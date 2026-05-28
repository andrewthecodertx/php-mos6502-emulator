<?php

declare(strict_types=1);

namespace andrewthecoder\WDC65C02;

use andrewthecoder\Core\BusInterface;
use andrewthecoder\Core\StatusRegister;
use andrewthecoder\WDC65C02\Instructions\Arithmetic;
use andrewthecoder\WDC65C02\Instructions\CMOS65C02;
use andrewthecoder\WDC65C02\Instructions\Flags;
use andrewthecoder\WDC65C02\Instructions\FlowControl;
use andrewthecoder\WDC65C02\Instructions\IllegalOpcodes;
use andrewthecoder\WDC65C02\Instructions\IncDec;
use andrewthecoder\WDC65C02\Instructions\LoadStore;
use andrewthecoder\WDC65C02\Instructions\Logic;
use andrewthecoder\WDC65C02\Instructions\ShiftRotate;
use andrewthecoder\WDC65C02\Instructions\Stack;
use andrewthecoder\WDC65C02\Instructions\Transfer;

/**
 * W65C02S CPU Emulator
 *
 * Implements a fully functional WDC 65C02 CMOS microprocessor with support for all
 * standard opcodes (including 65C02-specific instructions like BRA, STZ, TRB, TSB,
 * WAI, STP, and bit manipulation), addressing modes, interrupts (NMI, IRQ, RESET),
 * and a hybrid execution model combining JSON-driven and custom handler-based
 * instruction processing.
 *
 * Key differences from NMOS 6502:
 * - New instructions: BRA, PHX, PHY, PLX, PLY, STZ, TRB, TSB, WAI, STP
 * - Bit manipulation: BBR0-7, BBS0-7, RMB0-7, SMB0-7
 * - New addressing modes: Zero Page Indirect (zp), Absolute Indexed Indirect (a,x)
 * - All illegal opcodes replaced with NOPs
 * - Fixed JMP indirect page boundary bug
 * - Proper decimal mode flag handling
 *
 * @package andrewthecoder\MOS6502
 */
class CPU
{
    public int $pc = 0;
    public int $sp = 0xFF;
    public int $accumulator = 0;
    public int $registerX = 0;
    public int $registerY = 0;
    public int $cycles = 0;
    public bool $halted = false;
    public bool $waiting = false; // 65C02 WAI instruction state

    /** @var array<int, string> */
    private array $pcTrace = [];
    private bool $nmiPending = false;
    private bool $irqPending = false;
    private bool $resetPending = false;
    private bool $nmiLastState = true;
    private bool $running = true;
    private bool $autoTickBus = true;
    /** @var array<string, callable(\andrewthecoder\Core\Opcode): int> */
    private array $instructionHandlers = [];

    private readonly InstructionInterpreter $interpreter;
    private readonly LoadStore $loadStoreHandler;
    private readonly Transfer $transferHandler;
    private readonly Arithmetic $arithmeticHandler;
    private readonly Logic $logicHandler;
    private readonly ShiftRotate $shiftRotateHandler;
    private readonly IncDec $incDecHandler;
    private readonly FlowControl $flowControlHandler;
    private readonly Stack $stackHandler;
    private readonly Flags $flagsHandler;
    private readonly IllegalOpcodes $illegalOpcodesHandler;
    private readonly CMOS65C02 $cmos65c02Handler;

    /**
     * Initializes the CPU with a bus interface and optional monitoring
     *
     * @param BusInterface $bus The system bus for memory access
     * @param CPUMonitor|null $monitor Optional monitor for debugging and profiling
     * @param InstructionRegister $instructionRegister The opcode registry
     * @param StatusRegister $status The CPU status flags register
     */
    public function __construct(
        private readonly BusInterface $bus,
        private ?CPUMonitor $monitor = null,
        private readonly InstructionRegister $instructionRegister = new InstructionRegister(),
        public readonly StatusRegister $status = new StatusRegister(),
    ) {
        $this->interpreter = new InstructionInterpreter($this);
        $this->loadStoreHandler = new LoadStore($this);
        $this->transferHandler = new Transfer($this);
        $this->arithmeticHandler = new Arithmetic($this);
        $this->logicHandler = new Logic($this);
        $this->shiftRotateHandler = new ShiftRotate($this);
        $this->incDecHandler = new IncDec($this);
        $this->flowControlHandler = new FlowControl($this);
        $this->stackHandler = new Stack($this);
        $this->flagsHandler = new Flags($this);
        $this->illegalOpcodesHandler = new IllegalOpcodes($this);
        $this->cmos65c02Handler = new CMOS65C02($this);

        $this->initializeInstructionHandlers();
    }

    /**
     * Decrements the cycle counter and logs to monitor if enabled
     */
    public function clock(): void
    {
        $this->cycles--;

        if ($this->monitor !== null) {
            $this->monitor->logCycle();
        }
    }

    /**
     * Runs the CPU continuously until stopped
     *
     * Executes instructions in a loop until stop() is called.
     * Dispatches signals every 10000 instructions for CTRL-C handling.
     */
    public function run(): void
    {
        $instructionCount = 0;
        while ($this->running) {
            $this->step();

            // Dispatch signals every 10000 instructions for CTRL-C handling
            if ((++$instructionCount % 10000) == 0 && function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Stops the CPU execution loop
     *
     * Causes run() to exit after the current instruction completes.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Executes a single CPU cycle
     *
     * Handles interrupts (RESET, NMI, IRQ), fetches and executes instructions,
     * and manages the cycle counter. Uses either JSON-driven or handler-based
     * execution depending on the opcode.
     *
     * @throws \InvalidArgumentException If an unknown opcode is encountered
     * @throws \RuntimeException If an instruction handler is not implemented
     */
    public function step(): void
    {
        if ($this->halted) {
            if ($this->cycles > 0) {
                $this->cycles--;
            }
            return;
        }

        if ($this->cycles === 0) {
            if ($this->resetPending) {
                $this->handleReset();
                return;
            }

            if ($this->nmiPending) {
                $this->handleNMI();
                return;
            }

            if ($this->irqPending && !$this->status->get(StatusRegister::INTERRUPT_DISABLE)) {
                $this->handleIRQ();
                return;
            }

            $pcBeforeRead = $this->pc;
            $opcode = $this->bus->read($this->pc);

            // Track PC for debugging
            $this->pcTrace[] = sprintf('0x%04X', $pcBeforeRead);
            if (count($this->pcTrace) > 10) {
                array_shift($this->pcTrace);
            }

            if ($this->monitor !== null) {
                $opcodeHex = sprintf('0x%02X', $opcode);
                $opcodeData = $this->instructionRegister->getOpcode($opcodeHex);
                $mnemonic = $opcodeData ? $opcodeData->getMnemonic() : 'UNKNOWN';
                $this->monitor->logInstruction($this->pc, $opcode, $mnemonic);
            }

            $this->pc++;

            $opcodeData = $this->instructionRegister->getOpcode(sprintf('0x%02X', $opcode));

            if (!$opcodeData) {
                fprintf(STDERR, "DEBUG: Last 10 PCs: %s\n", implode(' -> ', $this->pcTrace));
                fprintf(
                    STDERR,
                    "DEBUG: Fetched opcode 0x%02X from PC 0x%04X (PC after inc: 0x%X)\n",
                    $opcode,
                    $pcBeforeRead,
                    $this->pc,
                );
                throw new \InvalidArgumentException(sprintf(
                    'Unknown opcode: 0x%02X at PC: 0x%04X',
                    $opcode,
                    $pcBeforeRead,
                ));
            }

            if ($opcodeData->hasExecution()) {
                $this->cycles += $this->interpreter->execute($opcodeData);
            } else {
                $mnemonic = $opcodeData->getMnemonic();

                if (!isset($this->instructionHandlers[$mnemonic])) {
                    throw new \RuntimeException("Instruction {$mnemonic} not implemented");
                }

                $handler = $this->instructionHandlers[$mnemonic];
                $this->cycles += $handler($opcodeData);
            }
        }
        $this->clock();

        if ($this->bus != null && $this->autoTickBus) {
            $this->bus->tick();
        }
    }

    /**
     * Executes a complete instruction including all cycles
     *
     * Calls step() repeatedly until the instruction completes and all cycles
     * are consumed. Useful for debugging or single-stepping through instructions.
     *
     * If there are pending cycles (e.g. from a previous interrupt), those are
     * consumed first before fetching and executing the next instruction.
     */
    public function executeInstruction(): void
    {
        // First, consume any pending cycles from previous operations (e.g. interrupts)
        while (!$this->halted && $this->cycles > 0) {
            $this->step();
        }

        // Now fetch and execute the next instruction
        $this->step();

        // Continue stepping until all instruction cycles are consumed
        while (!$this->halted && $this->cycles > 0) {
            $this->step();
        }
    }

    /**
     * Halts CPU execution
     *
     * Sets the halted flag, causing the CPU to stop executing instructions
     * while continuing to decrement the cycle counter.
     */
    public function halt(): void
    {
        $this->halted = true;
    }

    /**
     * Resumes CPU execution after halt
     */
    public function resume(): void
    {
        $this->halted = false;
    }

    /**
     * Sets the CPU waiting state (65C02 WAI instruction)
     *
     * @param bool $waiting True to enter wait state, false to exit
     */
    public function setWaiting(bool $waiting): void
    {
        $this->waiting = $waiting;
    }

    /**
     * Checks if the CPU is in waiting state (65C02 WAI)
     */
    public function isWaiting(): bool
    {
        return $this->waiting;
    }

    /**
     * Checks if the CPU is currently halted
     *
     * @return bool True if halted, false otherwise
     */
    public function isHalted(): bool
    {
        return $this->halted;
    }

    /**
     * Enables or disables automatic bus ticking during step()
     *
     * @param bool $autoTick If true, bus->tick() is called each cycle
     */
    public function setAutoTickBus(bool $autoTick): void
    {
        $this->autoTickBus = $autoTick;
    }

    /**
     * Resets the CPU via the RESET interrupt
     *
     * Requests a RESET interrupt and executes it immediately if the CPU is halted.
     * Otherwise, RESET will be processed at the next instruction boundary.
     */
    public function reset(): void
    {
        $this->requestReset();

        if ($this->halted) {
            $this->handleReset(immediate: true);
        }
    }

    private function handleReset(bool $immediate = false): void
    {
        if ($this->monitor !== null) {
            $this->monitor->clearLog();
        }

        // W65C02S RESET sequence per datasheet:
        // - Takes 7 clock cycles
        // - SP decremented by 3
        // - PC loaded from reset vector (0xFFFC-0xFFFD)
        // - Status register: I=1, D=0, unused=1

        // Only add cycles if this is not an immediate reset (i.e., queued for later)
        if (!$immediate) {
            $this->cycles += 7;
        }

        $this->sp = ($this->sp - 3) & 0xFF;

        $resetLow = $this->bus->read(0xFFFC);
        $resetHigh = $this->bus->read(0xFFFD);
        $this->pc = ($resetHigh << 8) | $resetLow;

        $this->accumulator = 0;
        $this->registerX = 0;
        $this->registerY = 0;

        $this->status->fromInt(0b110100); // Binary: NVUBDIZC = 00110100

        $this->halted = false;
        $this->resetPending = false;

        $this->nmiPending = false;
        $this->irqPending = false;
    }

    private function handleNMI(): void
    {
        // NMI interrupt sequence per W65C02S datasheet:
        // 1. Complete current instruction (already done in step())
        // 2. Push PC high byte to stack
        // 3. Push PC low byte to stack
        // 4. Push status register to stack
        // 5. Set I flag (though NMI cannot be masked)
        // 6. Load PC from NMI vector (0xFFFA-0xFFFB)

        // Clear WAI state - interrupts wake the CPU from WAI
        $this->waiting = false;

        $this->pushByte(($this->pc >> 8) & 0xFF);
        $this->pushByte($this->pc & 0xFF);

        $statusValue = $this->status->toInt() & ~(1 << StatusRegister::BREAK_COMMAND);
        $this->pushByte($statusValue);
        $this->status->set(StatusRegister::INTERRUPT_DISABLE, true);

        $nmiLow = $this->bus->read(0xFFFA);
        $nmiHigh = $this->bus->read(0xFFFB);

        $this->pc = ($nmiHigh << 8) | $nmiLow;
        $this->cycles += 7;
        $this->nmiPending = false;
    }

    private function handleIRQ(): void
    {
        // IRQ interrupt sequence per W65C02S datasheet:
        // 1. Complete current instruction (already done in step())
        // 2. Push PC high byte to stack
        // 3. Push PC low byte to stack
        // 4. Push status register to stack
        // 5. Set I flag to disable further IRQs
        // 6. Load PC from IRQ vector (0xFFFE-0xFFFF)
        // NOTE: IRQ is level-triggered - remains asserted until releaseIRQ() is called

        // Clear WAI state - interrupts wake the CPU from WAI
        $this->waiting = false;

        $this->pushByte(($this->pc >> 8) & 0xFF);
        $this->pushByte($this->pc & 0xFF);

        $statusValue = $this->status->toInt() & ~(1 << StatusRegister::BREAK_COMMAND);

        $this->pushByte($statusValue);
        $this->status->set(StatusRegister::INTERRUPT_DISABLE, true);

        $irqLow = $this->bus->read(0xFFFE);
        $irqHigh = $this->bus->read(0xFFFF);

        $this->pc = ($irqHigh << 8) | $irqLow;
        $this->cycles += 7;

        // NOTE: Do NOT clear irqPending here - IRQ is level-triggered
        // and remains asserted until explicitly released with releaseIRQ()
    }

    private function initializeInstructionHandlers(): void
    {
        $this->instructionHandlers = [
            'LDA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->loadStoreHandler->lda($opcode),
            'LDX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->loadStoreHandler->ldx($opcode),
            'LDY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->loadStoreHandler->ldy($opcode),
            'STA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->loadStoreHandler->sta($opcode),
            'SAX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->loadStoreHandler->sax($opcode),
            'STX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->loadStoreHandler->stx($opcode),
            'STY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->loadStoreHandler->sty($opcode),
            'TAX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->transferHandler->tax($opcode),
            'TAY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->transferHandler->tay($opcode),
            'TXA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->transferHandler->txa($opcode),
            'TYA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->transferHandler->tya($opcode),
            'TSX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->transferHandler->tsx($opcode),
            'TXS' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->transferHandler->txs($opcode),
            'ADC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->arithmeticHandler->adc($opcode),
            'SBC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->arithmeticHandler->sbc($opcode),
            'CMP' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->arithmeticHandler->cmp($opcode),
            'CPX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->arithmeticHandler->cpx($opcode),
            'CPY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->arithmeticHandler->cpy($opcode),
            'AND' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->logicHandler->and($opcode),
            'ORA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->logicHandler->ora($opcode),
            'EOR' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->logicHandler->eor($opcode),
            'BIT' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->logicHandler->bit($opcode),
            'ANC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->logicHandler->anc($opcode),
            'ASL' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->shiftRotateHandler->asl($opcode),
            'LSR' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->shiftRotateHandler->lsr($opcode),
            'ROL' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->shiftRotateHandler->rol($opcode),
            'ROR' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->shiftRotateHandler->ror($opcode),
            'RLA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->shiftRotateHandler->rla($opcode),
            'SLO' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->shiftRotateHandler->slo($opcode),
            'INC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->incDecHandler->inc($opcode),
            'DEC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->incDecHandler->dec($opcode),
            'INX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->incDecHandler->inx($opcode),
            'DEX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->incDecHandler->dex($opcode),
            'INY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->incDecHandler->iny($opcode),
            'DEY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->incDecHandler->dey($opcode),
            'ISC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->incDecHandler->isc($opcode),
            'BEQ' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->beq($opcode),
            'BNE' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->bne($opcode),
            'BCC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->bcc($opcode),
            'BCS' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->bcs($opcode),
            'BPL' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->bpl($opcode),
            'BMI' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->bmi($opcode),
            'BVC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->bvc($opcode),
            'BVS' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->bvs($opcode),
            'JMP' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->jmp($opcode),
            'JSR' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->jsr($opcode),
            'RTS' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->rts($opcode),
            'BRK' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->brk($opcode),
            'RTI' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->rti($opcode),
            'JAM' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flowControlHandler->jam($opcode),
            'PHA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->pha($opcode),
            'PLA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->pla($opcode),
            'PHP' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->php($opcode),
            'PLP' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->plp($opcode),
            'PHX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->phx($opcode),
            'PHY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->phy($opcode),
            'PLX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->plx($opcode),
            'PLY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->stackHandler->ply($opcode),
            'SEC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flagsHandler->sec($opcode),
            'CLC' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flagsHandler->clc($opcode),
            'SEI' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flagsHandler->sei($opcode),
            'CLI' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flagsHandler->cli($opcode),
            'SED' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flagsHandler->sed($opcode),
            'CLD' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flagsHandler->cld($opcode),
            'CLV' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->flagsHandler->clv($opcode),
            // 65C02 CMOS-specific instructions
            'BRA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bra($opcode),
            'STZ' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->stz($opcode),
            'TRB' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->trb($opcode),
            'TSB' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->tsb($opcode),
            'WAI' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->wai($opcode),
            'STP' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->stp($opcode),
            // BBR0-7 - Branch on Bit Reset
            'BBR0' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr0($opcode),
            'BBR1' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr1($opcode),
            'BBR2' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr2($opcode),
            'BBR3' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr3($opcode),
            'BBR4' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr4($opcode),
            'BBR5' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr5($opcode),
            'BBR6' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr6($opcode),
            'BBR7' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbr7($opcode),
            // BBS0-7 - Branch on Bit Set
            'BBS0' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs0($opcode),
            'BBS1' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs1($opcode),
            'BBS2' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs2($opcode),
            'BBS3' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs3($opcode),
            'BBS4' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs4($opcode),
            'BBS5' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs5($opcode),
            'BBS6' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs6($opcode),
            'BBS7' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->bbs7($opcode),
            // RMB0-7 - Reset Memory Bit
            'RMB0' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb0($opcode),
            'RMB1' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb1($opcode),
            'RMB2' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb2($opcode),
            'RMB3' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb3($opcode),
            'RMB4' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb4($opcode),
            'RMB5' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb5($opcode),
            'RMB6' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb6($opcode),
            'RMB7' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->rmb7($opcode),
            // SMB0-7 - Set Memory Bit
            'SMB0' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb0($opcode),
            'SMB1' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb1($opcode),
            'SMB2' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb2($opcode),
            'SMB3' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb3($opcode),
            'SMB4' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb4($opcode),
            'SMB5' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb5($opcode),
            'SMB6' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb6($opcode),
            'SMB7' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->cmos65c02Handler->smb7($opcode),
            'NOP' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->nop($opcode),
            // Illegal/undocumented opcodes
            'ALR' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->alr($opcode),
            'ANE' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->ane($opcode),
            'ARR' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->arr($opcode),
            'DCP' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->dcp($opcode),
            'LAS' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->las($opcode),
            'LAX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->lax($opcode),
            'LXA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->lxa($opcode),
            'RRA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->rra($opcode),
            'SBX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->sbx($opcode),
            'SHA' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->sha($opcode),
            'SHS' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->shs($opcode),
            'SHX' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->shx($opcode),
            'SHY' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->shy($opcode),
            'SRE' => fn(\andrewthecoder\Core\Opcode $opcode) => $this->illegalOpcodesHandler->sre($opcode),
        ];
    }

    /**
     * Handles NOP (No Operation) instruction
     *
     * Does nothing but must consume operand bytes for multi-byte NOPs.
     * For illegal NOPs (DOP/TOP), the operand is read but ignored.
     *
     * @param \andrewthecoder\Core\Opcode $opcode The NOP opcode
     * @return int Number of cycles taken
     */
    private function nop(\andrewthecoder\Core\Opcode $opcode): int
    {
        // For NOPs with operands (not Implied addressing), we need to
        // advance PC over the operand bytes by calling getAddress()
        $addressingMode = $opcode->getAddressingMode();
        if ($addressingMode !== 'Implied') {
            $this->getAddress($addressingMode);
        }

        return $opcode->getCycles();
    }

    /**
     * Gets the current value of the accumulator register
     *
     * @return int Accumulator value (0x00-0xFF)
     */
    public function getAccumulator(): int
    {
        return $this->accumulator;
    }

    /**
     * Sets the accumulator register value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setAccumulator(int $value): void
    {
        $this->accumulator = $value & 0xFF;
    }

    /**
     * Gets the current value of the X index register
     *
     * @return int X register value (0x00-0xFF)
     */
    public function getRegisterX(): int
    {
        return $this->registerX;
    }

    /**
     * Sets the X index register value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setRegisterX(int $value): void
    {
        $this->registerX = $value & 0xFF;
    }

    /**
     * Gets the current value of the Y index register
     *
     * @return int Y register value (0x00-0xFF)
     */
    public function getRegisterY(): int
    {
        return $this->registerY;
    }

    /**
     * Sets the Y index register value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setRegisterY(int $value): void
    {
        $this->registerY = $value & 0xFF;
    }

    /**
     * Gets the current stack pointer value
     *
     * @return int Stack pointer (0x00-0xFF, points to next free location)
     */
    public function getStackPointer(): int
    {
        return $this->sp;
    }

    /**
     * Sets the stack pointer value
     *
     * @param int $value Value to set (automatically masked to 8 bits)
     */
    public function setStackPointer(int $value): void
    {
        $this->sp = $value & 0xFF;
    }

    /**
     * Pushes a byte onto the stack
     *
     * Writes the value to the current stack location (0x0100 + SP) and
     * decrements the stack pointer. Stack grows downward from 0x01FF.
     *
     * @param int $value Byte to push (automatically masked to 8 bits)
     */
    public function pushByte(int $value): void
    {
        $this->bus->write(0x100 + $this->sp, $value & 0xFF);
        $this->sp = ($this->sp - 1) & 0xFF;
    }

    /**
     * Pulls a byte from the stack
     *
     * Increments the stack pointer and reads from the stack location.
     *
     * @return int Byte value pulled from stack (0x00-0xFF)
     */
    public function pullByte(): int
    {
        $this->sp = ($this->sp + 1) & 0xFF;
        return $this->bus->read(0x100 + $this->sp);
    }

    /**
     * Pushes a 16-bit word onto the stack
     *
     * Pushes high byte first, then low byte (standard 6502 convention).
     *
     * @param int $value 16-bit value to push
     */
    public function pushWord(int $value): void
    {
        $this->pushByte(($value >> 8) & 0xFF);
        $this->pushByte($value & 0xFF);
    }

    /**
     * Pulls a 16-bit word from the stack
     *
     * Pulls low byte first, then high byte (standard 6502 convention).
     *
     * @return int 16-bit value pulled from stack
     */
    public function pullWord(): int
    {
        $low = $this->pullByte();
        $high = $this->pullByte();
        return ($high << 8) | $low;
    }

    /**
     * Calculates effective address for a given addressing mode
     *
     * Reads necessary bytes from memory after PC and advances PC accordingly.
     * Supports all standard 6502 addressing modes including zero page, absolute,
     * indexed, indirect, and relative modes.
     *
     * @param string $addressingMode The addressing mode name
     * @return int The effective address calculated for this mode
     * @throws \InvalidArgumentException If addressing mode is not recognized
     */
    public function getAddress(string $addressingMode): int
    {
        return match ($addressingMode) {
            'Immediate' => $this->immediate(),
            'Zero Page' => $this->zeroPage(),
            'X-Indexed Zero Page', 'Zero Page Indexed with X' => $this->zeroPageX(),
            'Y-Indexed Zero Page', 'Zero Page Indexed with Y' => $this->zeroPageY(),
            'Absolute' => $this->absolute(),
            'X-Indexed Absolute', 'Absolute Indexed with X' => $this->absoluteX(),
            'Y-Indexed Absolute', 'Absolute Indexed with Y' => $this->absoluteY(),
            'X-Indexed Zero Page Indirect', 'Zero Page Indexed Indirect' => $this->indirectX(),
            'Zero Page Indirect Y-Indexed', 'Zero Page Indirect Indexed with Y' => $this->indirectY(),
            'Absolute Indirect' => $this->absoluteIndirect(),
            'Relative', 'Program Counter Relative' => $this->relative(),
            'Implied' => $this->implied(),
            'Accumulator' => $this->accumulator(),
            'Zero Page Indirect' => $this->zeroPageIndirect(), // New 65C02 addressing mode
            'Absolute Indexed Indirect' => $this->absoluteIndexedIndirect(), // New 65C02 addressing mode
            'Stack' => 0, // Stack operations don't use getAddress
            default => throw new \InvalidArgumentException("Invalid addressing mode: {$addressingMode}"),
        };
    }

    private function immediate(): int
    {
        $this->pc++;
        return $this->pc - 1;
    }

    private function zeroPage(): int
    {
        $address = $this->bus->read($this->pc);
        $this->pc++;
        return $address;
    }

    private function zeroPageX(): int
    {
        $address = $this->bus->read($this->pc) + $this->registerX;
        $this->pc++;
        return $address & 0xFF;
    }

    private function zeroPageY(): int
    {
        $address = $this->bus->read($this->pc) + $this->registerY;
        $this->pc++;
        return $address & 0xFF;
    }

    private function absolute(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        return ($high << 8) | $low;
    }

    private function absoluteX(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        $address = (($high << 8) | $low) + $this->registerX;

        return $address & 0xFFFF;
    }

    private function absoluteY(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        $address = (($high << 8) | $low) + $this->registerY;

        return $address & 0xFFFF;
    }

    private function indirectX(): int
    {
        $zeroPageAddress = $this->bus->read($this->pc) + $this->registerX;
        $this->pc++;
        $low = $this->bus->read($zeroPageAddress & 0xFF);
        $high = $this->bus->read(($zeroPageAddress + 1) & 0xFF);

        return ($high << 8) | $low;
    }

    private function indirectY(): int
    {
        $zeroPageAddress = $this->bus->read($this->pc);
        $this->pc++;
        $low = $this->bus->read($zeroPageAddress & 0xFF);
        $high = $this->bus->read(($zeroPageAddress + 1) & 0xFF);
        $address = (($high << 8) | $low) + $this->registerY;

        return $address & 0xFFFF;
    }

    private function absoluteIndirect(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;
        $indirectAddress = ($high << 8) | $low;

        // 65C02 fix: Page boundary bug is fixed - always read from consecutive addresses
        return $this->bus->readWord($indirectAddress);
    }

    /**
     * Zero Page Indirect addressing mode (65C02)
     * Format: (zp)
     * The zero page address contains a pointer to the effective address
     */
    private function zeroPageIndirect(): int
    {
        $zpAddress = $this->bus->read($this->pc);
        $this->pc++;

        // Read the 16-bit address from zero page
        $low = $this->bus->read($zpAddress);
        $high = $this->bus->read(($zpAddress + 1) & 0xFF);

        return ($high << 8) | $low;
    }

    /**
     * Absolute Indexed Indirect addressing mode (65C02)
     * Format: (a,x)
     * Used by JMP instruction - adds X to absolute address, then uses result as pointer
     */
    private function absoluteIndexedIndirect(): int
    {
        $low = $this->bus->read($this->pc);
        $this->pc++;
        $high = $this->bus->read($this->pc);
        $this->pc++;

        // Add X register to the absolute address
        $indirectAddress = ((($high << 8) | $low) + $this->registerX) & 0xFFFF;

        // Read the target address from the indirect address
        return $this->bus->readWord($indirectAddress);
    }

    private function relative(): int
    {
        $offset = $this->bus->read($this->pc);
        $this->pc++;
        return $offset;
    }

    private function implied(): int
    {
        return 0;
    }

    private function accumulator(): int
    {
        return 0;
    }

    /**
     * Returns a formatted string of CPU register states
     *
     * @return string Formatted string showing PC, SP, A, X, Y registers in hex
     */
    public function getRegistersState(): string
    {
        return sprintf(
            'PC: 0x%04X, SP: 0x%04X, A: 0x%02X, X: 0x%02X, Y: 0x%02X',
            $this->pc,
            $this->sp,
            $this->accumulator,
            $this->registerX,
            $this->registerY,
        );
    }

    /**
     * Returns a formatted string of CPU status flags
     *
     * @return string Formatted string showing all status flags (NVUBDIZC)
     */
    public function getFlagsState(): string
    {
        return sprintf(
            'Flags: %s%s%s%s%s%s%s%s',
            $this->status->get(StatusRegister::NEGATIVE) ? 'N' : '-',
            $this->status->get(StatusRegister::OVERFLOW) ? 'V' : '-',
            '-',
            $this->status->get(StatusRegister::BREAK_COMMAND) ? 'B' : '-',
            $this->status->get(StatusRegister::DECIMAL_MODE) ? 'D' : '-',
            $this->status->get(StatusRegister::INTERRUPT_DISABLE) ? 'I' : '-',
            $this->status->get(StatusRegister::ZERO) ? 'Z' : '-',
            $this->status->get(StatusRegister::CARRY) ? 'C' : '-',
        );
    }

    /**
     * Gets the system bus interface
     *
     * @return BusInterface The bus for memory and I/O access
     */
    public function getBus(): BusInterface
    {
        return $this->bus;
    }

    /**
     * Gets the instruction register containing opcode definitions
     *
     * @return InstructionRegister The opcode registry
     */
    public function getInstructionRegister(): InstructionRegister
    {
        return $this->instructionRegister;
    }

    /**
     * Requests a Non-Maskable Interrupt (NMI)
     *
     * NMI is edge-triggered and cannot be masked by the I flag. Will execute
     * at the next instruction boundary. Only triggers on falling edge.
     */
    public function requestNMI(): void
    {
        if ($this->nmiLastState === true) {
            $this->nmiPending = true;
        }
        $this->nmiLastState = false;
    }

    /**
     * Releases the NMI line to high state
     *
     * Prepares for the next falling edge detection.
     */
    public function releaseNMI(): void
    {
        $this->nmiLastState = true;
    }

    /**
     * Requests an Interrupt Request (IRQ)
     *
     * IRQ is level-triggered and can be masked by the I flag. Will execute
     * at the next instruction boundary if interrupts are enabled.
     */
    public function requestIRQ(): void
    {
        $this->irqPending = true;
    }

    /**
     * Releases the IRQ line, clearing the pending interrupt
     */
    public function releaseIRQ(): void
    {
        $this->irqPending = false;
    }

    /**
     * Requests a RESET interrupt
     *
     * RESET has highest priority and will execute at the next instruction boundary.
     */
    public function requestReset(): void
    {
        $this->resetPending = true;
    }

    /**
     * Sets or clears the CPU monitor for debugging
     *
     * @param CPUMonitor|null $monitor Monitor instance or null to disable
     */
    public function setMonitor(?CPUMonitor $monitor): void
    {
        $this->monitor = $monitor;
    }

    /**
     * Gets the current CPU monitor instance
     *
     * @return CPUMonitor|null Monitor instance or null if not monitored
     */
    public function getMonitor(): ?CPUMonitor
    {
        return $this->monitor;
    }

    /**
     * Checks if the CPU is currently being monitored
     *
     * @return bool True if a monitor is attached, false otherwise
     */
    public function isMonitored(): bool
    {
        return $this->monitor !== null;
    }
}

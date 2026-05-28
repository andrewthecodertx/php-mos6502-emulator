<?php declare(strict_types=1);

namespace andrewthecoder\Core;

/**
 * 6502 Processor Status Register (P register).
 *
 * The status register contains 8 flags that indicate various CPU states:
 * - N (Negative): Set if bit 7 of the result is 1
 * - V (Overflow): Set if signed arithmetic overflow occurred
 * - U (Unused): Always set to 1
 * - B (Break): Set if BRK instruction caused the interrupt
 * - D (Decimal): Set to enable BCD arithmetic mode
 * - I (Interrupt Disable): Set to disable IRQ interrupts
 * - Z (Zero): Set if result is zero
 * - C (Carry): Set if carry/borrow occurred
 *
 * Format: NV-BDIZC (bit 7 to bit 0)
 */
class StatusRegister
{
    /**
     * Carry flag - bit 0
     */
    public const CARRY = 0;

    /**
     * Zero flag - bit 1
     */
    public const ZERO = 1;

    /**
     * Interrupt disable flag - bit 2
     */
    public const INTERRUPT_DISABLE = 2;

    /**
     * Decimal mode flag - bit 3
     */
    public const DECIMAL_MODE = 3;

    /**
     * Break command flag - bit 4
     */
    public const BREAK_COMMAND = 4;

    /**
     * Unused flag - bit 5 (always 1)
     */
    public const UNUSED = 5;

    /**
     * Overflow flag - bit 6
     */
    public const OVERFLOW = 6;

    /**
     * Negative flag - bit 7
     */
    public const NEGATIVE = 7;

    /** @var int The 8-bit status register value (default: 0b00110100 = Unused and Break set) */
    private int $flags = 0b110100;

    /**
     * Set or clear a specific flag in the status register.
     *
     * @param int $flag One of the flag constants (CARRY, ZERO, etc.)
     * @param bool $value True to set the flag, false to clear it
     */
    public function set(int $flag, bool $value): void
    {
        if ($value) {
            $this->flags |= (1 << $flag);  // Set bit
        } else {
            $this->flags &= ~(1 << $flag);  // Clear bit
        }
    }

    /**
     * Get the current state of a specific flag.
     *
     * @param int $flag One of the flag constants (CARRY, ZERO, etc.)
     * @return bool True if the flag is set, false otherwise
     */
    public function get(int $flag): bool
    {
        return ($this->flags & (1 << $flag)) !== 0;
    }

    /**
     * Get the entire status register as an 8-bit integer.
     *
     * Useful for pushing the status register onto the stack during interrupts.
     *
     * @return int The status register value (0x00-0xFF)
     */
    public function toInt(): int
    {
        return $this->flags;
    }

    /**
     * Set the entire status register from an 8-bit integer.
     *
     * Useful for restoring the status register from the stack (RTI, PLP instructions).
     *
     * @param int $value The new status register value (0x00-0xFF)
     */
    public function fromInt(int $value): void
    {
        $this->flags = $value & 0xFF;
    }
}

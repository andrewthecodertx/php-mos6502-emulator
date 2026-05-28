# PHP-6502: A 6502 Emulator in PHP

A fully functional 6502 microprocessor emulator written entirely in PHP,
packaged as a Composer library for building custom 6502-based systems. Features
two CPU cores (NMOS 6502 and WDC 65C02) that can be paired with different system
implementations.

## Installation

Install via Composer:

```bash
composer require andrewthecoder/6502-emulator
```

## Features

### Dual CPU Core Support

* **MOS 6502** - Original MOS Technology 6502 with 56 documented instructions
and illegal opcodes
* **WDC 65C02** - Western Design Center 65C02 with 70 instructions including
BRA, STZ, PHX/PHY, bit manipulation, and more
* **Hybrid execution model** combining JSON-driven and custom handler-based
instruction processing
* **Interrupt support** (NMI, IRQ, RESET) with proper edge/level triggering
* **CPU monitoring** for debugging and profiling with instruction tracing and
cycle counting
* **Comprehensive PHPDoc documentation** for IDE support

## CPU Variants Explained

This library provides two different 6502 CPU implementations:

### MOS 6502 (Original)

The **MOS 6502** refers to the original microprocessor designed by MOS
Technology in 1975. It uses **NMOS** (N-channel Metal-Oxide-Semiconductor)
fabrication technology. While you may see it referred to as "NMOS 6502" in
technical documentation, the official product name is simply **MOS 6502** or **6502**.

**Characteristics:**

* 56 documented instructions
* Includes illegal/undocumented opcodes used by some software
* Hardware bugs (JMP indirect page boundary bug, decimal mode doesn't set N/V/Z
flags)
* Found in: Commodore 64, Apple II/II+, NES, Atari 2600/5200/7800

### WDC 65C02 (Enhanced)

The **WDC 65C02** (or simply **65C02**) is an enhanced version designed by
Western Design Center using **CMOS** (Complementary Metal-Oxide-Semiconductor)
fabrication technology. The "C" in the name literally stands for CMOS. This
version fixed bugs from the original and added new instructions.

**Characteristics:**

* 70 instructions (14 additional instruction types)
* Fixed hardware bugs from the original
* Better power efficiency (CMOS technology)
* New addressing modes and bit manipulation instructions
* Found in: Apple IIc/IIe, later Commodore models, modern 6502-based systems

### When to Use Which?

**Use `andrewthecoder\MOS6502\CPU` when:**

* Emulating systems that require the original chip (NES, C64, original Apple II)
* You need illegal opcodes for compatibility
* You want hardware-accurate behavior including bugs

**Use `andrewthecoder\WDC65C02\CPU` when:**

* Building modern 6502-based projects
* You want the additional instructions (BRA, STZ, etc.)
* You need the bug fixes (JMP indirect, decimal mode)
* Emulating systems that use the 65C02 (Apple IIe, IIc)

## Quick Start

### Using the CPU in Your Project

```php
<?php

require 'vendor/autoload.php';

// Import core interfaces (shared by both CPUs)
use andrewthecoder\Core\BusInterface;
use andrewthecoder\Core\StatusRegister;

// Choose your CPU variant:
// For MOS 6502 (original with illegal opcodes):
use andrewthecoder\MOS6502\CPU;

// OR for WDC 65C02 (enhanced with additional instructions):
// use andrewthecoder\WDC65C02\CPU;

// Implement a simple bus with 64KB RAM
class SimpleBus implements BusInterface
{
    private array $memory = [];

    public function read(int $address): int
    {
        return $this->memory[$address & 0xFFFF] ?? 0;
    }

    public function write(int $address, int $value): void
    {
        $this->memory[$address & 0xFFFF] = $value & 0xFF;
    }

    public function readWord(int $address): int
    {
        $low = $this->read($address);
        $high = $this->read($address + 1);
        return ($high << 8) | $low;
    }

    public function tick(): void
    {
        // Called after each CPU cycle
    }
}

// Create CPU with your bus
$bus = new SimpleBus();
$cpu = new CPU($bus);

// Load a simple program: LDA #$42, STA $00
$bus->write(0x8000, 0xA9);  // LDA #$42
$bus->write(0x8001, 0x42);
$bus->write(0x8002, 0x85);  // STA $00
$bus->write(0x8003, 0x00);

// Set reset vector
$bus->write(0xFFFC, 0x00);
$bus->write(0xFFFD, 0x80);

// Run
$cpu->reset();
$cpu->executeInstruction();
$cpu->executeInstruction();

echo sprintf("Value at $00: 0x%02X\n", $bus->read(0x00)); // 0x42
```

### Development Setup

For developing this library itself or running the included examples:

1. **Clone the repository:**

    ```bash
    git clone https://github.com/your-username/6502-Emulator.git
    cd 6502-Emulator
    ```

2. **Install dependencies:**

    ```bash
    composer install
    ```

3. **Optional: Install cc65 toolchain** for assembling 6502 programs:
Download from [official cc65 website](https://cc65.github.io/) and place `ca65`
and `ld65` in `bin/`

## Architecture

The emulator uses a modular, reusable architecture with three main namespaces:

### Namespace Structure

**`andrewthecoder\Core`** - Shared interfaces and components:

* **BusInterface** - Contract for system buses (memory-mapped I/O)
* **RAMInterface** - Contract for RAM implementations
* **ROMInterface** - Contract for ROM implementations
* **PeripheralInterface** - Contract for memory-mapped peripherals
* **StatusRegister** - CPU status flags (NV-BDIZC)
* **Opcode** - Opcode metadata container

**`andrewthecoder\MOS6502`** - MOS 6502 (Original):

* **CPU** - MOS Technology 6502 with 56 documented instructions
* Includes illegal/undocumented opcodes
* Hardware-accurate bugs (JMP indirect page boundary, decimal mode flags)
* **InstructionRegister** - MOS 6502 opcode definitions
* **InstructionInterpreter** - JSON-driven instruction execution
* **CPUMonitor** - Optional debugging tool
* **Instructions/** - Complex instruction handlers (ADC/SBC, branches, etc.)

**`andrewthecoder\WDC65C02`** - WDC 65C02 (Enhanced):

* **CPU** - WDC 65C02S with 70 instructions
* Additional instructions: BRA, STZ, PHX/PHY/PLX/PLY, TRB/TSB, WAI/STP, bit manipulation
* Bug fixes (JMP indirect, decimal mode)
* **InstructionRegister** - WDC 65C02 opcode definitions
* **InstructionInterpreter** - JSON-driven instruction execution
* **CPUMonitor** - Optional debugging tool
* **Instructions/** - Complex instruction handlers including CMOS-specific opcodes

### Building Your Own System

To create a custom 6502 system:

1. Install the package via Composer
2. Choose your CPU variant (MOS6502 or WDC65C02)
3. Implement `BusInterface` with your desired memory map
4. Optionally implement `RAMInterface`, `ROMInterface`, and `PeripheralInterface`
5. Instantiate `CPU` with your bus

See `docs/CPU_CORE_ARCHITECTURE.md` for detailed instructions and examples.

## Development

### Running Tests

The project uses PHPUnit for unit testing. To run the test suite:

```bash
./vendor/bin/phpunit
```

### Static Analysis

PHPStan is used for static analysis. To check the codebase:

```bash
./vendor/bin/phpstan analyse src
```

### Code Quality

* **Comprehensive PHPDoc** - All public methods and classes are fully documented
* **Type Safety** - Strict typing throughout with detailed array type annotations
* **Test Coverage** - 56 tests covering CPU operations, addressing modes, and
peripherals (coming soon)

## Project Structure

```
src/
├── Core/                       # andrewthecoder\Core - Shared components
│   ├── BusInterface.php        # Bus contract
│   ├── RAMInterface.php        # RAM contract
│   ├── ROMInterface.php        # ROM contract
│   ├── PeripheralInterface.php # Peripheral contract
│   ├── StatusRegister.php      # CPU status flags
│   └── Opcode.php              # Opcode metadata
│
├── MOS6502/                    # andrewthecoder\MOS6502 - MOS 6502 CPU
│   ├── CPU.php                 # Main MOS 6502 CPU
│   ├── InstructionRegister.php # MOS 6502 opcode registry
│   ├── InstructionInterpreter.php # JSON-driven execution
│   ├── CPUMonitor.php          # Debugging tool
│   ├── opcodes.json            # MOS 6502 opcodes
│   └── Instructions/           # Instruction handlers
│       ├── Arithmetic.php      # ADC, SBC
│       ├── IllegalOpcodes.php  # Undocumented opcodes
│       └── ...
│
├── WDC65C02/                   # andrewthecoder\WDC65C02 - WDC 65C02 CPU
│   ├── CPU.php                 # Main WDC 65C02 CPU
│   ├── InstructionRegister.php # WDC 65C02 opcode registry
│   ├── InstructionInterpreter.php # JSON-driven execution
│   ├── CPUMonitor.php          # Debugging tool
│   ├── opcodes.json            # WDC 65C02 opcodes
│   └── Instructions/           # Instruction handlers
│       ├── CMOS65C02.php       # CMOS-specific opcodes
│       └── ...
│

```

## Using in External Projects

After installing via Composer, you can use the CPU core to build any 6502-based system:

### Minimal Example

```php
<?php

use andrewthecoder\Core\BusInterface;
use andrewthecoder\MOS6502\CPU;

class MyBus implements BusInterface {
    private array $ram = [];

    public function read(int $address): int {
        return $this->ram[$address & 0xFFFF] ?? 0;
    }

    public function write(int $address, int $value): void {
        $this->ram[$address & 0xFFFF] = $value & 0xFF;
    }

    public function readWord(int $address): int {
        return $this->read($address) | ($this->read($address + 1) << 8);
    }

    public function tick(): void {
        // Update peripherals, check interrupts, etc.
    }
}

$cpu = new CPU(new MyBus());
$cpu->reset();
```

### Advanced: Memory-Mapped Peripherals

```php
<?php

use andrewthecoder\Core\BusInterface;
use andrewthecoder\Core\PeripheralInterface;

class MyBus implements BusInterface {
    private RAM $ram;
    private ROM $rom;
    private array $peripherals = [];

    public function addPeripheral(PeripheralInterface $peripheral): void {
        $this->peripherals[] = $peripheral;
    }

    public function read(int $address): int {
        // Check peripherals first
        foreach ($this->peripherals as $peripheral) {
            if ($peripheral->handlesAddress($address)) {
                return $peripheral->read($address);
            }
        }

        // Fall through to RAM/ROM
        return $address < 0x8000
            ? $this->ram->read($address)
            : $this->rom->read($address);
    }

    // ... write(), readWord(), tick() implementations
}
```

## License

MIT, see [LICENSE](LICENSE).

## Contributing

PRs welcome. Please open an issue first for major changes.

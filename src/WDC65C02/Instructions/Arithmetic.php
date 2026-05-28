<?php

declare(strict_types=1);

namespace andrewthecoder\WDC65C02\Instructions;

use andrewthecoder\Core\Opcode;
use andrewthecoder\Core\StatusRegister;
use andrewthecoder\WDC65C02\CPU;

class Arithmetic
{
    public function __construct(
        private CPU $cpu,
    ) {}

    public function adc(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $accumulator = $this->cpu->getAccumulator();
        $carry = $this->cpu->status->get(StatusRegister::CARRY) ? 1 : 0;

        $result = $accumulator + $value + $carry;

        $this->cpu->status->set(StatusRegister::CARRY, $result > 0xFF);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        $overflow = (($accumulator ^ $result) & ($value ^ $result) & 0x80) !== 0;
        $this->cpu->status->set(StatusRegister::OVERFLOW, $overflow);
        $this->cpu->setAccumulator($result & 0xFF);

        return $opcode->getCycles();
    }

    public function sbc(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $accumulator = $this->cpu->getAccumulator();
        $carry = $this->cpu->status->get(StatusRegister::CARRY) ? 1 : 0;
        $result = $accumulator - $value - (1 - $carry);

        $this->cpu->status->set(StatusRegister::CARRY, $result >= 0);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        $overflow = (($accumulator ^ $value) & ($accumulator ^ $result) & 0x80) !== 0;
        $this->cpu->status->set(StatusRegister::OVERFLOW, $overflow);

        $this->cpu->setAccumulator($result & 0xFF);

        return $opcode->getCycles();
    }

    public function cmp(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $accumulator = $this->cpu->getAccumulator();

        $result = $accumulator - $value;

        $this->cpu->status->set(StatusRegister::CARRY, $result >= 0);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function cpx(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $registerX = $this->cpu->getRegisterX();

        $result = $registerX - $value;

        $this->cpu->status->set(StatusRegister::CARRY, $result >= 0);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }

    public function cpy(Opcode $opcode): int
    {
        $address = $this->cpu->getAddress($opcode->getAddressingMode());
        $value = $this->cpu->getBus()->read($address);
        $registerY = $this->cpu->getRegisterY();

        $result = $registerY - $value;

        $this->cpu->status->set(StatusRegister::CARRY, $result >= 0);
        $this->cpu->status->set(StatusRegister::ZERO, ($result & 0xFF) === 0);
        $this->cpu->status->set(StatusRegister::NEGATIVE, ($result & 0x80) !== 0);

        return $opcode->getCycles();
    }
}

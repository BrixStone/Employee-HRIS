<?php
declare(strict_types=1);

namespace App\Domain\Payroll;

final class Money
{
    private function __construct(
        private readonly int $centavos
    ) {}

    public static function fromPesos(float $pesos): self
    {
        return new self((int) round($pesos * 100));
    }

    public static function fromCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public function centavos(): int
    {
        return $this->centavos;
    }

    public function format(): string
    {
        return number_format($this->centavos / 100, 2, '.', '');
    }

    public function half(): self
    {
        return new self((int) round($this->centavos / 2));
    }

    public function add(self $other): self
    {
        return new self($this->centavos + $other->centavos);
    }

    public function subtract(self $other): self
    {
        return new self($this->centavos - $other->centavos);
    }

    public function multiplyBy(float $factor): self
    {
        return new self((int) round($this->centavos * $factor));
    }
}
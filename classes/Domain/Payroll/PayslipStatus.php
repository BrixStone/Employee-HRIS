<?php
declare(strict_types=1);

namespace App\Domain\Payroll;

use DomainException;

/**
 * Backed enum representing the one-way payslip status machine.
 *
 * Legal transitions only:
 *   generated → finalized → paid
 *
 * Values match the 'status' column in the payslips table exactly.
 */
enum PayslipStatus: string
{
    case Generated = 'generated';
    case Finalized = 'finalized';
    case Paid      = 'paid';

    /**
     * Advance to the next status.
     * Throws DomainException if already at the terminal state.
     */
    public function advance(): self
    {
        return match($this) {
            self::Generated => self::Finalized,
            self::Finalized => self::Paid,
            self::Paid      => throw new DomainException(
                'Payslip is already paid and cannot be advanced further.'
            ),
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isEditable(): bool
    {
        return $this === self::Generated;
    }

    public function label(): string
    {
        return match($this) {
            self::Generated => 'Generated',
            self::Finalized => 'Finalized',
            self::Paid      => 'Paid',
        };
    }
}
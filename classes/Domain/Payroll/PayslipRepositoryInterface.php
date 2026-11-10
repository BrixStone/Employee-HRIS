<?php
declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * Domain contract for payslip persistence.
 *
 * The domain defines this interface.
 * Infrastructure implements it (PDO, in-memory, etc.).
 * The domain never depends on MySQL or any framework.
 */
interface PayslipRepositoryInterface
{
    /**
     * Persist a payslip.
     * Insert if new, update if already stored.
     */
    public function save(Payslip $payslip): void;

    /**
     * Return all payslips belonging to one employee.
     *
     * @return Payslip[]
     */
    public function findByEmployeeId(int $employeeId): array;

    /**
     * Return every payslip in the store.
     *
     * @return Payslip[]
     */
    public function findAll(): array;
}
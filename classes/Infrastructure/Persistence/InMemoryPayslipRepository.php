<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Payroll\Payslip;
use App\Domain\Payroll\PayslipRepositoryInterface;

/**
 * In-memory implementation used exclusively in unit tests.
 * No database, no PDO, no I/O of any kind.
 *
 * The real MySQL implementation (PdoPayslipRepository) satisfies
 * the same interface and is tested separately via integration tests.
 */
final class InMemoryPayslipRepository implements PayslipRepositoryInterface
{
    /** @var Payslip[] */
    private array $store = [];

    public function save(Payslip $payslip): void
    {
        $this->store[] = $payslip;
    }

    public function findByEmployeeId(int $employeeId): array
    {
        return array_values(
            array_filter(
                $this->store,
                fn(Payslip $p) => $p->employeeId() === $employeeId
            )
        );
    }

    public function findAll(): array
    {
        return array_values($this->store);
    }
}
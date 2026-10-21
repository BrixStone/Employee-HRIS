<?php


// to run unit testing =  .\vendor\bin\phpunit.bat tests\LeaveBalanceTest.php 
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/LeaveBalance.php';

final class LeaveBalanceTest extends TestCase
{
    public function testHasSufficientReturnsTrueWhenEnoughLeaveDaysExist(): void
    {
        $statement = $this->createMock(PDOStatement::class);

        $statement->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['remaining_days' => 10]);

        $pdo = $this->createMock(PDO::class);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $leaveBalance = new LeaveBalance($pdo);

        $this->assertTrue(
            $leaveBalance->hasSufficient(1, 1, 2026, 5)
        );
    }

    public function testHasSufficientReturnsFalseWhenNotEnoughLeaveDaysExist(): void
    {
        $statement = $this->createMock(PDOStatement::class);

        $statement->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['remaining_days' => 2]);

        $pdo = $this->createMock(PDO::class);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $leaveBalance = new LeaveBalance($pdo);

        $this->assertFalse(
            $leaveBalance->hasSufficient(1, 1, 2026, 5)
        );
    }

    public function testHasSufficientReturnsFalseWhenNoBalanceExists(): void
    {
        $statement = $this->createMock(PDOStatement::class);

        $statement->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $statement->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $pdo = $this->createMock(PDO::class);

        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $leaveBalance = new LeaveBalance($pdo);

        $this->assertFalse(
            $leaveBalance->hasSufficient(999, 1, 2026, 5)
        );
    }
}

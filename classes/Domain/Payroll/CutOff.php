<?php
declare(strict_types=1);

namespace App\Domain\Payroll;

use DateTimeImmutable;
use DateInterval;
use InvalidArgumentException;

final class CutOff
{
    private function __construct(
        private readonly string $start,
        private readonly string $end
    ) {}

    public static function fromDates(string $start, string $end): self
    {
        $s = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $e = DateTimeImmutable::createFromFormat('Y-m-d', $end);

        if ($s === false || $e === false) {
            throw new InvalidArgumentException('Invalid date format. Use Y-m-d.');
        }
        if ($s > $e) {
            throw new InvalidArgumentException('Start date must not be after end date.');
        }

        return new self($start, $end);
    }

    public function start(): string
    {
        return $this->start;
    }

    public function end(): string
    {
        return $this->end;
    }

    public function isFirstHalf(): bool
    {
        // First half: period starts on day 1–15
        return (int) (new DateTimeImmutable($this->start))->format('d') <= 15;
    }

    public function isSecondHalf(): bool
    {
        return !$this->isFirstHalf();
    }

    public function workingDays(): int
    {
        $current = new DateTimeImmutable($this->start);
        $end     = new DateTimeImmutable($this->end);
        $count   = 0;

        while ($current <= $end) {
            // 1=Mon … 5=Fri; 6=Sat, 7=Sun
            if ((int) $current->format('N') <= 5) {
                $count++;
            }
            $current = $current->add(new DateInterval('P1D'));
        }

        return $count;
    }

    public function label(): string
    {
        $month = (new DateTimeImmutable($this->start))->format('F Y');
        $half  = $this->isFirstHalf() ? '1st' : '2nd';
        return "{$month} {$half} Cut-off";
    }
}
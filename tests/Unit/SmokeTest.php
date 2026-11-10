<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_phpunit_is_wired_up(): void
    {
        $this->assertTrue(true);
    }
}
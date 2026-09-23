<?php

namespace Tests\Unit\Academic;

use App\Modules\Academic\Application\AcademicPeriodWeeks;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AcademicPeriodWeeksTest extends TestCase
{
    public function test_counts_monday_to_sunday_weeks_including_partial_edges(): void
    {
        $calculator = new AcademicPeriodWeeks;

        $this->assertSame(18, $calculator->calculate('2026-08-17', '2026-12-18'));
        $this->assertSame(16, $calculator->calculate('2026-08-17', '2026-12-04'));
        $this->assertSame(2, $calculator->calculate('2026-08-21', '2026-08-24'));
        $this->assertSame(1, $calculator->calculate('2026-08-19', '2026-08-21'));
    }

    public function test_rejects_an_inverted_period(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AcademicPeriodWeeks)->calculate('2026-08-21', '2026-08-20');
    }
}

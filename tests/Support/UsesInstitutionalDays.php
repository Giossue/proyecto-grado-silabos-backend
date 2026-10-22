<?php

namespace Tests\Support;

use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;

trait UsesInstitutionalDays
{
    protected function institutionDay(int $offset = 0): string
    {
        return CarbonImmutable::parse(InstitutionDate::todayOn(), InstitutionDate::timezone())->addDays($offset)->toDateString();
    }
}

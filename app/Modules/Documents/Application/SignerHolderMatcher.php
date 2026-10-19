<?php

namespace App\Modules\Documents\Application;

use Illuminate\Support\Str;

class SignerHolderMatcher
{
    private const MINIMUM_SHARED_WORDS = 2;

    public function matches(string $certificateHolder, string $accountName): bool
    {
        $holder = $this->words($certificateHolder);
        $account = $this->words($accountName);
        if ($holder === [] || $account === []) {
            return false;
        }
        $shared = count(array_intersect($holder, $account));

        return $shared >= self::MINIMUM_SHARED_WORDS && $shared >= min(count($holder), count($account));
    }

    /** @return list<string> */
    private function words(string $name): array
    {
        $clean = preg_replace('/[^A-Z\s]/', ' ', Str::upper(Str::ascii($name))) ?? '';

        return array_values(array_unique(array_filter(
            preg_split('/\s+/', trim($clean)) ?: [],
            fn (string $word): bool => $word !== '',
        )));
    }
}

<?php

namespace App\Modules\Documents\Application;

use Illuminate\Support\Str;

class SignerHolderMatcher
{
    private const MINIMUM_WORDS = 2;

    /**
     * The certificate holder and the account name must carry exactly the same words,
     * in any order, ignoring accents, case, punctuation and spacing.
     */
    public function matches(string $certificateHolder, string $accountName): bool
    {
        $holder = $this->words($certificateHolder);
        $account = $this->words($accountName);
        if ($holder === [] || $account === []) {
            return false;
        }
        sort($holder);
        sort($account);

        return count($holder) >= self::MINIMUM_WORDS && $holder === $account;
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

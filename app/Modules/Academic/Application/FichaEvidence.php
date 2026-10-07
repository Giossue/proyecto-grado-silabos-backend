<?php

namespace App\Modules\Academic\Application;

use Illuminate\Support\Str;

/** Elige, de los fragmentos de los documentos, los que hablan de una materia. */
final class FichaEvidence
{
    private const LIMIT = 16000;

    /**
     * @param  list<string>  $blocks
     * @return list<string>
     */
    public function for(string $subjectName, string $subjectCode, array $blocks): array
    {
        $needle = $this->normalize($subjectName);
        $code = $this->normalize($subjectCode);
        $picked = [];
        $total = 0;
        foreach ($blocks as $index => $block) {
            $haystack = $this->normalize($block);
            if (! str_contains($haystack, $needle) && ($code === '' || ! str_contains($haystack, $code))) {
                continue;
            }
            // El fragmento siguiente suele continuar la tabla de la misma materia.
            foreach ([$block, $blocks[$index + 1] ?? null] as $piece) {
                if ($piece === null || in_array($piece, $picked, true) || $total + mb_strlen($piece) > self::LIMIT) {
                    continue;
                }
                $picked[] = $piece;
                $total += mb_strlen($piece);
            }
        }

        return $picked;
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))) ?? '');
    }
}

<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Documents\Domain\SignatureAnchor;
use App\Modules\Syllabus\Application\IdentificationCard;

final class TemplateVariables
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return TemplateContext::active()->variables();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     */
    public static function resolve(array $snapshot): array
    {
        $values = [];
        foreach (self::definitions() as $key => $definition) {
            $values[$key] = self::value($definition, data_get($snapshot, $definition['source']));
        }

        return $values;
    }

    /**
     * @param  array{submitted_on: string, coordinator: string, approved_on: string, librarian: string, verified_on: string}  $approval
     * @return array<string, string>
     */
    public static function approval(array $approval): array
    {
        $keys = array_keys(array_filter(
            self::definitions(),
            fn (array $definition): bool => str_starts_with($definition['source'], 'approval.'),
        ));

        return array_intersect_key(self::resolve(['approval' => $approval]), array_flip($keys));
    }

    /**
     * @param  array<string, string>  $stamps
     * @return array<string, string>
     */
    public static function signatures(array $stamps): array
    {
        $values = [];
        foreach (self::definitions() as $key => $definition) {
            $role = str_starts_with($definition['source'], 'signature.') ? substr($definition['source'], strlen('signature.')) : null;
            if ($role !== null && isset($stamps[$role])) {
                $values[$key] = $stamps[$role];
            }
        }

        return $values;
    }

    /** @return list<array{key: string, label: string, sample: string}> */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::definitions() as $key => $definition) {
            $sample = str_starts_with($definition['source'], 'signature.')
                ? self::value($definition, null)
                : self::value($definition, $definition['sample'] ?? $definition['label']);
            $catalog[] = ['key' => $key, 'label' => $definition['label'], 'sample' => isset($definition['equals']) ? ($definition['sample'] ?? '') : $sample];
        }

        return $catalog;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function value(array $definition, mixed $raw): string
    {
        if (str_starts_with($definition['source'], 'signature.')) {
            return SignatureAnchor::text(substr($definition['source'], strlen('signature.')));
        }
        $text = is_scalar($raw) ? (string) $raw : '';
        if (($definition['format'] ?? null) === 'number') {
            $text = IdentificationCard::number($text);
        }

        return isset($definition['equals']) ? ($text === $definition['equals'] ? 'X' : '') : $text;
    }
}

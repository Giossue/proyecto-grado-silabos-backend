<?php

namespace App\Modules\Academic\Domain;

enum CurricularUnit: string
{
    case Basica = 'basica';
    case Profesional = 'profesional';
    case IntegracionCurricular = 'integracion_curricular';

    public function label(): string
    {
        return match ($this) {
            self::Basica => 'Unidad básica',
            self::Profesional => 'Unidad profesional',
            self::IntegracionCurricular => 'Unidad de integración curricular',
        };
    }

    public static function tryFromText(?string $text): ?self
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }
        if (($unit = self::tryFrom($text)) !== null) {
            return $unit;
        }
        $plain = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return match (true) {
            str_contains($plain, 'basic') => self::Basica,
            str_contains($plain, 'profesional') => self::Profesional,
            str_contains($plain, 'integracion'), str_contains($plain, 'titulacion') => self::IntegracionCurricular,
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $unit): string => $unit->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $unit): array => ['value' => $unit->value, 'label' => $unit->label()], self::cases());
    }
}

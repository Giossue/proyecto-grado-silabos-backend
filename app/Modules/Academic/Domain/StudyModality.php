<?php

namespace App\Modules\Academic\Domain;

enum StudyModality: string
{
    case Presencial = 'presencial';
    case Semipresencial = 'semipresencial';
    case EnLinea = 'en_linea';
    case ADistancia = 'a_distancia';
    case Hibrida = 'hibrida';

    public function label(): string
    {
        return match ($this) {
            self::Presencial => 'Presencial',
            self::Semipresencial => 'Semipresencial',
            self::EnLinea => 'En línea',
            self::ADistancia => 'A distancia',
            self::Hibrida => 'Híbrida',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $modality): string => $modality->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $modality): array => ['value' => $modality->value, 'label' => $modality->label()], self::cases());
    }
}

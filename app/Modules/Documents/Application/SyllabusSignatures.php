<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use Illuminate\Database\Eloquent\Collection;

class SyllabusSignatures
{
    public function signed(string $syllabusId, string $role): bool
    {
        return SyllabusSignature::query()
            ->where('silabo_id', $syllabusId)
            ->where('firmado_como', $role)
            ->exists();
    }

    /** @return Collection<int, SyllabusSignature> */
    public function of(string $syllabusId): Collection
    {
        return SyllabusSignature::query()
            ->where('silabo_id', $syllabusId)
            ->with('signer:id,nombre')
            ->get()
            ->sortBy(fn (SyllabusSignature $signature): int|false => array_search($signature->firmado_como, SyllabusSignature::ORDER, true))
            ->values();
    }

    /** @return list<array{role: string, name: string, signed_at: string}> */
    public function summary(string $syllabusId): array
    {
        return array_values($this->of($syllabusId)->map(fn (SyllabusSignature $signature): array => [
            'role' => $signature->firmado_como,
            'name' => $signature->signer->nombre,
            'signed_at' => $signature->firmado_en->toIso8601String(),
        ])->all());
    }
}

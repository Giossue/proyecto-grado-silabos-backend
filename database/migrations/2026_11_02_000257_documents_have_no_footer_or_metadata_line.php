<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FOOTER = ['text' => 'Página {page} de {pages}', 'size' => 8, 'color' => '#7F7F7F'];

    private const METADATA = ['text' => 'Revisión {revision} · {subject} ({code}) · huella {fingerprint} · generado {generated}', 'size' => 7, 'color' => '#7F7F7F'];

    public function up(): void
    {
        $this->rewrite(function (array $document): array {
            unset($document['footer'], $document['metadata']);

            return $document;
        });
    }

    public function down(): void
    {
        $this->rewrite(fn (array $document): array => [...$document, 'footer' => self::FOOTER, 'metadata' => self::METADATA]);
    }

    private function rewrite(callable $change): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (! is_array($definition) || ! is_array($definition['document'] ?? null)) {
                continue;
            }
            $document = $change($definition['document']);
            if ($document === $definition['document']) {
                continue;
            }
            $definition['document'] = $document;
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)->update([
                'definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
        }
    }
};

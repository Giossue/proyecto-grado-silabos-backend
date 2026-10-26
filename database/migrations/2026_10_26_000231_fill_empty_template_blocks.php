<?php

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * La activación de ADR-0012 guardó bloques sin su valor inicial (la sección 6 quedaba
 * vacía). Normaliza el contenido de la plantilla para que cada bloque tenga sus datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $template = SyllabusTemplate::query()->first();
        if ($template === null) {
            return;
        }
        app(TemplateContext::class)->using($template->definition(), function () use ($template): void {
            foreach (FieldDefinition::query()->where('plantilla_id', $template->id)->get() as $field) {
                $empty = array_filter($field->contenido['content'] ?? [], fn (mixed $node): bool => is_array($node)
                    && ($node['type'] ?? null) === 'documentBlock' && ($node['attrs']['value'] ?? []) === []);
                if ($empty !== []) {
                    $field->update(['contenido' => FieldDocument::normalize($field->contenido)]);
                    echo "  bloque completado: {$field->clave}".PHP_EOL;
                }
            }
        });
    }

    public function down(): void {}
};

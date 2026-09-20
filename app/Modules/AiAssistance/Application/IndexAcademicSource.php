<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Jobs\EmbedAcademicSourceJob;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AcademicSourceFragment;
use App\Modules\Configuration\Application\AcademicSourceDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Support\CanonicalHasher;
use Illuminate\Support\Facades\DB;

class IndexAcademicSource
{
    public function __construct(
        private readonly StructuredSourceFragmenter $fragmenter,
        private readonly CanonicalHasher $hasher,
        private readonly AcademicSourceDocument $documents,
    ) {}

    public function execute(AcademicSource $source): int
    {
        AcademicSourceFragment::query()->where('fuente_academica_id', $source->id)->delete();
        $document = $source->documento;
        if (($document['content'] ?? []) === []) {
            $document = $this->documents->fromLegacyText($source->contenido);
        }
        $fragments = $this->fragmenter->fragment(
            $document,
            (int) config('ai.limits.source_fragment_characters'),
        );
        foreach ($fragments as $position => $fragment) {
            AcademicSourceFragment::query()->create([
                'fuente_academica_id' => $source->id,
                'ruta_encabezados' => $fragment['heading_path'],
                'titulo_fragmento' => $fragment['title'],
                'pagina_inicio' => $fragment['page_start'],
                'pagina_fin' => $fragment['page_end'],
                'bloques_origen' => $fragment['source_blocks'],
                'posicion_fragmento' => $position,
                'contenido_fragmento' => $fragment['content'],
                'huella_fragmento' => $this->hasher->hash([
                    'path' => $fragment['heading_path'],
                    'content' => $fragment['content'],
                ]),
            ]);
        }

        if (config('ai.embeddings.driver') === 'openai') {
            DB::afterCommit(function () use ($source): void {
                EmbedAcademicSourceJob::dispatch($source->id);
            });
        }

        return count($fragments);
    }
}

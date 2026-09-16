<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AcademicSourceFragment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Support\CanonicalHasher;

class IndexAcademicSource
{
    public function __construct(
        private readonly MarkdownSourceFragmenter $fragmenter,
        private readonly CanonicalHasher $hasher,
    ) {}

    public function execute(AcademicSource $source): int
    {
        AcademicSourceFragment::query()->where('fuente_academica_id', $source->id)->delete();
        $fragments = $this->fragmenter->fragment(
            $source->contenido,
            (int) config('ai.limits.source_fragment_characters'),
        );
        foreach ($fragments as $position => $fragment) {
            AcademicSourceFragment::query()->create([
                'fuente_academica_id' => $source->id,
                'ruta_encabezados' => $fragment['heading_path'],
                'titulo_fragmento' => $fragment['title'],
                'posicion_fragmento' => $position,
                'contenido_fragmento' => $fragment['content'],
                'huella_fragmento' => $this->hasher->hash([
                    'path' => $fragment['heading_path'],
                    'content' => $fragment['content'],
                ]),
            ]);
        }

        return count($fragments);
    }
}

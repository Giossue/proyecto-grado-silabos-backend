<?php

namespace App\Modules\Configuration\Application;

use App\Models\User;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\TemplateStyle;
use App\Modules\Configuration\Domain\TemplateVersionDiff;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;

final class TemplateVersions
{
    public const SYSTEM = 'Sistema';

    public function ensureInitial(SyllabusTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $this->lock($template);
            if (! TemplateVersion::query()->where('plantilla_id', $template->id)->exists()) {
                $this->store($template, null);
            }
        });
    }

    public function record(SyllabusTemplate $template, User $actor): TemplateVersion
    {
        return DB::transaction(function () use ($template, $actor): TemplateVersion {
            $this->lock($template);

            return $this->store($template, $actor);
        });
    }

    /** @return list<array{id: string, number: int, saved_at: string, saved_by: string, sections: int, fields: int, current: bool}> */
    public function list(SyllabusTemplate $template): array
    {
        $this->ensureInitial($template);
        $current = TemplateJsonExport::export($template->fresh() ?? $template);

        return array_values(TemplateVersion::query()
            ->with('author')
            ->where('plantilla_id', $template->id)
            ->orderByDesc('numero_version_plantilla')
            ->get()
            ->values()
            ->map(fn (TemplateVersion $version, int $index): array => [
                ...$this->summary($version),
                'current' => $index === 0 && TemplateVersionDiff::changes($version->contenido, $current) === [],
            ])
            ->all());
    }

    public function find(SyllabusTemplate $template, string $id): TemplateVersion
    {
        return TemplateVersion::query()->with('author')->where('plantilla_id', $template->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function show(TemplateVersion $version): array
    {
        $json = $version->contenido;
        $document = is_array($json['document'] ?? null) ? $json['document'] : [];

        return [
            ...$this->summary($version),
            'json' => $json,
            'draft' => [
                'title' => (string) ($document['title']['text'] ?? ''),
                'layout' => TemplateStyle::layout($document),
                'sections' => array_map(fn (array $section): array => [
                    'key' => (string) $section['key'],
                    'title' => (string) $section['title'],
                    'orientation' => ($section['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
                    'fields' => array_map(fn (array $field): array => [
                        'key' => (string) $field['key'],
                        'label' => (string) ($field['label'] ?? ''),
                        'quien_llena' => (string) $field['quien_llena'],
                        'content' => DocumentHtml::fromHtml($field['content']),
                        ...(is_array($field['show_if'] ?? null) ? ['show_if' => $field['show_if']] : []),
                    ], array_values($section['fields'] ?? [])),
                ], array_values($json['sections'] ?? [])),
            ],
        ];
    }

    /** @return list<string> */
    public function compare(SyllabusTemplate $template, TemplateVersion $version): array
    {
        return TemplateVersionDiff::changes($version->contenido, TemplateJsonExport::export($template));
    }

    /** @return array{id: string, number: int, saved_at: string, saved_by: string, sections: int, fields: int} */
    private function summary(TemplateVersion $version): array
    {
        $sections = is_array($version->contenido['sections'] ?? null) ? $version->contenido['sections'] : [];

        return [
            'id' => $version->id,
            'number' => $version->numero,
            'saved_at' => $version->guardada_en->toIso8601String(),
            'saved_by' => $version->author !== null ? $version->author->nombre : self::SYSTEM,
            'sections' => count($sections),
            'fields' => array_sum(array_map(fn (mixed $section): int => is_array($section) && is_array($section['fields'] ?? null) ? count($section['fields']) : 0, $sections)),
        ];
    }

    private function lock(SyllabusTemplate $template): void
    {
        SyllabusTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
    }

    private function store(SyllabusTemplate $template, ?User $actor): TemplateVersion
    {
        $number = (int) TemplateVersion::query()->where('plantilla_id', $template->id)->max('numero_version_plantilla') + 1;
        $version = TemplateVersion::query()->create([
            'plantilla_id' => $template->id,
            'numero' => $number,
            'contenido' => TemplateJsonExport::export($template->fresh() ?? $template),
            'guardada_por' => $actor?->id,
        ]);

        return $version->refresh();
    }
}

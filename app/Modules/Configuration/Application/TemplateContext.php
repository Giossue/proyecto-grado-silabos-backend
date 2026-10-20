<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Domain\TemplateStyle;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Database\QueryException;

final class TemplateContext
{
    private ?TemplateDefinition $current = null;

    /** @var list<TemplateDefinition> */
    private array $stack = [];

    public static function active(): TemplateDefinition
    {
        return app(self::class)->definition();
    }

    public function definition(): TemplateDefinition
    {
        $top = end($this->stack);
        if ($top instanceof TemplateDefinition) {
            return $top;
        }

        return $this->current ??= $this->load();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function using(TemplateDefinition $definition, callable $callback): mixed
    {
        $this->stack[] = $definition;
        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $snapshot
     * @param  callable(): T  $callback
     * @return T
     */
    public function forSnapshot(array $snapshot, callable $callback): mixed
    {
        return $this->using(self::fromSnapshot($snapshot), $callback);
    }

    public function forget(): void
    {
        $this->current = null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): TemplateDefinition
    {
        if (is_array($snapshot['template_definition'] ?? null)) {
            return TemplateDefinition::trusted($snapshot['template_definition']);
        }
        $official = OfficialTemplate::definition();
        $mapping = is_array($snapshot['document_mapping'] ?? null) ? $snapshot['document_mapping'] : null;

        return TemplateDefinition::trusted([...$official->toArray(), 'document' => TemplateStyle::fromLegacyMapping($official->document(), $mapping)]);
    }

    private function load(): TemplateDefinition
    {
        try {
            $stored = SyllabusTemplate::query()->value('definicion_plantilla');
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '42P01') {
                throw $exception;
            }
            $stored = null;
        }
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) && $decoded !== [] ? TemplateDefinition::trusted($decoded) : OfficialTemplate::definition();
    }
}

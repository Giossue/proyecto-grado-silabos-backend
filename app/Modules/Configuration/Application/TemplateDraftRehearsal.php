<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\SyllabusSectionPayload;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TemplateDraftRehearsal
{
    public const UNAVAILABLE = 'No se pudo calcular qué sílabos cambian ahora.';

    private const NO_IMPACT = ['affected' => false, 'counted' => true, 'in_progress' => 0, 'keep' => 0, 'lose' => 0, 'back_to_draft' => 0, 'careers' => []];

    private const TIMEOUT_STATES = ['57014', '55P03'];

    public function __construct(
        private readonly SyllabusTemplateProvisioner $provisioner,
        private readonly TemplateContext $context,
        private readonly SubjectProfileFields $profiles,
        private readonly InProgressWork $work,
    ) {}

    /**
     * @param  list<array{key: string, title: string, orientation?: string, fields?: list<array{key: string, label?: string|null, quien_llena: string, content: array<string, mixed>}>}>  $sections
     * @return array{sections: list<array<string, mixed>>, sheets: list<array<string, mixed>>}
     */
    public function preview(SyllabusTemplate $template, array $sections, ?TemplateDefinition $definition): array
    {
        $preview = $this->rehearse(function () use ($template, $sections, $definition): array {
            $saved = $this->provisioner->replaceStructure($template, $sections, $definition);
            $context = ['identification' => IdentificationCard::sample()];

            return [
                'sections' => array_values($saved->sections
                    ->map(fn (TemplateSection $section): array => SyllabusSectionPayload::build($section, fn (FieldDefinition $field): mixed => $field->contenido, $context))
                    ->all()),
                'sheets' => TemplatePresentation::sections($saved),
            ];
        });
        if ($preview === null) {
            throw ValidationException::withMessages(['draft' => 'No se pudo armar la vista previa ahora. Intente de nuevo.']);
        }

        return $preview;
    }

    /**
     * @param  list<array{key: string, title: string, orientation?: string, fields?: list<array{key: string, label?: string|null, quien_llena: string, content: array<string, mixed>}>}>  $sections
     * @return array{affected: bool, counted: bool, message?: string, in_progress: int, keep: int, lose: int, back_to_draft: int, careers: list<array{name: string, in_progress: int, keep: int, lose: int, back_to_draft: int}>}
     */
    public function impact(SyllabusTemplate $template, array $sections, ?TemplateDefinition $definition): array
    {
        $process = SyllabusProcess::query()->where('estado', SyllabusProcess::STATE_PAUSED)->first();
        if (! $process instanceof SyllabusProcess) {
            return self::NO_IMPACT;
        }

        $impact = $this->rehearse(function () use ($process, $template, $sections, $definition): array {
            $syllabi = Syllabus::query()
                ->whereHas('convocation', fn (Builder $query) => $query->where('proceso_id', $process->id)
                    ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED]))
                ->where('estado', '!=', 'sin_iniciar')
                ->with(['convocation.career', 'parallel'])
                ->orderBy('silabo_id')
                ->get();
            if ($syllabi->isEmpty()) {
                return self::NO_IMPACT;
            }
            $written = [];
            foreach ($syllabi as $syllabus) {
                $written[$syllabus->id] = $this->writtenText($syllabus);
            }
            $this->provisioner->replaceStructure($template, $sections, $definition);
            $this->context->forget();

            $total = ['in_progress' => 0, 'keep' => 0, 'lose' => 0, 'back_to_draft' => 0];
            $careers = [];
            foreach ($syllabi as $syllabus) {
                $sent = in_array($syllabus->estado, InProgressWork::SENT_STATES, true);
                $career = (string) ($syllabus->convocation->career->nombre ?? '');
                $adapted = $this->work->adaptToTemplate($syllabus);
                $lost = $this->losesText($adapted, $written[$syllabus->id]);
                $row = ['in_progress' => 1, 'keep' => $lost ? 0 : 1, 'lose' => $lost ? 1 : 0, 'back_to_draft' => $sent && $adapted->estado === 'borrador' ? 1 : 0];
                $careers[$career] ??= ['name' => $career, 'in_progress' => 0, 'keep' => 0, 'lose' => 0, 'back_to_draft' => 0];
                foreach ($row as $key => $count) {
                    $total[$key] += $count;
                    $careers[$career][$key] += $count;
                }
            }
            ksort($careers);

            return ['affected' => true, 'counted' => true, ...$total, 'careers' => array_values($careers)];
        });

        return $impact ?? ['affected' => true, 'counted' => false, 'message' => self::UNAVAILABLE, 'in_progress' => 0, 'keep' => 0, 'lose' => 0, 'back_to_draft' => 0, 'careers' => []];
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult|null
     */
    private function rehearse(Closure $work): mixed
    {
        DB::beginTransaction();
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [
                    (string) config('templates.rehearsal.lock_timeout', '2s'),
                    (string) config('templates.rehearsal.statement_timeout', '20s'),
                ]);
            }

            return $work();
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), self::TIMEOUT_STATES, true)) {
                return null;
            }

            throw $exception;
        } finally {
            DB::rollBack();
            $this->context->forget();
        }
    }

    /** @return array<string, mixed> */
    private function writtenText(Syllabus $syllabus): array
    {
        $templates = $this->profiles->templates((string) $syllabus->parallel->asignatura_id);
        $values = FieldValue::query()->where('silabo_id', $syllabus->id)->get()->keyBy('definicion_campo_id');
        $written = [];
        foreach (FieldDefinition::query()->get() as $field) {
            if (! SubjectProfileFields::teacherEditable($field, $templates)) {
                continue;
            }
            $value = $values->get($field->id)?->valor;
            if (is_array($value) && ! FieldDocument::isEmpty($value) && $value != ($templates[$field->id] ?? $field->contenido)) {
                $written[$field->clave] = $value;
            }
        }

        return $written;
    }

    /** @param  array<string, mixed>  $written */
    private function losesText(Syllabus $syllabus, array $written): bool
    {
        if ($written === []) {
            return false;
        }
        $templates = $this->profiles->templates((string) $syllabus->parallel->asignatura_id);
        $fields = FieldDefinition::query()->get()->keyBy(fn (FieldDefinition $field): string => $field->clave);
        $values = FieldValue::query()->where('silabo_id', $syllabus->id)->get()->keyBy('definicion_campo_id');
        foreach ($written as $key => $before) {
            $field = $fields->get($key);
            $after = $field instanceof FieldDefinition ? $values->get($field->id)?->valor : null;
            if ($after == $before) {
                continue;
            }
            if (! $field instanceof FieldDefinition || ! is_array($after) || FieldDocument::isEmpty($after) || $after == ($templates[$field->id] ?? $field->contenido)) {
                return true;
            }
        }

        return false;
    }
}

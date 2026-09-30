<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\CurricularData;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DeclareProjectData
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
    ) {}

    /**
     * @param  array{label: string, type: string}  $data
     * @return array{key: string, label: string, sample: string, type: string}
     */
    public function execute(array $data, User $actor, Request $request): array
    {
        $this->locks->assertTemplateEditable();
        $type = $data['type'];
        $base = trim($data['label']);

        $concept = DB::transaction(function () use ($actor, $base, $request, $type): CurricularConcept {
            foreach ([$base, "{$base} ({$type})"] as $label) {
                $concept = CurricularConcept::query()->where('clave_concepto', self::keyFor($label))->lockForUpdate()->first();
                if ($concept === null) {
                    break;
                }
                if ($concept->tipo_concepto === $type) {
                    return $concept;
                }
            }
            if ($concept !== null) {
                throw ValidationException::withMessages(['label' => "Ya existe «{$concept->etiqueta_concepto}» con otra forma. Use otro nombre."]);
            }
            $concept = CurricularConcept::query()->create([
                'clave_concepto' => self::keyFor($label), 'etiqueta_concepto' => $label,
                'tipo_concepto' => $type, 'columnas_concepto' => null,
            ]);
            $this->audit->execute(
                actorId: $actor->id, roleAssignmentId: $this->roles->resolve($request)?->id, action: 'curriculo.concepto_declarado',
                resourceType: 'concepto_curricular', resourceId: $concept->id, result: 'exito',
            );

            return $concept;
        });

        return [
            'key' => CurricularData::VARIABLE_PREFIX.$concept->clave_concepto, 'label' => $concept->etiqueta_concepto,
            'sample' => $concept->etiqueta_concepto, 'type' => $concept->tipo_concepto,
        ];
    }

    public static function keyFor(string $label): string
    {
        $key = rtrim(Str::limit(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii($label))), '_'), 90, ''), '_');

        return preg_match('/^[a-z]/', $key) === 1 ? $key : 'dato_'.$key;
    }
}

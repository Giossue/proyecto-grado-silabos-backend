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

/**
 * Declara un dato @ProyectoCarrera (I-155) al insertarlo en la hoja, sin preguntar nada:
 * el nombre sale del contexto y el dato es por asignatura. Un nombre ya declarado con la
 * misma forma se reutiliza; con otra forma se crea «Nombre (lista)» o «Nombre (texto)»,
 * porque los valores confirmados dependen de la forma.
 */
final class DeclareProjectData
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
    ) {}

    /**
     * @param  array{label: string, type: string, scope?: string}  $data
     * @return array{key: string, label: string, sample: string, type: string, scope: string}
     */
    public function execute(array $data, User $actor, Request $request): array
    {
        $this->locks->assertTemplateEditable();
        $type = $data['type'];
        $scope = $data['scope'] ?? 'asignatura';
        $base = trim($data['label']);

        $concept = DB::transaction(function () use ($actor, $base, $request, $scope, $type): CurricularConcept {
            foreach ([$base, "{$base} ({$type})"] as $label) {
                $concept = CurricularConcept::query()->where('clave_concepto', self::keyFor($label))->lockForUpdate()->first();
                if ($concept === null) {
                    break;
                }
                if ($concept->tipo_concepto === $type && $concept->ambito_concepto === $scope) {
                    return $concept;
                }
            }
            if ($concept !== null) {
                throw ValidationException::withMessages(['label' => "Ya existe «{$concept->etiqueta_concepto}» con otra forma. Use otro nombre."]);
            }
            $concept = CurricularConcept::query()->create([
                'clave_concepto' => self::keyFor($label), 'etiqueta_concepto' => $label,
                'tipo_concepto' => $type, 'ambito_concepto' => $scope, 'columnas_concepto' => null,
            ]);
            $this->audit->execute(
                actorId: $actor->id, roleAssignmentId: $this->roles->resolve($request)?->id, action: 'curriculo.concepto_declarado',
                resourceType: 'concepto_curricular', resourceId: $concept->id, result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $concept;
        });

        return [
            'key' => CurricularData::VARIABLE_PREFIX.$concept->clave_concepto, 'label' => $concept->etiqueta_concepto,
            'sample' => $concept->etiqueta_concepto, 'type' => $concept->tipo_concepto, 'scope' => $concept->ambito_concepto,
        ];
    }

    /** Clave estable del dato: el nombre en minúsculas, sin tildes y con guiones bajos. */
    public static function keyFor(string $label): string
    {
        $key = rtrim(Str::limit(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii($label))), '_'), 90, ''), '_');

        return preg_match('/^[a-z]/', $key) === 1 ? $key : 'dato_'.$key;
    }
}

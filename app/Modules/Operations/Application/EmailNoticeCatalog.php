<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;

/**
 * Avisos que cada rol puede recibir por correo y que cada persona elige en Configuración › Notificaciones.
 * Sin elegir, todos llegan. Los correos de la cuenta (invitación, contraseña, verificación) no están aquí:
 * no se pueden apagar.
 */
final class EmailNoticeCatalog
{
    public const DAILY_SUMMARY = 'coordinacion.resumen_diario';

    public const TEACHER_SUMMARY = 'docente.resumen_diario';

    public const LIBRARY_SUMMARY = 'biblioteca.resumen_diario';

    public const SYLLABUS_RESTARTED = 'silabo.reiniciado';

    /** Avisos que comparten un mismo interruptor: tipo => opción que lo apaga. */
    private const SWITCH_OF = [
        'silabo.eliminado' => self::SYLLABUS_RESTARTED,
        'silabo.regenerado' => self::SYLLABUS_RESTARTED,
        'proceso.plantilla_rehecho' => 'silabo.devuelto_borrador',
    ];

    /** @var array<string, array<string, array{label: string, description: string}>> rol => tipo => textos */
    private const BY_ROLE = [
        'docente' => [
            self::TEACHER_SUMMARY => ['label' => 'Resumen diario', 'description' => 'Sílabos por elaborar o corregir y el plazo, de lunes a viernes, solo si hay pendientes'],
            'convocatoria.abierta' => ['label' => 'Convocatoria abierta', 'description' => 'Tiene sílabos por elaborar'],
            'convocatoria.reanudada' => ['label' => 'Convocatoria reanudada', 'description' => 'Puede seguir con sus sílabos tras una pausa'],
            'silabo.correccion_solicitada' => ['label' => 'Corrección solicitada', 'description' => 'Coordinación o Biblioteca le pide corregir un sílabo'],
            'silabo.devuelto_borrador' => ['label' => 'Sílabo devuelto a borrador', 'description' => 'Cambió un dato de la carrera, de la malla o la plantilla'],
            'silabo.aprobado' => ['label' => 'Sílabo aprobado', 'description' => 'Su sílabo quedó aprobado, con el PDF listo'],
            self::SYLLABUS_RESTARTED => ['label' => 'Sílabo reiniciado', 'description' => 'Su sílabo volvió a empezar: Coordinación lo reinició o cambió la malla o el paralelo'],
            'proceso.cierre_proximo' => ['label' => 'Cierre de la convocatoria', 'description' => '3 días antes del último día de entrega, si tiene sílabos sin aprobar'],
        ],
        'coordinador' => [
            self::DAILY_SUMMARY => ['label' => 'Resumen diario', 'description' => 'Sílabos por revisar, de lunes a viernes, solo si hay pendientes'],
            'proceso.reanudado' => ['label' => 'Convocatoria reanudada por Administración', 'description' => 'Se retoman las revisiones tras una pausa institucional'],
            'proceso.cierre_proximo' => ['label' => 'Cierre de la convocatoria', 'description' => '3 días antes del último día de entrega, si su carrera tiene sílabos sin aprobar'],
        ],
        'bibliotecario' => [
            self::LIBRARY_SUMMARY => ['label' => 'Resumen diario', 'description' => 'Sílabos por revisar, de lunes a viernes, solo si hay pendientes'],
            'coordinacion.aprobado' => ['label' => 'Sílabo por revisar', 'description' => 'Coordinación aprobó un sílabo y espera la revisión de biblioteca'],
            'silabo.reenviado_biblioteca' => ['label' => 'Sílabo reenviado', 'description' => 'El docente corrigió la bibliografía y la reenvió'],
            'proceso.reanudado' => ['label' => 'Convocatoria reanudada por Administración', 'description' => 'Se retoman las revisiones tras una pausa institucional'],
        ],
    ];

    /** @return list<array{type: string, label: string, description: string, enabled: bool}> */
    public function optionsFor(User $user): array
    {
        $options = [];
        foreach ($this->typesFor($user) as $type => $texts) {
            $options[] = ['type' => $type, ...$texts, 'enabled' => $this->wants($user, $type)];
        }

        return $options;
    }

    /** @return array<string, array{label: string, description: string}> */
    public function typesFor(User $user): array
    {
        $roles = RoleAssignment::query()->effective()->where('usuario_id', $user->id)->pluck('rol')->unique();
        $types = [];
        foreach (self::BY_ROLE as $role => $notices) {
            if ($roles->contains($role)) {
                $types = [...$types, ...$notices];
            }
        }

        return $types;
    }

    public function wants(User $user, string $type): bool
    {
        $preferences = $user->preferencias_correo;

        $type = self::SWITCH_OF[$type] ?? $type;

        return ! is_array($preferences) || ($preferences[$type] ?? true) !== false;
    }
}

<?php

namespace App\Modules\Configuration\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class SaveFacultyLogo
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly InstitutionalLogos $logos,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    public function execute(User $actor, UploadedFile $file, Request $request): Faculty
    {
        $activeRole = $this->roles->resolve($request);
        abort_unless(
            $activeRole?->role->codigo_rol === RoleCode::Coordinator->value
                && is_string($activeRole->carrera_id),
            403,
        );
        $facultyId = Career::query()->whereKey($activeRole->carrera_id)->value('facultad_id');
        abort_unless(is_string($facultyId), 404);
        $careerIds = Career::query()->where('facultad_id', $facultyId)->pluck('id')->all();
        $this->locks->assertInstitutionalRecordEditable($careerIds);

        return $this->work->replace($request, function () use ($activeRole, $actor, $file, $request): Faculty {
            $career = Career::query()
                ->with('faculty')
                ->lockForUpdate()
                ->findOrFail($activeRole->carrera_id);
            $faculty = $career->faculty;
            abort_unless($faculty instanceof Faculty, 404);

            $this->logos->storeFaculty($faculty, $file);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'facultad.logo_actualizado',
                resourceType: 'facultad',
                resourceId: $faculty->id,
                result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $faculty->refresh();
        }, $careerIds);
    }
}

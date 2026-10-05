<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\SaveSubjectProfile;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Presentation\Http\Requests\SaveSubjectProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectProfileController extends Controller
{
    public function show(Subject $asignatura, Request $request, SaveSubjectProfile $action, SubjectProfileFields $profile): JsonResponse
    {
        $action->authorize($asignatura, $request);

        return response()->json($this->payload($asignatura->id, $profile->values($asignatura->id), $profile));
    }

    public function update(Subject $asignatura, SaveSubjectProfileRequest $request, SaveSubjectProfile $action, SubjectProfileFields $profile): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array<string, mixed> $values */
        $values = $request->validated('values');

        return response()->json($this->payload($asignatura->id, $action->execute($asignatura, $values, $actor, $request), $profile));
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $values
     * @return array<string, mixed>
     */
    private function payload(string $subjectId, array $values, SubjectProfileFields $profile): array
    {
        return [
            'fields' => $profile->fields()->map(fn ($field): array => [
                'id' => $field->id,
                'key' => $field->clave,
                'label' => $field->etiqueta,
                'help' => $field->ayuda,
                'section' => $field->block?->section?->titulo,
            ])->all(),
            'values' => (object) $values,
            'complete' => $profile->completeBySubject([$subjectId])[$subjectId] ?? false,
        ];
    }
}

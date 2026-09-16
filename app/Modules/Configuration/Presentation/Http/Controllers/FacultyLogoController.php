<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Models\User;
use App\Modules\Configuration\Application\Actions\SaveFacultyLogo;
use App\Modules\Configuration\Presentation\Http\Requests\StoreFacultyLogoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class FacultyLogoController
{
    public function store(StoreFacultyLogoRequest $request, SaveFacultyLogo $action): JsonResponse
    {
        $actor = $request->user();
        $file = $request->file('logo');
        abort_unless($actor instanceof User && $file instanceof UploadedFile, 422);

        $action->execute($actor, $file, $request);

        return response()->json(['message' => 'Logo de la facultad actualizado.']);
    }
}

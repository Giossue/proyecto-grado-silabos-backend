<?php

namespace App\Modules\Syllabus\Domain\Policies;

use App\Models\User;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class SyllabusRevisionPolicy
{
    public function view(User $user, SyllabusRevision $revision): bool
    {
        return $user->can('view', $revision->syllabus);
    }

    public function review(User $user, SyllabusRevision $revision): bool
    {
        return $user->can('review', $revision->syllabus);
    }

    public function decide(User $user, SyllabusRevision $revision): Response
    {
        return Gate::forUser($user)->inspect('decide', $revision->syllabus);
    }
}

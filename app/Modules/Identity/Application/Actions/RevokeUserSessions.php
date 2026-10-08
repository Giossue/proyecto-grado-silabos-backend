<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevokeUserSessions
{
    public function execute(User $user, ?string $exceptSessionId = null): int
    {
        $user->forceFill(['codigo_recordarme' => Str::random(60)])->save();

        return DB::table((string) config('session.table', 'sesiones'))
            ->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}

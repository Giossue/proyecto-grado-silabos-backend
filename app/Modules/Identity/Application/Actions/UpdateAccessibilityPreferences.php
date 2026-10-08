<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;

class UpdateAccessibilityPreferences
{
    /** @param array{high_contrast: bool, text_size: string, no_motion: bool, underline_links: bool, wide_spacing: bool} $preferences */
    public function execute(User $user, array $preferences): User
    {
        $user->preferencias_accesibilidad = $preferences;
        $user->save();

        return $user;
    }
}

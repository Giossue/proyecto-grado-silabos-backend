<?php

use Laravel\Fortify\Features;

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'correo_electronico',

    'email' => 'correo_electronico',

    'lowercase_usernames' => true,

    'home' => '/panel',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    'views' => false,

    'features' => [
        Features::resetPasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => false,
        ]),
    ],

];

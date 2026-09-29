<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            'correo_electronico' => fake()->unique()->safeEmail(),
            'correo_verificado_en' => now(),
            'contrasena' => static::$password ??= Hash::make('password'),
            'activo' => true,
            'codigo_recordarme' => Str::random(10),
            'secreto_dos_factores' => null,
            'codigos_recuperacion_dos_factores' => null,
            'dos_factores_confirmado_en' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'correo_verificado_en' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'secreto_dos_factores' => encrypt('secret'),
            'codigos_recuperacion_dos_factores' => encrypt(json_encode(['recovery-code-1'])),
            'dos_factores_confirmado_en' => now(),
        ]);
    }
}

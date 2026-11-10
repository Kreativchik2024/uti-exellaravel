<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Правила приложения: по умолчанию — активный исполнитель
            'role' => 'executor',
            'is_active' => true,
            // Согласие с действующей редакцией правил (без него сайт отдаёт только окно согласия)
            'consent_version' => config('legal.version'),
            'consent_at' => now(),
        ];
    }

    /** Зарегистрирован, но ещё не подтверждён администратором. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function master(): static
    {
        return $this->state(fn () => ['role' => 'master']);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

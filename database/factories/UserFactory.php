<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
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
            'first_name' => strtoupper(fake()->firstName()),
            'last_name' => strtoupper(fake()->lastName()),
            'middle_name' => null,
            'extension_name' => null,
            'sex' => fake()->randomElement(['Male', 'Female']),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => null,
            'role' => User::ROLE_FARMER,
            'account_status' => User::STATUS_VERIFIED,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123'),
            'remember_token' => Str::random(10),
        ];
    }

    public function farmer(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_FARMER]);
    }

    public function mao(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_MAO]);
    }

    public function barangay(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_BARANGAY]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['account_status' => User::STATUS_PENDING]);
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

<?php

namespace Tests\Feature\Auth;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        $barangay = Barangay::create(['name' => 'San Fermin']);

        return array_merge([
            'last_name' => 'dela cruz',
            'first_name' => 'juan',
            'middle_name' => 'santos',
            'sex' => 'Male',
            'barangay_id' => $barangay->id,
            'email_or_phone' => 'juan@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'birthdate' => '1990-05-15',
            'address' => 'Purok 1, San Fermin',
        ], $overrides);
    }

    public function test_farmer_can_register_with_email(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'juan@example.com')
            ->assertJsonPath('user.role', User::ROLE_FARMER)
            ->assertJsonPath('user.account_status', User::STATUS_PENDING);

        $this->assertDatabaseHas('users', ['email' => 'juan@example.com']);
        $this->assertDatabaseHas('farmer_profiles', ['email_or_phone' => 'juan@example.com']);
    }

    public function test_farmer_can_register_with_philippine_phone_number(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'email_or_phone' => '09171234567',
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('user.phone_number', '09171234567')
            ->assertJsonPath('user.email', null);
    }

    public function test_names_are_saved_in_uppercase(): void
    {
        $this->postJson('/api/register', $this->validPayload())->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'first_name' => 'JUAN',
            'last_name' => 'DELA CRUZ',
            'middle_name' => 'SANTOS',
        ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $this->postJson('/api/register', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email_or_phone');
    }

    public function test_registration_fails_with_invalid_email_or_phone(): void
    {
        $this->postJson('/api/register', $this->validPayload(['email_or_phone' => '12345']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email_or_phone');
    }

    public function test_registration_fails_with_weak_password(): void
    {
        $this->postJson('/api/register', $this->validPayload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_when_password_confirmation_does_not_match(): void
    {
        $this->postJson('/api/register', $this->validPayload([
            'password_confirmation' => 'Different123',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_invalid_barangay(): void
    {
        $this->postJson('/api/register', $this->validPayload(['barangay_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('barangay_id');
    }

    public function test_registration_fails_when_required_fields_are_missing(): void
    {
        $this->postJson('/api/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'last_name', 'first_name', 'sex', 'barangay_id',
                'email_or_phone', 'password', 'birthdate', 'address',
            ]);
    }
}

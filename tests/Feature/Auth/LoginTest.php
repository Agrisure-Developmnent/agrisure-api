<?php

namespace Tests\Feature\Auth;

use App\Models\LoginOtp;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'farmer@example.com',
            'password' => Hash::make('Password123'),
        ], $overrides));
    }

    public function test_user_can_login_with_email_and_receives_otp(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/login', [
            'login' => 'farmer@example.com',
            'password' => 'Password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('requires_otp', true)
            ->assertJsonPath('delivery_method', 'email')
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('login_otps', [
            'user_id' => $user->id,
            'delivery_method' => 'email',
            'is_used' => false,
        ]);
    }

    public function test_user_can_login_with_phone_number_and_receives_sms_otp(): void
    {
        $user = $this->makeUser(['email' => null, 'phone_number' => '09171234567']);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->once()->andReturn(['status' => 'sent']);
        $this->app->instance(SmsService::class, $sms);

        $this->postJson('/api/login', [
            'login' => '09171234567',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('delivery_method', 'sms');

        $this->assertDatabaseHas('login_otps', ['user_id' => $user->id, 'delivery_method' => 'sms']);
    }

    public function test_login_returns_error_when_sms_service_fails(): void
    {
        $this->makeUser(['email' => null, 'phone_number' => '09171234567']);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->andThrow(new \Exception('Semaphore down'));
        $this->app->instance(SmsService::class, $sms);

        $this->postJson('/api/login', [
            'login' => '09171234567',
            'password' => 'Password123',
        ])->assertStatus(422);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeUser();

        $this->postJson('/api/login', [
            'login' => 'farmer@example.com',
            'password' => 'WrongPassword1',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_login_fails_for_unknown_account(): void
    {
        $this->postJson('/api/login', [
            'login' => 'nobody@example.com',
            'password' => 'Password123',
        ])->assertStatus(401);
    }

    public function test_login_requires_login_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login', 'password']);
    }

    public function test_user_receives_token_after_verifying_valid_otp(): void
    {
        $user = $this->makeUser();
        LoginOtp::create([
            'user_id' => $user->id,
            'otp_code' => '123456',
            'delivery_method' => 'email',
            'expires_at' => now()->addMinutes(3),
            'is_used' => false,
        ]);

        $this->postJson('/api/verify-login-otp', [
            'user_id' => $user->id,
            'otp_code' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['access_token']);

        $this->assertDatabaseHas('login_otps', ['user_id' => $user->id, 'is_used' => true]);
    }

    public function test_verify_otp_fails_with_wrong_code(): void
    {
        $user = $this->makeUser();
        LoginOtp::create([
            'user_id' => $user->id,
            'otp_code' => '123456',
            'delivery_method' => 'email',
            'expires_at' => now()->addMinutes(3),
            'is_used' => false,
        ]);

        $this->postJson('/api/verify-login-otp', [
            'user_id' => $user->id,
            'otp_code' => '999999',
        ])->assertStatus(422);
    }

    public function test_verify_otp_fails_when_otp_is_expired(): void
    {
        $user = $this->makeUser();
        LoginOtp::create([
            'user_id' => $user->id,
            'otp_code' => '123456',
            'delivery_method' => 'email',
            'expires_at' => now()->subMinute(),
            'is_used' => false,
        ]);

        $this->postJson('/api/verify-login-otp', [
            'user_id' => $user->id,
            'otp_code' => '123456',
        ])->assertStatus(422);
    }

    public function test_otp_cannot_be_used_twice(): void
    {
        $user = $this->makeUser();
        LoginOtp::create([
            'user_id' => $user->id,
            'otp_code' => '123456',
            'delivery_method' => 'email',
            'expires_at' => now()->addMinutes(3),
            'is_used' => false,
        ]);

        $payload = ['user_id' => $user->id, 'otp_code' => '123456'];

        $this->postJson('/api/verify-login-otp', $payload)->assertOk();
        $this->postJson('/api/verify-login-otp', $payload)->assertStatus(422);
    }

    public function test_resending_otp_invalidates_the_previous_one(): void
    {
        $user = $this->makeUser();
        $old = LoginOtp::create([
            'user_id' => $user->id,
            'otp_code' => '123456',
            'delivery_method' => 'email',
            'expires_at' => now()->addMinutes(3),
            'is_used' => false,
        ]);

        $this->postJson('/api/otp/resend', ['user_id' => $user->id])->assertOk();

        $this->assertTrue($old->fresh()->is_used);
        $this->assertSame(1, LoginOtp::where('user_id', $user->id)->where('is_used', false)->count());
    }

    public function test_user_can_reset_password_with_valid_otp(): void
    {
        $user = $this->makeUser();
        LoginOtp::create([
            'user_id' => $user->id,
            'otp_code' => '654321',
            'delivery_method' => 'email',
            'expires_at' => now()->addMinutes(3),
            'is_used' => false,
        ]);

        $this->postJson('/api/forgot-password/reset', [
            'user_id' => $user->id,
            'otp_code' => '654321',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_forgot_password_returns_404_for_unknown_account(): void
    {
        $this->postJson('/api/forgot-password', ['login' => 'nobody@example.com'])
            ->assertStatus(404);
    }
}

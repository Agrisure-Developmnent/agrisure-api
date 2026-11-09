<?php

namespace Tests\Feature\Auth;

use App\Models\LoginOtp;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class OtpBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function enableBypass(array $accounts, ?string $code = '111111'): void
    {
        config(['services.otp_bypass' => ['code' => $code, 'accounts' => $accounts]]);
    }

    private function login(string $login): void
    {
        $this->postJson('/api/login', ['login' => $login, 'password' => 'Password123'])->assertOk();
    }

    private function latestOtp(User $user): string
    {
        return LoginOtp::where('user_id', $user->id)->latest('id')->value('otp_code');
    }

    public function test_listed_test_account_gets_fixed_otp(): void
    {
        $user = User::factory()->mao()->create(['email' => 'jmeter@example.com']);
        $this->enableBypass(['jmeter@example.com']);

        $this->login('jmeter@example.com');

        $this->assertSame('111111', $this->latestOtp($user));
    }

    public function test_listed_account_can_log_in_with_fixed_otp(): void
    {
        $user = User::factory()->mao()->create(['email' => 'jmeter@example.com']);
        $this->enableBypass(['jmeter@example.com']);

        $this->login('jmeter@example.com');

        $this->postJson('/api/verify-login-otp', ['user_id' => $user->id, 'otp_code' => '111111'])
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    public function test_unlisted_account_never_gets_fixed_otp(): void
    {
        $user = User::factory()->create(['email' => 'farmer@example.com']);
        $this->enableBypass(['jmeter@example.com']);

        $this->login('farmer@example.com');

        $this->assertNotSame('111111', $this->latestOtp($user));

        $this->postJson('/api/verify-login-otp', ['user_id' => $user->id, 'otp_code' => '111111'])
            ->assertStatus(422);
    }

    public function test_bypass_matches_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'JMeter@Example.com']);
        $this->enableBypass(['jmeter@example.com']);

        $this->login('JMeter@Example.com');

        $this->assertSame('111111', $this->latestOtp($user));
    }

    public function test_bypass_works_for_listed_phone_number(): void
    {
        $user = User::factory()->create(['email' => null, 'phone_number' => '09171234567']);
        $this->enableBypass(['09171234567']);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendOtp')->once()->andReturn([]);
        $this->app->instance(SmsService::class, $sms);

        $this->login('09171234567');

        $this->assertSame('111111', $this->latestOtp($user));
    }

    public function test_bypass_is_off_when_code_is_empty(): void
    {
        $user = User::factory()->create(['email' => 'jmeter@example.com']);
        $this->enableBypass(['jmeter@example.com'], code: null);

        $this->login('jmeter@example.com');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->latestOtp($user));
        $this->assertNotSame('111111', $this->latestOtp($user));
    }

    public function test_bypass_ignores_invalid_code(): void
    {
        $user = User::factory()->create(['email' => 'jmeter@example.com']);
        $this->enableBypass(['jmeter@example.com'], code: 'abc');

        $this->login('jmeter@example.com');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->latestOtp($user));
    }

    public function test_status_command_reports_listed_account(): void
    {
        $user = User::factory()->mao()->create(['email' => 'jmeter@example.com']);
        $this->enableBypass(['jmeter@example.com']);

        $this->artisan('otp:bypass-status', ['login' => 'jmeter@example.com'])
            ->expectsOutputToContain('Bypass active:   YES')
            ->expectsOutputToContain("User #{$user->id} (mao): WILL get the fixed OTP")
            ->assertExitCode(0);
    }

    public function test_status_command_reports_inactive_bypass(): void
    {
        User::factory()->create(['email' => 'farmer@example.com']);
        $this->enableBypass([], code: null);

        $this->artisan('otp:bypass-status', ['login' => 'farmer@example.com'])
            ->expectsOutputToContain('Bypass active:   NO')
            ->expectsOutputToContain('will get a RANDOM OTP')
            ->assertExitCode(0);
    }

    public function test_production_no_longer_gives_everyone_fixed_otp(): void
    {
        $this->app['env'] = 'production';
        $user = User::factory()->create(['email' => 'farmer@example.com']);
        $this->enableBypass([], code: null);

        $this->login('farmer@example.com');

        $this->assertNotSame('111111', $this->latestOtp($user));
    }
}

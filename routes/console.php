<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('otp:bypass-status {login? : Email or phone to check}', function (?string $login = null) {
    $code = (string) config('services.otp_bypass.code');
    $accounts = config('services.otp_bypass.accounts', []);
    $active = preg_match('/^\d{6}$/', $code) === 1 && !empty($accounts);

    $this->line('Config cached:   ' . (app()->configurationIsCached() ? 'YES (run php artisan config:clear after editing .env)' : 'no'));
    $this->line('Bypass code set: ' . ($code === '' ? 'no' : (preg_match('/^\d{6}$/', $code) ? 'yes' : 'INVALID (must be 6 digits)')));
    $this->line('Bypass accounts: ' . (empty($accounts) ? '(none)' : implode(', ', $accounts)));
    $this->line('Bypass active:   ' . ($active ? 'YES' : 'NO'));

    if ($login !== null) {
        $user = \App\Models\User::where('email', $login)->orWhere('phone_number', $login)->first();

        if (!$user) {
            $this->error("No user found for {$login}");
            return 1;
        }

        $ids = array_filter([$user->email ? strtolower($user->email) : null, $user->phone_number]);
        $listed = (bool) array_intersect($ids, $accounts);

        $this->line("User #{$user->id} ({$user->role}): " . ($active && $listed ? 'WILL get the fixed OTP' : 'will get a RANDOM OTP'));
    }

    return 0;
})->purpose('Show whether the fixed test OTP (OTP_BYPASS_*) is active');

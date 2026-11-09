<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Models\LoginOtp;
use App\Services\SmsService;

class LoginController extends Controller
{
    public function login(Request $request, SmsService $smsService)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $loginInput = $request->login;

        $user = User::where('email', $loginInput)
            ->orWhere('phone_number', $loginInput)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        if (!$user->email && !$user->phone_number) {
            return response()->json([
                'message' => 'This account has no email or phone number for OTP.'
            ], 422);
        }

        return $this->sendOtp($user, $loginInput, $smsService);
    }

    public function resendOtp(Request $request, SmsService $smsService)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);

        $loginInput = $user->email ?? $user->phone_number;

        return $this->sendOtp($user, $loginInput, $smsService);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'otp_code' => 'required|string|size:6',
        ]);

        $otp = LoginOtp::where('user_id', $request->user_id)
            ->where('otp_code', $request->otp_code)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            return response()->json([
                'message' => 'Invalid or expired OTP.'
            ], 422);
        }

        $otp->update(['is_used' => true]);

        $user = User::findOrFail($request->user_id);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'extension_name' => $user->extension_name,
                'sex' => $user->sex,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'role' => $user->role,
                'barangay_id' => $user->barangay_id,
                'barangay' => $user->barangay ? [
                    'id' => $user->barangay->id,
                    'name' => $user->barangay->name,
                ] : null,
                'account_status' => $user->account_status,
            ],
        ]);
    }

    // ── Private helper — invalidates old OTPs and sends a new one ──
    private function sendOtp(User $user, string $loginInput, SmsService $smsService): \Illuminate\Http\JsonResponse
    {
        // Invalidate all previous unused OTPs for this user
        LoginOtp::where('user_id', $user->id)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        $otpCode = $this->bypassOtpFor($user) ?? (string) random_int(100000, 999999);

        if (filter_var($loginInput, FILTER_VALIDATE_EMAIL)) {

            Mail::send(
                'emails.login-otp',
                [
                    'name' => $user->first_name,
                    'otp' => $otpCode,
                ],
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('AgriSure Login OTP');
                }
            );

            $deliveryMethod = 'email';
            $message = 'OTP sent to your email.';

        } else {

            try {
                $smsResponse = $smsService->sendOtp(
                    $user->phone_number,
                    $otpCode
                );

                \Log::info('Semaphore response', $smsResponse);

                $deliveryMethod = 'sms';
                $message = 'OTP sent to your phone number.';

            } catch (\Exception $e) {

                \Log::error('Semaphore SMS failed', [
                    'user_id' => $user->id,
                    'phone' => $user->phone_number,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'SMS OTP is currently unavailable. Please login using your email instead.',
                ], 422);
            }
        }

        LoginOtp::create([
            'user_id' => $user->id,
            'otp_code' => $otpCode,
            'delivery_method' => $deliveryMethod,
            'expires_at' => now()->addMinutes(3),
            'is_used' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'requires_otp' => true,
            'delivery_method' => $deliveryMethod,
            'user_id' => $user->id,
            'role' => $user->role,
        ]);
    }

    /**
     * Returns the fixed test OTP when this user is one of the configured
     * test accounts (see config/services.php "otp_bypass"), otherwise null.
     */
    private function bypassOtpFor(User $user): ?string
    {
        $code = (string) config('services.otp_bypass.code');
        $accounts = config('services.otp_bypass.accounts', []);

        if (!preg_match('/^\d{6}$/', $code) || empty($accounts)) {
            return null;
        }

        $identifiers = array_filter([
            $user->email ? strtolower($user->email) : null,
            $user->phone_number,
        ]);

        return array_intersect($identifiers, $accounts) ? $code : null;
    }

    public function forgotPassword(Request $request, SmsService $smsService)
    {
        $request->validate([
            'login' => 'required|string',
        ]);

        $user = User::where('email', $request->login)
            ->orWhere('phone_number', $request->login)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Account not found.'
            ], 404);
        }

        return $this->sendOtp($user, $request->login, $smsService);
    }

    public function verifyForgotPasswordOtp(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'otp_code' => 'required|string|size:6',
        ]);

        $otp = LoginOtp::where('user_id', $request->user_id)
            ->where('otp_code', $request->otp_code)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            return response()->json([
                'message' => 'Invalid or expired OTP.'
            ], 422);
        }

        return response()->json([
            'message' => 'OTP verified successfully.',
            'user_id' => $request->user_id,
            'reset_allowed' => true,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'otp_code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $otp = LoginOtp::where('user_id', $request->user_id)
            ->where('otp_code', $request->otp_code)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            return response()->json([
                'message' => 'Invalid or expired OTP.'
            ], 422);
        }

        $user = User::findOrFail($request->user_id);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        $otp->update([
            'is_used' => true,
        ]);

        return response()->json([
            'message' => 'Password reset successfully.'
        ]);
    }
}
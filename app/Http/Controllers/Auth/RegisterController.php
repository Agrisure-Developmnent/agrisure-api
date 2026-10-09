<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\FarmerProfile;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    /**
     * Trim and convert a name value to uppercase.
     * Uses mb_strtoupper so letters like Ñ are handled correctly.
     * Returns null for empty values so nullable fields stay null.
     */
    private function formatName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_strtoupper($value, 'UTF-8');
    }

    public function register(Request $request)
    {
        $request->validate(
            [
                'last_name' => 'required|string|max:255',
                'first_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'extension_name' => 'nullable|string|max:20',
                'sex' => 'required|in:Male,Female',

                'barangay_id' => 'required|exists:barangays,id',

                'email_or_phone' => [
                    'required',
                    function ($attribute, $value, $fail) {
                        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            if (User::where('email', $value)->exists()) {
                                $fail('Email is already taken.');
                            }
                        } elseif (preg_match('/^(09|\+639)\d{9}$/', $value)) {
                            if (User::where('phone_number', $value)->exists()) {
                                $fail('Phone number is already taken.');
                            }
                        } else {
                            $fail('Must be a valid email or Philippine phone number.');
                        }
                    },
                ],

                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                    'confirmed',
                ],

                'birthdate' => 'required|date',
                'address' => 'required|string',
            ],
            [
                'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
                'barangay_id.required' => 'Please select your barangay.',
                'barangay_id.exists' => 'Selected barangay is invalid.',
            ]
        );

        $email = filter_var($request->email_or_phone, FILTER_VALIDATE_EMAIL)
            ? $request->email_or_phone
            : null;

        $phone = preg_match('/^(09|\+639)\d{9}$/', $request->email_or_phone)
            ? $request->email_or_phone
            : null;

        $user = User::create([
            'last_name' => $this->formatName($request->last_name),
            'first_name' => $this->formatName($request->first_name),
            'middle_name' => $this->formatName($request->middle_name),
            'extension_name' => $this->formatName($request->extension_name),
            'sex' => $request->sex,
            'email' => $email,
            'phone_number' => $phone,
            'password' => Hash::make($request->password),
            'role' => User::ROLE_FARMER,
            'barangay_id' => $request->barangay_id,
            'account_status' => User::STATUS_PENDING,
        ]);

        $profile = FarmerProfile::create([
            'user_id' => $user->id,
            'email_or_phone' => $request->email_or_phone,
            'birthdate' => $request->birthdate,
            'address' => $request->address,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Your account is awaiting MAO verification.',
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
                'account_status' => $user->account_status,
            ],
            'profile' => $profile,
        ], 201);
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\FarmerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use App\Models\RsbsaFarmer;
use App\Models\Barangay;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FarmerProfileController extends Controller
{
    // ==========================================
    // GET METHODS FOR TAB STATUSES
    // ==========================================

    public function pending()
    {
        return User::with(['farmerProfile', 'farmerProfile.farms'])
            ->where('role', User::ROLE_FARMER)
            ->where('account_status', User::STATUS_PENDING)
            ->get();
    }

    public function verified()
    {
        return User::with(['farmerProfile', 'farmerProfile.farms'])
            ->where('role', User::ROLE_FARMER)
            ->where('account_status', User::STATUS_VERIFIED)
            ->get();
    }

    public function inactive()
    {
        return User::with(['farmerProfile', 'farmerProfile.farms'])
            ->where('role', User::ROLE_FARMER)
            ->where('account_status', User::STATUS_INACTIVE ?? 'inactive')
            ->get();
    }

    public function rejected()
    {
        return User::with(['farmerProfile', 'farmerProfile.farms'])
            ->where('role', User::ROLE_FARMER)
            ->where('account_status', User::STATUS_REJECTED)
            ->get();
    }

    // ==========================================
    // PROFILE DETAILS & MANAGEMENT
    // ==========================================

    public function show($user_id)
    {
        $user = User::with(['farmerProfile', 'farmerProfile.farms'])
            ->where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        if (!$user->farmerProfile) {
            return response()->json([
                'message' => 'Farmer profile not found'
            ], 404);
        }

        $profile = $user->farmerProfile;

        $firstName = $profile->first_name ?? $user->first_name ?? null;
        $middleName = $profile->middle_name ?? $user->middle_name ?? null;
        $lastName = $profile->last_name ?? $user->last_name ?? null;
        $extensionName = $profile->extension_name ?? $user->extension_name ?? null;

        return response()->json([
            'id' => $profile->id,
            'user_id' => $user->id,

            'profile_photo' => $profile->profile_photo
                ? asset('storage/' . $profile->profile_photo)
                : null,

            'full_name' => trim(
                ($firstName ?? '') . ' ' .
                ($middleName ?? '') . ' ' .
                ($lastName ?? '') . ' ' .
                ($extensionName ?? '')
            ),

            'role' => 'Farmer',
            'account_status' => $user->account_status,
            'rsbsa_reference' => $profile->rsbsa_reference ?? $user->rsbsa_reference ?? null,

            'personal_information' => [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'extension_name' => $extensionName,
                'sex' => $profile->sex ?? $user->sex ?? null,
                'birthdate' => $profile->birthdate ?? $user->birthdate ?? null,
                'contact' => $profile->email_or_phone ?? $user->email ?? $user->phone ?? null,
                'address' => $profile->address ?? $user->address ?? null,
            ],

            'farmer_information' => [
                'rsbsa_reference' => $profile->rsbsa_reference ?? $user->rsbsa_reference ?? null,
                'verification_status' => $user->account_status,
                'rejection_remarks' => $profile->rejection_remarks ?? null,
                'deactivation_reason' => $profile->deactivation_reason ?? null,
            ],
            
            'farmer_profile' => $profile,
        ]);
    }

    public function update(Request $request, $user_id)
    {
        $profile = FarmerProfile::where('user_id', $user_id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'birthdate' => 'sometimes|date',
            'address' => 'sometimes|string|max:255',
            'email_or_phone' => 'sometimes|string|max:255|unique:farmer_profiles,email_or_phone,' . $profile->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $profile->update($validator->validated());

        return response()->json([
            'message' => 'Profile updated successfully',
            'profile' => $profile
        ]);
    }

    // ==========================================
    // ACCOUNT STATUS TRANSITIONS
    // ==========================================

    public function verify(Request $request, $user_id)
    {
        $user = User::where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        $profile = FarmerProfile::where('user_id', $user_id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'rsbsa_reference' => 'required|string|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $profile->update([
            'rsbsa_reference' => $request->rsbsa_reference,
        ]);

        $user->update([
            'account_status' => User::STATUS_VERIFIED,
        ]);

        NotificationService::send(
            $user->id,
            'Account Verified',
            'Your AgriSure account has been verified by MAO.'
        );

        return response()->json([
            'message' => 'Farmer verified successfully',
            'user' => $user,
            'profile' => $profile
        ]);
    }

    public function reject(Request $request, $user_id)
    {
        $user = User::where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        $profile = FarmerProfile::where('user_id', $user_id)->first();

        if ($profile && $request->has('remarks')) {
            $profile->update([
                'rejection_remarks' => $request->remarks,
            ]);
        }

        $user->update([
            'account_status' => User::STATUS_REJECTED,
        ]);

        NotificationService::send(
            $user->id,
            'Account Rejected',
            'Your AgriSure account verification was rejected. Please review and update your profile.'
        );

        return response()->json([
            'message' => 'Farmer rejected successfully',
            'user' => $user
        ]);
    }

    public function deactivate(Request $request, $user_id)
    {
        $user = User::where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        $profile = FarmerProfile::where('user_id', $user_id)->first();

        if ($profile && $request->has('deactivation_reason')) {
            $profile->update([
                'deactivation_reason' => $request->deactivation_reason,
            ]);
        }

        $user->update([
            'account_status' => User::STATUS_INACTIVE ?? 'inactive',
        ]);

        NotificationService::send(
            $user->id,
            'Account Deactivated',
            'Your AgriSure account has been deactivated by MAO.'
        );

        return response()->json([
            'message' => 'Farmer account deactivated successfully',
            'user' => $user
        ]);
    }

    public function reactivate($user_id)
    {
        $user = User::where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        $user->update([
            'account_status' => User::STATUS_VERIFIED,
        ]);

        NotificationService::send(
            $user->id,
            'Account Reactivated',
            'Your AgriSure account has been reactivated.'
        );

        return response()->json([
            'message' => 'Farmer account reactivated successfully',
            'user' => $user
        ]);
    }

    // ==========================================
    // MEDIA & SECURITY
    // ==========================================

    public function uploadProfilePhoto(Request $request, $user_id)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $profile = FarmerProfile::where('user_id', $user_id)->firstOrFail();

        if ($profile->profile_photo) {
            Storage::disk('public')->delete($profile->profile_photo);
        }

        $file = $request->file('photo');

        $filename = 'user_' . $user_id . '_' . time() . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs('profile_photos', $filename, 'public');

        $profile->update([
            'profile_photo' => $path,
        ]);

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'photo_url' => asset('storage/' . $path),
        ]);
    }

    public function updateRejectedProfile(Request $request, $user_id)
    {
        $user = User::where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        if ($user->account_status !== User::STATUS_REJECTED) {
            return response()->json([
                'message' => 'Profile can only be edited when account is rejected.'
            ], 403);
        }

        $profile = FarmerProfile::where('user_id', $user_id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:100',
            'middle_name' => 'sometimes|nullable|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'extension_name' => 'sometimes|nullable|string|max:20',
            'birthdate' => 'sometimes|date',
            'address' => 'sometimes|string|max:255',
            'email_or_phone' => 'sometimes|string|max:255|unique:farmer_profiles,email_or_phone,' . $profile->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        $user->update([
            'first_name' => $data['first_name'] ?? $user->first_name,
            'middle_name' => $data['middle_name'] ?? $user->middle_name,
            'last_name' => $data['last_name'] ?? $user->last_name,
            'extension_name' => $data['extension_name'] ?? $user->extension_name,
        ]);

        $profile->update([
            'birthdate' => $data['birthdate'] ?? $profile->birthdate,
            'address' => $data['address'] ?? $profile->address,
            'email_or_phone' => $data['email_or_phone'] ?? $profile->email_or_phone,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user,
            'profile' => $profile,
        ]);
    }

    public function resubmitVerification($user_id)
    {
        $user = User::where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        if ($user->account_status !== User::STATUS_REJECTED) {
            return response()->json([
                'message' => 'Only rejected accounts can be resubmitted.'
            ], 403);
        }

        $user->update([
            'account_status' => User::STATUS_PENDING,
        ]);

        NotificationService::send(
            $user->id,
            'Profile Resubmitted',
            'Your profile has been resubmitted for MAO verification.'
        );

        return response()->json([
            'message' => 'Profile resubmitted for verification.',
            'user' => $user,
        ]);
    }

    public function changePassword(Request $request, $user_id)
    {
        $user = User::where('role', User::ROLE_FARMER)
            ->findOrFail($user_id);

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.'
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'message' => 'Password changed successfully.'
        ]);
    }

public function createFromRsbsa(Request $request, $id)
{
    $rsbsa = RsbsaFarmer::findOrFail($id);

    $data = $request->validate([
        'email'          => 'nullable|email|unique:users,email',
        'contact_number' => 'nullable|string|max:20',
        'address'        => 'nullable|string|max:255',
        'birthdate'      => 'nullable|date',
    ]);

    // Handle column name variations (rsbsa_no vs rsbsa_number)
    $rsbsaNo = $rsbsa->rsbsa_no ?? $rsbsa->rsbsa_number ?? null;

    if ($rsbsaNo && FarmerProfile::where('rsbsa_reference', $rsbsaNo)->exists()) {
        return response()->json([
            'message' => 'This RSBSA farmer already has an AgriSure account.',
        ], 422);
    }

    // Lookup Barangay safely
    $barangay = Barangay::whereRaw('LOWER(name) = ?', [strtolower(trim($rsbsa->barangay ?? ''))])->first();

    // Contact number resolution
    $contact = !empty($data['contact_number']) ? trim($data['contact_number']) : (!empty($rsbsa->contact_number) ? trim($rsbsa->contact_number) : null);

    // Email resolution (convert empty strings to null to prevent SQL unique index errors)
    $email = !empty($data['email']) ? trim($data['email']) : (!empty($rsbsa->email) ? trim($rsbsa->email) : null);

    // Birthdate resolution (satisfies non-null database constraint if applicable)
    $birthdate = $data['birthdate'] ?? $rsbsa->birthdate ?? $rsbsa->date_of_birth ?? '1990-01-01';

    $tempPassword = Str::random(10);

    $user = DB::transaction(function () use ($rsbsa, $rsbsaNo, $email, $contact, $barangay, $birthdate, $data, $tempPassword) {
        $user = User::create([
            'first_name'     => $rsbsa->first_name,
            'middle_name'    => $rsbsa->middle_name ?? null,
            'last_name'      => $rsbsa->last_name,
            'email'          => $email,
            'phone_number'   => $contact,
            'barangay_id'    => $barangay?->id,
            'role'           => User::ROLE_FARMER ?? 'farmer',
            'account_status' => User::STATUS_VERIFIED ?? 'verified',
            'password'       => Hash::make($tempPassword),
        ]);

        $user->farmerProfile()->create([
            'email_or_phone'  => $contact ?? $email ?? ('farmer_' . $user->id . '_' . time()),
            'birthdate'       => $birthdate,
            'address'         => $data['address'] ?? $rsbsa->address ?? 'N/A',
            'rsbsa_reference' => $rsbsaNo,
        ]);

        return $user;
    });

    return response()->json([
        'message'            => 'Farmer account created.',
        'data'               => $user->load('farmerProfile.farms'),
        'temporary_password' => $tempPassword,
    ], 201);
}
public function updateDetails(Request $request, $user_id)
{
    $user = User::findOrFail($user_id);

    // Name and RSBSA number are NOT editable here: they come from the RSBSA record.
    $data = $request->validate([
        'email'          => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
        'contact_number' => 'nullable|string|max:20',
        'barangay_id'    => 'nullable|integer|exists:barangays,id',
        'address'        => 'nullable|string|max:255',
        'birthdate'      => 'nullable|date|before:today',
    ]);

    DB::transaction(function () use ($user, $data) {
        $user->update([
            'email'        => $data['email'] ?? null,
            'phone_number' => ($data['contact_number'] ?? '') !== '' ? $data['contact_number'] : null,
            'barangay_id'  => $data['barangay_id'] ?? $user->barangay_id,
        ]);

        $user->farmerProfile()->updateOrCreate(['user_id' => $user->id], [
            'address'   => $data['address'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
        ]);
    });

    return response()->json(['message' => 'Farmer updated.', 'data' => $user->fresh('farmerProfile.farms')]);
}
}
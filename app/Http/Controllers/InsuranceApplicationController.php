<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\InsuranceApplication;
use App\Models\InsuranceSeason;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

class InsuranceApplicationController extends Controller
{
    private const GEOFENCE_RADIUS_METERS = 500;

    /**
     * Send application status notification through
     * the AgriSure app / FCM push notification only.
     *
     * No SMS.
     * No Email.
     */
    private function sendApplicationNotification(
        InsuranceApplication $application,
        string $title,
        string $messageText
    ): void {
        $application->loadMissing('farm.farmerProfile.user');

        $user = $application->farm?->farmerProfile?->user;

        if (!$user) {
            Log::warning(
                "No user linked to Application #{$application->id} for notification."
            );

            return;
        }

        try {
            NotificationService::send(
                $user->id,
                $title,
                $messageText
            );

            Log::info('Application push notification sent', [
                'application_id' => $application->id,
                'user_id' => $user->id,
                'title' => $title,
            ]);

        } catch (\Throwable $e) {
            Log::error('Application push notification failed', [
                'application_id' => $application->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the current open insurance season.
     */
    private function getOrCreateCurrentSeason()
    {
        $season = InsuranceSeason::where('status', 'application_open')
            ->latest()
            ->first();

        if (!$season) {
            $season = InsuranceSeason::create([
                'season_name' => 'Default Season ' . now()->year,
                'deadline_date' => null,
                'status' => 'application_open',
                'is_default' => true,
            ]);
        }

        return $season;
    }

    /**
     * Submit insurance application.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|exists:farms,id',
            'insured_area' => 'required|numeric|min:0.01',

            'civil_status' => 'required|string|max:50',
            'beneficiary_name' => 'required|string|max:255',
            'spouse_name' => 'nullable|string|max:255',
            'parent_guardian_name' => 'nullable|string|max:255',

            'variety' => 'required|string|max:255',
            'farm_type' => 'required|in:Irrigated,Rainfed',

            'sowing_date' => 'nullable|date',
            'transplanting_date' => 'nullable|date',

            'farm_location' => 'required|string|max:255',
            'area' => 'required|numeric|min:0.01',
            'land_category' => 'nullable|string|max:255',

            'north_boundary' => 'required|string|max:255',
            'east_boundary' => 'required|string|max:255',
            'west_boundary' => 'required|string|max:255',
            'south_boundary' => 'required|string|max:255',

            'is_land_owner' => 'required|boolean',
            'tenure_status' => 'required|in:Owner Cultivator,Tenant,Leaseholder,Others',

            'signature' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',

            'payment_proof' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'gcash_reference_number' => 'nullable|string|max:100',

            'client_uuid' => 'nullable|uuid',
            'sync_source' => 'nullable|in:online,offline',
            'captured_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        /*
         * Prevent duplicate offline synchronization.
         */
        if ($request->client_uuid) {
            $existingApplication = InsuranceApplication::where(
                'client_uuid',
                $request->client_uuid
            )->first();

            if ($existingApplication) {
                return response()->json([
                    'message' => 'Insurance application already synced.',
                    'application' => $existingApplication,
                ], 200);
            }
        }

        $farm = Farm::findOrFail($request->farm_id);
        $season = $this->getOrCreateCurrentSeason();

        /*
         * Check if season is still open.
         */
        if ($season->status !== 'application_open') {
            return response()->json([
                'message' => 'This insurance season is already closed for applications.',
            ], 422);
        }

        /*
         * Check application deadline.
         */
        if (
            $season->deadline_date &&
            now()->toDateString() > $season->deadline_date->toDateString()
        ) {
            $season->update([
                'status' => 'application_closed',
            ]);

            $season = InsuranceSeason::create([
                'season_name' => 'Default Season ' . now()->year,
                'deadline_date' => null,
                'status' => 'application_open',
                'is_default' => true,
            ]);
        }

        /*
         * Prevent duplicate active applications
         * for the same farm and season.
         */
        $existingFarmApplication = InsuranceApplication::where(
            'farm_id',
            $farm->id
        )
            ->where('insurance_season_id', $season->id)
            ->whereIn('status', [
                'submitted_to_mao',
                'approved_for_pcic',
                'submitted_to_pcic',
                'insured',
            ])
            ->first();

        if ($existingFarmApplication) {
            return response()->json([
                'message' => 'This farm already has an active or pending application for this season.',
            ], 409);
        }

        $registeredFarmArea = (float) $farm->farm_area;
        $insuredArea = (float) $request->insured_area;

        if ($insuredArea > $registeredFarmArea) {
            return response()->json([
                'message' => 'Insured area cannot exceed registered farm area.',
                'registered_farm_area' => $registeredFarmArea,
                'insured_area' => $insuredArea,
            ], 422);
        }

        /*
         * Insurance computation.
         */
        $freeCoverageLimit = 3.00;
        $premiumRatePerHectare = 1000;

        $usedFreeArea = InsuranceApplication::whereHas(
            'farm',
            function ($query) use ($farm) {
                $query->where(
                    'farmer_profile_id',
                    $farm->farmer_profile_id
                );
            }
        )
            ->where('insurance_season_id', $season->id)
            ->whereIn('status', [
                'submitted_to_mao',
                'approved_for_pcic',
                'submitted_to_pcic',
                'insured',
            ])
            ->sum('covered_free_area');

        $remainingFreeArea = max(
            0,
            $freeCoverageLimit - $usedFreeArea
        );

        $coveredFreeArea = min(
            $insuredArea,
            $remainingFreeArea
        );

        $excessArea = max(
            0,
            $insuredArea - $remainingFreeArea
        );

        $premiumAmount = $excessArea * $premiumRatePerHectare;

        $requiresPayment = $excessArea > 0;

        $freeCoverageAfter = max(
            0,
            $remainingFreeArea - $coveredFreeArea
        );

        /*
         * Payment requirement.
         */
        if (
            $requiresPayment &&
            (
                !$request->hasFile('payment_proof') ||
                !$request->filled('gcash_reference_number')
            )
        ) {
            return response()->json([
                'message' => 'GCash reference number and payment proof are required.',
                'requires_payment' => true,
                'free_coverage_limit' => $freeCoverageLimit,
                'used_free_area' => (float) $usedFreeArea,
                'remaining_free_area' => $remainingFreeArea,
                'registered_farm_area' => $registeredFarmArea,
                'insured_area' => $insuredArea,
                'covered_free_area' => $coveredFreeArea,
                'excess_area' => $excessArea,
                'premium_amount' => $premiumAmount,
                'free_coverage_after' => $freeCoverageAfter,
            ], 422);
        }

        /*
         * Store signature.
         */
        $signaturePath = null;

        if ($request->hasFile('signature')) {
            $signaturePath = $request
                ->file('signature')
                ->store('signatures', 'public');
        }

        /*
         * Store payment proof.
         */
        $paymentProofPath = null;

        if ($request->hasFile('payment_proof')) {
            $paymentProofPath = $request
                ->file('payment_proof')
                ->store('payment_proofs', 'public');
        }

        /*
         * Create application.
         */
        $application = InsuranceApplication::create([
            'farm_id' => $farm->id,
            'insurance_season_id' => $season->id,

            'civil_status' => $request->civil_status,
            'beneficiary_name' => $request->beneficiary_name,
            'spouse_name' => $request->spouse_name,
            'parent_guardian_name' => $request->parent_guardian_name,

            'variety' => $request->variety,
            'farm_type' => $request->farm_type,

            'sowing_date' => $request->sowing_date,
            'transplanting_date' => $request->transplanting_date,

            'farm_location' => $request->farm_location,
            'area' => $request->area,
            'land_category' => $request->land_category,

            'north_boundary' => $request->north_boundary,
            'east_boundary' => $request->east_boundary,
            'west_boundary' => $request->west_boundary,
            'south_boundary' => $request->south_boundary,

            'is_land_owner' => $request->is_land_owner,
            'tenure_status' => $request->tenure_status,

            'application_date' => now()->toDateString(),
            'status' => 'submitted_to_mao',

            'signature_path' => $signaturePath,

            'insured_area' => $insuredArea,
            'free_coverage_before' => $remainingFreeArea,
            'covered_free_area' => $coveredFreeArea,
            'excess_area' => $excessArea,
            'free_coverage_after' => $freeCoverageAfter,
            'premium_amount' => $premiumAmount,

            'payment_status' => $requiresPayment
                ? 'pending_verification'
                : 'not_required',

            'payment_method' => $requiresPayment
                ? 'gcash'
                : null,

            'payment_proof_path' => $paymentProofPath,

            'gcash_reference_number' => $requiresPayment
                ? $request->gcash_reference_number
                : null,

            'payment_submitted_at' => $requiresPayment
                ? now()
                : null,

            'client_uuid' => $request->client_uuid,
            'sync_source' => $request->sync_source ?? 'online',
            'captured_at' => $request->captured_at,
        ]);

        /*
         * Notify farmer that the application was submitted to MAO.
         */
        $farmName = $farm->farm_name ?? 'your farm';

        $this->sendApplicationNotification(
            $application,
            'Insurance Application Submitted',
            "Your crop insurance application for {$farmName} has been submitted to the Municipal Agriculture Office for verification."
        );

        return response()->json([
            'message' => $requiresPayment
                ? 'Insurance application submitted. Payment proof is pending MAO verification.'
                : 'Insurance application submitted to MAO successfully.',

            'application' => $application,

            'payment' => [
                'requires_payment' => $requiresPayment,
                'free_coverage_limit' => $freeCoverageLimit,
                'used_free_area' => (float) $usedFreeArea,
                'remaining_free_area' => $remainingFreeArea,
                'registered_farm_area' => $registeredFarmArea,
                'insured_area' => $insuredArea,
                'covered_free_area' => $coveredFreeArea,
                'excess_area' => $excessArea,
                'free_coverage_after' => $freeCoverageAfter,
                'premium_amount' => $premiumAmount,
                'payment_status' => $application->payment_status,
                'payment_method' => $application->payment_method,
                'gcash_reference_number' => $application->gcash_reference_number,
            ],

            'season' => $season,
        ], 201);
    }

    /**
     * Submit planting photo.
     */
    public function capturePlanting(Request $request, $id)
{
    $application = InsuranceApplication::with('farm')
        ->findOrFail($id);

    $validator = Validator::make($request->all(), [
        'planting_photo' => 'required|image|mimes:png,jpg,jpeg|max:5120',
        'latitude' => 'required|numeric|between:-90,90',
        'longitude' => 'required|numeric|between:-180,180',
        'accuracy_meters' => 'nullable|numeric|min:0',
        'captured_at' => 'nullable|date',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }

    if (in_array($application->status, [
        'rejected',
        'insured',
    ])) {
        return response()->json([
            'message' =>
                'Planting photo cannot be submitted for an application in "'
                . $application->status
                . '" status.',
        ], 422);
    }

    $photoPath = $request
        ->file('planting_photo')
        ->store('planting_photos', 'public');

    $distanceMeters = null;
    $isWithinGeofence = null;
    $geotagBackfilled = false;

    $farm = $application->farm;

    if (
        $farm &&
        isset($farm->latitude) &&
        isset($farm->longitude)
    ) {
        $distanceMeters = $this->haversineDistanceMeters(
            (float) $farm->latitude,
            (float) $farm->longitude,
            (float) $request->latitude,
            (float) $request->longitude
        );

        $isWithinGeofence =
            $distanceMeters <= self::GEOFENCE_RADIUS_METERS;
    } elseif ($farm && $farm->geotag_status === 'pending') {
        /*
         * The farm has no confirmed location yet (registered by MAO
         * for a walk-in farmer, with GPS unavailable at the time).
         * The planting photo's coordinates are the farmer's own phone
         * GPS taken at the farm, so use them to backfill the farm's
         * location rather than leaving it unset indefinitely.
         */
        $farm->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'geotag_status' => 'confirmed',
        ]);

        $geotagBackfilled = true;
        // No distance to compute against — this capture *is* the farm's
        // first known location, so there's nothing to measure it against.
    }

    $application->update([
        'planting_photo_path' => $photoPath,
        'planting_photo_latitude' => $request->latitude,
        'planting_photo_longitude' => $request->longitude,
        'planting_photo_accuracy_meters' => $request->accuracy_meters,
        'planting_photo_captured_at' =>
            $request->captured_at ?? now(),
        'is_within_geofence' => $isWithinGeofence,
        'distance_from_farm_meters' => $distanceMeters,
        'capture_status' => 'pending',
        'capture_remarks' => null,
    ]);

    return response()->json([
        'message' => 'Planting photo submitted. Pending MAO verification.',
        'application' => $application,
        'geofence' => [
            'distance_from_farm_meters' => $distanceMeters,
            'is_within_geofence' => $isWithinGeofence,
            'radius_meters' => self::GEOFENCE_RADIUS_METERS,
            'geotag_backfilled' => $geotagBackfilled,
            'note' => $geotagBackfilled
                ? 'Farm had no recorded location; this photo\'s GPS was used to set it. No geofence distance to report for this first capture.'
                : ($isWithinGeofence === null
                    ? 'Farm location is not confirmed; geofence could not be checked.'
                    : null),
        ],
    ]);
}

    /**
     * Reject planting photo.
     */
    public function rejectPlantingCapture(
        Request $request,
        $id
    ) {
        $application = InsuranceApplication::findOrFail($id);

        if (!$application->planting_photo_path) {
            return response()->json([
                'message' =>
                    'No planting photo has been submitted for this application yet.',
            ], 422);
        }

        $application->update([
            'capture_status' => 'rejected',
            'capture_remarks' => $request->remarks,
        ]);

        return response()->json([
            'message' => 'Planting photo rejected.',
            'application' => $application,
        ]);
    }

    /**
     * Calculate distance between two coordinates.
     */
    private function haversineDistanceMeters(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadiusMeters = 6371000;

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a =
            sin($latDelta / 2) *
            sin($latDelta / 2) +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($lonDelta / 2) *
            sin($lonDelta / 2);

        $c = 2 * atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

        return round(
            $earthRadiusMeters * $c,
            2
        );
    }

    /**
     * Get all insurance applications.
     */
    public function index()
    {
        return InsuranceApplication::with([
            'season',
            'farm',
            'farm.farmerProfile',
            'farm.farmerProfile.user',
        ])
            ->latest()
            ->get();
    }

    /**
     * Get one insurance application.
     */
    public function show($id)
    {
        return InsuranceApplication::with([
            'season',
            'farm',
            'farm.farmerProfile',
            'farm.farmerProfile.user',
        ])->findOrFail($id);
    }

    /**
     * Final action by MAO when submitting application to PCIC.
     */
    public function submitToPcic($id)
    {
        $application = InsuranceApplication::with([
            'farm.farmerProfile.user',
        ])->findOrFail($id);

        if ($application->payment_status === 'pending_verification') {
            return response()->json([
                'message' =>
                    'Payment must be verified before submitting to PCIC.',
            ], 422);
        }

        if ($application->payment_status === 'rejected') {
            return response()->json([
                'message' =>
                    'Payment proof was rejected. Application cannot be submitted to PCIC.',
            ], 422);
        }

        /*
         * Update status.
         */
        $application->update([
            'status' => 'submitted_to_pcic',
        ]);

        /*
         * Notify farmer through app/push only.
         */
        $farmName = $application->farm->farm_name
            ?? 'your farm';

        $this->sendApplicationNotification(
            $application,
            'Application Forwarded to PCIC',
            "Your crop insurance application for {$farmName} has been forwarded to PCIC."
        );

        return response()->json([
            'message' =>
                'Application status updated to Endorsed/Submitted to PCIC.',
            'application' => $application,
        ], 200);
    }

    /**
     * Mark application as insured.
     */
    public function approve($id)
    {
        $application = InsuranceApplication::findOrFail($id);

        if ($application->payment_status === 'pending_verification') {
            return response()->json([
                'message' =>
                    'Payment must be verified before approving this application.',
            ], 422);
        }

        if ($application->payment_status === 'rejected') {
            return response()->json([
                'message' =>
                    'Payment proof was rejected. Application cannot be approved.',
            ], 422);
        }

        $application->update([
            'status' => 'insured',
        ]);

        $farmName = $application->farm->farm_name
            ?? 'your farm';

        /*
         * Notify farmer.
         */
        $this->sendApplicationNotification(
            $application,
            'Insurance Application Insured',
            "Your crop insurance application for {$farmName} has been marked as insured."
        );

        return response()->json([
            'message' => 'Insurance application marked as insured.',
            'application' => $application,
        ]);
    }

    /**
     * Reject insurance application.
     */
    public function reject(
        Request $request,
        $id
    ) {
        $application = InsuranceApplication::findOrFail($id);

        $application->update([
            'status' => 'rejected',
            'remarks' => $request->remarks,
        ]);

        $farmName = $application->farm->farm_name
            ?? 'your farm';

        $remarks = $request->remarks
            ? " Reason: {$request->remarks}"
            : '';

        /*
         * Notify farmer.
         */
        $this->sendApplicationNotification(
            $application,
            'Insurance Application Rejected',
            "Your crop insurance application for {$farmName} has been rejected.{$remarks}"
        );

        return response()->json([
            'message' => 'Insurance application rejected.',
            'application' => $application,
        ]);
    }

    /**
     * Verify payment.
     */
    public function verifyPayment($id)
    {
        $application = InsuranceApplication::findOrFail($id);

        if ($application->payment_status === 'not_required') {
            return response()->json([
                'message' =>
                    'Payment is not required for this application.',
            ], 422);
        }

        $application->update([
            'payment_status' => 'verified',
        ]);

        $farmName = $application->farm->farm_name
            ?? 'your farm';

        /*
         * Notify farmer that payment was verified.
         */
        $this->sendApplicationNotification(
            $application,
            'Payment Verified',
            "Your payment for the crop insurance application for {$farmName} has been verified. Your application may now proceed to PCIC."
        );

        return response()->json([
            'message' =>
                'Payment verified successfully. Application may now proceed to PCIC.',
            'application' => $application,
        ]);
    }

    /**
     * Reject payment proof.
     */
    public function rejectPayment(
        Request $request,
        $id
    ) {
        $application = InsuranceApplication::findOrFail($id);

        if ($application->payment_status === 'not_required') {
            return response()->json([
                'message' =>
                    'Payment is not required for this application.',
            ], 422);
        }

        $application->update([
            'payment_status' => 'rejected',
            'remarks' => $request->remarks,
        ]);

        $farmName = $application->farm->farm_name
            ?? 'your farm';

        $remarks = $request->remarks
            ? " Reason: {$request->remarks}"
            : '';

        /*
         * Notify farmer.
         */
        $this->sendApplicationNotification(
            $application,
            'Payment Proof Rejected',
            "Your payment proof for the crop insurance application for {$farmName} was rejected.{$remarks} Please submit a valid payment proof."
        );

        return response()->json([
            'message' => 'Payment proof rejected.',
            'application' => $application,
        ]);
    }

    /**
     * Get remaining free insurance coverage.
     */
    public function freeCoverage($user_id)
    {
        $freeCoverageLimit = 3.00;

        $season = $this->getOrCreateCurrentSeason();

        $usedFreeArea = InsuranceApplication::whereHas(
            'farm.farmerProfile',
            function ($query) use ($user_id) {
                $query->where('user_id', $user_id);
            }
        )
            ->where('insurance_season_id', $season->id)
            ->whereIn('status', [
                'submitted_to_mao',
                'approved_for_pcic',
                'submitted_to_pcic',
                'insured',
            ])
            ->sum('covered_free_area');

        $remainingFreeArea = max(
            0,
            $freeCoverageLimit - $usedFreeArea
        );

        return response()->json([
            'season' => $season,
            'free_coverage_limit' => $freeCoverageLimit,
            'used_free_area' => (float) $usedFreeArea,
            'remaining_free_area' => $remainingFreeArea,
        ]);
    }

    /**
     * Get application history for a farm.
     */
    public function farmHistory($farm_id)
    {
        return InsuranceApplication::with([
            'season',
            'farm',
            'farm.farmerProfile',
            'farm.farmerProfile.user',
        ])
            ->where('farm_id', $farm_id)
            ->latest()
            ->get();
    }

    /**
     * Get application history.
     */
    public function history()
    {
        $currentSeason = InsuranceSeason::where(
            'status',
            'application_open'
        )
            ->where('is_default', false)
            ->latest()
            ->first();

        return InsuranceApplication::with([
            'farm',
            'farm.farmerProfile',
            'farm.farmerProfile.user',
            'season',
        ])
            ->when(
                $currentSeason,
                function ($query) use ($currentSeason) {
                    $query->where(
                        'insurance_season_id',
                        '!=',
                        $currentSeason->id
                    );
                }
            )
            ->latest()
            ->get();
    }

    /**
     * Approve application for PCIC submission.
     */
    public function approveForPcic($id)
    {
        $application = InsuranceApplication::findOrFail($id);

        if ($application->payment_status === 'pending_verification') {
            return response()->json([
                'message' =>
                    'Payment must be verified before this application can proceed to PCIC.',
            ], 422);
        }

        if ($application->payment_status === 'rejected') {
            return response()->json([
                'message' =>
                    'Payment proof was rejected. Application cannot proceed to PCIC.',
            ], 422);
        }

        $application->update([
            'status' => 'approved_for_pcic',
        ]);

        $farmName = $application->farm->farm_name
            ?? 'your farm';

        /*
         * Notify farmer.
         */
        $this->sendApplicationNotification(
            $application,
            'Application Approved for PCIC',
            "Your insurance application for {$farmName} has been approved for PCIC submission."
        );

        return response()->json([
            'message' =>
                'Application approved for PCIC submission.',
            'application' => $application,
        ]);
    }

    /**
     * Mark application as needing revision.
     */
    public function needsRevision($id)
    {
        $application = InsuranceApplication::findOrFail($id);

        $application->update([
            'status' => 'needs_revision',
        ]);

        $farmName = $application->farm->farm_name
            ?? 'your farm';

        /*
         * Notify farmer.
         */
        $this->sendApplicationNotification(
            $application,
            'Application Requires Revision',
            "Your insurance application for {$farmName} needs revision. Please check your application for the required corrections."
        );

        return response()->json([
            'message' =>
                'Application flagged for document revision.',
            'application' => $application,
        ]);
    }
}


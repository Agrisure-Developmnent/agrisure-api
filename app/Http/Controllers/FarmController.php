<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\FarmerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FarmController extends Controller
{
   
    public function all()
    {
        $farms = Farm::with('farmerProfile.user')->get();
        return response()->json($farms);
    }
    public function index($user_id)
    {
        $profile = FarmerProfile::where('user_id', $user_id)->first();

        if (!$profile) {
            return response()->json([
                'message' => 'Farmer profile not found'
            ], 404);
        }

        $farms = Farm::where(
            'farmer_profile_id',
            $profile->id
        )->latest()->get();

        return response()->json($farms);
    }

    /**
     * Register new farm
     */
    public function store(Request $request)
    {
        $request->validate([
            'farmer_profile_id' => 'required|exists:farmer_profiles,id',
            'farm_name' => 'required|string|max:255',
            'crop_type' => 'required|in:Rice,Corn',
            'farm_area' => 'required|numeric|min:0.01',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'farm_image' => 'required|image|mimes:jpg,jpeg,png|max:5120',

            // Offline sync fields
            'client_uuid' => 'nullable|uuid',
            'sync_source' => 'nullable|in:online,offline',
            'captured_at' => 'nullable|date',
        ]);

        if ($request->client_uuid) {
            $existingFarm = Farm::where('client_uuid', $request->client_uuid)->first();

            if ($existingFarm) {
                return response()->json([
                    'message' => 'Farm already synced.',
                    'farm' => $existingFarm,
                ], 200);
            }
        }

        $imagePath = $request->file('farm_image')
            ->store('farms', 'public');

        $farm = Farm::create([
            'farmer_profile_id' => $request->farmer_profile_id,
            'farm_name' => $request->farm_name,
            'crop_type' => $request->crop_type,
            'farm_area' => $request->farm_area,
            'farm_image_path' => $imagePath,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'insurance_status' => 'not_insured',

            'client_uuid' => $request->client_uuid,
            'sync_source' => $request->sync_source ?? 'online',
            'captured_at' => $request->captured_at,
        ]);

        return response()->json([
            'message' => 'Farm registered successfully.',
            'farm' => $farm,
        ], 201);
    }
    /**
     * View farm details
     */
    public function show($id)
    {
        $farm = Farm::find($id);

        if (!$farm) {
            return response()->json([
                'message' => 'Farm not found'
            ], 404);
        }

        return response()->json($farm);
    }

    /**
     * Update farm
     */
    public function update(Request $request, $id)
    {
        $farm = Farm::find($id);

        if (!$farm) {
            return response()->json([
                'message' => 'Farm not found'
            ], 404);
        }

        $request->validate([
            'farm_name' => 'sometimes|string|max:255',
            'crop_type' => 'sometimes|in:Rice,Corn',
            'farm_area' => 'sometimes|numeric|min:0.01',
        ]);

        $farm->update($request->only([
            'farm_name',
            'crop_type',
            'farm_area',
        ]));

        return response()->json([
            'message' => 'Farm updated successfully.',
            'farm' => $farm,
        ]);
    }

    /**
     * Delete farm
     */
    public function destroy($id)
    {
        $farm = Farm::find($id);

        if (!$farm) {
            return response()->json([
                'message' => 'Farm not found'
            ], 404);
        }

        if ($farm->farm_image_path) {
            Storage::disk('public')
                ->delete($farm->farm_image_path);
        }

        $farm->delete();

        return response()->json([
            'message' => 'Farm deleted successfully.'
        ]);
    }

    /**
 * Register a farm for a walk-in farmer, created by MAO at the office.
 * GPS coordinates and a farm photo are not available on-site, so both
 * are optional here — unlike store(), which is used by farmers
 * self-registering via the app with live GPS capture.
 */
public function storeByMao(Request $request)
{
    $request->validate([
        'farmer_profile_id' => 'required|exists:farmer_profiles,id',
        'farm_name'         => 'required|string|max:255',
        'crop_type'         => 'required|in:Rice,Corn',
        'farm_area'         => 'required|numeric|min:0.01',

        'latitude'          => 'nullable|numeric|between:-90,90',
        'longitude'         => 'nullable|numeric|between:-180,180',
        'farm_image'        => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
    ]);

    // If MAO provides one coordinate, require the other — a half pin is worse than none.
    if ($request->filled('latitude') xor $request->filled('longitude')) {
        return response()->json([
            'message' => 'Both latitude and longitude are required together, or leave both blank.',
        ], 422);
    }

    $hasCoordinates = $request->filled('latitude') && $request->filled('longitude');

    $imagePath = $request->hasFile('farm_image')
        ? $request->file('farm_image')->store('farms', 'public')
        : null;

    $farm = Farm::create([
        'farmer_profile_id' => $request->farmer_profile_id,
        'farm_name'         => $request->farm_name,
        'crop_type'         => $request->crop_type,
        'farm_area'         => $request->farm_area,
        'farm_image_path'   => $imagePath,
        'latitude'          => $request->latitude,
        'longitude'         => $request->longitude,
        'geotag_status'     => $hasCoordinates ? 'confirmed' : 'pending',
        'insurance_status'  => 'not_insured',
        'sync_source'       => 'online',
    ]);

    return response()->json([
        'message' => $hasCoordinates
            ? 'Farm registered successfully.'
            : 'Farm registered without GPS location. It will need to be geotagged before final PCIC submission.',
        'farm' => $farm,
    ], 201);
}

/**
 * Update a farm's GPS location after the fact — e.g. MAO drops a pin
 * on a follow-up visit, or the farmer confirms it later via the app.
 */
public function updateLocation(Request $request, $id)
{
    $farm = Farm::find($id);

    if (!$farm) {
        return response()->json(['message' => 'Farm not found'], 404);
    }

    $request->validate([
        'latitude'  => 'required|numeric|between:-90,90',
        'longitude' => 'required|numeric|between:-180,180',
    ]);

    $farm->update([
        'latitude'      => $request->latitude,
        'longitude'     => $request->longitude,
        'geotag_status' => 'confirmed',
    ]);

    return response()->json([
        'message' => 'Farm location updated.',
        'farm'    => $farm,
    ]);
}

/**
 * List farms still awaiting GPS geotagging — MAO's follow-up work queue.
 */
public function pendingGeotag()
{
    $farms = Farm::with('farmerProfile.user')
        ->where('geotag_status', 'pending')
        ->latest()
        ->get();

    return response()->json($farms);
}

public function search(Request $request)
    {
        $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim($request->input('query'));

        $farmers = FarmerProfile::with('user')
            // Restrict to verified farmers eligible for insurance
            ->where('status', 'verified')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('rsbsa_number', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('first_name', 'LIKE', "%{$search}%")
                                      ->orWhere('last_name', 'LIKE', "%{$search}%")
                                      ->orWhere('middle_name', 'LIKE', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $farmers,
        ]);
    }

}
<?php

namespace App\Http\Controllers;

use App\Models\RsbsaFarmer;
use Illuminate\Http\Request;

class RsbsaFarmerController extends Controller
{
    /**
     * Search RSBSA farmers.
     */
    public function search(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'barangay' => ['nullable', 'string', 'max:150'],
        ]);

        $query = RsbsaFarmer::query();

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('middle_name', 'LIKE', "%{$search}%")
                    ->orWhere('rsbsa_no', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('barangay')) {
            $query->where('barangay', $request->barangay);
        }

        $farmers = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $farmers,
        ]);
    }

    /**
     * Display a specific RSBSA farmer.
     */
    public function show($id)
    {
        $farmer = RsbsaFarmer::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $farmer,
        ]);
    }
}
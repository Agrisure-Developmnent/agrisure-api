<?php

namespace App\Http\Controllers;

use App\Models\FarmerProfile;
use App\Models\Farm;
use App\Models\InsuranceApplication;
use App\Models\InsuranceSeason;
use App\Models\DamageReport;
use App\Models\Claim;
use App\Models\DistributionList;
use App\Models\InventorySupply;
use App\Models\Barangay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{

    /**
     * Resolves which season a report should use.
     *
     * - If the request explicitly passes season_id, honor it (including
     *   letting the frontend send season_id=all to explicitly mean
     *   "every season combined" — that resolves to null on purpose).
     * - Otherwise, default to whichever season is currently marked
     *   active, so reports default to "current season" instead of
     *   silently aggregating every season ever recorded.
     *
     * ASSUMPTION: InsuranceSeason has an `is_active` boolean column.
     * If your schema marks "current" differently (a date range, a
     * status enum, etc.), swap the query below accordingly — nothing
     * else in this controller needs to change.
     */
    private function resolveSeasonId(Request $request)
    {
        if ($request->filled('season_id')) {
            if ($request->season_id === 'all') {
                return null;
            }

            return (int) $request->season_id;
        }

        return InsuranceSeason::where('is_active', true)->value('id');
    }

    /**
     * Normalizes every incoming filter param.
     *
     * FIX (prior): previously this passed $request->season_id /
     * barangay_id / year straight through, whatever type they
     * arrived as. The frontend's year input was sending a STRING
     * ("2026") instead of a number, and an empty-string filter value
     * ("") is truthy enough to slip past some !empty() checks
     * depending on context. Casting explicitly to int (or null when
     * not provided) here means every report method gets a
     * consistent, predictable filter shape regardless of what the
     * client sends.
     *
     * FIX (new): season_id now goes through resolveSeasonId() so
     * every season-aware report (overview, insurance, damageReports,
     * claims, executive) defaults to the CURRENT season instead of
     * silently combining every season when nothing is selected.
     *
     * FIX (new): barangay role-scoping is now centralized here
     * instead of being duplicated (and previously only present) in
     * farmers()/farms(). Before this change, a barangay-role account
     * hitting overview/insurance/damageReports/claims/distribution/
     * executive/inventory got municipality-wide data unless the
     * frontend happened to also pass barangay_id manually — that's
     * a real data-scoping gap, not just a cosmetic bug.
     */
    private function reportFilters(Request $request)
    {
        $barangayId = $request->filled('barangay_id') ? (int) $request->barangay_id : null;

        if ($request->user() && $request->user()->role === 'barangay') {
            $barangayId = $request->user()->barangay_id;
        }

        return [
            'season_id'    => $this->resolveSeasonId($request),
            'barangay_id'  => $barangayId,
            'crop_type'    => $request->filled('crop_type') ? $request->crop_type : null,
            'year'         => $request->filled('year') ? (int) $request->year : null,
            'date_from'    => $request->filled('date_from') ? $request->date_from : null,
            'date_to'      => $request->filled('date_to') ? $request->date_to : null,
        ];
    }

    private function applyDateFilters($query, array $filters, $column = 'created_at')
    {
        if (!empty($filters['year'])) {
            $query->whereYear($column, $filters['year']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate($column, '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate($column, '<=', $filters['date_to']);
        }
        return $query;
    }

    public function overview(Request $request)
    {
        $filters = $this->reportFilters($request);

        $farmers = FarmerProfile::query();
        $farms = Farm::query();
        $applications = InsuranceApplication::query();
        $damageReports = DamageReport::query();
        $claims = Claim::query();
        $distribution = DistributionList::query();

        if (!empty($filters['barangay_id'])) {
            $farmers->whereHas('user', function ($q) use ($filters) {
                $q->where('barangay_id', $filters['barangay_id']);
            });
        }

        if (!empty($filters['crop_type'])) {
            $farms->where('crop_type', $filters['crop_type']);
        }

        if (!empty($filters['barangay_id'])) {
            $farms->whereHas('farmerProfile.user', function ($q) use ($filters) {
                $q->where('barangay_id', $filters['barangay_id']);
            });
        }

        if (!empty($filters['season_id'])) {
            $applications->where(
                'insurance_season_id',
                $filters['season_id']
            );
        }

        if (!empty($filters['crop_type'])) {
            $applications->whereHas('farm', function ($q) use ($filters) {
                $q->where('crop_type', $filters['crop_type']);
            });
        }

        if (!empty($filters['barangay_id'])) {
            $applications->whereHas(
                'farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        $this->applyDateFilters(
            $applications,
            $filters
        );

        if (!empty($filters['season_id'])) {
            $damageReports->whereHas(
                'insuranceApplication',
                function ($q) use ($filters) {
                    $q->where(
                        'insurance_season_id',
                        $filters['season_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $damageReports->whereHas('farm', function ($q) use ($filters) {
                $q->where('crop_type', $filters['crop_type']);
            });
        }

        if (!empty($filters['barangay_id'])) {
            $damageReports->whereHas(
                'farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        // FIX: damage reports use `damage_date` as their real-world event
        // date, not `created_at` (when the record was entered into the
        // system). Using created_at here meant the Overview "damage
        // reports" count could disagree with the Damage Reports tab
        // itself for the same year filter.
        $this->applyDateFilters(
            $damageReports,
            $filters,
            'damage_reports.damage_date'
        );

        if (!empty($filters['season_id'])) {
            $claims->whereHas(
                'damageReport.insuranceApplication',
                function ($q) use ($filters) {
                    $q->where(
                        'insurance_season_id',
                        $filters['season_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $claims->whereHas(
                'damageReport.farm',
                function ($q) use ($filters) {
                    $q->where(
                        'crop_type',
                        $filters['crop_type']
                    );
                }
            );
        }

        if (!empty($filters['barangay_id'])) {
            $claims->whereHas(
                'damageReport.farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        $this->applyDateFilters(
            $claims,
            $filters,
            'claims.created_at'
        );

        if (!empty($filters['barangay_id'])) {
            $distribution->where(
                'barangay_id',
                $filters['barangay_id']
            );
        }

        $distribution->whereHas('event', function ($q) use ($filters) {
            if (!empty($filters['year'])) {
                $q->whereYear(
                    'distribution_date',
                    $filters['year']
                );
            }

            if (!empty($filters['date_from'])) {
                $q->whereDate(
                    'distribution_date',
                    '>=',
                    $filters['date_from']
                );
            }

            if (!empty($filters['date_to'])) {
                $q->whereDate(
                    'distribution_date',
                    '<=',
                    $filters['date_to']
                );
            }
        });

        return response()->json([
            'summary' => [
                'total_farmers' => $farmers->count(),

                'total_farms' => $farms->count(),

                'rice_farms' => (clone $farms)
                    ->where('crop_type', 'Rice')
                    ->count(),

                'corn_farms' => (clone $farms)
                    ->where('crop_type', 'Corn')
                    ->count(),

                'insurance_applications' => $applications->count(),

                'damage_reports' => $damageReports->count(),

                'claims' => $claims->count(),

                'distribution_events' => $distribution->count(),

                'inventory_supplies' => InventorySupply::count(),
            ],
        ]);
    }


    public function farmers(Request $request)
    {
        $filters = $this->reportFilters($request);

        $farmers = FarmerProfile::query();

        if ($filters['barangay_id']) {
            $farmers->whereHas('user', function ($q) use ($filters) {
                $q->where('barangay_id', $filters['barangay_id']);
            });
        }

        $totalFarmers = (clone $farmers)->count();

        $riceFarmers = (clone $farmers)
            ->whereHas('farms', function ($q) {
                $q->where('crop_type', 'Rice');
            })
            ->count();

        $cornFarmers = (clone $farmers)
            ->whereHas('farms', function ($q) {
                $q->where('crop_type', 'Corn');
            })
            ->count();

        $farmerIds = (clone $farmers)->pluck('id');

        $averageFarmSize = Farm::whereIn('farmer_profile_id', $farmerIds)
            ->avg('farm_area');

        $farmersPerBarangay = Barangay::select(
                'barangays.id',
                'barangays.name',
                DB::raw('COUNT(DISTINCT farmer_profiles.id) as total')
            )
            ->leftJoin(
                'users',
                'barangays.id',
                '=',
                'users.barangay_id'
            )
            ->leftJoin(
                'farmer_profiles',
                'users.id',
                '=',
                'farmer_profiles.user_id'
            )
            ->when($filters['barangay_id'], function ($query) use ($filters) {
                $query->where('barangays.id', $filters['barangay_id']);
            })
            ->groupBy(
                'barangays.id',
                'barangays.name'
            )
            ->orderByDesc('total')
            ->get();

        $topBarangays = Barangay::select(
                'barangays.id',
                'barangays.name',
                DB::raw('COUNT(DISTINCT farmer_profiles.id) as total')
            )
            ->leftJoin(
                'users',
                'barangays.id',
                '=',
                'users.barangay_id'
            )
            ->leftJoin(
                'farmer_profiles',
                'users.id',
                '=',
                'farmer_profiles.user_id'
            )
            ->when($filters['barangay_id'], function ($query) use ($filters) {
                $query->where('barangays.id', $filters['barangay_id']);
            })
            ->groupBy(
                'barangays.id',
                'barangays.name'
            )
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $sexDistribution = FarmerProfile::query()
            ->select(
                'users.sex',
                DB::raw('COUNT(farmer_profiles.id) as total')
            )
            ->join(
                'users',
                'farmer_profiles.user_id',
                '=',
                'users.id'
            )
            ->when($filters['barangay_id'], function ($query) use ($filters) {
                $query->where(
                    'users.barangay_id',
                    $filters['barangay_id']
                );
            })
            ->groupBy('users.sex')
            ->get();

        $civilStatusDistribution = FarmerProfile::query()
            ->select(
                'users.civil_status',
                DB::raw('COUNT(farmer_profiles.id) as total')
            )
            ->join(
                'users',
                'farmer_profiles.user_id',
                '=',
                'users.id'
            )
            ->when($filters['barangay_id'], function ($query) use ($filters) {
                $query->where(
                    'users.barangay_id',
                    $filters['barangay_id']
                );
            })
            ->groupBy('users.civil_status')
            ->get();

        $ageGroups = [
            '18-30' => (clone $farmers)
                ->whereRaw(
                    'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 18 AND 30'
                )
                ->count(),

            '31-45' => (clone $farmers)
                ->whereRaw(
                    'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 31 AND 45'
                )
                ->count(),

            '46-60' => (clone $farmers)
                ->whereRaw(
                    'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 46 AND 60'
                )
                ->count(),

            '61+' => (clone $farmers)
                ->whereRaw(
                    'TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) >= 61'
                )
                ->count(),
        ];

        return response()->json([
            'summary' => [
                'total_farmers'     => $totalFarmers,
                'rice_farmers'      => $riceFarmers,
                'corn_farmers'      => $cornFarmers,
                'average_farm_size' => round((float) $averageFarmSize, 2),
            ],

            'farmers_per_barangay' => $farmersPerBarangay,

            'top_barangays' => $topBarangays,

            'sex_distribution' => $sexDistribution,

            'civil_status_distribution' => $civilStatusDistribution,

            'age_groups' => $ageGroups,
        ]);
    }

    public function farms(Request $request)
    {
        $filters = $this->reportFilters($request);

        $farms = Farm::query();

        if ($filters['crop_type']) {
            $farms->where(
                'crop_type',
                $filters['crop_type']
            );
        }

        if ($filters['barangay_id']) {
            $farms->whereHas(
                'farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        $totalFarms = (clone $farms)->count();

        $riceFarms = (clone $farms)
            ->where('crop_type', 'Rice')
            ->count();

        $cornFarms = (clone $farms)
            ->where('crop_type', 'Corn')
            ->count();

        $totalRiceArea = (clone $farms)
            ->where('crop_type', 'Rice')
            ->sum('farm_area');

        $totalCornArea = (clone $farms)
            ->where('crop_type', 'Corn')
            ->sum('farm_area');

        $averageFarmArea = (clone $farms)
            ->avg('farm_area');

        $cropDistribution = (clone $farms)
            ->select(
                'crop_type',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('crop_type')
            ->orderByDesc('total')
            ->get();

        $cropAreaDistribution = (clone $farms)
            ->select(
                'crop_type',
                DB::raw('SUM(farm_area) as total_area')
            )
            ->groupBy('crop_type')
            ->orderByDesc('total_area')
            ->get();

        $farmsPerBarangay = Barangay::select(
                'barangays.id',
                'barangays.name',
                DB::raw('COUNT(farms.id) as total_farms'),
                DB::raw('COALESCE(SUM(farms.farm_area), 0) as total_area')
            )
            ->leftJoin(
                'users',
                'barangays.id',
                '=',
                'users.barangay_id'
            )
            ->leftJoin(
                'farmer_profiles',
                'users.id',
                '=',
                'farmer_profiles.user_id'
            )
            ->leftJoin(
                'farms',
                'farmer_profiles.id',
                '=',
                'farms.farmer_profile_id'
            )
            ->when($filters['barangay_id'], function ($query) use ($filters) {
                $query->where(
                    'barangays.id',
                    $filters['barangay_id']
                );
            })
            ->when($filters['crop_type'], function ($query) use ($filters) {
                $query->where(
                    'farms.crop_type',
                    $filters['crop_type']
                );
            })
            ->groupBy(
                'barangays.id',
                'barangays.name'
            )
            ->orderByDesc('total_farms')
            ->get();

        $largestAgriculturalBarangays = Barangay::select(
                'barangays.id',
                'barangays.name',
                DB::raw('COALESCE(SUM(farms.farm_area), 0) as total_area'),
                DB::raw('COUNT(farms.id) as total_farms')
            )
            ->leftJoin(
                'users',
                'barangays.id',
                '=',
                'users.barangay_id'
            )
            ->leftJoin(
                'farmer_profiles',
                'users.id',
                '=',
                'farmer_profiles.user_id'
            )
            ->leftJoin(
                'farms',
                'farmer_profiles.id',
                '=',
                'farms.farmer_profile_id'
            )
            ->when($filters['barangay_id'], function ($query) use ($filters) {
                $query->where(
                    'barangays.id',
                    $filters['barangay_id']
                );
            })
            ->when($filters['crop_type'], function ($query) use ($filters) {
                $query->where(
                    'farms.crop_type',
                    $filters['crop_type']
                );
            })
            ->groupBy(
                'barangays.id',
                'barangays.name'
            )
            ->orderByDesc('total_area')
            ->limit(10)
            ->get();

        return response()->json([
            'summary' => [
                'total_farms'       => $totalFarms,
                'rice_farms'        => $riceFarms,
                'corn_farms'        => $cornFarms,
                'total_rice_area'   => round((float) $totalRiceArea, 2),
                'total_corn_area'   => round((float) $totalCornArea, 2),
                'average_farm_area' => round((float) $averageFarmArea, 2),
            ],

            'crop_distribution' => $cropDistribution,

            'crop_area_distribution' => $cropAreaDistribution,

            'farms_per_barangay' => $farmsPerBarangay,

            'largest_agricultural_barangays' => $largestAgriculturalBarangays,
        ]);
    }

    public function insurance(Request $request)
    {
        $filters = $this->reportFilters($request);

        $applications = InsuranceApplication::query();

        if (!empty($filters['season_id'])) {
            $applications->where(
                'insurance_season_id',
                $filters['season_id']
            );
        }

        if (!empty($filters['crop_type'])) {
            $applications->whereHas(
                'farm',
                function ($q) use ($filters) {
                    $q->where(
                        'crop_type',
                        $filters['crop_type']
                    );
                }
            );
        }

        if (!empty($filters['barangay_id'])) {
            $applications->whereHas(
                'farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        if ($request->filled('status')) {
            $applications->where(
                'status',
                $request->status
            );
        }

        $this->applyDateFilters(
            $applications,
            $filters
        );

        return response()->json([
            'summary' => [
                'total_applications' => (clone $applications)->count(),

                'submitted_to_mao' => (clone $applications)
                    ->where('status', 'submitted_to_mao')
                    ->count(),

                'to_be_submitted_to_pcic' => (clone $applications)
                    ->where('status', 'approved_for_pcic')
                    ->count(),

                'submitted_to_pcic' => (clone $applications)
                    ->where('status', 'submitted_to_pcic')
                    ->count(),

                'insured' => (clone $applications)
                    ->where('status', 'insured')
                    ->count(),

                'rejected' => (clone $applications)
                    ->where('status', 'rejected')
                    ->count(),
            ],

            'status_distribution' => (clone $applications)
                ->select(
                    'status',
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('status')
                ->get(),

            'crop_distribution' => Farm::select(
                    'farms.crop_type',
                    DB::raw(
                        'COUNT(insurance_applications.id) as total'
                    )
                )
                ->join(
                    'insurance_applications',
                    'farms.id',
                    '=',
                    'insurance_applications.farm_id'
                )
                ->when(
                    $filters['season_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'insurance_applications.insurance_season_id',
                            $filters['season_id']
                        );
                    }
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->whereHas(
                            'farmerProfile.user',
                            function ($userQuery) use ($filters) {
                                $userQuery->where(
                                    'barangay_id',
                                    $filters['barangay_id']
                                );
                            }
                        );
                    }
                )
                ->groupBy('farms.crop_type')
                ->get(),

            'monthly_applications' => (clone $applications)
                ->selectRaw(
                    'MONTH(created_at) as month, COUNT(*) as total'
                )
                ->groupByRaw('MONTH(created_at)')
                ->orderByRaw('MONTH(created_at)')
                ->get(),

            'applications_per_barangay' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(insurance_applications.id) as total'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'insurance_applications',
                    'farms.id',
                    '=',
                    'insurance_applications.farm_id'
                )
                ->when(
                    $filters['season_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'insurance_applications.insurance_season_id',
                            $filters['season_id']
                        );
                    }
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'insurance_applications.created_at',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total')
                ->get(),

            'top_barangays' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(insurance_applications.id) as total'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'insurance_applications',
                    'farms.id',
                    '=',
                    'insurance_applications.farm_id'
                )
                ->when(
                    $filters['season_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'insurance_applications.insurance_season_id',
                            $filters['season_id']
                        );
                    }
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'insurance_applications.created_at',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total')
                ->limit(10)
                ->get(),
        ]);
    }

    public function damageReports(Request $request)
    {
        $filters = $this->reportFilters($request);

        $reports = DamageReport::query();

        if (!empty($filters['season_id'])) {
            $reports->whereHas(
                'insuranceApplication',
                function ($q) use ($filters) {
                    $q->where(
                        'insurance_season_id',
                        $filters['season_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $reports->whereHas(
                'farm',
                function ($q) use ($filters) {
                    $q->where(
                        'crop_type',
                        $filters['crop_type']
                    );
                }
            );
        }

        if (!empty($filters['barangay_id'])) {
            $reports->whereHas(
                'farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        if ($request->filled('damage_cause')) {
            $reports->where(
                'damage_cause',
                $request->damage_cause
            );
        }

        if ($request->filled('status')) {
            $reports->where(
                'status',
                $request->status
            );
        }

        $this->applyDateFilters($reports, $filters, 'damage_reports.damage_date');

        return response()->json([
            'summary' => [
                'total_damage_reports' => (clone $reports)->count(),

                'validated_by_mao' => (clone $reports)
                    ->where('status', 'validated_by_mao')
                    ->count(),

                'submitted_to_mao' => (clone $reports)
                    ->where('status', 'submitted_to_mao')
                    ->count(),
            ],

            'damage_causes' => (clone $reports)
                ->select(
                    'damage_cause',
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('damage_cause')
                ->orderByDesc('total')
                ->get(),

            'monthly_damage' => (clone $reports)
                ->selectRaw(
                    'MONTH(damage_reports.damage_date) as month, COUNT(*) as total'
                )
                ->groupByRaw('MONTH(damage_reports.damage_date)')
                ->orderByRaw('MONTH(damage_reports.damage_date)')
                ->get(),

            'crop_damage' => (clone $reports)
                ->join(
                    'farms',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->select(
                    'farms.crop_type',
                    DB::raw(
                        'COUNT(damage_reports.id) as total'
                    )
                )
                ->groupBy('farms.crop_type')
                ->get(),

            'barangays' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(damage_reports.id) as total_reports'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'damage_reports',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'damage_reports.damage_date',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total_reports')
                ->get(),

            'top_barangays' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(damage_reports.id) as total_reports'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'damage_reports',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'damage_reports.damage_date',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total_reports')
                ->limit(10)
                ->get(),
        ]);
    }

    public function claims(Request $request)
    {
        $filters = $this->reportFilters($request);

        $claims = Claim::query();

        if (!empty($filters['season_id'])) {
            $claims->whereHas(
                'damageReport.insuranceApplication',
                function ($q) use ($filters) {
                    $q->where(
                        'insurance_season_id',
                        $filters['season_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $claims->whereHas(
                'damageReport.farm',
                function ($q) use ($filters) {
                    $q->where(
                        'crop_type',
                        $filters['crop_type']
                    );
                }
            );
        }

        if (!empty($filters['barangay_id'])) {
            $claims->whereHas(
                'damageReport.farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        if ($request->filled('status')) {
            $claims->where(
                'status',
                $request->status
            );
        }

        $this->applyDateFilters(
            $claims,
            $filters,
            'claims.created_at'
        );

        return response()->json([
            'summary' => [
                'total_claims' => (clone $claims)->count(),

                'submitted_to_pcic' => (clone $claims)
                    ->where('status', 'submitted_to_pcic')
                    ->count(),

                'ready_for_claiming' => (clone $claims)
                    ->where('status', 'ready_for_claiming')
                    ->count(),

                'claimed' => (clone $claims)
                    ->where('status', 'claimed')
                    ->count(),

                'rejected' => (clone $claims)
                    ->where('status', 'rejected')
                    ->count(),
            ],

            'status_distribution' => (clone $claims)
                ->select(
                    'status',
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('status')
                ->get(),

            'monthly_claims' => (clone $claims)
                ->selectRaw(
                    'MONTH(created_at) as month,
                    COUNT(*) as total'
                )
                ->groupByRaw('MONTH(created_at)')
                ->orderByRaw('MONTH(created_at)')
                ->get(),

            'crop_claims' => (clone $claims)
                ->join(
                    'damage_reports',
                    'damage_reports.id',
                    '=',
                    'claims.damage_report_id'
                )
                ->join(
                    'farms',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->select(
                    'farms.crop_type',
                    DB::raw(
                        'COUNT(claims.id) as total'
                    )
                )
                ->groupBy('farms.crop_type')
                ->get(),

            'barangays' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(claims.id) as total_claims'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'damage_reports',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->leftJoin(
                    'claims',
                    'damage_reports.id',
                    '=',
                    'claims.damage_report_id'
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'claims.created_at',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total_claims')
                ->get(),

            'top_barangays' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(claims.id) as total_claims'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'damage_reports',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->leftJoin(
                    'claims',
                    'damage_reports.id',
                    '=',
                    'claims.damage_report_id'
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'claims.created_at',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total_claims')
                ->limit(10)
                ->get(),
        ]);
    }

    public function distribution(Request $request)
    {
        $filters = $this->reportFilters($request);

        $distribution = DistributionList::query()
            ->join(
                'distribution_events',
                'distribution_events.id',
                '=',
                'distribution_lists.distribution_event_id'
            );

        if (!empty($filters['barangay_id'])) {
            $distribution->where(
                'distribution_lists.barangay_id',
                $filters['barangay_id']
            );
        }

        if (!empty($filters['year'])) {
            $distribution->whereYear(
                'distribution_events.distribution_date',
                $filters['year']
            );
        }

        if (!empty($filters['date_from'])) {
            $distribution->whereDate(
                'distribution_events.distribution_date',
                '>=',
                $filters['date_from']
            );
        }

        if (!empty($filters['date_to'])) {
            $distribution->whereDate(
                'distribution_events.distribution_date',
                '<=',
                $filters['date_to']
            );
        }

        if ($request->filled('supply_id')) {
            $distribution->whereExists(function ($q) use ($request) {
                $q->select(DB::raw(1))
                    ->from('distribution_list_items')
                    ->whereColumn(
                        'distribution_list_items.distribution_list_id',
                        'distribution_lists.id'
                    )
                    ->where(
                        'distribution_list_items.supply_id',
                        $request->supply_id
                    );
            });
        }

        $beneficiaryFarmers = DB::table(
            'distribution_list_farmers'
        )
            ->join(
                'distribution_lists',
                'distribution_lists.id',
                '=',
                'distribution_list_farmers.distribution_list_id'
            )
            ->join(
                'distribution_events',
                'distribution_events.id',
                '=',
                'distribution_lists.distribution_event_id'
            )
            ->when(
                $filters['barangay_id'],
                function ($q) use ($filters) {
                    $q->where(
                        'distribution_lists.barangay_id',
                        $filters['barangay_id']
                    );
                }
            )
            ->when(
                $filters['year'],
                function ($q) use ($filters) {
                    $q->whereYear(
                        'distribution_events.distribution_date',
                        $filters['year']
                    );
                }
            )
            ->when(
                $filters['date_from'],
                function ($q) use ($filters) {
                    $q->whereDate(
                        'distribution_events.distribution_date',
                        '>=',
                        $filters['date_from']
                    );
                }
            )
            ->when(
                $filters['date_to'],
                function ($q) use ($filters) {
                    $q->whereDate(
                        'distribution_events.distribution_date',
                        '<=',
                        $filters['date_to']
                    );
                });

        $distributedItems = DB::table(
            'distribution_list_items'
        )
            ->join(
                'distribution_lists',
                'distribution_lists.id',
                '=',
                'distribution_list_items.distribution_list_id'
            )
            ->join(
                'distribution_events',
                'distribution_events.id',
                '=',
                'distribution_lists.distribution_event_id'
            )
            ->when(
                $filters['barangay_id'],
                function ($q) use ($filters) {
                    $q->where(
                        'distribution_lists.barangay_id',
                        $filters['barangay_id']
                    );
                }
            )
            ->when(
                $filters['year'],
                function ($q) use ($filters) {
                    $q->whereYear(
                        'distribution_events.distribution_date',
                        $filters['year']
                    );
                }
            )
            ->when(
                $filters['date_from'],
                function ($q) use ($filters) {
                    $q->whereDate(
                        'distribution_events.distribution_date',
                        '>=',
                        $filters['date_from']
                    );
                }
            )
            ->when(
                $filters['date_to'],
                function ($q) use ($filters) {
                    $q->whereDate(
                        'distribution_events.distribution_date',
                        '<=',
                        $filters['date_to']
                    );
                }
            );

        if ($request->filled('supply_id')) {
            $distributedItems->where(
                'distribution_list_items.supply_id',
                $request->supply_id
            );
        }

        return response()->json([
            'summary' => [
                'distribution_events' => (clone $distribution)
                    ->distinct(
                        'distribution_lists.id'
                    )
                    ->count(
                        'distribution_lists.id'
                    ),

                'beneficiary_farmers' => (clone $beneficiaryFarmers)
                    ->distinct('farmer_id')
                    ->count('farmer_id'),

                'barangays_served' => (clone $distribution)
                    ->distinct(
                        'distribution_lists.barangay_id'
                    )
                    ->count(
                        'distribution_lists.barangay_id'
                    ),

                'distributed_items' => (clone $distributedItems)
                    ->sum(
                        'distribution_list_items.quantity'
                    ),
            ],

            'barangays' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(distribution_lists.id) as total_events'
                    )
                )
                ->leftJoin(
                    'distribution_lists',
                    'barangays.id',
                    '=',
                    'distribution_lists.barangay_id'
                )
                ->leftJoin(
                    'distribution_events',
                    'distribution_events.id',
                    '=',
                    'distribution_lists.distribution_event_id'
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'distribution_events.distribution_date',
                            $filters['year']
                        );
                    }
                )
                ->when(
                    $filters['date_from'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '>=',
                            $filters['date_from']
                        );
                    }
                )
                ->when(
                    $filters['date_to'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '<=',
                            $filters['date_to']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total_events')
                ->get(),

            'supplies' => InventorySupply::select(
                    'inventory_supplies.id',
                    'inventory_supplies.name as supply_name',
                    DB::raw(
                        'SUM(distribution_list_items.quantity) as total_quantity'
                    )
                )
                ->join(
                    'distribution_list_items',
                    'inventory_supplies.id',
                    '=',
                    'distribution_list_items.supply_id'
                )
                ->join(
                    'distribution_lists',
                    'distribution_lists.id',
                    '=',
                    'distribution_list_items.distribution_list_id'
                )
                ->join(
                    'distribution_events',
                    'distribution_events.id',
                    '=',
                    'distribution_lists.distribution_event_id'
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'distribution_lists.barangay_id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'distribution_events.distribution_date',
                            $filters['year']
                        );
                    }
                )
                ->when(
                    $filters['date_from'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '>=',
                            $filters['date_from']
                        );
                    }
                )
                ->when(
                    $filters['date_to'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '<=',
                            $filters['date_to']
                        );
                    }
                )
                ->when(
                    $request->supply_id,
                    function ($q) use ($request) {
                        $q->where(
                            'inventory_supplies.id',
                            $request->supply_id
                        );
                    }
                )
                ->groupBy(
                    'inventory_supplies.id',
                    'inventory_supplies.name'
                )
                ->orderByDesc('total_quantity')
                ->get(),

            'monthly_distribution' => (clone $distribution)
                ->selectRaw(
                    'MONTH(distribution_events.distribution_date) as month,
                    COUNT(distribution_lists.id) as total_events'
                )
                ->groupByRaw(
                    'MONTH(distribution_events.distribution_date)'
                )
                ->orderByRaw(
                    'MONTH(distribution_events.distribution_date)'
                )
                ->get(),

            'beneficiaries' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(distribution_list_farmers.id) as total'
                    )
                )
                ->leftJoin(
                    'distribution_lists',
                    'barangays.id',
                    '=',
                    'distribution_lists.barangay_id'
                )
                ->leftJoin(
                    'distribution_events',
                    'distribution_events.id',
                    '=',
                    'distribution_lists.distribution_event_id'
                )
                ->leftJoin(
                    'distribution_list_farmers',
                    'distribution_lists.id',
                    '=',
                    'distribution_list_farmers.distribution_list_id'
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'distribution_events.distribution_date',
                            $filters['year']
                        );
                    }
                )
                ->when(
                    $filters['date_from'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '>=',
                            $filters['date_from']
                        );
                    }
                )
                ->when(
                    $filters['date_to'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '<=',
                            $filters['date_to']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total')
                ->get(),

            'top_barangays' => Barangay::select(
                    'barangays.id',
                    'barangays.name',
                    DB::raw(
                        'COUNT(distribution_lists.id) as total'
                    )
                )
                ->leftJoin(
                    'distribution_lists',
                    'barangays.id',
                    '=',
                    'distribution_lists.barangay_id'
                )
                ->leftJoin(
                    'distribution_events',
                    'distribution_events.id',
                    '=',
                    'distribution_lists.distribution_event_id'
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'distribution_events.distribution_date',
                            $filters['year']
                        );
                    }
                )
                ->when(
                    $filters['date_from'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '>=',
                            $filters['date_from']
                        );
                    }
                )
                ->when(
                    $filters['date_to'],
                    function ($q) use ($filters) {
                        $q->whereDate(
                            'distribution_events.distribution_date',
                            '<=',
                            $filters['date_to']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * ============================================================
     * INVENTORY REPORT
     *
     * FIX: this method previously never called reportFilters() at
     * all, so barangay_id / year / season_id from the shared filter
     * bar were completely ignored here — only `category`,
     * `supply_id`, and `stock_status` (all inventory-specific
     * params) were ever read.
     *
     * current_inventory / category_distribution / low_stock /
     * out_of_stock still can't be barangay-scoped: inventory_supplies
     * has no barangay column, it's a single municipal stockroom.
     * "most_distributed" CAN be scoped, though, since it's derived
     * from distribution_list_items -> distribution_lists (barangay) ->
     * distribution_events (date) — so barangay_id and year now filter
     * that section.
     *
     * NOTE: this report intentionally does NOT apply season_id —
     * inventory stock isn't tied to a season, only distribution
     * activity is, and that's already scoped by year/barangay above.
     * ============================================================
     */
    public function inventory(Request $request)
    {
        $filters = $this->reportFilters($request);

        $inventory = InventorySupply::query();

        if ($request->filled('category')) {
            $inventory->where(
                'category',
                $request->category
            );
        }

        if ($request->filled('supply_id')) {
            $inventory->where(
                'id',
                $request->supply_id
            );
        }

        if ($request->filled('stock_status')) {
            if ($request->stock_status === 'out_of_stock') {
                $inventory->where(
                    'qty_available',
                    0
                );
            }

            if ($request->stock_status === 'low_stock') {
                $inventory->whereColumn(
                    'qty_available',
                    '<=',
                    'low_threshold'
                )->where(
                    'qty_available',
                    '>',
                    0
                );
            }

            if ($request->stock_status === 'available') {
                $inventory->whereColumn(
                    'qty_available',
                    '>',
                    'low_threshold'
                );
            }
        }

        return response()->json([
            'summary' => [
                'total_supplies' => (clone $inventory)->count(),

                'low_stock_items' => (clone $inventory)
                    ->whereColumn(
                        'qty_available',
                        '<=',
                        'low_threshold'
                    )
                    ->where(
                        'qty_available',
                        '>',
                        0
                    )
                    ->count(),

                'out_of_stock' => (clone $inventory)
                    ->where(
                        'qty_available',
                        0
                    )
                    ->count(),
            ],

            'current_inventory' => (clone $inventory)
                ->select(
                    'id',
                    'name as supply_name',
                    'category',
                    'qty_available as quantity',
                    'unit',
                    'low_threshold as reorder_level'
                )
                ->orderBy('name')
                ->get(),

            'category_distribution' => (clone $inventory)
                ->select(
                    'category',
                    DB::raw('COUNT(*) as total_items'),
                    DB::raw(
                        'SUM(qty_available) as total_quantity'
                    )
                )
                ->groupBy('category')
                ->orderBy('category')
                ->get(),

            'most_distributed' => InventorySupply::select(
                    'inventory_supplies.id',
                    'inventory_supplies.name as supply_name',
                    DB::raw(
                        'COALESCE(SUM(distribution_list_items.quantity), 0) as distributed'
                    )
                )
                ->leftJoin(
                    'distribution_list_items',
                    'inventory_supplies.id',
                    '=',
                    'distribution_list_items.supply_id'
                )
                ->leftJoin(
                    'distribution_lists',
                    'distribution_lists.id',
                    '=',
                    'distribution_list_items.distribution_list_id'
                )
                ->leftJoin(
                    'distribution_events',
                    'distribution_events.id',
                    '=',
                    'distribution_lists.distribution_event_id'
                )
                ->when(
                    $request->category,
                    function ($q) use ($request) {
                        $q->where(
                            'inventory_supplies.category',
                            $request->category
                        );
                    }
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'distribution_lists.barangay_id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'distribution_events.distribution_date',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'inventory_supplies.id',
                    'inventory_supplies.name'
                )
                ->orderByDesc('distributed')
                ->limit(10)
                ->get(),

            'low_stock' => (clone $inventory)
                ->whereColumn(
                    'qty_available',
                    '<=',
                    'low_threshold'
                )
                ->where(
                    'qty_available',
                    '>',
                    0
                )
                ->orderBy('qty_available')
                ->get(),

            'out_of_stock_items' => (clone $inventory)
                ->where(
                    'qty_available',
                    0
                )
                ->get(),
        ]);
    }

    public function executive(Request $request)
    {
        $filters = $this->reportFilters($request);

        $applications = InsuranceApplication::query();
        $damageReports = DamageReport::query();
        $claims = Claim::query();
        $distribution = DistributionList::query();

        if (!empty($filters['season_id'])) {
            $applications->where(
                'insurance_season_id',
                $filters['season_id']
            );
        }

        if (!empty($filters['barangay_id'])) {
            $applications->whereHas(
                'farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $applications->whereHas(
                'farm',
                function ($q) use ($filters) {
                    $q->where(
                        'crop_type',
                        $filters['crop_type']
                    );
                }
            );
        }

        $this->applyDateFilters(
            $applications,
            $filters
        );

        if (!empty($filters['season_id'])) {
            $damageReports->whereHas(
                'insuranceApplication',
                function ($q) use ($filters) {
                    $q->where(
                        'insurance_season_id',
                        $filters['season_id']
                    );
                }
            );
        }

        if (!empty($filters['barangay_id'])) {
            $damageReports->whereHas(
                'farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $damageReports->whereHas(
                'farm',
                function ($q) use ($filters) {
                    $q->where(
                        'crop_type',
                        $filters['crop_type']
                    );
                }
            );
        }

        // FIX: was previously filtering on `created_at` here, while
        // `top_damage_barangays` below (and the standalone Damage
        // Reports tab) both filter on `damage_date`. That mismatch
        // meant Executive's damage-report KPI count could silently
        // disagree with its own barangay ranking for the same
        // year filter.
        $this->applyDateFilters(
            $damageReports,
            $filters,
            'damage_reports.damage_date'
        );

        if (!empty($filters['season_id'])) {
            $claims->whereHas(
                'damageReport.insuranceApplication',
                function ($q) use ($filters) {
                    $q->where(
                        'insurance_season_id',
                        $filters['season_id']
                    );
                }
            );
        }

        if (!empty($filters['barangay_id'])) {
            $claims->whereHas(
                'damageReport.farm.farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $claims->whereHas(
                'damageReport.farm',
                function ($q) use ($filters) {
                    $q->where(
                        'crop_type',
                        $filters['crop_type']
                    );
                }
            );
        }

        $this->applyDateFilters(
            $claims,
            $filters,
            'claims.created_at'
        );

        if (!empty($filters['barangay_id'])) {
            $distribution->where(
                'barangay_id',
                $filters['barangay_id']
            );
        }

        $distribution->whereHas(
            'event',
            function ($q) use ($filters) {
                if (!empty($filters['year'])) {
                    $q->whereYear(
                        'distribution_date',
                        $filters['year']
                    );
                }

                if (!empty($filters['date_from'])) {
                    $q->whereDate(
                        'distribution_date',
                        '>=',
                        $filters['date_from']
                    );
                }

                if (!empty($filters['date_to'])) {
                    $q->whereDate(
                        'distribution_date',
                        '<=',
                        $filters['date_to']
                    );
                }
            }
        );

        $farmers = FarmerProfile::query();

        if (!empty($filters['barangay_id'])) {
            $farmers->whereHas(
                'user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        $farms = Farm::query();

        if (!empty($filters['barangay_id'])) {
            $farms->whereHas(
                'farmerProfile.user',
                function ($q) use ($filters) {
                    $q->where(
                        'barangay_id',
                        $filters['barangay_id']
                    );
                }
            );
        }

        if (!empty($filters['crop_type'])) {
            $farms->where(
                'crop_type',
                $filters['crop_type']
            );
        }

        return response()->json([
            'kpis' => [
                'registered_farmers' => $farmers->count(),

                'registered_farms' => $farms->count(),

                'insurance_applications' => $applications->count(),

                'insured_farmers' => (clone $applications)
                    ->where(
                        'status',
                        'insured'
                    )
                    ->count(),

                'damage_reports' => $damageReports->count(),

                'claims_processed' => (clone $claims)
                    ->whereIn(
                        'status',
                        [
                            'ready_for_claiming',
                            'claimed',
                        ]
                    )
                    ->count(),

                'distribution_events' => $distribution->count(),

                'inventory_items' => InventorySupply::count(),

                'low_stock_items' => InventorySupply::whereColumn(
                    'qty_available',
                    '<=',
                    'low_threshold'
                )->count(),
            ],

            'top_barangays_by_farmers' => Barangay::select(
                    'barangays.name',
                    DB::raw(
                        'COUNT(DISTINCT farmer_profiles.id) as total'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total')
                ->limit(5)
                ->get(),

            'top_damage_barangays' => Barangay::select(
                    'barangays.name',
                    DB::raw(
                        'COUNT(damage_reports.id) as total'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'damage_reports',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'damage_reports.damage_date',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total')
                ->limit(5)
                ->get(),

            'top_claim_barangays' => Barangay::select(
                    'barangays.name',
                    DB::raw(
                        'COUNT(claims.id) as total'
                    )
                )
                ->leftJoin(
                    'users',
                    'barangays.id',
                    '=',
                    'users.barangay_id'
                )
                ->leftJoin(
                    'farmer_profiles',
                    'users.id',
                    '=',
                    'farmer_profiles.user_id'
                )
                ->leftJoin(
                    'farms',
                    'farmer_profiles.id',
                    '=',
                    'farms.farmer_profile_id'
                )
                ->leftJoin(
                    'damage_reports',
                    'farms.id',
                    '=',
                    'damage_reports.farm_id'
                )
                ->leftJoin(
                    'claims',
                    'damage_reports.id',
                    '=',
                    'claims.damage_report_id'
                )
                ->when(
                    $filters['barangay_id'],
                    function ($q) use ($filters) {
                        $q->where(
                            'barangays.id',
                            $filters['barangay_id']
                        );
                    }
                )
                ->when(
                    $filters['crop_type'],
                    function ($q) use ($filters) {
                        $q->where(
                            'farms.crop_type',
                            $filters['crop_type']
                        );
                    }
                )
                ->when(
                    $filters['year'],
                    function ($q) use ($filters) {
                        $q->whereYear(
                            'claims.created_at',
                            $filters['year']
                        );
                    }
                )
                ->groupBy(
                    'barangays.id',
                    'barangays.name'
                )
                ->orderByDesc('total')
                ->limit(5)
                ->get(),

            'low_stock_supplies' => InventorySupply::whereColumn(
                    'qty_available',
                    '<=',
                    'low_threshold'
                )
                ->orderBy('qty_available')
                ->get([
                    'id',
                    'name as supply_name',
                    'qty_available as quantity',
                    'low_threshold as reorder_level',
                    'unit',
                ]),
        ]);
    }

    /**
     * ============================================================
     * BARANGAY SUPPLIES DISTRIBUTED REPORT
     *
     * SECURITY NOTE: barangay_id is taken from the authenticated
     * user, never from the request, so a barangay account can
     * only ever see its own numbers.
     * ============================================================
     */
    public function barangaySuppliesDistributed(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $barangayId = $user->barangay_id;

        if (!$barangayId) {
            return response()->json(['message' => 'No barangay assigned to this account.'], 422);
        }

        $filters = [
            'year'      => $request->filled('year') ? (int) $request->year : null,
            'date_from' => $request->filled('date_from') ? $request->date_from : null,
            'date_to'   => $request->filled('date_to') ? $request->date_to : null,
        ];

        $baseQuery = DB::table('distribution_list_items')
            ->join(
                'distribution_lists',
                'distribution_lists.id',
                '=',
                'distribution_list_items.distribution_list_id'
            )
            ->join(
                'distribution_events',
                'distribution_events.id',
                '=',
                'distribution_lists.distribution_event_id'
            )
            ->where('distribution_lists.barangay_id', $barangayId)
            ->when($filters['year'], function ($q) use ($filters) {
                $q->whereYear(
                    'distribution_events.distribution_date',
                    $filters['year']
                );
            })
            ->when($filters['date_from'], function ($q) use ($filters) {
                $q->whereDate(
                    'distribution_events.distribution_date',
                    '>=',
                    $filters['date_from']
                );
            })
            ->when($filters['date_to'], function ($q) use ($filters) {
                $q->whereDate(
                    'distribution_events.distribution_date',
                    '<=',
                    $filters['date_to']
                );
            });

        $totalDistributed = (clone $baseQuery)
            ->sum('distribution_list_items.quantity');

        $totalEvents = (clone $baseQuery)
            ->distinct('distribution_lists.id')
            ->count('distribution_lists.id');

        $totalBeneficiaries = DB::table('distribution_list_farmers')
            ->join(
                'distribution_lists',
                'distribution_lists.id',
                '=',
                'distribution_list_farmers.distribution_list_id'
            )
            ->join(
                'distribution_events',
                'distribution_events.id',
                '=',
                'distribution_lists.distribution_event_id'
            )
            ->where('distribution_lists.barangay_id', $barangayId)
            ->when($filters['year'], function ($q) use ($filters) {
                $q->whereYear(
                    'distribution_events.distribution_date',
                    $filters['year']
                );
            })
            ->when($filters['date_from'], function ($q) use ($filters) {
                $q->whereDate(
                    'distribution_events.distribution_date',
                    '>=',
                    $filters['date_from']
                );
            })
            ->when($filters['date_to'], function ($q) use ($filters) {
                $q->whereDate(
                    'distribution_events.distribution_date',
                    '<=',
                    $filters['date_to']
                );
            })
            ->distinct('farmer_id')
            ->count('farmer_id');

        $bySupply = InventorySupply::select(
                'inventory_supplies.id',
                'inventory_supplies.name as supply_name',
                'inventory_supplies.unit',
                DB::raw(
                    'SUM(distribution_list_items.quantity) as total_quantity'
                )
            )
            ->join(
                'distribution_list_items',
                'inventory_supplies.id',
                '=',
                'distribution_list_items.supply_id'
            )
            ->join(
                'distribution_lists',
                'distribution_lists.id',
                '=',
                'distribution_list_items.distribution_list_id'
            )
            ->join(
                'distribution_events',
                'distribution_events.id',
                '=',
                'distribution_lists.distribution_event_id'
            )
            ->where('distribution_lists.barangay_id', $barangayId)
            ->when($filters['year'], function ($q) use ($filters) {
                $q->whereYear(
                    'distribution_events.distribution_date',
                    $filters['year']
                );
            })
            ->when($filters['date_from'], function ($q) use ($filters) {
                $q->whereDate(
                    'distribution_events.distribution_date',
                    '>=',
                    $filters['date_from']
                );
            })
            ->when($filters['date_to'], function ($q) use ($filters) {
                $q->whereDate(
                    'distribution_events.distribution_date',
                    '<=',
                    $filters['date_to']
                );
            })
            ->groupBy(
                'inventory_supplies.id',
                'inventory_supplies.name',
                'inventory_supplies.unit'
            )
            ->orderByDesc('total_quantity')
            ->get();

        $monthly = (clone $baseQuery)
            ->selectRaw(
                'MONTH(distribution_events.distribution_date) as month,
                SUM(distribution_list_items.quantity) as total_quantity'
            )
            ->groupByRaw(
                'MONTH(distribution_events.distribution_date)'
            )
            ->orderByRaw(
                'MONTH(distribution_events.distribution_date)'
            )
            ->get();

        return response()->json([
            'summary' => [
                'total_distributed'   => (float) $totalDistributed,
                'total_events'        => $totalEvents,
                'total_beneficiaries' => $totalBeneficiaries,
            ],

            'by_supply' => $bySupply,

            'monthly_distribution' => $monthly,
        ]);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Farm extends Model
{
    protected $fillable = [
    'farmer_profile_id',
    'farm_name',
    'crop_type',
    'farm_area',
    'farm_image_path',
    'latitude',
    'longitude',
    'geotag_status',
    'insurance_status',

    // Offline support
    'client_uuid',
    'sync_source',
    'captured_at',
];
    protected $casts = [
        'farm_area' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'captured_at' => 'datetime',
    ];

    public function farmerProfile()
    {
        return $this->belongsTo(FarmerProfile::class);
    }

    public function insuranceApplications()
    {
        return $this->hasMany(
            InsuranceApplication::class
        );
    }

    public function damageReports()
    {
        return $this->hasMany(
            DamageReport::class
        );
    }

    public function activeApplication()
{
    // Filter by the CURRENT season (is_default), not by whether that
    // season is still open for new applications (status). A season
    // closing for new applications does not mean it stops being the
    // active/current season — per InsuranceSeasonController::closeCurrent(),
    // a closed season stays "current" (is_default stays true) until a
    // new season is explicitly created. Filtering on `status` here caused
    // insurance_status to fall back to 'not_insured' the moment a season
    // was closed, even for farms with an approved/insured application.
    return $this->hasOne(InsuranceApplication::class)
        ->whereHas('season', function ($query) {
            $query->where('is_default', true);
        })
        ->whereIn('status', [
            'submitted_to_mao', 
            'approved_for_pcic', 
            'submitted_to_pcic', 
            'insured'
        ])
        ->latest();
}

// Appends `insurance_status`, `has_damage_report`, and
// `current_insurance_application_id` dynamically to JSON responses
protected $appends = [
    'insurance_status',
    'has_damage_report',
    'current_insurance_application_id',
];

public function getInsuranceStatusAttribute()
{
    return $this->activeApplication ? $this->activeApplication->status : 'not_insured';
}

// True when the farm's current active application has an unresolved
// damage report against it (same "still active" statuses the app uses
// to decide whether to show the "Damage Report Submitted" banner).
public function getHasDamageReportAttribute()
{
    $activeApp = $this->activeApplication;

    if (!$activeApp) {
        return false;
    }

    return $activeApp->damageReports()
        ->whereIn('status', [
            'pending',
            'submitted_to_mao',
            'validated_by_mao',
            'submitted_to_pcic',
            'under_mao_review',
            'in_pcic_processing',
            'ready_for_claiming',
        ])
        ->exists();
}

// The farm's current-season application id, if any. The Flutter app uses
// this to scope a damage report to "the current season" — without it,
// a report from a previous season could be mistaken for a current one.
public function getCurrentInsuranceApplicationIdAttribute()
{
    return $this->activeApplication?->id;
}
}
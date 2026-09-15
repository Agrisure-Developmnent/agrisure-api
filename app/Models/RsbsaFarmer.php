<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RsbsaFarmer extends Model
{
    use HasFactory;

    protected $table = 'rsbsa_farmers';

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_name',
        'extension_name',
        'gender',
        'contact_number',
        'rsbsa_no',
        'barangay',
    ];

    public function getFullNameAttribute(): string
    {
        $name = $this->last_name . ', ' . $this->first_name;

        if ($this->middle_name) {
            $name .= ' ' . $this->middle_name;
        }

        if ($this->extension_name) {
            $name .= ' ' . $this->extension_name;
        }

        return $name;
    }
}
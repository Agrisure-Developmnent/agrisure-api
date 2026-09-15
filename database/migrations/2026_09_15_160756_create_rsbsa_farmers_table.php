<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rsbsa_farmers', function (Blueprint $table) {
            $table->id();

            // Farmer Information
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('extension_name', 20)->nullable();

            // Personal Information
            $table->string('gender', 20)->nullable();
            $table->string('contact_number', 30)->nullable();

            // RSBSA Information
            $table->string('rsbsa_no', 50)->unique();
            $table->string('barangay', 150);

            $table->timestamps();

            // Search indexes
            $table->index([
                'last_name',
                'first_name',
                'middle_name',
            ]);

            $table->index('barangay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsbsa_farmers');
    }
};
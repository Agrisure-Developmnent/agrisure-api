<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('farmer_profile_id')
                ->constrained('farmer_profiles')
                ->onDelete('cascade');

            $table->string('farm_name');

            $table->enum('crop_type', [
                'Rice',
                'Corn',
            ]);

            $table->decimal('farm_area', 8, 2);

            // Nullable: a walk-in farm registered by MAO may not have
            // a photo captured on-site.
            $table->string('farm_image_path')->nullable();

            // Nullable: a walk-in farm registered by MAO has no GPS
            // capture, since MAO is at the office, not the field.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Tracks whether latitude/longitude are real GPS coordinates
            // or still awaiting capture.
            // 'confirmed' = has real GPS coordinates (self-registered
            //                farmers, or MAO-pinned on a map)
            // 'pending'   = created by MAO without coordinates yet,
            //                needs a follow-up visit or app update
            $table->string('geotag_status')->default('confirmed');

            $table->string('insurance_status')
                ->default('not_insured');

            // OFFLINE SUPPORT
            $table->uuid('client_uuid')
                ->nullable()
                ->unique();

            $table->enum('sync_source', [
                'online',
                'offline',
            ])->default('online');

            $table->timestamp('captured_at')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farms');
    }
};
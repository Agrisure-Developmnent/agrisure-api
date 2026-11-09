<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function maoOnlyReports(): array
    {
        return [
            'overview' => ['/api/reports/overview'],
            'insurance' => ['/api/reports/insurance'],
            'damage reports' => ['/api/reports/damage-reports'],
            'claims' => ['/api/reports/claims'],
            'distribution' => ['/api/reports/distribution'],
            'inventory' => ['/api/reports/inventory'],
            'executive' => ['/api/reports/executive'],
        ];
    }

    /**
     * Reports whose queries run on any database. The other reports use
     * MySQL-only functions (MONTH, TIMESTAMPDIFF, CURDATE), so they can't
     * return 200 on the in-memory SQLite test database; their access
     * rules are still checked by the 403 tests below.
     */
    public static function portableMaoReports(): array
    {
        return [
            'overview' => ['/api/reports/overview'],
            'inventory' => ['/api/reports/inventory'],
            'executive' => ['/api/reports/executive'],
        ];
    }

    #[DataProvider('portableMaoReports')]
    public function test_mao_can_view_report(string $url): void
    {
        Sanctum::actingAs(User::factory()->mao()->create());

        $this->getJson($url)->assertOk();
    }

    #[DataProvider('maoOnlyReports')]
    public function test_farmer_cannot_view_mao_report(string $url): void
    {
        Sanctum::actingAs(User::factory()->farmer()->create());

        $this->getJson($url)
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized.');
    }

    #[DataProvider('maoOnlyReports')]
    public function test_barangay_cannot_view_mao_report(string $url): void
    {
        Sanctum::actingAs(User::factory()->barangay()->create());

        $this->getJson($url)->assertForbidden();
    }

    public function test_guest_cannot_view_reports(): void
    {
        $this->getJson('/api/reports/overview')->assertUnauthorized();
    }

    public function test_farmer_cannot_view_farmer_or_farm_reports(): void
    {
        Sanctum::actingAs(User::factory()->farmer()->create());

        $this->getJson('/api/reports/farmers')->assertForbidden();
        $this->getJson('/api/reports/farms')->assertForbidden();
    }

    public function test_mao_cannot_view_barangay_supplies_distributed_report(): void
    {
        Sanctum::actingAs(User::factory()->mao()->create());

        $this->getJson('/api/reports/supplies-distributed')->assertForbidden();
    }

    public function test_barangay_with_assigned_barangay_passes_role_check_for_supplies_report(): void
    {
        $barangay = Barangay::create(['name' => 'San Fermin']);
        Sanctum::actingAs(User::factory()->barangay()->create(['barangay_id' => $barangay->id]));

        // The report body uses MySQL-only MONTH(), so on SQLite we only
        // assert the request got past auth and role checks.
        $status = $this->getJson('/api/reports/supplies-distributed')->status();

        $this->assertNotContains($status, [401, 403, 422]);
    }

    public function test_supplies_distributed_report_requires_assigned_barangay(): void
    {
        Sanctum::actingAs(User::factory()->barangay()->create(['barangay_id' => null]));

        $this->getJson('/api/reports/supplies-distributed')
            ->assertStatus(422)
            ->assertJsonPath('message', 'No barangay assigned to this account.');
    }
}

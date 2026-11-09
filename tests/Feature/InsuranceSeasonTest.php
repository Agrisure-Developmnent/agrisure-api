<?php

namespace Tests\Feature;

use App\Models\InsuranceSeason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsuranceSeasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->mao()->create());
    }

    public function test_current_season_is_created_automatically_when_none_exists(): void
    {
        $this->getJson('/api/insurance-seasons/current')
            ->assertOk()
            ->assertJsonPath('season.status', 'application_open')
            ->assertJsonPath('season.is_default', true);

        $this->assertSame(1, InsuranceSeason::count());
    }

    public function test_current_season_is_returned_when_it_exists(): void
    {
        $season = InsuranceSeason::create([
            'season_name' => 'Wet Season 2026',
            'status' => 'application_open',
            'is_default' => true,
        ]);

        $this->getJson('/api/insurance-seasons/current')
            ->assertOk()
            ->assertJsonPath('season.id', $season->id)
            ->assertJsonPath('season.season_name', 'Wet Season 2026');
    }

    public function test_season_closes_automatically_after_deadline(): void
    {
        InsuranceSeason::create([
            'season_name' => 'Dry Season 2026',
            'deadline_date' => now()->subDays(2)->toDateString(),
            'status' => 'application_open',
            'is_default' => true,
        ]);

        $this->getJson('/api/insurance-seasons/current')
            ->assertOk()
            ->assertJsonPath('season.status', 'application_closed');
    }

    public function test_mao_can_update_current_season(): void
    {
        $this->putJson('/api/insurance-seasons/current', [
            'season_name' => 'Wet Season 2027',
            'deadline_date' => now()->addMonth()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('season.season_name', 'Wet Season 2027');

        $this->assertDatabaseHas('insurance_seasons', ['season_name' => 'Wet Season 2027']);
    }

    public function test_update_current_season_requires_name(): void
    {
        $this->putJson('/api/insurance-seasons/current', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('season_name');
    }

    public function test_mao_can_close_current_season(): void
    {
        $this->postJson('/api/insurance-seasons/current/close')
            ->assertOk()
            ->assertJsonPath('closed_season.status', 'application_closed');
    }

    public function test_creating_new_season_replaces_the_default(): void
    {
        $old = InsuranceSeason::create([
            'season_name' => 'Old Season',
            'status' => 'application_closed',
            'is_default' => true,
        ]);

        $this->postJson('/api/insurance-seasons/new', ['season_name' => 'New Season'])
            ->assertOk()
            ->assertJsonPath('season.season_name', 'New Season')
            ->assertJsonPath('season.is_default', true);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame(1, InsuranceSeason::where('is_default', true)->count());
    }

    public function test_can_list_all_seasons(): void
    {
        InsuranceSeason::create(['season_name' => 'A', 'status' => 'application_open', 'is_default' => false]);
        InsuranceSeason::create(['season_name' => 'B', 'status' => 'application_open', 'is_default' => true]);

        $this->getJson('/api/insurance-seasons')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_viewing_missing_season_returns_404(): void
    {
        $this->getJson('/api/insurance-seasons/9999')->assertNotFound();
    }
}

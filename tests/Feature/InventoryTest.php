<?php

namespace Tests\Feature;

use App\Models\InventorySupply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsMao(): User
    {
        $mao = User::factory()->mao()->create();
        Sanctum::actingAs($mao);

        return $mao;
    }

    private function supplyPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rice Seeds',
            'category' => 'Seeds',
            'unit' => 'sack',
            'qty_available' => 100,
            'low_threshold' => 20,
        ], $overrides);
    }

    private function createSupply(array $overrides = []): InventorySupply
    {
        return InventorySupply::create(array_merge($this->supplyPayload(), ['status' => 'in-stock'], $overrides));
    }

    public function test_mao_can_list_inventory(): void
    {
        $this->actingAsMao();
        $this->createSupply(['name' => 'Rice Seeds']);
        $this->createSupply(['name' => 'Urea Fertilizer']);

        $this->getJson('/api/inventory')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_mao_can_add_a_supply(): void
    {
        $this->actingAsMao();

        $this->postJson('/api/inventory', $this->supplyPayload())
            ->assertCreated()
            ->assertJsonPath('name', 'Rice Seeds')
            ->assertJsonPath('status', 'in-stock');

        $this->assertDatabaseHas('inventory_supplies', ['name' => 'Rice Seeds']);
    }

    public function test_supply_is_marked_low_when_below_threshold(): void
    {
        $this->actingAsMao();

        $this->postJson('/api/inventory', $this->supplyPayload(['qty_available' => 5]))
            ->assertCreated()
            ->assertJsonPath('status', 'low');
    }

    public function test_supply_is_marked_out_when_quantity_is_zero(): void
    {
        $this->actingAsMao();

        $this->postJson('/api/inventory', $this->supplyPayload(['qty_available' => 0]))
            ->assertCreated()
            ->assertJsonPath('status', 'out');
    }

    public function test_adding_supply_requires_all_fields(): void
    {
        $this->actingAsMao();

        $this->postJson('/api/inventory', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category', 'unit', 'qty_available', 'low_threshold']);
    }

    public function test_mao_can_update_a_supply(): void
    {
        $this->actingAsMao();
        $supply = $this->createSupply();

        $this->putJson("/api/inventory/{$supply->id}", $this->supplyPayload(['qty_available' => 10]))
            ->assertOk()
            ->assertJsonPath('data.qty_available', 10)
            ->assertJsonPath('data.status', 'low');
    }

    public function test_update_rejects_negative_quantity(): void
    {
        $this->actingAsMao();
        $supply = $this->createSupply();

        $this->putJson("/api/inventory/{$supply->id}", $this->supplyPayload(['qty_available' => -5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('qty_available');
    }

    public function test_mao_can_delete_a_supply(): void
    {
        $this->actingAsMao();
        $supply = $this->createSupply();

        $this->deleteJson("/api/inventory/{$supply->id}")->assertOk();

        $this->assertDatabaseMissing('inventory_supplies', ['id' => $supply->id]);
    }

    public function test_updating_missing_supply_returns_404(): void
    {
        $this->actingAsMao();

        $this->putJson('/api/inventory/9999', $this->supplyPayload())->assertNotFound();
    }

    public function test_farmer_cannot_access_inventory(): void
    {
        Sanctum::actingAs(User::factory()->farmer()->create());

        $this->getJson('/api/inventory')->assertForbidden();
        $this->postJson('/api/inventory', $this->supplyPayload())->assertForbidden();
    }

    public function test_guest_cannot_access_inventory(): void
    {
        $this->getJson('/api/inventory')->assertUnauthorized();
    }
}

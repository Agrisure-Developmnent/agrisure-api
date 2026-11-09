<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, bool $read = false): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'title' => 'Application Update',
            'message' => 'Your insurance application was approved.',
            'is_read' => $read,
        ]);
    }

    public function test_user_sees_only_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->notify($user);
        $this->notify($user);
        $this->notify($other);

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_unread_count_is_correct(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify($user);
        $this->notify($user, read: true);

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        $user = User::factory()->create();
        $notification = $this->notify($user);

        Sanctum::actingAs($user);

        $this->postJson("/api/notifications/{$notification->id}/read")->assertOk();

        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_user_cannot_mark_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $notification = $this->notify($owner);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/notifications/{$notification->id}/read")->assertNotFound();
        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_user_can_save_fcm_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/save-fcm-token', ['fcm_token' => 'abc123token'])->assertOk();

        $this->assertSame('abc123token', $user->fresh()->fcm_token);
    }

    public function test_saving_fcm_token_requires_token(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/save-fcm-token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fcm_token');
    }

    public function test_guest_cannot_view_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}

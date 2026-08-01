<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Generic notification bell coverage, adapted from Dot.Billing's version
 * (which exercised a billing-specific notification). Dot.Dopemine has no
 * automated notification triggers yet (see wiki.md roadmap), so this test
 * uses a small inline test notification instead.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_notification_bell_for_authenticated_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeLivewire('notification-bell');
    }

    public function test_unread_count_reflects_database_notifications(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $user->notify(new DopemineTestNotification('Mechanic certified'));

        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->assertSee('Mechanic certified');

        $this->assertEquals(1, $user->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $user->notify(new DopemineTestNotification('Mechanic decertified'));

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->call('markAllAsRead');

        $this->assertEquals(0, $user->fresh()->unreadNotifications()->count());
    }
}

class DopemineTestNotification extends Notification
{
    public function __construct(private readonly string $title)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => 'Test notification for NotificationBell coverage.',
        ];
    }
}

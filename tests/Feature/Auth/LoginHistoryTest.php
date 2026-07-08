<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_records_a_login_event()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $events = LoginHistory::where('user_id', $user->id)->pluck('event_type');
        $this->assertEquals([LoginEventType::Login], $events->all());
    }

    public function test_logout_records_a_logout_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $this->assertSame(
            1,
            LoginHistory::where('user_id', $user->id)
                ->where('event_type', LoginEventType::Logout)
                ->count()
        );
    }

    public function test_failed_login_records_no_event()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $this->assertSame(0, LoginHistory::where('user_id', $user->id)->count());
    }
}

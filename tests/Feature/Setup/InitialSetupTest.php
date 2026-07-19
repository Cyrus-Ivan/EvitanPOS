<?php

namespace Tests\Feature\Setup;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\Auth\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The initial setup wizard (post-v1.0.0, replaces the seeded first-launch
 * Owner): a fresh install — no users at all — is funnelled to /setup, where
 * one atomic submit creates the Owner, saves business/printer settings, and
 * signs in. Once any user exists the wizard is unreachable forever.
 */
class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete, valid wizard submission (printer configured over network).
     *
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Maria Cruz',
            'username' => 'maria',
            'password' => 'a-password-of-my-own',
            'password_confirmation' => 'a-password-of-my-own',
            'business_name' => 'Cruz Sari-Sari Store',
            'business_address' => '123 Mabini St, Manila',
            'tin' => '123-456-789',
            'receipt_footer' => 'Salamat po!',
            'timezone' => 'Asia/Manila',
            'currency_symbol' => '₱',
            'configure_printer' => '1',
            'receipt_paper_size' => '58mm',
            'receipt_connection' => 'network',
            'receipt_printer_name' => '',
            'receipt_device_path' => '',
            'receipt_network_host' => '192.168.1.50',
            'receipt_network_port' => '9100',
            'auto_print' => '1',
            'open_cash_drawer' => '0',
        ], $overrides);
    }

    public function test_a_fresh_install_is_routed_to_the_setup_wizard(): void
    {
        $this->assertDatabaseCount('users', 0);

        $this->get(route('pos'))->assertRedirect(route('setup'));
        $this->get(route('login'))->assertRedirect(route('setup'));
        $this->get(route('setup'))->assertOk()->assertInertia(fn ($page) => $page->component('setup', false));
    }

    public function test_the_wizard_is_unreachable_once_any_user_exists(): void
    {
        User::factory()->cashier()->create();

        $this->get(route('setup'))->assertRedirect(route('login'));
        $this->post(route('setup.store'), $this->payload())->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_archived_user_still_counts_as_an_installed_database(): void
    {
        // A restored backup whose only user was archived must not re-trigger
        // the wizard (it would allow creating a second Owner).
        User::factory()->cashier()->create()->delete();

        $this->get(route('setup'))->assertRedirect(route('login'));
        $this->get(route('pos'))->assertRedirect(route('login'));
    }

    public function test_completing_setup_creates_the_owner_saves_settings_and_signs_in(): void
    {
        // Mirror the native runtime: a boot id exists before any request.
        app(SessionManager::class)->handleBoot();

        $response = $this->post(route('setup.store'), $this->payload());

        $response->assertSessionHasNoErrors()->assertRedirect(route('pos'));

        $owner = User::sole();
        $this->assertSame('maria', $owner->username);
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertFalse($owner->must_change_password);
        $this->assertAuthenticatedAs($owner);

        $this->assertSame('Cruz Sari-Sari Store', Setting::where('key', 'business_name')->value('value'));
        $this->assertSame('Asia/Manila', Setting::where('key', 'timezone')->value('value'));
        $this->assertSame('₱', Setting::where('key', 'currency_symbol')->value('value'));
        $this->assertSame('network', Setting::where('key', 'receipt_connection')->value('value'));
        $this->assertSame('192.168.1.50', Setting::where('key', 'receipt_network_host')->value('value'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.setup_completed',
            'user_id' => $owner->id,
            'auditable_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('login_history', ['user_id' => $owner->id]);

        // The session survives the boot-session guard (boot id was stamped).
        $this->get(route('pos'))->assertOk();
    }

    public function test_the_printer_step_can_be_skipped(): void
    {
        $response = $this->post(route('setup.store'), $this->payload([
            'configure_printer' => '0',
            // With the printer skipped, its fields are not validated…
            'receipt_connection' => 'network',
            'receipt_network_host' => '',
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect(route('pos'));

        // …and nothing printer-related is written; defaults apply at read time.
        $this->assertSame(0, Setting::where('key', 'like', 'receipt_paper%')->count());
        $this->assertNull(Setting::where('key', 'receipt_connection')->first());
        $this->assertNull(Setting::where('key', 'auto_print')->first());
    }

    public function test_validation_failures_persist_nothing(): void
    {
        $invalid = [
            ['business_name' => ''],
            ['timezone' => 'Mars/Olympus_Mons'],
            ['password_confirmation' => 'something-else'],
            ['username' => ''],
            // Printer configured but its required-per-connection field missing.
            ['receipt_connection' => 'network', 'receipt_network_host' => ''],
            ['receipt_connection' => 'system', 'receipt_printer_name' => ''],
        ];

        foreach ($invalid as $overrides) {
            $this->post(route('setup.store'), $this->payload($overrides))->assertSessionHasErrors();

            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('settings', 0);
            $this->assertGuest();
        }
    }

    public function test_a_double_submit_cannot_create_a_second_user(): void
    {
        $this->post(route('setup.store'), $this->payload())->assertRedirect(route('pos'));

        $this->post(route('setup.store'), $this->payload(['username' => 'second']))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 1);
    }
}

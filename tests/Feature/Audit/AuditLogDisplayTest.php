<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 15 read layer — Admin+ access, Owner-only cost redaction in payloads,
 * and the user/action/date filters.
 */
class AuditLogDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_cannot_access_audit_logs()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('audit-logs'))
            ->assertForbidden();
    }

    public function test_admin_can_view_audit_logs()
    {
        AuditLog::factory()->count(3)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('audit-logs'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('audit-logs', false)->has('entries.data', 3));
    }

    public function test_cost_is_redacted_for_admins_but_visible_to_owner()
    {
        AuditLog::factory()->action('inventory.item.updated')->create([
            'new_values' => [
                'name' => 'Widget',
                'variants' => [
                    ['name' => 'Default', 'selling_price' => '10.00', 'cost' => '3.50'],
                ],
            ],
        ]);

        // Admin: cost stripped at every depth, price kept.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('audit-logs'))
            ->assertInertia(fn ($page) => $page
                ->where('entries.data.0.new_values.variants.0.selling_price', '10.00')
                ->missing('entries.data.0.new_values.variants.0.cost'));

        // Owner: cost visible.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('audit-logs'))
            ->assertInertia(fn ($page) => $page
                ->where('entries.data.0.new_values.variants.0.cost', '3.50'));
    }

    public function test_filters_by_action()
    {
        AuditLog::factory()->action('user.created')->count(2)->create();
        AuditLog::factory()->action('pos.sale.created')->count(3)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('audit-logs', ['action' => 'user.created']))
            ->assertInertia(fn ($page) => $page->has('entries.data', 2));
    }

    public function test_filters_by_user()
    {
        $actor = User::factory()->admin()->create();
        AuditLog::factory()->count(2)->for($actor)->create();
        AuditLog::factory()->count(4)->create();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('audit-logs', ['user' => $actor->id]))
            ->assertInertia(fn ($page) => $page->has('entries.data', 2));
    }
}

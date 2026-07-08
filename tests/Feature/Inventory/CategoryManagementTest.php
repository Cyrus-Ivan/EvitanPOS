<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_category()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('categories.store'), ['name' => 'Beverages'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Beverages']);
    }

    public function test_category_name_must_be_unique()
    {
        Category::factory()->create(['name' => 'Snacks']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('categories.store'), ['name' => 'Snacks'])
            ->assertSessionHasErrors('name');
    }

    public function test_owner_can_rename_a_category()
    {
        $category = Category::factory()->create(['name' => 'Old']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('categories.update', $category->id), ['name' => 'New'])
            ->assertSessionHasNoErrors();

        $this->assertSame('New', $category->fresh()->name);
    }

    public function test_category_can_be_archived_and_restored()
    {
        $category = Category::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('categories.archive', $category->id))->assertSessionHasNoErrors();
        $this->assertNotNull($category->fresh()->archived_at);

        $this->actingAs($admin)->patch(route('categories.restore', $category->id))->assertSessionHasNoErrors();
        $this->assertNull($category->fresh()->archived_at);
    }

    public function test_index_hides_archived_by_default()
    {
        Category::factory()->create(['name' => 'Active Cat']);
        Category::factory()->archived()->create(['name' => 'Archived Cat']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('categories.index'))
            ->assertInertia(fn ($page) => $page->has('categories', 1)->where('categories.0.name', 'Active Cat'));
    }

    public function test_cashier_cannot_create_categories()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('categories.store'), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(0, Category::count());
    }
}

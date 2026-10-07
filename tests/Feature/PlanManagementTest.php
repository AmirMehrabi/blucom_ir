<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commerce\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_saves_plan_and_first_draft_together_and_renders_editor(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->get('/admin/plans/create')->assertOk();
        $this->post('/admin/plans', ['name' => 'Business', 'extensions' => 0, 'queues' => 2, 'ivr_menus' => 1])->assertSessionHasErrors('extensions');
        $this->assertDatabaseCount('plans', 0);
        $this->post('/admin/plans', ['name' => 'Business', 'extensions' => 20, 'queues' => 2, 'ivr_menus' => 1])->assertSessionHasNoErrors();
        $plan = Plan::firstOrFail();
        $this->assertDatabaseCount('plan_versions', 1);
        $this->assertNull($plan->versions->first()->published_at);
        $this->get('/admin/plans/'.$plan->id)->assertOk()->assertSee('Business')->assertSee('پیش‌نویس نسخه 1');
    }

    public function test_publication_requires_review_and_rejects_a_changed_draft(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin);
        $service = app(PlanService::class);
        $plan = $service->create($admin, 'Business');
        $draft = $service->version($admin, $plan->id, ['extensions' => 5, 'queues' => 1, 'ivr_menus' => 1]);
        $review = $this->get('/admin/plan-versions/'.$draft->id.'/review')->assertOk();
        $fingerprint = $review->viewData('fingerprint');
        $this->post('/admin/plan-versions/'.$draft->id.'/publish')->assertSessionHasErrors('fingerprint');
        $this->put('/admin/plan-versions/'.$draft->id, ['extensions' => 10, 'queues' => 1, 'ivr_menus' => 1, 'intent' => 'review'])
            ->assertRedirect(route('admin.plans.review', $draft))->assertSessionHasNoErrors();
        $this->post('/admin/plan-versions/'.$draft->id.'/publish', ['fingerprint' => $fingerprint])->assertSessionHasErrors('plan');
        $this->assertNull($draft->fresh()->published_at);
        $fingerprint = $this->get('/admin/plan-versions/'.$draft->id.'/review')->assertOk()->viewData('fingerprint');
        $this->post('/admin/plan-versions/'.$draft->id.'/publish', ['fingerprint' => $fingerprint])->assertRedirect(route('admin.plans.show', $plan))->assertSessionHasNoErrors();
        $this->assertNotNull($draft->fresh()->published_at);
        $this->get('/admin/plan-versions/'.$draft->id.'/review')->assertNotFound();
    }

    public function test_search_and_filters_separate_archived_and_published_plans(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $service = app(PlanService::class);
        $active = $service->create($admin, 'Published business');
        $draft = $service->version($admin, $active->id, ['extensions' => 5, 'queues' => 1, 'ivr_menus' => 1]);
        $service->publish($admin, $draft->id);
        $service->create($admin, 'Unpublished starter');
        $archived = $service->create($admin, 'Retired package');
        $service->archive($admin, $archived->id, 'Retired');
        $this->actingAs($admin)->get('/admin/plans')->assertOk()->assertDontSee('Retired package');
        $this->get('/admin/plans?filter=published')->assertOk()->assertSee('Published business')->assertDontSee('Unpublished starter');
        $this->get('/admin/plans?filter=unpublished')->assertOk()->assertSee('Unpublished starter')->assertDontSee('Published business');
        $this->get('/admin/plans?filter=archived')->assertOk()->assertSee('Retired package')->assertDontSee('Published business');
        $this->get('/admin/plans?q=starter')->assertOk()->assertSee('Unpublished starter')->assertDontSee('Published business');
    }
}

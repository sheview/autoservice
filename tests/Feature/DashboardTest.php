<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        // dashboard.view comes with a role (permissions.json); without any, the page is refused
        $this->actingAs(User::factory()->withRole('user')->create());
        $this->get('/dashboard')->assertStatus(200);

        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertForbidden();
    }
}

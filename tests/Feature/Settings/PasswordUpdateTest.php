<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'New-password-1',
                'password_confirmation' => 'New-password-1',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('New-password-1', $user->refresh()->password));
    }

    public function test_new_password_needs_8_characters_mixed_case_and_a_symbol()
    {
        $user = User::factory()->create();

        foreach (['Ab-1', 'new-password-1', 'NEW-PASSWORD-1', 'Newpassword1'] as $weak) {
            $this
                ->actingAs($user)
                ->from('/settings/password')
                ->put('/settings/password', [
                    'current_password' => 'password',
                    'password' => $weak,
                    'password_confirmation' => $weak,
                ])
                ->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'wrong-password',
                'password' => 'New-password-1',
                'password_confirmation' => 'New-password-1',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/settings/password');
    }
}

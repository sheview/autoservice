<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->other = createTenant('other');
    $this->main = userWithRole('technician', ['email' => 'somchai@itbtthai.com', 'employee_code' => 'E001']); // tenant "default"
    $this->linked = userWithRole('technician', [
        'email' => 'somchai@datacomm-asia.com', 'employee_code' => 'E001', 'login_user_id' => $this->main->id,
    ], $this->other);
});

it('lists the companies of the person and switches to their own account there', function () {
    $this->actingAs($this->main)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('companies', fn ($companies) => collect($companies)->pluck('name')->sort()->values()->all() === ['Default', 'Other']
            && collect($companies)->firstWhere('current', true)['name'] === 'Default'));

    $this->actingAs($this->main)->post("/switch-company/{$this->other->ulid}")->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->linked);

    // ...and back to the main company
    $default = $this->main->tenant;
    $this->post("/switch-company/{$default->ulid}")->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->main);
});

it('shows no company switch to people with one company', function () {
    $alone = userWithRole('helpdesk');

    $this->actingAs($alone)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('companies', []));
    $this->actingAs($alone)->post("/switch-company/{$this->other->ulid}")->assertForbidden();
    $this->assertAuthenticatedAs($alone);
});

it('keeps a deactivated linked account out of its company', function () {
    asTenant($this->other, fn () => $this->linked->update(['is_active' => false]));

    $this->actingAs($this->main)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('companies', []));
    $this->actingAs($this->main)->post("/switch-company/{$this->other->ulid}")->assertForbidden();
    $this->actingAs($this->main)->get('http://other.localhost/dashboard')->assertForbidden();
});

it('lets only the main account log in, also on the subdomain of the other company', function () {
    asTenant($this->other, fn () => $this->linked->forceFill(['password' => 'Secret-123'])->save());

    $this->post('/login', ['email' => $this->linked->email, 'password' => 'Secret-123'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('http://other.localhost/login', ['email' => $this->main->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($this->main);

    // on the other company's host the person works as their account there
    $this->get('http://other.localhost/dashboard')->assertOk();
    $this->assertAuthenticatedAs($this->linked);
});

it('works in each company with that company\'s role, branch and data', function () {
    $branch = asTenant($this->other, fn () => Branch::create(['code' => 'DC', 'name' => 'ดาต้าคอม']));
    asTenant($this->other, function () use ($branch) {
        $this->linked->update(['branch_id' => $branch->id]);
        $this->linked->syncRoles(['helpdesk']);
    });

    $this->actingAs($this->main)->post("/switch-company/{$this->other->ulid}");
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.name', 'Other')
        ->where('auth.user.branch_id', $branch->id)
        ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('tickets.assign')));
});

it('changes the password of the main account from any company', function () {
    $this->actingAs($this->main)->post("/switch-company/{$this->other->ulid}");

    $this->from('/settings/password')->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'New-password-1',
        'password_confirmation' => 'New-password-1',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('New-password-1', $this->main->fresh()->password))->toBeTrue();
});

it('sends the mail of a linked account to the main account', function () {
    expect($this->linked->routeNotificationForMail())->toBe('somchai@itbtthai.com')
        ->and($this->main->routeNotificationForMail())->toBe('somchai@itbtthai.com');
});

it('lets the company admin link a user to a main account of another company', function () {
    $otherAdmin = userWithRole('admin_company', [], $this->other);
    $person = userWithRole('technician', ['email' => 'wichai@itbtthai.com']);
    $payload = ['name' => 'วิชัย', 'email' => 'wichai@datacomm-asia.com', 'role' => 'technician', 'main_email' => 'WICHAI@itbtthai.com'];

    // no password needed for a linked account
    $this->actingAs($otherAdmin)->post('http://other.localhost/users', $payload)->assertSessionHasNoErrors();
    $row = asTenant($this->other, fn () => User::where('email', 'wichai@datacomm-asia.com')->sole());
    expect($row->login_user_id)->toBe($person->id);

    $this->actingAs($otherAdmin)->get("http://other.localhost/users/{$row->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('user.main_email', 'wichai@itbtthai.com'));

    // one account per person in a company
    $this->actingAs($otherAdmin)->post('http://other.localhost/users', [...$payload, 'email' => 'wichai2@datacomm-asia.com'])
        ->assertSessionHasErrors('main_email');
    // unknown e-mail, an account of this company, a linked account, a customer account
    $this->actingAs($otherAdmin)->post('http://other.localhost/users', [...$payload, 'email' => 'x1@x.test', 'main_email' => 'nobody@x.test'])
        ->assertSessionHasErrors('main_email');
    $this->actingAs($otherAdmin)->post('http://other.localhost/users', [...$payload, 'email' => 'x2@x.test', 'main_email' => $otherAdmin->email])
        ->assertSessionHasErrors('main_email');
    $this->actingAs($otherAdmin)->post('http://other.localhost/users', [...$payload, 'email' => 'x3@x.test', 'main_email' => 'somchai@datacomm-asia.com'])
        ->assertSessionHasErrors('main_email');
    // unlinking makes it a normal account again
    $this->actingAs($otherAdmin)->put("http://other.localhost/users/{$row->id}", [...$payload, 'main_email' => '', 'password' => 'Secret-123', 'password_confirmation' => 'Secret-123'])
        ->assertSessionHasNoErrors();
    expect(asTenant($this->other, fn () => $row->fresh()->login_user_id))->toBeNull();
});

it('does not link an account that others are linked to', function () {
    $otherAdmin = userWithRole('admin_company', [], $this->other);

    $this->actingAs(userWithRole('admin_company'))
        ->put("/users/{$this->main->id}", ['name' => 'x', 'email' => $this->main->email, 'role' => 'technician', 'main_email' => $otherAdmin->email])
        ->assertSessionHasErrors(['main_email' => __('identity.users.main_is_main')]);
});

it('links the accounts of people created in two companies by employee code', function () {
    $nan = userWithRole('technician', ['name' => 'นายนันท์ธวัช คำมั่น', 'employee_code' => '680102002']);
    $khom = userWithRole('technician', ['name' => 'นายคมสันต์ เชือพหล', 'employee_code' => '680102002']);
    asTenant($this->other, function () {
        User::factory()->withRole('technician')->create(['name' => 'นันท์ธวัช คำมั่น', 'email' => 'nan@dc.test', 'employee_code' => '680102002']);
        User::factory()->withRole('technician')->create(['name' => 'นายคมสันต์ เชือพหล', 'email' => 'khom@dc.test', 'employee_code' => '680102002']);
        User::factory()->withRole('technician')->create(['name' => 'ไม่มีคู่', 'email' => 'none@dc.test', 'employee_code' => '999']);
    });
    $defaultSubdomain = $this->main->tenant->subdomain;

    $this->artisan('platform:link-accounts', ['main' => $defaultSubdomain, 'other' => 'other'])->assertSuccessful();
    expect(asTenant($this->other, fn () => User::whereNotNull('login_user_id')->count()))->toBe(1); // only the one linked before

    $this->artisan('platform:link-accounts', ['main' => $defaultSubdomain, 'other' => 'other', '--apply' => true])->assertSuccessful();
    asTenant($this->other, function () use ($nan, $khom) {
        expect(User::where('email', 'nan@dc.test')->value('login_user_id'))->toBe($nan->id)
            ->and(User::where('email', 'khom@dc.test')->value('login_user_id'))->toBe($khom->id)
            ->and(User::where('email', 'none@dc.test')->value('login_user_id'))->toBeNull();
    });
});

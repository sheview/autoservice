<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Events\UserSaved;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates a user of the current tenant and sets their role. The company's last active
 * admin keeps that role and stays active (GuardLastAdmin). A change of role is logged.
 */
class SaveUser
{
    public function __construct(private GuardLastAdmin $guardLastAdmin) {}

    /**
     * @param  array{name: string, email: string, password?: string|null, branch_id?: int|null,
     *     employee_code?: string|null, position?: string|null, phone?: string|null,
     *     service_lines?: list<string>, is_active?: bool, role: string, login_user_id?: int|null}  $data
     */
    public function handle(?User $user, array $data): User
    {
        if ($user !== null) {
            $this->guardLastAdmin->handle($user, [$data['role']], (bool) ($data['is_active'] ?? $user->is_active));
        }

        $saved = DB::transaction(function () use ($user, $data) {
            $user ??= new User;
            $before = $user->exists ? $user->getRoleNames()->all() : [];

            $attributes = collect($data)->except(['role', 'password', 'main_email', 'login_user_id'])->all();
            if (! empty($data['password'])) {
                $attributes['password'] = $data['password'];
            } elseif (! $user->exists) {
                // A row linked to a main account never logs in: it gets a password nobody knows.
                $attributes['password'] = Str::password(32);
            }

            $user->fill($attributes);
            if (array_key_exists('login_user_id', $data)) {
                $user->forceFill(['login_user_id' => $data['login_user_id']]);
            }
            $user->save();
            $user->syncRoles([$data['role']]);

            if ($before !== [$data['role']]) {
                activity()->performedOn($user)->event('user_role_changed')
                    ->withProperties(['old' => $before, 'attributes' => [$data['role']]])
                    ->log('เปลี่ยนบทบาทของ '.$user->name);
            }

            return $user;
        });

        UserSaved::dispatch($saved);

        return $saved;
    }
}

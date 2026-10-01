<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

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
     *     service_lines?: list<string>, is_active?: bool, role: string}  $data
     */
    public function handle(?User $user, array $data): User
    {
        if ($user !== null) {
            $this->guardLastAdmin->handle($user, [$data['role']], (bool) ($data['is_active'] ?? $user->is_active));
        }

        return DB::transaction(function () use ($user, $data) {
            $user ??= new User;
            $before = $user->exists ? $user->getRoleNames()->all() : [];

            $attributes = collect($data)->except(['role', 'password'])->all();
            if (! empty($data['password'])) {
                $attributes['password'] = $data['password'];
            }

            $user->fill($attributes)->save();
            $user->syncRoles([$data['role']]);

            if ($before !== [$data['role']]) {
                activity()->performedOn($user)->event('user_role_changed')
                    ->withProperties(['old' => $before, 'attributes' => [$data['role']]])
                    ->log('เปลี่ยนบทบาทของ '.$user->name);
            }

            return $user;
        });
    }
}

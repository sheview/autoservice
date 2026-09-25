<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a user of the current tenant and sets their role.
 */
class SaveUser
{
    /**
     * @param  array{name: string, email: string, password?: string|null, branch_id?: int|null,
     *     employee_code?: string|null, position?: string|null, phone?: string|null,
     *     service_lines?: list<string>, is_active?: bool, role: string}  $data
     */
    public function handle(?User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user ??= new User;

            $attributes = collect($data)->except(['role', 'password'])->all();
            if (! empty($data['password'])) {
                $attributes['password'] = $data['password'];
            }

            $user->fill($attributes)->save();
            $user->syncRoles([$data['role']]);

            return $user;
        });
    }
}

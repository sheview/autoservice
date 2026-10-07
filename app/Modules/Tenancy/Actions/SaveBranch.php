<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Branch;

/**
 * Creates or updates a branch of the current company.
 */
class SaveBranch
{
    /**
     * @param  array{code: string, name: string, address?: string|null, province?: string|null}  $data
     */
    public function handle(?Branch $branch, array $data): Branch
    {
        $branch ??= new Branch;

        $branch->fill([
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'address' => filled($data['address'] ?? null) ? trim($data['address']) : null,
            'province' => filled($data['province'] ?? null) ? trim($data['province']) : null,
        ])->save();

        return $branch;
    }
}

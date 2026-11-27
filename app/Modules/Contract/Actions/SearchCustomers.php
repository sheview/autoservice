<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customers a user may see, searched and sorted as on the customer list (and its Excel export).
 */
class SearchCustomers
{
    public const SORTABLE = ['code', 'name', 'contracts_count', 'created_at'];

    /**
     * @param  array{search: string, sort: string, direction: string}  $filters
     * @return Builder<Customer>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'];

        // A customer's contracts all fall within the same reach as the customer (ContractScope).
        return ContractScope::customers(Customer::query(), $user)
            ->withCount('contracts')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('short_name', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id');
    }
}

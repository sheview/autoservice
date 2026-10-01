<?php

namespace App\Modules\Contract\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Service\Actions\TicketCustomerIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How far customers.* and contracts.* reach (DataScope). Neither has branches, so scope branch
 * is the whole company. Scope own = the customers of the user's own tickets (reported by them
 * or assigned to them, TicketCustomerIds of the Service module) and their contracts; scope
 * customer = the account's own customer and its contracts.
 */
class ContractScope
{
    /**
     * Narrows a customers query to those the user reaches with $permission (customers.*).
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function customers(Builder $query, User $user, string $permission = 'customers.view'): Builder
    {
        return DataScope::constrain($query, $user, $permission, branch: null, customer: $query->qualifyColumn('id'),
            own: fn ($q) => $q->whereIn($query->qualifyColumn('id'), self::ticketCustomerIds($user)));
    }

    /**
     * Narrows a contracts query to those the user reaches with $permission (contracts.*).
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function contracts(Builder $query, User $user, string $permission = 'contracts.view'): Builder
    {
        return DataScope::constrain($query, $user, $permission, branch: null, customer: $query->qualifyColumn('customer_id'),
            own: fn ($q) => $q->whereIn($query->qualifyColumn('customer_id'), self::ticketCustomerIds($user)));
    }

    /** Whether a customer (by id) is one the user's tickets are for: "own" of customers and contracts. */
    public static function ownsCustomer(User $user, ?int $customerId): bool
    {
        return $customerId !== null && in_array($customerId, self::ticketCustomerIds($user), true);
    }

    /** Whether the user reaches the customer (or the contract's customer) with $permission. */
    public static function coversCustomerId(User $user, ?int $customerId, string $permission): bool
    {
        if ($customerId === null) {
            return false;
        }
        $probe = new class extends Model {};
        $probe->setAttribute('customer_id', $customerId);

        return DataScope::covers($probe, $user, $permission, branch: null, customer: 'customer_id',
            own: fn () => self::ownsCustomer($user, $customerId));
    }

    /**
     * @return list<int>
     */
    private static function ticketCustomerIds(User $user): array
    {
        return app(TicketCustomerIds::class)->handle($user);
    }
}

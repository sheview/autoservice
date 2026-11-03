<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\OpenTicketsByCustomer;
use App\Modules\Tenancy\Actions\BranchPlaces;

/**
 * The map tab of the home page: the company's branches and the sites of the customers the user
 * may see, placed on it; each site with its customer's open tickets (when the user sees tickets).
 * Narrowed to one customer (then no branches) or one province.
 */
class MapPlaces
{
    public function __construct(
        private SitePlaces $sitePlaces,
        private BranchPlaces $branchPlaces,
        private OpenTicketsByCustomer $openTickets,
        private Modules $modules,
    ) {}

    /** Staff who may see customers, with the customer module on. */
    public function allowed(User $user): bool
    {
        return $user->customer_id === null && $this->modules->enabled('contract') && $user->can('customers.view');
    }

    /**
     * @param  array{customer_id: int|null, province: string|null}  $filters
     * @return array<string, mixed>
     */
    public function handle(User $user, array $filters): array
    {
        $sites = $this->sitePlaces->handle($user, $filters);
        $tickets = $this->modules->enabled('service') && $user->can('tickets.view') ? $this->openTickets->handle($user) : null;

        return [
            'filters' => $filters,
            'sites' => array_map(fn (array $site) => $site + [
                'tickets' => $tickets[$site['customer_id']] ?? ['open' => 0, 'overdue' => 0],
            ], $sites['places']),
            'unplaced' => $sites['unplaced'],
            'branches' => $filters['customer_id'] ? [] : $this->branchPlaces->handle(),
            'customers' => ContractScope::customers(Customer::query(), $user)->orderBy('name')->get(['id', 'name']),
            'provinces' => $sites['provinces'],
            // Without ticket access the pins are not coloured by tickets.
            'showTickets' => $tickets !== null,
        ];
    }
}

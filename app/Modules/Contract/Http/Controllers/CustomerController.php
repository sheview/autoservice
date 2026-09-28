<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\DeleteCustomer;
use App\Modules\Contract\Actions\SaveCustomer;
use App\Modules\Contract\Http\Requests\CustomerRequest;
use App\Modules\Contract\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    private const SORTABLE = ['code', 'name', 'contracts_count', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];

        $customers = Customer::query()
            ->withCount('contracts')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$filters['search']}%")
                ->orWhere('name', 'ilike', "%{$filters['search']}%")
                ->orWhere('contact_name', 'ilike', "%{$filters['search']}%")
                ->orWhere('tax_id', 'ilike', "%{$filters['search']}%")))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Customer $customer) => [
                ...$customer->only(['id', 'code', 'name', 'contact_name', 'phone', 'email']),
                'contracts_count' => $customer->contracts_count,
            ]);

        $user = $request->user();

        return Inertia::render('Contract/Customers/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'can' => [
                'create' => $user->can('create', Customer::class),
                'update' => $user->can('customer.update'),
                'delete' => $user->can('customer.delete'),
                'viewContracts' => $user->can('contract.view'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('Contract/Customers/Form', ['customer' => null]);
    }

    public function store(CustomerRequest $request, SaveCustomer $saveCustomer): RedirectResponse
    {
        $saveCustomer->handle(null, $request->validated());

        return redirect()->route('contract.customers.index')->with('success', __('contract.customers.created'));
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('Contract/Customers/Form', [
            'customer' => $customer->only(['id', 'code', 'name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes']),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer, SaveCustomer $saveCustomer): RedirectResponse
    {
        $saveCustomer->handle($customer, $request->validated());

        return redirect()->route('contract.customers.index')->with('success', __('contract.customers.updated'));
    }

    public function destroy(Customer $customer, DeleteCustomer $deleteCustomer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $deleteCustomer->handle($customer);

        return redirect()->route('contract.customers.index')->with('success', __('contract.customers.deleted'));
    }
}

<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\DeleteCustomer;
use App\Modules\Contract\Actions\SaveCustomer;
use App\Modules\Contract\Http\Requests\CustomerRequest;
use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Document\Support\Attachments;
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

        $user = $request->user();
        // A customer's contracts all fall within the same reach as the customer (ContractScope).
        $customers = ContractScope::customers(Customer::query(), $user)
            ->withCount('contracts')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$filters['search']}%")
                ->orWhere('name', 'ilike', "%{$filters['search']}%")
                ->orWhere('short_name', 'ilike', "%{$filters['search']}%")
                ->orWhere('contact_name', 'ilike', "%{$filters['search']}%")
                ->orWhere('tax_id', 'ilike', "%{$filters['search']}%")))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Customer $customer) => [
                ...$customer->only(['id', 'code', 'name', 'short_name', 'contact_name', 'phone', 'email']),
                'contracts_count' => $customer->contracts_count,
                'can' => ['update' => $user->can('update', $customer), 'delete' => $user->can('delete', $customer)],
            ]);

        return Inertia::render('Contract/Customers/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'can' => [
                'create' => $user->can('create', Customer::class),
                'update' => $user->can('customers.update'),
                'delete' => $user->can('customers.delete'),
                'viewContracts' => $user->can('contracts.view'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('Contract/Customers/Form', ['customer' => null, 'attachments' => []]);
    }

    public function store(CustomerRequest $request, SaveCustomer $saveCustomer, AddAttachments $addAttachments): RedirectResponse
    {
        $customer = $saveCustomer->handle(null, $request->customerData());
        $addAttachments->handle($customer, $request->attachments());

        return redirect()->route('contract.customers.index')->with('success', __('contract.customers.created'));
    }

    /** The edit page is also where a customer's files are seen (there is no separate detail page). */
    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('Contract/Customers/Form', [
            'customer' => $customer->only(['id', 'code', 'name', 'short_name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes']),
            'attachments' => Attachments::list($customer, $customer->attachmentCollection(), fn (int $id) => route('contract.customers.attachments.show', [$customer, $id])),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer, SaveCustomer $saveCustomer, AddAttachments $addAttachments): RedirectResponse
    {
        $saveCustomer->handle($customer, $request->customerData());
        $addAttachments->handle($customer, $request->attachments());

        return redirect()->route('contract.customers.index')->with('success', __('contract.customers.updated'));
    }

    public function destroy(Customer $customer, DeleteCustomer $deleteCustomer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $deleteCustomer->handle($customer);

        return redirect()->route('contract.customers.index')->with('success', __('contract.customers.deleted'));
    }
}

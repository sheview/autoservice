<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\DeleteCustomer;
use App\Modules\Contract\Actions\ListSites;
use App\Modules\Contract\Actions\SaveCustomer;
use App\Modules\Contract\Actions\SearchCustomers;
use App\Modules\Contract\Exports\CustomersExport;
use App\Modules\Contract\Http\Requests\CustomerRequest;
use App\Modules\Contract\Models\Customer;
use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Document\Support\Attachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerController extends Controller
{
    public function index(Request $request, SearchCustomers $searchCustomers): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = $this->filters($request);

        $user = $request->user();
        $customers = $searchCustomers->handle($user, $filters)
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

    /** The list as Excel: the same search and sort as on screen, all pages, for whoever may see the list. */
    public function export(Request $request, SearchCustomers $searchCustomers): BinaryFileResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $query = $searchCustomers->handle($request->user(), $this->filters($request));

        return Excel::download(new CustomersExport($query), 'customers-'.now()->format('Ymd-His').'.xlsx');
    }

    /** @return array{search: string, sort: string, direction: string} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'sort' => in_array($request->input('sort'), SearchCustomers::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('Contract/Customers/Form', ['customer' => null, 'attachments' => [], 'sites' => []]);
    }

    public function store(CustomerRequest $request, SaveCustomer $saveCustomer, AddAttachments $addAttachments): RedirectResponse
    {
        $customer = $saveCustomer->handle(null, $request->customerData());
        $addAttachments->handle($customer, $request->attachments());

        return redirect()->route('contract.customers.index')->with('success', __('contract.customers.created'));
    }

    /** The edit page is also where a customer's files are seen (there is no separate detail page). */
    public function edit(Customer $customer, ListSites $listSites): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('Contract/Customers/Form', [
            'customer' => $customer->only(['id', 'code', 'name', 'short_name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes', 'require_signature']),
            'attachments' => Attachments::list($customer, $customer->attachmentCollection(), fn (int $id) => route('contract.customers.attachments.show', [$customer, $id])),
            // Where the customer's networks are (IP management).
            'sites' => $listSites->handle($customer->id),
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

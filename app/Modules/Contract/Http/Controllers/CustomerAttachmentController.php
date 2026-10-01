<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Models\Customer;
use App\Modules\Document\Http\Concerns\ServesAttachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to a customer (Word, Excel, PDF). Whoever may see the customer opens them;
 * whoever may edit it adds and deletes them.
 */
class CustomerAttachmentController extends Controller
{
    use ServesAttachments;

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);

        return $this->storeAttachments($request, $customer);
    }

    public function show(Customer $customer, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $customer);

        return $this->showAttachment($customer, $attachment);
    }

    public function destroy(Customer $customer, int $attachment): RedirectResponse
    {
        Gate::authorize('update', $customer);

        return $this->destroyAttachment($customer, $attachment);
    }
}

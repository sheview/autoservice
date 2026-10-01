<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Models\Contract;
use App\Modules\Document\Http\Concerns\ServesAttachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of a contract (Word, Excel, PDF, or a scan as JPG/PNG). They are on a private disk and
 * only served here, after the permission check.
 */
class ContractDocumentController extends Controller
{
    use ServesAttachments;

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        Gate::authorize('update', $contract);

        return $this->storeAttachments($request, $contract);
    }

    public function show(Contract $contract, int $document): StreamedResponse
    {
        Gate::authorize('view', $contract);

        return $this->showAttachment($contract, $document);
    }

    public function destroy(Contract $contract, int $document): RedirectResponse
    {
        Gate::authorize('update', $contract);

        return $this->destroyAttachment($contract, $document);
    }
}

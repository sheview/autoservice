<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\AddContractDocument;
use App\Modules\Contract\Actions\DeleteContractDocument;
use App\Modules\Contract\Models\Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of a contract. They are on a private disk and only served here, after the permission check.
 */
class ContractDocumentController extends Controller
{
    public const MAX_KB = 20480;

    public function store(Request $request, Contract $contract, AddContractDocument $addDocument): RedirectResponse
    {
        Gate::authorize('update', $contract);

        $request->validate(
            ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::MAX_KB]],
            attributes: ['file' => __('contract.documents.file')],
        );

        $addDocument->handle($contract, $request->file('file'));

        return back()->with('success', __('contract.documents.added'));
    }

    public function show(Contract $contract, int $document): StreamedResponse
    {
        Gate::authorize('view', $contract);

        $media = $this->document($contract, $document);

        return response()->streamDownload(
            fn () => fpassthru($media->stream()),
            $media->file_name,
            ['Content-Type' => $media->mime_type],
            'inline',
        );
    }

    public function destroy(Contract $contract, int $document, DeleteContractDocument $deleteDocument): RedirectResponse
    {
        Gate::authorize('update', $contract);

        $deleteDocument->handle($contract, $this->document($contract, $document));

        return back()->with('success', __('contract.documents.deleted'));
    }

    private function document(Contract $contract, int $id): Media
    {
        $media = $contract->getMedia(Contract::DOCUMENTS)->firstWhere('id', $id);
        abort_if($media === null, 404);

        return $media;
    }
}

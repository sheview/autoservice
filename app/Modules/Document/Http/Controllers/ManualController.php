<?php

namespace App\Modules\Document\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\DeleteManual;
use App\Modules\Document\Actions\SaveManual;
use App\Modules\Document\Actions\SearchManuals;
use App\Modules\Document\Http\Concerns\ServesAttachments;
use App\Modules\Document\Http\Requests\ManualRequest;
use App\Modules\Document\Models\Manual;
use App\Modules\Document\Support\Attachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "คู่มือ": the company's manuals, as links and/or attached files. Read by anyone with
 * manuals.view; added, changed and removed with manuals.manage.
 */
class ManualController extends Controller
{
    use ServesAttachments;

    public function index(Request $request, SearchManuals $search): Response
    {
        Gate::authorize('viewAny', Manual::class);
        $filters = SearchManuals::filtersFrom($request);

        return Inertia::render('Document/Manuals/Index', [
            'manuals' => $search->handle($filters)->paginate(20)->withQueryString()->through(fn (Manual $manual) => [
                ...$manual->only(['id', 'title', 'category', 'description', 'links', 'files_count']),
                'updated_at' => $manual->updated_at?->toIso8601String(),
            ]),
            'filters' => $filters,
            'categories' => $this->categories(),
            'can' => ['manage' => $request->user()->can('create', Manual::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Manual::class);

        return Inertia::render('Document/Manuals/Form', ['manual' => null, ...$this->formProps()]);
    }

    public function store(ManualRequest $request, SaveManual $save): RedirectResponse
    {
        $manual = $save->handle(null, $request->manualData(), $request->user(), $request->attachments());

        return redirect()->route('document.manuals.show', $manual)->with('success', __('document.manuals.created'));
    }

    public function show(Request $request, Manual $manual): Response
    {
        Gate::authorize('view', $manual);
        $manage = $request->user()->can('update', $manual);

        return Inertia::render('Document/Manuals/Show', [
            'manual' => [
                ...$manual->only(['id', 'title', 'category', 'description', 'links', 'created_by_name']),
                'created_at' => $manual->created_at?->toIso8601String(),
                'updated_at' => $manual->updated_at?->toIso8601String(),
            ],
            'attachments' => Attachments::list($manual, $manual->attachmentCollection(), fn (int $id) => route('document.manuals.attachments.show', [$manual, $id])),
            'maxMb' => intdiv(Manual::MAX_KB, 1024),
            'can' => ['manage' => $manage],
        ]);
    }

    public function edit(Manual $manual): Response
    {
        Gate::authorize('update', $manual);

        return Inertia::render('Document/Manuals/Form', [
            'manual' => $manual->only(['id', 'title', 'category', 'description', 'links']),
            ...$this->formProps(),
        ]);
    }

    public function update(ManualRequest $request, Manual $manual, SaveManual $save): RedirectResponse
    {
        $save->handle($manual, $request->manualData(), $request->user(), $request->attachments());

        return redirect()->route('document.manuals.show', $manual)->with('success', __('document.manuals.updated'));
    }

    public function destroy(Manual $manual, DeleteManual $delete): RedirectResponse
    {
        Gate::authorize('delete', $manual);
        $delete->handle($manual);

        return redirect()->route('document.manuals.index')->with('success', __('document.manuals.deleted'));
    }

    public function storeFile(Request $request, Manual $manual): RedirectResponse
    {
        Gate::authorize('update', $manual);

        return $this->storeAttachments($request, $manual);
    }

    public function file(Manual $manual, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $manual);

        return $this->showAttachment($manual, $attachment);
    }

    public function destroyFile(Manual $manual, int $attachment): RedirectResponse
    {
        Gate::authorize('update', $manual);

        return $this->destroyAttachment($manual, $attachment);
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return ['categories' => $this->categories(), 'maxLinks' => Manual::MAX_LINKS, 'maxMb' => intdiv(Manual::MAX_KB, 1024)];
    }

    /** @return list<string> the categories in use, for the filter and the form's suggestions */
    private function categories(): array
    {
        return Manual::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }
}

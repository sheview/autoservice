<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ImportParts;
use App\Modules\Inventory\Exports\PartsExport;
use App\Modules\Inventory\Models\Part;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartImportController extends Controller
{
    public const PERMISSION = 'part.import';

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        return $this->page(null);
    }

    /**
     * The import runs here and the page comes back with its result (nothing is kept about it).
     */
    public function store(Request $request, ImportParts $importParts): Response
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        $request->validate(
            ['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']],
            attributes: ['file' => __('inventory.imports.file')],
        );

        return $this->page($importParts->handle($request->file('file'), $request->user()));
    }

    /**
     * An empty sheet with the import headings.
     */
    public function template(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        return Excel::download(new PartsExport(Part::query()->whereRaw('false')), 'part-import-template.xlsx');
    }

    /**
     * @param  array<string, mixed>|null  $result  from ImportParts
     */
    private function page(?array $result): Response
    {
        return Inertia::render('Inventory/Parts/Import', [
            'result' => $result,
            'maxRows' => ImportParts::MAX_ROWS,
        ]);
    }
}

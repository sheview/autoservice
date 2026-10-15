<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ImportContracts;
use App\Modules\Contract\Exports\ContractsExport;
use App\Modules\Contract\Models\Contract;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractImportController extends Controller
{
    public const PERMISSION = 'contracts.import';

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        return $this->page(null);
    }

    /**
     * The import runs here and the page comes back with its result (nothing is kept about it).
     */
    public function store(Request $request, ImportContracts $importContracts): Response
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        $request->validate(
            ['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']],
            attributes: ['file' => __('contract.imports.file')],
        );

        return $this->page($importContracts->handle($request->file('file'), $request->user()));
    }

    /**
     * An empty sheet with the import headings.
     */
    public function template(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        return Excel::download(new ContractsExport(Contract::query()->whereRaw('false')), 'contract-import-template.xlsx');
    }

    /**
     * @param  array<string, mixed>|null  $result  from ImportContracts
     */
    private function page(?array $result): Response
    {
        return Inertia::render('Contract/Contracts/Import', [
            'result' => $result,
            'maxRows' => ImportContracts::MAX_ROWS,
        ]);
    }
}

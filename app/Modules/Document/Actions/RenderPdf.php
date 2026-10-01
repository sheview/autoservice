<?php

namespace App\Modules\Document\Actions;

use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Document\Support\Gotenberg;
use Illuminate\Http\Response;

/**
 * Renders a Blade document (resources/views/documents) to PDF through Gotenberg and returns it as
 * a download response.
 *
 * @throws PdfUnavailable
 */
class RenderPdf
{
    public function __construct(private Gotenberg $gotenberg) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(string $view, array $data, string $fileName): Response
    {
        $pdf = $this->gotenberg->htmlToPdf(view($view, $data)->render());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($fileName).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

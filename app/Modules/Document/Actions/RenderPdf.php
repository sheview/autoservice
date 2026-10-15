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
     * @param  string|null  $footer  HTML printed on every page (page numbers), see Gotenberg::htmlToPdf()
     * @param  bool  $download  ask the browser to save the file rather than show it
     */
    public function handle(string $view, array $data, string $fileName, ?string $footer = null, bool $download = false): Response
    {
        $pdf = $this->gotenberg->htmlToPdf(view($view, $data)->render(), $footer);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.addslashes($fileName).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

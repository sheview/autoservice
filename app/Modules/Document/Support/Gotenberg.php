<?php

namespace App\Modules\Document\Support;

use App\Modules\Document\Exceptions\PdfUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The client of Gotenberg (https://gotenberg.dev), the only way the app makes PDFs:
 * an HTML page goes in, an A4 PDF comes out (Chromium route).
 */
class Gotenberg
{
    /** A4 portrait, in inches as Gotenberg wants them. */
    private const PAGE = [
        'paperWidth' => '8.27',
        'paperHeight' => '11.69',
        'marginTop' => '0.5',
        'marginBottom' => '0.5',
        'marginLeft' => '0.5',
        'marginRight' => '0.5',
        'printBackground' => 'true',
    ];

    /**
     * $footer: a small HTML page printed at the bottom of every page (Chromium fills the elements
     * of class "pageNumber" and "totalPages"); it has no access to the web fonts of the page.
     *
     * @return string the PDF bytes
     *
     * @throws PdfUnavailable when Gotenberg cannot be reached or refuses the page
     */
    public function htmlToPdf(string $html, ?string $footer = null): string
    {
        $url = rtrim((string) config('services.gotenberg.url'), '/').'/forms/chromium/convert/html';

        try {
            $request = Http::timeout((int) config('services.gotenberg.timeout', 30))
                ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html']);
            if ($footer !== null) {
                $request = $request->attach('files', $footer, 'footer.html', ['Content-Type' => 'text/html']);
            }
            // The footer needs room below the page's content.
            $response = $request->post($url, $footer !== null ? ['marginBottom' => '0.7'] + self::PAGE : self::PAGE);
        } catch (ConnectionException $e) {
            report($e);

            throw new PdfUnavailable('Gotenberg is not reachable at '.$url, previous: $e);
        } catch (Throwable $e) {
            report($e);

            throw new PdfUnavailable('Gotenberg request failed', previous: $e);
        }

        if (! $response->successful()) {
            report(new PdfUnavailable("Gotenberg answered {$response->status()}: ".mb_substr($response->body(), 0, 500)));

            throw new PdfUnavailable("Gotenberg answered {$response->status()}");
        }

        return $response->body();
    }

    /**
     * Joins PDFs into one, in the order given (PDF engines route: files are merged by name, so they
     * are named 001.pdf, 002.pdf, …).
     *
     * @param  list<string>  $pdfs  the PDF bytes
     * @return string the PDF bytes
     *
     * @throws PdfUnavailable when Gotenberg cannot be reached or refuses the files
     */
    public function mergePdfs(array $pdfs): string
    {
        if (count($pdfs) === 1) {
            return $pdfs[0];
        }
        $url = rtrim((string) config('services.gotenberg.url'), '/').'/forms/pdfengines/merge';

        try {
            $request = Http::timeout((int) config('services.gotenberg.timeout', 30) * 2);
            foreach (array_values($pdfs) as $i => $pdf) {
                $request = $request->attach('files', $pdf, sprintf('%03d.pdf', $i + 1), ['Content-Type' => 'application/pdf']);
            }
            $response = $request->post($url);
        } catch (ConnectionException $e) {
            report($e);

            throw new PdfUnavailable('Gotenberg is not reachable at '.$url, previous: $e);
        } catch (Throwable $e) {
            report($e);

            throw new PdfUnavailable('Gotenberg request failed', previous: $e);
        }

        if (! $response->successful()) {
            report(new PdfUnavailable("Gotenberg answered {$response->status()}: ".mb_substr($response->body(), 0, 500)));

            throw new PdfUnavailable("Gotenberg answered {$response->status()}");
        }

        return $response->body();
    }
}

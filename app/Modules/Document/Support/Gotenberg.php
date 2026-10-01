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
     * @return string the PDF bytes
     *
     * @throws PdfUnavailable when Gotenberg cannot be reached or refuses the page
     */
    public function htmlToPdf(string $html): string
    {
        $url = rtrim((string) config('services.gotenberg.url'), '/').'/forms/chromium/convert/html';

        try {
            $response = Http::timeout((int) config('services.gotenberg.timeout', 30))
                ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html'])
                ->post($url, self::PAGE);
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

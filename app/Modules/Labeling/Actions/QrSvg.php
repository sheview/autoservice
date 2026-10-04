<?php

namespace App\Modules\Labeling\Actions;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * A QR code as inline SVG markup (no XML header, scales to its box). ECC level M survives a
 * scratched or dusty sticker.
 */
class QrSvg
{
    private ?QRCode $qr = null;

    public function handle(string $text): string
    {
        $this->qr ??= new QRCode(new QROptions([
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'addQuietzone' => true,
            // A white margin of 2 modules: phone cameras find the code more easily.
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'connectPaths' => true,
        ]));

        return $this->qr->render($text);
    }
}

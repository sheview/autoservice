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
    private ?QROptions $options = null;

    public function handle(string $text): string
    {
        $this->options ??= new QROptions([
            'eccLevel' => EccLevel::M,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'addQuietzone' => true,
            // A white margin of 2 modules: phone cameras find the code more easily.
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'connectPaths' => true,
        ]);

        // A new QRCode each time: one that is reused keeps the text of every code before it
        // (render() adds a data segment), so each label after the first held all the earlier links.
        return (new QRCode($this->options))->render($text);
    }
}

<?php

namespace App\Modules\Document\Support;

/**
 * The Thai font of the documents (Sarabun, SIL Open Font License, resources/fonts/sarabun) as CSS
 * font-face rules with the font files inline, so a PDF has its Thai letters whether or not the
 * PDF service can reach the internet. Built once per request.
 */
final class EmbeddedFonts
{
    private const FACES = [
        400 => 'Sarabun-Regular.ttf',
        600 => 'Sarabun-SemiBold.ttf',
        700 => 'Sarabun-Bold.ttf',
    ];

    private static ?string $css = null;

    public static function css(): string
    {
        return self::$css ??= implode("\n", array_map(
            fn (int $weight, string $file) => sprintf(
                "@font-face { font-family: 'Sarabun'; font-style: normal; font-weight: %d; src: url(data:font/ttf;base64,%s) format('truetype'); }",
                $weight,
                base64_encode((string) file_get_contents(resource_path("fonts/sarabun/{$file}"))),
            ),
            array_keys(self::FACES),
            self::FACES,
        ));
    }
}

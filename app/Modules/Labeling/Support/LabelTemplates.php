<?php

namespace App\Modules\Labeling\Support;

/**
 * Label sizes. "roll" = one label per page of a label printer (the page is the label);
 * "sheet" = A4 sticker paper with cols x rows labels. Sizes in millimetres.
 */
class LabelTemplates
{
    public const DEFAULT = 'roll_50x30';

    public const TEMPLATES = [
        'roll_50x30' => ['type' => 'roll', 'width' => 50, 'height' => 30],
        'roll_40x25' => ['type' => 'roll', 'width' => 40, 'height' => 25],
        'roll_70x40' => ['type' => 'roll', 'width' => 70, 'height' => 40],
        // Common A4 sticker sheet (e.g. 70 x 37 mm, 24 per sheet).
        'a4_3x8' => ['type' => 'sheet', 'width' => 70, 'height' => 37, 'cols' => 3, 'rows' => 8],
        'a4_2x7' => ['type' => 'sheet', 'width' => 99, 'height' => 38, 'cols' => 2, 'rows' => 7],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * @return list<array{key: string, type: string, width: int, height: int, cols?: int, rows?: int}>
     */
    public static function all(): array
    {
        return collect(self::TEMPLATES)->map(fn (array $template, string $key) => ['key' => $key] + $template)->values()->all();
    }
}

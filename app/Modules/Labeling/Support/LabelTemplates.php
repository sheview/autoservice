<?php

namespace App\Modules\Labeling\Support;

/**
 * Label sizes. "roll" = one label per page of a label printer (the page is the label);
 * "sheet" = sticker paper (A4 portrait unless "paper" says otherwise) with cols x rows labels.
 * Sizes in millimetres. "detailed" labels carry the company header and the contract, like the
 * stickers MA companies put on the devices they look after; the others are small asset tags.
 */
class LabelTemplates
{
    public const DEFAULT = 'roll_50x30';

    /** Paper sizes of sheets, width x height in millimetres as printed. */
    public const PAPERS = [
        'a4' => ['size' => 'A4', 'width' => 210, 'height' => 297],
        'a3_landscape' => ['size' => 'A3 landscape', 'width' => 420, 'height' => 297],
    ];

    public const TEMPLATES = [
        'roll_50x30' => ['type' => 'roll', 'width' => 50, 'height' => 30],
        'roll_40x25' => ['type' => 'roll', 'width' => 40, 'height' => 25],
        'roll_70x40' => ['type' => 'roll', 'width' => 70, 'height' => 40],
        // Common A4 sticker sheet (e.g. 70 x 37 mm, 24 per sheet).
        'a4_3x8' => ['type' => 'sheet', 'width' => 70, 'height' => 37, 'cols' => 3, 'rows' => 8],
        'a4_2x7' => ['type' => 'sheet', 'width' => 99, 'height' => 38, 'cols' => 2, 'rows' => 7],
        // A3 sheet of 30 device stickers (5 x 6, landscape).
        'a3_5x6' => ['type' => 'sheet', 'paper' => 'a3_landscape', 'width' => 82, 'height' => 48, 'cols' => 5, 'rows' => 6, 'detailed' => true],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function isDetailed(string $key): bool
    {
        return self::TEMPLATES[$key]['detailed'] ?? false;
    }

    /**
     * @return list<array{key: string, type: string, width: int, height: int, cols?: int, rows?: int,
     *     detailed: bool, paper?: array{size: string, width: int, height: int}}>
     */
    public static function all(): array
    {
        return collect(self::TEMPLATES)->map(fn (array $template, string $key) => [
            'key' => $key,
            ...$template,
            'detailed' => $template['detailed'] ?? false,
            ...($template['type'] === 'sheet' ? ['paper' => self::PAPERS[$template['paper'] ?? 'a4']] : []),
        ])->values()->all();
    }
}

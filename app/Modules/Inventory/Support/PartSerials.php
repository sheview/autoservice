<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Asset\Actions\AssetSerialsInUse;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Platform\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serial numbers as people type or paste them: one per line (or separated by commas, semicolons
 * or tabs, as copied from a spreadsheet), spaces around them dropped. A serial is unique per part
 * of the tenant, ignoring case; the same serial on another part or on an asset is only a warning
 * (short serials of different brands do meet).
 */
class PartSerials
{
    public const MAX_LENGTH = 100;

    /**
     * @param  list<string>|string|null  $input
     * @return list<string>
     */
    public static function clean(array|string|null $input): array
    {
        $text = is_array($input) ? implode("\n", array_map('strval', $input)) : (string) $input;
        $serials = preg_split('/[\r\n,;\t]+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $serials), fn (string $serial) => $serial !== ''));
    }

    /**
     * Refuses a list with the wrong count, a serial twice, one too long, or one this part already has.
     *
     * @param  list<string>  $serials
     */
    public static function check(Part $part, array $serials, ?int $count = null, string $field = 'serials', ?int $except = null): void
    {
        if ($count !== null && count($serials) !== $count) {
            throw ValidationException::withMessages([$field => __('inventory.units.count_mismatch', ['count' => $count, 'given' => count($serials)])]);
        }

        $long = array_filter($serials, fn (string $serial) => mb_strlen($serial) > self::MAX_LENGTH);
        if ($long !== []) {
            throw ValidationException::withMessages([$field => __('inventory.units.too_long', ['max' => self::MAX_LENGTH])]);
        }

        $lower = array_map('mb_strtolower', $serials);
        $twice = array_unique(array_diff_assoc($lower, array_unique($lower)));
        if ($twice !== []) {
            throw ValidationException::withMessages([$field => __('inventory.units.repeated', ['serials' => implode(', ', $twice)])]);
        }

        $taken = PartUnit::query()
            ->where('part_id', $part->id)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereIn(DB::raw('lower(serial_number)'), $lower)
            ->pluck('serial_number');
        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([$field => __('inventory.units.taken', ['serials' => $taken->implode(', ')])]);
        }
    }

    /**
     * Where else in the company these serials are already found: other parts and assets.
     *
     * @param  list<string>  $serials
     * @return list<string> one line per serial found
     */
    public static function elsewhere(Part $part, array $serials): array
    {
        if ($serials === []) {
            return [];
        }
        $lower = array_map('mb_strtolower', $serials);

        $lines = PartUnit::query()->with('part:id,code')
            ->where('part_id', '!=', $part->id)
            ->whereIn(DB::raw('lower(serial_number)'), $lower)
            ->get()
            ->map(fn (PartUnit $unit) => __('inventory.units.seen_on_part', ['serial' => $unit->serial_number, 'code' => $unit->part?->code]))
            ->all();

        if (app(Modules::class)->enabled('asset')) {
            foreach (app(AssetSerialsInUse::class)->handle($serials) as $serial => $code) {
                $lines[] = __('inventory.units.seen_on_asset', ['serial' => $serial, 'code' => $code]);
            }
        }

        return $lines;
    }
}

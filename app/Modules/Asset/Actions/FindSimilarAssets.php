<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetSerial;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\SimilarText;

/**
 * Assets of the company that may be the device being typed, though not the same to the letter
 * (those are FindDuplicateAssets): a serial number one holds the other of ("SMPSW00026" ~
 * "SW00026"), or the same owner with a name / brand / model that reads alike
 * (SimilarText). Only a hint while typing: nothing is refused for it.
 */
class FindSimilarAssets
{
    public const LIMIT = 5;

    /** Candidates looked at before scoring. */
    private const CANDIDATES = 200;

    /**
     * @param  array{name?: string|null, brand?: string|null, model?: string|null, customer_id?: int|null, serials?: list<string>|null}  $fields
     * @param  list<int>  $except  the asset edited and the exact matches already shown
     * @return list<array{ulid: string, asset_code: string, name: string, brand: string|null, model: string|null,
     *     serial_number: string|null, reasons: list<string>, blocking: bool, viewable: bool}>  reasons: similar_serial / similar_name
     */
    public function handle(array $fields, array $except, ?User $user = null): array
    {
        $serials = array_values(array_filter(array_map(fn ($s) => SimilarText::normalize(is_string($s) ? $s : null), (array) ($fields['serials'] ?? [])),
            fn (string $s) => mb_strlen($s) >= 5));
        $label = trim(implode(' ', array_filter([$fields['name'] ?? null, $fields['brand'] ?? null, $fields['model'] ?? null])));
        $words = array_slice(SimilarText::words($label), 0, 3);
        $customerId = filled($fields['customer_id'] ?? null) ? (int) $fields['customer_id'] : null;
        if ($serials === [] && $words === []) {
            return [];
        }

        $sql = fn (string $column) => "LOWER(REGEXP_REPLACE(COALESCE({$column}, ''), '[[:space:][:punct:]]', ''))";
        // Serials: on the asset or in its list of serials (lot assets).
        $bySerial = $serials === [] ? [] : AssetSerial::query()
            ->where(function ($q) use ($serials, $sql) {
                foreach ($serials as $serial) {
                    $q->orWhereRaw($sql('serial_number').' like ?', ['%'.addcslashes(mb_substr($serial, -5), '%_\\').'%']);
                }
            })
            ->limit(self::CANDIDATES)->pluck('asset_id')->all();

        $candidates = Asset::query()
            ->when($except !== [], fn ($q) => $q->whereKeyNot($except))
            ->with('serials')
            ->where(function ($q) use ($serials, $bySerial, $words, $customerId, $sql) {
                $q->whereKey($bySerial);
                foreach ($serials as $serial) {
                    $q->orWhereRaw($sql('serial_number').' like ?', ['%'.addcslashes(mb_substr($serial, -5), '%_\\').'%']);
                }
                if ($words !== []) {
                    // Alike by name only within the same owner: units of one model elsewhere are normal.
                    $q->orWhere(fn ($q) => $q
                        ->where(fn ($q) => $customerId === null ? $q->whereNull('customer_id') : $q->where('customer_id', $customerId))
                        ->where(function ($q) use ($words) {
                            foreach ($words as $word) {
                                $q->orWhere('name', 'like', '%'.addcslashes($word, '%_\\').'%')
                                    ->orWhere('model', 'like', '%'.addcslashes($word, '%_\\').'%');
                            }
                        }));
                }
            })
            ->limit(self::CANDIDATES)
            ->get();

        return $candidates
            ->map(function (Asset $asset) use ($serials, $label, $customerId, $user) {
                $theirs = $asset->serials->pluck('serial_number')->push($asset->serial_number)->filter()->unique();
                // Serials only when one holds the other ("SW00026" in "SMPSW00026"): two devices of
                // one batch differ by a digit, so a typo-like difference says nothing.
                $serialScore = collect($serials)->contains(fn (string $s) => $theirs->contains(function ($t) use ($s) {
                    $t = SimilarText::normalize($t);

                    return $t !== $s && mb_strlen($t) >= 5 && (str_contains($t, $s) || str_contains($s, $t));
                })) ? 1.0 : 0.0;
                $nameScore = $label !== '' && $asset->customer_id === $customerId
                    ? SimilarText::score($label, implode(' ', array_filter([$asset->name, $asset->brand, $asset->model])))
                    : 0.0;

                return [
                    ...$asset->only(['ulid', 'asset_code', 'name', 'brand', 'model', 'serial_number']),
                    'reasons' => array_keys(array_filter(
                        ['similar_serial' => $serialScore, 'similar_name' => $nameScore],
                        fn (float $score) => $score >= SimilarText::THRESHOLD,
                    )),
                    'blocking' => false,
                    'viewable' => $user?->can('view', $asset) ?? false,
                    'score' => max($serialScore, $nameScore),
                ];
            })
            ->filter(fn (array $asset) => $asset['reasons'] !== [])
            ->sortByDesc('score')
            ->take(self::LIMIT)
            ->map(fn (array $asset) => collect($asset)->except('score')->all())
            ->values()
            ->all();
    }
}

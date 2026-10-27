<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;

/**
 * Assets of the company that the one being typed would repeat, ignoring case, spaces and
 * punctuation:
 *
 *   property_no, ip_address, mac_address   the same: the same device, saving is refused (blocking)
 *   same_model   same name, brand, model and owner (customer or the company): maybe the same
 *                device typed again, but several units of a model are normal, so saving only
 *                asks to confirm, and only for a new asset
 *
 * Serial numbers are checked apart (AssetRequest::checkSerialsTaken). The form shows the matches
 * while typing so the existing asset is opened or copied instead of typed again.
 */
class FindDuplicateAssets
{
    public const LIMIT = 5;

    public const BLOCKING = ['property_no', 'ip_address', 'mac_address'];

    public static function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/[\s[:punct:]]+/u', '', (string) $value) ?? '');
    }

    /**
     * @param  array{property_no?: string|null, ip_address?: string|null, mac_address?: string|null, name?: string|null,
     *     brand?: string|null, model?: string|null, customer_id?: int|null}  $fields
     * @param  bool  $sameModel  also look for the same name / brand / model / owner (a new asset)
     * @return list<array{ulid: string, asset_code: string, name: string, brand: string|null, model: string|null,
     *     serial_number: string|null, reasons: list<string>, blocking: bool, viewable: bool}>
     */
    public function handle(array $fields, ?int $except, bool $sameModel, ?User $user = null): array
    {
        $checks = array_filter([
            'property_no' => self::normalize($fields['property_no'] ?? null),
            'ip_address' => trim((string) ($fields['ip_address'] ?? '')),
            'mac_address' => self::normalize($fields['mac_address'] ?? null),
        ], fn (string $value) => $value !== '');
        $model = $sameModel && self::normalize($fields['name'] ?? null) !== '' ? [
            'name' => self::normalize($fields['name'] ?? null),
            'brand' => self::normalize($fields['brand'] ?? null),
            'model' => self::normalize($fields['model'] ?? null),
            'customer_id' => filled($fields['customer_id'] ?? null) ? (int) $fields['customer_id'] : null,
        ] : null;
        if ($checks === [] && $model === null) {
            return [];
        }

        // The same normalising in SQL (MariaDB REGEXP_REPLACE).
        $sql = fn (string $column) => "LOWER(REGEXP_REPLACE(COALESCE({$column}, ''), '[[:space:][:punct:]]', ''))";

        return Asset::query()
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where(function ($q) use ($checks, $model, $sql) {
                foreach ($checks as $column => $value) {
                    $column === 'ip_address' ? $q->orWhere('ip_address', $value) : $q->orWhereRaw($sql($column).' = ?', [$value]);
                }
                if ($model !== null) {
                    $q->orWhere(fn ($q) => $q
                        ->whereRaw($sql('name').' = ?', [$model['name']])
                        ->whereRaw($sql('brand').' = ?', [$model['brand']])
                        ->whereRaw($sql('model').' = ?', [$model['model']])
                        ->where(fn ($q) => $model['customer_id'] === null ? $q->whereNull('customer_id') : $q->where('customer_id', $model['customer_id'])));
                }
            })
            ->orderBy('asset_code')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (Asset $asset) use ($checks, $model, $user) {
                $reasons = array_values(array_filter(array_keys($checks), fn (string $column) => $column === 'ip_address'
                    ? $asset->ip_address === $checks[$column]
                    : self::normalize($asset->{$column}) === $checks[$column]));
                if ($model !== null && self::normalize($asset->name) === $model['name'] && self::normalize($asset->brand) === $model['brand']
                    && self::normalize($asset->model) === $model['model'] && $asset->customer_id === $model['customer_id']) {
                    $reasons[] = 'same_model';
                }

                return [
                    ...$asset->only(['ulid', 'asset_code', 'name', 'brand', 'model', 'serial_number']),
                    'reasons' => $reasons,
                    'blocking' => array_intersect($reasons, self::BLOCKING) !== [],
                    // Another branch's asset is named (it is still the same device) but not linked.
                    'viewable' => $user?->can('view', $asset) ?? false,
                ];
            })
            ->all();
    }
}

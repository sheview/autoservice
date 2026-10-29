<?php

namespace App\Modules\Platform\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Why an item is asked for, on each line of an issue/loan request (Asset module) and each item
 * of a purchase request (Inventory module), so nothing goes out without a reason that can be
 * followed up. Replacing a unit (REPLACE, CUSTOMER_SWAP) needs proof of the broken one: its
 * serial number or a photo. OTHER is spelt out in purpose_other.
 */
class ItemPurpose
{
    public const REPLACE = 'replace';

    public const CUSTOMER_SWAP = 'customer_swap';

    public const PROJECT_SPARE = 'project_spare';

    public const DELIVERY = 'delivery';

    public const OTHER = 'other';

    public const ALL = [self::REPLACE, self::CUSTOMER_SWAP, self::PROJECT_SPARE, self::DELIVERY, self::OTHER];

    /** Replacing a broken unit: its serial number or a photo of it is required. */
    public const NEEDS_EVIDENCE = [self::REPLACE, self::CUSTOMER_SWAP];

    /** The photo of the broken unit. */
    public const PHOTO_RULES = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'];

    public static function needsEvidence(?string $purpose): bool
    {
        return in_array($purpose, self::NEEDS_EVIDENCE, true);
    }

    /**
     * Validation rules of the purpose fields under $prefix (e.g. "items.*." or "").
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}purpose" => ['required', Rule::in(self::ALL)],
            "{$prefix}purpose_other" => ['nullable', "required_if:{$prefix}purpose,".self::OTHER, 'string', 'max:255'],
            // Used inside the company, or for a customer (and a project of theirs: checkProject).
            "{$prefix}for_internal" => ['boolean'],
            "{$prefix}customer_name" => ['nullable', "required_unless:{$prefix}for_internal,true", 'string', 'max:255'],
            "{$prefix}replaced_serial" => ['nullable', 'string', 'max:100'],
            "{$prefix}replaced_photo" => self::PHOTO_RULES,
        ];
    }

    /**
     * Not for inside the company: when the customer has projects, the item is for one of them.
     * (A customer typed in, or one without a project, has none to pick.)
     *
     * @param  array<string, mixed>  $item
     * @param  list<array{id: int, customer_name: string|null}>  $contracts  the projects the form offers (ContractOptions)
     */
    public static function checkProject(Validator $validator, string $prefix, array $item, array $contracts): void
    {
        $customer = trim((string) ($item['customer_name'] ?? ''));
        if (filter_var($item['for_internal'] ?? false, FILTER_VALIDATE_BOOL) || $customer === '') {
            return;
        }
        $theirs = collect($contracts)->where('customer_name', $customer);
        if ($theirs->isNotEmpty() && ! $theirs->contains('id', (int) ($item['contract_id'] ?? 0))) {
            $validator->errors()->add("{$prefix}contract_id", __('platform.item_purpose.project_required'));
        }
    }

    /** Checks the serial / photo of the unit replaced: $photo says whether there is one (new or kept). */
    public static function checkEvidence(Validator $validator, string $prefix, array $item, bool $photo): void
    {
        if (self::needsEvidence($item['purpose'] ?? null) && trim((string) ($item['replaced_serial'] ?? '')) === '' && ! $photo) {
            $validator->errors()->add("{$prefix}replaced_serial", __('platform.item_purpose.evidence_required'));
        }
    }

    /** @return array<string, string> */
    public static function attributes(string $prefix = ''): array
    {
        return collect(__('platform.item_purpose.fields'))->mapWithKeys(fn (string $label, string $field) => ["{$prefix}{$field}" => $label])->all();
    }

    /** As people read it: the purpose, or what was written for "other". */
    public static function label(?string $purpose, ?string $other = null): ?string
    {
        return match (true) {
            $purpose === null => null,
            $purpose === self::OTHER => trim((string) $other) !== '' ? trim((string) $other) : __('platform.item_purpose.options.other'),
            default => __("platform.item_purpose.options.{$purpose}"),
        };
    }
}

<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to issue or lend an asset: to a user of the company (borrower_user_id) or to someone
 * from outside (borrower_name). A loan needs its due date.
 *
 * asset-checkouts.create makes a form for anyone; asset-checkouts.request only for oneself (the
 * borrower is always the user, whatever was sent).
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');
        $user = $this->user();

        return $asset instanceof Asset
            && self::canAsk($user)
            && ($user->can('view', $asset) || SearchAssets::askableBy(Asset::query(), $user)->whereKey($asset->id)->exists());
    }

    /** Whether the user may ask for assets at all (for someone else or for themself). */
    public static function canAsk(User $user): bool
    {
        return $user->can('asset-checkouts.create') || $user->can('asset-checkouts.request');
    }

    /** Whether the user may only ask for themself (asset-checkouts.request without .create). */
    public static function onlyForSelf(User $user): bool
    {
        return ! $user->can('asset-checkouts.create');
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if (! self::onlyForSelf($user)) {
            return;
        }

        // Central staff working in this tenant are not its users: their name goes on the form.
        $member = (int) $user->tenant_id === (int) app(TenantContext::class)->id();
        $this->merge([
            'borrower_user_id' => $member ? $user->id : null,
            'borrower_name' => $user->name,
        ]);
    }

    public function rules(): array
    {
        $forSelf = self::onlyForSelf($this->user());

        return [
            'type' => ['required', Rule::in(AssetCheckout::TYPES)],
            // Enough of it is checked by RequestCheckout, under a lock.
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNull('deleted_at')],
            'borrower_user_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($forSelf) {
                // Staff of this company only (the list the form offers); oneself is always allowed.
                if ($forSelf && (int) $value === $this->user()->id) {
                    return;
                }
                if (! app(UsersWithPermission::class)->handle('assets.view')->contains('id', (int) $value)) {
                    $fail(__('validation.exists', ['attribute' => __('asset.checkouts.fields.borrower')]));
                }
            }],
            'borrower_name' => ['required_without:borrower_user_id', 'nullable', 'string', 'max:255'],
            'borrower_department' => ['nullable', 'string', 'max:255'],
            'borrower_phone' => ['nullable', 'string', 'max:50'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['required_if:type,'.AssetCheckout::TYPE_LOAN, 'nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function attributes(): array
    {
        return __('asset.checkouts.fields');
    }
}

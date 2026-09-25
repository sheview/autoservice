<?php

namespace App\Modules\Platform\CrossTenant;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * E-mail must be unique across all tenants (one e-mail = one login).
 * A normal "unique" rule would only see the current tenant's users because of RLS.
 */
class UniqueUserEmail implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = IdentityLookup::run(fn () => DB::table('users')
            ->whereRaw('lower(email) = lower(?)', [$value])
            ->when($this->ignoreUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists());

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}

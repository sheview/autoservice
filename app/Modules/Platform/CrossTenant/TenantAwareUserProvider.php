<?php

namespace App\Modules\Platform\CrossTenant;

use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent user provider that finds users before the tenant is known.
 *
 * The user is looked up across tenants (IdentityLookup) and without the tenant global scope.
 * Everything that happens after authentication runs inside the user's tenant as usual.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return IdentityLookup::run(fn () => parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return IdentityLookup::run(fn () => parent::retrieveByToken($identifier, $token));
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        return IdentityLookup::run(fn () => parent::retrieveByCredentials($credentials));
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        IdentityLookup::run(fn () => parent::updateRememberToken($user, $token));
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
        IdentityLookup::run(fn () => parent::rehashPasswordIfRequired($user, $credentials, $force));
    }

    protected function newModelQuery($model = null)
    {
        // The user's tenant is not known yet, so the tenant scope must not apply here.
        // Rows are still limited by RLS (IdentityLookup) to this lookup only.
        /** @var Builder $query */
        $query = parent::newModelQuery($model);

        return $query->withoutGlobalScope(TenantScope::class);
    }
}

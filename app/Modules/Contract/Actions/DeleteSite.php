<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\CustomerSite;

/**
 * Removes (soft deletes) a site. Networks at the site keep its name (ListSites withTrashed).
 */
class DeleteSite
{
    public function handle(CustomerSite $site): void
    {
        $site->delete();
    }
}

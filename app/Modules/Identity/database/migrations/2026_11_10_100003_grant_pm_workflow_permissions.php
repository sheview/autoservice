<?php

use App\Modules\Identity\Actions\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * PM work orders gained review / approve / publish, and approved reports their own pages and
 * customer links (pm-reports, pm-settings). Adds the permission rows and gives the roles of
 * every company what their default role now has (PermissionCatalog::DEFAULT_ROLES); grants a
 * company already has are left as they are. Runs across every tenant on purpose (platform data
 * change, by role name and permission). The admin role holds every permission anyway.
 */
return new class extends Migration
{
    /** role name => [permission => scope] */
    private const GRANTS = [
        'admin_company' => [
            'pm-visits.review' => 'all', 'pm-visits.approve' => 'all', 'pm-visits.publish' => 'all',
            'pm-reports.view' => 'all', 'pm-reports.share' => 'all', 'pm-settings.manage' => 'all',
        ],
        'helpdesk' => ['pm-visits.review' => 'all', 'pm-reports.view' => 'all', 'pm-reports.share' => 'all'],
        'customer_it' => ['pm-reports.view' => 'customer'],
        // Platform roles (central staff, scope all).
        'central_helpdesk' => ['pm-visits.review' => 'all', 'pm-reports.view' => 'all', 'pm-reports.share' => 'all'],
    ];

    public function up(): void
    {
        app(SyncPermissions::class)->handle();
        $permissions = DB::table('permissions')->pluck('id', 'name');

        foreach (self::GRANTS as $role => $grants) {
            foreach (DB::table('roles')->where('name', $role)->pluck('id') as $roleId) {
                foreach ($grants as $permission => $scope) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'role_id' => $roleId, 'permission_id' => $permissions[$permission], 'scope' => $scope,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', ['pm-visits.review', 'pm-visits.approve', 'pm-visits.publish', 'pm-reports.view', 'pm-reports.share', 'pm-settings.manage'])
            ->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

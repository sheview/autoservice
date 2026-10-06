<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles and permissions as in permissions.json:
 *
 * - every grant of a role keeps a scope (all / branch / own / customer) on role_has_permissions;
 * - the permissions get the new names (ticket.view → tickets.view, ...); one old permission may
 *   become several, and roles and users holding it get all of them (branch.all goes: the scope
 *   of each grant says it now);
 * - the customer account role becomes customer_it.
 *
 * Grants copied here keep scope "all" (branch for roles that had no branch.all, as before), so
 * nobody sees more than they did. `php artisan platform:sync-permissions --defaults` then puts
 * the default roles exactly as permissions.json says.
 */
return new class extends Migration
{
    /** Old name => new names. */
    private const RENAMES = [
        'user.view' => ['users.view'],
        'user.create' => ['users.manage'],
        'user.update' => ['users.manage'],
        'role.view' => ['roles.manage'],
        'role.create' => ['roles.manage'],
        'role.update' => ['roles.manage'],
        'branch.view' => ['branches.view'],
        'branch.create' => ['branches.manage'],
        'branch.update' => ['branches.manage'],
        'branch.delete' => ['branches.manage'],
        'asset.view' => ['assets.view', 'ip-check.view', 'ip-check.run', 'dashboard.view'],
        'asset.create' => ['assets.create'],
        'asset.update' => ['assets.update'],
        'asset.delete' => ['assets.delete'],
        'asset.import' => ['assets.import'],
        'asset.export' => ['assets.export'],
        'asset.checkout' => ['asset-checkouts.view', 'asset-checkouts.create', 'asset-checkouts.return'],
        'asset.approve' => ['asset-checkouts.approve', 'purchase-requests.approve'],
        'asset_category.view' => ['asset-categories.view'],
        'asset_category.create' => ['asset-categories.manage'],
        'asset_category.update' => ['asset-categories.manage'],
        'asset_category.delete' => ['asset-categories.manage'],
        'customer.view' => ['customers.view'],
        'customer.create' => ['customers.create'],
        'customer.update' => ['customers.update'],
        'customer.delete' => ['customers.delete'],
        'contract.view' => ['contracts.view'],
        'contract.create' => ['contracts.create'],
        'contract.update' => ['contracts.update'],
        'contract.delete' => ['contracts.delete'],
        'ticket.view' => ['tickets.view'],
        'ticket.create' => ['tickets.create'],
        'ticket.update' => ['tickets.update'],
        'ticket.assign' => ['tickets.assign'],
        'ticket.approve' => ['tickets.approve'],
        'ticket.close' => ['tickets.close'],
        'holiday.view' => ['holidays.view'],
        'holiday.create' => ['holidays.manage'],
        'holiday.delete' => ['holidays.manage'],
        'pm.view' => ['pm-visits.view', 'pm-plans.view', 'pm-checklists.view'],
        'pm.create' => ['pm-visits.create', 'pm-plans.create'],
        'pm.update' => ['pm-visits.update', 'pm-plans.update', 'pm-checklists.manage'],
        'pm.delete' => ['pm-visits.delete', 'pm-plans.delete'],
        'pm.perform' => ['pm-visits.complete'],
        'sticker.print' => ['labels.view', 'labels.print'],
        'part.view' => ['parts.view'],
        'part.create' => ['parts.create'],
        'part.update' => ['parts.update'],
        'part.delete' => ['parts.delete'],
        'part.import' => ['parts.import'],
        'part.export' => ['parts.export'],
        'stock.view' => ['stock-movements.view'],
        'stock.receive' => ['stock-movements.create', 'purchase-requests.receive'],
        'stock.issue' => ['parts.issue'],
        'stock.adjust' => ['stock-movements.create'],
        'survey.view' => ['surveys.view'],
        'survey.answer' => ['surveys.respond'],
        'report.view' => ['reports.view', 'reports.export', 'summary-people.view', 'summary-projects.view'],
        'purchase.request' => ['purchase-requests.view', 'purchase-requests.create'],
        'company.update' => ['company.view', 'company.manage', 'alerts.manage'],
        'log.view' => ['activity-log.view'],
    ];

    private const RLS_TABLES = ['roles', 'model_has_permissions'];

    public function up(): void
    {
        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->string('scope', 10)->default('all');
        });

        // This runs across every tenant: let the owner past row level security meanwhile.
        foreach (self::RLS_TABLES as $table) {
            Rls::noForce($table);
        }

        $now = now();
        $id = function (string $name) use ($now): int {
            $existing = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

            return $existing ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        };

        // Roles that saw one branch only (no branch.all) keep seeing one branch.
        $allBranches = DB::table('permissions')->where('name', 'branch.all')->value('id');
        $branchWide = $allBranches ? DB::table('role_has_permissions')->where('permission_id', $allBranches)->pluck('role_id')->all() : [];
        $customerRoles = DB::table('roles')->where('name', 'customer')->pluck('id')->all();

        foreach (self::RENAMES as $old => $news) {
            $oldId = DB::table('permissions')->where('name', $old)->value('id');
            if ($oldId === null) {
                continue;
            }

            foreach ($news as $new) {
                $newId = $id($new);
                foreach (DB::table('role_has_permissions')->where('permission_id', $oldId)->pluck('role_id') as $roleId) {
                    $scope = in_array($roleId, $customerRoles, true) ? 'customer' : (in_array($roleId, $branchWide, true) ? 'all' : 'branch');
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $newId, 'role_id' => $roleId, 'scope' => $scope]);
                }
                foreach (DB::table('model_has_permissions')->where('permission_id', $oldId)->get() as $row) {
                    DB::table('model_has_permissions')->insertOrIgnore([...(array) $row, 'permission_id' => $newId]);
                }
            }
        }

        // The old names (and branch.all) go.
        $oldIds = DB::table('permissions')->whereIn('name', [...array_keys(self::RENAMES), 'branch.all'])->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $oldIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $oldIds)->delete();
        DB::table('permissions')->whereIn('id', $oldIds)->delete();

        DB::table('roles')->where('name', 'customer')->update(['name' => 'customer_it', 'label' => 'เจ้าหน้าที่ไอทีของลูกค้า']);

        foreach (self::RLS_TABLES as $table) {
            Rls::force($table);
        }
    }

    public function down(): void
    {
        // The old names are not brought back: restore from a backup to go back.
        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};

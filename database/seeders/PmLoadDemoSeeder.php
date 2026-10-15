<?php

namespace Database\Seeders;

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Contract\Actions\SaveContract;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\SyncTemplateAssignments;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * A PM work order of 300 pieces of equipment, to try the table (PC-SET) on a phone or tablet: a
 * customer with 150 PCs over 3 floors, each with its monitor (a part), a contract, and a plan given
 * to the first technician. Not part of DatabaseSeeder; run on the demo data (DemoSeeder) with
 *
 *   php artisan db:seed --class=PmLoadDemoSeeder
 *
 * into the company of subdomain PM_LOAD_TENANT (default "itsol"). Runs once per company.
 */
class PmLoadDemoSeeder extends Seeder
{
    private const CUSTOMER_CODE = 'LOAD300';

    public function run(TenantContext $context): void
    {
        $tenant = Tenant::query()->where('subdomain', env('PM_LOAD_TENANT', 'itsol'))->firstOrFail();
        $context->run($tenant, function () {
            if (Customer::query()->where('code', self::CUSTOMER_CODE)->exists()) {
                $this->command?->warn('Already seeded: customer '.self::CUSTOMER_CODE);

                return;
            }

            $pcs = AssetCategory::query()->where('code_prefix', 'PC')->firstOrFail();
            $monitors = AssetCategory::query()->firstOrCreate(['code_prefix' => 'MON'], ['name' => 'จอภาพ']);
            // Monitors are checked with the PC table too.
            $assignments = PmTemplateAssignment::query()->where('template_code', 'PC-SET')->get()
                ->map(fn (PmTemplateAssignment $a) => $a->only(['asset_category_id', 'asset_brand_id', 'asset_model_id']))->all();
            if (! collect($assignments)->contains('asset_category_id', $monitors->id)) {
                app(SyncTemplateAssignments::class)->handle('PC-SET', [...$assignments, ['asset_category_id' => $monitors->id]]);
            }

            $customer = Customer::create(['code' => self::CUSTOMER_CODE, 'name' => 'สำนักงานทดสอบ 300 เครื่อง']);
            $save = app(SaveAsset::class);
            $pcIds = [];
            for ($i = 1; $i <= 150; $i++) {
                $floor = (string) (intdiv($i - 1, 50) + 1);
                $room = 'ห้อง '.$floor.'0'.(intdiv(($i - 1) % 50, 10) + 1);
                $pc = $save->handle(null, [
                    'category_id' => $pcs->id, 'customer_id' => $customer->id, 'status' => Asset::STATUS_IN_USE,
                    'name' => 'PC '.$room.' #'.$i, 'brand' => 'Dell', 'model' => 'OptiPlex 7010',
                    'serial_number' => sprintf('DL%07d', $i), 'property_no' => sprintf('ครุภัณฑ์-%04d', $i), 'floor' => $floor, 'room' => $room,
                ]);
                $save->handle(null, [
                    'category_id' => $monitors->id, 'customer_id' => $customer->id, 'status' => Asset::STATUS_IN_USE, 'parent_asset_id' => $pc->id,
                    'name' => 'จอ '.$room.' #'.$i, 'brand' => 'Dell', 'model' => 'P2422H',
                    'serial_number' => sprintf('MN%07d', $i), 'floor' => $floor, 'room' => $room,
                ]);
                $pcIds[] = $pc->id;
            }

            $contract = app(SaveContract::class)->handle(null, [
                'customer_id' => $customer->id, 'contract_no' => 'MA-LOAD-300', 'title' => 'สัญญาทดสอบ PM 300 เครื่อง',
                'status' => Contract::STATUS_ACTIVE, 'starts_on' => today()->startOfMonth()->toDateString(),
                'ends_on' => today()->startOfMonth()->addYear()->subDay()->toDateString(), 'service_window' => '8x5', 'notify_days_before' => 60,
            ]);
            $contract->contractAssets()->createMany(array_map(fn (int $id) => ['asset_id' => $id], $pcIds));

            $technician = User::query()->whereNull('customer_id')->whereHas('roles', fn ($q) => $q->where('name', 'technician'))->orderBy('id')->first();
            $plan = app(SavePmPlan::class)->handle(null, [
                'contract_id' => $contract->id, 'title' => 'PM PC 300 เครื่อง', 'interval_months' => 6, 'assignee_id' => $technician?->id,
                'asset_ids' => $pcIds,
            ]);
            // The PCs of this plan use the table, whatever template the company gives PCs otherwise.
            PmPlanAsset::query()->where('pm_plan_id', $plan->id)->update(['template_code' => 'PC-SET']);

            $this->command?->info("Plan #{$plan->id}: work order ".$plan->visits()->value('visit_no').' for '.($technician?->email ?? 'no one'));
        });
    }
}

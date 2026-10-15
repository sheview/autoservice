<?php

namespace Database\Seeders;

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Contract\Actions\SaveContract;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\AddStandardChecklists;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\SchedulePmVisits;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Sample Data Center PM work: a customer's data center (electrical, UPS with its batteries, precision
 * air, fire suppression, water leak, HSSD, monitoring, control room), the categories its standard
 * templates (StandardTemplates DC-*) are tied to, a monthly plan for the first technician, and two
 * work orders:
 *
 *   this month  done: readings filled in (one UPS reading out of range, a failed precision air, a
 *               sensor not inspected), signed by the technician and the customer, approved, published
 *   next month  scheduled, to try on the phone
 *
 * Not part of DatabaseSeeder; run on the demo data (DemoSeeder) with
 *
 *   php artisan db:seed --class=PmDataCenterDemoSeeder
 *
 * into the company of subdomain PM_DEMO_TENANT (default "itsol"). Runs once per company.
 */
class PmDataCenterDemoSeeder extends Seeder
{
    use PmDemoWork;

    private const CUSTOMER_CODE = 'DCDEMO';

    /** Category prefix => name; the standard Data Center templates are tied to these prefixes. */
    private const CATEGORIES = [
        'EDB' => 'ตู้ไฟฟ้า (MDB / PDU)',
        'UPS' => 'UPS',
        'BAT' => 'แบตเตอรี่ UPS',
        'PAC' => 'เครื่องปรับอากาศ Precision',
        'FSS' => 'ระบบดับเพลิง',
        'WLD' => 'ระบบตรวจจับน้ำรั่ว',
        'SD' => 'ระบบตรวจจับควัน (HSSD)',
        'EMS' => 'ระบบ Monitoring',
        'CTR' => 'ห้องควบคุม',
    ];

    /** [prefix, name, brand, model, room, parent (index)] */
    private const EQUIPMENT = [
        ['EDB', 'ตู้ไฟหลัก MDB-01', 'Schneider', 'Prisma P', 'ห้องไฟฟ้า', null],
        ['EDB', 'PDU แถว A', 'Schneider', 'PDU 40kVA', 'ห้อง Server', null],
        ['UPS', 'UPS-01', 'Eaton', '93PM 60kVA', 'ห้อง UPS', null],
        ['UPS', 'UPS-02', 'Eaton', '93PM 60kVA', 'ห้อง UPS', null],
        ['BAT', 'แบตเตอรี่ UPS-01', 'CSB', 'HRL12540W', 'ห้อง UPS', 2],
        ['BAT', 'แบตเตอรี่ UPS-02', 'CSB', 'HRL12540W', 'ห้อง UPS', 3],
        ['PAC', 'Precision Air PAC-01', 'STULZ', 'CyberAir 3PRO', 'ห้อง Server', null],
        ['PAC', 'Precision Air PAC-02', 'STULZ', 'CyberAir 3PRO', 'ห้อง Server', null],
        ['FSS', 'ระบบดับเพลิง FM-200', 'Kidde', 'FM-200 Panel', 'ห้อง Server', null],
        ['WLD', 'Water Leak Detection', 'RLE', 'SeaHawk LD5100', 'ห้อง Server', null],
        ['SD', 'VESDA HSSD', 'Xtralis', 'VLF-500', 'ห้อง Server', null],
        ['EMS', 'Monitoring Packet Power', 'Packet Power', 'GW04', 'ห้อง Server', null],
        ['CTR', 'ห้องควบคุม NOC', '-', '-', 'ห้องควบคุม', null],
    ];

    public function run(TenantContext $context): void
    {
        $tenant = Tenant::query()->where('subdomain', env('PM_DEMO_TENANT', 'itsol'))->firstOrFail();
        $context->run($tenant, function () {
            if (Customer::query()->where('code', self::CUSTOMER_CODE)->exists()) {
                $this->command?->warn('Already seeded: customer '.self::CUSTOMER_CODE);

                return;
            }

            $categories = [];
            foreach (self::CATEGORIES as $prefix => $name) {
                $categories[$prefix] = AssetCategory::query()->firstOrCreate(['code_prefix' => $prefix], ['name' => $name]);
            }
            $added = app(AddStandardChecklists::class)->handle();

            $customer = Customer::create(['code' => self::CUSTOMER_CODE, 'name' => 'ศูนย์ข้อมูล บริษัท ดาต้าเซ็นเตอร์ทดสอบ จำกัด']);
            $save = app(SaveAsset::class);
            $assets = [];
            foreach (self::EQUIPMENT as $i => [$prefix, $name, $brand, $model, $room, $parent]) {
                $assets[$i] = $save->handle(null, [
                    'category_id' => $categories[$prefix]->id, 'customer_id' => $customer->id, 'status' => Asset::STATUS_IN_USE,
                    'name' => $name, 'brand' => $brand, 'model' => $model, 'serial_number' => sprintf('DC%s%05d', $prefix, 101 + $i),
                    'property_no' => sprintf('DC-%03d', $i + 1), 'floor' => '1', 'room' => $room, 'location' => 'อาคาร Data Center',
                    'ip_address' => in_array($prefix, ['UPS', 'PAC', 'EMS', 'EDB'], true) ? '10.20.0.'.(11 + $i) : null,
                    'parent_asset_id' => $parent !== null ? $assets[$parent]->id : null,
                ]);
            }

            $contract = app(SaveContract::class)->handle(null, [
                'customer_id' => $customer->id, 'contract_no' => 'MA-DC-DEMO', 'title' => 'สัญญาบำรุงรักษาระบบ Data Center (ตัวอย่าง)',
                'status' => Contract::STATUS_ACTIVE, 'starts_on' => today()->startOfMonth()->toDateString(),
                'ends_on' => today()->startOfMonth()->addYear()->subDay()->toDateString(), 'service_window' => '24x7', 'notify_days_before' => 60,
            ]);
            // The parts (batteries) join their UPS when the work starts.
            $contract->contractAssets()->createMany(collect($assets)->filter(fn (Asset $asset) => $asset->parent_asset_id === null)
                ->map(fn (Asset $asset) => ['asset_id' => $asset->id])->values()->all());

            $technician = User::query()->where('email', 'tech1@itsol.test')->first()
                ?? User::query()->whereNull('customer_id')->whereHas('roles', fn ($q) => $q->where('name', 'technician'))->orderBy('id')->firstOrFail();
            $admin = User::query()->whereNull('customer_id')->whereHas('roles', fn ($q) => $q->where('name', 'admin_company'))->orderBy('id')->firstOrFail();

            $plan = app(SavePmPlan::class)->handle(null, [
                'contract_id' => $contract->id, 'title' => 'PM Data Center รายเดือน', 'interval_months' => 1, 'assignee_id' => $technician->id,
            ]);
            // Next month's round too, to try on the phone.
            app(SchedulePmVisits::class)->handle($plan, 45);
            $visits = $plan->visits()->orderBy('round')->get();

            $done = $this->work($visits->first(), $technician, $admin);
            $this->command?->info("Templates added: {$added}. Done: {$done->visit_no} ({$done->status}). To try: ".($visits[1]->visit_no ?? '-'));
        });
    }

    /**
     * This month's round: everything fine but a UPS room too warm, a clogged filter, and a sensor
     * that could not be reached.
     */
    private function work(PmVisit $visit, User $technician, User $admin): PmVisit
    {
        return $this->doTheWork($visit, $technician, $admin, function (PmVisitItem $item, Asset $asset, array $answers) {
            if ($asset->name === 'UPS-01') {
                // A reading out of range: the temperature of the UPS room (max 32 °C).
                return ['result' => PmVisitItem::RESULT_FAILED, 'answers' => ['environment_temp' => 34.5] + $answers,
                    'note' => 'อุณหภูมิห้อง UPS สูงกว่าเกณฑ์ (34.5 °C) แนะนำตรวจสอบระบบปรับอากาศห้อง UPS'];
            }
            if ($asset->name === 'Precision Air PAC-02') {
                $failed = collect($item->checklist)->firstWhere('type', 'pass_fail');

                return ['result' => PmVisitItem::RESULT_FAILED, 'answers' => $failed ? [$failed['key'] => 'fail'] + $answers : $answers,
                    'note' => 'แผ่นกรองอากาศอุดตัน เปลี่ยนแผ่นกรองแล้ว นัดตรวจซ้ำรอบหน้า'];
            }
            if ($asset->name === 'Water Leak Detection') {
                return ['result' => PmVisitItem::RESULT_NOT_INSPECTED, 'answers' => [], 'not_inspected_reason' => 'other',
                    'note' => 'พื้นยกถูกปิดล็อกระหว่างติดตั้งตู้ Rack ใหม่ ตรวจรอบหน้า'];
            }

            return ['result' => PmVisitItem::RESULT_PASSED, 'answers' => $answers];
        }, [
            'summary' => 'ตรวจบำรุงรักษาระบบ Data Center ประจำเดือน ระบบไฟฟ้าและ UPS ทำงานปกติ พบอุณหภูมิห้อง UPS สูง และแผ่นกรอง PAC-02 อุดตัน (เปลี่ยนแล้ว)',
            'customer_signer_name' => 'คุณวิชัย ศูนย์ข้อมูล',
            'customer_signer_position' => 'Data Center Manager',
            'satisfaction' => 'excellent',
        ]);
    }
}

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
use App\Modules\Maintenance\Actions\OpenTicketFromPmItem;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmInstrument;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\StandardTemplates;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * One sample work order with every standard PM form (StandardTemplates, the forms in docs/pm-forms),
 * filled in completely: a piece of equipment per form (the PC and Network tables with several rows,
 * a PC with its monitor as a set), every question answered, notes on some questions, the measuring
 * instrument, site photos, office use (travel, OT), a repair ticket for what failed, signatures of
 * the technician, the customer and the approver, satisfaction — approved and published.
 *
 * Each piece gets its form through the plan (pm_plan_assets.template_code): the company's own
 * templates of its categories stay as they are. Not part of DatabaseSeeder; run on the demo data
 * (DemoSeeder) with
 *
 *   php artisan db:seed --class=PmAllFormsDemoSeeder
 *
 * into the company of subdomain PM_DEMO_TENANT (default "itsol"). Runs once per company.
 */
class PmAllFormsDemoSeeder extends Seeder
{
    use PmDemoWork;

    private const CUSTOMER_CODE = 'ALLFORMS';

    /** Category prefix => name (the prefixes the standard forms are made for). */
    private const CATEGORIES = [
        'EDB' => 'ตู้ไฟฟ้า (MDB / PDU)', 'UPS' => 'UPS', 'BAT' => 'แบตเตอรี่ UPS', 'EMS' => 'ระบบ Monitoring',
        'PAC' => 'เครื่องปรับอากาศ Precision', 'WLD' => 'ระบบตรวจจับน้ำรั่ว', 'FSS' => 'ระบบดับเพลิง', 'SD' => 'ระบบตรวจจับควัน (HSSD)',
        'CCTV' => 'กล้องวงจรปิด (NVR)', 'SW' => 'สวิตช์', 'ACS' => 'ระบบควบคุมการเข้าออก', 'CTR' => 'ห้องควบคุม',
        'PC' => 'คอมพิวเตอร์ตั้งโต๊ะ', 'NB' => 'โน้ตบุ๊ก', 'MON' => 'จอภาพ', 'RT' => 'Router', 'FW' => 'Firewall', 'AP' => 'Access Point',
    ];

    /** [form, category prefix, name, brand, model, room, parent (index)] */
    private const EQUIPMENT = [
        ['DC-ELEC', 'EDB', 'ตู้ไฟหลัก MDB-01', 'Schneider', 'Prisma P', 'ห้องไฟฟ้า', null],
        ['DC-UPS', 'UPS', 'UPS-01 60kVA', 'Eaton', '93PM 60kVA', 'ห้อง UPS', null],
        ['DC-BATT', 'BAT', 'แบตเตอรี่ UPS-01', 'CSB', 'HRL12540W', 'ห้อง UPS', 1],
        ['DC-MON', 'EMS', 'Monitoring Packet Power', 'Packet Power', 'EMX', 'ห้อง Server', null],
        ['DC-MON-EAGLEEYE', 'EMS', 'Monitoring Eagle Eye', 'Eagle Eye', 'EE-200', 'ห้อง Server', null],
        ['DC-MON-GW04', 'EMS', 'Monitoring Gateway GW04', 'Packet Power', 'GW04', 'ห้อง Server', null],
        ['DC-MON-GH04', 'EMS', 'Monitoring Hub GH04', 'Packet Power', 'GH04', 'ห้อง Server', null],
        ['DC-MON-CYBERHUB', 'EMS', 'STULZ Cyber Hub', 'STULZ', 'Cyber Hub', 'ห้อง Server', null],
        ['DC-PAC', 'PAC', 'Precision Air PAC-01', 'STULZ', 'CyberAir 3PRO', 'ห้อง Server', null],
        ['DC-WLD', 'WLD', 'Water Leak Detection', 'RLE', 'SeaHawk LD5100', 'ห้อง Server', null],
        ['DC-FSS', 'FSS', 'ระบบดับเพลิง FM-200', 'Kidde', 'FM-200 Panel', 'ห้อง Server', null],
        ['DC-HSSD', 'SD', 'VESDA HSSD', 'Xtralis', 'VLF-500', 'ห้อง Server', null],
        ['DC-CCTV', 'CCTV', 'NVR กล้องวงจรปิด', 'Hikvision', 'DS-7732NI', 'ห้อง Server', null],
        ['NW-SWITCH', 'SW', 'Core Switch', 'Cisco', 'Catalyst 9300', 'ห้อง Server', null],
        ['DC-ACS', 'ACS', 'ระบบควบคุมการเข้าออก', 'ZKTeco', 'InBio 460', 'ห้อง Server', null],
        ['DC-CTRL', 'CTR', 'ห้องควบคุม NOC', '-', '-', 'ห้องควบคุม', null],
        ['PC-SET', 'PC', 'PC ฝ่ายบัญชี 1', 'Dell', 'OptiPlex 7010', 'ฝ่ายบัญชี', null],
        ['PC-SET', 'MON', 'จอ ฝ่ายบัญชี 1', 'Dell', 'P2422H', 'ฝ่ายบัญชี', 16],
        ['PC-SET', 'PC', 'PC ฝ่ายบัญชี 2', 'Dell', 'OptiPlex 7010', 'ฝ่ายบัญชี', null],
        ['PC-SET', 'MON', 'จอ ฝ่ายบัญชี 2', 'Dell', 'P2422H', 'ฝ่ายบัญชี', 18],
        ['PC-SET', 'NB', 'Notebook ผู้จัดการ', 'Lenovo', 'ThinkPad T14', 'ห้องผู้จัดการ', null],
        ['NW-GROUP', 'RT', 'Router อินเทอร์เน็ต', 'MikroTik', 'CCR2004', 'ห้อง Server', null],
        ['NW-GROUP', 'FW', 'Firewall', 'Fortinet', 'FortiGate 100F', 'ห้อง Server', null],
        ['NW-GROUP', 'AP', 'Access Point ชั้น 2', 'Ubiquiti', 'U6 Pro', 'โถงชั้น 2', null],
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
            app(AddStandardChecklists::class)->handle();
            $forms = collect(StandardTemplates::all())->reject(fn (array $form) => str_starts_with($form['code'], 'STD-'))->pluck('code');
            $missing = $forms->diff(PmChecklist::query()->where('status', PmChecklist::STATUS_PUBLISHED)->pluck('code'));
            if ($missing->isNotEmpty()) {
                $this->command?->warn('Not published here: '.$missing->implode(', '));
            }

            $customer = Customer::create(['code' => self::CUSTOMER_CODE, 'name' => 'บริษัท ตัวอย่างครบทุกแบบฟอร์ม จำกัด']);
            $save = app(SaveAsset::class);
            $assets = [];
            foreach (self::EQUIPMENT as $i => [$form, $prefix, $name, $brand, $model, $room, $parent]) {
                $assets[$i] = $save->handle(null, [
                    'category_id' => $categories[$prefix]->id, 'customer_id' => $customer->id, 'status' => Asset::STATUS_IN_USE,
                    'name' => $name, 'brand' => $brand, 'model' => $model, 'serial_number' => sprintf('AF%s%04d', $prefix, 1001 + $i),
                    'property_no' => sprintf('ครุภัณฑ์-%04d', 2001 + $i), 'ip_address' => '10.30.0.'.(11 + $i),
                    'floor' => $room === 'โถงชั้น 2' ? '2' : '1', 'room' => $room, 'location' => 'อาคารสำนักงานใหญ่',
                    'parent_asset_id' => $parent !== null ? $assets[$parent]->id : null,
                ]);
            }

            $contract = app(SaveContract::class)->handle(null, [
                'customer_id' => $customer->id, 'contract_no' => 'MA-ALL-FORMS', 'title' => 'สัญญาบำรุงรักษาระบบ (ตัวอย่างครบทุกแบบฟอร์ม)',
                'status' => Contract::STATUS_ACTIVE, 'starts_on' => today()->startOfMonth()->toDateString(),
                'ends_on' => today()->startOfMonth()->addYear()->subDay()->toDateString(), 'service_window' => '24x7', 'notify_days_before' => 60,
            ]);
            $contract->contractAssets()->createMany(collect($assets)->map(fn (Asset $asset) => ['asset_id' => $asset->id])->values()->all());

            $technician = User::query()->where('email', 'tech1@itsol.test')->first()
                ?? User::query()->whereNull('customer_id')->whereHas('roles', fn ($q) => $q->where('name', 'technician'))->orderBy('id')->firstOrFail();
            $admin = User::query()->whereNull('customer_id')->whereHas('roles', fn ($q) => $q->where('name', 'admin_company'))->orderBy('id')->firstOrFail();

            $plan = app(SavePmPlan::class)->handle(null, [
                'contract_id' => $contract->id, 'title' => 'PM ตัวอย่างครบทุกแบบฟอร์ม', 'interval_months' => 3, 'assignee_id' => $technician->id,
                'asset_ids' => collect($assets)->pluck('id')->all(),
            ]);
            // Each piece its form, whatever template its category has in this company.
            foreach (self::EQUIPMENT as $i => [$form]) {
                PmPlanAsset::query()->where('pm_plan_id', $plan->id)->where('asset_id', $assets[$i]->id)->update(['template_code' => $form]);
            }

            $instrument = PmInstrument::query()->firstOrCreate(['serial_no' => 'FL-376-0001'], [
                'name' => 'Fluke 376 FC Clamp Meter', 'calibration_due_at' => today()->addMonths(8)->toDateString(),
            ]);

            $visit = $this->work($plan->visits()->orderBy('round')->firstOrFail(), $technician, $admin, $instrument);
            $this->command?->info("Done: {$visit->visit_no} ({$visit->status}), {$visit->items()->count()} pieces, ".$visit->items()->distinct()->count('template_code').' forms');
        });
    }

    private function work(PmVisit $visit, User $technician, User $admin, PmInstrument $instrument): PmVisit
    {
        $measured = fn (PmVisitItem $item) => collect($item->checklist)->contains(fn ($field) => in_array($field['type'], ['number', 'measure', 'measure_table'], true));
        $tickets = app(Modules::class)->enabled('service');

        return $this->doTheWork($visit, $technician, $admin, function (PmVisitItem $item, Asset $asset, array $answers) use ($measured, $instrument) {
            $data = ['result' => PmVisitItem::RESULT_PASSED, 'answers' => $answers, 'instrument_id' => $measured($item) ? $instrument->id : null];
            $passFail = collect($item->checklist)->where('type', 'pass_fail')->pluck('key')->values();

            // A note on the first question of every per-asset form.
            if ($passFail->isNotEmpty() && ! in_array($item->template_code, ['PC-SET', 'NW-GROUP'], true)) {
                $data['remarks'] = [$passFail[0] => 'ตรวจด้วยสายตาและทดสอบการทำงานแล้ว'];
            }

            return match ($asset->name) {
                'VESDA HSSD' => ['result' => PmVisitItem::RESULT_FAILED, 'answers' => [$passFail[1] ?? $passFail[0] => 'fail'] + $answers,
                    'note' => 'ท่อดูดอากาศจุดที่ 3 อุดตันบางส่วน ทำความสะอาดแล้ว แนะนำเปลี่ยนไส้กรองรอบหน้า'] + $data,
                'NVR กล้องวงจรปิด' => ['result' => PmVisitItem::RESULT_FAILED, 'answers' => [$passFail[0] ?? 'status' => 'fail'] + $answers,
                    'note' => 'กล้องช่องที่ 7 ไม่มีภาพ สายสัญญาณขาด เปิดใบแจ้งซ่อมแล้ว'] + $data,
                'Access Point ชั้น 2' => ['result' => PmVisitItem::RESULT_NOT_INSPECTED, 'answers' => [], 'not_inspected_reason' => 'sent_for_repair',
                    'note' => 'ส่งเคลมกับผู้จำหน่ายตั้งแต่สัปดาห์ก่อน', 'instrument_id' => null],
                'PC ฝ่ายบัญชี 2' => ['result' => PmVisitItem::RESULT_NOT_INSPECTED, 'answers' => [], 'not_inspected_reason' => 'user_absent',
                    'note' => 'ผู้ใช้ลาพักร้อน เครื่องล็อกไว้', 'instrument_id' => null],
                default => $data,
            };
        }, [
            'summary' => "ตรวจบำรุงรักษาตามแผนครบทุกระบบ: ไฟฟ้า, UPS และแบตเตอรี่, Monitoring, Precision Air, ระบบดับเพลิงและตรวจจับ, CCTV, เครือข่าย และคอมพิวเตอร์\nพบท่อดูดอากาศ HSSD อุดตันบางส่วน (ทำความสะอาดแล้ว) และกล้อง CCTV ช่องที่ 7 ไม่มีภาพ (เปิดใบแจ้งซ่อมแล้ว)",
            'customer_signer_name' => 'คุณสมศรี ใจดี',
            'customer_signer_position' => 'IT Manager',
            'customer_signer_phone' => '081-234-5678',
            'customer_signer_email' => 'somsri@example.com',
            'satisfaction' => 'good',
            'satisfaction_comment' => 'ช่างตรงเวลา อธิบายผลการตรวจชัดเจน',
        ], function (PmVisitItem $item, Asset $asset) use ($technician, $tickets) {
            if (in_array($item->template_code, ['PC-SET', 'NW-GROUP'], true) || $item->result === PmVisitItem::RESULT_NOT_INSPECTED) {
                return;
            }
            // Site photos: the equipment, and its first question.
            $first = collect($item->checklist)->firstWhere('type', 'pass_fail');
            $this->attachPhoto($item, $asset->asset_code.' '.$asset->model, $item->id, null, 'ภาพรวมอุปกรณ์หลังทำความสะอาด');
            if ($first) {
                $this->attachPhoto($item, $asset->asset_code.' - '.$first['key'], $item->id + 1, $first['key']);
            }
            if ($item->result === PmVisitItem::RESULT_FAILED && $tickets) {
                app(OpenTicketFromPmItem::class)->handle($item, $technician, 'high');
            }
        }, function (PmVisit $visit) {
            // Office use: travel and overtime.
            $visit->update(['office_data' => ['travel' => 'รถบริษัท ไป-กลับ 46 กม.', 'ot_from' => '17:30', 'ot_to' => '19:00']]);
        });
    }
}

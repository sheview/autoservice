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
use App\Modules\Maintenance\Actions\MarkPmVisitArrival;
use App\Modules\Maintenance\Actions\MarkPmVisitDeparture;
use App\Modules\Maintenance\Actions\MovePmVisit;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\SchedulePmVisits;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Actions\SubmitPmVisit;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

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

            $done = $this->doTheWork($visits->first(), $technician, $admin);
            $this->command?->info("Templates added: {$added}. Done: {$done->visit_no} ({$done->status}). To try: ".($visits[1]->visit_no ?? '-'));
        });
    }

    /**
     * The round of this month, from arrival to published, as the technician, the customer and the
     * admin would do it.
     */
    private function doTheWork(PmVisit $visit, User $technician, User $admin): PmVisit
    {
        $visit = app(StartPmVisit::class)->handle($visit, $technician);
        app(MarkPmVisitArrival::class)->handle($visit->fresh(), $technician);

        mt_srand(2569);
        $record = app(RecordPmItem::class);
        foreach ($visit->items()->with('visit')->orderBy('id')->get() as $item) {
            $asset = Asset::query()->find($item->asset_id);
            $answers = $this->answers($item->checklist ?? [], $asset);
            $data = ['result' => PmVisitItem::RESULT_PASSED, 'answers' => $answers];

            if ($asset->name === 'UPS-01') {
                // A reading out of range: the temperature of the UPS room (max 32 °C).
                $data['answers']['environment_temp'] = 34.5;
                $data = ['result' => PmVisitItem::RESULT_FAILED, 'answers' => $data['answers'],
                    'note' => 'อุณหภูมิห้อง UPS สูงกว่าเกณฑ์ (34.5 °C) แนะนำตรวจสอบระบบปรับอากาศห้อง UPS'];
            } elseif ($asset->name === 'Precision Air PAC-02') {
                $failed = collect($item->checklist)->firstWhere('type', 'pass_fail');
                if ($failed) {
                    $data['answers'][$failed['key']] = 'fail';
                }
                $data = ['result' => PmVisitItem::RESULT_FAILED, 'answers' => $data['answers'],
                    'note' => 'แผ่นกรองอากาศอุดตัน เปลี่ยนแผ่นกรองแล้ว นัดตรวจซ้ำรอบหน้า'];
            } elseif ($asset->name === 'Water Leak Detection') {
                $data = ['result' => PmVisitItem::RESULT_NOT_INSPECTED, 'answers' => [], 'not_inspected_reason' => 'other',
                    'note' => 'พื้นยกถูกปิดล็อกระหว่างติดตั้งตู้ Rack ใหม่ ตรวจรอบหน้า'];
            }

            try {
                $record->handle($item, $technician, $data);
            } catch (ValidationException $e) {
                $this->command?->warn("{$asset->name}: ".collect($e->errors())->flatten()->first());
            }
        }

        app(MarkPmVisitDeparture::class)->handle($visit->fresh(), $technician);
        $signature = $this->signature();
        $visit = app(SubmitPmVisit::class)->handle($visit->fresh(), $technician, [
            'summary' => 'ตรวจบำรุงรักษาระบบ Data Center ประจำเดือน ระบบไฟฟ้าและ UPS ทำงานปกติ พบอุณหภูมิห้อง UPS สูง และแผ่นกรอง PAC-02 อุดตัน (เปลี่ยนแล้ว)',
            'technician_signature' => $signature,
            'customer_signature' => $this->signature(7),
            'customer_signer_name' => 'คุณวิชัย ศูนย์ข้อมูล',
            'customer_signer_position' => 'Data Center Manager',
            'satisfaction' => 'excellent',
        ]);

        // Through the company's own steps: review, approve (signed), publish.
        $move = app(MovePmVisit::class);
        foreach (['submitted' => 'review', 'pending_approval' => 'approve', 'approved' => 'publish'] as $status => $action) {
            $visit = $visit->fresh();
            if ($visit->status === $status) {
                $visit = $move->handle($visit, $action, $admin, null, $action === 'approve' ? $this->signature(13) : null);
            }
        }

        return $visit->fresh();
    }

    /**
     * Answers that pass every question of a checklist: readings in the middle of their range,
     * Pass for Pass/Fail, the first option of a choice, tables filled row by row.
     *
     * @return array<string, mixed>
     */
    private function answers(array $checklist, Asset $asset): array
    {
        $answers = [];
        foreach ($checklist as $field) {
            $answers[$field['key']] = match ($field['type']) {
                'pass_fail' => 'pass',
                'check' => true,
                'number', 'measure' => $this->reading($field['min'] ?? null, $field['max'] ?? null, $field['unit'] ?? null, $field['key']),
                'select' => $field['options'][0] ?? null,
                'checkbox_group' => array_slice($field['options'] ?? [], 0, 1),
                'measure_table' => collect($field['rows'] ?? [])->mapWithKeys(fn (array $row) => [$row['key'] => collect($field['columns'] ?? [])
                    ->mapWithKeys(function (array $column) use ($row) {
                        [$min, $max] = ChecklistItems::cellRange($row, $column);

                        return [$column['key'] => $this->reading($min, $max, $row['unit'] ?? null, $row['key'])];
                    })->all()])->all(),
                'repeat_table' => collect(range(1, max(1, ChecklistItems::repeatRowCount($field, $answers))))
                    ->map(fn () => collect($field['columns'] ?? [])->mapWithKeys(fn (array $column) => [$column['key'] => match ($column['type']) {
                        'number' => $this->reading($column['min'] ?? null, $column['max'] ?? null, $column['unit'] ?? null, $column['key']),
                        'pass_fail' => 'pass',
                        default => 'ปกติ',
                    }])->all())->all(),
                'text', 'textarea' => match (true) {
                    str_contains($field['key'], 'brand') => $asset->brand,
                    str_contains($field['key'], 'model') => $asset->model,
                    str_contains($field['key'], 'ip') => $asset->ip_address ?? '-',
                    str_contains($field['key'], 'number') || str_contains($field['key'], 'name') => $asset->name,
                    default => 'ปกติ',
                },
                default => null, // photos, values from the asset register
            };
        }

        return $answers;
    }

    /** A plausible reading: inside its range, else by its unit or name. */
    private function reading(mixed $min, mixed $max, ?string $unit, string $key): float|int
    {
        if ($min !== null && $max !== null) {
            return round($min + ($max - $min) * mt_rand(35, 65) / 100, 1);
        }
        if ($max !== null) {
            return round($max * mt_rand(60, 85) / 100, 1);
        }
        if ($min !== null) {
            return round($min * mt_rand(105, 130) / 100, 1);
        }

        return match (true) {
            $unit === 'V' => mt_rand(2280, 2330) / 10,
            $unit === 'A' => mt_rand(180, 420) / 10,
            $unit === 'Hz' => 50,
            $unit === '%' => mt_rand(30, 55),
            in_array($unit, ['°C', 'C'], true) => mt_rand(210, 240) / 10,
            str_contains($key, 'capacity') => 60,
            str_contains($key, 'block') => 32,
            str_contains($key, 'string') => 2,
            str_contains($key, 'ah') => 100,
            str_contains($key, 'volt') => 12,
            str_contains($key, 'time') => 15,
            default => mt_rand(1, 6),
        };
    }

    /** A hand-drawn-looking signature (PNG, data URL), a different stroke per seed. */
    private function signature(int $seed = 3): string
    {
        $image = imagecreatetruecolor(360, 120);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagesetthickness($image, 3);
        $ink = imagecolorallocate($image, 20, 40, 110);
        $previous = null;
        for ($x = 20; $x <= 340; $x += 4) {
            $y = (int) (60 + 28 * sin(($x + $seed * 17) / (18 + $seed)) * cos($x / 47) + 10 * sin($x / 9));
            if ($previous) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }
            $previous = [$x, $y];
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}

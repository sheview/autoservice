<?php

namespace Database\Seeders;

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Contract\Actions\AddContractAssets;
use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Contract\Actions\SaveContract;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Actions\IssuePartToTicket;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Actions\SavePart;
use App\Modules\Maintenance\Actions\CompletePmVisit;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\CheckTicketWarranty;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Actions\OpenTicket;
use App\Modules\Service\Actions\SaveRepairReport;
use App\Modules\Service\Jobs\SendTicketNotification;
use App\Modules\Service\Models\Holiday;
use App\Modules\Service\Models\Ticket;
use App\Modules\Survey\Actions\AnswerTicketSurvey;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * Demo data for local development (never in production). Every password is "password".
 *
 *   admin@platform.test            superadmin (platform tenant)
 *   helpdesk@platform.test         central helpdesk: enters any company, works there, configures nothing
 *   tech@platform.test             central technician: the same, with technician permissions
 *   {role}@{subdomain}.test        admin, helpdesk, tech1, tech2, user in each customer tenant
 *   customer@{subdomain}.test      customer account of the first customer (CUST001)
 *
 * Run on an empty database: php artisan migrate:fresh --database=pgsql_migrate --seed
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    private const TENANTS = [
        'itsol' => 'ไอทีโซลูชั่น จำกัด',
        'netpro' => 'เน็ตเวิร์คโปร จำกัด',
    ];

    private const BRANCHES = [
        ['code' => 'BKK', 'name' => 'สำนักงานใหญ่ กรุงเทพ', 'province' => 'กรุงเทพมหานคร'],
        ['code' => 'CNX', 'name' => 'สาขาเชียงใหม่', 'province' => 'เชียงใหม่'],
        ['code' => 'KKC', 'name' => 'สาขาขอนแก่น', 'province' => 'ขอนแก่น'],
    ];

    /** Customers of each MA company (codes CUST001...). */
    private const CUSTOMERS = [
        ['name' => 'บริษัท สยามค้าปลีก จำกัด (มหาชน)', 'tax_id' => '0107551000011'],
        ['name' => 'โรงพยาบาลเมืองใหม่', 'tax_id' => '0994000123456'],
        ['name' => 'บริษัท ขนส่งด่วนไทย จำกัด', 'tax_id' => '0105560123457'],
        ['name' => 'มหาวิทยาลัยเทคโนโลยีภาคเหนือ', 'tax_id' => '0994000654321'],
    ];

    private const CATEGORIES = [
        [
            'name' => 'คอมพิวเตอร์ตั้งโต๊ะ', 'code_prefix' => 'PC', 'service_line' => 'pc',
            'brands' => ['Dell OptiPlex 7010', 'HP ProDesk 400', 'Lenovo ThinkCentre M70'],
            'spec_fields' => [
                ['key' => 'cpu', 'label' => 'CPU', 'type' => 'text', 'options' => [], 'required' => true],
                ['key' => 'ram_gb', 'label' => 'RAM (GB)', 'type' => 'number', 'options' => [], 'required' => false],
                ['key' => 'os', 'label' => 'ระบบปฏิบัติการ', 'type' => 'select', 'options' => ['Windows 10', 'Windows 11', 'Ubuntu'], 'required' => false],
            ],
        ],
        [
            'name' => 'โน้ตบุ๊ก', 'code_prefix' => 'NB', 'service_line' => 'pc',
            'brands' => ['Lenovo ThinkPad E14', 'Dell Latitude 5440', 'ASUS ExpertBook B1'],
            'spec_fields' => [
                ['key' => 'cpu', 'label' => 'CPU', 'type' => 'text', 'options' => [], 'required' => true],
                ['key' => 'ram_gb', 'label' => 'RAM (GB)', 'type' => 'number', 'options' => [], 'required' => false],
            ],
        ],
        [
            'name' => 'สวิตช์', 'code_prefix' => 'SW', 'service_line' => 'network',
            'brands' => ['Cisco Catalyst 9200', 'Aruba 2930F', 'HPE 1920S'],
            'spec_fields' => [
                ['key' => 'ports', 'label' => 'จำนวนพอร์ต', 'type' => 'number', 'options' => [], 'required' => true],
                ['key' => 'layer', 'label' => 'Layer', 'type' => 'select', 'options' => ['L2', 'L3'], 'required' => false],
                ['key' => 'ip', 'label' => 'IP Address', 'type' => 'text', 'options' => [], 'required' => false],
            ],
        ],
        [
            'name' => 'เซิร์ฟเวอร์', 'code_prefix' => 'SV', 'service_line' => 'datacenter',
            'brands' => ['Dell PowerEdge R650', 'HPE ProLiant DL380', 'Lenovo ThinkSystem SR630'],
            'spec_fields' => [
                ['key' => 'cpu', 'label' => 'CPU', 'type' => 'text', 'options' => [], 'required' => true],
                ['key' => 'ram_gb', 'label' => 'RAM (GB)', 'type' => 'number', 'options' => [], 'required' => false],
                ['key' => 'rack', 'label' => 'ตำแหน่ง Rack', 'type' => 'text', 'options' => [], 'required' => false],
            ],
        ],
        [
            'name' => 'เครื่องพิมพ์', 'code_prefix' => 'PR', 'service_line' => 'pc',
            'brands' => ['HP LaserJet Pro M404', 'Brother HL-L2375', 'Epson L6270'],
            'spec_fields' => [],
        ],
        [
            'name' => 'อุปกรณ์สำนักงาน', 'code_prefix' => 'OE', 'service_line' => 'pc',
            'brands' => ['Canon imageRUNNER 2630', 'Epson EB-X51', 'APC Back-UPS 1100', 'Fujitsu fi-7160', 'Yealink T31P'],
            'spec_fields' => [
                ['key' => 'equipment_type', 'label' => 'ประเภทอุปกรณ์', 'type' => 'select', 'options' => ['เครื่องถ่ายเอกสาร', 'โปรเจกเตอร์', 'สแกนเนอร์', 'เครื่องสำรองไฟ (UPS)', 'โทรศัพท์ IP', 'อื่น ๆ'], 'required' => true],
            ],
        ],
        [
            'name' => 'ซอฟต์แวร์ลิขสิทธิ์', 'code_prefix' => 'LIC', 'service_line' => 'pc', 'asset_type' => 'software',
            'brands' => ['Microsoft 365 Business Standard', 'Windows Server 2022 Standard', 'Veeam Backup & Replication', 'Kaspersky Endpoint Security'],
            'spec_fields' => [
                ['key' => 'seats', 'label' => 'จำนวนสิทธิ์ใช้งาน', 'type' => 'number', 'options' => [], 'required' => true],
                ['key' => 'license_key', 'label' => 'License Key', 'type' => 'text', 'options' => [], 'required' => false],
            ],
        ],
    ];

    /** Thai public holidays 2026 (demo data: check against the official announcement). */
    private const HOLIDAYS = [
        '2026-01-01' => 'วันขึ้นปีใหม่',
        '2026-03-03' => 'วันมาฆบูชา',
        '2026-04-06' => 'วันจักรี',
        '2026-04-13' => 'วันสงกรานต์',
        '2026-04-14' => 'วันสงกรานต์',
        '2026-04-15' => 'วันสงกรานต์',
        '2026-05-01' => 'วันแรงงานแห่งชาติ',
        '2026-05-04' => 'วันฉัตรมงคล',
        '2026-06-01' => 'ชดเชยวันวิสาขบูชา',
        '2026-06-03' => 'วันเฉลิมพระชนมพรรษาสมเด็จพระราชินี',
        '2026-07-28' => 'วันเฉลิมพระชนมพรรษา ร.10',
        '2026-07-29' => 'วันอาสาฬหบูชา',
        '2026-08-12' => 'วันแม่แห่งชาติ',
        '2026-10-13' => 'วันนวมินทรมหาราช',
        '2026-10-23' => 'วันปิยมหาราช',
        '2026-12-07' => 'ชดเชยวันพ่อแห่งชาติ',
        '2026-12-10' => 'วันรัฐธรรมนูญ',
        '2026-12-31' => 'วันสิ้นปี',
    ];

    private const PROBLEMS = [
        'เครื่องเปิดไม่ติด', 'เชื่อมต่ออินเทอร์เน็ตไม่ได้', 'พอร์ตสวิตช์ไม่ทำงาน', 'เครื่องพิมพ์กระดาษติด',
        'ระบบช้าผิดปกติ', 'ฮาร์ดดิสก์มีเสียงดัง', 'จอภาพไม่แสดงผล', 'ไฟแจ้งเตือนสีส้มที่เซิร์ฟเวอร์',
    ];

    /** PM checklists: category code_prefix (null = general) => [name, [key, label, type]...] */
    private const CHECKLISTS = [
        null => ['PM ทั่วไป', [['look', 'ตรวจสภาพภายนอก', 'check'], ['clean', 'ทำความสะอาด', 'check'], ['remark', 'ข้อสังเกต', 'text']]],
        'PC' => ['PM คอมพิวเตอร์', [['clean', 'เป่าฝุ่นภายในเครื่อง', 'check'], ['update', 'อัปเดต Windows / Antivirus', 'check'], ['disk_free_gb', 'พื้นที่ดิสก์คงเหลือ (GB)', 'number']]],
        'SW' => ['PM สวิตช์', [['fan', 'ตรวจพัดลมและอุณหภูมิ', 'check'], ['log', 'ตรวจ log error', 'check'], ['firmware', 'เวอร์ชัน firmware', 'text']]],
        'SV' => ['PM เซิร์ฟเวอร์', [['raid', 'สถานะ RAID ปกติ', 'check'], ['psu', 'Power supply ทั้งสองชุดทำงาน', 'check'], ['temp_c', 'อุณหภูมิ (°C)', 'number']]],
    ];

    /** Spare parts: [code, name, brand, part number, unit, reorder point, cost in baht, pieces received]. */
    private const PARTS = [
        ['RAM-DDR4-8G', 'RAM DDR4 8GB 2666', 'Kingston', 'KVR26N19S8/8', 'ชิ้น', 4, 890, 12],
        ['RAM-DDR4-16G', 'RAM DDR4 16GB 3200', 'Kingston', 'KVR32N22S8/16', 'ชิ้น', 2, 1590, 3],
        ['SSD-SATA-500', 'SSD SATA 500GB', 'WD', 'WDS500G3B0A', 'ชิ้น', 3, 1490, 8],
        ['HDD-SAS-1T2', 'HDD SAS 1.2TB 10K', 'Dell', '400-ATJL', 'ชิ้น', 2, 7900, 2],
        ['PSU-ATX-550', 'Power supply ATX 550W', 'Corsair', 'CV550', 'ชิ้น', 2, 1690, 5],
        ['FAN-SW-C9200', 'พัดลมสวิตช์ Catalyst 9200', 'Cisco', 'C9200-FAN', 'ชิ้น', 1, 4200, 0],
        ['SFP-10G-SR', 'SFP+ 10G SR module', 'Cisco', 'SFP-10G-SR', 'ชิ้น', 4, 2900, 10],
        ['CAB-UTP-C6-3M', 'สาย UTP Cat6 3 เมตร', 'Link', 'US-5103', 'เส้น', 20, 65, 60],
        ['TONER-HP-59A', 'ตลับหมึก HP 59A', 'HP', 'CF259A', 'กล่อง', 3, 3450, 4],
        ['THERMAL-PASTE', 'ซิลิโคนระบายความร้อน', 'Arctic', 'MX-4', 'หลอด', 2, 250, 6],
    ];

    public function __construct(
        private TenantContext $context,
        private SaveAsset $saveAsset,
        private SaveContract $saveContract,
        private AddContractAssets $addContractAssets,
        private CoveringContracts $coveringContracts,
        private OpenTicket $openTicket,
        private AssignTicket $assignTicket,
        private MoveTicket $moveTicket,
        private SavePmPlan $savePmPlan,
        private StartPmVisit $startPmVisit,
        private RecordPmItem $recordPmItem,
        private CompletePmVisit $completePmVisit,
        private SavePart $savePart,
        private RecordStockMovement $recordStockMovement,
        private IssuePartToTicket $issuePartToTicket,
        private AnswerTicketSurvey $answerTicketSurvey,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder does not run in production.');

            return;
        }

        fake()->seed(2026);

        $platform = Tenant::create(['name' => 'Governix - ITSM Platform', 'slug' => 'platform', 'subdomain' => 'admin', 'is_platform' => true]);
        $this->context->run($platform, function () {
            $this->user('admin@platform.test', 'ผู้ดูแลแพลตฟอร์ม', PermissionCatalog::SUPERADMIN);
            // Central staff: enter any company and work there, but configure nothing.
            $this->user('helpdesk@platform.test', $this->name(), PermissionCatalog::CENTRAL_HELPDESK, ['position' => 'Helpdesk ส่วนกลาง']);
            $this->user('tech@platform.test', $this->name(), PermissionCatalog::CENTRAL_TECHNICIAN, ['position' => 'ช่างส่วนกลาง']);
        });

        foreach (self::TENANTS as $subdomain => $name) {
            $tenant = Tenant::create(['name' => $name, 'slug' => $subdomain, 'subdomain' => $subdomain]);
            $this->context->run($tenant, fn () => $this->seedTenant($subdomain));
        }

        $this->command?->info('Demo data ready. Log in with e.g. admin@itsol.test / '.self::PASSWORD);
    }

    private function seedTenant(string $subdomain): void
    {
        $branches = collect(self::BRANCHES)->map(fn (array $branch) => Branch::create($branch));

        $admin = $this->user("admin@{$subdomain}.test", $this->name(), 'admin_company');
        // Helpdesk works in its own branch (head office); only the company admin sees every branch.
        $helpdesk = $this->user("helpdesk@{$subdomain}.test", $this->name(), 'helpdesk', [
            'branch_id' => $branches[0]->id, 'position' => 'เจ้าหน้าที่ Helpdesk',
        ]);
        $technicians = collect([
            $this->user("tech1@{$subdomain}.test", $this->name(), 'technician', [
                'branch_id' => $branches[0]->id, 'position' => 'ช่างเทคนิค', 'service_lines' => ['network', 'datacenter'],
            ]),
            $this->user("tech2@{$subdomain}.test", $this->name(), 'technician', [
                'branch_id' => $branches[1]->id, 'position' => 'ช่างเทคนิค', 'service_lines' => ['pc'],
            ]),
        ]);
        $this->user("user@{$subdomain}.test", $this->name(), 'user', ['branch_id' => $branches[0]->id]);

        $customers = collect(self::CUSTOMERS)->map(fn (array $customer, int $i) => Customer::create($customer + [
            'code' => sprintf('CUST%03d', $i + 1),
            'contact_name' => $this->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
        ]));

        // A customer account of the first customer: sees only that customer's tickets and assets.
        $this->user("customer@{$subdomain}.test", $this->name(), PermissionCatalog::CUSTOMER_ROLE, [
            'customer_id' => $customers->first()->id, 'position' => 'เจ้าหน้าที่ไอทีของลูกค้า',
        ]);

        foreach (self::CATEGORIES as $definition) {
            $category = AssetCategory::create(collect($definition)->except('brands')->all());

            foreach (range(1, fake()->numberBetween(6, 12)) as $i) {
                // Most assets belong to a customer; the rest are the MA company's own.
                $customer = fake()->boolean(85) ? $customers->random() : null;
                $this->asset($category, $definition['brands'], $branches->random(), $customer);
            }
        }

        foreach ($customers as $i => $customer) {
            // The first customer's contract is about to expire, so the expiry e-mail has something to send.
            $this->contracts($customer, $i === 0 ? 30 : fake()->numberBetween(90, 330));
        }

        foreach (self::HOLIDAYS as $date => $name) {
            Holiday::create(['date' => $date, 'name' => $name]);
        }

        $this->tickets($admin, $helpdesk, $technicians);
        $this->maintenance($technicians);
        $this->inventory($admin);
        $this->surveys();
    }

    /**
     * Closing a ticket created its survey (MoveTicket). Most are answered through the public
     * link a day later; the newest one is still waiting.
     */
    private function surveys(): void
    {
        $answers = [
            [5, 'ช่างมาเร็วและอธิบายปัญหาชัดเจน'],
            [4, 'แก้ไขได้เรียบร้อย แต่รออะไหล่นานไปหน่อย'],
            [2, 'ต้องแจ้งซ้ำสองครั้งกว่าจะมีคนติดต่อกลับ'],
            [5, null],
        ];

        foreach (TicketSurvey::orderBy('id')->get()->slice(0, -1)->values() as $i => $survey) {
            [$score, $comment] = $answers[$i % count($answers)];
            Carbon::setTestNow($survey->created_at->copy()->addDay());
            $this->answerTicketSurvey->handle($survey, ['score' => $score, 'comment' => $comment, 'name' => $this->name()]);
        }

        Carbon::setTestNow();
    }

    /**
     * Spare parts received two weeks ago (some at or below their reorder point, one never in
     * stock), and a part or two used on every ticket that a technician has worked on.
     */
    private function inventory(User $admin): void
    {
        $realNow = now()->toImmutable();
        Carbon::setTestNow($realNow->subDays(14)->setTime(10, 0));

        $parts = collect(self::PARTS)->map(function (array $row) use ($admin) {
            [$code, $name, $brand, $partNumber, $unit, $minQty, $baht, $received] = $row;
            $part = $this->savePart->handle(null, [
                'code' => $code, 'name' => $name, 'brand' => $brand, 'part_number' => $partNumber,
                'unit' => $unit, 'min_qty' => $minQty, 'unit_cost' => $baht * 100, // satang
            ]);
            if ($received > 0) {
                $this->recordStockMovement->handle($part, 'receive', $received, $admin, [
                    'unit_cost' => $baht * 100,
                    'reference' => 'DN-'.fake()->numerify('69####'),
                ]);
            }

            return $part;
        });

        $worked = Ticket::whereIn('status', [Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            // Oldest first: the ledger's running balance must follow the dates (the demo tickets
            // were not created in date order).
            ->whereNotNull('assignee_id')->orderBy('created_at')->orderBy('id')->get();

        foreach ($worked as $ticket) {
            $technician = User::find($ticket->assignee_id);
            Carbon::setTestNow($ticket->created_at->copy()->addHours(3));

            foreach ($parts->filter(fn ($part) => $part->qty_on_hand > 1)->random(fake()->numberBetween(1, 2)) as $part) {
                $this->issuePartToTicket->handle($ticket->id, $part->id, 1, $technician);
                $part->refresh();
            }
        }

        Carbon::setTestNow();
    }

    /**
     * Tickets of the last ten days in every status, and finished ones spread over the last two
     * years. Each one is opened "back then" (so SLA due
     * times and breaches look real) and moved along the workflow by the people who would do it.
     *
     * @param  Collection<int, User>  $technicians
     */
    private function tickets(User $admin, User $helpdesk, Collection $technicians): void
    {
        $assets = Asset::whereNotNull('customer_id')->inRandomOrder()->limit(14)->get();
        $plans = [
            // [moves after opening, days ago]
            [[], 0], [[], 1],
            [['assign'], 0], [['assign'], 2],
            [['assign', 'start'], 1], [['assign', 'start'], 4],
            [['assign', 'start', 'hold'], 3],
            [['assign', 'start', 'resolve'], 2],
            [['assign', 'start', 'resolve', 'approve'], 5], [['assign', 'start', 'resolve', 'approve'], 8],
            [['assign', 'start', 'resolve', 'approve'], 9],
            [['cancel'], 6],
            // Older, finished work, so the home page charts have months and years to show.
            ...array_map(fn (int $daysAgo) => [['assign', 'start', 'resolve', 'approve'], $daysAgo], [24, 41, 58, 77, 103, 131, 165, 198, 240, 290, 350, 420, 510, 640]),
        ];

        // Demo tickets must not e-mail anyone when the queue worker starts.
        Bus::fake([SendTicketNotification::class]);

        // The real time: setTestNow below moves the clock for each ticket.
        $realNow = now()->toImmutable();

        foreach ($plans as $i => [$moves, $daysAgo]) {
            $asset = $assets[$i % $assets->count()];
            $technician = $technicians->random();
            // Today's tickets 4 hours ago, so no step lands in the future.
            $opened = $daysAgo === 0
                ? $realNow->subHours(4)
                : $realNow->subDays($daysAgo)->setTime(fake()->numberBetween(8, 16), fake()->randomElement([0, 15, 30, 45]));
            Carbon::setTestNow($opened);

            $contract = $this->coveringContracts->handle($asset->customer_id, $asset->id)[0] ?? null;
            $ticket = $this->openTicket->handle($helpdesk, [
                'customer_id' => $asset->customer_id,
                'asset_id' => $asset->id,
                'contract_id' => $contract['id'] ?? null,
                'title' => fake()->randomElement(self::PROBLEMS)." ({$asset->asset_code})",
                'description' => 'ลูกค้าแจ้งทางโทรศัพท์ ต้องการให้ช่างเข้าตรวจสอบ',
                'priority' => fake()->randomElement(['critical', 'high', 'high', 'medium', 'medium', 'low']),
                'source' => fake()->randomElement(Ticket::SOURCES),
                'contact_name' => $this->name(),
                'contact_phone' => fake()->phoneNumber(),
            ]);

            foreach ($moves as $move) {
                Carbon::setTestNow(now()->addMinutes(fake()->numberBetween(20, 180)));
                match ($move) {
                    'assign' => $this->assignTicket->handle($ticket, $technician->id, $helpdesk),
                    // Starting needs the warranty checked, resolving needs the repair report.
                    'start' => $this->moveTicket->handle(
                        app(CheckTicketWarranty::class)->handle($ticket, $technician, fake()->randomElement(Ticket::WARRANTY_STATUSES), null),
                        'start', $technician),
                    'resolve' => $this->moveTicket->handle(
                        app(SaveRepairReport::class)->handle($ticket, $technician, [
                            'cause' => fake()->randomElement(['สายสัญญาณหลวม', 'อุปกรณ์เสื่อมตามอายุ', 'ตั้งค่าผิด', 'ไฟกระชาก']),
                            'extra_cost' => null,
                            'approver_name' => $this->name(),
                        ]),
                        'resolve', $technician),
                    'hold' => $this->moveTicket->handle($ticket, 'hold', $technician, 'รออะไหล่จากผู้จำหน่าย'),
                    'approve' => $this->moveTicket->handle($ticket, 'approve', $admin),
                    'cancel' => $this->moveTicket->handle($ticket, 'cancel', $helpdesk, 'ลูกค้าแจ้งซ้ำกับใบงานเดิม'),
                };
            }
        }

        Carbon::setTestNow();
    }

    /**
     * PM checklists, and a plan for each running contract. Rounds that are already due were
     * done "back then" (a few found issues); the current round is half done.
     *
     * @param  Collection<int, User>  $technicians
     */
    private function maintenance(Collection $technicians): void
    {
        $categories = AssetCategory::pluck('id', 'code_prefix');
        foreach (self::CHECKLISTS as $prefix => [$name, $items]) {
            PmChecklist::create([
                'name' => $name,
                'asset_category_id' => $prefix === '' ? null : $categories[$prefix] ?? null,
                'items' => array_map(fn (array $item) => ['key' => $item[0], 'label' => $item[1], 'type' => $item[2]], $items),
            ]);
        }

        $realNow = now()->toImmutable();
        $contracts = Contract::where('status', Contract::STATUS_ACTIVE)->where('ends_on', '>=', $realNow->toDateString())->get();

        foreach ($contracts as $contract) {
            $technician = $technicians->random();
            // Planned on the first day of the contract, so every round exists.
            Carbon::setTestNow($contract->starts_on->copy()->setTime(9, 0));
            $plan = $this->savePmPlan->handle(null, [
                'contract_id' => $contract->id,
                'title' => 'PM '.$contract->title,
                'interval_months' => $contract->pm_interval_months ?? 6,
                'assignee_id' => $technician->id,
            ]);

            foreach ($plan->visits()->orderBy('round')->get() as $visit) {
                $current = $visit->period_starts_on->lte($realNow) && $visit->due_on->gte($realNow->startOfDay());
                if (! $current && $visit->due_on->gte($realNow)) {
                    break;
                }

                $day = $current ? $realNow->subDay() : $visit->due_on->copy()->subDays(fake()->numberBetween(3, 20));
                Carbon::setTestNow($day->setTime(9, 30));
                $this->startPmVisit->handle($visit, $technician);

                foreach ($visit->items()->get() as $i => $item) {
                    if ($current && $i % 2 === 1) {
                        continue;
                    }
                    Carbon::setTestNow(now()->addMinutes(fake()->numberBetween(10, 40)));
                    $issue = fake()->boolean(10);
                    $this->recordPmItem->handle($item, $technician, [
                        'result' => $issue ? PmVisitItem::RESULT_ISSUE : PmVisitItem::RESULT_OK,
                        'answers' => collect($item->checklist)->mapWithKeys(fn (array $field) => [$field['key'] => match ($field['type']) {
                            'check' => true,
                            'number' => fake()->numberBetween(20, 60),
                            default => 'ปกติ',
                        }])->all(),
                        'note' => $issue ? fake()->randomElement(self::PROBLEMS) : null,
                    ]);
                }

                if (! $current) {
                    $this->completePmVisit->handle($visit, 'ทำ PM ครบทุกเครื่องตามแผน');
                }
            }
        }

        Carbon::setTestNow();
    }

    /**
     * Last year's (expired) contract and this year's, which ends in $daysLeft days,
     * each covering the customer's assets.
     */
    private function contracts(Customer $customer, int $daysLeft): void
    {
        $assetIds = Asset::where('customer_id', $customer->id)->pluck('id')->all();
        $window = fake()->randomElement(Contract::SERVICE_WINDOWS);
        $ends = now()->addDays($daysLeft)->startOfDay();
        $starts = $ends->copy()->subYear()->addDay();

        foreach ([[$starts->copy()->subYear(), $starts->copy()->subDay()], [$starts, $ends]] as [$from, $to]) {
            $contract = $this->saveContract->handle(null, [
                'customer_id' => $customer->id,
                'contract_no' => sprintf('MA-%s-%s', $from->year + 543, $customer->code),
                'title' => 'สัญญาบำรุงรักษาระบบ '.$customer->name.' ปี '.($from->year + 543),
                'status' => Contract::STATUS_ACTIVE,
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'value' => fake()->numberBetween(5, 60) * 10_000_00, // satang
                'service_window' => $window,
                'pm_interval_months' => fake()->randomElement([3, 6, 12]),
                'notify_days_before' => 60,
                // [response, resolve] minutes per priority
                'slas' => collect($window === '24x7'
                    ? ['critical' => [30, 240], 'high' => [60, 480], 'medium' => [240, 1440], 'low' => [480, 2880]]
                    : ['critical' => [120, 480], 'high' => [240, 960], 'medium' => [480, 2400]])
                    ->map(fn (array $minutes) => ['response_minutes' => $minutes[0], 'resolve_minutes' => $minutes[1]])
                    ->all(),
            ]);

            if ($assetIds !== []) {
                $this->addContractAssets->handle($contract, $assetIds);
            }
        }
    }

    /**
     * @param  list<string>  $models  "Brand Model ..."
     */
    private function asset(AssetCategory $category, array $models, Branch $branch, ?Customer $customer): void
    {
        [$brand, $model] = explode(' ', fake()->randomElement($models), 2);
        $purchased = fake()->dateTimeBetween('-5 years', '-1 month');
        // Warranty of 1-5 years from purchase, so some are expired and some are about to expire.
        $warranty = (clone $purchased)->modify('+'.fake()->numberBetween(1, 5).' years');

        $this->saveAsset->handle(null, [
            'category_id' => $category->id,
            'branch_id' => fake()->boolean(90) ? $branch->id : null,
            'customer_id' => $customer?->id,
            'name' => "{$category->name} {$brand}",
            'brand' => $brand,
            'model' => $model,
            'serial_number' => strtoupper(fake()->bothify('??#######')),
            'status' => fake()->randomElement([
                ...array_fill(0, 7, Asset::STATUS_IN_USE), Asset::STATUS_SPARE, Asset::STATUS_IN_REPAIR, Asset::STATUS_RETIRED,
            ]),
            'location' => fake()->randomElement(['ชั้น 1', 'ชั้น 2', 'ชั้น 3', 'ห้อง Server', 'ห้องประชุม', 'ฝ่ายบัญชี', 'ฝ่ายขาย']),
            'purchased_at' => $purchased->format('Y-m-d'),
            'purchase_price' => fake()->numberBetween(80, 4000) * 10000, // satang
            'warranty_expires_at' => $warranty->format('Y-m-d'),
            'specs' => $this->specs($category),
        ]);
    }

    private function specs(AssetCategory $category): array
    {
        $values = [
            'cpu' => fake()->randomElement(['Intel Core i5-12500', 'Intel Core i7-13700', 'AMD Ryzen 5 5600G', 'Intel Xeon Silver 4314']),
            'ram_gb' => fake()->randomElement([8, 16, 32, 64]),
            'ports' => fake()->randomElement([8, 24, 48]),
            'ip' => fake()->localIpv4(),
            'rack' => 'R'.fake()->numberBetween(1, 4).'-U'.fake()->numberBetween(1, 42),
            'seats' => fake()->randomElement([5, 10, 25, 50, 100]),
            'license_key' => strtoupper(fake()->bothify('?????-?????-?????-?????')),
        ];

        $specs = [];
        foreach ($category->spec_fields as $field) {
            $specs[$field['key']] = $field['type'] === 'select'
                ? fake()->randomElement($field['options'])
                : $values[$field['key']];
        }

        return $specs;
    }

    // First and last name only: faker th_TH may prefix titles like "Dr.".
    private function name(): string
    {
        return fake()->firstName().' '.fake()->lastName();
    }

    private function user(string $email, string $name, string $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes + [
            'email' => $email,
            'name' => $name,
            'password' => self::PASSWORD,
        ]);
    }
}

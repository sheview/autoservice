<?php

namespace Database\Seeders;

use App\Modules\Asset\Actions\ApproveCheckoutRequest;
use App\Modules\Asset\Actions\AssignIp;
use App\Modules\Asset\Actions\BackorderCheckoutItem;
use App\Modules\Asset\Actions\CancelCheckoutRequest;
use App\Modules\Asset\Actions\FulfillCheckoutItem;
use App\Modules\Asset\Actions\HandOutPurchase;
use App\Modules\Asset\Actions\IpRecord;
use App\Modules\Asset\Actions\RejectCheckoutRequest;
use App\Modules\Asset\Actions\ReleaseIp;
use App\Modules\Asset\Actions\ReserveIps;
use App\Modules\Asset\Actions\ReturnCheckoutItem;
use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Actions\SaveAssetCategory;
use App\Modules\Asset\Actions\SaveCheckoutRequest;
use App\Modules\Asset\Actions\SaveNetwork;
use App\Modules\Asset\Actions\SaveSubnet;
use App\Modules\Asset\Actions\SetIpExcluded;
use App\Modules\Asset\Actions\SubmitCheckoutRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Contract\Actions\AddContractAssets;
use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Contract\Actions\SaveContract;
use App\Modules\Contract\Actions\SaveCustomer;
use App\Modules\Contract\Actions\SaveSite;
use App\Modules\Contract\Actions\SyncContractMembers;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Models\CustomerSite;
use App\Modules\Document\Actions\SaveManual;
use App\Modules\Identity\Actions\SaveUser;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Actions\IssuePartToTicket;
use App\Modules\Inventory\Actions\MovePurchaseRequest;
use App\Modules\Inventory\Actions\PurchaseIssueLines;
use App\Modules\Inventory\Actions\ReceivePartUnits;
use App\Modules\Inventory\Actions\ReceivePurchase;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Actions\RemovePartUnits;
use App\Modules\Inventory\Actions\SavePart;
use App\Modules\Inventory\Actions\SavePurchaseRequest;
use App\Modules\Inventory\Actions\VoidStockReceipt;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Labeling\Actions\RecordLabelPrint;
use App\Modules\Maintenance\Actions\CreatePmReportShare;
use App\Modules\Maintenance\Actions\CreatePmWorkOrder;
use App\Modules\Maintenance\Actions\CreateTemplateVersion;
use App\Modules\Maintenance\Actions\MarkPmVisitArrival;
use App\Modules\Maintenance\Actions\MarkPmVisitDeparture;
use App\Modules\Maintenance\Actions\MovePmVisit;
use App\Modules\Maintenance\Actions\PublishTemplate;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Actions\SubmitPmVisit;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Platform\Actions\CreateTenant;
use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\RoomAccess\Actions\CancelRoomAccessRequest;
use App\Modules\RoomAccess\Actions\DecideRoomAccessRequest;
use App\Modules\RoomAccess\Actions\FinishRoomVisit;
use App\Modules\RoomAccess\Actions\MarkOverdueRoomRequests;
use App\Modules\RoomAccess\Actions\PublishRoomRules;
use App\Modules\RoomAccess\Actions\RecordRoomEntry;
use App\Modules\RoomAccess\Actions\RecordRoomExit;
use App\Modules\RoomAccess\Actions\RoomRulesForRequest;
use App\Modules\RoomAccess\Actions\SaveRoomAccessRequest;
use App\Modules\RoomAccess\Actions\SaveRoomAccessSettings;
use App\Modules\RoomAccess\Actions\SaveServerRoom;
use App\Modules\RoomAccess\Actions\SubmitRoomAccessRequest;
use App\Modules\RoomAccess\Jobs\SendGuardLinkMail;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomApprovalStep;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\CheckTicketWarranty;
use App\Modules\Service\Actions\CommentOnTicket;
use App\Modules\Service\Actions\CreateFieldLink;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Actions\OpenReportedTicket;
use App\Modules\Service\Actions\OpenTicket;
use App\Modules\Service\Actions\ReviewReportedTicket;
use App\Modules\Service\Actions\RevokeFieldLink;
use App\Modules\Service\Actions\SaveHoliday;
use App\Modules\Service\Actions\SavePersonalEvent;
use App\Modules\Service\Actions\SaveRepairPresets;
use App\Modules\Service\Actions\SaveRepairReport;
use App\Modules\Service\Actions\SubmitFieldReport;
use App\Modules\Service\Jobs\SendCustomerTicketMail;
use App\Modules\Service\Jobs\SendTicketNotification;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketFieldLink;
use App\Modules\Service\Models\TicketRemovedPart;
use App\Modules\Survey\Actions\AnswerTicketSurvey;
use App\Modules\Survey\Jobs\SendSurveyInvitation;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Tenancy\Actions\SaveBranch;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "บริษัท ตัวอย่าง จำกัด" (subdomain "sample"): a company for training staff and customers, with
 * at least four examples of everything and every status of each workflow — tickets, PM work
 * orders, purchase requests, issue/loan requests, room access requests, IP addresses, contracts,
 * assets, spare parts. Everything goes through the same Actions as the app, dated over the last
 * weeks, so SLA clocks, histories and dashboards look real.
 *
 * Unlike DemoSeeder this one also runs in production (it does not use faker) and goes with every
 * deploy; it does nothing when the company already exists:
 *   php artisan db:seed --class=SampleCompanySeeder --force
 *
 * The password of every account is SAMPLE_COMPANY_PASSWORD (config app.sample_company_password); without it, "password" off
 * production and a random one (printed once) in production. No e-mail or alert goes out.
 */
class SampleCompanySeeder extends Seeder
{
    use PmDemoWork;

    public const SUBDOMAIN = 'sample';

    public const NAME = 'บริษัท ตัวอย่าง จำกัด';

    private const DOMAIN = 'sample.test';

    private const BRANCHES = [
        ['code' => 'BKK', 'name' => 'สำนักงานใหญ่ กรุงเทพ', 'province' => 'กรุงเทพมหานคร'],
        ['code' => 'CNX', 'name' => 'สาขาเชียงใหม่', 'province' => 'เชียงใหม่'],
        ['code' => 'KKC', 'name' => 'สาขาขอนแก่น', 'province' => 'ขอนแก่น'],
        ['code' => 'HDY', 'name' => 'สาขาหาดใหญ่', 'province' => 'สงขลา'],
    ];

    /** [name, tax id, contact, phone, site name, site note, branch index]. */
    private const CUSTOMERS = [
        ['บริษัท ตัวอย่างค้าปลีก จำกัด (มหาชน)', '0107500000011', 'คุณสมศรี ใจดี', '02-111-1111', 'สำนักงานใหญ่ สีลม', 'ติดต่อ รปภ. ชั้น 1 ก่อนขึ้นอาคาร', 0],
        ['โรงพยาบาลตัวอย่าง', '0994000000022', 'คุณวิภา รักษ์งาน', '053-222-222', 'อาคารผู้ป่วยนอก', 'เข้างานได้ 08:00-16:00 น.', 1],
        ['บริษัท ตัวอย่างขนส่ง จำกัด', '0105500000033', 'คุณประเสริฐ ส่งไว', '043-333-333', 'คลังสินค้า 1', null, 2],
        ['มหาวิทยาลัยตัวอย่าง', '0994000000044', 'คุณอรุณี วิชาการ', '074-444-444', 'ศูนย์คอมพิวเตอร์', 'แลกบัตรที่ป้อมหน้าอาคาร', 3],
    ];

    /** Each customer's devices: [category prefix, brand, model, status, location]. */
    private const CUSTOMER_ASSETS = [
        ['SV', 'Dell', 'PowerEdge R650', Asset::STATUS_IN_USE, 'ห้อง Server'],
        ['SW', 'Cisco', 'Catalyst 9200-24P', Asset::STATUS_IN_USE, 'ห้อง Server'],
        ['PC', 'HP', 'ProDesk 400 G9', Asset::STATUS_IN_USE, 'ฝ่ายบัญชี'],
        ['UPS', 'APC', 'Smart-UPS 3000', Asset::STATUS_IN_USE, 'ห้อง Server'],
        ['AP', 'Aruba', 'AP-515', Asset::STATUS_IN_USE, 'ชั้น 2'],
        ['NB', 'Lenovo', 'ThinkPad E14', Asset::STATUS_SPARE, 'ห้องเก็บของ'],
        ['PR', 'HP', 'LaserJet Pro M404dn', Asset::STATUS_IN_REPAIR, 'ฝ่ายขาย'],
        ['MON', 'Dell', 'P2422H', Asset::STATUS_RETIRED, 'ห้องเก็บของ'],
    ];

    /** The MA company's own devices (lent and issued on checkout requests). */
    private const OWN_ASSETS = [
        ['NB', 'Dell', 'Latitude 5440', Asset::STATUS_SPARE, 'ห้องเก็บของ สำนักงานใหญ่'],
        ['NB', 'Dell', 'Latitude 5440', Asset::STATUS_SPARE, 'ห้องเก็บของ สำนักงานใหญ่'],
        ['NB', 'Lenovo', 'ThinkPad T14', Asset::STATUS_SPARE, 'ห้องเก็บของ สำนักงานใหญ่'],
        ['NB', 'Lenovo', 'ThinkPad T14', Asset::STATUS_SPARE, 'ห้องเก็บของ สำนักงานใหญ่'],
        ['PC', 'Lenovo', 'ThinkCentre M70', Asset::STATUS_SPARE, 'ห้องเก็บของ สำนักงานใหญ่'],
        ['PC', 'Lenovo', 'ThinkCentre M70', Asset::STATUS_IN_USE, 'ฝ่ายบริการ'],
        ['MON', 'Dell', 'P2422H', Asset::STATUS_SPARE, 'ห้องเก็บของ สำนักงานใหญ่'],
        ['OE', 'Epson', 'EB-X51', Asset::STATUS_SPARE, 'ห้องประชุม'],
        ['LIC', 'Microsoft', '365 Business Standard', Asset::STATUS_IN_USE, 'Cloud'],
    ];

    /** Spare parts: [code, name, brand, part number, unit, reorder point, cost in baht, pieces received]. */
    private const PARTS = [
        ['RAM-DDR4-8G', 'RAM DDR4 8GB 2666', 'Kingston', 'KVR26N19S8/8', 'ชิ้น', 4, 890, 12, 'hardware'],
        ['SSD-SATA-500', 'SSD SATA 500GB', 'WD', 'WDS500G3B0A', 'ชิ้น', 3, 1490, 8, 'hardware'],
        ['PSU-ATX-550', 'Power supply ATX 550W', 'Corsair', 'CV550', 'ชิ้น', 2, 1690, 2, 'hardware'],
        ['FAN-SW-C9200', 'พัดลมสวิตช์ Catalyst 9200', 'Cisco', 'C9200-FAN', 'ชิ้น', 1, 4200, 0, 'network'],
        ['SFP-10G-SR', 'SFP+ 10G SR module', 'Cisco', 'SFP-10G-SR', 'ชิ้น', 4, 2900, 10, 'network'],
        ['CAB-UTP-C6-3M', 'สาย UTP Cat6 3 เมตร', 'Link', 'US-5103', 'เส้น', 20, 65, 60, 'network'],
        ['TONER-HP-59A', 'ตลับหมึก HP 59A', 'HP', 'CF259A', 'กล่อง', 3, 3450, 4, 'supplies'],
        ['BAT-UPS-12V9', 'แบตเตอรี่ UPS 12V 9Ah', 'CSB', 'HR1234W', 'ลูก', 4, 950, 16, 'power'],
    ];

    /** Thai public holidays 2026 (check against the official announcement). */
    private const HOLIDAYS = [
        '2026-01-01' => 'วันขึ้นปีใหม่', '2026-03-03' => 'วันมาฆบูชา', '2026-04-06' => 'วันจักรี',
        '2026-04-13' => 'วันสงกรานต์', '2026-04-14' => 'วันสงกรานต์', '2026-04-15' => 'วันสงกรานต์',
        '2026-05-01' => 'วันแรงงานแห่งชาติ', '2026-05-04' => 'วันฉัตรมงคล', '2026-06-01' => 'ชดเชยวันวิสาขบูชา',
        '2026-06-03' => 'วันเฉลิมพระชนมพรรษาสมเด็จพระราชินี', '2026-07-28' => 'วันเฉลิมพระชนมพรรษา ร.10',
        '2026-07-29' => 'วันอาสาฬหบูชา', '2026-08-12' => 'วันแม่แห่งชาติ', '2026-10-13' => 'วันนวมินทรมหาราช',
        '2026-10-23' => 'วันปิยมหาราช', '2026-12-07' => 'ชดเชยวันพ่อแห่งชาติ', '2026-12-10' => 'วันรัฐธรรมนูญ',
        '2026-12-31' => 'วันสิ้นปี',
    ];

    private CarbonImmutable $realNow;

    private string $password;

    /** @var array<string, User> */
    private array $people = [];

    /** @var Collection<int, Customer> */
    private Collection $customers;

    public function __construct(private TenantContext $context) {}

    public function run(): void
    {
        if ($existing = Tenant::query()->where('subdomain', self::SUBDOMAIN)->first()) {
            // Made before the flag existed: still marked, so it stays quiet and shows its banner.
            $existing->forceFill(['is_sample' => true])->save();
            $this->command?->info(self::NAME.' มีอยู่แล้ว: ไม่ได้เพิ่มข้อมูล');

            return;
        }

        // Sample data must not e-mail or message anyone, now or when the queue worker starts.
        Bus::fake([SendTicketNotification::class, SendCustomerTicketMail::class, SendSurveyInvitation::class, DeliverAlert::class, SendGuardLinkMail::class]);
        Mail::fake();
        Notification::fake();

        $this->password = (string) (config('app.sample_company_password') ?: (app()->isProduction() ? Str::password(12, symbols: false) : 'password'));
        $this->realNow = now()->toImmutable();
        mt_srand(2569);

        try {
            DB::transaction(function () {
                $tenant = app(CreateTenant::class)->handle([
                    'name' => self::NAME,
                    'subdomain' => self::SUBDOMAIN,
                    'status' => Tenant::STATUS_ACTIVE,
                    'admin_name' => 'สมชาย ผู้ดูแล',
                    'admin_email' => 'admin@'.self::DOMAIN,
                    'admin_password' => $this->password,
                ]);
                // A training company: banner on every page, nothing ever sent out (TenancyServiceProvider).
                $tenant->forceFill(['is_sample' => true])->save();
                $this->context->run($tenant, fn () => $this->seedTenant());
            });
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info(self::NAME.' พร้อมใช้งานที่ subdomain "'.self::SUBDOMAIN.'"');
        $this->command?->table(['บัญชี', 'บทบาท'], collect($this->people)->map(fn (User $user, string $key) => [$user->email, $key])->values()->all());
        $this->command?->warn('รหัสผ่านทุกบัญชี: '.$this->password);
    }

    private function seedTenant(): void
    {
        $this->at(60, 9);
        $branches = collect(self::BRANCHES)->map(fn (array $branch) => app(SaveBranch::class)->handle(null, $branch));
        $this->people($branches);
        $this->customers($branches);
        $this->assets($branches);
        $this->contracts();

        foreach (self::HOLIDAYS as $date => $name) {
            app(SaveHoliday::class)->handle(['date' => $date, 'name' => $name]);
        }
        app(SaveRepairPresets::class)->handle([
            'symptom' => ['เปิดเครื่องไม่ติด', 'เชื่อมต่อเครือข่ายไม่ได้', 'กระดาษติด', 'ระบบช้าผิดปกติ'],
            'solution' => ['เปลี่ยนสายสัญญาณ', 'รีสตาร์ทอุปกรณ์', 'ทำความสะอาดลูกยาง', 'เปลี่ยนอะไหล่'],
        ]);

        $this->parts();
        $this->tickets();
        $this->ticketExtras();
        $this->purchaseRequests();
        $this->checkouts();
        $this->ipam();
        $this->labelsAndDocuments();
        $this->maintenance();
        $this->roomAccess();
        $this->surveys();
    }

    /** Sets the clock to $daysAgo days back at $hour:$minute. */
    private function at(int $daysAgo, int $hour = 10, int $minute = 0): void
    {
        Carbon::setTestNow($this->realNow->subDays($daysAgo)->setTime($hour, $minute));
    }

    private function later(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    // ---------------------------------------------------------------- People, customers, assets, contracts

    private function people(Collection $branches): void
    {
        $this->people['admin_company'] = User::query()->where('email', 'admin@'.self::DOMAIN)->firstOrFail();
        $save = fn (string $key, string $email, string $name, string $role, array $attributes = []) => $this->people[$key] = app(SaveUser::class)->handle(null, $attributes + [
            'name' => $name, 'email' => $email.'@'.self::DOMAIN, 'password' => $this->password, 'role' => $role,
        ]);

        $save('helpdesk', 'helpdesk', 'สุดา ช่วยเหลือ', 'helpdesk', ['branch_id' => $branches[0]->id, 'position' => 'เจ้าหน้าที่ Helpdesk', 'phone' => '081-000-0001']);
        $save('technician (network / data center)', 'tech1', 'วิชัย ช่างดี', 'technician', [
            'branch_id' => $branches[0]->id, 'position' => 'ช่างเทคนิคอาวุโส', 'phone' => '081-000-0002', 'service_lines' => ['network', 'datacenter'],
        ]);
        $save('technician (PC)', 'tech2', 'มานพ ซ่อมเก่ง', 'technician', [
            'branch_id' => $branches[1]->id, 'position' => 'ช่างเทคนิค', 'phone' => '081-000-0003', 'service_lines' => ['pc'],
        ]);
        $save('user', 'user', 'ปิยะ พนักงาน', 'user', ['branch_id' => $branches[0]->id, 'position' => 'เจ้าหน้าที่ฝ่ายขาย']);
        $save('user (inactive)', 'former', 'อดีต พนักงาน', 'user', ['branch_id' => $branches[2]->id, 'position' => 'ลาออกแล้ว', 'is_active' => false]);
    }

    private function customers(Collection $branches): void
    {
        $this->customers = collect(self::CUSTOMERS)->map(function (array $row, int $i) {
            [$name, $taxId, $contact, $phone, $site, $siteNote] = $row;
            $customer = app(SaveCustomer::class)->handle(null, [
                'code' => sprintf('CUST%03d', $i + 1), 'name' => $name, 'tax_id' => $taxId,
                'contact_name' => $contact, 'phone' => $phone, 'email' => 'it'.($i + 1).'@customer.'.self::DOMAIN,
                'address' => ($i + 1).'/99 ถนนตัวอย่าง แขวงตัวอย่าง เขตตัวอย่าง กรุงเทพมหานคร 10500',
            ]);
            app(SaveSite::class)->handle($customer, null, ['name' => $site, 'notes' => $siteNote, 'address' => 'ที่ตั้งของ '.$site]);

            return $customer;
        });

        // Customer accounts: see only their own company's tickets, assets and PM reports.
        foreach ([0, 1] as $i) {
            $key = 'customer '.$this->customers[$i]->code;
            $this->people[$key] = app(SaveUser::class)->handle(null, [
                'name' => self::CUSTOMERS[$i][2], 'email' => ($i === 0 ? 'customer' : 'customer2').'@'.self::DOMAIN,
                'password' => $this->password, 'role' => PermissionCatalog::CUSTOMER_ROLE,
                'customer_id' => $this->customers[$i]->id, 'position' => 'เจ้าหน้าที่ไอทีของลูกค้า',
            ]);
        }
    }

    private function assets(Collection $branches): void
    {
        $categories = AssetCategory::query()->pluck('id', 'code_prefix');
        $n = 0;
        $save = function (array $row, ?Customer $customer, Branch $branch) use ($categories, &$n) {
            [$prefix, $brand, $model, $status, $location] = $row;
            $n++;
            $purchased = $this->realNow->subMonths(6 + $n * 2);

            app(SaveAsset::class)->handle(null, [
                'category_id' => $categories[$prefix],
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'name' => AssetCategory::query()->find($categories[$prefix])->name.' '.$brand,
                'brand' => $brand,
                'model' => $model,
                'serial_number' => sprintf('SMP%s%05d', $prefix, $n),
                'status' => $status,
                'location' => $location,
                'purchased_at' => $purchased->toDateString(),
                'purchase_price' => (10_000 + $n * 1_500) * 100, // satang
                // Every fourth one out of warranty, the rest covered for 1-3 more years.
                'warranty_expires_at' => $n % 4 === 0 ? $purchased->addYear()->toDateString() : $this->realNow->addMonths(6 + ($n % 3) * 12)->toDateString(),
                'specs' => $this->specs($prefix, $n),
            ]);
        };

        foreach ($this->customers as $i => $customer) {
            foreach (self::CUSTOMER_ASSETS as $row) {
                $save($row, $customer, $branches[self::CUSTOMERS[$i][6]]);
            }
        }
        foreach (self::OWN_ASSETS as $row) {
            $save($row, null, $branches[0]);
        }
    }

    private function specs(string $prefix, int $n): array
    {
        return match ($prefix) {
            'PC', 'NB' => ['cpu' => 'Intel Core i5-1335U', 'ram_gb' => 16, 'storage' => 'SSD 512GB', 'os' => 'Windows 11'],
            'SV' => ['cpu' => 'Intel Xeon Silver 4314', 'ram_gb' => 128, 'storage' => 'SAS 4 x 1.2TB RAID 5', 'rack' => 'R1-U'.(10 + $n % 20), 'mgmt_ip' => '10.0.0.'.(10 + $n)],
            'SW' => ['ports' => 24, 'layer' => 'L2', 'poe' => 'มี', 'mgmt_ip' => '10.0.1.'.(10 + $n), 'firmware' => '17.9.4'],
            'UPS' => ['capacity_va' => 3000, 'battery_changed_on' => $this->realNow->subYear()->toDateString()],
            'AP' => ['wifi_standard' => 'Wi-Fi 6', 'mgmt_ip' => '10.0.2.'.(10 + $n)],
            'PR' => ['print_type' => 'เลเซอร์ขาวดำ', 'connection' => 'LAN'],
            'MON' => ['size_inch' => 24],
            'OE' => ['equipment_type' => 'โปรเจกเตอร์'],
            'LIC' => ['seats' => 25, 'license_key' => 'XXXXX-XXXXX-XXXXX-'.sprintf('%05d', $n), 'license_expires_on' => $this->realNow->addMonths(8)->toDateString()],
            default => [],
        };
    }

    /**
     * A running contract for every customer (the first ends in 30 days), and around them one
     * of each other state: expired (last year's), a draft renewal and a cancelled one.
     */
    private function contracts(): void
    {
        $slas = fn (string $window) => collect($window === '24x7'
            ? ['critical' => [30, 240], 'high' => [60, 480], 'medium' => [240, 1440], 'low' => [480, 2880]]
            : ['critical' => [120, 480], 'high' => [240, 960], 'medium' => [480, 2400], 'low' => [960, 4800]])
            ->map(fn (array $minutes) => ['response_minutes' => $minutes[0], 'resolve_minutes' => $minutes[1]])->all();

        $save = function (Customer $customer, string $status, CarbonImmutable $from, CarbonImmutable $to, string $window, int $baht, string $suffix = '') use ($slas) {
            $contract = app(SaveContract::class)->handle(null, [
                'customer_id' => $customer->id,
                'contract_no' => sprintf('MA-%d-%s%s', $from->year + 543, $customer->code, $suffix),
                'title' => 'สัญญาบำรุงรักษาระบบ '.$customer->name.' ปี '.($from->year + 543),
                'status' => $status,
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'value' => $baht * 100, // satang
                'service_window' => $window,
                'pm_interval_months' => 3,
                'notify_days_before' => 60,
                'slas' => $slas($window),
            ]);
            $assetIds = Asset::query()->where('customer_id', $customer->id)->where('status', '!=', Asset::STATUS_RETIRED)->pluck('id')->all();
            app(AddContractAssets::class)->handle($contract, $assetIds);

            return $contract;
        };

        $today = $this->realNow->startOfDay();
        $windows = ['24x7', '8x5', '24x7', '8x5'];
        foreach ($this->customers as $i => $customer) {
            $ends = $i === 0 ? $today->addDays(30) : $today->addMonths(4 + $i * 2);
            $save($customer, Contract::STATUS_ACTIVE, $ends->subYear()->addDay(), $ends, $windows[$i], 240_000 + $i * 60_000);
        }

        // Expired: last year's contract of the first customer.
        $first = $this->customers[0];
        $save($first, Contract::STATUS_ACTIVE, $today->addDays(30)->subYears(2)->addDay(), $today->addDays(30)->subYear(), '24x7', 220_000, '-OLD');
        // A renewal still being drafted for the first customer.
        $save($first, Contract::STATUS_DRAFT, $today->addDays(31), $today->addDays(30)->addYear(), '24x7', 260_000, '-RENEW');
        // Cancelled before it started.
        $save($this->customers[2], Contract::STATUS_CANCELLED, $today->addMonths(1), $today->addMonths(13), '8x5', 90_000, '-CCTV');

        // Project teams of the running contracts.
        $teams = [
            [$this->people['admin_company'], $this->tech1()],
            [$this->helpdesk(), $this->tech2()],
            [$this->helpdesk(), $this->tech1(), $this->tech2()],
            [$this->tech1()],
        ];
        $this->runningContracts()->each(fn (Contract $contract, int $i) => app(SyncContractMembers::class)->handle($contract, collect($teams[$i])->pluck('id')->all()));
    }

    /** @return Collection<int, Contract> one per customer, in customer order */
    private function runningContracts(): Collection
    {
        return Contract::query()->where('status', Contract::STATUS_ACTIVE)
            ->where('starts_on', '<=', $this->realNow->toDateString())->where('ends_on', '>=', $this->realNow->toDateString())
            ->orderBy('customer_id')->get();
    }

    private function helpdesk(): User
    {
        return $this->people['helpdesk'];
    }

    private function tech1(): User
    {
        return $this->people['technician (network / data center)'];
    }

    private function tech2(): User
    {
        return $this->people['technician (PC)'];
    }

    private function admin(): User
    {
        return $this->people['admin_company'];
    }

    /** A customer's device of a category (in use unless said otherwise). */
    private function device(int $customer, string $prefix): Asset
    {
        return Asset::query()->where('customer_id', $this->customers[$customer]->id)
            ->whereHas('category', fn ($q) => $q->where('code_prefix', $prefix))->orderBy('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- Inventory

    /**
     * Four part categories and the parts in them (one at its reorder point, one never in stock),
     * a part followed by serial number with pieces in stock, issued and written off, and a
     * receipt keyed in by mistake and voided.
     */
    private function parts(): void
    {
        $this->at(30, 10);
        $categories = collect([
            'hardware' => ['อะไหล่คอมพิวเตอร์และเซิร์ฟเวอร์', false],
            'network' => ['อุปกรณ์เครือข่าย', true],
            'supplies' => ['วัสดุสิ้นเปลือง', false],
            'power' => ['อุปกรณ์ระบบไฟฟ้า', false],
        ])->map(fn (array $row) => AssetCategory::query()->where('name', $row[0])->first()
            ?? app(SaveAssetCategory::class)->handle(null, ['name' => $row[0], 'requires_serial' => $row[1]]));

        foreach (self::PARTS as $i => [$code, $name, $brand, $partNumber, $unit, $minQty, $baht, $received, $category]) {
            $part = app(SavePart::class)->handle(null, [
                'code' => $code, 'name' => $name, 'brand' => $brand, 'part_number' => $partNumber, 'unit' => $unit,
                'min_qty' => $minQty, 'unit_cost' => $baht * 100, 'asset_category_id' => $categories[$category]->id,
                // The network category follows serial numbers; these sample parts are counted only.
                'track_serial' => false,
            ]);
            if ($received > 0) {
                app(RecordStockMovement::class)->handle($part, 'receive', $received, $this->admin(), [
                    'unit_cost' => $baht * 100, 'reference' => sprintf('DN-6909%03d', $i + 1),
                ]);
            }
        }

        // Keyed in twice by mistake: the second receipt is voided.
        $toner = Part::query()->where('code', 'TONER-HP-59A')->firstOrFail();
        $this->later(30);
        $twice = app(RecordStockMovement::class)->handle($toner, 'receive', 4, $this->admin(), ['unit_cost' => 345_000, 'reference' => 'DN-6909007']);
        $this->later(60);
        app(VoidStockReceipt::class)->handle($twice, 'บันทึกรับซ้ำกับใบส่งของเดิม', $this->admin());

        // A part followed by serial number: five pieces in, one written off as damaged.
        $this->at(20, 10);
        $nvme = app(SavePart::class)->handle(null, [
            'code' => 'SSD-NVME-1T', 'name' => 'SSD NVMe 1TB', 'brand' => 'Samsung', 'part_number' => 'MZ-V8P1T0BW', 'unit' => 'ชิ้น',
            'min_qty' => 2, 'unit_cost' => 3290 * 100, 'asset_category_id' => $categories['hardware']->id, 'track_serial' => true,
        ]);
        app(ReceivePartUnits::class)->handle($nvme, array_map(fn (int $i) => sprintf('S6WRNJ0T4%05d', $i), range(1, 5)), $this->admin(), [
            'unit_cost' => 3290 * 100, 'reference' => 'DN-6910021', 'supplier' => 'บริษัท ไอทีซัพพลาย จำกัด',
            'warranty_until' => $this->realNow->addYears(5)->toDateString(),
        ]);
        $this->later(120);
        $damaged = PartUnit::query()->where('part_id', $nvme->id)->orderByDesc('id')->value('id');
        app(RemovePartUnits::class)->handle($nvme->fresh(), [$damaged], $this->admin(), 'ชำรุดจากการขนส่ง ส่งเคลมผู้จำหน่าย', 'RMA-6910001');
    }

    // ---------------------------------------------------------------- Service

    /**
     * Tickets in every status: two QR reports waiting for review (one asked for more), new,
     * assigned, in progress (one sent back after the fix), on hold, resolved, four closed (for the
     * surveys) and two cancelled (one by the helpdesk, one QR report turned down).
     */
    private function tickets(): void
    {
        $plans = [
            // [customer, device, title, priority, source, days ago, moves]
            [0, 'PC', 'เครื่องเปิดไม่ติด มีเสียงบี๊ปยาว', 'high', 'phone', 0, []],
            [1, 'AP', 'Wi-Fi ชั้น 2 หลุดบ่อยช่วงบ่าย', 'medium', 'email', 1, ['assign']],
            [2, 'SW', 'พอร์ตสวิตช์ 12-16 ไม่ทำงาน', 'critical', 'phone', 2, ['assign', 'start']],
            [3, 'SV', 'ไฟแจ้งเตือนสีส้มที่เซิร์ฟเวอร์ ดิสก์ช่อง 3', 'high', 'portal', 3, ['assign', 'start', 'hold']],
            [1, 'UPS', 'UPS ส่งเสียงเตือนเป็นระยะ', 'medium', 'phone', 4, ['assign', 'start', 'resolve']],
            [0, 'SW', 'ลูกค้าแจ้ง Internet ช้าทั้งชั้น', 'high', 'email', 5, ['assign', 'start', 'resolve', 'reject']],
            [0, 'SV', 'เซิร์ฟเวอร์ Backup ทำงานไม่ครบ', 'critical', 'phone', 6, ['assign', 'start', 'resolve', 'approve']],
            [1, 'PC', 'ติดตั้งโปรแกรมบัญชีเวอร์ชันใหม่', 'low', 'walk_in', 9, ['assign', 'start', 'resolve', 'approve']],
            [2, 'UPS', 'UPS ไม่จ่ายไฟเมื่อไฟดับ', 'high', 'phone', 14, ['assign', 'start', 'hold', 'start', 'resolve', 'approve']],
            [3, 'AP', 'ขอย้ายจุด Access Point ห้องประชุม', 'low', 'portal', 21, ['assign', 'start', 'resolve', 'approve']],
            [3, 'PC', 'ลูกค้าแจ้งซ้ำกับใบงานเดิม', 'medium', 'phone', 7, ['cancel']],
        ];

        foreach ($plans as $i => [$customer, $prefix, $title, $priority, $source, $daysAgo, $moves]) {
            $asset = $this->device($customer, $prefix);
            $technician = in_array($prefix, ['PC', 'PR', 'NB'], true) ? $this->tech2() : $this->tech1();
            if ($daysAgo === 0) {
                Carbon::setTestNow($this->realNow->subHours(3));
            } else {
                $this->at($daysAgo, 9 + $i % 6, ($i * 15) % 60);
            }

            $contract = app(CoveringContracts::class)->handle($asset->customer_id, $asset->id)[0] ?? null;
            $ticket = app(OpenTicket::class)->handle($this->helpdesk(), [
                'customer_id' => $asset->customer_id, 'asset_id' => $asset->id, 'contract_id' => $contract['id'] ?? null,
                'title' => "{$title} ({$asset->asset_code})",
                'description' => 'ลูกค้าแจ้งปัญหา ต้องการให้ช่างเข้าตรวจสอบ',
                'priority' => $priority, 'source' => $source,
                'contact_name' => self::CUSTOMERS[$customer][2], 'contact_phone' => self::CUSTOMERS[$customer][3],
            ]);

            foreach ($moves as $move) {
                $this->later(45 + $i * 5);
                match ($move) {
                    'assign' => app(AssignTicket::class)->handle($ticket, $technician->id, $this->helpdesk()),
                    'start' => app(MoveTicket::class)->handle($ticket->warranty_checked_at
                        ? $ticket
                        : app(CheckTicketWarranty::class)->handle($ticket, $technician, $asset->warranty_expires_at?->isFuture() ? 'in_warranty' : 'out_of_warranty', null),
                        'start', $technician),
                    'hold' => app(MoveTicket::class)->handle($ticket, 'hold', $technician, 'รออะไหล่จากผู้จำหน่าย'),
                    'resolve' => app(MoveTicket::class)->handle(app(SaveRepairReport::class)->handle($ticket, $technician, [
                        'cause' => ['สายสัญญาณหลวม', 'อุปกรณ์เสื่อมตามอายุ', 'ตั้งค่าผิด', 'ไฟกระชาก'][$i % 4],
                        'extra_cost' => null,
                        'approver_name' => self::CUSTOMERS[$customer][2],
                    ]), 'resolve', $technician),
                    'reject' => app(MoveTicket::class)->handle($ticket, 'reject', $this->admin(), 'ลูกค้าแจ้งว่ายังช้าอยู่ ให้ตรวจสอบเพิ่ม'),
                    'approve' => app(MoveTicket::class)->handle($ticket, 'approve', $this->admin()),
                    'cancel' => app(MoveTicket::class)->handle($ticket, 'cancel', $this->helpdesk(), 'ลูกค้าแจ้งซ้ำกับใบงานเดิม'),
                };
            }
        }

        // Reported with the device's QR code: waiting, asked for more, turned down.
        $reports = [
            [0, 'PR', ['กระดาษติด'], 'ติดบ่อยตอนพิมพ์สองหน้า', null, 0],
            [2, 'PC', ['เปิดเครื่องไม่ติด'], null, 'ask', 1],
            [1, 'SW', ['เชื่อมต่อเครือข่ายไม่ได้'], 'ทดสอบแล้วเป็นที่สาย LAN ของผู้ใช้เอง', 'reject', 2],
        ];
        foreach ($reports as [$customer, $prefix, $symptoms, $note, $decision, $daysAgo]) {
            $this->at($daysAgo, 8, 30);
            $ticket = app(OpenReportedTicket::class)->handle($this->device($customer, $prefix)->id, [
                'symptoms' => $symptoms, 'note' => $note, 'name' => 'ผู้ใช้งานของลูกค้า', 'phone' => '080-000-0000',
            ])['ticket'];
            if ($decision !== null) {
                $this->later(40);
                app(ReviewReportedTicket::class)->handle($ticket, $decision, $decision === 'ask'
                    ? 'รบกวนแจ้งชื่อเครื่องหรือถ่ายรูปหน้าจอที่ขึ้นข้อความเพิ่มเติม'
                    : 'ตรวจสอบแล้วไม่ใช่อุปกรณ์ในสัญญา MA', $this->helpdesk());
            }
        }

        Carbon::setTestNow($this->realNow);
    }

    /** Comments, parts used, links for outside technicians, and pieces taken out of devices. */
    private function ticketExtras(): void
    {
        $byStatus = fn (string $status) => Ticket::query()->where('status', $status)->orderBy('id')->first();
        $inProgress = $byStatus(Ticket::STATUS_IN_PROGRESS);
        $onHold = $byStatus(Ticket::STATUS_ON_HOLD);
        $assigned = $byStatus(Ticket::STATUS_ASSIGNED);
        $new = $byStatus(Ticket::STATUS_NEW);

        Carbon::setTestNow($this->realNow->subHours(2));
        app(CommentOnTicket::class)->handle($inProgress, 'โทรนัดลูกค้าแล้ว จะเข้าหน้างานบ่ายนี้', false, $this->helpdesk());
        app(CommentOnTicket::class)->handle($onHold, 'สั่ง HDD จากผู้จำหน่ายแล้ว คาดว่าได้ของใน 3 วัน (หมายเหตุภายใน)', true, $this->tech1());

        // Parts used on the jobs a technician worked on, oldest job first so the ledger follows the dates.
        $worked = Ticket::query()->whereIn('status', [Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->orderBy('created_at')->orderBy('id')->get();
        $codes = ['CAB-UTP-C6-3M', 'RAM-DDR4-8G', 'BAT-UPS-12V9', 'SFP-10G-SR', 'SSD-SATA-500'];
        foreach ($worked as $i => $ticket) {
            Carbon::setTestNow($ticket->created_at->copy()->addHours(3));
            $part = Part::query()->where('code', $codes[$i % count($codes)])->firstOrFail();
            app(IssuePartToTicket::class)->handle($ticket->id, $part->id, 1, User::query()->findOrFail($ticket->assignee_id), 'ใช้ในงานซ่อม');
        }
        // One serial-numbered SSD fitted into the server on hold.
        $nvme = Part::query()->where('code', 'SSD-NVME-1T')->firstOrFail();
        $unitId = PartUnit::query()->where('part_id', $nvme->id)->where('status', PartUnit::STATUS_IN_STOCK)->orderBy('id')->value('id');
        Carbon::setTestNow($this->realNow->subHours(5));
        app(IssuePartToTicket::class)->handle($onHold->id, $nvme->id, 1, $this->tech1(), 'เปลี่ยนดิสก์ที่เสีย', unitIds: [$unitId], links: ['asset_id' => $onHold->asset_id]);

        // Links for people without an account: a report sent back, one still open, a sign-off, one revoked.
        Carbon::setTestNow($this->realNow->subHours(4));
        $holders = [
            [$inProgress, TicketFieldLink::MODE_WORK, 'สมชาย ช่างนอก', 'บริษัท ซับคอนแทรค จำกัด', '081-234-5678'],
            [$onHold, TicketFieldLink::MODE_WORK, 'วิชัย ช่างนอก', 'หจก. เน็ตเวิร์คเซอร์วิส', '089-876-5432'],
            [$assigned, TicketFieldLink::MODE_SIGN, 'คุณวิภา (ลูกค้า)', null, null],
            [$new, TicketFieldLink::MODE_WORK, 'ช่างที่ยกเลิกงาน', 'บริษัท ช่างด่วน จำกัด', null],
        ];
        foreach ($holders as $i => [$ticket, $mode, $name, $company, $phone]) {
            $link = app(CreateFieldLink::class)->handle($ticket, [
                'mode' => $mode, 'holder_name' => $name, 'holder_company' => $company, 'holder_phone' => $phone, 'days' => 7,
            ], $this->helpdesk());
            if ($i === 0) {
                $this->later(60);
                app(SubmitFieldReport::class)->handle($link, [
                    'symptoms' => ['เชื่อมต่อเครือข่ายไม่ได้'], 'solutions' => ['เปลี่ยนสายสัญญาณ'],
                    'parts' => 'สาย UTP Cat6 3 เมตร 1 เส้น', 'note' => 'ทดสอบแล้วใช้งานได้ปกติ',
                    'signer_name' => null, 'signature' => null,
                ]);
            }
            if ($i === 3) {
                app(RevokeFieldLink::class)->handle($link, $this->helpdesk());
            }
        }

        // Pieces technicians took out of customers' devices (written the way QuickCloseTicket does).
        $pieces = [
            ['item_name' => 'RAM DDR4 8GB (ตัวเดิม)', 'serial_number' => 'KVR26-0A1B2C', 'problem' => 'เครื่องค้าง จอฟ้า ตรวจ MemTest พบ error', 'disposition' => 'claim'],
            ['item_name' => 'แบตเตอรี่ UPS 12V 9Ah (ลูกเดิม)', 'serial_number' => null, 'problem' => 'แบตเสื่อม สำรองไฟได้ไม่ถึง 1 นาที', 'disposition' => 'discard'],
            ['item_name' => 'SSD SATA 240GB (ตัวเดิม)', 'serial_number' => 'WD240-77X1', 'problem' => 'มี bad sector ข้อมูลลูกค้าอยู่ในดิสก์', 'disposition' => 'return_customer'],
            ['item_name' => 'SFP 1G (ตัวเดิม)', 'serial_number' => 'FNS1990A01', 'problem' => 'สัญญาณขาดเป็นช่วง ๆ เก็บไว้เป็นอะไหล่สำรอง', 'disposition' => 'keep'],
        ];
        $finished = Ticket::query()->whereIn('status', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])->orderBy('id')->get();
        foreach ($finished->take(count($pieces)) as $i => $ticket) {
            $technician = User::query()->findOrFail($ticket->assignee_id);
            Carbon::setTestNow($ticket->resolved_at ?? $ticket->created_at->copy()->addHours(3));
            TicketRemovedPart::create($pieces[$i] + [
                'ticket_id' => $ticket->id, 'asset_id' => $ticket->asset_id, 'user_id' => $technician->id, 'user_name' => $technician->name,
            ]);
        }

        // Appointments on people's own "my work" calendars.
        Carbon::setTestNow($this->realNow);
        $day = $this->realNow->addDays(2)->startOfDay();
        $events = [
            [$this->tech1(), 'อบรม Cisco CCNA', $day->setTime(9, 0), $day->setTime(16, 0), false, 'ห้องประชุมชั้น 3'],
            [$this->tech2(), 'ลาพักร้อน', $day->addDays(3), null, true, null],
            [$this->helpdesk(), 'ประชุมทีมประจำสัปดาห์', $day->addDay()->setTime(10, 0), $day->addDay()->setTime(11, 0), false, null],
            [$this->admin(), 'นำเสนอรายงาน MA ประจำไตรมาสให้ลูกค้า', $day->addDays(4)->setTime(14, 0), $day->addDays(4)->setTime(15, 30), false, self::CUSTOMERS[0][0]],
        ];
        foreach ($events as [$user, $title, $starts, $ends, $allDay, $notes]) {
            app(SavePersonalEvent::class)->handle($user, null, [
                'title' => $title, 'starts_at' => $starts->toDateTimeString(), 'ends_at' => $ends?->toDateTimeString(),
                'all_day' => $allDay, 'notes' => $notes,
            ]);
        }
    }

    /**
     * Purchase requests in every status: pending, rejected, cancelled, approved, ordered, partly
     * received, received (not yet put into the system), registered, and issued to who asked.
     */
    private function purchaseRequests(): void
    {
        $move = app(MovePurchaseRequest::class);
        $receive = app(ReceivePurchase::class);
        $contractId = $this->runningContracts()->first()->id;
        $category = fn (string $prefix) => AssetCategory::query()->where('code_prefix', $prefix)->value('id');
        $ask = function (array $item, int $daysAgo, ?User $requester = null) use ($contractId) {
            $this->at($daysAgo, 9);

            return app(SavePurchaseRequest::class)->handle(null, $item + [
                'contract_id' => $contractId, 'reason' => 'ใช้สำหรับงาน MA ตามสัญญา',
                'needed_by' => $this->realNow->addDays(14)->toDateString(), 'links' => ['https://www.example.com/product'],
            ], $requester ?? $this->tech1());
        };

        // Waiting for approval.
        $ask(['item_name' => 'Switch PoE 24 พอร์ต', 'description' => 'ขยายจุด Access Point ชั้น 3', 'quantity' => 1, 'unit' => 'เครื่อง',
            'unit_price' => 18_500 * 100, 'item_kind' => PurchaseRequest::KIND_ASSET, 'asset_category_id' => $category('SW')], 1);

        // Rejected.
        $pr = $ask(['item_name' => 'จอมอนิเตอร์ 27 นิ้ว', 'quantity' => 4, 'unit' => 'เครื่อง', 'unit_price' => 6_900 * 100], 6);
        $this->at(5);
        $move->handle($pr, 'reject', $this->admin(), 'ไม่อยู่ในขอบเขตสัญญา MA');

        // Cancelled by who asked.
        $pr = $ask(['item_name' => 'เมาส์ไร้สาย', 'quantity' => 10, 'unit' => 'ชิ้น', 'unit_price' => 390 * 100], 4, $this->tech2());
        $this->at(3);
        $move->handle($pr, 'cancel', $this->tech2(), 'ลูกค้าจัดซื้อเอง');

        // Approved, not ordered yet.
        $pr = $ask(['item_name' => 'แบตเตอรี่ UPS 12V 9Ah', 'quantity' => 8, 'unit' => 'ลูก', 'unit_price' => 950 * 100,
            'item_kind' => PurchaseRequest::KIND_PART], 3);
        $this->at(2);
        $move->handle($pr, 'approve', $this->admin(), 'อนุมัติตามงบ MA');

        // Ordered: waiting for delivery.
        $pr = $ask(['item_name' => 'ตลับหมึก HP 59A', 'quantity' => 6, 'unit' => 'กล่อง', 'unit_price' => 3_450 * 100,
            'item_kind' => PurchaseRequest::KIND_PART], 5);
        $this->at(4);
        $move->handle($pr, 'approve', $this->admin());
        $this->at(4, 14);
        $move->handle($pr, 'order', $this->admin(), 'สั่งซื้อจาก บริษัท ไอทีซัพพลาย จำกัด PO-6910-0012');

        // Partly received: one of three notebooks so far.
        $pr = $ask(['item_name' => 'โน้ตบุ๊กสำรอง Lenovo ThinkPad E14', 'quantity' => 3, 'unit' => 'เครื่อง', 'unit_price' => 25_000 * 100,
            'item_kind' => PurchaseRequest::KIND_ASSET, 'asset_category_id' => $category('NB')], 9);
        $this->at(8);
        $move->handle($pr, 'approve', $this->admin());
        $this->at(8, 14);
        $move->handle($pr, 'order', $this->admin(), 'PO-6910-0009');
        $this->at(3, 11);
        $receive->handle($pr, ['quantity' => 1, 'brand' => 'Lenovo', 'model' => 'ThinkPad E14 Gen 5', 'unit_price' => 24_500 * 100,
            'serials' => ['PF4XK1A1'], 'note' => 'ทยอยส่ง', 'location' => 'ห้องเก็บของ สำนักงานใหญ่'], $this->admin());

        // Received in full, not yet put into the system (what it becomes was not said).
        $pr = $ask(['item_name' => 'ชุดเครื่องมือเข้าหัวสาย LAN', 'quantity' => 2, 'unit' => 'ชุด', 'unit_price' => 1_200 * 100], 7);
        $this->at(6);
        $move->handle($pr, 'approve', $this->admin());
        $this->at(2, 15);
        $receive->handle($pr, ['quantity' => 2, 'brand' => 'Fluke', 'model' => 'Pro3000', 'unit_price' => 1_150 * 100], $this->admin());

        // Registered as stock of a part.
        $pr = $ask(['item_name' => 'RAM DDR4 8GB 2666', 'quantity' => 10, 'unit' => 'ชิ้น', 'unit_price' => 890 * 100,
            'item_kind' => PurchaseRequest::KIND_PART], 7);
        $this->at(6);
        $move->handle($pr, 'approve', $this->admin());
        $this->at(3, 15);
        $receive->handle($pr, ['quantity' => 10, 'brand' => 'Kingston', 'model' => 'KVR26N19S8/8', 'unit_price' => 870 * 100,
            'part_id' => Part::query()->where('code', 'RAM-DDR4-8G')->value('id')], $this->admin());

        // Registered as assets, then handed to the technician who asked: issued.
        $pr = $ask(['item_name' => 'UPS APC Back-UPS 1100VA', 'quantity' => 2, 'unit' => 'เครื่อง', 'unit_price' => 4_590 * 100,
            'item_kind' => PurchaseRequest::KIND_ASSET, 'asset_category_id' => $category('UPS')], 8);
        $this->at(7);
        $move->handle($pr, 'approve', $this->admin());
        $this->at(3, 11);
        $receive->handle($pr, ['quantity' => 2, 'brand' => 'APC', 'model' => 'BX1100LI-MS', 'serials' => ['3B2311X10001', '3B2311X10002'],
            'location' => 'คลังสาขาเชียงใหม่'], $this->admin());
        $this->at(2, 10);
        $lines = app(PurchaseIssueLines::class)->handle($pr->fresh()->ulid, $this->admin());
        if ($lines !== null) {
            app(HandOutPurchase::class)->handle($lines, $this->admin());
        }

        Carbon::setTestNow($this->realNow);
    }

    // ---------------------------------------------------------------- Asset: checkouts, IP, labels, manuals

    /**
     * Issue/loan requests in every status: draft, pending, approved (one line backordered),
     * partly handed out, fulfilled (a loan still out), closed (issued; and a loan returned),
     * rejected and cancelled.
     */
    private function checkouts(): void
    {
        $save = app(SaveCheckoutRequest::class);
        $submit = app(SubmitCheckoutRequest::class);
        $approve = fn (CheckoutRequest $request) => $request->fresh()->status === CheckoutRequest::STATUS_PENDING
            ? app(ApproveCheckoutRequest::class)->handle($request->fresh(), $this->admin())
            : $request->fresh();
        $fulfill = app(FulfillCheckoutItem::class);
        $own = Asset::query()->whereNull('customer_id')->whereIn('status', [Asset::STATUS_IN_USE, Asset::STATUS_SPARE])
            ->whereHas('category', fn ($q) => $q->where('asset_type', AssetCategory::TYPE_HARDWARE))->orderBy('id')->get();
        $loan = fn (Asset $asset, CarbonImmutable $due) => ['item_type' => CheckoutItem::TYPE_ASSET, 'asset_id' => $asset->id,
            'checkout_type' => CheckoutItem::LOAN, 'qty' => 1, 'due_return_date' => $due->toDateString()];
        $part = fn (string $code, int $qty) => ['item_type' => CheckoutItem::TYPE_PART, 'part_id' => Part::query()->where('code', $code)->value('id'), 'qty' => $qty];
        $ticket = Ticket::query()->where('status', Ticket::STATUS_IN_PROGRESS)->orderBy('id')->firstOrFail();
        $staff = $this->people['user'];

        // Draft, not sent yet.
        $this->at(0, 8);
        $save->handle(null, ['borrower_user_id' => $staff->id, 'borrower_department' => 'ฝ่ายขาย', 'purpose' => 'ยืมไปออกบูธงานแสดงสินค้า',
            'needed_by' => $this->realNow->addDays(7)->toDateString(), 'items' => [$loan($own[0], $this->realNow->addDays(10))]], $this->helpdesk());

        // Waiting for approval.
        $this->at(1, 9, 15);
        $request = $save->handle(null, ['borrower_user_id' => $staff->id, 'borrower_department' => 'ฝ่ายขาย', 'borrower_phone' => '081-000-0004',
            'purpose' => 'ยืมไปนำเสนอลูกค้านอกสถานที่', 'needed_by' => $this->realNow->addDays(2)->toDateString(),
            'items' => [$loan($own[7], $this->realNow->addDays(9))]], $this->helpdesk());
        $submit->handle($request, $this->helpdesk());

        // Approved, waiting to be handed out; the fan is out of stock and waits as backordered.
        $this->at(2, 10);
        $request = $save->handle(null, ['borrower_user_id' => $this->tech1()->id, 'ticket_id' => $ticket->id, 'purpose' => 'ใช้ในงานซ่อมตามใบแจ้งซ่อม',
            'items' => [$loan($own[1], $this->realNow->addDays(5)), $part('FAN-SW-C9200', 1)]], $this->tech1());
        $submit->handle($request, $this->tech1());
        $this->later(60);
        $request = $approve($request);
        $fan = $request->items()->where('item_type', CheckoutItem::TYPE_PART)->first();
        if ($fan) {
            app(BackorderCheckoutItem::class)->handle($fan, $this->admin());
        }

        // Partly handed out: 4 cables asked, 2 given so far.
        $this->at(3, 10);
        $request = $save->handle(null, ['borrower_user_id' => $this->tech1()->id, 'ticket_id' => $ticket->id, 'purpose' => 'เดินสายเพิ่มที่หน้างาน',
            'items' => [$part('CAB-UTP-C6-3M', 4)]], $this->tech1());
        $submit->handle($request, $this->tech1());
        $this->later(30);
        $request = $approve($request);
        $this->later(30);
        $fulfill->handle($request->items()->first(), 2, $this->helpdesk());

        // Fulfilled: a notebook lent and not back yet.
        $this->at(4, 13);
        $request = $save->handle(null, ['borrower_user_id' => $staff->id, 'borrower_department' => 'ฝ่ายบัญชี',
            'purpose' => 'ยืมใช้งานชั่วคราวระหว่างเครื่องหลักส่งซ่อม', 'items' => [$loan($own[2], $this->realNow->addDays(3))]], $this->helpdesk());
        $submit->handle($request, $this->helpdesk());
        $this->later(60);
        $request = $approve($request);
        $this->later(60);
        $fulfill->handle($request->items()->first(), 1, $this->helpdesk());

        // Closed: a monitor issued for good.
        $this->at(6, 10);
        $request = $save->handle(null, ['borrower_user_id' => $this->tech2()->id, 'purpose' => 'เครื่องสำรองให้ลูกค้าใช้ระหว่างซ่อม',
            'items' => [['item_type' => CheckoutItem::TYPE_ASSET, 'asset_id' => $own[6]->id, 'checkout_type' => CheckoutItem::ISSUE, 'qty' => 1]]], $this->tech2());
        $submit->handle($request, $this->tech2());
        $this->later(120);
        $request = $approve($request);
        $this->later(30);
        $fulfill->handle($request->items()->first(), 1, $this->helpdesk());

        // Closed: a PC lent and returned.
        $this->at(12, 13, 30);
        $request = $save->handle(null, ['borrower_user_id' => $staff->id, 'borrower_department' => 'ฝ่ายบัญชี',
            'purpose' => 'ยืมใช้งานระหว่างย้ายสำนักงาน', 'items' => [$loan($own[4], $this->realNow->subDays(5))]], $this->helpdesk());
        $submit->handle($request, $this->helpdesk());
        $this->later(60);
        $request = $approve($request);
        $this->later(60);
        $item = $fulfill->handle($request->items()->first(), 1, $this->helpdesk());
        $this->at(7, 16);
        app(ReturnCheckoutItem::class)->handle($item->fresh(), $this->helpdesk(), 1, 'สภาพปกติ อุปกรณ์ครบ');

        // Rejected.
        $this->at(3, 11);
        $request = $save->handle(null, ['borrower_name' => 'ผู้รับเหมา สมปอง รับงาน', 'borrower_phone' => '082-000-0000',
            'purpose' => 'ขอยืมไปใช้ที่หน้างานลูกค้า', 'items' => [$loan($own[3], $this->realNow->addDays(14))]], $this->helpdesk());
        $submit->handle($request, $this->helpdesk());
        $this->later(180);
        app(RejectCheckoutRequest::class)->handle($request->fresh(), $this->admin(), 'เครื่องนี้สงวนไว้สำหรับงานภายใน');

        // Cancelled by who asked.
        $this->at(5, 9);
        $request = $save->handle(null, ['borrower_user_id' => $staff->id, 'purpose' => 'ยืมจอเพิ่มสำหรับห้องประชุม',
            'items' => [$loan($own[5], $this->realNow->addDays(2))]], $this->helpdesk());
        $submit->handle($request, $this->helpdesk());
        $this->later(30);
        app(CancelCheckoutRequest::class)->handle($request->fresh(), $this->helpdesk());

        Carbon::setTestNow($this->realNow);
    }

    /**
     * A network of each of three customers and the company's own, each with a subnet and addresses
     * in every state: in use (one taken from its reservation), reserved, excluded, and released
     * (free again, its history kept).
     */
    private function ipam(): void
    {
        $plans = [
            // [customer index (null = the company), network, VLAN, cidr, gateway, days ago]
            [0, 'LAN สำนักงานใหญ่', 10, '192.168.10.0/24', '192.168.10.1', 30],
            [1, 'Server Farm', 20, '10.20.0.0/24', '10.20.0.1', 25],
            [2, 'CCTV', 30, '10.30.0.0/24', '10.30.0.1', 20],
            [null, 'Management (ของบริษัท)', 99, '172.16.99.0/24', '172.16.99.1', 15],
        ];

        foreach ($plans as $i => [$customer, $name, $vlan, $cidr, $gateway, $daysAgo]) {
            $this->at($daysAgo);
            $customerId = $customer === null ? null : $this->customers[$customer]->id;
            $network = app(SaveNetwork::class)->handle(null, [
                'customer_id' => $customerId,
                'site_id' => $customerId ? CustomerSite::query()->where('customer_id', $customerId)->value('id') : null,
                'name' => $name, 'vlan_id' => $vlan, 'description' => 'VLAN '.$vlan.' · '.$name,
            ]);
            $subnet = app(SaveSubnet::class)->handle(null, [
                'network_id' => $network->id, 'cidr' => $cidr, 'gateway' => $gateway, 'description' => 'ซับเน็ตหลักของ '.$name,
            ]);
            $ip = fn (int $offset) => long2ip($subnet->first_int + $offset);

            $reserved = app(ReserveIps::class)->handle($subnet, [$ip(20), $ip(21), $ip(22)], $this->tech1(), 'เตรียมติดตั้งอุปกรณ์ใหม่', 'จองไว้ระหว่างรออุปกรณ์');
            app(SetIpExcluded::class)->handle(app(IpRecord::class)->handle($subnet, $ip(250)), true, $this->tech1(), 'ช่วงของเครื่องพิมพ์ ตั้งค่าเอง');

            $assets = Asset::query()
                ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id), fn ($q) => $q->whereNull('customer_id'))
                ->whereNull('ip_address')->whereIn('status', [Asset::STATUS_IN_USE, Asset::STATUS_SPARE])
                ->orderBy('id')->limit(3)->get();
            foreach ($assets as $j => $asset) {
                $this->later(60 * 24 + $j * 30);
                // The first device takes reserved .20, the others .10 and .11 directly.
                $address = $j === 0 ? $reserved[0] : app(IpRecord::class)->handle($subnet, $ip(9 + $j));
                $address = app(AssignIp::class)->handle($address, [
                    'asset_id' => $asset->id, 'hostname' => strtolower(str_replace(' ', '-', $asset->asset_code)),
                    'mac_address' => sprintf('00:1A:2B:%02X:%02X:%02X', $i, $j, 10 + $i * 3 + $j),
                    'responsible_id' => $this->tech1()->id, 'in_use_since' => now()->toDateString(), 'notes' => 'ติดตั้งโดยทีมช่าง',
                ], $this->tech1());
                // The last device was moved off the network: its address is free again.
                if ($j === 2) {
                    $this->later(60 * 24);
                    app(ReleaseIp::class)->handle($address, $this->tech1(), 'ย้ายเครื่องไปใช้ VLAN อื่น');
                }
            }
            // A reservation given up.
            $this->later(60);
            app(ReleaseIp::class)->handle($reserved[2]->fresh(), $this->tech1(), 'ยกเลิกแผนติดตั้ง');
        }

        Carbon::setTestNow($this->realNow);
    }

    private function labelsAndDocuments(): void
    {
        $runs = [
            [$this->admin(), 'a3_5x6', Asset::query()->where('customer_id', $this->customers[0]->id)->orderBy('id')->pluck('id')->all(), 25],
            [$this->tech1(), 'roll_50x30', Asset::query()->where('customer_id', $this->customers[1]->id)->orderBy('id')->pluck('id')->all(), 12],
            [$this->tech2(), 'a4_3x8', Asset::query()->whereNull('customer_id')->orderBy('id')->pluck('id')->all(), 5],
            [$this->helpdesk(), 'roll_40x25', Asset::query()->where('customer_id', $this->customers[3]->id)->orderBy('id')->limit(4)->pluck('id')->all(), 1],
        ];
        foreach ($runs as [$user, $template, $assetIds, $daysAgo]) {
            $this->at($daysAgo, 14);
            app(RecordLabelPrint::class)->handle($user, $assetIds, $template);
        }

        // Manuals: links only, a file only, both, and a customer's own procedure.
        $this->at(40);
        // The media collection checks the real MIME type, so the file must look like a PDF.
        $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%sample\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $save = app(SaveManual::class);
        $save->handle(null, ['title' => 'คู่มือตั้งค่า Cisco Catalyst 9200', 'category' => 'Network', 'description' => 'ขั้นตอนตั้งค่า VLAN และ uplink',
            'links' => [['label' => 'Cisco Configuration Guide', 'url' => 'https://www.cisco.com/c/en/us/support/switches/catalyst-9200-series-switches/series.html']]], $this->admin());
        $save->handle(null, ['title' => 'ขั้นตอนเปลี่ยนฮาร์ดดิสก์ในเซิร์ฟเวอร์', 'category' => 'Data Center', 'description' => null, 'links' => []],
            $this->admin(), [$pdf('hdd-replacement.pdf')]);
        $save->handle(null, ['title' => 'คู่มือติดตั้ง Windows 11 สำหรับลูกค้า', 'category' => 'PC', 'description' => 'ใช้ image มาตรฐานของบริษัท',
            'links' => [['label' => null, 'url' => 'https://learn.microsoft.com/windows/deployment/']]], $this->admin(), [$pdf('windows11-install.pdf')]);
        $save->handle(null, ['title' => 'ขั้นตอนเข้าห้อง Server ของลูกค้า', 'category' => 'ทั่วไป', 'description' => 'แลกบัตร แจ้งล่วงหน้า และกรอกแบบฟอร์มขอเข้าห้อง',
            'links' => []], $this->admin(), [$pdf('server-room-access.pdf')]);

        Carbon::setTestNow($this->realNow);
    }

    // ---------------------------------------------------------------- Maintenance (PM)

    /**
     * PM checklists in every status (published standard ones, a draft of a new version, an archived
     * old version), a quarterly plan for each running contract, and work orders at every step:
     * draft, scheduled, in progress, sent back, handed in, waiting for approval, approved,
     * published (with a link for the customer), closed and cancelled.
     */
    private function maintenance(): void
    {
        $this->at(45);
        $published = PmChecklist::query()->where('status', PmChecklist::STATUS_PUBLISHED)->orderBy('id')->get();
        if ($published->count() >= 2) {
            // A new version being written.
            app(CreateTemplateVersion::class)->handle($published[0]);
            // A new version published: the old one is archived.
            app(PublishTemplate::class)->handle(app(CreateTemplateVersion::class)->handle($published[1]));
        }

        // Quarterly plans from next month (their rounds are scheduled ahead).
        foreach ($this->runningContracts() as $i => $contract) {
            app(SavePmPlan::class)->handle(null, [
                'contract_id' => $contract->id, 'title' => 'PM รายไตรมาส '.$contract->customer->name,
                'interval_months' => 3, 'starts_on' => $this->realNow->addMonth()->startOfMonth()->toDateString(),
                'assignee_id' => ($i % 2 === 0 ? $this->tech1() : $this->tech2())->id,
            ]);
        }

        $orders = [
            // [customer, title, device prefixes, how far, days ago]
            [0, 'PM ห้อง Server ประจำเดือน', ['SV', 'UPS'], 'draft', 2],
            [1, 'PM อุปกรณ์เครือข่าย อาคารผู้ป่วยนอก', ['SW', 'AP'], 'scheduled', 3],
            [2, 'PM ระบบสำรองไฟ คลังสินค้า', ['UPS', 'SV'], 'in_progress', 1],
            [3, 'PM ศูนย์คอมพิวเตอร์', ['SV', 'SW', 'UPS'], 'revision_required', 6],
            [0, 'PM อุปกรณ์เครือข่าย สำนักงานใหญ่', ['SW', 'AP'], 'submitted', 4],
            [1, 'PM ห้อง Server อาคารผู้ป่วยนอก', ['SV', 'UPS'], 'pending_approval', 7],
            [2, 'PM เครื่องคอมพิวเตอร์ฝ่ายบัญชี', ['PC', 'AP'], 'approved', 10],
            [3, 'PM อุปกรณ์เครือข่าย ศูนย์คอมพิวเตอร์', ['SW', 'AP'], 'published', 14],
            [0, 'PM ระบบสำรองไฟ สำนักงานใหญ่ (รอบก่อน)', ['UPS', 'PC'], 'closed', 40],
            [1, 'PM เพิ่มเติมตามคำขอลูกค้า', ['PC'], 'cancelled', 5],
        ];
        $move = app(MovePmVisit::class);

        foreach ($orders as $k => [$customer, $title, $prefixes, $goal, $daysAgo]) {
            $this->at($daysAgo + 3, 9);
            $technician = $k % 2 === 0 ? $this->tech1() : $this->tech2();
            $contract = $this->runningContracts()->firstWhere('customer_id', $this->customers[$customer]->id);
            $visit = app(CreatePmWorkOrder::class)->handle([
                'customer_id' => $this->customers[$customer]->id, 'contract_id' => $contract?->id, 'title' => $title,
                'due_on' => $this->realNow->addDays(14 - $daysAgo)->toDateString(),
                'scheduled_on' => $this->realNow->subDays($daysAgo)->toDateString(),
                'assignee_id' => $technician->id, 'draft' => $goal === 'draft',
                'asset_ids' => collect($prefixes)->map(fn (string $prefix) => $this->device($customer, $prefix)->id)->all(),
            ], $this->helpdesk());

            if (in_array($goal, ['draft', 'scheduled'], true)) {
                continue;
            }
            if ($goal === 'cancelled') {
                $move->handle($visit, 'cancel', $this->helpdesk(), 'ลูกค้าขอเลื่อนไปรวมกับรอบ PM ปกติ');

                continue;
            }

            $this->at($daysAgo, 9, 30);
            $visit = app(StartPmVisit::class)->handle($visit, $technician);
            app(MarkPmVisitArrival::class)->handle($visit->fresh(), $technician);
            $this->recordItems($visit->fresh(), $technician, $goal === 'in_progress' ? 1 : null, $goal === 'submitted');
            if ($goal === 'in_progress') {
                continue;
            }
            app(MarkPmVisitDeparture::class)->handle($visit->fresh(), $technician);

            // The customer signs on the technician's device for the finished ones (that locks the
            // results); a work order that is sent back is handed in unsigned.
            $signed = in_array($goal, ['approved', 'published', 'closed'], true);
            $this->later(30);
            $visit = app(SubmitPmVisit::class)->handle($visit->fresh(), $technician, [
                'summary' => $goal === 'submitted' ? 'พบพัดลมเซิร์ฟเวอร์มีเสียงดัง แนะนำเปลี่ยนในรอบถัดไป' : 'ทำ PM ครบทุกเครื่องตามแผน อุปกรณ์ทำงานปกติ',
                'technician_signature' => $this->signature($k + 3),
            ] + ($signed ? [
                'customer_signature' => $this->signature($k + 11),
                'customer_signer_name' => self::CUSTOMERS[$customer][2],
                'customer_signer_position' => 'เจ้าหน้าที่ไอที',
            ] : []));

            $steps = match ($goal) {
                'revision_required' => [['return', $this->helpdesk(), 'ขอรูปถ่ายหน้าตู้ Rack และค่าอุณหภูมิห้องเพิ่มเติม']],
                'submitted' => [],
                'pending_approval' => [['review', $this->helpdesk(), null]],
                'approved' => [['review', $this->helpdesk(), null], ['approve', $this->admin(), 'ตรวจสอบรายงานแล้ว']],
                'published' => [['review', $this->helpdesk(), null], ['approve', $this->admin(), null], ['publish', $this->admin(), null]],
                'closed' => [['review', $this->helpdesk(), null], ['approve', $this->admin(), null], ['publish', $this->admin(), null], ['close', $this->admin(), null]],
            };
            foreach ($steps as [$action, $actor, $note]) {
                $this->later(120);
                $visit = $visit->fresh();
                // A company without a review step skips straight to approval.
                if ($action === 'review' && $visit->status !== PmVisit::STATUS_SUBMITTED) {
                    continue;
                }
                $visit = $move->handle($visit, $action, $actor, $note);
            }

            if ($goal === 'published') {
                $this->later(30);
                app(CreatePmReportShare::class)->handle($visit->fresh(), [
                    'recipient_name' => self::CUSTOMERS[$customer][2], 'days' => 30, 'allow_download' => true,
                ], $this->admin());
            }
        }

        Carbon::setTestNow($this->realNow);
    }

    /**
     * Records the work order's unchecked equipment (only the first $limit): passing answers to
     * every question; the first one failed when $failOne; not inspected when a checklist asks for
     * something the sample cannot give (a photo).
     */
    private function recordItems(PmVisit $visit, User $technician, ?int $limit = null, bool $failOne = false): void
    {
        $items = $visit->items()->where('result', PmVisitItem::RESULT_PENDING)->orderBy('id')->get();
        foreach ($items->take($limit ?? $items->count())->values() as $i => $item) {
            $this->later(25);
            $asset = Asset::query()->findOrFail($item->asset_id);
            $answers = $this->answers($item->checklist ?? [], $asset);
            try {
                app(RecordPmItem::class)->handle($item, $technician, $failOne && $i === 0
                    ? ['result' => PmVisitItem::RESULT_FAILED, 'answers' => $answers, 'note' => 'พัดลมระบายความร้อนมีเสียงดัง']
                    : ['result' => PmVisitItem::RESULT_PASSED, 'answers' => $answers]);
            } catch (ValidationException) {
                app(RecordPmItem::class)->handle($item->fresh(), $technician, [
                    'result' => PmVisitItem::RESULT_NOT_INSPECTED, 'not_inspected_reason' => 'ลูกค้าใช้งานเครื่องอยู่ ไม่สามารถปิดเครื่องตรวจได้',
                ]);
            }
        }
    }

    // ---------------------------------------------------------------- Room access

    /**
     * Four server rooms (rules, caretakers, approval steps) and requests in every status: draft
     * (sent back for more), pending, approved, inside now, exited (one summed up, one not yet),
     * rejected, cancelled and overdue (approved, nobody went in).
     */
    private function roomAccess(): void
    {
        $admin = $this->admin();
        $approver = $this->helpdesk()->can('room-access.approve') ? $this->helpdesk() : $admin;

        $this->at(100);
        app(SaveRoomAccessSettings::class)->handle($this->context->tenant(), [
            'company_terms' => [
                'ติดบัตรพนักงานตลอดเวลาที่อยู่ในห้อง',
                'ห้ามถ่ายภาพหรือบันทึกวิดีโอภายในห้องโดยไม่ได้รับอนุญาต',
                'แจ้งผู้ดูแลห้องทันทีเมื่อพบความผิดปกติ',
            ],
            'id_retention_days' => 90,
        ], $admin);

        $site = fn (int $i) => CustomerSite::query()->where('customer_id', $this->customers[$i]->id)->value('id');
        $room = fn (int $i, array $data, array $managers, ?int $approverId) => app(SaveServerRoom::class)->handle(null, $data + [
            'customer_id' => $this->customers[$i]->id, 'site_id' => $site($i),
            'requires_id_number' => false, 'missing_rules' => ServerRoom::MISSING_BLOCK, 'accept_mode' => ServerRoom::ACCEPT_EVERY_REQUEST,
            'accept_on_enter' => false, 'entrants_accept_self' => false, 'guard_link' => false,
            'freeze_periods' => [], 'guard_contacts' => [], 'is_active' => true, 'notes' => null,
        ], $managers, $approverId, $admin);

        // Data center: ID cards, rules accepted again at the door, a guard link.
        $roomA = $room(0, [
            'name' => 'ห้อง Data Center ชั้น 5', 'location' => 'อาคารสำนักงานใหญ่ ชั้น 5 ห้อง 501 (แลกบัตรที่ป้อม รปภ. ชั้น 1)',
            'requires_id_number' => true, 'accept_on_enter' => true, 'guard_link' => true,
            'guard_contacts' => [['name' => 'รปภ. สมศักดิ์ ใจดี', 'phone' => '081-234-5678', 'email' => null]],
            'notes' => 'ต้องแลกบัตรประชาชนก่อนเข้า',
        ], [$this->tech1()->id, $this->helpdesk()->id], null);
        // Server room: two approval steps, accept once per version, a freeze period ahead.
        $roomB = $room(1, [
            'name' => 'ห้องเซิร์ฟเวอร์ ชั้น 2', 'location' => 'อาคารผู้ป่วยนอก ชั้น 2 ข้างห้องเวชระเบียน',
            'accept_mode' => ServerRoom::ACCEPT_ONCE_PER_VERSION,
            'freeze_periods' => [[
                'from' => $this->realNow->addDays(20)->setTime(22, 0)->format('Y-m-d H:i'),
                'to' => $this->realNow->addDays(21)->setTime(6, 0)->format('Y-m-d H:i'),
                'reason' => 'ปิดปรับปรุงระบบไฟฟ้าประจำปี',
            ]],
            'guard_contacts' => [['name' => 'คุณวิภา (ผู้ดูแลห้อง)', 'phone' => '02-123-4567', 'email' => null]],
        ], [$this->tech2()->id], $approver->id);
        RoomApprovalStep::create(['server_room_id' => $roomB->id, 'position' => 2, 'side' => RoomApprovalStep::SIDE_COMPANY, 'approver_user_id' => $admin->id]);
        // Network core and a computer centre: no rules of their own, the company's terms show.
        $roomC = $room(2, ['name' => 'ห้อง Network Core', 'location' => 'อาคารคลังสินค้า 1 ชั้น 1', 'missing_rules' => ServerRoom::MISSING_COMPANY_TERMS],
            [$this->tech1()->id], $admin->id);
        $roomD = $room(3, ['name' => 'ห้องศูนย์คอมพิวเตอร์', 'location' => 'อาคารเรียนรวม ชั้น 3', 'missing_rules' => ServerRoom::MISSING_COMPANY_TERMS],
            [$this->tech2()->id], $admin->id);

        // Rule versions in effect before the backdated requests (room A has an older v1).
        $versions = [
            [$roomA, 95, ['ห้ามนำอาหารและเครื่องดื่มเข้าห้อง', 'ต้องมีเจ้าหน้าที่ของลูกค้าอยู่ด้วยตลอดเวลา'], 'ฝ่ายอาคารสถานที่'],
            [$roomA, 30, ['ห้ามนำอาหารและเครื่องดื่มเข้าห้อง', 'ต้องมีเจ้าหน้าที่ของลูกค้าอยู่ด้วยตลอดเวลา', 'สวมรองเท้าหุ้มส้นและห้ามใช้สเปรย์ทุกชนิด'], 'ฝ่ายอาคารสถานที่'],
            [$roomB, 90, ['แจ้งล่วงหน้าอย่างน้อย 1 วันทำการ', 'ห้ามปิดเครื่องที่ติดป้าย "ระบบผู้ป่วย" โดยไม่ได้รับอนุญาต'], 'ฝ่ายเทคโนโลยีสารสนเทศ'],
        ];
        foreach ($versions as [$target, $daysAgo, $summary, $from]) {
            $this->at($daysAgo, 9);
            app(PublishRoomRules::class)->handle($target, [
                'summary' => $summary, 'effective_on' => now()->toDateString(), 'received_from' => $from,
                'received_on' => now()->subDays(3)->toDateString(), 'note' => null,
            ], null, $admin);
        }

        $company = self::NAME;
        $tech1 = $this->tech1();
        $tech2 = $this->tech2();
        $device = ['ip' => '127.0.0.1', 'user_agent' => 'SampleCompanySeeder'];
        $now = $this->realNow;

        // Exited and summed up, a week ago in room A.
        $day = $now->subDays(7);
        Carbon::setTestNow($day->subDay()->setTime(15, 0));
        $request = $this->roomRequest($roomA, $tech1, $day->setTime(10, 0), $day->setTime(12, 0), 'เปลี่ยนฮาร์ดดิสก์ที่เสียใน RAID ของเซิร์ฟเวอร์ฐานข้อมูล', [
            ['name' => $tech1->name, 'company' => $company, 'phone' => '081-000-0002', 'id_number' => '1100700123456'],
            ['name' => 'สมชาย ช่วยงาน', 'company' => $company, 'phone' => '082-333-4444', 'id_number' => '3101500654321'],
        ], [
            ['name' => 'HDD SAS 1.2TB 10K (ลูกใหม่)', 'serial_number' => 'WXA1B2C3', 'quantity' => 1, 'direction' => 'in'],
            ['name' => 'HDD SAS 1.2TB 10K (ลูกที่เสีย)', 'serial_number' => 'WXZ9Y8X7', 'quantity' => 1, 'direction' => 'out'],
        ]);
        $request = $this->approveRoomRequest($request, $approver);
        Carbon::setTestNow($day->setTime(9, 50));
        app(RecordRoomEntry::class)->handle($request, $tech1, null, ['accept' => true] + $device);
        Carbon::setTestNow($day->setTime(11, 40));
        app(RecordRoomExit::class)->handle($request, null, 'สมศักดิ์ ใจดี');
        Carbon::setTestNow($day->setTime(13, 0));
        app(FinishRoomVisit::class)->handle($request->fresh(), $tech1, 'เปลี่ยน HDD ช่อง 3 แล้ว RAID rebuild เสร็จ สถานะปกติ', true);

        // Exited three days ago in room B (two approval steps, left late), not summed up yet.
        $day = $now->subDays(3);
        Carbon::setTestNow($day->subDays(2)->setTime(10, 0));
        $request = $this->roomRequest($roomB, $tech2, $day->setTime(13, 0), $day->setTime(16, 0), 'PM ประจำไตรมาส ตรวจ UPS และทำความสะอาดตู้ Rack', [
            ['name' => $tech2->name, 'company' => $company, 'phone' => '081-000-0003'],
        ], [['name' => 'เครื่องเป่าลม', 'serial_number' => null, 'quantity' => 1, 'direction' => 'in']]);
        $request = $this->approveRoomRequest($request, $approver, $admin);
        Carbon::setTestNow($day->setTime(13, 5));
        app(RecordRoomEntry::class)->handle($request, $tech2, null, $device);
        Carbon::setTestNow($day->setTime(16, 20));
        app(RecordRoomExit::class)->handle($request, $tech2);

        // Approved, nobody went in before the end: overdue.
        $day = $now->subDays(2);
        Carbon::setTestNow($day->subDays(2)->setTime(9, 0));
        $request = $this->roomRequest($roomD, $tech2, $day->setTime(9, 0), $day->setTime(11, 0), 'ตรวจสอบเครื่องสำรองไฟห้องศูนย์คอมพิวเตอร์', [
            ['name' => $tech2->name, 'company' => $company, 'phone' => '081-000-0003'],
        ]);
        $this->approveRoomRequest($request, $admin);

        // Inside now in room C.
        Carbon::setTestNow($now->subDay()->setTime(16, 0));
        $request = $this->roomRequest($roomC, $tech1, $now->subMinutes(30), $now->addHours(2), 'ตรวจสอบสวิตช์ Core ที่แจ้งเตือนอุณหภูมิสูง', [
            ['name' => $tech1->name, 'company' => $company, 'phone' => '081-000-0002'],
        ]);
        $request = $this->approveRoomRequest($request, $admin);
        Carbon::setTestNow($now->subMinutes(20));
        app(RecordRoomEntry::class)->handle($request, $tech1, null, $device);

        // Approved for three days from now in room B.
        Carbon::setTestNow($now->subHours(5));
        $start = $now->addDays(3)->setTime(13, 0);
        $request = $this->roomRequest($roomB, $tech2, $start, $start->setTime(16, 0), 'ติดตั้งสวิตช์ตัวใหม่แทนตัวเดิมที่หมดอายุ', [
            ['name' => $tech2->name, 'company' => $company, 'phone' => '081-000-0003'],
            ['name' => 'วิศวกรผู้จำหน่าย', 'company' => 'บริษัท ผู้จำหน่ายอุปกรณ์ จำกัด', 'phone' => null],
        ], [
            ['name' => 'Cisco Catalyst 9300-48P', 'serial_number' => 'FOC2501X0AB', 'quantity' => 1, 'direction' => 'in'],
            ['name' => 'Cisco Catalyst 2960 (ตัวเดิม)', 'serial_number' => 'FOC1702Y1CD', 'quantity' => 1, 'direction' => 'out'],
        ]);
        $this->approveRoomRequest($request, $approver, $admin);

        // Waiting for the admin.
        Carbon::setTestNow($now->subHours(2));
        $start = $now->addDays(5)->setTime(9, 0);
        $this->roomRequest($roomC, $tech1, $start, $start->setTime(11, 0), 'อัปเกรด firmware สวิตช์ Core ตามประกาศช่องโหว่ของผู้ผลิต', [
            ['name' => $tech1->name, 'company' => $company, 'phone' => '081-000-0002'],
        ]);

        // Sent back to the requester as a draft, asking for more.
        Carbon::setTestNow($now->subHours(6));
        $start = $now->addDays(6)->setTime(13, 0);
        $request = $this->roomRequest($roomD, $tech2, $start, $start->setTime(15, 0), 'เดินสาย LAN เพิ่มในห้องศูนย์คอมพิวเตอร์', [
            ['name' => $tech2->name, 'company' => $company, 'phone' => '081-000-0003'],
        ]);
        $this->later(45);
        app(DecideRoomAccessRequest::class)->handle($request, 'ask', $admin, 'ขอรายชื่อผู้รับเหมาที่จะเข้าไปด้วย');

        // Rejected.
        Carbon::setTestNow($now->subDays(4)->setTime(10, 0));
        $start = $now->addDays(1)->setTime(22, 0);
        $request = $this->roomRequest($roomD, $tech2, $start, $start->addHours(3), 'ย้ายตู้ Rack ช่วงกลางคืน', [
            ['name' => $tech2->name, 'company' => $company, 'phone' => '081-000-0003'],
        ]);
        $this->later(45);
        app(DecideRoomAccessRequest::class)->handle($request, 'reject', $admin, 'ไม่อนุญาตให้เข้าห้องนอกเวลาทำการ');

        // Approved, then cancelled.
        Carbon::setTestNow($now->subDays(1)->setTime(9, 0));
        $start = $now->addDays(8)->setTime(10, 0);
        $request = $this->roomRequest($roomA, $tech1, $start, $start->setTime(12, 0), 'ตรวจเช็คแบตเตอรี่ UPS', [
            ['name' => $tech1->name, 'company' => $company, 'phone' => '081-000-0002', 'id_number' => '1100700123456'],
        ]);
        $request = $this->approveRoomRequest($request, $approver);
        $this->later(120);
        app(CancelRoomAccessRequest::class)->handle($request, $tech1, 'ลูกค้าขอเลื่อนไปรวมกับรอบ PM');

        Carbon::setTestNow($now);
        app(MarkOverdueRoomRequests::class)->handle();
    }

    /** Saves a request and sends it for approval, accepting the rules in effect. */
    private function roomRequest(ServerRoom $room, User $requester, CarbonImmutable $start, CarbonImmutable $end, string $purpose, array $people, array $items = []): RoomAccessRequest
    {
        $request = app(SaveRoomAccessRequest::class)->handle(null, [
            'server_room_id' => $room->id,
            'planned_start' => $start->format('Y-m-d H:i'),
            'planned_end' => $end->format('Y-m-d H:i'),
            'purpose' => $purpose,
            'recurrence' => null,
            'ticket_id' => Ticket::query()->where('customer_id', $room->customer_id)->latest('id')->value('id'),
            'contract_id' => Contract::query()->where('customer_id', $room->customer_id)->where('status', Contract::STATUS_ACTIVE)->latest('ends_on')->value('id'),
            'people' => $people,
            'items' => $items,
        ], $requester);
        $rules = app(RoomRulesForRequest::class)->handle($room, $requester, $request);

        return app(SubmitRoomAccessRequest::class)->handle($request, $requester, [
            'accept' => true, 'version_id' => $rules['version_id'], 'ip' => '127.0.0.1', 'user_agent' => 'SampleCompanySeeder',
        ]);
    }

    /** Each approver decides their step, in order, 45 minutes apart. */
    private function approveRoomRequest(RoomAccessRequest $request, User ...$approvers): RoomAccessRequest
    {
        foreach ($approvers as $approver) {
            $this->later(45);
            $request = app(DecideRoomAccessRequest::class)->handle($request, 'approve', $approver);
        }

        return $request;
    }

    // ---------------------------------------------------------------- Survey

    /** Closing a ticket asked its customer how it went: three answered a day later, the newest still waiting. */
    private function surveys(): void
    {
        $answers = [[5, 'ช่างมาเร็วและอธิบายปัญหาชัดเจน', 'คุณสมศรี'], [4, 'แก้ไขได้เรียบร้อย แต่รออะไหล่นานไปหน่อย', 'คุณวิภา'], [2, 'ต้องแจ้งซ้ำสองครั้งกว่าจะมีคนติดต่อกลับ', null]];
        $surveys = TicketSurvey::query()->orderBy('created_at')->get();
        foreach ($surveys->slice(0, -1)->values() as $i => $survey) {
            [$score, $comment, $name] = $answers[$i % count($answers)];
            Carbon::setTestNow($survey->created_at->copy()->addDay());
            app(AnswerTicketSurvey::class)->handle($survey, ['score' => $score, 'comment' => $comment, 'name' => $name]);
        }

        Carbon::setTestNow($this->realNow);
    }
}

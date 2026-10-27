<?php

namespace Database\Seeders;

use App\Modules\Asset\Actions\ApproveCheckoutRequest;
use App\Modules\Asset\Actions\AssignIp;
use App\Modules\Asset\Actions\FulfillCheckoutItem;
use App\Modules\Asset\Actions\IpRecord;
use App\Modules\Asset\Actions\RejectCheckoutRequest;
use App\Modules\Asset\Actions\ReserveIps;
use App\Modules\Asset\Actions\ReturnCheckoutItem;
use App\Modules\Asset\Actions\SaveAssetCategory;
use App\Modules\Asset\Actions\SaveCheckoutRequest;
use App\Modules\Asset\Actions\SaveNetwork;
use App\Modules\Asset\Actions\SaveSubnet;
use App\Modules\Asset\Actions\SubmitCheckoutRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Models\Network;
use App\Modules\Contract\Actions\SaveSite;
use App\Modules\Contract\Actions\SyncContractMembers;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\ContractMember;
use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Models\CustomerSite;
use App\Modules\Document\Actions\SaveManual;
use App\Modules\Document\Models\Manual;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\IssuePartToTicket;
use App\Modules\Inventory\Actions\MovePurchaseRequest;
use App\Modules\Inventory\Actions\ReceivePartUnits;
use App\Modules\Inventory\Actions\ReceivePurchase;
use App\Modules\Inventory\Actions\ReturnPartUnits;
use App\Modules\Inventory\Actions\SavePart;
use App\Modules\Inventory\Actions\SavePurchaseRequest;
use App\Modules\Inventory\Actions\StartTrackingSerials;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Labeling\Actions\RecordLabelPrint;
use App\Modules\Labeling\Models\AssetLabelPrint;
use App\Modules\Maintenance\Actions\AddStandardChecklists;
use App\Modules\Maintenance\Actions\CreatePmReportShare;
use App\Modules\Maintenance\Actions\CreatePmWorkOrder;
use App\Modules\Maintenance\Actions\MovePmVisit;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Actions\SubmitPmVisit;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Platform\Actions\DecideTenantShare;
use App\Modules\Platform\Actions\SaveStaffPool;
use App\Modules\Platform\Actions\SaveTenantShare;
use App\Modules\Platform\CrossTenant\ForwardedTickets;
use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\RoomAccess\Actions\DecideRoomAccessRequest;
use App\Modules\RoomAccess\Actions\FinishRoomVisit;
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
use App\Modules\Service\Actions\CreateFieldLink;
use App\Modules\Service\Actions\SavePersonalEvent;
use App\Modules\Service\Actions\SaveRepairPresets;
use App\Modules\Service\Actions\SubmitFieldReport;
use App\Modules\Service\Jobs\SendTicketNotification;
use App\Modules\Service\Models\PersonalEvent;
use App\Modules\Service\Models\RepairPreset;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketFieldLink;
use App\Modules\Service\Models\TicketRemovedPart;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Three examples of everything DemoSeeder leaves empty, in each demo company (itsol, netpro):
 * customer sites, project teams, repair presets, calendar events, field links, manuals,
 * part categories, serial-tracked parts, purchase requests, removed parts, IP networks,
 * checkout requests, label prints, server rooms and room access requests; and between the two
 * companies: tenant shares, staff pools and forwarded tickets.
 *
 * Runs after DemoSeeder, or on its own against a database DemoSeeder already filled:
 *   php artisan db:seed --class=DemoExtrasSeeder
 * Each part is skipped when its table already has data, so running it twice adds nothing.
 */
class DemoExtrasSeeder extends Seeder
{
    private const TENANTS = ['itsol', 'netpro'];

    public function __construct(private TenantContext $context) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoExtrasSeeder does not run in production.');

            return;
        }

        fake()->seed(2569);
        // Demo data must not e-mail or message anyone when the queue worker starts.
        Bus::fake([SendTicketNotification::class, DeliverAlert::class, SendGuardLinkMail::class]);

        DB::transaction(function () {
            foreach (self::TENANTS as $subdomain) {
                $tenant = Tenant::where('subdomain', $subdomain)->first();
                if ($tenant === null) {
                    $this->command?->warn("Tenant {$subdomain} not found: run DemoSeeder first.");

                    continue;
                }
                $this->context->run($tenant, fn () => $this->seedTenant($subdomain));
            }

            $this->platformSharing();
        });

        Carbon::setTestNow();
        $this->command?->info('Demo extras ready.');
    }

    private function seedTenant(string $subdomain): void
    {
        $admin = $this->staff("admin@{$subdomain}.test");
        $helpdesk = $this->staff("helpdesk@{$subdomain}.test");
        $technicians = collect([$this->staff("tech1@{$subdomain}.test"), $this->staff("tech2@{$subdomain}.test")]);
        $staff = $this->staff("user@{$subdomain}.test");
        $customers = Customer::orderBy('id')->get();

        $this->once(CustomerSite::class, fn () => $this->sites($customers));
        $this->once(ContractMember::class, fn () => $this->projectTeams($admin, $helpdesk, $technicians));
        $this->once(RepairPreset::class, fn () => $this->repairPresets());
        $this->once(PersonalEvent::class, fn () => $this->personalEvents($helpdesk, $technicians));

        // Parts sorted into categories once (parts share the asset categories).
        if (! Part::query()->whereNotNull('asset_category_id')->exists()) {
            $categories = $this->partCategories();
            $serialParts = $this->serialParts($admin, $categories);
            $this->once(PurchaseRequest::class, fn () => $this->purchaseRequests($admin, $technicians[0], $serialParts['nvme']));
            Carbon::setTestNow();
        }
        $this->once(TicketRemovedPart::class, fn () => $this->removedParts());

        $this->once(Network::class, fn () => $this->ipam($technicians[0]));
        $this->once(CheckoutRequest::class, fn () => $this->checkouts($admin, $helpdesk, $technicians[0], $staff));
        $this->once(AssetLabelPrint::class, fn () => $this->labelPrints($admin, $technicians));
        $this->once(TicketFieldLink::class, fn () => $this->fieldLinks($helpdesk));
        $this->once(Manual::class, fn () => $this->manuals($admin));
        $this->once(ServerRoom::class, fn () => $this->roomAccess($admin, $helpdesk, $technicians[0], $technicians[1], $customers));
        $this->once(PmReportShare::class, fn () => $this->pmWorkflow($admin, $helpdesk, $customers));
    }

    /** Runs $seed only while the model's table (of the current company) is still empty. */
    private function once(string $model, callable $seed): void
    {
        if (! $model::query()->exists()) {
            $seed();
            Carbon::setTestNow();
        }
    }

    // ---------------------------------------------------------------- Contract / Service / Document

    /** Sites of the first three customers. */
    private function sites(Collection $customers): void
    {
        $sites = [
            ['name' => 'สำนักงานใหญ่', 'notes' => 'ติดต่อ รปภ. ชั้น 1 ก่อนขึ้นอาคาร'],
            ['name' => 'สาขาบางนา', 'notes' => null],
            ['name' => 'ศูนย์ข้อมูล (Data Center)', 'notes' => 'ต้องแลกบัตรและแจ้งล่วงหน้า 1 วัน'],
        ];

        foreach ($customers->take(3)->values() as $i => $customer) {
            app(SaveSite::class)->handle($customer, null, $sites[$i] + ['address' => fake()->address()]);
        }
    }

    /** Project teams of three running contracts. */
    private function projectTeams(User $admin, User $helpdesk, Collection $technicians): void
    {
        $teams = [
            [$admin->id, $technicians[0]->id],
            [$helpdesk->id, $technicians[1]->id],
            [$helpdesk->id, $technicians[0]->id, $technicians[1]->id],
        ];

        Contract::where('status', Contract::STATUS_ACTIVE)
            ->where('ends_on', '>=', now()->toDateString())
            ->orderBy('id')->limit(3)->get()
            ->each(fn (Contract $contract, int $i) => app(SyncContractMembers::class)->handle($contract, $teams[$i]));
    }

    /** Symptom and fix chips of the repair report. */
    private function repairPresets(): void
    {
        app(SaveRepairPresets::class)->handle([
            'symptom' => ['เปิดเครื่องไม่ติด', 'เชื่อมต่อเครือข่ายไม่ได้', 'กระดาษติด'],
            'solution' => ['เปลี่ยนสายสัญญาณ', 'รีสตาร์ทอุปกรณ์', 'ทำความสะอาดลูกยาง'],
        ]);
    }

    /** Appointments on people's own "my work" calendars. */
    private function personalEvents(User $helpdesk, Collection $technicians): void
    {
        $day = now()->addDays(2)->startOfDay();
        $save = app(SavePersonalEvent::class);

        $save->handle($technicians[0], null, [
            'title' => 'อบรม Cisco CCNA', 'starts_at' => $day->copy()->setTime(9, 0)->toDateTimeString(),
            'ends_at' => $day->copy()->setTime(16, 0)->toDateTimeString(), 'all_day' => false, 'notes' => 'ห้องประชุมชั้น 3',
        ]);
        $save->handle($technicians[1], null, [
            'title' => 'ลาพักร้อน', 'starts_at' => $day->copy()->addDays(3)->toDateTimeString(),
            'ends_at' => null, 'all_day' => true, 'notes' => null,
        ]);
        $save->handle($helpdesk, null, [
            'title' => 'ประชุมทีมประจำสัปดาห์', 'starts_at' => $day->copy()->addDay()->setTime(10, 0)->toDateTimeString(),
            'ends_at' => $day->copy()->addDay()->setTime(11, 0)->toDateTimeString(), 'all_day' => false, 'notes' => null,
        ]);
    }

    /** Links to work on open tickets without an account: two outside technicians (one reported back), one sign-off. */
    private function fieldLinks(User $helpdesk): void
    {
        $tickets = Ticket::whereIn('status', [Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD, Ticket::STATUS_ASSIGNED])
            ->orderBy('id')->limit(3)->get();
        $holders = [
            [TicketFieldLink::MODE_WORK, 'สมชาย ช่างนอก', 'บริษัท ซับคอนแทรค จำกัด', '0812345678'],
            [TicketFieldLink::MODE_WORK, 'วิชัย ช่างนอก', 'หจก. เน็ตเวิร์คเซอร์วิส', '0898765432'],
            [TicketFieldLink::MODE_SIGN, 'คุณสุดา (ลูกค้า)', null, null],
        ];

        foreach ($tickets as $i => $ticket) {
            [$mode, $name, $company, $phone] = $holders[$i];
            $link = app(CreateFieldLink::class)->handle($ticket, [
                'mode' => $mode, 'holder_name' => $name, 'holder_company' => $company, 'holder_phone' => $phone, 'days' => 7,
            ], $helpdesk);

            if ($i === 0) {
                app(SubmitFieldReport::class)->handle($link, [
                    'symptoms' => ['เชื่อมต่อเครือข่ายไม่ได้'],
                    'solutions' => ['เปลี่ยนสายสัญญาณ'],
                    'parts' => 'สาย UTP Cat6 3 เมตร 1 เส้น',
                    'note' => 'ทดสอบแล้วใช้งานได้ปกติ',
                    'signer_name' => null,
                    'signature' => null,
                ]);
            }
        }
    }

    /** Company manuals: links only, a file only, and both. */
    private function manuals(User $admin): void
    {
        // The media collection checks the real MIME type, so the file must look like a PDF.
        $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%demo\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $save = app(SaveManual::class);

        $save->handle(null, [
            'title' => 'คู่มือตั้งค่า Cisco Catalyst 9200', 'category' => 'Network',
            'description' => 'ขั้นตอนตั้งค่า VLAN และ uplink',
            'links' => [['label' => 'Cisco Configuration Guide', 'url' => 'https://www.cisco.com/c/en/us/support/switches/catalyst-9200-series-switches/series.html']],
        ], $admin);
        $save->handle(null, [
            'title' => 'ขั้นตอนเปลี่ยนฮาร์ดดิสก์ในเซิร์ฟเวอร์', 'category' => 'Data Center', 'description' => null, 'links' => [],
        ], $admin, [$pdf('hdd-replacement.pdf')]);
        $save->handle(null, [
            'title' => 'คู่มือติดตั้ง Windows 11 สำหรับลูกค้า', 'category' => 'PC',
            'description' => 'ใช้ image มาตรฐานของบริษัท',
            'links' => [['label' => null, 'url' => 'https://learn.microsoft.com/windows/deployment/']],
        ], $admin, [$pdf('windows11-install.pdf')]);
    }

    // ---------------------------------------------------------------- Inventory

    /**
     * Three categories for parts (the company's own when it has one of the name), and the existing
     * parts sorted into them.
     *
     * @return array<string, AssetCategory>
     */
    private function partCategories(): array
    {
        $category = fn (string $name, bool $serial) => AssetCategory::query()->where('name', $name)->first()
            ?? app(SaveAssetCategory::class)->handle(null, ['name' => $name, 'requires_serial' => $serial]);
        $categories = [
            'hardware' => $category('อะไหล่คอมพิวเตอร์และเซิร์ฟเวอร์', true),
            'network' => $category('อุปกรณ์เครือข่าย', true),
            'supplies' => $category('วัสดุสิ้นเปลือง', false),
        ];

        $map = [
            'RAM-DDR4-8G' => 'hardware', 'RAM-DDR4-16G' => 'hardware', 'SSD-SATA-500' => 'hardware',
            'HDD-SAS-1T2' => 'hardware', 'PSU-ATX-550' => 'hardware',
            'FAN-SW-C9200' => 'network', 'SFP-10G-SR' => 'network', 'CAB-UTP-C6-3M' => 'network',
            'TONER-HP-59A' => 'supplies', 'THERMAL-PASTE' => 'supplies',
        ];
        foreach (Part::whereIn('code', array_keys($map))->get() as $part) {
            // An existing part: code is required and a missing min_qty would become 0.
            app(SavePart::class)->handle($part, [
                'code' => $part->code, 'name' => $part->name, 'unit' => $part->unit, 'min_qty' => $part->min_qty,
                'asset_category_id' => $categories[$map[$part->code]]->id,
            ]);
        }

        return $categories;
    }

    /**
     * Three parts followed by serial number: two new ones received by serial, and an existing part
     * switched over. One piece of each goes into a ticket's device, and the SFP comes back.
     *
     * @param  array<string, AssetCategory>  $categories
     * @return array{nvme: Part, sfp: Part, hdd: ?Part}
     */
    private function serialParts(User $admin, array $categories): array
    {
        $realNow = now()->toImmutable();
        $receiveUnits = app(ReceivePartUnits::class);
        // range(1, 0) is [1, 0] in PHP: never call it with a count of 0.
        $serials = fn (string $prefix, int $count) => $count > 0
            ? array_map(fn (int $i) => $prefix.sprintf('%05d', $i), range(1, $count))
            : [];

        Carbon::setTestNow($realNow->subDays(10)->setTime(10, 0));

        // Tracked because its category says so.
        $nvme = app(SavePart::class)->handle(null, [
            'code' => 'SSD-NVME-1T', 'name' => 'SSD NVMe 1TB', 'brand' => 'Samsung', 'part_number' => 'MZ-V8P1T0BW',
            'unit' => 'ชิ้น', 'min_qty' => 2, 'unit_cost' => 3290 * 100, 'asset_category_id' => $categories['hardware']->id,
        ]);
        $receiveUnits->handle($nvme, $serials('S6WRNJ0T4', 5), $admin, [
            'unit_cost' => 3290 * 100, 'reference' => 'DN-6910021', 'note' => 'รับเข้าตามใบส่งของ',
            'supplier' => 'บริษัท ไอทีซัพพลาย จำกัด', 'warranty_until' => $realNow->addYears(5)->toDateString(),
        ]);

        // Tracked because it was asked for.
        $sfp = app(SavePart::class)->handle(null, [
            'code' => 'SFP-1G-LX', 'name' => 'SFP 1G LX module', 'brand' => 'Cisco', 'part_number' => 'GLC-LH-SMD',
            'unit' => 'ชิ้น', 'min_qty' => 2, 'unit_cost' => 2100 * 100, 'asset_category_id' => $categories['network']->id,
            'track_serial' => true,
        ]);
        $receiveUnits->handle($sfp, $serials('FNS2231', 4), $admin, [
            'unit_cost' => 2100 * 100, 'reference' => 'DN-6910022', 'supplier' => 'บริษัท เน็ตดิสทริบิวชั่น จำกัด',
            'warranty_until' => $realNow->addYear()->toDateString(),
        ]);

        // An existing part switched over: one serial for every piece on hand, then two more received.
        $hdd = Part::firstWhere('code', 'HDD-SAS-1T2');
        if ($hdd !== null && ! $hdd->track_serial) {
            $hdd = app(StartTrackingSerials::class)->handle($hdd, [], $serials('WFK0', (int) $hdd->qty_on_hand), $admin);
            $receiveUnits->handle($hdd, $serials('WFK1', 2), $admin, ['unit_cost' => 7900 * 100, 'reference' => 'DN-6910023']);
        }

        $tickets = Ticket::whereIn('status', [Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD, Ticket::STATUS_RESOLVED])
            ->whereNotNull('assignee_id')->whereNotNull('asset_id')->latest('id')->limit(3)->get();
        foreach ([$nvme, $sfp, $hdd] as $i => $part) {
            $ticket = $tickets[$i] ?? null;
            $unitId = $part ? PartUnit::where('part_id', $part->id)->where('status', PartUnit::STATUS_IN_STOCK)->orderBy('id')->value('id') : null;
            if ($ticket === null || $unitId === null) {
                continue;
            }
            $technician = User::find($ticket->assignee_id);
            Carbon::setTestNow($realNow->subHours(2));
            app(IssuePartToTicket::class)->handle($ticket->id, $part->id, 1, $technician, 'เปลี่ยนอะไหล่ตามใบงาน',
                unitIds: [$unitId], links: ['asset_id' => $ticket->asset_id]);

            if ($part->is($sfp)) {
                Carbon::setTestNow($realNow->subHour());
                app(ReturnPartUnits::class)->handle($sfp, [$unitId], $technician, [
                    'ticket_id' => $ticket->id, 'note' => 'ไม่ได้ใช้ คืนเข้าสต็อก',
                ]);
            }
        }

        Carbon::setTestNow();

        return ['nvme' => $nvme->fresh(), 'sfp' => $sfp->fresh(), 'hdd' => $hdd?->fresh()];
    }

    /** Purchase requests at every stage: pending, rejected, ordered, partly received, and registered (part, asset, serial pieces). */
    private function purchaseRequests(User $admin, User $requester, Part $trackedPart): void
    {
        $save = app(SavePurchaseRequest::class);
        $move = app(MovePurchaseRequest::class);
        $receive = app(ReceivePurchase::class);
        $realNow = now()->toImmutable();
        $at = fn (int $daysAgo, int $hour = 10) => Carbon::setTestNow($realNow->subDays($daysAgo)->setTime($hour, 0));
        $contractId = Contract::where('status', Contract::STATUS_ACTIVE)->where('ends_on', '>=', $realNow->toDateString())->orderBy('id')->value('id');
        $category = fn (string $prefix) => AssetCategory::where('code_prefix', $prefix)->value('id');
        // What the form would hand over: money in satang, at least one link, needed_by not in the past.
        $ask = function (array $item, int $daysAgo) use ($save, $at, $realNow, $contractId, $requester) {
            $at($daysAgo, 9);

            return $save->handle(null, $item + [
                'contract_id' => $contractId,
                'reason' => 'ใช้สำรองสำหรับงาน MA ตามสัญญา',
                'needed_by' => $realNow->addDays(14)->toDateString(),
                'links' => ['https://www.example.com/product'],
            ], $requester);
        };

        // Waiting for approval.
        $ask(['item_name' => 'Switch PoE 24 พอร์ต', 'description' => 'ขยายจุด Access Point ชั้น 3', 'quantity' => 1, 'unit' => 'เครื่อง',
            'unit_price' => 18500 * 100, 'item_kind' => PurchaseRequest::KIND_ASSET, 'asset_category_id' => $category('SW')], 1);

        // Rejected.
        $pr = $ask(['item_name' => 'จอมอนิเตอร์ 27 นิ้ว', 'quantity' => 4, 'unit' => 'เครื่อง', 'unit_price' => 6900 * 100], 6);
        $at(5);
        $move->handle($pr, 'reject', $admin, 'ไม่อยู่ในขอบเขตสัญญา MA');

        // Approved and ordered: waiting for delivery.
        $pr = $ask(['item_name' => 'ตลับหมึก HP 59A', 'quantity' => 6, 'unit' => 'กล่อง', 'unit_price' => 3450 * 100,
            'item_kind' => PurchaseRequest::KIND_PART], 5);
        $at(4);
        $move->handle($pr, 'approve', $admin, 'อนุมัติตามงบ MA');
        $at(4, 14);
        $move->handle($pr, 'order', $admin, 'สั่งซื้อจาก บริษัท ไอทีซัพพลาย จำกัด PO-6910-0012');

        // Partly received as assets: two deliveries of three notebooks.
        $pr = $ask(['item_name' => 'โน้ตบุ๊กสำรอง Lenovo ThinkPad E14', 'quantity' => 3, 'unit' => 'เครื่อง', 'unit_price' => 25000 * 100,
            'item_kind' => PurchaseRequest::KIND_ASSET, 'asset_category_id' => $category('NB')], 9);
        $at(8);
        $move->handle($pr, 'approve', $admin);
        $at(8, 14);
        $move->handle($pr, 'order', $admin, 'PO-6910-0009');
        foreach ([[4, 'PF4XK1A1'], [2, 'PF4XK1A2']] as [$daysAgo, $serial]) {
            $at($daysAgo, 11);
            $receive->handle($pr, ['quantity' => 1, 'brand' => 'Lenovo', 'model' => 'ThinkPad E14 Gen 5', 'unit_price' => 24500 * 100,
                'serials' => [$serial], 'note' => 'ทยอยส่ง', 'location' => 'ห้องเก็บของ สำนักงานใหญ่'], $admin);
        }

        // Registered as stock of a plain part.
        $pr = $ask(['item_name' => 'RAM DDR4 8GB 2666', 'quantity' => 10, 'unit' => 'ชิ้น', 'unit_price' => 890 * 100,
            'item_kind' => PurchaseRequest::KIND_PART], 7);
        $at(6);
        $move->handle($pr, 'approve', $admin);
        $at(3, 15);
        $receive->handle($pr, ['quantity' => 10, 'brand' => 'Kingston', 'model' => 'KVR26N19S8/8', 'unit_price' => 870 * 100,
            'part_id' => Part::where('code', 'RAM-DDR4-8G')->value('id')], $admin);

        // Registered as an asset.
        $pr = $ask(['item_name' => 'UPS APC Back-UPS 1100VA', 'quantity' => 2, 'unit' => 'เครื่อง', 'unit_price' => 4590 * 100,
            'item_kind' => PurchaseRequest::KIND_ASSET, 'asset_category_id' => $category('OE')], 8);
        $at(7);
        $move->handle($pr, 'approve', $admin);
        $at(3, 11);
        $receive->handle($pr, ['quantity' => 2, 'brand' => 'APC', 'model' => 'BX1100LI-MS', 'serials' => ['3B2311X10001', '3B2311X10002'],
            'location' => 'คลังสาขาเชียงใหม่'], $admin);

        // Registered as serial pieces of a tracked part.
        $pr = $ask(['item_name' => $trackedPart->name, 'quantity' => 2, 'unit' => 'ชิ้น', 'unit_price' => 3290 * 100,
            'item_kind' => PurchaseRequest::KIND_PART], 6);
        $at(5);
        $move->handle($pr, 'approve', $admin);
        $at(2, 10);
        $receive->handle($pr, ['quantity' => 2, 'brand' => 'Samsung', 'model' => '980 PRO', 'serials' => ['S6WRNJ0T490001', 'S6WRNJ0T490002'],
            'part_id' => $trackedPart->id], $admin);

        Carbon::setTestNow();
    }

    /**
     * Pieces technicians took out of customers' devices. In the app only QuickCloseTicket writes
     * them (and resolves the ticket), so they are written onto finished tickets the same way.
     */
    private function removedParts(): void
    {
        $pieces = [
            ['item_name' => 'RAM DDR4 8GB (ตัวเดิม)', 'serial_number' => 'KVR26-0A1B2C', 'problem' => 'เครื่องค้าง จอฟ้า ตรวจ MemTest พบ error', 'disposition' => 'claim'],
            ['item_name' => 'Power supply 550W (ตัวเดิม)', 'serial_number' => null, 'problem' => 'ไม่จ่ายไฟ มีกลิ่นไหม้', 'disposition' => 'discard'],
            ['item_name' => 'SSD SATA 240GB (ตัวเดิม)', 'serial_number' => 'WD240-77X1', 'problem' => 'มี bad sector ข้อมูลลูกค้าอยู่ในดิสก์', 'disposition' => 'return_customer'],
        ];

        $tickets = Ticket::whereIn('status', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->whereNotNull('asset_id')->whereNotNull('assignee_id')->latest('created_at')->limit(count($pieces))->get();

        foreach ($tickets as $i => $ticket) {
            $technician = User::find($ticket->assignee_id);
            Carbon::setTestNow($ticket->resolved_at ?? $ticket->created_at->copy()->addHours(3));
            TicketRemovedPart::create($pieces[$i] + [
                'ticket_id' => $ticket->id, 'asset_id' => $ticket->asset_id,
                'user_id' => $technician->id, 'user_name' => $technician->name,
            ]);
        }

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------- Asset / Labeling

    /** Three networks (two customers' and the company's own), each with a subnet, reserved and assigned addresses. */
    private function ipam(User $technician): void
    {
        $realNow = now()->toImmutable();
        $customerIds = Customer::orderBy('id')->limit(2)->pluck('id')->all();
        $plans = [
            // [customer_id (null = the company), network name, VLAN, cidr, gateway, days ago]
            [$customerIds[0] ?? null, 'LAN สำนักงานใหญ่', 10, '192.168.10.0/24', '192.168.10.1', 30],
            [$customerIds[1] ?? null, 'Server Farm', 20, '10.20.0.0/24', '10.20.0.1', 20],
            [null, 'Management (ของบริษัท)', 99, '172.16.99.0/24', '172.16.99.1', 10],
        ];

        foreach ($plans as [$customerId, $name, $vlan, $cidr, $gateway, $daysAgo]) {
            Carbon::setTestNow($realNow->subDays($daysAgo)->setTime(10, 0));

            $network = app(SaveNetwork::class)->handle(null, [
                'customer_id' => $customerId,
                'site_id' => $customerId ? CustomerSite::where('customer_id', $customerId)->value('id') : null,
                'name' => $name,
                'vlan_id' => $vlan,
                'description' => 'VLAN '.$vlan.' · '.$name,
            ]);
            $subnet = app(SaveSubnet::class)->handle(null, [
                'network_id' => $network->id,
                'cidr' => $cidr,
                'gateway' => $gateway,
                'description' => 'ซับเน็ตหลักของ '.$name,
            ]);
            $ip = fn (int $offset) => long2ip($subnet->first_int + $offset);

            $reserved = app(ReserveIps::class)->handle($subnet, [$ip(20), $ip(21)], $technician,
                'เตรียมติดตั้ง Access Point ชั้น 2', 'จองไว้ระหว่างรออุปกรณ์');

            $assets = Asset::query()
                ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id), fn ($q) => $q->whereNull('customer_id'))
                ->whereNull('ip_address')
                ->whereIn('status', [Asset::STATUS_IN_USE, Asset::STATUS_SPARE])
                ->orderBy('id')->limit(2)->get();

            foreach ($assets as $i => $asset) {
                Carbon::setTestNow(now()->addDays(2)->addHours($i));
                // The first device takes the reserved .20, the second .10 directly.
                $address = $i === 0 ? $reserved[0] : app(IpRecord::class)->handle($subnet, $ip(10));
                app(AssignIp::class)->handle($address, [
                    'asset_id' => $asset->id,
                    'hostname' => strtolower(str_replace(' ', '-', $asset->asset_code)),
                    'mac_address' => strtoupper(fake()->macAddress()),
                    'responsible_id' => $technician->id,
                    'in_use_since' => now()->toDateString(),
                    'notes' => 'ติดตั้งโดยทีมช่าง',
                ], $technician);
            }
        }

        Carbon::setTestNow();
    }

    /** Issue/loan requests: pending, handed out (closed), returned (closed), rejected. */
    private function checkouts(User $admin, User $helpdesk, User $technician, User $staff): void
    {
        $save = app(SaveCheckoutRequest::class);
        $submit = app(SubmitCheckoutRequest::class);
        $approve = app(ApproveCheckoutRequest::class);
        $fulfill = app(FulfillCheckoutItem::class);
        $realNow = now()->toImmutable();

        // Single assets that can be handed out, the company's own first; each request needs its own.
        $assets = Asset::query()
            ->whereIn('status', [Asset::STATUS_IN_USE, Asset::STATUS_SPARE])
            ->where('quantity', '<=', 1)
            ->orderByRaw('customer_id is null desc')->orderBy('id')
            ->limit(4)->get();
        if ($assets->count() < 4) {
            return;
        }
        $part = Part::where('is_active', true)->where('track_serial', false)->where('qty_on_hand', '>=', 2)->orderByDesc('qty_on_hand')->first();
        $ticket = Ticket::whereIn('status', Ticket::OPEN_STATUSES)->whereNotNull('assignee_id')->latest('id')->first();
        $loan = fn (Asset $asset, string $due) => ['item_type' => CheckoutItem::TYPE_ASSET, 'asset_id' => $asset->id, 'checkout_type' => CheckoutItem::LOAN, 'qty' => 1, 'due_return_date' => $due];

        // Waiting for approval.
        Carbon::setTestNow($realNow->subDay()->setTime(9, 15));
        $request = $save->handle(null, [
            'borrower_user_id' => $staff->id,
            'borrower_department' => 'ฝ่ายขาย',
            'borrower_phone' => fake()->phoneNumber(),
            'purpose' => 'ยืมไปนำเสนอลูกค้านอกสถานที่',
            'needed_by' => $realNow->addDays(2)->toDateString(),
            'items' => [$loan($assets[0], $realNow->addDays(9)->toDateString())],
        ], $helpdesk);
        $submit->handle($request, $helpdesk);

        // Approved and handed out in full (an asset, and a part against a ticket): closes itself.
        Carbon::setTestNow($realNow->subDays(6)->setTime(10, 0));
        $items = [['item_type' => CheckoutItem::TYPE_ASSET, 'asset_id' => $assets[1]->id, 'checkout_type' => CheckoutItem::ISSUE, 'qty' => 1, 'note' => 'เครื่องสำรองให้ลูกค้าใช้ระหว่างซ่อม']];
        if ($part && $ticket) {
            $items[] = ['item_type' => CheckoutItem::TYPE_PART, 'part_id' => $part->id, 'qty' => 2];
        }
        $request = $save->handle(null, [
            'borrower_user_id' => $technician->id,
            'ticket_id' => $part && $ticket ? $ticket->id : null,
            'purpose' => 'ใช้ในงานซ่อมตามใบแจ้งซ่อม',
            'needed_by' => $realNow->subDays(5)->toDateString(),
            'items' => $items,
        ], $technician);
        $submit->handle($request, $technician);
        Carbon::setTestNow(now()->addHours(2));
        $approve->handle($request, $admin);
        foreach ($request->items()->get() as $item) {
            Carbon::setTestNow(now()->addMinutes(30));
            $fulfill->handle($item, $item->remaining(), $helpdesk);
        }

        // A loan handed out, then returned.
        Carbon::setTestNow($realNow->subDays(12)->setTime(13, 30));
        $request = $save->handle(null, [
            'borrower_user_id' => $staff->id,
            'borrower_department' => 'ฝ่ายบัญชี',
            'purpose' => 'ยืมใช้งานชั่วคราวระหว่างเครื่องหลักส่งซ่อม',
            'items' => [$loan($assets[2], $realNow->subDays(5)->toDateString())],
        ], $helpdesk);
        $submit->handle($request, $helpdesk);
        Carbon::setTestNow(now()->addHour());
        $approve->handle($request, $admin);
        Carbon::setTestNow(now()->addHour());
        $item = $request->items()->first();
        $fulfill->handle($item, 1, $helpdesk);
        Carbon::setTestNow($realNow->subDays(7)->setTime(16, 0));
        app(ReturnCheckoutItem::class)->handle($item->fresh(), $helpdesk, 1, 'สภาพปกติ อุปกรณ์ครบ');

        // Rejected.
        Carbon::setTestNow($realNow->subDays(3)->setTime(11, 0));
        $request = $save->handle(null, [
            'borrower_name' => 'ผู้รับเหมา '.fake()->firstName().' '.fake()->lastName(),
            'borrower_phone' => fake()->phoneNumber(),
            'purpose' => 'ขอยืมไปใช้ที่หน้างานลูกค้า',
            'items' => [$loan($assets[3], $realNow->addDays(14)->toDateString())],
        ], $helpdesk);
        $submit->handle($request, $helpdesk);
        Carbon::setTestNow(now()->addHours(3));
        app(RejectCheckoutRequest::class)->handle($request, $admin, 'เครื่องนี้สงวนไว้สำหรับงานภายใน');

        Carbon::setTestNow();
    }

    /** Three print runs of asset labels. */
    private function labelPrints(User $admin, Collection $technicians): void
    {
        $realNow = now()->toImmutable();
        $firstCustomer = Customer::orderBy('id')->value('id');
        $runs = [
            // [who, template, asset ids, days ago]
            [$admin, 'a3_5x6', Asset::where('customer_id', $firstCustomer)->orderBy('id')->limit(10)->pluck('id')->all(), 25],
            [$technicians[0], 'roll_50x30', Asset::whereNotNull('customer_id')->inRandomOrder()->limit(5)->pluck('id')->all(), 12],
            [$technicians[1], 'a4_3x8', Asset::whereNull('customer_id')->orderBy('id')->limit(8)->pluck('id')->all(), 2],
        ];

        foreach ($runs as [$user, $template, $assetIds, $daysAgo]) {
            if ($assetIds === []) {
                continue;
            }
            Carbon::setTestNow($realNow->subDays($daysAgo)->setTime(14, 0));
            app(RecordLabelPrint::class)->handle($user, $assetIds, $template);
        }

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------- RoomAccess

    /**
     * Server rooms of three customers (rules, caretakers, approval steps) and requests in every
     * stage: two finished visits, a team inside now, one approved for later, one waiting.
     */
    private function roomAccess(User $admin, User $helpdesk, User $tech1, User $tech2, Collection $customers): void
    {
        if ($customers->count() < 3) {
            return;
        }
        $realNow = now()->toImmutable();
        // Roles made before the module may lack room-access.approve.
        $approver = $helpdesk->can('room-access.approve') ? $helpdesk : $admin;

        Carbon::setTestNow($realNow->subDays(100)->setTime(10, 0));
        app(SaveRoomAccessSettings::class)->handle($this->context->tenant(), [
            'company_terms' => [
                'ติดบัตรพนักงานตลอดเวลาที่อยู่ในห้อง',
                'ห้ามถ่ายภาพหรือบันทึกวิดีโอภายในห้องโดยไม่ได้รับอนุญาต',
                'แจ้งผู้ดูแลห้องทันทีเมื่อพบความผิดปกติ',
            ],
            'id_retention_days' => 90,
        ], $admin);

        [$roomA, $roomB, $roomC] = $this->serverRooms($admin, $helpdesk, $tech1, $tech2, $approver, $customers->take(3)->values(), $realNow);
        $this->roomRules($admin, $roomA, $roomB, $roomC, $realNow);
        $this->roomRequests($roomA, $roomB, $roomC, $admin, $approver, $tech1, $tech2, $realNow);

        Carbon::setTestNow();
    }

    /** @return array{0: ServerRoom, 1: ServerRoom, 2: ServerRoom} */
    private function serverRooms(User $admin, User $helpdesk, User $tech1, User $tech2, User $approver, Collection $customers, CarbonImmutable $realNow): array
    {
        $save = app(SaveServerRoom::class);
        $site = fn (Customer $customer) => CustomerSite::query()->where('customer_id', $customer->id)->value('id');

        // Data center: ID cards, rules again at the door, a guard link.
        $roomA = $save->handle(null, [
            'customer_id' => $customers[0]->id, 'site_id' => $site($customers[0]),
            'name' => 'ห้อง Data Center ชั้น 5', 'location' => 'อาคารสำนักงานใหญ่ ชั้น 5 ห้อง 501 (แลกบัตรที่ป้อม รปภ. ชั้น 1)',
            'requires_id_number' => true, 'missing_rules' => ServerRoom::MISSING_BLOCK, 'accept_mode' => ServerRoom::ACCEPT_EVERY_REQUEST,
            'accept_on_enter' => true, 'entrants_accept_self' => false, 'guard_link' => true,
            'freeze_periods' => [],
            'guard_contacts' => [['name' => 'รปภ. สมศักดิ์ ใจดี', 'phone' => '081-234-5678', 'email' => 'guard@example.test']],
            'is_active' => true, 'notes' => 'ต้องแลกบัตรประชาชนก่อนเข้า',
        ], [$tech1->id, $helpdesk->id], null, $admin);

        // Server room: two approval steps, accept once per version, a freeze period ahead.
        $roomB = $save->handle(null, [
            'customer_id' => $customers[1]->id, 'site_id' => $site($customers[1]),
            'name' => 'ห้องเซิร์ฟเวอร์ ชั้น 2', 'location' => 'อาคารผู้ป่วยนอก ชั้น 2 ข้างห้องเวชระเบียน',
            'requires_id_number' => false, 'missing_rules' => ServerRoom::MISSING_BLOCK, 'accept_mode' => ServerRoom::ACCEPT_ONCE_PER_VERSION,
            'accept_on_enter' => false, 'entrants_accept_self' => false, 'guard_link' => false,
            'freeze_periods' => [[
                'from' => $realNow->addDays(20)->setTime(22, 0)->format('Y-m-d H:i'),
                'to' => $realNow->addDays(21)->setTime(6, 0)->format('Y-m-d H:i'),
                'reason' => 'ปิดปรับปรุงระบบไฟฟ้าประจำปี',
            ]],
            'guard_contacts' => [['name' => 'คุณวิภา (ผู้ดูแลห้อง)', 'phone' => '02-123-4567', 'email' => null]],
            'is_active' => true, 'notes' => null,
        ], [$tech2->id], $approver->id, $admin);
        RoomApprovalStep::create(['server_room_id' => $roomB->id, 'position' => 2, 'side' => RoomApprovalStep::SIDE_COMPANY, 'approver_user_id' => $admin->id]);

        // Network core: no rules of its own, shows the company's terms. The admin approves by name.
        $roomC = $save->handle(null, [
            'customer_id' => $customers[2]->id, 'site_id' => $site($customers[2]),
            'name' => 'ห้อง Network Core', 'location' => 'อาคารคลังสินค้า 1 ชั้น 1',
            'requires_id_number' => false, 'missing_rules' => ServerRoom::MISSING_COMPANY_TERMS, 'accept_mode' => ServerRoom::ACCEPT_EVERY_REQUEST,
            'accept_on_enter' => false, 'entrants_accept_self' => false, 'guard_link' => false,
            'freeze_periods' => [], 'guard_contacts' => [], 'is_active' => true, 'notes' => null,
        ], [$tech1->id], $admin->id, $admin);

        return [$roomA, $roomB, $roomC];
    }

    /** Rule versions in effect before every backdated request (room A has an older v1 and v2 in effect). */
    private function roomRules(User $admin, ServerRoom $roomA, ServerRoom $roomB, ServerRoom $roomC, CarbonImmutable $realNow): void
    {
        $versions = [
            [$roomA, 95, ['ห้ามนำอาหารและเครื่องดื่มเข้าห้อง', 'ต้องมีเจ้าหน้าที่ของลูกค้าอยู่ด้วยตลอดเวลา'], 'ฝ่ายอาคารสถานที่'],
            [$roomA, 30, ['ห้ามนำอาหารและเครื่องดื่มเข้าห้อง', 'ต้องมีเจ้าหน้าที่ของลูกค้าอยู่ด้วยตลอดเวลา', 'สวมรองเท้าหุ้มส้นและห้ามใช้สเปรย์ทุกชนิด'], 'ฝ่ายอาคารสถานที่'],
            [$roomB, 90, ['แจ้งล่วงหน้าอย่างน้อย 1 วันทำการ', 'ห้ามปิดเครื่องที่ติดป้าย "ระบบผู้ป่วย" โดยไม่ได้รับอนุญาต'], 'ฝ่ายเทคโนโลยีสารสนเทศ'],
            [$roomC, 60, ['ปิดประตูทุกครั้งเพื่อรักษาอุณหภูมิ', 'ห้ามถอดสายที่ไม่มีป้ายระบุ'], 'แผนกไอที'],
        ];

        foreach ($versions as [$room, $daysAgo, $summary, $from]) {
            Carbon::setTestNow($realNow->subDays($daysAgo)->setTime(9, 0));
            app(PublishRoomRules::class)->handle($room, [
                'summary' => $summary,
                'effective_on' => now()->toDateString(),
                'received_from' => $from,
                'received_on' => now()->subDays(3)->toDateString(),
                'note' => null,
            ], null, $admin);
        }
    }

    private function roomRequests(ServerRoom $roomA, ServerRoom $roomB, ServerRoom $roomC, User $admin, User $approver, User $tech1, User $tech2, CarbonImmutable $realNow): void
    {
        $company = $this->context->tenant()->name;
        $enter = app(RecordRoomEntry::class);
        $exit = app(RecordRoomExit::class);
        $finish = app(FinishRoomVisit::class);
        $device = ['ip' => '127.0.0.1', 'user_agent' => 'DemoSeeder'];

        // Finished a week ago in room A: ID numbers, rules accepted again at the door, the guard records leaving.
        $day = $realNow->subDays(7);
        Carbon::setTestNow($day->subDay()->setTime(15, 0));
        $request = $this->roomRequest($roomA, $tech1, $day->setTime(10, 0), $day->setTime(12, 0), 'เปลี่ยนฮาร์ดดิสก์ที่เสียใน RAID ของเซิร์ฟเวอร์ฐานข้อมูล', [
            ['name' => $tech1->name, 'company' => $company, 'phone' => '081-111-2222', 'id_number' => '1100700123456'],
            ['name' => 'สมชาย ช่วยงาน', 'company' => $company, 'phone' => '082-333-4444', 'id_number' => '3101500654321'],
        ], [
            ['name' => 'HDD SAS 1.2TB 10K (ลูกใหม่)', 'serial_number' => 'WXA1B2C3', 'quantity' => 1, 'direction' => 'in'],
            ['name' => 'HDD SAS 1.2TB 10K (ลูกที่เสีย)', 'serial_number' => 'WXZ9Y8X7', 'quantity' => 1, 'direction' => 'out'],
            ['name' => 'โน้ตบุ๊กสำหรับตั้งค่า', 'serial_number' => null, 'quantity' => 1, 'direction' => 'in'],
        ]);
        $request = $this->approveRoomRequest($request, $approver);
        Carbon::setTestNow($day->setTime(9, 50));
        $enter->handle($request, $tech1, null, ['accept' => true] + $device);
        Carbon::setTestNow($day->setTime(11, 40));
        $exit->handle($request, null, 'สมศักดิ์ ใจดี');
        Carbon::setTestNow($day->setTime(13, 0));
        $finish->handle($request->fresh(), $tech1, 'เปลี่ยน HDD ช่อง 3 แล้ว RAID rebuild เสร็จ สถานะปกติ นำลูกที่เสียออกมาเคลมแล้ว', true);

        // Finished three weeks ago in room B: two approval steps, left 20 minutes late.
        $day = $realNow->subDays(21);
        Carbon::setTestNow($day->subDays(2)->setTime(10, 0));
        $request = $this->roomRequest($roomB, $tech2, $day->setTime(13, 0), $day->setTime(16, 0), 'PM ประจำไตรมาส ตรวจ UPS และทำความสะอาดตู้ Rack', [
            ['name' => $tech2->name, 'company' => $company, 'phone' => '089-555-6666'],
        ], [
            ['name' => 'เครื่องเป่าลม', 'serial_number' => null, 'quantity' => 1, 'direction' => 'in'],
        ]);
        $request = $this->approveRoomRequest($request, $approver, $admin);
        Carbon::setTestNow($day->setTime(13, 5));
        $enter->handle($request, $tech2, null, $device);
        Carbon::setTestNow($day->setTime(16, 20));
        $exit->handle($request, $tech2);
        Carbon::setTestNow($day->setTime(16, 40));
        $finish->handle($request->fresh(), $tech2, 'ตรวจ UPS ทั้ง 2 ชุด แบตเตอรี่ปกติ ทำความสะอาดตู้ Rack 4 ตู้', true);

        // Inside now in room C.
        Carbon::setTestNow($realNow->subDay()->setTime(16, 0));
        $request = $this->roomRequest($roomC, $tech1, $realNow->subMinutes(30), $realNow->addHours(2), 'ตรวจสอบสวิตช์ Core ที่แจ้งเตือนอุณหภูมิสูง', [
            ['name' => $tech1->name, 'company' => $company, 'phone' => '081-111-2222'],
        ]);
        $request = $this->approveRoomRequest($request, $admin);
        Carbon::setTestNow($realNow->subMinutes(20));
        $enter->handle($request, $tech1, null, $device);

        // Approved for three days from now in room B.
        Carbon::setTestNow($realNow->subHours(5));
        $start = $realNow->addDays(3)->setTime(13, 0);
        $request = $this->roomRequest($roomB, $tech2, $start, $start->setTime(16, 0), 'ติดตั้งสวิตช์ตัวใหม่แทนตัวเดิมที่หมดอายุ', [
            ['name' => $tech2->name, 'company' => $company, 'phone' => '089-555-6666'],
            ['name' => 'วิศวกรจาก Cisco', 'company' => 'บริษัท ซิสโก้ ซิสเต็มส์ (ประเทศไทย) จำกัด', 'phone' => null],
        ], [
            ['name' => 'Cisco Catalyst 9300-48P', 'serial_number' => 'FOC2501X0AB', 'quantity' => 1, 'direction' => 'in'],
            ['name' => 'Cisco Catalyst 2960 (ตัวเดิม)', 'serial_number' => 'FOC1702Y1CD', 'quantity' => 1, 'direction' => 'out'],
        ]);
        $this->approveRoomRequest($request, $approver, $admin);

        // Waiting for the admin.
        Carbon::setTestNow($realNow->subHours(2));
        $start = $realNow->addDays(5)->setTime(9, 0);
        $this->roomRequest($roomC, $tech1, $start, $start->setTime(11, 0), 'อัปเกรด firmware สวิตช์ Core ตามประกาศช่องโหว่ของผู้ผลิต', [
            ['name' => $tech1->name, 'company' => $company, 'phone' => '081-111-2222'],
            ['name' => 'สมชาย ช่วยงาน', 'company' => $company, 'phone' => '082-333-4444'],
        ]);
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
            'contract_id' => Contract::query()->where('customer_id', $room->customer_id)->latest('id')->value('id'),
            'people' => $people,
            'items' => $items,
        ], $requester);
        $rules = app(RoomRulesForRequest::class)->handle($room, $requester, $request);

        return app(SubmitRoomAccessRequest::class)->handle($request, $requester, [
            'accept' => true, 'version_id' => $rules['version_id'], 'ip' => '127.0.0.1', 'user_agent' => 'DemoSeeder',
        ]);
    }

    /** Each approver decides their step, in order, 45 minutes apart. */
    private function approveRoomRequest(RoomAccessRequest $request, User ...$approvers): RoomAccessRequest
    {
        foreach ($approvers as $approver) {
            Carbon::setTestNow(now()->addMinutes(45));
            $request = app(DecideRoomAccessRequest::class)->handle($request, 'approve', $approver);
        }

        return $request;
    }

    // ---------------------------------------------------------------- Maintenance (PM workflow)

    /**
     * Work orders at every step of the PM workflow, from the rounds DemoSeeder left running:
     * one approved, published and shared with its customer; one handed in and waiting for review;
     * one sent back to the technician; and a work order made by hand, still a draft. The standard
     * checklists of the company's categories are added first.
     */
    private function pmWorkflow(User $admin, User $helpdesk, Collection $customers): void
    {
        app(AddStandardChecklists::class)->handle();
        $move = app(MovePmVisit::class);
        $submit = fn (PmVisit $visit, string $summary) => app(SubmitPmVisit::class)->handle($visit, $this->assigneeOf($visit, $admin), [
            'summary' => $summary,
            'technician_signature' => DemoSeeder::signature(),
            'customer_signature' => DemoSeeder::signature(),
            'customer_signer_name' => fake()->firstName().' '.fake()->lastName(),
            'customer_signer_position' => 'เจ้าหน้าที่ไอที',
        ]);

        $running = PmVisit::where('status', PmVisit::STATUS_IN_PROGRESS)->orderBy('id')->get();
        if ($running->count() >= 2) {
            // Approved, published and shared.
            $this->finishItems($running[0], $admin);
            $submit($running[0], 'ตรวจครบทุกเครื่อง อุปกรณ์ทำงานปกติ');
            $move->handle($running[0]->fresh(), 'review', $helpdesk);
            $move->handle($running[0]->fresh(), 'approve', $admin, 'ตรวจสอบรายงานแล้ว');
            $move->handle($running[0]->fresh(), 'publish', $admin);
            ['url' => $url] = app(CreatePmReportShare::class)->handle($running[0]->fresh(), [
                'recipient_name' => 'ฝ่ายไอทีลูกค้า', 'days' => 30, 'allow_download' => true,
            ], $admin);
            $this->command?->info("PM report link ({$running[0]->visit_no}): {$url}");

            // Handed in, waiting for review.
            $this->finishItems($running[1], $admin);
            $submit($running[1], 'ส่งงานรอบนี้ รอหัวหน้าตรวจ');
        }

        // Sent back: started, done, handed in, returned with what to fix.
        $scheduled = PmVisit::where('status', PmVisit::STATUS_SCHEDULED)->whereHas('plan')->orderBy('due_on')->first();
        if ($scheduled) {
            app(StartPmVisit::class)->handle($scheduled, $this->assigneeOf($scheduled, $admin));
            $this->finishItems($scheduled->fresh(), $admin);
            $submit($scheduled->fresh(), 'ส่งงานแล้ว');
            $move->handle($scheduled->fresh(), 'return', $helpdesk, 'ขอรูปถ่ายหน้าตู้ Rack และค่าอุณหภูมิห้องเพิ่มเติม');
        }

        // Made by hand for the first customer's servers, still a draft.
        $customer = $customers->first();
        if ($customer) {
            app(CreatePmWorkOrder::class)->handle([
                'customer_id' => $customer->id,
                'title' => 'PM ห้อง Server พิเศษก่อนปิดปีงบประมาณ',
                'due_on' => now()->addDays(20)->toDateString(),
                'asset_ids' => Asset::where('customer_id', $customer->id)->whereHas('category', fn ($q) => $q->where('code_prefix', 'SV'))->limit(3)->pluck('id')->all(),
                'draft' => true,
            ], $helpdesk);
        }
    }

    /** Records every unchecked piece of equipment of a work order as fine, each question answered as passing. */
    private function finishItems(PmVisit $visit, User $fallback): void
    {
        $technician = $this->assigneeOf($visit, $fallback);
        foreach ($visit->items()->where('result', PmVisitItem::RESULT_PENDING)->get() as $item) {
            $answers = collect($item->checklist)->mapWithKeys(fn (array $question) => [$question['key'] => match ($question['type']) {
                'check' => true,
                'pass_fail' => 'pass',
                'measure' => isset($question['min'], $question['max']) ? ($question['min'] + $question['max']) / 2 : ($question['min'] ?? $question['max'] ?? 1),
                'number' => fake()->numberBetween(1, 100),
                default => 'ปกติ',
            }])->all();
            app(RecordPmItem::class)->handle($item, $technician, ['result' => PmVisitItem::RESULT_PASSED, 'answers' => $answers]);
        }
    }

    private function assigneeOf(PmVisit $visit, User $fallback): User
    {
        return User::find($visit->assignee_id) ?? $fallback;
    }

    // ---------------------------------------------------------------- Platform

    /**
     * Between the two companies (only one row per direction is allowed):
     * itsol may see netpro's parts and assets and forward tickets there; netpro may see itsol's
     * parts (accepted by itsol's admin); itsol's technicians and netpro's helpdesk work in both;
     * three itsol tickets forwarded to netpro.
     */
    private function platformSharing(): void
    {
        $platform = Tenant::where('is_platform', true)->first();
        $itsol = Tenant::where('subdomain', 'itsol')->first();
        $netpro = Tenant::where('subdomain', 'netpro')->first();
        if (! $platform || ! $itsol || ! $netpro || TenantShare::query()->exists()) {
            return;
        }

        // The share's activity log needs a tenant: run in the platform's.
        $pending = $this->context->run($platform, function () use ($itsol, $netpro) {
            $superadmin = User::where('email', 'admin@platform.test')->firstOrFail();

            app(SaveTenantShare::class)->handle($itsol, $netpro, [
                'abilities' => ['parts.view', 'assets.view', 'tickets.forward'],
                'roles' => ['helpdesk', 'technician'], 'user_ids' => [], 'branch_ids' => [],
                'reason' => 'ใช้อะไหล่และส่งงานต่อระหว่างบริษัทในเครือ', 'expires_on' => null, 'activate' => true,
            ], $superadmin);
            $pending = app(SaveTenantShare::class)->handle($netpro, $itsol, [
                'abilities' => ['parts.view'],
                'roles' => ['technician'], 'user_ids' => [], 'branch_ids' => [],
                'reason' => 'ตรวจสอบสต็อกอะไหล่', 'expires_on' => now()->addYear()->toDateString(), 'activate' => false,
            ], $superadmin);

            // Each pool makes linked accounts in the "to" company.
            app(SaveStaffPool::class)->handle(null, ['from_tenant_id' => $itsol->id, 'to_tenant_id' => $netpro->id, 'roles' => ['technician']], $superadmin->name);
            app(SaveStaffPool::class)->handle(null, ['from_tenant_id' => $netpro->id, 'to_tenant_id' => $itsol->id, 'roles' => ['helpdesk']], $superadmin->name);

            return $pending;
        });

        $this->context->run($itsol, function () use ($pending, $netpro) {
            app(DecideTenantShare::class)->handle($pending, 'accept', User::where('email', 'admin@itsol.test')->firstOrFail());

            $helpdesk = User::where('email', 'helpdesk@itsol.test')->firstOrFail();
            Ticket::whereIn('status', [Ticket::STATUS_NEW, Ticket::STATUS_ASSIGNED])->orderBy('id')->limit(3)->get()
                ->each(fn (Ticket $ticket) => app(ForwardedTickets::class)->forward($helpdesk, $netpro, $ticket, 'ขอให้ทีมเน็ตเวิร์คโปรช่วยเข้าหน้างาน'));
        });
    }

    private function staff(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
}

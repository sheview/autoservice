<?php

namespace Database\Seeders;

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development (never in production). Every password is "password".
 *
 *   admin@platform.test            superadmin (platform tenant)
 *   {role}@{subdomain}.test        admin, helpdesk, tech1, tech2, user in each customer tenant
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
    ];

    public function __construct(
        private TenantContext $context,
        private SaveAsset $saveAsset,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder does not run in production.');

            return;
        }

        fake()->seed(2026);

        $platform = Tenant::create(['name' => 'AutoService Platform', 'slug' => 'platform', 'subdomain' => 'admin', 'is_platform' => true]);
        $this->context->run($platform, fn () => $this->user('admin@platform.test', 'ผู้ดูแลแพลตฟอร์ม', PermissionCatalog::SUPERADMIN));

        foreach (self::TENANTS as $subdomain => $name) {
            $tenant = Tenant::create(['name' => $name, 'slug' => $subdomain, 'subdomain' => $subdomain]);
            $this->context->run($tenant, fn () => $this->seedTenant($subdomain));
        }

        $this->command?->info('Demo data ready. Log in with e.g. admin@itsol.test / '.self::PASSWORD);
    }

    private function seedTenant(string $subdomain): void
    {
        $branches = collect(self::BRANCHES)->map(fn (array $branch) => Branch::create($branch));

        $this->user("admin@{$subdomain}.test", $this->name(), 'admin_company');
        $this->user("helpdesk@{$subdomain}.test", $this->name(), 'helpdesk', ['position' => 'เจ้าหน้าที่ Helpdesk']);
        $this->user("tech1@{$subdomain}.test", $this->name(), 'technician', [
            'branch_id' => $branches[0]->id, 'position' => 'ช่างเทคนิค', 'service_lines' => ['network', 'datacenter'],
        ]);
        $this->user("tech2@{$subdomain}.test", $this->name(), 'technician', [
            'branch_id' => $branches[1]->id, 'position' => 'ช่างเทคนิค', 'service_lines' => ['pc'],
        ]);
        $this->user("user@{$subdomain}.test", $this->name(), 'user', ['branch_id' => $branches[0]->id]);

        foreach (self::CATEGORIES as $definition) {
            $category = AssetCategory::create(collect($definition)->except('brands')->all());

            foreach (range(1, fake()->numberBetween(6, 12)) as $i) {
                $this->asset($category, $definition['brands'], $branches->random());
            }
        }
    }

    /**
     * @param  list<string>  $models  "Brand Model ..."
     */
    private function asset(AssetCategory $category, array $models, Branch $branch): void
    {
        [$brand, $model] = explode(' ', fake()->randomElement($models), 2);
        $purchased = fake()->dateTimeBetween('-5 years', '-1 month');
        // Warranty of 1-5 years from purchase, so some are expired and some are about to expire.
        $warranty = (clone $purchased)->modify('+'.fake()->numberBetween(1, 5).' years');

        $this->saveAsset->handle(null, [
            'category_id' => $category->id,
            'branch_id' => fake()->boolean(90) ? $branch->id : null,
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

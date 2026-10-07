<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Actions\SaveUser;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\CrossTenant\UniqueUserEmail;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * First step on a new server (after the migrations): creates the platform tenant with its roles
 * and the first superadmin, who then adds the customer companies from the web. Safe to run again:
 * an existing platform tenant is kept and only another superadmin is added.
 */
class InstallPlatformCommand extends Command
{
    protected $signature = 'platform:install
        {--name= : Name of the superadmin}
        {--email= : E-mail (login) of the superadmin}
        {--password= : Password (asked for when left out)}
        {--subdomain=admin : Subdomain of the platform tenant}';

    protected $description = 'Create the platform tenant and the first superadmin';

    public function handle(TenantContext $context, SaveUser $saveUser): int
    {
        $platform = Tenant::query()->where('is_platform', true)->first()
            ?? Tenant::create([
                'name' => config('app.name').' Platform',
                'slug' => 'platform',
                'subdomain' => $this->option('subdomain'),
                'is_platform' => true,
            ]);

        $data = [
            'name' => $this->option('name') ?? $this->ask('Superadmin name'),
            'email' => strtolower((string) ($this->option('email') ?? $this->ask('Superadmin e-mail'))),
            'password' => $this->option('password') ?? $this->secret('Password (at least 8 characters, upper and lower case, and a special character)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', new UniqueUserEmail],
            'password' => ['required', Password::defaults()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $context->run($platform, fn () => $saveUser->handle(null, $data + ['role' => PermissionCatalog::SUPERADMIN]));

        $this->info("Superadmin {$data['email']} is ready. Sign in and add the customer companies under \"บริษัทลูกค้า\".");

        return self::SUCCESS;
    }
}

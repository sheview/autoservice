<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Actions\LinkCompanyAccounts;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * One-off: people created in two companies become one login (LinkCompanyAccounts). Shows the pairs
 * first; --apply saves them.
 *
 *   php artisan platform:link-accounts itbt datacom            (look)
 *   php artisan platform:link-accounts itbt datacom --apply    (save)
 */
class LinkAccountsCommand extends Command
{
    protected $signature = 'platform:link-accounts
        {main : Subdomain of the company whose accounts people log in with}
        {other : Subdomain of the company whose accounts become linked to them}
        {--apply : Save the links (otherwise only show them)}';

    protected $description = 'Link the same people\'s accounts of two companies to one login';

    public function handle(LinkCompanyAccounts $link): int
    {
        $main = Tenant::where('subdomain', $this->argument('main'))->where('is_platform', false)->first();
        $other = Tenant::where('subdomain', $this->argument('other'))->where('is_platform', false)->first();

        if ($main === null || $other === null || $main->is($other)) {
            $this->error('Give the subdomains of two different companies.');

            return self::FAILURE;
        }

        $result = $link->handle($main, $other, (bool) $this->option('apply'));

        $this->table(['Main account (logs in)', 'Linked account', 'Employee code'], array_map(fn (array $pair) => [
            "{$pair['main']->name} <{$pair['main']->email}>",
            "{$pair['other']->name} <{$pair['other']->email}>",
            $pair['other']->employee_code,
        ], $result['pairs']));

        if ($result['unmatched'] !== []) {
            $this->warn('Not matched (link them in the user form, field "อีเมลบัญชีหลัก"):');
            foreach ($result['unmatched'] as $user) {
                $this->line("  {$user->name} <{$user->email}> code: ".($user->employee_code ?? '-'));
            }
        }

        $this->option('apply')
            ? $this->info(count($result['pairs']).' accounts linked.')
            : $this->comment('Nothing saved. Run again with --apply to link these accounts.');

        return self::SUCCESS;
    }
}

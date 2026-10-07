<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * Links the staff accounts of one company ($other) to the same people's main accounts in another
 * ($main), for people who were created twice (once per company) before LinkedAccounts existed.
 *
 * Same person = same employee code; when a code is not unique in either company, also the same
 * name (titles such as นาย / นางสาว left out). Rows already linked, rows other rows are linked to,
 * and customer accounts are left alone. Without $apply nothing is saved.
 */
class LinkCompanyAccounts
{
    private const TITLES = ['นางสาว', 'น.ส.', 'นาง', 'นาย', 'ดร.', 'Mr.', 'Mrs.', 'Ms.', 'Miss'];

    public function __construct(private TenantContext $context) {}

    /**
     * @return array{pairs: list<array{main: User, other: User}>, unmatched: list<User>}
     */
    public function handle(Tenant $main, Tenant $other, bool $apply = false): array
    {
        $mains = $this->context->run($main, fn () => User::whereNull('login_user_id')->whereNull('customer_id')->get());
        $others = $this->context->run($other, fn () => User::whereNull('customer_id')->get());

        // A row that is someone's main account, or already linked, stays as it is.
        $linkedTo = $others->pluck('login_user_id')->filter()->all();
        $candidates = $others->filter(fn (User $user) => $user->login_user_id === null && ! in_array($user->id, $linkedTo, true));

        $pairs = [];
        $unmatched = [];
        foreach ($candidates as $user) {
            $match = $this->match($user, $mains, $candidates);
            if ($match === null) {
                $unmatched[] = $user;
            } else {
                $pairs[] = ['main' => $match, 'other' => $user];
            }
        }

        if ($apply) {
            $this->context->run($other, function () use ($pairs) {
                foreach ($pairs as $pair) {
                    $pair['other']->forceFill(['login_user_id' => $pair['main']->id])->save();
                }
            });
        }

        return ['pairs' => $pairs, 'unmatched' => $unmatched];
    }

    /**
     * @param  Collection<int, User>  $mains
     * @param  Collection<int, User>  $others
     */
    private function match(User $user, Collection $mains, Collection $others): ?User
    {
        $code = trim((string) $user->employee_code);
        if ($code === '') {
            return null;
        }

        $sameCode = $mains->filter(fn (User $main) => trim((string) $main->employee_code) === $code);
        $codeIsUnique = $sameCode->count() === 1
            && $others->filter(fn (User $other) => trim((string) $other->employee_code) === $code)->count() === 1;

        if ($codeIsUnique) {
            return $sameCode->first();
        }

        $byName = $sameCode->filter(fn (User $main) => $this->name($main->name) === $this->name($user->name));

        return $byName->count() === 1 ? $byName->first() : null;
    }

    private function name(string $name): string
    {
        $name = trim($name);
        foreach (self::TITLES as $title) {
            if (str_starts_with($name, $title)) {
                $name = trim(substr($name, strlen($title)));
                break;
            }
        }

        return preg_replace('/\s+/u', ' ', $name);
    }
}

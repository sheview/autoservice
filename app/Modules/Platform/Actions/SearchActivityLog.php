<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The activity log of the company (kept KEEP_DAYS days, see PruneActivityLogCommand): search
 * (what, who), filter by kind of record and dates, newest or oldest first.
 */
class SearchActivityLog
{
    public const KEEP_DAYS = 90;

    /**
     * @return array{search: string, subject: string|null, from: string|null, to: string|null, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $date = fn (string $key) => self::date($request->input($key));

        return [
            'search' => $request->string('search')->trim()->value(),
            'subject' => $request->filled('subject') ? (string) $request->input('subject') : null,
            'from' => $date('from'),
            'to' => $date('to'),
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<Activity>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';

        return Activity::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('description', 'like', "%{$search}%")
                ->orWhere('event', 'like', "%{$search}%")
                ->orWhereRaw("json_unquote(json_extract(properties, '$.actor.name')) collate utf8mb4_unicode_ci like ?", ["%{$search}%"])
                ->orWhereRaw('properties like ?', ["%{$search}%"])))
            ->when($filters['subject'] ?? null, fn (Builder $q, $subject) => $q->where('subject_type', $subject))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderBy('created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id', $filters['direction'] ?? 'desc');
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }
}

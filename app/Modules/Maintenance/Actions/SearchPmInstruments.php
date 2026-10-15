<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmInstrument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The measuring instrument list query: search (name, serial), "calibration due" filter, sort.
 */
class SearchPmInstruments
{
    public const SORTABLE = ['name', 'serial_no', 'calibration_due_at'];

    /**
     * @return array{search: string, due: bool, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            // only those whose calibration is due within 30 days or past
            'due' => $request->boolean('due'),
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<PmInstrument>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';

        return PmInstrument::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('serial_no', 'like', "%{$search}%")))
            ->when($filters['due'] ?? false, fn (Builder $q) => $q->whereNotNull('calibration_due_at')
                ->where('calibration_due_at', '<=', CarbonImmutable::now(config('app.timezone'))->addDays(30)->toDateString()))
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }
}

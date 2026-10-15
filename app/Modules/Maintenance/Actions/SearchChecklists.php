<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The PM template list query: one row per code (its newest version) with search, filters and sort.
 */
class SearchChecklists
{
    public const SORTABLE = ['name', 'code', 'updated_at'];

    /**
     * @return array{search: string, status: string|null, service_line: string|null, category: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $category = (string) $request->input('category', '');

        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), PmChecklist::STATUSES, true) ? $request->input('status') : null,
            'service_line' => in_array($request->input('service_line'), PmChecklist::SERVICE_LINES, true) ? $request->input('service_line') : null,
            // a category id the template is tied to, or "default" = the default template
            'category' => $category === 'default' || ctype_digit($category) ? $category : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<PmChecklist>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $category = (string) ($filters['category'] ?? '');
        $tiedTo = fn (Builder $q, callable $where) => $q->whereExists(fn ($sub) => $where($sub->from('pm_template_assignments')
            ->whereColumn('pm_template_assignments.tenant_id', 'pm_checklists.tenant_id')
            ->whereColumn('pm_template_assignments.template_code', 'pm_checklists.code')
            ->whereNull('pm_template_assignments.asset_id')));

        return PmChecklist::query()
            // the newest version of each code (the same tenant, as the outer query)
            ->whereRaw('pm_checklists.version = (select max(v.version) from pm_checklists v where v.tenant_id = pm_checklists.tenant_id and v.code = pm_checklists.code and v.deleted_at is null)')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['service_line'] ?? null, fn (Builder $q, string $line) => $q->where('service_line', $line))
            ->when($category === 'default', fn (Builder $q) => $tiedTo($q, fn ($sub) => $sub->whereNull('asset_category_id')->whereNull('asset_brand_id')->whereNull('asset_model_id')))
            ->when(ctype_digit($category), fn (Builder $q) => $tiedTo($q, fn ($sub) => $sub->where('asset_category_id', (int) $category)))
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }
}

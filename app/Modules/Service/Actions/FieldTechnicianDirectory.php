<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\FieldTechnician;
use App\Modules\Service\Models\FieldTechnicianTag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The outside technicians of the current company (the one their sign-up link belongs to; another
 * company reads it inside this one, through a share): searched by name, shop, phone or LINE, by
 * province and by kind of work. Staff of the company itself see every status; a company it is
 * shared with sees the approved ones only.
 */
class FieldTechnicianDirectory
{
    public const SORTABLE = ['name', 'company', 'created_at', 'status'];

    /**
     * @param  array{search?: string, province?: string|null, skill?: string|null, status?: string|null, sort?: string, direction?: string}  $filters
     */
    public function page(array $filters, bool $approvedOnly, int $perPage = 20): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $this->query($filters, $approvedOnly)
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (FieldTechnician $technician) => $this->row($technician));
    }

    /**
     * Approved technicians to send on a job: those working in $province first, then the others
     * matching the search.
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(?string $province, string $search, int $limit = 15): array
    {
        $query = $this->query(['search' => $search], true);
        if ($province !== null) {
            $query->orderByRaw(
                'EXISTS (SELECT 1 FROM field_technician_tags t WHERE t.field_technician_id = field_technicians.id AND t.tenant_id = field_technicians.tenant_id AND t.kind = ? AND t.value = ?) DESC',
                [FieldTechnicianTag::PROVINCE, $province],
            );
        }

        return $query->orderBy('name')->limit($limit)->get()
            ->map(fn (FieldTechnician $technician) => [
                ...$this->row($technician),
                'in_province' => $province !== null && in_array($province, $technician->tagValues(FieldTechnicianTag::PROVINCE), true),
            ])
            ->all();
    }

    /** Technicians waiting for staff to approve them. */
    public function pendingCount(): int
    {
        return FieldTechnician::query()->where('status', FieldTechnician::STATUS_PENDING)->count();
    }

    /**
     * @param  array{search?: string, province?: string|null, skill?: string|null, status?: string|null}  $filters
     * @return Builder<FieldTechnician>
     */
    private function query(array $filters, bool $approvedOnly): Builder
    {
        $search = trim($filters['search'] ?? '');
        $status = $approvedOnly ? FieldTechnician::STATUS_APPROVED : ($filters['status'] ?? null);

        return FieldTechnician::query()
            ->with('tags')
            ->when($status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('line_id', 'like', "%{$search}%")
                ->orWhereHas('tags', fn (Builder $q) => $q->where('value', 'like', "%{$search}%"))))
            ->when($filters['province'] ?? null, fn (Builder $q, string $province) => $this->tagged($q, FieldTechnicianTag::PROVINCE, $province))
            ->when($filters['skill'] ?? null, fn (Builder $q, string $skill) => $this->tagged($q, FieldTechnicianTag::SKILL, $skill));
    }

    private function tagged(Builder $query, string $kind, string $value): Builder
    {
        return $query->whereHas('tags', fn (Builder $q) => $q->where('kind', $kind)->where('value', $value));
    }

    /** @return array<string, mixed> */
    private function row(FieldTechnician $technician): array
    {
        return [
            ...$technician->only(['ulid', 'name', 'company', 'phone', 'line_id', 'email', 'note', 'status', 'reviewed_by_name']),
            'provinces' => $technician->tagValues(FieldTechnicianTag::PROVINCE),
            'skills' => $technician->tagValues(FieldTechnicianTag::SKILL),
            'created_at' => $technician->created_at?->toIso8601String(),
            'reviewed_at' => $technician->reviewed_at?->toIso8601String(),
        ];
    }
}

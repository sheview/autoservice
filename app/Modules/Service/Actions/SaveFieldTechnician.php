<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\FieldTechnician;
use App\Modules\Service\Models\FieldTechnicianTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps an outside technician's details and their provinces and kinds of work, in the current
 * company: a new one who signed up (pending, from the public link) or one staff corrected.
 * One phone number is one person: a second sign-up with it is refused.
 */
class SaveFieldTechnician
{
    /**
     * @param  array{name: string, company?: string|null, phone: string, line_id?: string|null, email?: string|null, note?: string|null, provinces: list<string>, skills: list<string>}  $data  validated
     */
    public function handle(?FieldTechnician $technician, array $data): FieldTechnician
    {
        $key = FieldTechnician::phoneKey($data['phone']);
        $taken = FieldTechnician::query()->where('phone_key', $key)
            ->when($technician, fn ($q) => $q->whereKeyNot($technician->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['phone' => __('service.field_technicians.phone_taken')]);
        }

        return DB::transaction(function () use ($technician, $data, $key) {
            $technician ??= new FieldTechnician(['status' => FieldTechnician::STATUS_PENDING, 'consented_at' => now()]);
            $technician->fill([
                'name' => trim($data['name']),
                'company' => filled($data['company'] ?? null) ? trim($data['company']) : null,
                'phone' => trim($data['phone']),
                'phone_key' => $key,
                'line_id' => filled($data['line_id'] ?? null) ? trim($data['line_id']) : null,
                'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            ])->save();

            $technician->tags()->delete();
            $rows = [];
            foreach ([FieldTechnicianTag::PROVINCE => $data['provinces'], FieldTechnicianTag::SKILL => $data['skills']] as $kind => $values) {
                foreach (array_unique($values) as $value) {
                    $rows[] = ['kind' => $kind, 'value' => $value];
                }
            }
            $technician->tags()->createMany($rows);

            return $technician->load('tags');
        });
    }
}

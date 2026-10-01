<?php

namespace App\Modules\Document\Actions;

use App\Modules\Document\Models\Manual;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Adds or changes a manual: its details and links, plus any files picked on the form.
 */
class SaveManual
{
    public function __construct(private AddAttachments $addAttachments) {}

    /**
     * @param  array{title: string, category?: string|null, description?: string|null, links?: list<array{label?: string|null, url?: string|null}>}  $data  validated
     * @param  list<UploadedFile>  $files
     */
    public function handle(?Manual $manual, array $data, User $actor, array $files = []): Manual
    {
        return DB::transaction(function () use ($manual, $data, $actor, $files) {
            $fields = [
                'title' => trim($data['title']),
                'category' => filled($data['category'] ?? null) ? trim($data['category']) : null,
                'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                // Rows left empty on the form are dropped; a link without a label shows its address.
                'links' => collect($data['links'] ?? [])
                    ->filter(fn (array $link) => filled($link['url'] ?? null))
                    ->map(fn (array $link) => ['label' => filled($link['label'] ?? null) ? trim($link['label']) : null, 'url' => trim($link['url'])])
                    ->values()->all(),
            ];

            if ($manual === null) {
                $manual = Manual::create([...$fields, 'created_by' => $actor->id, 'created_by_name' => $actor->name]);
            } else {
                $manual->update($fields);
            }

            $this->addAttachments->handle($manual, $files);

            return $manual;
        });
    }
}

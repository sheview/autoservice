<?php

namespace App\Modules\Document\Http\Requests;

use App\Modules\Document\Models\Manual;
use App\Modules\Document\Support\Attachments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A manual: title, category, description, web links (http/https only) and files to attach.
 */
class ManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        $manual = $this->route('manual');

        return $manual instanceof Manual ? $this->user()->can('update', $manual) : $this->user()->can('create', Manual::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'links' => ['nullable', 'array', 'max:'.Manual::MAX_LINKS],
            'links.*.label' => ['nullable', 'string', 'max:255'],
            'links.*.url' => ['nullable', 'string', 'max:2000', 'url:http,https'],
            ...Attachments::rules(images: true, maxKb: Manual::MAX_KB),
        ];
    }

    public function attributes(): array
    {
        return [...__('document.manuals.fields'), ...Attachments::attributes()];
    }

    /** @return array<string, mixed> */
    public function manualData(): array
    {
        return $this->safe()->except('attachments');
    }

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }
}

<?php

namespace Database\Seeders;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\MarkPmVisitArrival;
use App\Modules\Maintenance\Actions\MarkPmVisitDeparture;
use App\Modules\Maintenance\Actions\MovePmVisit;
use App\Modules\Maintenance\Actions\RecordPmItem;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Actions\SubmitPmVisit;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Validation\ValidationException;

/**
 * Doing a sample PM work order the way people do it, for the demo seeders: answers that pass every
 * question (readings mid-range), site photos, signatures drawn by code, and the work order taken
 * from arrival through hand-in (technician and customer sign) to approved and published.
 */
trait PmDemoWork
{
    /**
     * @param  callable(PmVisitItem, Asset, array<string, mixed>): array<string, mixed>  $result  the data to record for an item, from the passing answers
     * @param  array<string, mixed>  $submit  extra hand-in data (summary, customer signer, satisfaction…)
     * @param  callable(PmVisitItem, Asset): void|null  $extra  more for an item once recorded (photos…)
     */
    protected function doTheWork(PmVisit $visit, User $technician, User $admin, callable $result, array $submit, ?callable $extra = null, ?callable $beforeSubmit = null): PmVisit
    {
        $visit = app(StartPmVisit::class)->handle($visit, $technician);
        app(MarkPmVisitArrival::class)->handle($visit->fresh(), $technician);

        mt_srand(2569);
        $record = app(RecordPmItem::class);
        foreach ($visit->items()->with('visit')->orderBy('id')->get() as $item) {
            $asset = Asset::query()->find($item->asset_id);
            try {
                $record->handle($item, $technician, $result($item, $asset, $this->answers($item->checklist ?? [], $asset)));
            } catch (ValidationException $e) {
                $this->command?->warn("{$asset->name}: ".collect($e->errors())->flatten()->first());
            }
            if ($extra) {
                $extra($item->fresh(), $asset);
            }
        }

        app(MarkPmVisitDeparture::class)->handle($visit->fresh(), $technician);
        if ($beforeSubmit) {
            $beforeSubmit($visit->fresh());
        }
        $visit = app(SubmitPmVisit::class)->handle($visit->fresh(), $technician, $submit + [
            'technician_signature' => $this->signature(),
            'customer_signature' => $this->signature(7),
        ]);

        // Through the company's own steps: review, approve (signed), publish.
        $move = app(MovePmVisit::class);
        foreach (['submitted' => 'review', 'pending_approval' => 'approve', 'approved' => 'publish'] as $status => $action) {
            $visit = $visit->fresh();
            if ($visit->status === $status) {
                $visit = $move->handle($visit, $action, $admin, null, $action === 'approve' ? $this->signature(13) : null);
            }
        }

        return $visit->fresh();
    }

    /**
     * Answers that pass every question of a checklist: readings in the middle of their range,
     * Pass for Pass/Fail, the first option of a choice, tables filled row by row.
     *
     * @return array<string, mixed>
     */
    protected function answers(array $checklist, Asset $asset): array
    {
        $answers = [];
        foreach ($checklist as $field) {
            $answers[$field['key']] = match ($field['type']) {
                'pass_fail' => 'pass',
                'check' => true,
                'number', 'measure' => $this->reading($field['min'] ?? null, $field['max'] ?? null, $field['unit'] ?? null, $field['key']),
                'select' => $field['options'][0] ?? null,
                'checkbox_group' => array_slice($field['options'] ?? [], 0, 1),
                'measure_table' => collect($field['rows'] ?? [])->mapWithKeys(fn (array $row) => [$row['key'] => collect($field['columns'] ?? [])
                    ->mapWithKeys(function (array $column) use ($row) {
                        [$min, $max] = ChecklistItems::cellRange($row, $column);

                        return [$column['key'] => $this->reading($min, $max, $row['unit'] ?? null, $row['key'])];
                    })->all()])->all(),
                'repeat_table' => collect(range(1, max(1, ChecklistItems::repeatRowCount($field, $answers))))
                    ->map(fn () => collect($field['columns'] ?? [])->mapWithKeys(fn (array $column) => [$column['key'] => match ($column['type']) {
                        'number' => $this->reading($column['min'] ?? null, $column['max'] ?? null, $column['unit'] ?? null, $column['key']),
                        'pass_fail' => 'pass',
                        default => 'ปกติ',
                    }])->all())->all(),
                'text', 'textarea' => match (true) {
                    str_contains($field['key'], 'brand') => $asset->brand,
                    str_contains($field['key'], 'model') => $asset->model,
                    str_contains($field['key'], 'ip') => $asset->ip_address ?? '-',
                    str_contains($field['key'], 'number') || str_contains($field['key'], 'name') => $asset->name,
                    default => 'ปกติ',
                },
                default => null, // photos, values from the asset register
            };
        }

        return $answers;
    }

    /** A plausible reading: inside its range, else by its unit or name. */
    protected function reading(mixed $min, mixed $max, ?string $unit, string $key): float|int
    {
        if ($min !== null && $max !== null) {
            return round($min + ($max - $min) * mt_rand(35, 65) / 100, 1);
        }
        if ($max !== null) {
            return round($max * mt_rand(60, 85) / 100, 1);
        }
        if ($min !== null) {
            return round($min * mt_rand(105, 130) / 100, 1);
        }

        return match (true) {
            $unit === 'V' => mt_rand(2280, 2330) / 10,
            $unit === 'A' => mt_rand(180, 420) / 10,
            $unit === 'Hz' => 50,
            $unit === '%' => mt_rand(30, 55),
            in_array($unit, ['°C', 'C'], true) => mt_rand(210, 240) / 10,
            str_contains($key, 'capacity') => 60,
            str_contains($key, 'block') => 32,
            str_contains($key, 'string') => 2,
            str_contains($key, 'ah') => 100,
            str_contains($key, 'volt') => 12,
            str_contains($key, 'time') => 15,
            default => mt_rand(1, 6),
        };
    }

    /** A hand-drawn-looking signature (PNG, data URL), a different stroke per seed. */
    protected function signature(int $seed = 3): string
    {
        $image = imagecreatetruecolor(360, 120);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagesetthickness($image, 3);
        $ink = imagecolorallocate($image, 20, 40, 110);
        $previous = null;
        for ($x = 20; $x <= 340; $x += 4) {
            $y = (int) (60 + 28 * sin(($x + $seed * 17) / (18 + $seed)) * cos($x / 47) + 10 * sin($x / 9));
            if ($previous) {
                imageline($image, $previous[0], $previous[1], $x, $y, $ink);
            }
            $previous = [$x, $y];
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /** A site photo stand-in (JPEG bytes): a coloured panel with the equipment's code. */
    protected function photo(string $label, int $seed): string
    {
        $image = imagecreatetruecolor(800, 600);
        $hue = [[30, 64, 120], [22, 101, 52], [120, 53, 15], [76, 29, 149]][$seed % 4];
        imagefill($image, 0, 0, imagecolorallocate($image, ...$hue));
        $light = imagecolorallocate($image, 255, 255, 255);
        for ($i = 0; $i < 6; $i++) {
            imagerectangle($image, 60 + $i * 110, 140, 140 + $i * 110, 460, $light);
        }
        imagestring($image, 5, 40, 40, $label, $light);
        imagestring($image, 3, 40, 540, 'PM site photo (sample)', $light);
        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** Attaches a sample photo to an item, of one question or of the equipment as a whole. */
    protected function attachPhoto(PmVisitItem $item, string $label, int $seed, ?string $question = null, ?string $caption = null): void
    {
        $item->addMediaFromString($this->photo($label, $seed))
            ->usingName($label)
            ->usingFileName(sprintf('photo-%d-%d.jpg', $item->id, $seed))
            ->withCustomProperties(array_filter(['question' => $question, 'caption' => $caption]))
            ->toMediaCollection(PmVisitItem::PHOTOS);
    }
}

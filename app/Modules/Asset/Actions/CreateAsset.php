<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates an asset with its serial numbers and attached files. One asset may hold several serials
 * (devices of one lot) or a quantity of things without serial. When it is already out with
 * someone, an approved issue/loan form is recorded for them. All or nothing.
 */
class CreateAsset
{
    public function __construct(
        private SaveAsset $saveAsset,
        private AddAttachments $addAttachments,
        private RecordHandedOut $recordHandedOut,
    ) {}

    /**
     * @param  array<string, mixed>  $data  as for SaveAsset
     * @param  list<string>  $serials
     * @param  list<UploadedFile>  $files
     * @param  array{type: string, borrower_name: string, on: string}|null  $handedOut
     */
    public function handle(array $data, array $serials, array $files, ?array $handedOut, User $actor): Asset
    {
        return DB::transaction(function () use ($data, $serials, $files, $handedOut, $actor) {
            $asset = $this->saveAsset->handle(null, $data, $serials);
            $this->addAttachments->handle($asset, $files);

            if ($handedOut !== null) {
                $this->recordHandedOut->handle($asset, $handedOut, $actor);
            }

            return $asset;
        });
    }
}

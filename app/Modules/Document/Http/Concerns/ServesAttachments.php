<?php

namespace App\Modules\Document\Http\Concerns;

use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Document\Actions\DeleteAttachment;
use App\Modules\Document\Support\Attachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What an attachment controller does, once it has checked the permission on its own record:
 * add files, show (download) one, delete one. Files are on a private disk and are only served
 * through such a controller.
 */
trait ServesAttachments
{
    protected function storeAttachments(Request $request, HasMedia $model): RedirectResponse
    {
        $request->validate(
            Attachments::rules(images: $model->attachmentsTakeImages(), required: true),
            attributes: Attachments::attributes(),
        );

        app(AddAttachments::class)->handle($model, $request->file('attachments', []));

        return back()->with('success', __('document.attachments.added'));
    }

    protected function showAttachment(HasMedia $model, int $id): StreamedResponse
    {
        $media = $this->attachment($model, $id);

        return response()->streamDownload(
            fn () => fpassthru($media->stream()),
            $media->file_name,
            ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff'],
            // A PDF opens in the browser; Word and Excel are downloaded.
            $media->mime_type === 'application/pdf' || str_starts_with($media->mime_type, 'image/') ? 'inline' : 'attachment',
        );
    }

    protected function destroyAttachment(HasMedia $model, int $id): RedirectResponse
    {
        app(DeleteAttachment::class)->handle($model, $this->attachment($model, $id));

        return back()->with('success', __('document.attachments.deleted'));
    }

    private function attachment(HasMedia $model, int $id): Media
    {
        $media = Attachments::find($model, $model->attachmentCollection(), $id);
        abort_if($media === null, 404);

        return $media;
    }
}

<?php

use App\Modules\Document\Support\ImageShrink;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Every stored photo takes as little room as it needs (ImageShrink, on MediaHasBeenAddedEvent):
 * large photos are made smaller, small ones and other files are left as they are.
 */

/** A busy picture (it does not compress well), written as $format to a temporary file. */
function busyPicture(int $width, int $height, string $format = 'jpeg', bool $transparent = false): string
{
    $image = imagecreatetruecolor($width, $height);
    if ($transparent) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    }
    mt_srand(7);
    for ($i = 0; $i < 600; $i++) {
        $color = imagecolorallocatealpha($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $transparent ? 40 : 0);
        imagefilledrectangle($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $color);
    }
    $path = tempnam(sys_get_temp_dir(), 'img');
    match ($format) {
        'png' => imagepng($image, $path),
        default => imagejpeg($image, $path, 98),
    };

    return $path;
}

beforeEach(function () {
    Storage::fake(config('media-library.disk_name'));
    $this->ticket = openTicket(userWithRole('helpdesk'));
    $this->store = fn (string $path, string $name) => $this->ticket
        ->addMedia(new UploadedFile($path, $name, null, null, true))->toMediaCollection(Ticket::PHOTOS);
});

it('makes a large photo smaller, at most 1600 px on its longest side', function () {
    $path = busyPicture(2000, 1500);
    $before = filesize($path);

    $media = ($this->store)($path, 'camera.jpg');

    [$width, $height] = getimagesize($media->getPath());
    expect($width)->toBe(1600)->and($height)->toBe(1200)
        ->and($media->fresh()->size)->toBeLessThan($before)
        ->and($media->fresh()->size)->toBe(filesize($media->getPath()))
        ->and($media->mime_type)->toBe('image/jpeg');
});

it('leaves a small photo and a file that is not a picture as they are', function () {
    $small = busyPicture(400, 300);
    $bytes = file_get_contents($small);
    $media = ($this->store)($small, 'small.jpg');
    expect(file_get_contents($media->getPath()))->toBe($bytes);

    $pdf = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($pdf, "%PDF-1.4\n".str_repeat('x', 800_000));
    $doc = $this->ticket->addMedia(new UploadedFile($pdf, 'manual.pdf', 'application/pdf', null, true))
        ->toMediaCollection($this->ticket->attachmentCollection());
    expect($doc->fresh()->size)->toBe(800_009);
});

it('keeps a transparent PNG a PNG, with its transparency', function () {
    $media = ($this->store)(busyPicture(2000, 1500, 'png', transparent: true), 'screen.png');

    $image = imagecreatefrompng($media->getPath());
    expect(imagesx($image))->toBe(1600)
        ->and($media->mime_type)->toBe('image/png')
        ->and((imagecolorat($image, 0, 0) >> 24) & 0x7F)->toBeGreaterThan(0); // still see-through somewhere
});

it('turns a phone photo upright from its EXIF orientation before making it smaller', function () {
    // A portrait photo stored landscape with Orientation 6 ("turn 90° clockwise"), as phones do.
    $path = busyPicture(2000, 1500);
    $jpeg = file_get_contents($path);
    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
    $app1 = "\xFF\xE1".pack('n', strlen($tiff) + 8)."Exif\x00\x00".$tiff;
    file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    expect(exif_read_data($path)['Orientation'] ?? null)->toBe(6);

    $media = ($this->store)($path, 'portrait.jpg');

    [$width, $height] = getimagesize($media->getPath());
    expect([$width, $height])->toBe([1200, 1600]);
});

it('never makes a file bigger', function () {
    // Already squeezed hard: shrinking again would not help, so it is kept.
    $path = busyPicture(1700, 1000);
    $image = imagecreatefromjpeg($path);
    imagejpeg($image, $path, 20);
    $bytes = file_get_contents($path);

    $result = app(ImageShrink::class)->handle($path, 'image/jpeg');

    expect($result === null ? file_get_contents($path) === $bytes : $result < strlen($bytes))->toBeTrue();
});

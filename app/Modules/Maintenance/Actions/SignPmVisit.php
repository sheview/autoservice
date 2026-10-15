<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Support\PmDataHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a signature on a work order (pm_signatures): the drawn PNG on the private disk, who signed
 * (name, position, phone, e-mail), when, from where (IP, browser). A role signs once per work order;
 * signing again replaces it — until the customer has signed: the customer's signature locks the
 * results (locked_at) with their hash (PmDataHash), and after that only the approver still signs.
 */
class SignPmVisit
{
    public const DISK = 'local';

    /** The largest drawing taken (a pad drawing is far smaller). */
    public const MAX_BYTES = 400 * 1024;

    public function __construct(private LogPmEvent $logEvent) {}

    /**
     * @param  array{image: string, name: string, position?: string|null, phone?: string|null, email?: string|null}  $data  image as data:image/png;base64,…
     * @param  array{ip?: string|null, user_agent?: string|null}  $client
     */
    public function handle(PmVisit $visit, string $role, array $data, ?User $actor, array $client = []): PmSignature
    {
        $png = self::decode($data['image'] ?? null);
        if ($png === null) {
            throw ValidationException::withMessages(['signature' => __('maintenance.signing.drawing_required')]);
        }
        if (blank($data['name'] ?? null)) {
            throw ValidationException::withMessages(['signer_name' => __('maintenance.visits.signer_required')]);
        }

        return DB::transaction(function () use ($visit, $role, $data, $actor, $client, $png) {
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->isLocked() && $role !== PmSignature::ROLE_APPROVER) {
                throw ValidationException::withMessages(['signature' => __('maintenance.signing.locked')]);
            }

            foreach ($visit->signatures()->where('role', $role)->get() as $old) {
                Storage::disk(self::DISK)->delete($old->image_path);
                $old->delete();
            }

            $path = "pm-signatures/{$visit->tenant_id}/{$visit->id}/{$role}-".Str::random(12).'.png';
            Storage::disk(self::DISK)->put($path, $png);

            $locking = $role === PmSignature::ROLE_CUSTOMER;
            $signature = $visit->signatures()->create([
                'role' => $role,
                'name' => trim($data['name']),
                'position' => filled($data['position'] ?? null) ? trim($data['position']) : null,
                'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
                'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
                'image_path' => $path,
                'signed_at' => now(),
                'ip' => $client['ip'] ?? null,
                'user_agent' => isset($client['user_agent']) ? mb_substr($client['user_agent'], 0, 255) : null,
                'data_hash' => $locking ? PmDataHash::of($visit) : null,
            ]);
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_SIGNED, $actor, null, null, null, ['role' => $role, 'signer' => $signature->name]);

            if ($locking) {
                $visit->update(['locked_at' => now()]);
                $this->logEvent->handle($visit, PmVisitEvent::TYPE_LOCKED, $actor, null, null, null, ['hash' => $signature->data_hash, 'signer' => $signature->name]);
                activity()->performedOn($visit)->causedBy($actor)->event('pm_locked')
                    ->withProperties(['visit_no' => $visit->visit_no, 'hash' => $signature->data_hash, 'ip' => $signature->ip])
                    ->log(__('maintenance.log.locked', ['no' => $visit->visit_no]));
            }

            return $signature;
        });
    }

    /** The PNG bytes of a drawing sent as a data URL, or null when it is not one (or too big). */
    public static function decode(mixed $image): ?string
    {
        if (! is_string($image) || ! str_starts_with($image, 'data:image/png;base64,')) {
            return null;
        }
        $png = base64_decode(substr($image, 22), true);

        return is_string($png) && $png !== '' && strlen($png) <= self::MAX_BYTES && str_starts_with($png, "\x89PNG") ? $png : null;
    }

    /** The drawing as a data URL, for the PDF. */
    public static function dataUrl(PmSignature $signature): ?string
    {
        $png = Storage::disk(self::DISK)->get($signature->image_path);

        return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
    }
}

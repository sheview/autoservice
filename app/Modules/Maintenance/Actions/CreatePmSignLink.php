<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmSignLink;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\ShareToken;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * A link (and its QR) for the customer to sign a handed-in work order on their own phone, lasting
 * the company's sign_link_days. A new link replaces the ones not used yet. The address is the
 * company's public one (APP_URL / its subdomain); the token is shown only now.
 */
class CreatePmSignLink
{
    /** Handed in, not yet signed by the customer. */
    public const STATUSES = [PmVisit::STATUS_SUBMITTED, PmVisit::STATUS_PENDING_APPROVAL, PmVisit::STATUS_APPROVED, PmVisit::STATUS_PUBLISHED];

    public function __construct(private TenantContext $context, private LogPmEvent $logEvent) {}

    /**
     * @return array{url: string, expires_at: string}
     */
    public function handle(PmVisit $visit, User $actor): array
    {
        if ($visit->isLocked() || ! in_array($visit->status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['visit' => __('maintenance.signing.link_not_allowed')]);
        }

        $visit->signLinks()->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token = ShareToken::make();
        $link = $visit->signLinks()->create([
            'token_hash' => ShareToken::hash($token),
            'expires_at' => now()->addDays(PmSettings::of()->signLinkDays)->endOfDay(),
            'created_by' => $actor->id,
        ]);
        $this->logEvent->handle($visit, PmVisitEvent::TYPE_SIGN_LINK_CREATED, $actor, null, null, null, ['expires_at' => $link->expires_at->toIso8601String()]);

        return [
            'url' => PublicUrl::forTenant($this->context->tenant(), '/pm-sign/'.$token),
            'expires_at' => $link->expires_at->toIso8601String(),
        ];
    }

    public static function revoke(PmSignLink $link, User $actor): void
    {
        $link->update(['revoked_at' => $link->revoked_at ?? now()]);
        app(LogPmEvent::class)->handle($link->visit, PmVisitEvent::TYPE_SIGN_LINK_REVOKED, $actor);
    }
}

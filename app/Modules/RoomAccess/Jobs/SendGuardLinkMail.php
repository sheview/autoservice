<?php

namespace App\Modules\RoomAccess\Jobs;

use App\Modules\Platform\Support\PublicUrl;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\RoomAccess\Notifications\GuardLinkMail;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * Sends the guard link of a request to every guard of its room who has an e-mail, in the tenant
 * the job was dispatched in. Nothing goes for a link revoked meanwhile.
 */
class SendGuardLinkMail implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function __construct(public int $tokenId) {}

    public function handle(TenantContext $context): void
    {
        $token = RoomAccessToken::query()->with('request.room')->find($this->tokenId);
        if ($token === null || ! $token->usable() || $token->request === null) {
            return;
        }
        $tenant = $context->tenant();
        $link = PublicUrl::forTenant($tenant, '/room-guard/'.$token->token);

        foreach ($token->request->room?->guard_contacts ?? [] as $guard) {
            if (filled($guard['email'] ?? null)) {
                Notification::route('mail', $guard['email'])->notifyNow(new GuardLinkMail($token->request, $link, $tenant->name));
            }
        }
    }
}

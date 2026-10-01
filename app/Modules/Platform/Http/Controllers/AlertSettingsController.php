<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Support\AlertSender;
use App\Modules\Platform\Support\AlertSettings;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's alert settings (AlertSettings): channels, events, and a test message per channel.
 */
class AlertSettingsController extends Controller
{
    public function edit(TenantContext $context): Response
    {
        Gate::authorize(AlertSettings::PERMISSION);

        return Inertia::render('Platform/AlertSettings', [
            'settings' => AlertSettings::forForm($this->company($context)),
            'eventGroups' => AlertSettings::EVENTS,
            'maxRecipients' => AlertSettings::MAX_RECIPIENTS,
        ]);
    }

    public function update(Request $request, TenantContext $context, SaveAlertSettings $save): RedirectResponse
    {
        Gate::authorize(AlertSettings::PERMISSION);

        $data = $request->validate([
            'events' => ['array'],
            'events.*' => ['string', Rule::in(AlertSettings::events())],
            'line.enabled' => ['boolean'],
            'line.to' => ['nullable', 'required_if_accepted:line.enabled', 'string', 'max:100'],
            'line.token' => ['nullable', 'string', 'max:500'],
            'line.remove_token' => ['boolean'],
            'telegram.enabled' => ['boolean'],
            'telegram.chat_id' => ['nullable', 'required_if_accepted:telegram.enabled', 'string', 'max:100'],
            'telegram.token' => ['nullable', 'string', 'max:200'],
            'telegram.remove_token' => ['boolean'],
            'mail.enabled' => ['boolean'],
            'mail.recipients' => ['array', 'max:'.AlertSettings::MAX_RECIPIENTS],
            'mail.recipients.*' => ['nullable', 'email', 'max:255'],
        ], attributes: __('alerts.fields'));

        $save->handle($this->company($context), $data);

        return back()->with('success', __('alerts.saved'));
    }

    /**
     * A test message through one channel, with the settings saved: the error from the service when it fails.
     */
    public function test(Request $request, TenantContext $context, AlertSender $sender): RedirectResponse
    {
        Gate::authorize(AlertSettings::PERMISSION);
        $channel = $request->validate(['channel' => ['required', Rule::in(AlertSettings::CHANNELS)]])['channel'];

        $tenant = $this->company($context);
        $config = AlertSettings::channels($tenant)[$channel] ?? null;
        if ($config === null) {
            return back()->with('error', __('alerts.not_ready', ['channel' => __("alerts.channels.{$channel}")]));
        }

        try {
            $sender->send($channel, $config, __('alerts.test.title').' · '.$tenant->name, __('alerts.test.body'));
        } catch (\Throwable $e) {
            return back()->with('error', __('alerts.test.failed', ['message' => $e->getMessage()]));
        }

        return back()->with('success', __('alerts.test.sent', ['channel' => __("alerts.channels.{$channel}")]));
    }

    /** The company being worked in; the platform sends no alerts. */
    private function company(TenantContext $context): Tenant
    {
        $tenant = $context->tenant();
        abort_if($tenant === null || $tenant->is_platform, 404);

        return $tenant;
    }
}

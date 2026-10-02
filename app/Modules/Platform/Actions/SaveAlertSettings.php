<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Support\AlertSettings;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

/**
 * Saves where and about what the company is alerted (AlertSettings). A token left empty keeps
 * the one saved; "remove" clears it. Tokens are stored encrypted and never logged.
 */
class SaveAlertSettings
{
    /**
     * @param  array{events?: list<string>, line?: array<string, mixed>, telegram?: array<string, mixed>, mail?: array<string, mixed>}  $data  validated
     */
    public function handle(Tenant $tenant, array $data): Tenant
    {
        $settings = $tenant->settings;
        $old = $settings['alerts'] ?? [];

        $token = function (string $channel) use ($data, $old): ?string {
            if ($data[$channel]['remove_token'] ?? false) {
                return null;
            }
            $new = trim((string) ($data[$channel]['token'] ?? ''));

            return $new !== '' ? Crypt::encryptString($new) : ($old[$channel]['token'] ?? null);
        };

        $settings['alerts'] = [
            'events' => array_values(array_intersect(AlertSettings::events(), $data['events'] ?? [])),
            'thresholds' => collect(AlertSettings::THRESHOLDS)->map(fn (int $default, string $key) => (int) ($data['thresholds'][$key] ?? $old['thresholds'][$key] ?? $default))->all(),
            'line' => [
                'enabled' => (bool) ($data['line']['enabled'] ?? false),
                'to' => trim((string) ($data['line']['to'] ?? '')),
                'token' => $token('line'),
            ],
            'telegram' => [
                'enabled' => (bool) ($data['telegram']['enabled'] ?? false),
                'chat_id' => trim((string) ($data['telegram']['chat_id'] ?? '')),
                'token' => $token('telegram'),
            ],
            'mail' => [
                'enabled' => (bool) ($data['mail']['enabled'] ?? false),
                'recipients' => collect($data['mail']['recipients'] ?? [])->map(fn ($email) => mb_strtolower(trim((string) $email)))
                    ->filter()->unique()->values()->all(),
            ],
        ];

        $tenant->settings = $settings;
        $tenant->save();

        $form = AlertSettings::forForm($tenant);
        activity()->performedOn($tenant)->event('alert_settings_updated')
            ->withProperties(['attributes' => [
                'events' => $form['events'],
                'line' => $form['line']['enabled'],
                'telegram' => $form['telegram']['enabled'],
                'mail' => $form['mail']['recipients'],
            ]])
            ->log('ตั้งค่าการแจ้งเตือน');

        return $tenant;
    }
}

<?php

namespace App\Modules\Platform\Support;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

/**
 * Where a company wants to hear about things that happen (issue/loan forms, repairs): LINE
 * (Messaging API: channel access token + user/group id), Telegram (bot token + chat id) and
 * e-mail (a list of addresses), and for which events. Kept in tenants.settings "alerts"; the
 * tokens are encrypted and never sent back to the browser. Set by the company's admin.
 */
class AlertSettings
{
    public const PERMISSION = 'company.update';

    public const CHANNELS = ['line', 'telegram', 'mail'];

    /** Events by group, as the settings page lists them. */
    public const EVENTS = [
        'checkout' => ['checkout_requested', 'checkout_approved', 'checkout_rejected', 'checkout_returned'],
        'repair' => ['ticket_opened', 'ticket_resolved', 'asset_in_repair'],
    ];

    public const MAX_RECIPIENTS = 10;

    /**
     * @return list<string>
     */
    public static function events(): array
    {
        return array_merge(...array_values(self::EVENTS));
    }

    /**
     * The settings as the page edits them: no tokens, only whether one is set.
     *
     * @return array{events: list<string>, line: array{enabled: bool, to: string, token_set: bool},
     *     telegram: array{enabled: bool, chat_id: string, token_set: bool}, mail: array{enabled: bool, recipients: list<string>}}
     */
    public static function forForm(Tenant $tenant): array
    {
        $alerts = $tenant->settings['alerts'] ?? [];

        return [
            'events' => array_values(array_intersect(self::events(), $alerts['events'] ?? [])),
            'line' => [
                'enabled' => (bool) ($alerts['line']['enabled'] ?? false),
                'to' => (string) ($alerts['line']['to'] ?? ''),
                'token_set' => filled($alerts['line']['token'] ?? null),
            ],
            'telegram' => [
                'enabled' => (bool) ($alerts['telegram']['enabled'] ?? false),
                'chat_id' => (string) ($alerts['telegram']['chat_id'] ?? ''),
                'token_set' => filled($alerts['telegram']['token'] ?? null),
            ],
            'mail' => [
                'enabled' => (bool) ($alerts['mail']['enabled'] ?? false),
                'recipients' => array_values($alerts['mail']['recipients'] ?? []),
            ],
        ];
    }

    /**
     * The channels that can send now, with their (decrypted) credentials. With $event, none
     * unless the company wants that event.
     *
     * @return array<string, array<string, mixed>> channel => config
     */
    public static function channels(Tenant $tenant, ?string $event = null): array
    {
        $alerts = $tenant->settings['alerts'] ?? [];
        if ($event !== null && ! in_array($event, $alerts['events'] ?? [], true)) {
            return [];
        }

        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $config = $alerts[$channel] ?? [];
            if (! ($config['enabled'] ?? false)) {
                continue;
            }
            if (isset($config['token'])) {
                $config['token'] = self::decrypt($config['token']);
            }
            if (self::complete($channel, $config)) {
                $channels[$channel] = $config;
            }
        }

        return $channels;
    }

    /**
     * @param  array<string, mixed>  $config  with the token decrypted
     */
    public static function complete(string $channel, array $config): bool
    {
        return match ($channel) {
            'line' => filled($config['token'] ?? null) && filled($config['to'] ?? null),
            'telegram' => filled($config['token'] ?? null) && filled($config['chat_id'] ?? null),
            'mail' => ($config['recipients'] ?? []) !== [],
            default => false,
        };
    }

    public static function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null; // the app key changed: the token has to be entered again
        }
    }
}

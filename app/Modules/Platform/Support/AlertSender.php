<?php

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Notifications\AlertMail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Sends one alert through one channel. Throws when the service refuses (bad token, unknown
 * chat) or cannot be reached, so the caller can report it or show it on the settings page.
 */
class AlertSender
{
    public const LINE_PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    public const TELEGRAM_URL = 'https://api.telegram.org/bot%s/sendMessage';

    /**
     * @param  array<string, mixed>  $config  from AlertSettings::channels()
     */
    public function send(string $channel, array $config, string $title, string $body, ?string $url = null): void
    {
        $text = trim($title."\n".$body.($url ? "\n".$url : ''));

        try {
            match ($channel) {
                'line' => $this->check('LINE', Http::withToken($config['token'])->timeout(10)->post(self::LINE_PUSH_URL, [
                    'to' => $config['to'],
                    'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 5000)]],
                ])),
                'telegram' => $this->check('Telegram', Http::timeout(10)->post(sprintf(self::TELEGRAM_URL, $config['token']), [
                    'chat_id' => $config['chat_id'],
                    'text' => mb_substr($text, 0, 4096),
                    'disable_web_page_preview' => true,
                ])),
                'mail' => Notification::route('mail', $config['recipients'])->notifyNow(new AlertMail($title, $body, $url)),
            };
        } catch (ConnectionException) {
            // Not the exception itself: its message carries the URL, and Telegram's has the bot token in it.
            throw new RuntimeException(__('alerts.unreachable', ['channel' => __("alerts.channels.{$channel}")]));
        }
    }

    private function check(string $service, Response $response): void
    {
        if ($response->failed()) {
            $message = $response->json('message') ?? $response->json('description') ?? $response->body();
            throw new RuntimeException("{$service} {$response->status()}: ".mb_substr((string) $message, 0, 200));
        }
    }
}

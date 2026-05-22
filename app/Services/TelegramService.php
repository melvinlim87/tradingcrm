<?php

namespace App\Services;

use App\Models\TelegramTopic;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramService
{
    private const ENDPOINT = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly ?string $botToken = null,
        private readonly ?int $defaultChatId = null,
    ) {
    }

    /**
     * Send a plain message to a specific topic (forum thread) within the configured chat.
     */
    public function sendToTopic(string $topicName, string $message, string $parseMode = 'HTML'): bool
    {
        $threadId = TelegramTopic::threadIdFor($topicName);

        if ($threadId === null) {
            Log::warning('TelegramService: unknown topic', ['topic' => $topicName]);
            return false;
        }

        return $this->send($message, $threadId, null, $parseMode);
    }

    public function sendToAccount(int $mt5AccountId, string $message, string $parseMode = 'HTML'): bool
    {
        $threadId = TelegramTopic::threadIdForAccount($mt5AccountId);

        if ($threadId === null) {
            Log::warning('TelegramService: no topic bound to account', ['mt5_account_id' => $mt5AccountId]);
            return false;
        }

        return $this->send($message, $threadId, null, $parseMode);
    }

    public function send(
        string $message,
        ?int $messageThreadId = null,
        ?int $chatId = null,
        string $parseMode = 'HTML',
    ): bool {
        $token = $this->botToken ?? config('services.telegram.bot_token');
        $chatId = $chatId ?? $this->defaultChatId ?? (int) config('services.telegram.default_chat_id');

        if (! $token) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured');
        }
        if (! $chatId) {
            throw new RuntimeException('TELEGRAM_DEFAULT_CHAT_ID is not configured');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ];

        if ($messageThreadId !== null) {
            $payload['message_thread_id'] = $messageThreadId;
        }

        $response = Http::timeout(15)
            ->retry(2, 500)
            ->post(self::ENDPOINT . $token . '/sendMessage', $payload);

        if ($response->failed()) {
            Log::error('Telegram send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'chat_id' => $chatId,
                'thread_id' => $messageThreadId,
            ]);
            return false;
        }

        return (bool) ($response->json('ok') ?? false);
    }

    /**
     * Convenience for drawdown alerts. Sends to per-account topic AND risk_management.
     */
    public function drawdownAlert(
        int $mt5AccountId,
        string $accountLabel,
        float $drawdownPct,
        float $thresholdPct,
        float $equity,
    ): void {
        $msg = sprintf(
            "<b>🚨 Drawdown Alert</b>\n" .
            "Account: <code>%s</code>\n" .
            "Drawdown: <b>-%.2f%%</b> (threshold: %.2f%%)\n" .
            "Equity: <b>%s</b>\n" .
            "Time: %s GMT+8",
            $accountLabel,
            $drawdownPct,
            $thresholdPct,
            number_format($equity, 2),
            now('Asia/Singapore')->format('Y-m-d H:i'),
        );

        $this->sendToAccount($mt5AccountId, $msg);
        $this->sendToTopic('risk_management', $msg);
    }
}

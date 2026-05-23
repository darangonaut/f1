<?php declare(strict_types=1);

namespace App\Services;


final class TelegramNotifier
{
    public function __construct(
        private readonly ?string $botToken = null,
        private readonly ?string $chatId = null,
    ) {}

    public function isConfigured(): bool
    {
        return !empty($this->botToken) && !empty($this->chatId);
    }

    /** Send a Markdown-formatted message. Returns true on success. */
    public function send(string $text): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $payload = http_build_query([
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'MarkdownV2',
            'disable_web_page_preview' => 'true',
        ]);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            return false;
        }
        $data = json_decode($resp, true);
        return !empty($data['ok']);
    }

    /** Escape MarkdownV2 special chars. */
    public static function escape(string $s): string
    {
        return preg_replace('/([_*\[\]()~`>#+=|{}.!\\\\\-])/', '\\\\$1', $s);
    }
}

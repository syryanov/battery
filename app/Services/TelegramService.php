<?php 

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Сервис взаимодействия с Telegram.
 */
class TelegramService
{
    /**
     * Токен телеграм-бота
     * @var string
     */
    private $botToken;

    /**
     * URL API телеграм
     * @var string
     */
    private $url;

    function __construct()
    {
        $this->botToken = config('telegram.botToken');
        $this->url = config('telegram.url');
    }

    /**
     * Отправляет запрос к Telegram.
     * 
     * @param int $chatId Идентификатор пользователя в телеграм
     * @param string $message Сообщение
     * 
     * @return void
     */
    public function send(int $chatId, string $message): void
    {
        $response = Http::post("{$this->url}/bot{$this->botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);
    }
}

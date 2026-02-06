<?php 

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сервис взаимодействия с LLM.
 */
class LLMService
{
    /**
     * Тип модели
     * @var string
     */
    private $model;

    /**
     * Токен авторизации
     * @var string
     */
    private $token;

    /**
     * Ссылка на API LLM
     * @var string
     */
    private $url;

    function __construct()
    {
        $this->model = config('llm.model');
        $this->token = config('llm.token');
        $this->url = config('llm.url');
    }

    /**
     * Отправляет запрос к LLM и возвращает ответ.
     * 
     * @param array $data Массив сообщений для LLM
     * @param bool $jsonResponse Формат ответа
     * 
     * @return string|null JSON-строка или текстовая строка ответа LLM, либо null при ошибке
     */
    public function send (array $data, bool $jsonResponse = false): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->token}"
        ])->post("{$this->url}/chat/completions", [
            'model' => $this->model,
            'messages' => $data,
            'stream' => false,
            'response_format' => ['type' => $jsonResponse ? 'json_object' : 'text'],
        ]);

        if ($response->ok()) {
            $response = $response->json();

            $responseMessage = $response['choices'][0]['message']['content'] ?? null;

            Log::info("Ответ LLM: {$responseMessage}");

            return $responseMessage;
        }

        if ($response->failed()) {
            Log::error('Ошибка запроса к LLM');
        }

        return null;
    }
}
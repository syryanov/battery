<?php

namespace App\Http\Controllers;

use App\Services\MessageHandlerService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер обработки входящих сообщений Telegram webhook.
 */
class MessageHandlerController extends Controller
{
    /**
     * Обработка входящего сообщения от Telegram.
     *
     * @param  Request  $request
     * @return Response Всегда возвращает ответ для подтверждения webhook (200 OK). Иначе телеграм делает до 10 повторных попыток, растягивает время между попытками и блокирует поток сообщений.
     */
    public function handleMessage(Request $request): Response
    {
        try {
            $userId = $request->input('message.from.id');
            $message = $request->input('message.text');

            Log::info("\nВходящее сообщение.\nПользователь: {$userId}\nСообщение: {$message}");

            $messageHandlerService = new MessageHandlerService();

            $messageHandlerService->handle($userId, $message);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());
        }

        return response('OK', 200);
    }
}
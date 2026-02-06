<?php 

namespace App\Services;

use App\Services\TelegramService;
use App\Models\Task;

/**
 * Сервис обработки активных напоминаний.
 */
class NotificationService
{
    public static function sendActive(): void
    {
        $telegram = new TelegramService();

        $activeTasks = Task::where('status', 'active')
            ->whereBetween('deadline_at', [now(), now()->addMinute()])
            ->get();

        if ($activeTasks->isNotEmpty()) {
            foreach ($activeTasks as $task) {
                $message = "Напоминаю, у вас скоро событие:\n\n{$task->title}\nДата и время: {$task->deadline_at}\nДетали: {$task->description}";

                $telegram->send($task->telegram_user_id, $message);

                $task->status = 'done';
                $task->save();
            }
        }
    }
}
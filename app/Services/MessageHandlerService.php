<?php 

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

use App\Models\ConversationState;
use App\Models\Task;

use App\Services\LLMService;
use App\Services\TelegramService;
use App\Repositories\PromptsRepository;

/**
 * Сервис обработки входящих сообщений от пользователей Telegram
 * 
 * Обрабатывает команды, управляет состоянием диалога и выполняет операции
 * с задачами (напоминаниями) через LLM-интерфейс
 * 
 * @package App\Services
 */
class MessageHandlerService
{
    /**
     * @var LLMService Сервис для работы с языковой моделью
     */
    private $llm;

    /**
     * @var TelegramService Сервис для отправки сообщений в Telegram
     */
    private $telegram;

    /**
     * Максимальная длина сообщения от пользователя
     */
    private const MAX_MESSAGE_LENGTH = 1000;

    /**
     * Стандартное состояние диалога
     */
    private const DEFAULT_STATE = 'free';

    /**
     * Стартовая команда
     */
    private const START_MESSAGE = '/start';

    function __construct()
    {
        $this->llm = new LLMService();
        $this->telegram = new TelegramService();
    }

    /**
     * Основной метод обработки входящего сообщения
     * 
     * @param int $userId ID пользователя в Telegram
     * @param string $message Текст сообщения
     * @return void
     * @throws \Exception Если пользователь не найден или сообщение слишком длинное
     */
    public function handle(int $userId, string $message): void
    {
        // Получаем state пользователя
        $state = ConversationState::where('telegram_user_id', $userId)->first();

        // Возвращаем ошибку если state не существует и сообщение не является стартовым
        if (!$state && $message != self::START_MESSAGE) {
            throw new \Exception('User not found');
        }

        // Создаём state пользователя, если сообщение является стартовым
        if ($message == self::START_MESSAGE) {
            $this->handleCreateUserState($userId);

            return;
        }

        // Проверяем длину сообщения. Если сообщение слишком большое, отправляем предупреждение в телеграм
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $this->telegram->send($userId, 'Слишком большой объём текста. Я не могу разобраться. Попробуйте сократить.');

            throw new \Exception('Very long message');
        }

        // Предобработка конкретных сценариев по статическим командам
        $staticCommands = [
            '/new'    => fn () => $this->handleTaskOperation($userId, $state, 'Я хочу создать новое напоминание!', 'creating_task', PromptsRepository::getCreateTaskPrompt()),
            '/tasks'  => fn () => $this->handleGetTasks($userId),
            '/task'   => fn () => $this->handleTaskOperation($userId, $state, 'Я хочу прочитать конкретное напоминание!', 'reading_task', PromptsRepository::getReadTaskPrompt()),
            '/edit'   => fn () => $this->handleTaskOperation($userId, $state, 'Я хочу обновить напоминание!', 'updating_task', PromptsRepository::getUpdateTaskPrompt()),
            '/delete' => fn () => $this->handleTaskOperation($userId, $state, 'Я хочу удалить напоминание!', 'deleting_task', PromptsRepository::getDeleteTaskPrompt()),
            '/cancel' => fn () => $this->cancelAction($userId, $state),
        ];

        if (isset($staticCommands[$message])) {
            $this->resetState($state);

            // Выполняем сценанарий
            $staticCommands[$message]();

            return;
        }

        // Определяем тип сценария по сообщению. Если пользователю не присвоен сценарий, то определяет модель. Если сценарий присвоен, то идём по присвоенному сценарию.
        $action = $state->state === self::DEFAULT_STATE
            ? $this->getTypeOfMessage($message)
            : $state->state;

        // Сценарии
        $handlers = [
            'creating_task'  => fn () => $this->handleTaskOperation($userId, $state, $message, 'creating_task', PromptsRepository::getCreateTaskPrompt()),
            'updating_task'  => fn () => $this->handleTaskOperation($userId, $state, $message, 'updating_task', PromptsRepository::getUpdateTaskPrompt()),
            'deleting_task'  => fn () => $this->handleTaskOperation($userId, $state, $message, 'deleting_task', PromptsRepository::getDeleteTaskPrompt()),
            'reading_task'   => fn () => $this->handleTaskOperation($userId, $state, $message, 'reading_task', PromptsRepository::getReadTaskPrompt()),
            'list_tasks'     => fn () => $this->handleGetTasks($userId),
        ];
        
        // Если есть такой сценарий, выполняем его
        if (isset($handlers[$action])) {
            $handlers[$action]();
            
            return;
        }
        
        // Если нет подходящего типа, значит запрос нейтральный (без конкретной задачи). Отвечаем на него.
        $this->telegram->send($userId, 'Я люблю общение, но сейчас готов только создавать напоминания для вас. С чего начнём?');

        return;
    }

    /**
     * Создает состояние диалога для нового пользователя
     * 
     * @param int $userId ID пользователя в Telegram
     * @return void
     */
    private function handleCreateUserState(int $userId):void
    {
        if (ConversationState::where('telegram_user_id', $userId)->exists()) {
            $this->telegram->send($userId, 'Мы уже знакомы. Давно вас не видел. С чего начнём?');

            return;
        }

        $state = ConversationState::create([
            'telegram_user_id' => $userId,
            'state' => self::DEFAULT_STATE,
            'payload' => [],
        ]);

        // Отправляем приветственное сообщение в телеграм
        $this->telegram->send($userId, 'Добро пожаловать! Я ваш персональный помощник. Я умею создавать, обновлять и удалять напоминания. Показывать список активных напоминаний. Напоминать вам за некоторое время до начала события. С чего начнём?');
    }

    /**
     * Определяет тип сообщения с помощью LLM
     * 
     * @param string $message Текст сообщения пользователя
     * @return string Тип действия (creating_task, updating_task, deleting_task, reading_task, list_tasks)
     */
    private function getTypeOfMessage(string $message): string
    {
        $prompt = [
            [
                'role' => 'system',
                'content' => PromptsRepository::getMessageDetectionPrompt()
            ],
            [
                'role' => 'user',
                'content' => "Сообщение: {$message}"
            ]
        ];

        return $this->llm->send($prompt);
    }

    /**
     * Отменяет текущее действие и сбрасывает состояние диалога
     * 
     * @param int $userId ID пользователя в Telegram
     * @param ConversationState $state Объект состояния диалога
     * @return void
     */
    private function cancelAction(int $userId, ConversationState $state): void
    {
        $state->payload = [];
        $state->state = self::DEFAULT_STATE;
        $state->save();

        $this->telegram->send($userId, 'Текущие действия отменены. Я готов к новым задачкам. С чего начнём?');
    }

    /**
     * Получает и отправляет список активных задач пользователя
     * 
     * @param int $userId ID пользователя в Telegram
     * @return void
     */
    private function handleGetTasks(int $userId): void
    {
        $tasks = Task::where('telegram_user_id', $userId)->where('status', 'active')->get();

        if ($tasks->isNotEmpty()) {
            $responseMessage = "";

            foreach ($tasks as $task) {
                $responseMessage .= "№{$task->id}. {$task->title}.\nДетали: {$task->description}\nДата и время: {$task->deadline_at}\nСтатус: {$task->status}\n\n";
            }

            $this->telegram->send($userId, "Список ваших напоминаний:\n\n{$responseMessage}");
        } else {
            $this->telegram->send($userId, 'У вас нет активных напоминаний');
        }
    }

    /**
     * Сбрасывает состояние диалога к значениям по умолчанию
     * 
     * @param ConversationState $state Объект состояния диалога
     * @return void
     */
    private function resetState(ConversationState $state): void
    {
        $state->payload = [];
        $state->state = self::DEFAULT_STATE;
        $state->save();
    }

    /**
     * Обрабатывает операцию с задачей (создание, чтение, обновление, удаление)
     * 
     * @param int $userId ID пользователя в Telegram
     * @param ConversationState $state Объект состояния диалога
     * @param string $message Сообщение пользователя
     * @param string $operationType Тип операции
     * @param string $prompt Системный промпт для LLM
     * @return void
     * @throws \Throwable При ошибках выполнения операции
     */
    private function handleTaskOperation(
        int $userId,
        ConversationState $state,
        string $message,
        string $operationType,
        string $prompt,
    ): void {
        DB::beginTransaction();

        try {

            // Обновляем текущую операцию в стейте
            $state->state = $operationType;
            $state->save();
            
            // Если нет истории чата, закидываем системный промпт + первое пользователськое сообщение
            if (empty($state->payload)) {
                $prompt = [
                    [
                        'role' => 'system',
                        'content' => $prompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => "Сообщение: {$message}",
                    ]
                ];

                $state->payload = $prompt;
                $state->save();
            } else {
                // Если есть история чата, дополняем её пользовательским сообщением
                $prompt = [...$state->payload, ['role' => 'user', 'content' => $message]];
            }
        
            // Запрашиваем LLM
            $llmResponse = $this->llm->send($prompt, true);

            $llmResponse = json_decode($llmResponse, true);
        
            // Если сценарий не закончен, продолжаем общеться с моделью
            if (!$llmResponse['status']) {
                $state->payload = [...$prompt, ['role' => 'assistant', 'content' => $llmResponse['message']]];
                $state->save();

                $this->telegram->send($userId, $llmResponse['message']);

                DB::commit();

                return;
            }

            $handlers = [
                'creating_task'  => fn () => $this->handleCreateTask($userId, $state, $llmResponse['data']),
                'updating_task'  => fn () => $this->handleUpdateTask($userId, $state, $llmResponse['data']),
                'deleting_task'  => fn () => $this->handleDeleteTask($userId, $state, $llmResponse['data']),
                'reading_task'   => fn () => $this->handleGetTask($userId, $state, $llmResponse['data']),
            ];
        
            // Если сценарий окончен, выполняем итоговую операцию
            $handlers[$operationType]();

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();

            Log::error('Ошибка при обработке операции с задачей', [
                'user_id' => $userId,
                'operation_type' => $operationType,
                'error_message' => $th->getMessage(),
                'error_trace' => $th->getTraceAsString(),
            ]);
        }
    }

    /**
     * Получает и отправляет информацию о конкретной задаче
     * 
     * @param int $userId ID пользователя в Telegram
     * @param ConversationState $state Объект состояния диалога
     * @param array $data Данные от LLM (должен содержать 'id')
     * @return void
     */
    private function handleGetTask(int $userId, ConversationState $state, array $data): void
    {
        $task = Task::where('id', $data['id'])->where('telegram_user_id', $userId)->first();

        if (!$task) {
            $this->telegram->send($userId, 'К сожалению я не нашел напоминание с таким номером среди ваших напоминаний.');
        }

        $state->payload = [];
        $state->state = self::DEFAULT_STATE;
        $state->save();

        $taskMessage = "Напоминание №{$task->id}!\n\nНазвание: {$task->title}\nДата и время: {$task->deadline_at}\nДетали: {$task->description}";

        $this->telegram->send($userId, $taskMessage);
    }

    /**
     * Создает новую задачу (напоминание)
     * 
     * @param int $userId ID пользователя в Telegram
     * @param ConversationState $state Объект состояния диалога
     * @param array $data Данные от LLM (должен содержать 'title', 'description', 'deadline_at')
     * @return void
     */
    private function handleCreateTask(int $userId, ConversationState $state, array $data): void
    {
        // Валидация
        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'deadline_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);
    
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $task = Task::create([
            'telegram_user_id' => $userId,
            'status' => 'active',
            'title' => $data['title'],
            'description' => $data['description'],
            'deadline_at' => Carbon::createFromFormat('Y-m-d H:i:s', $data['deadline_at']),
        ]);

        $state->payload = [];
        $state->state = self::DEFAULT_STATE;
        $state->save();

        $taskMessage = "Напоминание №{$task->id} успешно создано!\n\nНазвание: {$task->title}\nДата и время: {$task->deadline_at}\nДетали: {$task->description}";

        $this->telegram->send($userId, $taskMessage);
    }

    /**
     * Обновляет существующую задачу
     * 
     * @param int $userId ID пользователя в Telegram
     * @param ConversationState $state Объект состояния диалога
     * @param array $data Данные от LLM (должен содержать 'id', опционально 'title', 'description', 'deadline_at')
     * @return void
     */
    private function handleUpdateTask(int $userId, ConversationState $state, array $data): void
    {
        $validator = Validator::make($data, [
            'id' => ['required', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'deadline_at' => ['sometimes', 'required', 'date_format:Y-m-d H:i:s'],
        ]);
    
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $task = Task::where('id', $data['id'])->where('telegram_user_id', $userId)->first();

        if (!$task) {
            $this->telegram->send($userId, 'К сожалению я не нашел напоминание с таким номером среди ваших напоминаний.');
        }

        if (isset($data['deadline_at'])) {
            $data['deadline_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $data['deadline_at']);
        }

        $task->update($data);

        $state->payload = [];
        $state->state = self::DEFAULT_STATE;
        $state->save();

        $taskMessage = "Напоминание успешно обновлено!\n\nНомер: {$task->id}\nНазвание: {$task->title}\nДата и время: {$task->deadline_at}\nДетали: {$task->description}";

        $this->telegram->send($userId, $taskMessage);
    }

    /**
     * Удаляет задачу (помечает как удаленную)
     * 
     * @param int $userId ID пользователя в Telegram
     * @param ConversationState $state Объект состояния диалога
     * @param array $data Данные от LLM (должен содержать 'id')
     * @return void
     */
    private function handleDeleteTask(int $userId, ConversationState $state, array $data): void
    {
        $task = Task::where('id', $data['id'])->where('telegram_user_id', $userId)->first();

        if (!$task) {
            $this->telegram->send($userId, 'К сожалению я не нашел напоминание с таким номером среди ваших напоминаний.');
        }

        if (isset($data['deadline_at'])) {
            $data['deadline_at'] = Carbon::createFromTimestamp($data['deadline_at']);
        }

        $task->status = 'deleted';
        $task->save();

        $state->payload = [];
        $state->state = self::DEFAULT_STATE;
        $state->save();

        $this->telegram->send($userId, "Напоминание №{$task->id} успешно удалено!");
    }
}
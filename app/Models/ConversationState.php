<?php 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель хранения состояний диалогов пользователей Telegram.
 */
class ConversationState extends Model
{
    /**
     * Имя таблицы в базе данных
     * @var string
     */
    protected $table = 'conversations_states';

    /**
     * Первичный ключ не используется
     * @var string|null
     */
    protected $primaryKey = null;

    /**
     * Модель не использует автоинкремент первичного ключа
     * @var bool
     */
    public $incrementing = false;

    /**
     * Модель не использует timestamps
     * @var bool
     */
    public $timestamps = false;

    /**
     * Атрибуты, доступные для массового присвоения
     * @var array
     */
    protected $fillable = [
        'telegram_user_id',
        'state',
        'payload',
    ];

    /**
     * Приведение типов атрибутов
     * @return array
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Связь "один-ко-многим" с моделью Task
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'telegram_user_id', 'telegram_user_id');
    }
}
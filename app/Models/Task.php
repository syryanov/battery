<?php 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ConversationState;

/**
 * Модель напоминания пользователя.
 */
class Task extends Model
{
    /**
     * Имя таблицы в базе данных
     * @var string
     */
    protected $table = 'tasks';

    /**
     * Атрибуты, доступные для массового присвоения
     * @var array
     */
    protected $fillable = [
        'telegram_user_id',
        'status',
        'title',
        'description',
        'deadline_at',
    ];

    /**
     * Связь с моделью ConversationState.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ConversationState::class, 'telegram_user_id', 'telegram_user_id');
    }
}
<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiConversation extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_ai_conversations';

    protected $fillable = [
        'user_id',
        'title',
        'context',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function user()
    {
        return $this->belongsTo(VelaUser::class, 'user_id');
    }

    public function messages()
    {
        // Tie-break by id: tool-call loops insert several messages in the same
        // second, so ordering by created_at alone returns them in undefined
        // order — which makes the assistant see a jumbled transcript and
        // sometimes echo earlier turns. id ascending is monotonic per-insert.
        return $this->hasMany(AiMessage::class, 'conversation_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function actionLogs()
    {
        return $this->hasMany(AiActionLog::class, 'conversation_id');
    }
}

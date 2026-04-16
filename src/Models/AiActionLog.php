<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class AiActionLog extends Model
{
    public $table = 'vela_ai_action_logs';

    protected $fillable = [
        'conversation_id',
        'message_id',
        'user_id',
        'tool_name',
        'parameters',
        'previous_state',
        'result',
        'status',
        'undone_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'previous_state' => 'array',
        'result' => 'array',
        'undone_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $dates = ['undone_at', 'created_at', 'updated_at'];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function message()
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(VelaUser::class, 'user_id');
    }

    public function canUndo(): bool
    {
        return $this->status === 'completed' && $this->undone_at === null;
    }
}

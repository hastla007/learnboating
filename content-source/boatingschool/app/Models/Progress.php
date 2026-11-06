<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Progress extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'progress';

    protected array $compositeKey = ['tenant_id', 'user_id', 'question_id'];

    public $incrementing = false;

    const CREATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'question_id', 'attempt_count', 'correct_count',
        'incorrect_count', 'current_streak', 'mastery_score', 'learning_state',
        'first_seen_at', 'last_seen_at', 'last_correct_at',
    ];

    protected function casts(): array
    {
        return [
            'mastery_score' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_correct_at' => 'datetime',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentQuestion::class, 'question_id');
    }
}

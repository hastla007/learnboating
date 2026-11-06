<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSessionQuestion extends Model
{
    use BelongsToTenant;
    use HasCompositePrimaryKey;

    protected $table = 'exam_session_question';

    protected array $compositeKey = ['exam_session_id', 'question_id'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'exam_session_id', 'question_id', 'revision_id', 'position',
        'selected_answer_id', 'selected_answer_ids', 'self_assessed_known', 'correct', 'answered_at', 'revealed_at',
    ];

    protected function casts(): array
    {
        return [
            'correct' => 'boolean',
            'self_assessed_known' => 'boolean',
            'selected_answer_ids' => 'array',
            'answered_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContentQuestionRevision::class, 'revision_id');
    }

    public function selectedAnswer(): BelongsTo
    {
        return $this->belongsTo(ContentAnswer::class, 'selected_answer_id');
    }
}

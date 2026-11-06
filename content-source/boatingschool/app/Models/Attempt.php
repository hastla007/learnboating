<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Attempt ist unveränderlich (immutable): es gibt bewusst kein update()
 * im Anwendungscode. Jede Antwort erzeugt einen neuen Datensatz.
 */
class Attempt extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'attempt';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'question_id', 'revision_id', 'course_id',
        'selected_answer_id', 'selected_answer_ids', 'correct', 'context', 'response_time_ms',
        'started_at_ms',
    ];

    protected function casts(): array
    {
        return [
            'correct' => 'boolean',
            'selected_answer_ids' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentQuestion::class, 'question_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContentQuestionRevision::class, 'revision_id');
    }
}

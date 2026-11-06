<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentAnswer extends Model
{
    use HasUuids;

    protected $table = 'content_answer';

    public $timestamps = false;

    protected $fillable = ['revision_id', 'answer_key', 'answer_text', 'is_correct', 'sort_order'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ContentQuestionRevision::class, 'revision_id');
    }
}

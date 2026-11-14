<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NavigationQuestion extends Model
{
    use HasUuids;

    protected $table = 'navigation_question';

    public $timestamps = false;

    protected $fillable = ['navigation_task_id', 'question_number', 'question_text', 'answer_text', 'sort_order'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(NavigationTask::class, 'navigation_task_id');
    }
}

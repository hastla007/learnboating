<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamPaper extends Model
{
    use HasUuids;

    protected $table = 'exam_paper';

    public $timestamps = false;

    protected $fillable = ['course_id', 'paper_number', 'sort_order'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function paperQuestions(): HasMany
    {
        return $this->hasMany(ExamPaperQuestion::class, 'exam_paper_id')->orderBy('position');
    }
}

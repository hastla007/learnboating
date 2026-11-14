<?php

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamPaperQuestion extends Model
{
    use HasCompositePrimaryKey;

    protected $table = 'exam_paper_question';

    protected array $compositeKey = ['exam_paper_id', 'question_id'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['exam_paper_id', 'question_id', 'position'];

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentQuestion::class, 'question_id');
    }
}

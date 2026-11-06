<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'exam_session';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'rule_set_id', 'paper_id', 'status',
        'started_at', 'submitted_at', 'score', 'passed',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
            'score' => 'float',
            'passed' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ExamRuleSet::class, 'rule_set_id');
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'paper_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamSessionQuestion::class, 'exam_session_id')->orderBy('position');
    }
}

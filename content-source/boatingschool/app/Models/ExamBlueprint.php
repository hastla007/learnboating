<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamBlueprint extends Model
{
    use HasUuids;

    protected $table = 'exam_blueprint';

    public $timestamps = false;

    protected $fillable = ['rule_set_id', 'module_id', 'question_count', 'selection_rules'];

    protected function casts(): array
    {
        return ['selection_rules' => 'array'];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }
}

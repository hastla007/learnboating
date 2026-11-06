<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    use HasUuids;

    protected $table = 'module';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'version', 'status', 'description'];

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(ContentQuestion::class, 'module_content', 'module_id', 'question_id')
            ->withPivot(['sort_order', 'required']);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(CourseDefinition::class, 'course_module', 'module_id', 'course_id')
            ->withPivot(['sort_order', 'required', 'rules']);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoModule extends Model
{
    use HasUuids;

    protected $table = 'video_module';

    public $timestamps = false;

    protected $fillable = ['course_id', 'title', 'sort_order'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(VideoLesson::class, 'video_module_id')->orderBy('sort_order');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoLessonStep extends Model
{
    use HasUuids;

    protected $table = 'video_lesson_step';

    public $timestamps = false;

    protected $fillable = ['video_lesson_id', 'title', 'image_path', 'sort_order'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(VideoLesson::class, 'video_lesson_id');
    }
}

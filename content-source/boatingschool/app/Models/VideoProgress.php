<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoProgress extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'video_progress';

    protected array $compositeKey = ['tenant_id', 'user_id', 'video_lesson_id'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'video_lesson_id', 'completed', 'last_position_seconds', 'updated_at'];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(VideoLesson::class, 'video_lesson_id');
    }
}

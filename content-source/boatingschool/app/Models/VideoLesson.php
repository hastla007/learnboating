<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoLesson extends Model
{
    use HasUuids;

    protected $table = 'video_lesson';

    public $timestamps = false;

    /** Öffentliches Platzhaltervideo der Seeder (siehe README), bis echte Kursvideos hinterlegt sind. */
    public const PLACEHOLDER_URL = 'https://www.w3schools.com/html/mov_bbb.mp4';

    protected $fillable = ['video_module_id', 'title', 'video_url', 'duration_seconds', 'sort_order'];

    public function isPlaceholder(): bool
    {
        return $this->video_url === self::PLACEHOLDER_URL;
    }

    /**
     * Kopien dieser Lektion in anderen Kursen: die Video-Seeder spiegeln
     * Kapitel samt Lektionen in Kombi-/Ergänzungskurse als eigene Zeilen
     * (z. B. ComboVideoCourseSeeder). Erkannt über gleichen Lektions- und
     * Kapiteltitel und noch dieselbe Video-URL -- eine bereits abweichend
     * gepflegte Kopie gilt bewusst nicht mehr als Kopie.
     */
    public function copies(): Builder
    {
        $moduleTitle = $this->module->title;

        return self::query()
            ->whereKeyNot($this->getKey())
            ->where('title', $this->title)
            ->where('video_url', $this->video_url)
            ->whereHas('module', fn (Builder $q) => $q->where('title', $moduleTitle));
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(VideoModule::class, 'video_module_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(VideoLessonStep::class, 'video_lesson_id')->orderBy('sort_order');
    }

    /** "Zu den Fragen"-Quiz nach dem Video (siehe VideoQuizController). */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(ContentQuestion::class, 'video_lesson_question', 'video_lesson_id', 'question_id')
            ->withPivot('sort_order')
            ->orderBy('video_lesson_question.sort_order');
    }

    public function formattedDuration(): string
    {
        if (! $this->duration_seconds) {
            return '';
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }
}

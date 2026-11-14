<?php

namespace App\Support;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Illuminate\Support\Collection;

/**
 * Liefert die Lektionen eines Videokurses in Abspielreihenfolge -- von
 * VideoCourseController (Player) und VideoQuizController ("Zu den Fragen")
 * gemeinsam genutzt, damit beide dieselbe Vorstellung von "nächste Lektion"
 * haben (z. B. für den "Nächstes Video"-Button nach der letzten Quizfrage).
 */
class VideoCourseLessons
{
    /** @return Collection<int, VideoLesson> */
    public static function ordered(CourseDefinition $course, ?array $moduleIds = null): Collection
    {
        $query = $course->videoModules()->with('lessons');

        if ($moduleIds) {
            $query->whereIn('id', $moduleIds);
        } else {
            // "Knoten" ist als eigene Kachel/Seite ausgelagert (siehe
            // CourseController) und taucht im normalen, unfilterten
            // Kursfluss bewusst nicht als Kapitel auf -- nur über die
            // gezielte ?kapitel=<id>-Verlinkung der Kachel erreichbar.
            $query->where('title', '!=', 'Knoten');
        }

        return $query->get()
            ->flatMap(fn ($module) => $module->lessons->map(fn ($lesson) => tap($lesson, fn ($l) => $l->setRelation('module', $module))));
    }
}

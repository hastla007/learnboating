<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExamRuleSet extends Model
{
    use HasUuids;

    protected $table = 'exam_rule_set';

    public $timestamps = false;

    protected $fillable = [
        'course_id', 'version', 'valid_from', 'valid_until', 'time_limit_seconds',
        'passing_rule', 'status', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'passing_rule' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(ExamBlueprint::class, 'rule_set_id');
    }

    public function isVerified(): bool
    {
        return $this->status === 'published' && $this->verified_at !== null;
    }

    /**
     * Amtliche Teilgrenzen (passing_rule.parts), z. B. SBF See: Basisfragen
     * 5 von 7 UND spezifische Fragen See 18 von 23 -- 23 Richtige gesamt
     * mit nur 4 Basisfragen bestehen nicht. Jede Frage zählt zum ersten
     * Teil, dessen Modul sie enthält. Ein Teil ohne Fragen in der Session
     * gilt als nicht bestanden. Erwartet ExamSessionQuestions mit bereits
     * gesetztem correct.
     *
     * @return list<array{label: string, correct: int, total: int, min_correct: int, of: int, passed: bool}>
     */
    public function partResults(Collection $sessionQuestions): array
    {
        $parts = $this->passing_rule['parts'] ?? [];

        if ($parts === []) {
            return [];
        }

        $moduleIds = Module::whereIn('code', array_column($parts, 'module'))->pluck('id', 'code');
        $questionIdsByModule = DB::table('module_content')
            ->whereIn('module_id', $moduleIds->values())
            ->whereIn('question_id', $sessionQuestions->pluck('question_id'))
            ->get(['module_id', 'question_id'])
            ->groupBy('module_id')
            ->map(fn ($rows) => $rows->pluck('question_id')->all());

        $assigned = [];
        $results = [];

        foreach ($parts as $part) {
            $inModule = $questionIdsByModule->get($moduleIds->get($part['module']), []);
            $questions = $sessionQuestions->filter(fn ($sq) => ! isset($assigned[$sq->question_id]) && in_array($sq->question_id, $inModule, true));
            $questions->each(function ($sq) use (&$assigned) {
                $assigned[$sq->question_id] = true;
            });

            $total = $questions->count();
            $correct = $questions->where('correct', true)->count();
            $results[] = [
                'label' => $part['label'],
                'correct' => $correct,
                'total' => $total,
                'min_correct' => $part['min_correct'],
                'of' => $part['of'],
                'passed' => $total > 0 && $correct / $total >= $part['min_correct'] / $part['of'] - 1e-9,
            ];
        }

        return $results;
    }

    /** Kurztext der Bestehensgrenze für einen Bogen mit $total Fragen, z. B. "Basisfragen 5/7 · See 18/23 · 60 Minuten". */
    public function passingSummary(int $total): string
    {
        $parts = $this->passing_rule['parts'] ?? [];
        $summary = $parts !== []
            ? implode(' · ', array_map(fn ($part) => "{$part['label']} {$part['min_correct']}/{$part['of']}", $parts))
            : 'Bestanden ab '.(int) ceil(($this->passing_rule['min_correct_ratio'] ?? 0.75) * $total - 1e-9)." von {$total} richtigen Antworten";

        return $this->time_limit_seconds ? $summary.' · '.intdiv($this->time_limit_seconds, 60).' Minuten' : $summary;
    }

    /** Hinweis auf amtliche Prüfungsteile, die die Simulation nicht automatisch bewertet (z. B. die Navigationsaufgabe SBF See). */
    public function note(): ?string
    {
        return $this->passing_rule['note'] ?? null;
    }
}

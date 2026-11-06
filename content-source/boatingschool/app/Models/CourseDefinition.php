<?php

namespace App\Models;

use App\Support\ComboExamGroups;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CourseDefinition extends Model
{
    use HasUuids;

    protected $table = 'course_definition';

    protected $fillable = ['tenant_id', 'code', 'name', 'course_type', 'status', 'site_enabled'];

    protected function casts(): array
    {
        return ['site_enabled' => 'boolean'];
    }

    /**
     * Kurse sind entweder global (tenant_id NULL, Plattform-Katalog) oder
     * mandantenspezifisch. Der Scope spiegelt exakt die RLS-Policy
     * course_definition_isolation wider.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);
            if ($context->has()) {
                $builder->where(function ($q) use ($context) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', $context->id());
                });
            }
        });
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'course_module', 'course_id', 'module_id')
            ->withPivot(['sort_order', 'required', 'rules'])
            ->orderByPivot('sort_order');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'course_id');
    }

    public function examRuleSets(): HasMany
    {
        return $this->hasMany(ExamRuleSet::class, 'course_id');
    }

    public function videoModules(): HasMany
    {
        return $this->hasMany(VideoModule::class, 'course_id')->orderBy('sort_order');
    }

    public function navigationTasks(): HasMany
    {
        return $this->hasMany(NavigationTask::class, 'course_id')->orderBy('sort_order');
    }

    public function examPapers(): HasMany
    {
        return $this->hasMany(ExamPaper::class, 'course_id')->orderBy('sort_order');
    }

    /**
     * IDs aller Fragen, die zum Kurs gehören: Modulfragen plus die Fragen
     * seiner Prüfungsbögen. Bug-Review (SRC & UBI: Fragen der UBI-Bögen
     * gehören nicht zum Kurs): der Kombikurs lernt bewusst nur SRC und die
     * UBI-Ergänzung, seine UBI-Bögen 101-112 fragen aber den vollen
     * UBI-Katalog ab -- Favoriten, Collections und "Falsche Fragen erneut
     * lernen" müssen auch diese Fragen als Kursinhalt akzeptieren.
     *
     * @return Collection<int, string>
     */
    public function contentQuestionIds(): Collection
    {
        $this->loadMissing('modules.questions');

        return $this->modules->flatMap(fn ($module) => $module->questions)->pluck('id')
            ->merge($this->examPaperQuestionIds())
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> IDs der Fragen aller Prüfungsbögen des Kurses. */
    public function examPaperQuestionIds(): Collection
    {
        return ExamPaperQuestion::whereIn('exam_paper_id', ExamPaper::where('course_id', $this->id)->select('id'))
            ->pluck('question_id')
            ->unique()
            ->values();
    }

    /** Aktive Praxistrainer-Karten (was Lernende sehen). */
    public function praxisTasks(): HasMany
    {
        return $this->allPraxisTasks()->where('active', true);
    }

    /** Alle Karten inkl. deaktivierter (Superadmin-Editor, Seeder). */
    public function allPraxisTasks(): HasMany
    {
        return $this->hasMany(PraxisTask::class, 'course_id')->orderBy('sort_order');
    }

    /**
     * Aktuell gültiges (published + verifiziert + innerhalb der Gültigkeits-
     * frist) Regelwerk. Bug-Review B21: valid_from/valid_until wurden
     * bisher nicht geprüft -- ein bereits abgelaufenes oder noch nicht
     * wirksames Regelwerk konnte trotzdem als "aktuell gültig" ausgewählt
     * werden, sobald es das mit dem spätesten valid_from unter den
     * published+verifizierten war.
     *
     * Bug-Review (Datumsgrenze nachts nach UTC statt deutscher Zeit):
     * valid_from/valid_until sind reine 'date'-Spalten (kein Zeitanteil) --
     * ein Superadmin trägt sie als deutsches Kalenderdatum ein. now()
     * läuft serverseitig weiterhin in UTC (siehe App\Support\GermanTime,
     * APP_TIMEZONE bleibt bewusst UTC), toDateString() lieferte damit
     * zwischen Mitternacht und ca. 1-2 Uhr deutscher Zeit noch den
     * VORTAG: ein Regelwerk mit valid_from = heute wurde dadurch erst 1-2
     * Stunden zu spät aktiv, ein ablaufendes blieb 1-2 Stunden zu lange
     * gültig. Die Umrechnung auf deutsche Zeit passiert deshalb -- anders
     * als bei GermanTime::format(), das ausschließlich für die Anzeige
     * gespeicherter Zeitstempel gedacht ist -- hier gezielt VOR dem
     * Herunterbrechen auf das Kalenderdatum, nicht an den gespeicherten
     * Zeitstempeln selbst.
     */
    public function activeExamRuleSet(): ?ExamRuleSet
    {
        $today = now()->setTimezone('Europe/Berlin')->toDateString();

        return $this->examRuleSets()
            ->where('status', 'published')
            ->whereNotNull('verified_at')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * Regelwerk, nach dem ein Versuch mit diesem Prüfungsbogen läuft. In
     * Kombikursen stammen die Bögen aus mehreren Teilkursen mit
     * verschiedenen amtlichen Grenzen (SRC-UBI: SRC 19/24 in 30 Minuten,
     * UBI 17/22 in 60 Minuten) -- ein einzelnes Regelwerk des Kombikurses
     * kann das nicht abbilden, ein Bogen nutzt deshalb das freigegebene
     * Regelwerk seines Teilkurses (siehe ComboExamGroups). null, solange
     * der Teilkurs kein freigegebenes Regelwerk hat.
     */
    public function examRuleSetForPaper(ExamPaper $paper): ?ExamRuleSet
    {
        $sourceCode = ComboExamGroups::sourceCourseFor($this->code, $paper->paper_number);

        if ($sourceCode === null) {
            return $this->activeExamRuleSet();
        }

        return static::where('code', $sourceCode)->whereNull('tenant_id')->first()?->activeExamRuleSet();
    }

    /** Lässt sich in diesem Kurs ein Prüfungsversuch starten (eigenes Regelwerk oder ein Bogen eines freigegebenen Teilkurses)? */
    public function hasStartableExam(): bool
    {
        if ($this->activeExamRuleSet()) {
            return true;
        }

        foreach (ComboExamGroups::GROUPS[$this->code] ?? [] as $group) {
            $source = static::where('code', $group['course'])->whereNull('tenant_id')->first();

            if ($source?->activeExamRuleSet() && $this->examPapers()->whereBetween('paper_number', [$group['min'], $group['max']])->exists()) {
                return true;
            }
        }

        return false;
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}

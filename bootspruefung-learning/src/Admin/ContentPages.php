<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Security\Capabilities;
use InvalidArgumentException;

/**
 * Content-Browser (Kap. 9, Schritt 5): Übersicht, Kurse & Module, Fragen,
 * Medien, Prüfungsregeln und Rechtefreigabe. Importierte Felder werden nicht
 * editiert; Rechte und Regel-Verifizierung laufen über begründete Overrides.
 */
final class ContentPages
{
    private \wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    public function register(): void
    {
        add_action('admin_post_bp_override', [$this, 'handleOverride']);
    }

    // ------------------------------------------------------------------
    // Übersicht
    // ------------------------------------------------------------------

    public function renderOverview(): void
    {
        $this->can();
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Bootsprüfung – Übersicht', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        $active = get_option('bp_learning_active_package');
        $last = $this->db->get_row("SELECT id, package_id, status, published_at FROM {$this->t('import_batch')} ORDER BY id DESC LIMIT 1");
        Ui::keyValue([
            __('Aktives Content-Paket', 'bootspruefung-learning') => '<code>'.esc_html($active ?: '–').'</code>',
            __('Letzter Import', 'bootspruefung-learning') => $last ? '<a href="'.esc_url(Ui::url(ImportPage::SLUG, ['batch' => $last->id])).'">#'.(int) $last->id.'</a> '.Ui::status($last->status) : '–',
            __('Lernbereich', 'bootspruefung-learning') => '<a href="'.esc_url(home_url('/bootsscheine/')).'">'.esc_html(home_url('/bootsscheine/')).'</a>',
        ]);

        $repo = new ContentRepository();
        $deliverable = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t('question')} q JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id ".Overrides::rightsJoin('question', 'q', 'o').' WHERE '.$repo->deliverableQuestionSql());
        $rows = [];
        foreach ([
            'questions' => ['question', 'q', 'COALESCE(o.value, r.rights_status)', " JOIN {$this->t('question_revision')} r ON r.id = x.current_revision_id"],
            'media' => ['media', 'm', 'COALESCE(o.value, x.rights_status)', ''],
            'navigation_tasks' => ['navigation_task', 'n', 'COALESCE(o.value, x.rights_status)', ''],
            'praxis_tasks' => ['praxis_task', 'p', 'COALESCE(o.value, x.rights_status)', ''],
            'video_modules' => ['video_module', 'v', 'COALESCE(o.value, x.rights_status)', ''],
            'radio_texts' => ['radio_text', 'r', 'COALESCE(o.value, x.rights_status)', ''],
            'radio_vocabulary' => ['radio_vocabulary', 'r', 'COALESCE(o.value, x.rights_status)', ''],
        ] as $entity => [$table, , $rightsExpr, $join]) {
            $r = $this->db->get_row(
                "SELECT COUNT(*) AS total, SUM(x.status = 'source_archived') AS archived, SUM({$rightsExpr} IN (".Overrides::blockingList().") AND x.status <> 'source_archived') AS blocked
                 FROM {$this->t($table)} x {$join} ".Overrides::rightsJoin($table, 'x', 'o')
            );
            $rows[] = [esc_html(Ui::entityLabel($entity)), (int) $r->total, $entity === 'questions' ? $deliverable : '–', (int) $r->blocked, (int) $r->archived];
        }
        echo '<h2>'.esc_html__('Bestand', 'bootspruefung-learning').'</h2>';
        Ui::table([__('Bereich', 'bootspruefung-learning'), __('gesamt', 'bootspruefung-learning'), __('auslieferbar', 'bootspruefung-learning'), __('durch Rechte gesperrt', 'bootspruefung-learning'), __('archiviert', 'bootspruefung-learning')], $rows);

        echo '<h2>'.esc_html__('Offene Punkte', 'bootspruefung-learning').'</h2><ul class="ul-disc">';
        $failed = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t('import_batch')} WHERE status = 'failed'");
        $unverified = 0;
        foreach ($repo->courses() as $course) {
            $unverified += $repo->examRule((int) $course->id)['live'] ? 0 : 1;
        }
        $missingFiles = 0;
        foreach ($this->db->get_col("SELECT storage_key FROM {$this->t('media')} WHERE status <> 'source_archived'") as $key) {
            $missingFiles += ($key && MediaStorage::exists($key)) ? 0 : 1;
        }
        $items = [
            [$failed, __('fehlgeschlagene Paketprüfungen', 'bootspruefung-learning'), Ui::url(ImportPage::SLUG)],
            [$unverified, __('Kurse ohne gültiges, verifiziertes Prüfungsregelwerk (nur Übungsmodus)', 'bootspruefung-learning'), Ui::url('bootspruefung-rules')],
            [$missingFiles, __('Medien ohne Datei', 'bootspruefung-learning'), Ui::url('bootspruefung-media', ['missing' => 1])],
        ];
        foreach ($items as [$count, $label, $url]) {
            printf('<li><a href="%s">%d %s</a></li>', esc_url($url), (int) $count, esc_html($label));
        }
        echo '</ul></div>';
    }

    // ------------------------------------------------------------------
    // Kurse & Module
    // ------------------------------------------------------------------

    public function renderCourses(): void
    {
        $this->can();
        $repo = new ContentRepository();
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Kurse & Module', 'bootspruefung-learning').'</h1>';
        $rows = [];
        foreach ($this->db->get_results("SELECT * FROM {$this->t('course')} ORDER BY sort_order, id") as $course) {
            $modules = [];
            foreach ($repo->modules((int) $course->id) as $m) {
                $total = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t('module_question')} WHERE module_id = %d", $m->id));
                $deliverable = count($repo->questionsForModules([(int) $m->id]));
                $modules[] = sprintf('%d. <a href="%s">%s</a> (%d/%d)', (int) $m->sort_order, esc_url(Ui::url('bootspruefung-questions', ['module' => $m->id])), esc_html($m->code), $deliverable, $total);
            }
            $rule = $repo->examRule((int) $course->id);
            $papers = count($repo->papers((int) $course->id));
            $nav = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t('navigation_task_course')} WHERE course_id = %d", $course->id));
            $praxis = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t('praxis_task')} WHERE course_id = %d AND status <> 'source_archived'", $course->id));
            $rows[] = [
                '<strong>'.esc_html($course->name).'</strong><br><code>'.esc_html($course->code).'</code> · '.esc_html($course->course_type),
                Ui::status($course->status),
                implode('<br>', $modules),
                $rule['rule'] ? ($rule['live'] ? Ui::status('published').' '.esc_html__('live', 'bootspruefung-learning') : Ui::status('draft').' '.esc_html__('nur Übung', 'bootspruefung-learning')) : '–',
                $papers,
                $nav,
                $praxis,
                '<a href="'.esc_url(home_url('/bootsscheine/'.ContentRepository::slug($course->code).'/')).'">'.esc_html__('ansehen', 'bootspruefung-learning').'</a>',
            ];
        }
        echo '<p class="description">'.esc_html__('Module: auslieferbare/zugeordnete Fragen. Reihenfolge und Zuordnung stammen aus dem Content-Paket.', 'bootspruefung-learning').'</p>';
        Ui::table([__('Kurs', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('Module', 'bootspruefung-learning'), __('Prüfung', 'bootspruefung-learning'), __('Bögen', 'bootspruefung-learning'), __('Navigation', 'bootspruefung-learning'), __('Praxis', 'bootspruefung-learning'), ''], $rows);
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Fragen
    // ------------------------------------------------------------------

    public function renderQuestions(): void
    {
        $this->can();
        $questionId = absint($_GET['question'] ?? 0);
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Fragen', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        $questionId > 0 ? $this->renderQuestion($questionId) : $this->renderQuestionList();
        echo '</div>';
    }

    private function renderQuestionList(): void
    {
        $f = [
            'module' => absint($_GET['module'] ?? 0),
            'type' => sanitize_key((string) ($_GET['type'] ?? '')),
            'status' => sanitize_key((string) ($_GET['status'] ?? '')),
            'rights' => sanitize_key((string) ($_GET['rights'] ?? '')),
            's' => sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
        ];
        $page = max(1, absint($_GET['paged'] ?? 1));
        $where = ['1=1'];
        $args = [];
        if ($f['module']) {
            $where[] = "q.id IN (SELECT question_id FROM {$this->t('module_question')} WHERE module_id = %d)";
            $args[] = $f['module'];
        }
        if ($f['type'] !== '') {
            $where[] = 'r.question_type = %s';
            $args[] = $f['type'];
        }
        if ($f['status'] !== '') {
            $where[] = 'q.status = %s';
            $args[] = $f['status'];
        }
        if ($f['rights'] !== '') {
            $where[] = 'COALESCE(o.value, r.rights_status) = %s';
            $args[] = $f['rights'];
        }
        if ($f['s'] !== '') {
            $like = '%'.$this->db->esc_like($f['s']).'%';
            $where[] = '(q.source_id LIKE %s OR r.question_text LIKE %s OR q.official_number = %s)';
            array_push($args, $like, $like, $f['s']);
        }
        $sql = "SELECT q.id, q.source_id, q.official_number, q.source_module, q.status, r.question_type, r.question_text, r.revision_no, r.image_required, COALESCE(o.value, r.rights_status) AS rights, o.value AS rights_override
                FROM {$this->t('question')} q JOIN {$this->t('question_revision')} r ON r.id = q.current_revision_id ".Overrides::rightsJoin('question', 'q', 'o')
            .' WHERE '.implode(' AND ', $where).' ORDER BY q.source_module, q.id LIMIT 51 OFFSET '.(($page - 1) * 50);
        $rows = $this->db->get_results($args === [] ? $sql : $this->db->prepare($sql, $args));

        echo '<form method="get" class="bp-filter"><input type="hidden" name="page" value="bootspruefung-questions">';
        echo '<select name="module"><option value="">'.esc_html__('Alle Module', 'bootspruefung-learning').'</option>';
        foreach ($this->db->get_results("SELECT id, code FROM {$this->t('module')} ORDER BY sort_order") as $m) {
            printf('<option value="%d" %s>%s</option>', (int) $m->id, selected($f['module'], (int) $m->id, false), esc_html($m->code));
        }
        echo '</select> ';
        $this->select('type', $f['type'], ['' => __('Alle Typen', 'bootspruefung-learning'), 'single_choice' => 'single_choice', 'multi_choice' => 'multi_choice', 'open_text' => 'open_text', 'chart_task' => 'chart_task']);
        $this->select('status', $f['status'], ['' => __('Alle Status', 'bootspruefung-learning'), 'published' => 'published', 'draft' => 'draft', 'source_archived' => 'source_archived']);
        $this->select('rights', $f['rights'], ['' => __('Alle Rechte', 'bootspruefung-learning')] + array_combine(Overrides::RIGHTS, Overrides::RIGHTS));
        printf('<input type="search" name="s" value="%s" placeholder="%s"> ', esc_attr($f['s']), esc_attr__('ID, Nr. oder Text', 'bootspruefung-learning'));
        submit_button(__('Filtern', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</form><br>';

        $out = [];
        foreach (array_slice($rows, 0, 50) as $q) {
            $out[] = [
                '<a href="'.esc_url(Ui::url('bootspruefung-questions', ['question' => $q->id])).'"><code>'.esc_html($q->source_id).'</code></a>',
                esc_html((string) $q->official_number),
                esc_html((string) $q->source_module),
                esc_html($q->question_type),
                esc_html(wp_trim_words(wp_strip_all_tags($q->question_text), 18)).($q->image_required ? ' 🖼' : ''),
                (int) $q->revision_no,
                Ui::status($q->status),
                Ui::status($q->rights).($q->rights_override !== null ? ' <small>'.esc_html__('(lokal)', 'bootspruefung-learning').'</small>' : ''),
            ];
        }
        Ui::table(['ID', __('Nr.', 'bootspruefung-learning'), __('Modul', 'bootspruefung-learning'), __('Typ', 'bootspruefung-learning'), __('Frage', 'bootspruefung-learning'), 'Rev.', __('Status', 'bootspruefung-learning'), __('Rechte', 'bootspruefung-learning')], $out, __('Keine Fragen gefunden.', 'bootspruefung-learning'));
        Ui::pager($page, count($rows) > 50, fn (int $p) => Ui::url('bootspruefung-questions', array_merge($f, ['paged' => $p])));
    }

    private function renderQuestion(int $id): void
    {
        $q = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('question')} WHERE id = %d", $id));
        if (! $q) {
            echo '<p>'.esc_html__('Frage nicht gefunden.', 'bootspruefung-learning').'</p>';

            return;
        }
        $repo = new ContentRepository();
        $rev = $repo->revision((int) $q->current_revision_id);
        $override = Overrides::get('question', $id, 'rights_status');
        echo '<p><a href="'.esc_url(Ui::url('bootspruefung-questions')).'">&larr; '.esc_html__('Alle Fragen', 'bootspruefung-learning').'</a></p>';
        echo '<h2><code>'.esc_html($q->source_id).'</code> '.Ui::status($q->status).' '.($repo->isDeliverable($id) ? Ui::status('published').' '.esc_html__('wird ausgeliefert', 'bootspruefung-learning') : '<em>'.esc_html__('wird nicht ausgeliefert', 'bootspruefung-learning').'</em>').'</h2>';
        $modules = $this->db->get_col($this->db->prepare("SELECT m.code FROM {$this->t('module_question')} mq JOIN {$this->t('module')} m ON m.id = mq.module_id WHERE mq.question_id = %d", $id));
        $papers = $this->db->get_col($this->db->prepare("SELECT p.source_id FROM {$this->t('exam_paper_question')} pq JOIN {$this->t('exam_paper')} p ON p.id = pq.paper_id WHERE pq.question_id = %d ORDER BY p.id", $id));
        Ui::keyValue([
            __('Amtliche Nr. / Familie', 'bootspruefung-learning') => esc_html(($q->official_number ?? '–').' / '.($q->content_role ?? '–')),
            __('Typ', 'bootspruefung-learning') => esc_html($rev->question_type),
            __('Thema', 'bootspruefung-learning') => esc_html(implode(' › ', array_filter([$rev->topic, $rev->subtopic, $rev->competency]))),
            __('Smarttrainer-Kategorie', 'bootspruefung-learning') => esc_html((string) $rev->smart_category),
            __('Quelle', 'bootspruefung-learning') => esc_html(trim($rev->source_catalog.' · '.$rev->source_version.' '.($rev->source_question_id ? '· '.$rev->source_question_id : ''))),
            __('Rechte', 'bootspruefung-learning') => Ui::status($rev->rights_status).($override !== null ? ' → '.Ui::status($override).' '.esc_html__('(lokaler Override)', 'bootspruefung-learning') : ''),
            __('Module', 'bootspruefung-learning') => esc_html(implode(', ', $modules)),
            __('In Bögen', 'bootspruefung-learning') => esc_html($papers === [] ? '–' : implode(', ', $papers)),
        ]);
        echo '<h3>'.esc_html__('Aktuelle Revision', 'bootspruefung-learning').' #'.(int) $rev->revision_no.'</h3>';
        echo '<div class="card" style="max-width:900px"><p><strong>'.nl2br(esc_html($rev->question_text)).'</strong></p>';
        foreach ($repo->media((int) $rev->id) as $m) {
            printf('<p><img src="%s" alt="%s" style="max-width:280px;height:auto"></p>', esc_url($m['url']), esc_attr($m['alt']));
        }
        echo '<ol type="A">';
        foreach ($repo->answers((int) $rev->id) as $a) {
            printf('<li%s>%s%s</li>', $a->is_correct ? ' style="font-weight:600;color:#00701a"' : '', esc_html($a->answer_text), $a->is_correct ? ' ✓' : '');
        }
        echo '</ol>';
        if ($rev->feedback_correct || $rev->feedback_incorrect) {
            echo '<p><em>'.esc_html__('Erklärung richtig:', 'bootspruefung-learning').'</em> '.nl2br(esc_html((string) $rev->feedback_correct)).'</p>';
            echo '<p><em>'.esc_html__('Erklärung falsch:', 'bootspruefung-learning').'</em> '.nl2br(esc_html((string) $rev->feedback_incorrect)).'</p>';
        }
        echo '</div>';

        $revs = $this->db->get_results($this->db->prepare("SELECT revision_no, editorial_status, package_id, created_at, LEFT(question_text, 120) AS text FROM {$this->t('question_revision')} WHERE question_id = %d ORDER BY revision_no DESC", $id));
        echo '<h3>'.esc_html__('Revisionen', 'bootspruefung-learning').'</h3>';
        Ui::table(['Rev.', __('Status', 'bootspruefung-learning'), __('Paket', 'bootspruefung-learning'), __('Angelegt', 'bootspruefung-learning'), __('Text', 'bootspruefung-learning')], array_map(static fn ($r) => [(int) $r->revision_no, Ui::status($r->editorial_status), '<code>'.esc_html((string) $r->package_id).'</code>', esc_html(Ui::date($r->created_at)), esc_html($r->text)], $revs));

        echo '<h3>'.esc_html__('Rechte-Status lokal überschreiben', 'bootspruefung-learning').'</h3>';
        $this->overrideForm('question', [$id], 'rights_status', $override, Ui::url('bootspruefung-questions', ['question' => $id]));
    }

    // ------------------------------------------------------------------
    // Medien
    // ------------------------------------------------------------------

    public function renderMedia(): void
    {
        $this->can();
        $missingOnly = ! empty($_GET['missing']);
        $page = max(1, absint($_GET['paged'] ?? 1));
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Medien', 'bootspruefung-learning').'</h1>';
        printf('<p><a class="button%s" href="%s">%s</a> <a class="button%s" href="%s">%s</a></p>', $missingOnly ? '' : ' button-primary', esc_url(Ui::url('bootspruefung-media')), esc_html__('alle', 'bootspruefung-learning'), $missingOnly ? ' button-primary' : '', esc_url(Ui::url('bootspruefung-media', ['missing' => 1])), esc_html__('ohne Datei', 'bootspruefung-learning'));
        $rows = $this->db->get_results(
            "SELECT m.*, COALESCE(o.value, m.rights_status) AS rights,
                (SELECT COUNT(*) FROM {$this->t('content_media')} cm WHERE cm.media_id = m.id) AS q_refs,
                (SELECT COUNT(*) FROM {$this->t('praxis_task')} pt WHERE pt.media_id = m.id) AS p_refs
             FROM {$this->t('media')} m ".Overrides::rightsJoin('media', 'm', 'o').' ORDER BY m.source_id'
        );
        if ($missingOnly) {
            $rows = array_values(array_filter($rows, static fn ($m) => ! $m->storage_key || ! MediaStorage::exists($m->storage_key)));
        }
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * 50, 50);
        $out = [];
        foreach ($rows as $m) {
            $exists = $m->storage_key && MediaStorage::exists($m->storage_key);
            $out[] = [
                $exists && str_starts_with($m->mime_type, 'image/') ? '<img src="'.esc_url(MediaStorage::url($m->storage_key)).'" alt="" style="max-width:80px;max-height:60px">' : '–',
                '<code>'.esc_html($m->source_id).'</code>',
                esc_html($m->mime_type.' · '.size_format((int) $m->size)),
                esc_html(wp_trim_words((string) $m->source_text, 12)),
                Ui::status($m->rights),
                (int) $m->q_refs.' / '.(int) $m->p_refs,
                $exists ? '✓' : '<strong style="color:#d63638">'.esc_html__('fehlt', 'bootspruefung-learning').'</strong>',
                Ui::status($m->status),
            ];
        }
        echo '<p>'.sprintf(esc_html__('%d Medien', 'bootspruefung-learning'), $total).'</p>';
        Ui::table(['', 'ID', __('Typ', 'bootspruefung-learning'), __('Quelle', 'bootspruefung-learning'), __('Rechte', 'bootspruefung-learning'), __('Verwendung Fragen/Praxis', 'bootspruefung-learning'), __('Datei', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning')], $out);
        Ui::pager($page, $page * 50 < $total, fn (int $p) => Ui::url('bootspruefung-media', ['missing' => $missingOnly ? 1 : '', 'paged' => $p]));
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Prüfungsregeln
    // ------------------------------------------------------------------

    public function renderRules(): void
    {
        $this->can();
        $repo = new ContentRepository();
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Prüfungsregeln', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        echo '<p class="description">'.esc_html__('Live-Prüfungsmodus nur mit veröffentlichtem, gültigem und fachlich verifiziertem Regelwerk (Kap. 4.5). Sonst ist die Simulation als Übungsmodus gekennzeichnet.', 'bootspruefung-learning').'</p>';
        $overrides = Overrides::forType('exam_rule');
        foreach ($this->db->get_results("SELECT r.*, c.code, c.name FROM {$this->t('exam_rule')} r JOIN {$this->t('course')} c ON c.id = r.course_id ORDER BY c.sort_order, r.id") as $rule) {
            $passing = json_decode((string) $rule->passing_rule, true) ?: [];
            $o = $overrides[(int) $rule->id] ?? [];
            $effective = $repo->examRule((int) $rule->course_id);
            $isLive = $effective['live'] && (int) $effective['rule']->id === (int) $rule->id;
            echo '<div class="card" style="max-width:900px"><h2>'.esc_html($rule->name).' <code>'.esc_html($rule->version).'</code> '.Ui::status($rule->status).' '.($isLive ? Ui::status('published').' live' : Ui::status('draft').' '.esc_html__('nur Übung', 'bootspruefung-learning')).'</h2>';
            $bp = array_map(static fn ($b) => $b->code.': '.$b->question_count, $repo->blueprints((int) $rule->id));
            $parts = array_map(static fn ($p) => $p['label'].' '.$p['min_correct'].'/'.$p['of'], $passing['parts'] ?? []);
            Ui::keyValue([
                __('Zeitlimit', 'bootspruefung-learning') => esc_html(sprintf(__('%d Minuten', 'bootspruefung-learning'), intdiv((int) $rule->time_limit_seconds, 60))),
                __('Fragen je Modul', 'bootspruefung-learning') => esc_html(implode(', ', $bp)),
                __('Bestehensgrenze', 'bootspruefung-learning') => esc_html(round(((float) ($passing['min_correct_ratio'] ?? 0.75)) * 100, 1).' %'.($parts ? ' · '.implode(' · ', $parts) : '')),
                __('Hinweis', 'bootspruefung-learning') => esc_html((string) ($passing['note'] ?? '–')),
                __('Verifiziert (Quelle)', 'bootspruefung-learning') => $rule->verified ? '✓' : '✗',
                __('Lokaler Override', 'bootspruefung-learning') => esc_html($o === [] ? '–' : wp_json_encode($o)),
            ]);
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:8px">';
            wp_nonce_field('bp_override');
            printf('<input type="hidden" name="action" value="bp_override"><input type="hidden" name="type" value="exam_rule"><input type="hidden" name="ids" value="%d"><input type="hidden" name="field" value="verified"><input type="hidden" name="back" value="%s">', (int) $rule->id, esc_attr(Ui::url('bootspruefung-rules')));
            echo '<select name="value"><option value="1">'.esc_html__('als verifiziert markieren', 'bootspruefung-learning').'</option><option value="0">'.esc_html__('als nicht verifiziert markieren', 'bootspruefung-learning').'</option><option value="">'.esc_html__('Override entfernen (Quelle gilt)', 'bootspruefung-learning').'</option></select> ';
            echo '<input type="text" name="reason" required class="regular-text" placeholder="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'"> ';
            submit_button(__('Speichern', 'bootspruefung-learning'), 'secondary', '', false);
            echo '</form></div>';
        }
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Rechte
    // ------------------------------------------------------------------

    /** Gruppierung je Objekttyp: [Tabelle, Join, Gruppenausdruck] */
    private function rightsGroups(): array
    {
        return [
            'question' => [__('Fragen nach Katalog', 'bootspruefung-learning'), "JOIN {$this->t('question_revision')} r ON r.id = x.current_revision_id", "CONCAT(COALESCE(r.source_catalog, '–'), ' · ', COALESCE(r.source_version, '–'))", 'r.rights_status'],
            'media' => [__('Medien nach Quelle', 'bootspruefung-learning'), '', "SUBSTRING_INDEX(COALESCE(x.source_text, '–'), ':', 1)", 'x.rights_status'],
            'navigation_task' => [__('Navigationsaufgaben nach Quelle', 'bootspruefung-learning'), '', "COALESCE(x.source_text, '–')", 'x.rights_status'],
            'praxis_task' => [__('Praxiskarten nach Kurs', 'bootspruefung-learning'), "JOIN {$this->t('course')} c ON c.id = x.course_id", 'c.code', 'x.rights_status'],
            'video_module' => [__('Videokapitel nach Kurs', 'bootspruefung-learning'), "JOIN {$this->t('course')} c ON c.id = x.course_id", 'c.code', 'x.rights_status'],
            'radio_text' => [__('Funktexte nach Katalog', 'bootspruefung-learning'), '', "CONCAT(x.catalog, ' · ', x.exercise_type)", 'x.rights_status'],
            'radio_vocabulary' => [__('Funk-Vokabeln nach Katalog', 'bootspruefung-learning'), '', 'x.catalog', 'x.rights_status'],
        ];
    }

    public function renderRights(): void
    {
        $this->can();
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Rechte und Freigabe', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        echo '<p class="description">'.esc_html__('Rechte-Status unknown und restricted sperren die Auslieferung (Kap. 4.10). Eine Freigabe wird als lokaler Override mit Begründung gespeichert, überlebt Reimporte und steht im Audit-Log.', 'bootspruefung-learning').'</p>';
        foreach ($this->rightsGroups() as $type => [$label, $join, $groupExpr, $importedExpr]) {
            echo '<h2>'.esc_html($label).'</h2>';
            $rows = $this->db->get_results(
                "SELECT {$groupExpr} AS grp, {$importedExpr} AS imported, COALESCE(o.value, {$importedExpr}) AS effective, COUNT(*) AS n
                 FROM {$this->t($type)} x {$join} ".Overrides::rightsJoin($type, 'x', 'o')."
                 WHERE x.status <> 'source_archived' GROUP BY grp, imported, effective ORDER BY grp"
            );
            $out = [];
            foreach ($rows as $row) {
                ob_start();
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('bp_override');
                printf(
                    '<input type="hidden" name="action" value="bp_override"><input type="hidden" name="type" value="%s"><input type="hidden" name="group" value="%s"><input type="hidden" name="imported" value="%s"><input type="hidden" name="effective" value="%s"><input type="hidden" name="field" value="rights_status"><input type="hidden" name="back" value="%s">',
                    esc_attr($type),
                    esc_attr($row->grp),
                    esc_attr($row->imported),
                    esc_attr($row->effective),
                    esc_attr(Ui::url('bootspruefung-rights'))
                );
                echo '<select name="value">';
                foreach (Overrides::RIGHTS as $r) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($r), selected($r, 'licensed', false), esc_html($r));
                }
                echo '<option value="">'.esc_html__('Override entfernen', 'bootspruefung-learning').'</option></select> ';
                echo '<input type="text" name="reason" required placeholder="'.esc_attr__('Rechtsgrundlage/Begründung', 'bootspruefung-learning').'"> ';
                submit_button(__('Anwenden', 'bootspruefung-learning'), 'small', '', false);
                echo '</form>';
                $out[] = [esc_html($row->grp), Ui::status($row->imported), Ui::status($row->effective), (int) $row->n, (string) ob_get_clean()];
            }
            Ui::table([__('Gruppe', 'bootspruefung-learning'), __('importiert', 'bootspruefung-learning'), __('wirksam', 'bootspruefung-learning'), __('Anzahl', 'bootspruefung-learning'), __('Ändern', 'bootspruefung-learning')], $out);
        }
        echo '<h2>'.esc_html__('Letzte Freigaben', 'bootspruefung-learning').'</h2>';
        $log = $this->db->get_results("SELECT * FROM {$this->t('audit_log')} WHERE action LIKE 'override_%' ORDER BY id DESC LIMIT 20");
        Ui::table([__('Zeit', 'bootspruefung-learning'), __('Person', 'bootspruefung-learning'), __('Aktion', 'bootspruefung-learning'), __('Objekt', 'bootspruefung-learning'), __('Begründung', 'bootspruefung-learning'), __('Details', 'bootspruefung-learning')], array_map(static fn ($l) => [
            esc_html(Ui::date($l->created_at)),
            esc_html($l->actor_user_id ? (get_userdata((int) $l->actor_user_id)->display_name ?? '#'.$l->actor_user_id) : '–'),
            esc_html($l->action),
            esc_html($l->object_type.' '.wp_trim_words((string) $l->object_id, 8)),
            esc_html((string) $l->reason),
            '<code>'.esc_html((string) $l->context).'</code>',
        ], $log));
        echo '</div>';
    }

    // ------------------------------------------------------------------

    public function handleOverride(): void
    {
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), 403);
        }
        check_admin_referer('bp_override');
        $type = sanitize_key((string) ($_POST['type'] ?? ''));
        $field = sanitize_key((string) ($_POST['field'] ?? ''));
        $value = (string) wp_unslash($_POST['value'] ?? '');
        $reason = sanitize_textarea_field(wp_unslash((string) ($_POST['reason'] ?? '')));
        $back = wp_validate_redirect((string) wp_unslash($_POST['back'] ?? ''), Ui::url('bootspruefung'));
        if (isset($_POST['group'])) {
            $ids = $this->idsForGroup($type, (string) wp_unslash($_POST['group']), (string) wp_unslash($_POST['imported'] ?? ''), (string) wp_unslash($_POST['effective'] ?? ''));
        } else {
            $ids = array_map('absint', explode(',', (string) ($_POST['ids'] ?? '')));
        }
        try {
            $count = Overrides::set($type, array_values(array_filter($ids)), $field, $value === '' ? null : $value, $reason);
            Ui::flash(sprintf(__('%d Einträge aktualisiert.', 'bootspruefung-learning'), $count));
        } catch (InvalidArgumentException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect($back);
        exit;
    }

    /** @return list<int> */
    private function idsForGroup(string $type, string $group, string $imported, string $effective): array
    {
        $groups = $this->rightsGroups();
        if (! isset($groups[$type])) {
            return [];
        }
        [, $join, $groupExpr, $importedExpr] = $groups[$type];

        return array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT x.id FROM {$this->t($type)} x {$join} ".Overrides::rightsJoin($type, 'x', 'o')."
             WHERE x.status <> 'source_archived' AND {$groupExpr} = %s AND {$importedExpr} = %s AND COALESCE(o.value, {$importedExpr}) = %s",
            $group,
            $imported,
            $effective
        )));
    }

    /** @param list<int> $ids */
    private function overrideForm(string $type, array $ids, string $field, ?string $current, string $back): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_override');
        printf('<input type="hidden" name="action" value="bp_override"><input type="hidden" name="type" value="%s"><input type="hidden" name="ids" value="%s"><input type="hidden" name="field" value="%s"><input type="hidden" name="back" value="%s">', esc_attr($type), esc_attr(implode(',', $ids)), esc_attr($field), esc_attr($back));
        echo '<select name="value">';
        foreach (Overrides::RIGHTS as $r) {
            printf('<option value="%s" %s>%s</option>', esc_attr($r), selected($current, $r, false), esc_html($r));
        }
        echo '<option value="">'.esc_html__('Override entfernen (Quelle gilt)', 'bootspruefung-learning').'</option></select> ';
        echo '<input type="text" name="reason" required class="regular-text" placeholder="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'"> ';
        submit_button(__('Speichern', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</form>';
    }

    /** @param array<string,string> $options */
    private function select(string $name, string $current, array $options): void
    {
        printf('<select name="%s">', esc_attr($name));
        foreach ($options as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr((string) $value), selected($current, (string) $value, false), esc_html($label));
        }
        echo '</select> ';
    }

    private function can(): void
    {
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
    }
}

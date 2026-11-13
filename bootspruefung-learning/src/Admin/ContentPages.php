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
        add_action('admin_post_bp_rights_release', [$this, 'handleRelease']);
        add_action('admin_post_bp_chart', [$this, 'handleChart']);
        add_action('admin_post_bp_rules_release', [$this, 'handleRulesRelease']);
        add_action('admin_post_bp_mapping_remove', [$this, 'handleMappingRemove']);
    }

    /** Verwaiste Zugangszuordnung eines gelöschten Produkts entfernen (Übersicht „Produktzuordnungen prüfen“). */
    public function handleMappingRemove(): void
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_mapping_remove');
        try {
            $n = \Bootspruefung\Learning\Commerce\MappingCheck::removeOrphan(absint($_POST['product'] ?? 0), absint($_POST['variation'] ?? 0));
            Ui::flash(sprintf(_n('%d Zuordnung entfernt. Bereits erteilte Zugänge bleiben bestehen.', '%d Zuordnungen entfernt. Bereits erteilte Zugänge bleiben bestehen.', $n, 'bootspruefung-learning'), $n));
        } catch (\RuntimeException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect(Ui::url(Menu::SLUG).'#bp-mapping-issues');
        exit;
    }

    // ------------------------------------------------------------------
    // Übersicht
    // ------------------------------------------------------------------

    public function renderOverview(): void
    {
        $this->can();
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Bootsprüfung – Übersicht', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        GoLiveChecklist::render(); // T99: Livegang-Checkliste mit Status
        $active = get_option('bp_learning_active_package');
        $last = $this->db->get_row("SELECT id, package_id, status, published_at FROM {$this->t('import_batch')} ORDER BY id DESC LIMIT 1");
        Ui::keyValue([
            __('Aktives Inhaltspaket', 'bootspruefung-learning') => '<code>'.esc_html($active ?: '–').'</code>',
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
            // Spalten schließen sich aus und ergeben zusammen „gesamt“.
            $r = $this->db->get_row(
                "SELECT COUNT(*) AS total, COALESCE(SUM(x.status = 'source_archived'), 0) AS archived,
                        COALESCE(SUM(x.status NOT IN ('published','source_archived')), 0) AS draft,
                        COALESCE(SUM(x.status = 'published' AND {$rightsExpr} IN (".Overrides::blockingList().")), 0) AS blocked
                 FROM {$this->t($table)} x {$join} ".Overrides::rightsJoin($table, 'x', 'o')
            );
            $total = (int) $r->total;
            $open = $total - (int) $r->archived - (int) $r->draft - (int) $r->blocked;
            // Fragen: zusätzlich Typ, Gültigkeit, Pflichtbild usw. (wie im Lernbereich); Rest = sonstige.
            $ok = $entity === 'questions' ? min($deliverable, $open) : $open;
            $rows[] = [esc_html(Ui::entityLabel($entity)), $total, $ok, (int) $r->draft, (int) $r->blocked, (int) $r->archived, $open - $ok];
        }
        echo '<h2>'.esc_html__('Bestand', 'bootspruefung-learning').'</h2>';
        Ui::table([__('Bereich', 'bootspruefung-learning'), __('gesamt', 'bootspruefung-learning'), __('auslieferbar', 'bootspruefung-learning'), __('Entwurf', 'bootspruefung-learning'), __('durch Rechte gesperrt', 'bootspruefung-learning'), __('archiviert', 'bootspruefung-learning'), __('sonst nicht auslieferbar', 'bootspruefung-learning')], $rows);
        echo '<p class="description">'.esc_html__('„sonst nicht auslieferbar“: z. B. Kartenaufgaben, deaktivierte oder abgelaufene Fragen, Pflichtbild fehlt oder ist gesperrt.', 'bootspruefung-learning').'</p>';

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
        $nav = \Bootspruefung\Learning\Trainers\NavigationCheck::coverage();
        $items[] = [$nav['total'] > 0 && $nav['with'] === 0 ? 1 : 0, sprintf(__('Paket ohne Lösungswerte der Navigationsaufgaben (automatische Prüfung aus; %1$d von %2$d Teilaufgaben mit Werten) – aktuelles Inhaltspaket importieren', 'bootspruefung-learning'), $nav['with'], $nav['total']), Ui::url(ImportPage::SLUG)];
        $shop = \Bootspruefung\Learning\Commerce\MappingCheck::summary();
        $items[] = [$shop['mapping_errors'], __('Fehler in WooCommerce-Produktzuordnungen', 'bootspruefung-learning'), '#bp-mapping-issues'];
        $items[] = [$shop['mapping_warnings'], __('Hinweise zu Produktzuordnungen', 'bootspruefung-learning'), '#bp-mapping-issues'];
        $items[] = [$shop['orders_without_account'], __('bezahlte Bestellungen ohne Kundenkonto (30 Tage) – Kunde zuordnen, dann „Lernzugang abgleichen“', 'bootspruefung-learning'), Ui::url(AccessPages::SLUG)];
        $items[] = [$shop['overbooked_groups'], __('überbuchte Gruppenlizenzen (nach Teilerstattung)', 'bootspruefung-learning'), Ui::url(AccessPages::GROUP_SLUG)];
        foreach ($items as [$count, $label, $url]) {
            printf('<li%s><a href="%s">%d %s</a></li>', $count > 0 ? ' style="font-weight:600"' : '', esc_url($url), (int) $count, esc_html($label));
        }
        echo '</ul>';
        $issues = \Bootspruefung\Learning\Commerce\MappingCheck::issues();
        if ($issues !== []) {
            echo '<h2 id="bp-mapping-issues">'.esc_html__('Produktzuordnungen prüfen', 'bootspruefung-learning').'</h2>';
            $rows = [];
            foreach ($issues as $i) {
                $variation = $i['variation_id'] ? ' <small>('.esc_html__('Variation', 'bootspruefung-learning').' #'.(int) $i['variation_id'].')</small>' : '';
                if (! empty($i['deleted'])) {
                    // Gelöschtes Produkt: kein Bearbeiten-Link (404), sondern verwaiste Zuordnung entfernen.
                    $product = '<span>'.esc_html(sprintf(__('Produkt #%d (gelöscht)', 'bootspruefung-learning'), $i['product_id'])).'</span>'.$variation;
                    $problem = esc_html($i['problem']);
                    if (current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
                        $problem .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:4px">'.wp_nonce_field('bp_mapping_remove', '_wpnonce', true, false)
                            .'<input type="hidden" name="action" value="bp_mapping_remove"><input type="hidden" name="product" value="'.(int) $i['product_id'].'"><input type="hidden" name="variation" value="'.(int) $i['variation_id'].'">'
                            .'<button type="submit" class="button button-small" data-bp-confirm="'.esc_attr__('Zuordnung des gelöschten Produkts entfernen? Bereits erteilte Zugänge bleiben bestehen.', 'bootspruefung-learning').'">'.esc_html__('Zuordnung entfernen', 'bootspruefung-learning').'</button></form>';
                    }
                } else {
                    $name = function_exists('wc_get_product') && ($p = wc_get_product($i['variation_id'] ?: $i['product_id'])) ? $p->get_name() : '#'.$i['product_id'];
                    $product = '<a href="'.esc_url(\Bootspruefung\Learning\Commerce\MappingCheck::editUrl($i['product_id'])).'">'.esc_html($name).'</a>'.$variation;
                    $problem = esc_html($i['problem']);
                }
                $rows[] = [$product, $i['severity'] === 'error' ? '<strong style="color:#b32d2e">'.esc_html__('Fehler', 'bootspruefung-learning').'</strong>' : esc_html__('Hinweis', 'bootspruefung-learning'), $problem];
            }
            Ui::table([__('Produkt', 'bootspruefung-learning'), __('Art', 'bootspruefung-learning'), __('Problem', 'bootspruefung-learning')], $rows);
        }
        echo '<h2>'.esc_html__('Hilfe', 'bootspruefung-learning').'</h2>';
        Glossary::render(); // T100: feste Begriffe, aufklappbar
        echo '</div>';
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
                '<strong>'.esc_html($course->name).'</strong><br><code>'.esc_html($course->code).'</code> · '.esc_html(Ui::label($course->course_type)),
                Ui::status($course->status),
                implode('<br>', $modules),
                $rule['rule'] ? ($rule['live'] ? Ui::status('published').' '.esc_html__('Prüfungsmodus', 'bootspruefung-learning') : Ui::status('draft').' '.esc_html__('Übungsmodus', 'bootspruefung-learning')) : '–',
                $papers,
                $nav,
                $praxis,
                '<a href="'.esc_url(home_url('/bootsscheine/'.ContentRepository::slug($course->code).'/')).'">'.esc_html__('ansehen', 'bootspruefung-learning').'</a>',
            ];
        }
        echo '<p class="description">'.esc_html__('Module: auslieferbare/zugeordnete Fragen. Reihenfolge und Zuordnung stammen aus dem Inhaltspaket.', 'bootspruefung-learning').'</p>';
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
        Ui::confirmBox();
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
        echo '<select name="module" aria-label="'.esc_attr__('Modul', 'bootspruefung-learning').'"><option value="">'.esc_html__('Alle Module', 'bootspruefung-learning').'</option>';
        foreach ($this->db->get_results("SELECT id, code FROM {$this->t('module')} ORDER BY sort_order") as $m) {
            printf('<option value="%d" %s>%s</option>', (int) $m->id, selected($f['module'], (int) $m->id, false), esc_html($m->code));
        }
        echo '</select> ';
        $this->select('type', $f['type'], ['' => __('Alle Typen', 'bootspruefung-learning'), 'single_choice' => Ui::label('single_choice'), 'multi_choice' => Ui::label('multi_choice'), 'open_text' => Ui::label('open_text'), 'chart_task' => Ui::label('chart_task')]);
        $this->select('status', $f['status'], ['' => __('Alle Status', 'bootspruefung-learning'), 'published' => Ui::label('published'), 'draft' => Ui::label('draft'), 'source_archived' => Ui::label('source_archived')]);
        $this->select('rights', $f['rights'], ['' => __('Alle Rechte', 'bootspruefung-learning')] + array_combine(Overrides::RIGHTS, array_map([Ui::class, 'label'], Overrides::RIGHTS)));
        printf('<input type="search" name="s" value="%1$s" placeholder="%2$s" aria-label="%2$s"> ', esc_attr($f['s']), esc_attr__('Kennung, Nr. oder Text', 'bootspruefung-learning'));
        submit_button(__('Filtern', 'bootspruefung-learning'), 'secondary', '', false);
        echo '</form><br>';

        $out = [];
        foreach (array_slice($rows, 0, 50) as $q) {
            $out[] = [
                '<a href="'.esc_url(Ui::url('bootspruefung-questions', ['question' => $q->id])).'"><code>'.esc_html($q->source_id).'</code></a>',
                esc_html((string) $q->official_number),
                esc_html((string) $q->source_module),
                esc_html(Ui::label($q->question_type)),
                esc_html(wp_trim_words(wp_strip_all_tags($q->question_text), 18)).($q->image_required ? ' <span aria-hidden="true">🖼</span><span class="screen-reader-text">'.esc_html__('(mit Pflichtbild)', 'bootspruefung-learning').'</span>' : ''),
                (int) $q->revision_no,
                Ui::status($q->status),
                Ui::status($q->rights).($q->rights_override !== null ? ' <small>'.esc_html__('(lokal)', 'bootspruefung-learning').'</small>' : ''),
            ];
        }
        Ui::table([__('Kennung', 'bootspruefung-learning'), __('Nr.', 'bootspruefung-learning'), __('Modul', 'bootspruefung-learning'), __('Typ', 'bootspruefung-learning'), __('Frage', 'bootspruefung-learning'), __('Fassung', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('Rechte', 'bootspruefung-learning')], $out, __('Keine Fragen gefunden.', 'bootspruefung-learning'));
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
            __('Typ', 'bootspruefung-learning') => esc_html(Ui::label($rev->question_type)),
            __('Thema', 'bootspruefung-learning') => esc_html(implode(' › ', array_filter([$rev->topic, $rev->subtopic, $rev->competency]))),
            __('Smarttrainer-Kategorie', 'bootspruefung-learning') => esc_html((string) $rev->smart_category),
            __('Quelle', 'bootspruefung-learning') => esc_html(trim($rev->source_catalog.' · '.$rev->source_version.' '.($rev->source_question_id ? '· '.$rev->source_question_id : ''))),
            __('Rechte', 'bootspruefung-learning') => Ui::status($rev->rights_status).($override !== null ? ' → '.Ui::status($override).' '.esc_html__('(lokale Abweichung)', 'bootspruefung-learning') : ''),
            __('Module', 'bootspruefung-learning') => esc_html(implode(', ', $modules)),
            __('In Bögen', 'bootspruefung-learning') => esc_html($papers === [] ? '–' : implode(', ', $papers)),
        ]);
        echo '<h3>'.esc_html__('Aktuelle Fassung', 'bootspruefung-learning').' #'.(int) $rev->revision_no.'</h3>';
        echo '<div class="card" style="max-width:900px"><p><strong>'.nl2br(esc_html($rev->question_text)).'</strong></p>';
        foreach ($repo->media((int) $rev->id) as $m) {
            printf('<p><img src="%s" alt="%s" style="max-width:280px;height:auto"></p>', esc_url($m['url']), esc_attr($m['alt']));
        }
        echo '<ol type="A">';
        foreach ($repo->answers((int) $rev->id) as $a) {
            printf('<li%s>%s%s</li>', $a->is_correct ? ' style="font-weight:600;color:#00701a"' : '', esc_html($a->answer_text), $a->is_correct ? ' <span aria-hidden="true">✓</span><span class="screen-reader-text">'.esc_html__('(richtige Antwort)', 'bootspruefung-learning').'</span>' : '');
        }
        echo '</ol>';
        if ($rev->feedback_correct || $rev->feedback_incorrect) {
            echo '<p><em>'.esc_html__('Erklärung richtig:', 'bootspruefung-learning').'</em> '.nl2br(esc_html((string) $rev->feedback_correct)).'</p>';
            echo '<p><em>'.esc_html__('Erklärung falsch:', 'bootspruefung-learning').'</em> '.nl2br(esc_html((string) $rev->feedback_incorrect)).'</p>';
        }
        echo '</div>';

        $revs = $this->db->get_results($this->db->prepare("SELECT revision_no, editorial_status, package_id, created_at, LEFT(question_text, 120) AS text FROM {$this->t('question_revision')} WHERE question_id = %d ORDER BY revision_no DESC", $id));
        echo '<h3>'.esc_html__('Fassungen', 'bootspruefung-learning').'</h3>';
        Ui::table([__('Fassung', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('Inhaltspaket', 'bootspruefung-learning'), __('Angelegt', 'bootspruefung-learning'), __('Text', 'bootspruefung-learning')], array_map(static fn ($r) => [(int) $r->revision_no, Ui::status($r->editorial_status), '<code>'.esc_html((string) $r->package_id).'</code>', esc_html(Ui::date($r->created_at)), esc_html($r->text)], $revs));

        echo '<h3>'.esc_html__('Rechte-Status lokal festlegen', 'bootspruefung-learning').'</h3>';
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
                $exists ? esc_html__('vorhanden', 'bootspruefung-learning') : '<strong style="color:#b32d2e">'.esc_html__('fehlt', 'bootspruefung-learning').'</strong>',
                Ui::status($m->status),
            ];
        }
        echo '<p>'.sprintf(esc_html__('%d Medien', 'bootspruefung-learning'), $total).'</p>';
        Ui::table([__('Vorschau', 'bootspruefung-learning'), __('Kennung', 'bootspruefung-learning'), __('Typ', 'bootspruefung-learning'), __('Quelle', 'bootspruefung-learning'), __('Rechte', 'bootspruefung-learning'), __('Verwendung Fragen/Praxis', 'bootspruefung-learning'), __('Datei', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning')], $out);
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
        echo '<p class="description">'.esc_html__('Die Prüfungssimulation läuft nur mit veröffentlichtem, gültigem und fachlich geprüftem Regelwerk im Prüfungsmodus. Sonst ist sie als Übungsmodus gekennzeichnet.', 'bootspruefung-learning').'</p>';
        Ui::confirmBox();
        $pendingRules = \Bootspruefung\Learning\Content\RightsRelease::releaseExamRules(\Bootspruefung\Learning\Content\RightsRelease::RULES_REASON, true);
        if ($pendingRules !== []) {
            echo '<div class="card" style="max-width:900px"><h2>'.esc_html__('Sammelfreigabe', 'bootspruefung-learning').'</h2><p>'.esc_html(sprintf(__('%1$d Regelwerke laufen noch im Übungsmodus: %2$s. Die Freigabe markiert sie als verifiziert und – bei Entwürfen der Quelle – als veröffentlicht (lokale Abweichung, umkehrbar).', 'bootspruefung-learning'), count($pendingRules), implode(', ', array_column($pendingRules, 'course')))).'</p>';
            // T103: Begründung nicht vorbelegen (Pflichtfeld), Rückfrage mit Anzahl – mit JS als Dialog, ohne JS als Zwischenschritt.
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('bp_rules_release', '_wpnonce', true, false).'<input type="hidden" name="action" value="bp_rules_release"><input type="hidden" name="bp_confirmed" value="">';
            echo '<label for="bp-rules-reason">'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').'</label> <input type="text" class="regular-text" id="bp-rules-reason" name="reason" required placeholder="'.esc_attr__('z. B. Freigabe durch den Betreiber am …', 'bootspruefung-learning').'"> ';
            echo '<button type="submit" class="button button-primary" data-bp-confirm="'.esc_attr(self::rulesConfirmMessage(count($pendingRules))).'">'.esc_html__('Alle Regelwerke freigeben', 'bootspruefung-learning').'</button>';
            echo '</form></div>';
        }
        $overrides = Overrides::forType('exam_rule');
        foreach ($this->db->get_results("SELECT r.*, c.code, c.name FROM {$this->t('exam_rule')} r JOIN {$this->t('course')} c ON c.id = r.course_id ORDER BY c.sort_order, r.id") as $rule) {
            $passing = json_decode((string) $rule->passing_rule, true) ?: [];
            $o = $overrides[(int) $rule->id] ?? [];
            $effective = $repo->examRule((int) $rule->course_id);
            $isLive = $effective['live'] && (int) $effective['rule']->id === (int) $rule->id;
            echo '<div class="card" style="max-width:900px"><h2>'.esc_html($rule->name).' <code>'.esc_html($rule->version).'</code> '.Ui::status($rule->status).' '.($isLive ? Ui::status('published').' '.esc_html__('Prüfungsmodus', 'bootspruefung-learning') : Ui::status('draft').' '.esc_html__('Übungsmodus', 'bootspruefung-learning')).'</h2>';
            $bp = array_map(static fn ($b) => $b->code.': '.$b->question_count, $repo->blueprints((int) $rule->id));
            $parts = array_map(static fn ($p) => $p['label'].' '.$p['min_correct'].'/'.$p['of'], $passing['parts'] ?? []);
            Ui::keyValue([
                __('Zeitlimit', 'bootspruefung-learning') => esc_html(sprintf(__('%d Minuten', 'bootspruefung-learning'), intdiv((int) $rule->time_limit_seconds, 60))),
                __('Fragen je Modul', 'bootspruefung-learning') => esc_html(implode(', ', $bp)),
                __('Bestehensgrenze', 'bootspruefung-learning') => esc_html(round(((float) ($passing['min_correct_ratio'] ?? 0.75)) * 100, 1).' %'.($parts ? ' · '.implode(' · ', $parts) : '')),
                __('Hinweis', 'bootspruefung-learning') => esc_html((string) ($passing['note'] ?? '–')),
                __('Verifiziert (Quelle)', 'bootspruefung-learning') => Ui::yesNo((bool) $rule->verified),
                __('Lokale Abweichung', 'bootspruefung-learning') => esc_html(self::overrideText($o)),
            ]);
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:8px">';
            wp_nonce_field('bp_override');
            printf('<input type="hidden" name="action" value="bp_override"><input type="hidden" name="bp_confirmed" value=""><input type="hidden" name="type" value="exam_rule"><input type="hidden" name="ids" value="%d"><input type="hidden" name="field" value="verified"><input type="hidden" name="back" value="%s">', (int) $rule->id, esc_attr(Ui::url('bootspruefung-rules')));
            // T103: aktuellen Stand vorauswählen (lokale Abweichung, sonst „Quelle gilt“).
            $current = isset($o['verified']) ? (string) $o['verified'] : '';
            echo '<select name="value" aria-label="'.esc_attr__('Prüfung des Regelwerks', 'bootspruefung-learning').'">';
            foreach (['1' => __('als verifiziert markieren', 'bootspruefung-learning'), '0' => __('als nicht verifiziert markieren', 'bootspruefung-learning'), '' => __('Lokale Abweichung entfernen (importierter Wert gilt)', 'bootspruefung-learning')] as $value => $label) {
                printf('<option value="%s" %s>%s</option>', esc_attr((string) $value), selected($current, (string) $value, false), esc_html($label));
            }
            echo '</select> ';
            echo '<input type="text" name="reason" required class="regular-text" aria-label="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'" placeholder="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'"> ';
            echo '<button type="submit" class="button" data-bp-confirm="'.esc_attr(self::overrideConfirmMessage(1)).'">'.esc_html__('Speichern', 'bootspruefung-learning').'</button>';
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
            'radio_scenario' => [__('Funkpraxis-Szenarien nach Katalog', 'bootspruefung-learning'), '', 'x.catalog', 'x.rights_status'],
        ];
    }

    public function renderRights(): void
    {
        $this->can();
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Rechte und Freigabe', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        echo '<p class="description">'.esc_html__('Inhalte mit Rechte-Status „ungeklärt“ oder „gesperrt“ werden nicht ausgeliefert. Eine Freigabe wird als lokale Abweichung mit Begründung gespeichert, bleibt bei neuen Importen erhalten und steht im Protokoll.', 'bootspruefung-learning').'</p>';
        Ui::confirmBox();
        $this->renderCharts();
        $pending = array_sum(\Bootspruefung\Learning\Content\RightsRelease::releaseAll(\Bootspruefung\Learning\Content\RightsRelease::DEFAULT_REASON, true));
        echo '<div class="card" style="max-width:900px"><h2>'.esc_html__('Sammelfreigabe', 'bootspruefung-learning').'</h2><p>'
            .esc_html(sprintf(__('%d Inhalte haben den Rechte-Status „ungeklärt“ und noch keine lokale Entscheidung (Fragen aller Kataloge inkl. ohne Quellenangabe, ELWIS-Bilder, Navigations- und Praxisaufgaben, Videos, Funktexte). Nur diese werden freigegeben; bewusst gesetzte Werte (z. B. „gesperrt“ oder „in Prüfung“), archivierte Inhalte und Seekarten bleiben unverändert.', 'bootspruefung-learning'), $pending)).'</p>';
        if ($pending > 0) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('bp_rights_release', '_wpnonce', true, false)
                .'<input type="hidden" name="action" value="bp_rights_release"><input type="hidden" name="bp_confirmed" value="">';
            echo '<label for="bp-rights-reason">'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').'</label> <input type="text" class="regular-text" id="bp-rights-reason" name="reason" required placeholder="'.esc_attr__('z. B. Rechte geklärt durch …, am …', 'bootspruefung-learning').'"> ';
            echo '<button type="submit" class="button button-primary" data-bp-confirm="'.esc_attr(self::releaseConfirmMessage($pending)).'">'.esc_html__('Ungeklärte Inhalte freigeben (außer Seekarten)', 'bootspruefung-learning').'</button>';
            echo '</form>';
        }
        echo '</div>';
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
                    '<input type="hidden" name="action" value="bp_override"><input type="hidden" name="bp_confirmed" value=""><input type="hidden" name="type" value="%s"><input type="hidden" name="group" value="%s"><input type="hidden" name="imported" value="%s"><input type="hidden" name="effective" value="%s"><input type="hidden" name="field" value="rights_status"><input type="hidden" name="back" value="%s">',
                    esc_attr($type),
                    esc_attr($row->grp),
                    esc_attr($row->imported),
                    esc_attr($row->effective),
                    esc_attr(Ui::url('bootspruefung-rights'))
                );
                // T103: aktuellen (wirksamen) Status vorauswählen statt immer „lizenziert“.
                echo '<select name="value" aria-label="'.esc_attr(sprintf(__('Neuer Rechte-Status für %s', 'bootspruefung-learning'), $row->grp)).'">';
                foreach (Overrides::RIGHTS as $r) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($r), selected($r, (string) $row->effective, false), esc_html(Ui::label($r)));
                }
                echo '<option value="">'.esc_html__('Lokale Abweichung entfernen (importierter Wert gilt)', 'bootspruefung-learning').'</option></select> ';
                echo '<input type="text" name="reason" required aria-label="'.esc_attr__('Rechtsgrundlage/Begründung', 'bootspruefung-learning').'" placeholder="'.esc_attr__('Rechtsgrundlage/Begründung', 'bootspruefung-learning').'"> ';
                echo '<button type="submit" class="button button-small" data-bp-confirm="'.esc_attr(self::overrideConfirmMessage((int) $row->n)).'">'.esc_html__('Anwenden', 'bootspruefung-learning').'</button>';
                echo '</form>';
                $out[] = [esc_html($row->grp), Ui::status($row->imported), Ui::status($row->effective), (int) $row->n, (string) ob_get_clean()];
            }
            Ui::table([__('Gruppe', 'bootspruefung-learning'), __('importiert', 'bootspruefung-learning'), __('wirksam', 'bootspruefung-learning'), __('Anzahl', 'bootspruefung-learning'), __('Ändern', 'bootspruefung-learning')], $out);
        }
        echo '<h2>'.esc_html__('Letzte Freigaben', 'bootspruefung-learning').'</h2>';
        $log = $this->db->get_results("SELECT * FROM {$this->t('audit_log')} WHERE action LIKE 'override\\_%' ORDER BY id DESC LIMIT 20");
        AuditPage::table($log ?: [], true); // T103: Klartext statt JSON
        echo '</div>';
    }

    private function renderCharts(): void
    {
        echo '<div class="card" style="max-width:900px"><h2>'.esc_html__('Seekarten (BSH)', 'bootspruefung-learning').'</h2>';
        echo '<p>'.esc_html__('Die Karte wird hochgeladen und geschützt abgelegt. Lernende sehen sie im Navigationstrainer erst, wenn die BSH-Lizenz bestätigt ist; bis dahin erscheint der Hinweis, mit der gedruckten Karte zu arbeiten.', 'bootspruefung-learning').'</p>';
        foreach (\Bootspruefung\Learning\Gate\Charts::catalog() as $code => $def) {
            $state = \Bootspruefung\Learning\Gate\Charts::state($code);
            $file = $state && $state['attachment_id'] > 0 ? get_post($state['attachment_id']) : null;
            echo '<h3>'.esc_html($def['title']).'</h3><p>';
            echo esc_html__('Datei:', 'bootspruefung-learning').' '.($file ? '<a href="'.esc_url(\Bootspruefung\Learning\Gate\ProtectedFiles::downloadUrl((int) $file->ID)).'">'.esc_html(get_the_title($file)).'</a>' : esc_html__('noch nicht hochgeladen', 'bootspruefung-learning')).'<br>';
            echo esc_html__('BSH-Lizenz:', 'bootspruefung-learning').' '.($state && $state['licensed'] ? esc_html(__('bestätigt', 'bootspruefung-learning').' – '.$state['note']) : esc_html__('nicht bestätigt (für Lernende gesperrt)', 'bootspruefung-learning')).'<br>';
            echo esc_html__('Kurse:', 'bootspruefung-learning').' '.esc_html(implode(', ', $def['courses'])).'</p>';
            $form = static fn (string $op, string $inner, string $extra = '') => '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:.4em 0">'
                .wp_nonce_field('bp_chart', '_wpnonce', true, false).'<input type="hidden" name="action" value="bp_chart"><input type="hidden" name="chart" value="'.esc_attr($code).'"><input type="hidden" name="op" value="'.esc_attr($op).'">'.$inner.$extra.'</form>';
            echo $form('upload', '<input type="file" name="chart_file" accept=".pdf,.png,.jpg,.jpeg,.webp" required aria-label="'.esc_attr(sprintf(__('Datei für %s', 'bootspruefung-learning'), $def['title'])).'"> ', '<button class="button">'.esc_html($file ? __('Datei ersetzen', 'bootspruefung-learning') : __('Hochladen', 'bootspruefung-learning')).'</button>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ($state && $state['licensed']) {
                echo $form('unlicense', '', '<button class="button">'.esc_html__('Lizenz zurückziehen (sperren)', 'bootspruefung-learning').'</button>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo $form('license', '<input type="text" class="regular-text" name="note" required placeholder="'.esc_attr__('Lizenznachweis (Vertrag, Datum)', 'bootspruefung-learning').'" aria-label="'.esc_attr__('Lizenznachweis (Vertrag, Datum)', 'bootspruefung-learning').'"> ', '<button class="button button-primary">'.esc_html__('BSH-Lizenz bestätigen', 'bootspruefung-learning').'</button>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            if ($file) {
                echo $form('remove', '', '<button class="button-link-delete">'.esc_html__('Datei löschen', 'bootspruefung-learning').'</button>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
        echo '</div>';
    }

    public function handleRelease(): void
    {
        $this->can();
        check_admin_referer('bp_rights_release');
        $reason = trim(sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
        $back = Ui::url('bootspruefung-rights');
        if ($reason === '') {
            Ui::flash(__('Bitte eine Begründung angeben.', 'bootspruefung-learning'), 'error');
        } elseif (! Ui::confirmed()) {
            $back = Ui::rememberConfirm('bp_rights_release', 'bp_rights_release', ['reason' => $reason], self::releaseConfirmMessage(array_sum(\Bootspruefung\Learning\Content\RightsRelease::releaseAll($reason, true))), $back);
        } else {
            $n = array_sum(\Bootspruefung\Learning\Content\RightsRelease::releaseAll($reason));
            Ui::flash(sprintf(__('%d Inhalte freigegeben.', 'bootspruefung-learning'), $n));
        }
        wp_safe_redirect($back);
        exit;
    }

    public function handleRulesRelease(): void
    {
        $this->can();
        check_admin_referer('bp_rules_release');
        $reason = trim(sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
        $back = Ui::url('bootspruefung-rules');
        if ($reason === '') {
            Ui::flash(__('Bitte eine Begründung angeben.', 'bootspruefung-learning'), 'error');
        } elseif (! Ui::confirmed()) {
            $back = Ui::rememberConfirm('bp_rules_release', 'bp_rules_release', ['reason' => $reason], self::rulesConfirmMessage(count(\Bootspruefung\Learning\Content\RightsRelease::releaseExamRules($reason, true))), $back);
        } else {
            $n = count(\Bootspruefung\Learning\Content\RightsRelease::releaseExamRules($reason));
            Ui::flash(sprintf(__('%d Regelwerke freigegeben – die Prüfungssimulation läuft jetzt im Prüfungsmodus.', 'bootspruefung-learning'), $n));
        }
        wp_safe_redirect($back);
        exit;
    }

    public function handleChart(): void
    {
        $this->can();
        check_admin_referer('bp_chart');
        $code = sanitize_text_field(wp_unslash($_POST['chart'] ?? ''));
        $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        try {
            if (! isset(\Bootspruefung\Learning\Gate\Charts::catalog()[$code])) {
                throw new \InvalidArgumentException(__('Unbekannte Karte.', 'bootspruefung-learning'));
            }
            if ($op === 'upload') {
                require_once ABSPATH.'wp-admin/includes/file.php';
                require_once ABSPATH.'wp-admin/includes/media.php';
                require_once ABSPATH.'wp-admin/includes/image.php';
                $id = media_handle_upload('chart_file', 0, [], ['test_form' => false, 'mimes' => ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp']]);
                if (is_wp_error($id)) {
                    throw new \InvalidArgumentException($id->get_error_message());
                }
                \Bootspruefung\Learning\Gate\Charts::attach($code, (int) $id);
                Ui::flash(__('Seekarte hochgeladen (geschützt). Für Lernende sichtbar, sobald die BSH-Lizenz bestätigt ist.', 'bootspruefung-learning'));
            } elseif ($op === 'license' || $op === 'unlicense') {
                \Bootspruefung\Learning\Gate\Charts::setLicense($code, $op === 'license', sanitize_text_field(wp_unslash($_POST['note'] ?? '')));
                Ui::flash($op === 'license' ? __('BSH-Lizenz bestätigt.', 'bootspruefung-learning') : __('Lizenz zurückgezogen – Karte für Lernende gesperrt.', 'bootspruefung-learning'));
            } elseif ($op === 'remove') {
                \Bootspruefung\Learning\Gate\Charts::remove($code);
                Ui::flash(__('Datei gelöscht.', 'bootspruefung-learning'));
            }
        } catch (\InvalidArgumentException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect(Ui::url('bootspruefung-rights'));
        exit;
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
        $fields = ['type' => $type, 'field' => $field, 'value' => $value, 'reason' => $reason, 'back' => $back];
        if (isset($_POST['group'])) {
            $fields += ['group' => (string) wp_unslash($_POST['group']), 'imported' => (string) wp_unslash($_POST['imported'] ?? ''), 'effective' => (string) wp_unslash($_POST['effective'] ?? '')];
            $ids = $this->idsForGroup($type, $fields['group'], $fields['imported'], $fields['effective']);
        } else {
            $ids = array_map('absint', explode(',', (string) ($_POST['ids'] ?? '')));
            $fields['ids'] = implode(',', array_filter($ids));
        }
        $ids = array_values(array_filter($ids));
        try {
            if (trim($reason) === '') {
                throw new InvalidArgumentException(__('Bitte eine Begründung angeben.', 'bootspruefung-learning'));
            }
            if (! Ui::confirmed()) {
                // T103: ohne JS-Bestätigung erst der Zwischenschritt mit Anzahl und neuem Wert.
                wp_safe_redirect(Ui::rememberConfirm('bp_override', 'bp_override', $fields, self::overrideSummary($type, $field, $value, count($ids)), $back));
                exit;
            }
            $count = Overrides::set($type, $ids, $field, $value === '' ? null : $value, $reason);
            Ui::flash(self::overrideSummary($type, $field, $value, $count, true));
        } catch (InvalidArgumentException $e) {
            Ui::flash($e->getMessage(), 'error');
        }
        wp_safe_redirect($back);
        exit;
    }

    /** Rückfrage-Text der Sammelfreigabe (Rechte). */
    public static function releaseConfirmMessage(int $n): string
    {
        /* translators: %d: Anzahl Inhalte */
        return sprintf(_n('%d Inhalt mit Rechte-Status „ungeklärt“ als „lizenziert“ freigeben? Er wird danach an Lernende ausgeliefert.', '%d Inhalte mit Rechte-Status „ungeklärt“ als „lizenziert“ freigeben? Sie werden danach an Lernende ausgeliefert.', $n, 'bootspruefung-learning'), $n);
    }

    /** Rückfrage-Text der Sammelfreigabe (Regelwerke). */
    public static function rulesConfirmMessage(int $n): string
    {
        /* translators: %d: Anzahl Regelwerke */
        return sprintf(_n('%d Regelwerk freigeben? Die Prüfungssimulation dieses Kurses zählt danach als echte Prüfungssimulation.', '%d Regelwerke freigeben? Die Prüfungssimulation dieser Kurse zählt danach als echte Prüfungssimulation.', $n, 'bootspruefung-learning'), $n);
    }

    /** Rückfrage im Browser-Dialog: Anzahl der betroffenen Einträge (der neue Wert steht im Auswahlfeld daneben). */
    public static function overrideConfirmMessage(int $n): string
    {
        /* translators: %d: Anzahl Einträge */
        return sprintf(_n('Wert für %d Eintrag ändern?', 'Wert für %d Einträge ändern?', $n, 'bootspruefung-learning'), $n);
    }

    /** Klartext für Rückfrage und Meldung: Anzahl, Inhaltsart und neuer Wert. */
    public static function overrideSummary(string $type, string $field, string $value, int $n, bool $done = false): string
    {
        $what = Ui::entityLabel($type);
        $new = match (true) {
            $value === '' => __('lokale Abweichung entfernen (importierter Wert gilt)', 'bootspruefung-learning'),
            $field === 'verified' => $value === '1' ? __('als verifiziert markiert', 'bootspruefung-learning') : __('als nicht verifiziert markiert', 'bootspruefung-learning'),
            default => sprintf(__('Rechte-Status „%s“', 'bootspruefung-learning'), Ui::label($value)),
        };
        if ($done) {
            /* translators: 1: Anzahl, 2: Inhaltsart, 3: neuer Wert */
            return sprintf(_n('%1$d Eintrag (%2$s) geändert: %3$s.', '%1$d Einträge (%2$s) geändert: %3$s.', $n, 'bootspruefung-learning'), $n, $what, $new);
        }

        /* translators: 1: Anzahl, 2: Inhaltsart, 3: neuer Wert */
        return sprintf(_n('%1$d Eintrag (%2$s) ändern: %3$s?', '%1$d Einträge (%2$s) ändern: %3$s?', $n, 'bootspruefung-learning'), $n, $what, $new);
    }

    /** Lokale Abweichungen eines Regelwerks als Text. @param array<string,?string> $o */
    public static function overrideText(array $o): string
    {
        if ($o === []) {
            return '–';
        }
        $parts = [];
        foreach ($o as $field => $value) {
            $parts[] = match ($field) {
                'verified' => __('verifiziert', 'bootspruefung-learning').': '.($value === '1' ? __('ja', 'bootspruefung-learning') : __('nein', 'bootspruefung-learning')),
                'status' => __('Status', 'bootspruefung-learning').': '.Ui::label((string) $value),
                'valid_from' => __('gültig ab', 'bootspruefung-learning').': '.($value ? Ui::date((string) $value) : '–'),
                'valid_until' => __('gültig bis', 'bootspruefung-learning').': '.($value ? Ui::date((string) $value) : '–'),
                default => $field.': '.(string) $value,
            };
        }

        return implode(' · ', $parts);
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
        printf('<input type="hidden" name="action" value="bp_override"><input type="hidden" name="bp_confirmed" value=""><input type="hidden" name="type" value="%s"><input type="hidden" name="ids" value="%s"><input type="hidden" name="field" value="%s"><input type="hidden" name="back" value="%s">', esc_attr($type), esc_attr(implode(',', $ids)), esc_attr($field), esc_attr($back));
        echo '<select name="value" aria-label="'.esc_attr__('Neuer Rechte-Status', 'bootspruefung-learning').'">';
        foreach (Overrides::RIGHTS as $r) {
            printf('<option value="%s" %s>%s</option>', esc_attr($r), selected((string) $current, $r, false), esc_html(Ui::label($r)));
        }
        // Ohne lokale Abweichung ist „Quelle gilt“ der aktuelle Stand.
        echo '<option value="" '.selected($current === null, true, false).'>'.esc_html__('Lokale Abweichung entfernen (importierter Wert gilt)', 'bootspruefung-learning').'</option></select> ';
        echo '<input type="text" name="reason" required class="regular-text" aria-label="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'" placeholder="'.esc_attr__('Begründung (Pflicht)', 'bootspruefung-learning').'"> ';
        echo '<button type="submit" class="button" data-bp-confirm="'.esc_attr(self::overrideConfirmMessage(count($ids))).'">'.esc_html__('Speichern', 'bootspruefung-learning').'</button>';
        echo '</form>';
    }

    /** @param array<string,string> $options */
    private function select(string $name, string $current, array $options): void
    {
        $labels = ['type' => __('Typ', 'bootspruefung-learning'), 'status' => __('Status', 'bootspruefung-learning'), 'rights' => __('Rechte-Status', 'bootspruefung-learning')];
        printf('<select name="%s" aria-label="%s">', esc_attr($name), esc_attr($labels[$name] ?? $name));
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

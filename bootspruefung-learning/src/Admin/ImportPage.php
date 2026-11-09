<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Content\Import\ImportService;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Security\Capabilities;
use RuntimeException;

/** Content-Pakete: Upload, Prüfung, Vorschau (Dry-Run), Freigabe, Import, Protokoll (Kap. 9). */
final class ImportPage
{
    public const SLUG = 'bootspruefung-import';

    public function register(): void
    {
        add_action('admin_post_bp_import_upload', [$this, 'handleUpload']);
        add_action('admin_post_bp_import_action', [$this, 'handleAction']);
    }

    public function handleUpload(): void
    {
        $this->guard('bp_import_upload');
        $file = $_FILES['package'] ?? null;
        if (! is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $file['tmp_name'])) {
            $this->redirect(null, __('Bitte eine ZIP-Datei auswählen.', 'bootspruefung-learning'), 'error');
        }
        if (! str_ends_with(strtolower((string) $file['name']), '.zip')) {
            $this->redirect(null, __('Nur ZIP-Dateien sind erlaubt.', 'bootspruefung-learning'), 'error');
        }
        try {
            $batchId = Plugin::instance()->importService()->createFromZip((string) $file['tmp_name']);
        } catch (RuntimeException $e) {
            $this->redirect(null, $e->getMessage(), 'error');
        }
        $this->redirect($batchId, __('Paket hochgeladen und geprüft.', 'bootspruefung-learning'));
    }

    public function handleAction(): void
    {
        $this->guard('bp_import_action');
        $batchId = absint($_POST['batch'] ?? 0);
        $action = sanitize_key((string) ($_POST['do'] ?? ''));
        $service = Plugin::instance()->importService();
        $note = sanitize_textarea_field(wp_unslash((string) ($_POST['note'] ?? '')));
        $message = __('Erledigt.', 'bootspruefung-learning');
        try {
            switch ($action) {
                case 'preview':
                    // In Teilschritten: ist das Zeitbudget um, setzt die Seite die Vorschau automatisch fort.
                    if ($service->preview($batchId, ImportService::PREVIEW_STEP_SECONDS) === null) {
                        $this->redirect($batchId, __('Vorschau wird in Teilschritten berechnet …', 'bootspruefung-learning'), 'info');
                    }
                    break;
                case 'approve':
                    $service->approve($batchId, $note);
                    break;
                case 'discard':
                    $service->discard($batchId);
                    $batchId = 0;
                    break;
                case 'discard_restore':
                    // Abgebrochenen Import verwerfen und den aktiven Stand per Rollback-Import wiederherstellen.
                    $service->discard($batchId);
                    try {
                        $restore = $service->restoreActive($note !== '' ? $note : __('Wiederherstellung nach abgebrochenem Import', 'bootspruefung-learning'));
                    } catch (RuntimeException $e) {
                        $this->redirect(null, sprintf(__('Import verworfen, aber der aktive Stand konnte nicht wiederhergestellt werden: %s', 'bootspruefung-learning'), $e->getMessage()), 'error');
                    }
                    if ($restore === null) {
                        $this->redirect(null, __('Import verworfen. Es gibt keinen veröffentlichten Stand zum Wiederherstellen – bitte ein vollständiges Paket importieren.', 'bootspruefung-learning'), 'warning');
                    }
                    $batchId = $restore;
                    $message = __('Import verworfen. Ein Rollback-Import auf das aktive Paket wurde angelegt: bitte Vorschau erstellen, freigeben und importieren, um den Mischstand zu beseitigen.', 'bootspruefung-learning');
                    break;
                case 'validate':
                    $service->validate($batchId);
                    break;
                case 'rollback':
                    $batchId = $service->rollback($batchId, $note);
                    break;
                default:
                    throw new RuntimeException(__('Unbekannte Aktion.', 'bootspruefung-learning'));
            }
        } catch (RuntimeException $e) {
            $this->redirect($batchId ?: null, $e->getMessage(), 'error');
        }
        $this->redirect($batchId ?: null, $message);
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::IMPORT_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $batchId = absint($_GET['batch'] ?? 0);
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Content-Pakete', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        $batchId > 0 ? $this->renderBatch($batchId) : $this->renderList();
        echo '</div>';
    }

    private function renderList(): void
    {
        $service = Plugin::instance()->importService();
        echo '<h2>'.esc_html__('Paket hochladen', 'bootspruefung-learning').'</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_import_upload');
        echo '<input type="hidden" name="action" value="bp_import_upload">';
        echo '<p><input type="file" name="package" accept=".zip,application/zip" required> ';
        submit_button(__('Hochladen und prüfen', 'bootspruefung-learning'), 'primary', 'submit', false);
        echo '</p><p class="description">'.esc_html__('ZIP aus tools/content-build (manifest.json, data/, media/). Max. 200 MB; der Server-Uploadwert kann niedriger sein. Prüfung und Entpacken laufen in diesem Request – bei großen Paketen (viele Medien) oder knappen Server-Zeitlimits besser per WP-CLI importieren: wp bootspruefung import <paket>.', 'bootspruefung-learning').'</p></form>';

        $aborted = $service->abortedImport();
        if ($aborted !== null) {
            echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('Achtung: Mischstand.', 'bootspruefung-learning').'</strong> '
                .sprintf(esc_html__('Import #%d wurde abgebrochen. Die Inhalte sind teils alt, teils neu. Bitte den Import fortsetzen oder verwerfen und den aktiven Stand wiederherstellen.', 'bootspruefung-learning'), $aborted)
                .' <a href="'.esc_url(Ui::url(self::SLUG, ['batch' => $aborted])).'">'.esc_html__('Zum Import', 'bootspruefung-learning').'</a></p></div>';
        }

        $active = get_option('bp_learning_active_package');
        echo '<p>'.esc_html__('Aktives Paket:', 'bootspruefung-learning').' <code>'.esc_html($active ?: '–').'</code></p>';

        echo '<h2>'.esc_html__('Importe', 'bootspruefung-learning').'</h2>';
        $rows = [];
        foreach ($service->batches() as $b) {
            $rows[] = [
                '<a href="'.esc_url(Ui::url(self::SLUG, ['batch' => $b->id])).'">#'.(int) $b->id.'</a>',
                '<code>'.esc_html($b->package_id).'</code>',
                Ui::status($b->status),
                esc_html(substr((string) $b->source_commit, 0, 7)),
                esc_html(Ui::date($b->created_at)),
                esc_html(Ui::date($b->published_at)),
            ];
        }
        Ui::table([__('Import', 'bootspruefung-learning'), __('Paket', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), 'Commit', __('Hochgeladen', 'bootspruefung-learning'), __('Veröffentlicht', 'bootspruefung-learning')], $rows, __('Noch keine Importe.', 'bootspruefung-learning'));
    }

    private function renderBatch(int $batchId): void
    {
        $service = Plugin::instance()->importService();
        try {
            $batch = $service->batch($batchId);
        } catch (RuntimeException $e) {
            echo '<p>'.esc_html($e->getMessage()).'</p>';

            return;
        }
        $manifest = json_decode((string) $batch->manifest, true) ?: [];
        $stats = $service->stats($batch);
        echo '<p><a href="'.esc_url(Ui::url(self::SLUG)).'">&larr; '.esc_html__('Alle Importe', 'bootspruefung-learning').'</a></p>';
        echo '<h2>'.sprintf(esc_html__('Import #%d', 'bootspruefung-learning'), $batchId).' '.Ui::status($batch->status).'</h2>';

        Ui::keyValue([
            __('Paket', 'bootspruefung-learning') => '<code>'.esc_html($batch->package_id).'</code>',
            __('Quelle', 'bootspruefung-learning') => esc_html(($manifest['source']['repository'] ?? '').' @ '.substr((string) ($manifest['source']['commit_sha'] ?? ''), 0, 7)),
            __('Erstellt (Konverter)', 'bootspruefung-learning') => esc_html(($manifest['built_at'] ?? '–').' · v'.($manifest['converter_version'] ?? '?')),
            __('Schema', 'bootspruefung-learning') => esc_html((string) $batch->schema_version),
            __('Hochgeladen', 'bootspruefung-learning') => esc_html(Ui::date($batch->created_at)),
            __('Freigegeben', 'bootspruefung-learning') => esc_html(Ui::date($batch->approved_at).($batch->approved_by ? ' · '.(get_userdata((int) $batch->approved_by)->display_name ?? '') : '')),
            __('Veröffentlicht', 'bootspruefung-learning') => esc_html(Ui::date($batch->published_at)),
        ]);

        $batchStats = $service->stats($batch);
        if (isset($batchStats['rollback_of'])) {
            echo '<div class="notice notice-warning inline"><p>'.sprintf(esc_html__('Rollback auf den Stand von Import #%1$d. Begründung: %2$s', 'bootspruefung-learning'), (int) $batchStats['rollback_of'], esc_html((string) ($batchStats['rollback_reason'] ?? ''))).'</p></div>';
        }
        if ($batch->error) {
            echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('Fehler', 'bootspruefung-learning').'</strong></p><pre style="white-space:pre-wrap">'.esc_html((string) $batch->error).'</pre></div>';
        }

        $this->renderState($batch);
        $this->renderActions($batch);

        if (isset($stats['preview'])) {
            echo '<h3>'.esc_html__('Vorschau (Dry-Run)', 'bootspruefung-learning').'</h3>';
            $rows = [];
            foreach ($stats['preview'] as $entity => $counts) {
                if ($entity === '_rights') {
                    continue;
                }
                $rows[] = [esc_html(Ui::entityLabel($entity)), (int) $counts['create'], (int) $counts['update'], (int) $counts['unchanged'], (int) $counts['archive']];
            }
            Ui::table([__('Bereich', 'bootspruefung-learning'), __('neu', 'bootspruefung-learning'), __('geändert', 'bootspruefung-learning'), __('unverändert', 'bootspruefung-learning'), __('archivieren', 'bootspruefung-learning')], $rows);
            if (! empty($stats['preview']['_rights'])) {
                echo '<h4>'.esc_html__('Rechte-Status im Paket', 'bootspruefung-learning').'</h4><p class="description">'.esc_html__('„ungeklärt“ (unknown) und „gesperrt“ (restricted) werden importiert, aber nicht ausgeliefert, bis sie unter „Rechte“ freigegeben sind.', 'bootspruefung-learning').'</p>';
                $rows = [];
                foreach ($stats['preview']['_rights'] as $entity => $counts) {
                    $rows[] = [esc_html(Ui::entityLabel($entity)), esc_html(implode(', ', array_map(static fn ($k, $v) => Ui::label((string) $k).": {$v}", array_keys($counts), $counts)))];
                }
                Ui::table([__('Bereich', 'bootspruefung-learning'), __('Rechte-Status', 'bootspruefung-learning')], $rows);
            }
        }

        if (isset($stats['actions'])) {
            echo '<h3>'.esc_html__('Ergebnis', 'bootspruefung-learning').'</h3>';
            $rows = [];
            foreach ($stats['actions'] as $phase => $counts) {
                $rows[] = [esc_html(Ui::entityLabel($phase)), esc_html(implode(', ', array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts)))];
            }
            Ui::table([__('Phase', 'bootspruefung-learning'), __('Aktionen', 'bootspruefung-learning')], $rows);
        }

        $warnings = $manifest['warnings'] ?? [];
        $skipped = $manifest['skipped'] ?? [];
        $notConverted = $manifest['not_converted'] ?? [];
        echo '<h3>'.sprintf(esc_html__('Hinweise aus dem Paket (%1$d Warnungen, %2$d übersprungene Quellzeilen)', 'bootspruefung-learning'), count($warnings), count($skipped)).'</h3>';
        $rows = [];
        foreach ($warnings as $w) {
            $rows[] = [esc_html($w['code']), esc_html(Ui::entityLabel($w['entity'])), '<code>'.esc_html((string) $w['source_id']).'</code>', esc_html($w['message'])];
        }
        foreach ($skipped as $s) {
            $rows[] = ['skipped', esc_html(Ui::entityLabel($s['entity'])), '<code>'.esc_html((string) $s['source_id']).'</code>', esc_html($s['source_file'].($s['line'] ? ':'.$s['line'] : '').' – '.$s['reason'])];
        }
        Ui::table([__('Code', 'bootspruefung-learning'), __('Bereich', 'bootspruefung-learning'), 'ID', __('Meldung', 'bootspruefung-learning')], $rows, __('Keine.', 'bootspruefung-learning'));
        if ($notConverted !== []) {
            echo '<p><strong>'.esc_html__('Noch nicht im Paket:', 'bootspruefung-learning').'</strong> '.esc_html(implode(', ', array_column($notConverted, 'entity'))).'</p>';
        }

        if (in_array($batch->status, ['importing', 'published'], true)) {
            $this->renderLog($batchId);
        }
    }

    /** Warnungen zu veralteten, abgebrochenen oder hängengebliebenen Importen. */
    private function renderState(object $batch): void
    {
        $service = Plugin::instance()->importService();
        if (in_array($batch->status, ['validated', 'previewed', 'approved', 'importing'], true) && ($newer = $service->supersededBy($batch)) !== null) {
            echo '<div class="notice notice-error inline"><p>'.sprintf(esc_html__('Seit diesem Import bzw. seiner Vorschau wurde Import #%d veröffentlicht. Vorschau und Freigabe sind ungültig; fortsetzen würde den neueren Stand überschreiben. Bitte verwerfen und das Paket bei Bedarf neu hochladen.', 'bootspruefung-learning'), $newer).'</p></div>';
        }
        if ($batch->status === 'importing') {
            $minutes = (int) floor(max(0, time() - $service->lastActivity($batch)) / 60);
            if ($service->isAborted($batch)) {
                $why = $service->isStale($batch)
                    ? sprintf(esc_html__('Seit %d Minuten kein Importschritt mehr (Abbruch durch Fehler, Zeitlimit oder geschlossenes Fenster).', 'bootspruefung-learning'), $minutes)
                    : esc_html__('Der Import ist mit einem Fehler stehengeblieben.', 'bootspruefung-learning');
                echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('Abgebrochener Import – Mischstand live.', 'bootspruefung-learning').'</strong> '.$why.' '
                    .esc_html__('Ein Teil der Inhalte stammt schon aus diesem Paket, der Rest aus dem bisherigen. Entweder fortsetzen (Ursache vorher beheben) oder verwerfen und den aktiven Stand wiederherstellen.', 'bootspruefung-learning').'</p></div>';
            } else {
                echo '<div class="notice notice-info inline"><p>'.sprintf(esc_html__('Import läuft (letzter Schritt vor %1$d Minuten). Bleibt er länger als %2$d Minuten ohne Fortschritt, kann er fortgesetzt oder verworfen werden.', 'bootspruefung-learning'), $minutes, (int) (ImportService::STALE_AFTER / 60)).'</p></div>';
            }
        }
        if ($batch->status === 'published' && $batch->package_id === get_option('bp_learning_active_package') && $service->abortedImport() !== null && $service->activeBatchId() === (int) $batch->id) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html__('Nach diesem Stand wurde ein Import abgebrochen. Zurücksetzen auf diesen Stand beseitigt den Mischstand.', 'bootspruefung-learning').'</p></div>';
        }
        if (($pct = $service->previewProgress($batch)) !== null) {
            // Vorschau in Teilschritten: nächsten Schritt automatisch anstoßen.
            echo '<div class="notice notice-info inline"><p>'.sprintf(esc_html__('Vorschau wird berechnet … (%d %%)', 'bootspruefung-learning'), $pct).'</p>';
            echo '<form method="post" id="bp-preview-continue" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bp_import_action');
            echo '<input type="hidden" name="action" value="bp_import_action"><input type="hidden" name="batch" value="'.(int) $batch->id.'"><input type="hidden" name="do" value="preview">';
            submit_button(__('Vorschau fortsetzen', 'bootspruefung-learning'), 'secondary', 'submit', false);
            echo '</form><script>setTimeout(function(){var f=document.getElementById("bp-preview-continue");if(f){f.submit();}},300);</script></div>';
        }
    }

    private function renderActions(object $batch): void
    {
        $service = Plugin::instance()->importService();
        $superseded = $service->supersededBy($batch) !== null;
        $restoreNote = '<input type="hidden" name="note" value="'.esc_attr__('Wiederherstellung nach abgebrochenem Import', 'bootspruefung-learning').'">';
        $form = static function (string $do, string $label, string $class = 'secondary', string $extra = '', array $attrs = []) use ($batch): string {
            ob_start();
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:8px">';
            wp_nonce_field('bp_import_action');
            echo '<input type="hidden" name="action" value="bp_import_action"><input type="hidden" name="batch" value="'.(int) $batch->id.'"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra;
            submit_button($label, $class, 'submit', false, $attrs);
            echo '</form>';

            return (string) ob_get_clean();
        };
        echo '<div class="bp-actions" style="margin:1em 0">';
        switch ($batch->status) {
            case 'validated':
                if ($superseded) {
                    echo $form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                    break;
                }
                echo $form('preview', __('Vorschau erstellen (Dry-Run)', 'bootspruefung-learning'), 'primary');
                echo $form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                break;
            case 'previewed':
                if ($superseded) {
                    echo $form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                    break;
                }
                echo $form('approve', __('Freigeben', 'bootspruefung-learning'), 'primary', '<label>'.esc_html__('Notiz/Begründung', 'bootspruefung-learning').' <input type="text" name="note" class="regular-text"></label> ');
                echo $form('preview', __('Vorschau neu berechnen', 'bootspruefung-learning'));
                echo $form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                break;
            case 'approved':
            case 'importing':
                $aborted = $service->isAborted($batch);
                if ($superseded) {
                    echo $form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                    if ($aborted && $service->activeBatchId() !== null) {
                        echo $form('discard_restore', __('Verwerfen und aktiven Stand wiederherstellen', 'bootspruefung-learning'), 'primary', $restoreNote, ['data-bp-confirm' => __('Abgebrochenen Import verwerfen und einen Rollback-Import auf das aktive Paket anlegen?', 'bootspruefung-learning')]);
                    }
                    break;
                }
                printf(
                    '<button type="button" class="button button-primary" id="bp-import-run" data-batch="%d">%s</button> <progress id="bp-import-progress" max="100" value="0" style="width:300px;vertical-align:middle"></progress> <span id="bp-import-status" aria-live="polite"></span>',
                    (int) $batch->id,
                    esc_html($batch->status === 'importing' ? __('Import fortsetzen', 'bootspruefung-learning') : __('Import starten', 'bootspruefung-learning'))
                );
                // Abgebrochener Import: fortsetzen oder verwerfen (blockiert sonst keine weiteren Importe).
                if ($aborted) {
                    echo '<p>';
                    if ($service->activeBatchId() !== null) {
                        echo $form('discard_restore', __('Verwerfen und aktiven Stand wiederherstellen', 'bootspruefung-learning'), 'secondary', $restoreNote, ['data-bp-confirm' => __('Abgebrochenen Import verwerfen und einen Rollback-Import auf das aktive Paket anlegen?', 'bootspruefung-learning')]);
                    }
                    echo $form('discard', __('Nur verwerfen (Mischstand bleibt)', 'bootspruefung-learning'), 'delete');
                    echo '</p><p class="description">'.esc_html__('„Verwerfen und wiederherstellen“ legt einen Rollback-Import auf das aktive Paket an; nach Vorschau und Freigabe sind alle Inhalte wieder auf diesem Stand. Per WP-CLI: wp bootspruefung import resume|discard <ID>.', 'bootspruefung-learning').'</p>';
                } elseif ($batch->status === 'approved') {
                    echo ' '.$form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                }
                break;
            case 'published':
                if ($batch->package_id === get_option('bp_learning_active_package') && $service->abortedImport() !== null && $service->activeBatchId() === (int) $batch->id) {
                    echo $form('rollback', __('Auf diesen Stand zurücksetzen (Mischstand beseitigen)', 'bootspruefung-learning'), 'primary', '<label>'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').' <input type="text" name="note" class="regular-text" required value="'.esc_attr__('Wiederherstellung nach abgebrochenem Import', 'bootspruefung-learning').'"></label> ');
                } elseif ($batch->package_id !== get_option('bp_learning_active_package')) {
                    echo '<p class="description">'.esc_html__('Dieser Stand ist nicht mehr aktiv. Zurücksetzen legt einen neuen Import aus diesem Paket an, der wie jeder Import geprüft, als Vorschau angezeigt und freigegeben wird. Frühere Fragenrevisionen werden wieder aktiv, nichts wird gelöscht.', 'bootspruefung-learning').'</p>';
                    echo $form('rollback', __('Auf diesen Stand zurücksetzen', 'bootspruefung-learning'), 'secondary', '<label>'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').' <input type="text" name="note" class="regular-text" required></label> ');
                }
                break;
            case 'failed':
                echo $form('validate', __('Erneut prüfen', 'bootspruefung-learning'));
                echo $form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                break;
        }
        echo '</div>';
    }

    private function renderLog(int $batchId): void
    {
        $filter = sanitize_key((string) ($_GET['log_action'] ?? ''));
        $page = max(1, absint($_GET['log_page'] ?? 1));
        $logs = Plugin::instance()->importService()->logs($batchId, $filter === '' ? null : $filter, 100, ($page - 1) * 100);
        echo '<h3>'.esc_html__('Protokoll', 'bootspruefung-learning').'</h3><p>';
        foreach (['' => __('alle', 'bootspruefung-learning'), 'create' => __('neu', 'bootspruefung-learning'), 'update' => __('geändert', 'bootspruefung-learning'), 'unchanged' => __('unverändert', 'bootspruefung-learning'), 'archive' => __('archiviert', 'bootspruefung-learning'), 'error' => __('Fehler', 'bootspruefung-learning')] as $value => $label) {
            printf('<a class="button%s" href="%s">%s</a> ', $filter === $value ? ' button-primary' : '', esc_url(Ui::url(self::SLUG, ['batch' => $batchId, 'log_action' => $value])), esc_html($label));
        }
        echo '</p>';
        $rows = [];
        foreach ($logs as $log) {
            $rows[] = [esc_html(Ui::entityLabel($log->entity)), '<code>'.esc_html((string) $log->source_id).'</code>', esc_html(['create' => __('neu', 'bootspruefung-learning'), 'update' => __('geändert', 'bootspruefung-learning'), 'unchanged' => __('unverändert', 'bootspruefung-learning'), 'archive' => __('archiviert', 'bootspruefung-learning'), 'error' => __('Fehler', 'bootspruefung-learning')][$log->action] ?? $log->action), esc_html((string) $log->message)];
        }
        Ui::table([__('Bereich', 'bootspruefung-learning'), 'ID', __('Aktion', 'bootspruefung-learning'), __('Meldung', 'bootspruefung-learning')], $rows, __('Keine Einträge.', 'bootspruefung-learning'));
        Ui::pager($page, count($logs) === 100, fn (int $p) => Ui::url(self::SLUG, ['batch' => $batchId, 'log_action' => $filter, 'log_page' => $p]));
    }

    private function guard(string $nonce): void
    {
        if (! current_user_can(Capabilities::IMPORT_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), 403);
        }
        check_admin_referer($nonce);
    }

    private function redirect(?int $batchId, string $message, string $type = 'success'): never
    {
        Ui::flash($message, $type);
        wp_safe_redirect(Ui::url(self::SLUG, $batchId ? ['batch' => $batchId] : []));
        exit;
    }
}

<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Content\Import\ImportService;
use Bootspruefung\Learning\Content\Import\ImportSources;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Security\Capabilities;
use RuntimeException;

/** Inhaltspakete: Upload, Prüfung, Vorschau, Freigabe, Import, Protokoll. Sichtbare Texte nach Glossary (T100/T126). */
final class ImportPage
{
    public const SLUG = 'bootspruefung-import';

    public function register(): void
    {
        add_action('admin_post_bp_import_upload', [$this, 'handleUpload']);
        add_action('admin_post_bp_import_server', [$this, 'handleServer']);
        add_action('admin_post_bp_import_url', [$this, 'handleUrl']);
        add_action('admin_post_bp_import_action', [$this, 'handleAction']);
        // Nach Menu::enqueue (Priorität 10): deutsche Texte für import.js.
        add_action('admin_enqueue_scripts', [$this, 'scriptTexts'], 20);
    }

    /** Texte und Phasennamen für import.js (Fortschritt, Fehlermeldungen, Größenprüfung vor dem Hochladen). */
    public function scriptTexts(): void
    {
        if (! wp_script_is('bp-admin-import', 'enqueued')) {
            return;
        }
        $phases = [];
        foreach (array_keys(ImportService::PHASES) as $phase) {
            $phases[$phase] = Ui::entityLabel($phase);
        }
        wp_localize_script('bp-admin-import', 'bpImportText', [
            'phases' => $phases,
            'step' => __('Schritt %1$d von %2$d: %3$s – %4$d von %5$d', 'bootspruefung-learning'),
            'done' => __('Import abgeschlossen. Die Seite wird neu geladen …', 'bootspruefung-learning'),
            'network' => __('Keine Verbindung zum Server. Bitte die Internetverbindung prüfen und mit „Import fortsetzen“ weitermachen – bereits importierte Schritte bleiben erhalten.', 'bootspruefung-learning'),
            'timeout' => __('Zeitüberschreitung: Der Server hat zu lange nicht geantwortet. Bitte die Seite neu laden und mit „Import fortsetzen“ weitermachen. Hält das an, per WP-CLI fortsetzen: wp bootspruefung import resume %d', 'bootspruefung-learning'),
            'http' => __('Fehler auf dem Server (HTTP %d). Bitte die Seite neu laden und mit „Import fortsetzen“ weitermachen. Hält das an, das Fehlerprotokoll des Servers prüfen oder per WP-CLI fortsetzen: wp bootspruefung import resume %d', 'bootspruefung-learning'),
            'invalid' => __('Der Server hat keine gültige Antwort geschickt (oft ein Zeitlimit oder PHP-Fehler). Bitte die Seite neu laden und mit „Import fortsetzen“ weitermachen. Hält das an, per WP-CLI fortsetzen: wp bootspruefung import resume %d', 'bootspruefung-learning'),
            'expired' => __('Die Anmeldung ist abgelaufen. Bitte die Seite neu laden (ggf. neu anmelden) und mit „Import fortsetzen“ weitermachen.', 'bootspruefung-learning'),
            'error' => __('Import angehalten: %s', 'bootspruefung-learning'),
            'tooLarge' => ImportSources::tooLargeMessage(),
            'tooLargeSize' => __('Die gewählte Datei hat %s.', 'bootspruefung-learning'),
            'notZip' => __('Bitte eine ZIP-Datei auswählen (Endung .zip).', 'bootspruefung-learning'),
            'uploading' => __('Wird hochgeladen und geprüft … Das kann bei großen Paketen einige Minuten dauern. Bitte die Seite nicht schließen.', 'bootspruefung-learning'),
        ]);
    }

    public function handleUpload(): void
    {
        // PHP verwirft eine Anfrage über post_max_size komplett: $_POST und $_FILES sind leer, das
        // Sicherheitsfeld fehlt. Ohne diese Prüfung käme „Link abgelaufen“ statt eines verständlichen Hinweises.
        // Es wird nichts verändert, nur gemeldet – daher vor der Nonce-Prüfung (Berechtigung wird geprüft).
        if ($_POST === [] && $_FILES === [] && ImportSources::postTooLarge()) {
            if (! current_user_can(Capabilities::IMPORT_CONTENT)) {
                wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), 403);
            }
            $this->redirect(null, ImportSources::tooLargeMessage((int) ($_SERVER['CONTENT_LENGTH'] ?? 0)), 'error');
        }
        $this->guard('bp_import_upload');
        $file = $_FILES['package'] ?? null;
        $code = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (($message = ImportSources::uploadErrorMessage($code, is_array($file) ? (int) ($file['size'] ?? 0) : 0)) !== null) {
            $this->redirect(null, $message, 'error');
        }
        $tmp = (string) $file['tmp_name'];
        if (! is_uploaded_file($tmp)) {
            $this->redirect(null, __('Bitte eine ZIP-Datei auswählen.', 'bootspruefung-learning'), 'error');
        }
        if (! str_ends_with(strtolower((string) $file['name']), '.zip')) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $this->redirect(null, __('Nur ZIP-Dateien sind erlaubt.', 'bootspruefung-learning'), 'error');
        }
        $error = null;
        try {
            $batchId = Plugin::instance()->importService()->createFromZip($tmp);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        // Abgelehnte und übernommene Uploads nicht liegen lassen (das Paket liegt entpackt beim Import).
        if (is_file($tmp)) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        if ($error !== null) {
            $this->redirect(null, self::rejected($error), 'error');
        }
        $this->redirect($batchId, __('Paket hochgeladen und geprüft.', 'bootspruefung-learning'));
    }

    /** Paket aus dem Ablageordner auf dem Server übernehmen (ohne Hochladen im Browser). */
    public function handleServer(): void
    {
        $this->guard('bp_import_server');
        // Name unverändert: sanitize_file_name machte aus „Paket (1).zip“ bzw. „inhalt.final.zip“ einen anderen
        // Namen, die Datei war dann „nicht gefunden“ (T108). resolveDrop prüft den Namen selbst streng.
        $name = trim(wp_unslash((string) ($_POST['file'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- resolveDrop
        try {
            $path = ImportSources::resolveDrop($name);
        } catch (RuntimeException $e) {
            $this->redirect(null, $e->getMessage(), 'error');
        }
        $service = Plugin::instance()->importService();
        $isZip = is_file($path);
        try {
            $batchId = $isZip ? $service->createFromZip($path) : $service->createFromDirectory($path);
        } catch (RuntimeException $e) {
            if ($isZip) {
                ImportSources::removeDropped($path); // abgelehntes Paket entfernen; ein Ordner bleibt zum Nachbessern
            }
            $this->redirect(null, self::rejected($e->getMessage()).($isZip ? ' '.__('Die Datei wurde aus dem Ablageordner gelöscht.', 'bootspruefung-learning') : ''), 'error');
        }
        // Übernommen: das Paket liegt jetzt beim Import – ZIP-Datei bzw. Paketordner aus dem Ablageordner löschen.
        $removed = ImportSources::removeDropped($path);
        $note = match (true) {
            ! $removed => __('Es konnte nicht aus dem Ablageordner gelöscht werden – bitte per SFTP löschen.', 'bootspruefung-learning'),
            $isZip => __('Die ZIP-Datei wurde aus dem Ablageordner gelöscht.', 'bootspruefung-learning'),
            default => __('Der Paketordner wurde aus dem Ablageordner gelöscht.', 'bootspruefung-learning'),
        };
        $this->redirect($batchId, sprintf(__('Paket „%s“ aus dem Ablageordner übernommen und geprüft.', 'bootspruefung-learning'), $name).' '.$note, $removed ? 'success' : 'warning');
    }

    /** Paket über einen https-Link laden (wp_safe_remote_get, nur https-Weiterleitungen, Größenlimit, optional SHA-256). */
    public function handleUrl(): void
    {
        $this->guard('bp_import_url');
        $url = esc_url_raw(trim(wp_unslash((string) ($_POST['url'] ?? ''))), ['https']);
        $sha256 = sanitize_text_field(wp_unslash((string) ($_POST['sha256'] ?? '')));
        $tmp = null;
        $error = null;
        try {
            $tmp = ImportSources::download($url, ImportSources::MAX_DOWNLOAD_BYTES, $sha256);
            $batchId = Plugin::instance()->importService()->createFromZip($tmp);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- temporäre Datei immer entfernen
        }
        if ($error !== null) {
            $this->redirect(null, $tmp === null ? $error : self::rejected($error), 'error');
        }
        $this->redirect($batchId, __('Paket über den Link geladen und geprüft.', 'bootspruefung-learning'));
    }

    private static function rejected(string $reason): string
    {
        return sprintf(__('Paket abgelehnt: %s', 'bootspruefung-learning'), $reason);
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
                    // Abgebrochenen Import verwerfen und den aktiven Stand per Wiederherstellungs-Import zurückholen.
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
                    $message = __('Import verworfen. Ein Import zur Wiederherstellung des aktiven Pakets wurde angelegt: bitte Vorschau erstellen, freigeben und importieren – danach stammen wieder alle Inhalte aus einem Paket.', 'bootspruefung-learning');
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
        echo '<div class="wrap bp-admin"><h1>'.esc_html__('Inhaltspakete', 'bootspruefung-learning').'</h1>';
        Ui::notice();
        $batchId > 0 ? $this->renderBatch($batchId) : $this->renderList();
        echo '</div>';
    }

    private function renderList(): void
    {
        $service = Plugin::instance()->importService();
        $limit = ImportSources::uploadLimit();
        echo '<h2>'.esc_html__('Paket hochladen', 'bootspruefung-learning').'</h2>';
        // action in der Adresse: Verwirft PHP eine zu große Anfrage (post_max_size), kommt sie trotzdem beim Handler an.
        echo '<form method="post" enctype="multipart/form-data" id="bp-import-upload" data-max-bytes="'.(int) $limit.'" action="'.esc_url(add_query_arg('action', 'bp_import_upload', admin_url('admin-post.php'))).'">';
        wp_nonce_field('bp_import_upload');
        echo '<input type="hidden" name="action" value="bp_import_upload"><input type="hidden" name="MAX_FILE_SIZE" value="'.(int) $limit.'">';
        echo '<p><label for="bp-import-file">'.esc_html__('ZIP-Datei des Inhaltspakets', 'bootspruefung-learning').'</label> <input type="file" id="bp-import-file" name="package" accept=".zip,application/zip" required> ';
        submit_button(__('Hochladen und prüfen', 'bootspruefung-learning'), 'primary', 'submit', false);
        echo '</p><div id="bp-import-upload-message" aria-live="polite"></div>';
        echo '<p><strong>'.esc_html(sprintf(__('Höchstgröße zum Hochladen auf diesem Server: %s', 'bootspruefung-learning'), size_format($limit))).'</strong> '
            .'<span class="description">'.esc_html(sprintf(__('(kleinster Wert aus upload_max_filesize %1$s, post_max_size %2$s, WordPress-Grenze %3$s und der Paketgrenze 200 MB)', 'bootspruefung-learning'), (string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size'), size_format((int) wp_max_upload_size()))).'</span></p>';
        echo '<p class="description">'.esc_html__('Die ZIP-Datei des Inhaltspakets enthält manifest.json sowie die Ordner data/ und media/ (das aktuelle Paket hat rund 11 MB). Ist die Datei größer als die Höchstgröße, unten „Ohne Hochladen importieren“ nutzen. Prüfung und Entpacken laufen in dieser Anfrage – bei großen Paketen oder knappen Zeitlimits des Servers besser per WP-CLI: wp bootspruefung import <paket>.', 'bootspruefung-learning').'</p></form>';

        $this->renderAlternatives();

        $aborted = $service->abortedImport();
        if ($aborted !== null) {
            echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('Achtung: Inhalte aus zwei Paketen (Mischstand).', 'bootspruefung-learning').'</strong> '
                .sprintf(esc_html__('Import #%d wurde abgebrochen. Ein Teil der Inhalte stammt schon aus dem neuen Paket, der Rest aus dem bisherigen. Bitte den Import fortsetzen oder verwerfen und den aktiven Stand wiederherstellen.', 'bootspruefung-learning'), $aborted)
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
                self::sourceState((string) $b->source_commit),
                esc_html(Ui::date($b->created_at)),
                esc_html(Ui::date($b->published_at)),
            ];
        }
        Ui::table([__('Import', 'bootspruefung-learning'), __('Paket', 'bootspruefung-learning'), __('Status', 'bootspruefung-learning'), __('Stand', 'bootspruefung-learning'), __('Hochgeladen', 'bootspruefung-learning'), __('Veröffentlicht', 'bootspruefung-learning')], $rows, __('Noch keine Importe.', 'bootspruefung-learning'));
    }

    /** Import ohne Hochladen: Ablageordner auf dem Server, https-Link, WP-CLI. */
    private function renderAlternatives(): void
    {
        echo '<h2 id="bp-import-alternatives">'.esc_html__('Ohne Hochladen importieren', 'bootspruefung-learning').'</h2>';
        echo '<h3>'.esc_html__('Aus dem Ablageordner auf dem Server', 'bootspruefung-learning').'</h3>';
        echo '<p>'.sprintf(
            esc_html__('Die ZIP-Datei (oder den entpackten Paketordner) per SFTP oder Dateimanager des Hosters in den Ordner %s legen und hier auswählen. Der Ordner liegt im geschützten Paketordner und ist für Besucher gesperrt. Nach dem Übernehmen wird die ZIP-Datei bzw. der Paketordner aus dem Ordner gelöscht, eine abgelehnte ZIP-Datei ebenso.', 'bootspruefung-learning'),
            '<code>'.esc_html(ImportSources::dropDirLabel()).'</code>'
        ).'</p>';
        if (($moved = ImportSources::migrationNotice()) !== null) {
            echo '<div class="notice notice-'.esc_attr($moved['type']).' inline"><p>'.esc_html($moved['text']).'</p></div>';
        }
        $items = ImportSources::dropItems();
        if ($items === []) {
            echo '<p class="description">'.esc_html__('Im Ablageordner liegt noch kein Paket. Nach dem Ablegen diese Seite neu laden.', 'bootspruefung-learning').'</p>';
        } else {
            echo '<form method="post" action="'.esc_url(add_query_arg('action', 'bp_import_server', admin_url('admin-post.php'))).'">';
            wp_nonce_field('bp_import_server');
            echo '<input type="hidden" name="action" value="bp_import_server"><fieldset><legend class="screen-reader-text">'.esc_html__('Paket im Ablageordner auswählen', 'bootspruefung-learning').'</legend>';
            foreach ($items as $i => $item) {
                $detail = $item['type'] === 'zip' ? size_format($item['size']) : __('Paketordner', 'bootspruefung-learning');
                printf(
                    '<label style="display:block;margin:.3em 0"><input type="radio" name="file" value="%s"%s> <code>%s</code> – %s, %s</label>',
                    esc_attr($item['name']),
                    checked($i, 0, false),
                    esc_html($item['name']),
                    esc_html($detail),
                    esc_html(wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), $item['modified']))
                );
            }
            echo '</fieldset><p>';
            submit_button(__('Übernehmen und prüfen', 'bootspruefung-learning'), 'secondary', 'submit', false);
            echo '</p></form>';
        }
        echo '<h3>'.esc_html__('Über einen Link', 'bootspruefung-learning').'</h3>';
        echo '<form method="post" action="'.esc_url(add_query_arg('action', 'bp_import_url', admin_url('admin-post.php'))).'">';
        wp_nonce_field('bp_import_url');
        echo '<input type="hidden" name="action" value="bp_import_url"><p><label for="bp-import-url" class="screen-reader-text">'.esc_html__('Link zur ZIP-Datei', 'bootspruefung-learning').'</label>'
            .'<input type="url" id="bp-import-url" name="url" class="regular-text" placeholder="https://…/paket.zip" pattern="https://.+" required> ';
        submit_button(__('Herunterladen und prüfen', 'bootspruefung-learning'), 'secondary', 'submit', false);
        echo '</p><p><label for="bp-import-sha256">'.esc_html__('SHA-256-Prüfsumme (freiwillig)', 'bootspruefung-learning').'</label><br>'
            .'<input type="text" id="bp-import-sha256" name="sha256" class="regular-text code" maxlength="80" pattern="\s*[0-9a-fA-F]{64}\s*" autocomplete="off" spellcheck="false" aria-describedby="bp-import-sha256-hint"></p>'
            .'<p class="description" id="bp-import-sha256-hint">'.esc_html__('Steht beim Paket eine Prüfsumme (64 Zeichen), hier eintragen: Die geladene Datei wird nur übernommen, wenn sie übereinstimmt.', 'bootspruefung-learning').'</p>';
        echo '<p class="description">'.esc_html(sprintf(__('Nur https-Links (auch Weiterleitungen, höchstens drei), höchstens %s. Die Datei wird in einen geschützten Ordner geladen und nach der Prüfung gelöscht.', 'bootspruefung-learning'), size_format(ImportSources::MAX_DOWNLOAD_BYTES))).'</p></form>';
        echo '<h3>'.esc_html__('Per WP-CLI', 'bootspruefung-learning').'</h3><p><code>wp bootspruefung import /pfad/zum/paket.zip</code> '
            .esc_html__('(prüft, zeigt die Vorschau und importiert nach Rückfrage; ohne Zeitlimit des Browsers).', 'bootspruefung-learning').'</p>';
    }

    private function renderBatch(int $batchId): void
    {
        $service = Plugin::instance()->importService();
        try {
            $batch = $service->batch($batchId);
        } catch (RuntimeException $e) {
            // T126: keine Sackgasse – Hinweis mit Weg zurück zur Liste.
            /* translators: %d: Nummer des Imports */
            echo '<div class="notice notice-warning inline"><p>'.esc_html(sprintf(__('Import #%d nicht gefunden. Alte Importe werden nach einiger Zeit aufgeräumt, oder die Nummer ist falsch.', 'bootspruefung-learning'), $batchId)).'</p></div>';
            echo '<p><a class="button" href="'.esc_url(Ui::url(self::SLUG)).'">&larr; '.esc_html__('Zurück zu allen Importen', 'bootspruefung-learning').'</a></p>';

            return;
        }
        $manifest = json_decode((string) $batch->manifest, true) ?: [];
        $stats = $service->stats($batch);
        echo '<p><a href="'.esc_url(Ui::url(self::SLUG)).'">&larr; '.esc_html__('Alle Importe', 'bootspruefung-learning').'</a></p>';
        echo '<h2>'.sprintf(esc_html__('Import #%d', 'bootspruefung-learning'), $batchId).' '.Ui::status($batch->status).'</h2>';

        Ui::keyValue([
            __('Paket', 'bootspruefung-learning') => '<code>'.esc_html($batch->package_id).'</code>',
            __('Quelle', 'bootspruefung-learning') => esc_html((string) ($manifest['source']['repository'] ?? '–')).' · '.esc_html__('Stand', 'bootspruefung-learning').' '.self::sourceState((string) ($manifest['source']['commit_sha'] ?? '')),
            __('Erstellt (Konverter)', 'bootspruefung-learning') => esc_html(($manifest['built_at'] ?? '–').' · v'.($manifest['converter_version'] ?? '?')),
            __('Schema', 'bootspruefung-learning') => esc_html((string) $batch->schema_version),
            __('Hochgeladen', 'bootspruefung-learning') => esc_html(Ui::date($batch->created_at)),
            __('Freigegeben', 'bootspruefung-learning') => esc_html(Ui::date($batch->approved_at).($batch->approved_by ? ' · '.(get_userdata((int) $batch->approved_by)->display_name ?? '') : '')),
            __('Veröffentlicht', 'bootspruefung-learning') => esc_html(Ui::date($batch->published_at)),
        ]);

        $batchStats = $service->stats($batch);
        if (isset($batchStats['rollback_of'])) {
            echo '<div class="notice notice-warning inline"><p>'.sprintf(esc_html__('Wiederherstellung des Stands von Import #%1$d. Begründung: %2$s', 'bootspruefung-learning'), (int) $batchStats['rollback_of'], esc_html((string) ($batchStats['rollback_reason'] ?? ''))).'</p></div>';
        }
        if ($batch->error) {
            echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('Fehler', 'bootspruefung-learning').'</strong></p><pre style="white-space:pre-wrap">'.esc_html((string) $batch->error).'</pre></div>';
        }

        $this->renderState($batch);
        $this->renderActions($batch);

        if (isset($stats['preview'])) {
            echo '<h3>'.esc_html__('Vorschau', 'bootspruefung-learning').'</h3><p class="description">'.esc_html__('Was der Import ändern würde – noch ist nichts geändert.', 'bootspruefung-learning').'</p>';
            $rows = [];
            foreach ($stats['preview'] as $entity => $counts) {
                if ($entity === '_rights') {
                    continue;
                }
                $rows[] = [esc_html(Ui::entityLabel($entity)), (int) $counts['create'], (int) $counts['update'], (int) $counts['unchanged'], (int) $counts['archive']];
            }
            Ui::table([__('Bereich', 'bootspruefung-learning'), self::actionLabel('create'), self::actionLabel('update'), self::actionLabel('unchanged'), __('archivieren', 'bootspruefung-learning')], $rows);
            if (! empty($stats['preview']['_rights'])) {
                echo '<h4>'.esc_html__('Rechte-Status im Paket', 'bootspruefung-learning').'</h4><p class="description">'.sprintf(
                    /* translators: 1: „ungeklärt“, 2: „gesperrt“ (mit internem Wert als Tooltip) */
                    esc_html__('Inhalte mit Rechte-Status %1$s und %2$s werden importiert, aber nicht ausgeliefert, bis sie unter „Rechte“ freigegeben sind.', 'bootspruefung-learning'),
                    '<span title="unknown">„'.esc_html(Ui::label('unknown')).'“</span>',
                    '<span title="restricted">„'.esc_html(Ui::label('restricted')).'“</span>'
                ).'</p>';
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
                $rows[] = [esc_html(Ui::entityLabel($phase)), self::countsText((array) $counts)];
            }
            Ui::table([__('Bereich', 'bootspruefung-learning'), __('Ergebnis', 'bootspruefung-learning')], $rows);
        }

        $warnings = $manifest['warnings'] ?? [];
        $skipped = $manifest['skipped'] ?? [];
        $notConverted = $manifest['not_converted'] ?? [];
        echo '<h3>'.sprintf(esc_html__('Hinweise aus dem Paket (%1$d Warnungen, %2$d übersprungene Quellzeilen)', 'bootspruefung-learning'), count($warnings), count($skipped)).'</h3>';
        $rows = [];
        foreach ($warnings as $w) {
            $rows[] = [self::warningCell((string) ($w['code'] ?? '')), esc_html(Ui::entityLabel((string) ($w['entity'] ?? ''))), '<code>'.esc_html((string) ($w['source_id'] ?? '')).'</code>', esc_html((string) ($w['message'] ?? ''))];
        }
        foreach ($skipped as $s) {
            $rows[] = [self::warningCell('skipped'), esc_html(Ui::entityLabel((string) ($s['entity'] ?? ''))), '<code>'.esc_html((string) ($s['source_id'] ?? '')).'</code>', esc_html(($s['source_file'] ?? '').(! empty($s['line']) ? ':'.$s['line'] : '').' – '.($s['reason'] ?? ''))];
        }
        Ui::table([__('Art', 'bootspruefung-learning'), __('Bereich', 'bootspruefung-learning'), __('Kennung', 'bootspruefung-learning'), __('Meldung', 'bootspruefung-learning')], $rows, __('Keine.', 'bootspruefung-learning'));
        if ($notConverted !== []) {
            echo '<p><strong>'.esc_html__('Noch nicht im Paket:', 'bootspruefung-learning').'</strong> '.esc_html(implode(', ', array_column($notConverted, 'entity'))).'</p>';
        }

        if (in_array($batch->status, ['importing', 'published', 'failed'], true)) {
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
                echo $form('preview', __('Vorschau erstellen', 'bootspruefung-learning'), 'primary');
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
                        echo $form('discard_restore', __('Verwerfen und aktiven Stand wiederherstellen', 'bootspruefung-learning'), 'primary', $restoreNote, ['data-bp-confirm' => __('Abgebrochenen Import verwerfen und einen Import zur Wiederherstellung des aktiven Pakets anlegen?', 'bootspruefung-learning')]);
                    }
                    break;
                }
                printf(
                    '<button type="button" class="button button-primary" id="bp-import-run" data-batch="%d">%s</button> <progress id="bp-import-progress" max="100" value="0" aria-label="%s" style="width:300px;vertical-align:middle"></progress> <span id="bp-import-status" aria-live="polite"></span>',
                    (int) $batch->id,
                    esc_html($batch->status === 'importing' ? __('Import fortsetzen', 'bootspruefung-learning') : __('Import starten', 'bootspruefung-learning')),
                    esc_attr__('Fortschritt des Imports', 'bootspruefung-learning')
                );
                // Abgebrochener Import: fortsetzen oder verwerfen (blockiert sonst keine weiteren Importe).
                if ($aborted) {
                    echo '<p>';
                    if ($service->activeBatchId() !== null) {
                        echo $form('discard_restore', __('Verwerfen und aktiven Stand wiederherstellen', 'bootspruefung-learning'), 'secondary', $restoreNote, ['data-bp-confirm' => __('Abgebrochenen Import verwerfen und einen Import zur Wiederherstellung des aktiven Pakets anlegen?', 'bootspruefung-learning')]);
                    }
                    echo $form('discard', __('Nur verwerfen (Mischstand bleibt)', 'bootspruefung-learning'), 'delete');
                    echo '</p><p class="description">'.esc_html__('„Verwerfen und wiederherstellen“ legt einen Import zur Wiederherstellung des aktiven Pakets an; nach Vorschau und Freigabe sind alle Inhalte wieder auf diesem Stand. Per WP-CLI: wp bootspruefung import resume|discard <ID>.', 'bootspruefung-learning').'</p>';
                } elseif ($batch->status === 'approved') {
                    echo ' '.$form('discard', __('Verwerfen', 'bootspruefung-learning'), 'delete');
                }
                break;
            case 'published':
                if ($batch->package_id === get_option('bp_learning_active_package') && $service->abortedImport() !== null && $service->activeBatchId() === (int) $batch->id) {
                    echo $form('rollback', __('Auf diesen Stand zurücksetzen (Mischstand beseitigen)', 'bootspruefung-learning'), 'primary', '<label>'.esc_html__('Begründung (Pflicht)', 'bootspruefung-learning').' <input type="text" name="note" class="regular-text" required value="'.esc_attr__('Wiederherstellung nach abgebrochenem Import', 'bootspruefung-learning').'"></label> ');
                } elseif ($batch->package_id !== get_option('bp_learning_active_package')) {
                    echo '<p class="description">'.esc_html__('Dieser Stand ist nicht mehr aktiv. Zurücksetzen legt einen neuen Import aus diesem Paket an, der wie jeder Import geprüft, als Vorschau angezeigt und freigegeben wird. Die früheren Fassungen der Fragen werden wieder aktiv, nichts wird gelöscht.', 'bootspruefung-learning').'</p>';
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
        foreach (['' => __('alle', 'bootspruefung-learning')] + array_combine(['create', 'update', 'unchanged', 'archive', 'error', 'rejected'], array_map([self::class, 'actionLabel'], ['create', 'update', 'unchanged', 'archive', 'error', 'rejected'])) as $value => $label) {
            printf('<a class="button%s" href="%s">%s</a> ', $filter === $value ? ' button-primary' : '', esc_url(Ui::url(self::SLUG, ['batch' => $batchId, 'log_action' => $value])), esc_html($label));
        }
        echo '</p>';
        $rows = [];
        foreach ($logs as $log) {
            $rows[] = [esc_html(Ui::entityLabel($log->entity)), '<code>'.esc_html((string) $log->source_id).'</code>', esc_html(self::actionLabel((string) $log->action)), esc_html((string) $log->message)];
        }
        Ui::table([__('Bereich', 'bootspruefung-learning'), __('Kennung', 'bootspruefung-learning'), __('Aktion', 'bootspruefung-learning'), __('Meldung', 'bootspruefung-learning')], $rows, __('Keine Einträge.', 'bootspruefung-learning'));
        Ui::pager($page, count($logs) === 100, fn (int $p) => Ui::url(self::SLUG, ['batch' => $batchId, 'log_action' => $filter, 'log_page' => $p]));
    }

    /** Aktion eines Imports auf Deutsch (Ergebnis, Protokoll; wie renderLog). */
    public static function actionLabel(string $action): string
    {
        $labels = [
            'create' => __('neu', 'bootspruefung-learning'), 'update' => __('geändert', 'bootspruefung-learning'), 'unchanged' => __('unverändert', 'bootspruefung-learning'),
            'archive' => __('archiviert', 'bootspruefung-learning'), 'skipped' => __('übersprungen', 'bootspruefung-learning'), 'skip' => __('übersprungen', 'bootspruefung-learning'),
            'error' => __('Fehler', 'bootspruefung-learning'), 'rejected' => __('abgelehnt', 'bootspruefung-learning'),
        ];

        return $labels[$action] ?? $action;
    }

    /** Zählung je Aktion als Text, z. B. „neu: 3, unverändert: 120“ (T121). @param array<string,mixed> $counts */
    public static function countsText(array $counts): string
    {
        $parts = [];
        foreach ($counts as $action => $n) {
            $parts[] = '<span title="'.esc_attr((string) $action).'">'.esc_html(self::actionLabel((string) $action)).'</span>: '.(int) $n;
        }

        return implode(', ', $parts);
    }

    /** Warnungscode aus dem Paket lesbar; der Code steht im Tooltip (T121). */
    public static function warningLabel(string $code): string
    {
        $labels = [
            'skipped' => __('Quellzeile übersprungen', 'bootspruefung-learning'),
            'source_removed' => __('In der Quelle entfernt (wird archiviert)', 'bootspruefung-learning'),
            'image_missing' => __('Pflichtbild fehlt', 'bootspruefung-learning'),
            'image_unassigned' => __('Bild ohne veröffentlichte Frage', 'bootspruefung-learning'),
            'praxis_image_missing' => __('Bild des Praxistrainers fehlt', 'bootspruefung-learning'),
            'praxis_elwis_missing' => __('ELWIS-Bild fehlt', 'bootspruefung-learning'),
            'chart_task_without_solution' => __('Kartenaufgabe ohne Lösung', 'bootspruefung-learning'),
            'exam_rule_unverified' => __('Regelwerk nicht geprüft (Übungsmodus)', 'bootspruefung-learning'),
            'combo_rule_mismatch' => __('Regelwerk des Kombikurses weicht ab', 'bootspruefung-learning'),
            'exam_papers_skipped' => __('Prüfungsbögen übersprungen', 'bootspruefung-learning'),
            'ubi_erg_missing' => __('UBI-Ergänzung übersprungen', 'bootspruefung-learning'),
            'video_chapter_missing' => __('Videokapitel fehlt', 'bootspruefung-learning'),
            'video_placeholder' => __('Platzhaltervideo', 'bootspruefung-learning'),
            'video_quiz_unresolved' => __('Quizfrage im Videokurs nicht zuzuordnen', 'bootspruefung-learning'),
            'video_url_unknown_lesson' => __('Videolink zu unbekannter Lektion', 'bootspruefung-learning'),
            'vocabulary_duplicate_key' => __('Doppelte Funk-Vokabel', 'bootspruefung-learning'),
        ];

        return $labels[$code] ?? __('Hinweis', 'bootspruefung-learning');
    }

    private static function warningCell(string $code): string
    {
        return '<span title="'.esc_attr($code).'">'.esc_html(self::warningLabel($code)).'</span>';
    }

    /** Stand der Quelle: Kurzform, die vollständige Kennung als Tooltip (statt Spalte „Commit“). */
    private static function sourceState(string $sha): string
    {
        $sha = trim($sha);

        return $sha === '' ? '–' : '<span title="'.esc_attr($sha).'"><code>'.esc_html(substr($sha, 0, 7)).'</code></span>';
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

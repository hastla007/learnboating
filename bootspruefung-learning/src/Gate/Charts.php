<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Gate;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Seekarten (BSH) für die Navigationsaufgaben. Die Datei wird im Admin hochgeladen und
 * als geschützte Datei abgelegt; Lernende sehen sie erst, wenn die BSH-Lizenz bestätigt
 * ist und sie den Navigationstrainer eines passenden Kurses nutzen dürfen. Bis dahin
 * zeigt der Trainer einen Hinweis (Papierkarte verwenden).
 */
final class Charts
{
    public const OPTION = 'bp_learning_charts';
    public const LICENSE_REASON = 'BSH-Lizenz';

    /** @return array<string,array{title:string,courses:list<string>}> */
    public static function catalog(): array
    {
        return [
            'D49' => ['title' => __('Übungskarte D49 (BSH)', 'bootspruefung-learning'), 'courses' => ['SBF-SEE', 'SBF-SEE-BIN', 'ADD-BIN-TO-SEE']],
        ];
    }

    public function register(): void
    {
        add_filter('bootspruefung_protected_file_allowed', [self::class, 'allowFile'], 10, 3);
    }

    /** @return array{attachment_id:int,licensed:bool,note:string,updated_at:string}|null */
    public static function state(string $code): ?array
    {
        $all = get_option(self::OPTION, []);
        $s = is_array($all) ? ($all[$code] ?? null) : null;

        return is_array($s) ? ['attachment_id' => (int) ($s['attachment_id'] ?? 0), 'licensed' => ! empty($s['licensed']), 'note' => (string) ($s['note'] ?? ''), 'updated_at' => (string) ($s['updated_at'] ?? '')] : null;
    }

    /** @param array<string,mixed>|null $state */
    private static function save(string $code, ?array $state): void
    {
        $all = get_option(self::OPTION, []);
        $all = is_array($all) ? $all : [];
        if ($state === null) {
            unset($all[$code]);
        } else {
            $all[$code] = $state + ['updated_at' => current_time('mysql', true)];
        }
        update_option(self::OPTION, $all, false);
    }

    /** Hochgeladene Datei (Anhang) als Karte hinterlegen und schützen. */
    public static function attach(string $code, int $attachmentId): void
    {
        $catalog = self::catalog();
        if (! isset($catalog[$code]) || get_post_type($attachmentId) !== 'attachment') {
            throw new \InvalidArgumentException('Unbekannte Karte oder Datei.');
        }
        $old = self::state($code);
        if ($old && $old['attachment_id'] > 0 && $old['attachment_id'] !== $attachmentId) {
            wp_delete_attachment($old['attachment_id'], true);
        }
        ProtectedFiles::protect($attachmentId, $catalog[$code]['courses'][0], AccessService::NAVIGATION);
        update_post_meta($attachmentId, '_bp_chart', $code);
        self::save($code, ['attachment_id' => $attachmentId, 'licensed' => $old['licensed'] ?? false, 'note' => $old['note'] ?? '']);
        AuditLog::record('chart_uploaded', 'attachment', (string) $attachmentId, null, ['chart' => $code]);
    }

    public static function setLicense(string $code, bool $licensed, string $note): void
    {
        $s = self::state($code) ?? ['attachment_id' => 0, 'licensed' => false, 'note' => ''];
        if ($licensed && trim($note) === '') {
            throw new \InvalidArgumentException(__('Bitte Lizenznachweis angeben (z. B. Vertragsnummer/Datum).', 'bootspruefung-learning'));
        }
        self::save($code, ['attachment_id' => $s['attachment_id'], 'licensed' => $licensed, 'note' => trim($note)]);
        AuditLog::record($licensed ? 'chart_licensed' : 'chart_unlicensed', 'chart', $code, trim($note) ?: null);
    }

    public static function remove(string $code): void
    {
        $s = self::state($code);
        if ($s && $s['attachment_id'] > 0) {
            wp_delete_attachment($s['attachment_id'], true);
        }
        self::save($code, $s ? ['attachment_id' => 0, 'licensed' => $s['licensed'], 'note' => $s['note']] : null);
        AuditLog::record('chart_removed', 'chart', $code);
    }

    /** Für Lernende sichtbar? (Datei vorhanden und Lizenz bestätigt) */
    public static function available(string $code): bool
    {
        $s = self::state($code);

        return $s !== null && $s['licensed'] && $s['attachment_id'] > 0 && get_post_type($s['attachment_id']) === 'attachment';
    }

    /**
     * Karten eines Kurses für den Navigationstrainer.
     *
     * @return list<array{code:string,title:string,url:?string}>
     */
    public static function forCourse(int $userId, string $courseCode): array
    {
        $out = [];
        foreach (self::catalog() as $code => $def) {
            if (! in_array($courseCode, $def['courses'], true)) {
                continue;
            }
            $url = null;
            if (self::available($code) && (new AccessService())->can($userId, $courseCode, AccessService::NAVIGATION)) {
                $url = ProtectedFiles::downloadUrl((int) self::state($code)['attachment_id']);
            }
            $out[] = ['code' => $code, 'title' => $def['title'], 'url' => $url];
        }

        return $out;
    }

    /** Auslieferung: Lizenz bestätigt und Navigationstrainer in irgendeinem Kurs der Karte. */
    public static function allowFile(bool $allowed, int $userId, int $attachmentId): bool
    {
        $code = (string) get_post_meta($attachmentId, '_bp_chart', true);
        if ($code === '' || ! isset(self::catalog()[$code])) {
            return $allowed;
        }
        if (! self::available($code)) {
            return false;
        }
        $access = new AccessService();
        foreach (self::catalog()[$code]['courses'] as $course) {
            if ($access->can($userId, $course, AccessService::NAVIGATION)) {
                return true;
            }
        }

        return false;
    }
}

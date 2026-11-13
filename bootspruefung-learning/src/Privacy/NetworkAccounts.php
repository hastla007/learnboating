<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Privacy;

/**
 * Konten im Multisite-Netzwerk (T112): Mitgliedschaften, Löschen aus dem Netzwerk bzw. nur aus dieser Website und
 * Websites mit Lernplattform-Tabellen. Gekapselt, damit Tests die Multisite-Zweige ohne Multisite-Installation
 * prüfen können (AccountDeletion::useNetwork() mit einem Test-Double); im Betrieb immer diese Klasse.
 */
class NetworkAccounts
{
    public function isMultisite(): bool
    {
        return is_multisite();
    }

    public function currentSite(): int
    {
        return (int) get_current_blog_id();
    }

    /** Websites, deren Mitglied das Konto ist (auch archivierte/gesperrte). @return list<int> */
    public function sitesOf(int $userId): array
    {
        return array_values(array_map('intval', array_keys((array) get_blogs_of_user($userId, true))));
    }

    /** Konto netzweit löschen (wpmu_delete_user; löst deleted_user aus). */
    public function deleteFromNetwork(int $userId): bool
    {
        require_once ABSPATH.'wp-admin/includes/user.php';
        require_once ABSPATH.'wp-admin/includes/ms.php';

        return (bool) wpmu_delete_user($userId);
    }

    /** Konto aus dieser Website entfernen (Single-Site: Konto löschen; Multisite: Rolle hier entfernen). */
    public function deleteFromSite(int $userId): bool
    {
        require_once ABSPATH.'wp-admin/includes/user.php';

        return (bool) wp_delete_user($userId);
    }

    /** Gibt es das Konto noch (direkt aus der Datenbank, ohne Objekt-Cache)? */
    public function userExists(int $userId): bool
    {
        global $wpdb;

        return $userId > 0 && (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID = %d", $userId));
    }

    /** Websites des Netzwerks mit Lernplattform-Tabellen (bp_attempt vorhanden). @return list<int> */
    public function sitesWithTables(): array
    {
        if (! $this->isMultisite() || ! function_exists('get_sites')) {
            return [$this->currentSite()];
        }
        global $wpdb;
        $out = [];
        foreach ((array) get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            $prefix = $wpdb->get_blog_prefix((int) $siteId);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix.'bp_attempt'))) !== null) {
                $out[] = (int) $siteId;
            }
        }

        return $out;
    }

    /**
     * $fn im Kontext einer Website ausführen (switch_to_blog, danach zurück).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function inSite(int $siteId, callable $fn): mixed
    {
        if (! $this->isMultisite() || $siteId === $this->currentSite()) {
            return $fn();
        }
        switch_to_blog($siteId);
        try {
            return $fn();
        } finally {
            restore_current_blog();
        }
    }
}

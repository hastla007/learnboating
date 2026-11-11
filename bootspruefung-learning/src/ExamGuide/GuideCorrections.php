<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\ExamGuide;

/**
 * Redaktionelle Korrekturen an den Anleitungen der Quelle, angewendet beim Ausliefern
 * (das Content-Paket bleibt unverändert und quellgetreu). Stand der Recherche: 06.11.2026.
 *
 * - Gebühren: SBF Binnen und FKN in der Quelle gerundet; belegte Beträge eingesetzt
 *   (SBF Binnen 130,78 € u. a. sportbootfuehrerschein.de, nautigo-bootsschule.de;
 *   FKN 28,89 € DMYV-Prüfungszentrum NRW, sportbootfuehrerscheine.org).
 *   Bestätigt unverändert: SRC 127,88 €, UBI 108,71 €, SBF See 148,38 €, SKN 48 € (PA Nord).
 * - Bootsschul-Bezug der Quelle („nicht an die Bootsschule“) passt nicht zu dieser Plattform.
 * - Alle Gebühren als Richtwert mit Stand und Hinweis auf den Prüfungsausschuss.
 * - Unterlagen SBF: Ein anderer Sportbootführerschein ersetzt das ärztliche Zeugnis nur, wenn er durch Prüfung
 *   erworben und bei Antragstellung nicht älter als ein Jahr ist (Zulassungsantrag; wie die Inhaltsseite „Attest“).
 */
final class GuideCorrections
{
    public const AS_OF = 'Stand November 2026';

    /** Quelltext => Ersatz, je Anleitung (source_id) oder '*' für alle */
    private const FEES = [
        'SBF-BIN' => ['130 €' => '130,78 €'],
        'FKN' => ['29 €' => '28,89 €'],
    ];

    private const TEXT = [
        ', nicht an die Bootsschule.' => ' – nicht über diese Lernplattform.',
        'Falls vorhanden: Kopie eines anderen Sportbootführerscheins (ersetzt ggf. das ärztliche Zeugnis)'
            => 'Falls vorhanden: Kopie eines durch Prüfung erworbenen Sportbootführerscheins – ist er bei Antragstellung nicht älter als ein Jahr, ersetzt er das ärztliche Zeugnis',
    ];

    /**
     * @param array<string,mixed> $guide
     * @return array<string,mixed>
     */
    public static function apply(array $guide): array
    {
        $key = (string) ($guide['key'] ?? $guide['source_id'] ?? '');
        $fees = self::FEES[$key] ?? [];
        if (isset($guide['fee']) && is_string($guide['fee']) && isset($fees[$guide['fee']])) {
            $guide['fee'] = $fees[$guide['fee']];
        }
        $replace = static function (string $text) use ($fees): string {
            foreach ($fees as $from => $to) {
                $text = (string) preg_replace('/(?<![\d,])'.preg_quote($from, '/').'/u', $to, $text);
            }

            return strtr($text, self::TEXT);
        };
        foreach (['intro', 'apply', 'deadline', 'lead_time'] as $field) {
            if (isset($guide[$field]) && is_string($guide[$field])) {
                $guide[$field] = $replace($guide[$field]);
            }
        }
        foreach ((array) ($guide['documents'] ?? []) as $i => $doc) {
            if (is_string($doc)) {
                $guide['documents'][$i] = $replace($doc);
            }
        }
        foreach ($guide['faq'] ?? [] as $i => $faq) {
            $guide['faq'][$i]['answer'] = $replace((string) ($faq['answer'] ?? ''));
            $guide['faq'][$i]['question'] = $replace((string) ($faq['question'] ?? ''));
        }
        $guide['fee_note'] = self::AS_OF.'; Richtwert – maßgeblich ist der Betrag deines Prüfungsausschusses.';

        return $guide;
    }

    /**
     * Kurse eines Prüfungsausschusses ableiten, wenn in der Quelle keine gepflegt sind:
     * Einträge mit eigener Funk-Anschrift bzw. eigener Stelle für SBF/FKN (laut DMYV-Liste).
     * Leere Liste = allgemeine Geschäftsstelle für alle Prüfungen.
     *
     * @return list<string>
     */
    public static function committeeCourses(?string $locationType): array
    {
        $type = (string) $locationType;
        if (preg_match('/Funk|SRC|UBI|LRC/u', $type)) {
            return ['SRC', 'UBI', 'SRC-UBI', 'ADD-UBI-TO-SRC'];
        }
        if (preg_match('/SBF|FKN/u', $type)) {
            return ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-BIN-SEGEL', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'ADD-BIN-SEGEL', 'FKN', 'ADD-FKN-TO-SKN'];
        }

        return [];
    }
}

<?php
/**
 * Nur für die statische Analyse (PHPStan): Platzhalter für Klassen und Funktionen aus Plugins, die nicht in diesem
 * Repository liegen. Abgeleitet allein aus der Nutzung in den Connectoren bzw. im Lern-Plugin – die Typen sind bewusst
 * weit gefasst (mixed, Arrays), damit sie keine echten Fehler verdecken. Wird nie geladen.
 *
 * - Voucher Captain (Kursgutschein-Connector): VCAP\Voucher, VCAP\API\V1\RedemptionService, vcap()
 * - WP Affiliate Captain (Partnerprogramm-Connector): wpac_contract_version(), wpac_resolve_partner_code()
 * - WooCommerce intern (nicht in php-stubs/woocommerce-stubs): FeaturesController
 *
 * phpcs:ignoreFile
 */

namespace VCAP {
    /** Gutschein aus Voucher Captain (genutzte Felder und Methoden). */
    class Voucher
    {
        /** @var int */
        public $id = 0;
        /** @var string */
        public $code = '';
        /** @var string|null */
        public $expires_at = null;
        /** @var int */
        public $order_item_id = 0;

        /** @return mixed */
        public function product()
        {
            return null;
        }

        public function is_multi_use(): bool
        {
            return false;
        }
    }
}

namespace VCAP\API\V1 {
    /** Einlöse-API von Voucher Captain (genutzte Konstanten und Methoden). */
    class RedemptionService
    {
        public const STATE_RESERVED = 'reserved';
        public const STATE_COMMITTED = 'committed';
        public const STATE_RELEASED = 'released';

        /** @param array<string,mixed> $context @return array<string,mixed>|\WP_Error */
        public function check(string $code, array $context = [])
        {
            return [];
        }

        /** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
        public function reserve(string $code, array $args)
        {
            return [];
        }

        /** @return array<string,mixed>|\WP_Error */
        public function commit(string $key)
        {
            return [];
        }

        /** @return array<string,mixed>|\WP_Error */
        public function release(string $key)
        {
            return [];
        }

        /** @return array<string,mixed>|\WP_Error|null */
        public function get(string $key)
        {
            return null;
        }
    }
}

namespace LearnBoatingStubs {
    /** Gutschein-Verwaltung hinter vcap()->vouchers (Name der echten Klasse unbekannt). */
    class VcapVouchers
    {
        public function get(int $id): ?\VCAP\Voucher
        {
            return null;
        }

        public function get_by_code(string $code): ?\VCAP\Voucher
        {
            return null;
        }

        /** @return mixed */
        public function log(int $voucherId, string $event, mixed $orderId = null, mixed $userId = null, string $note = '')
        {
            return null;
        }
    }

    /** Rückgabe von vcap() (Name der echten Klasse unbekannt). */
    class VcapContainer
    {
        /** @var VcapVouchers */
        public $vouchers;
        /** @var \VCAP\API\V1\RedemptionService */
        public $redemptions;
    }
}

namespace Automattic\WooCommerce\Internal\Features {
    /** WooCommerce intern: Funktionen unter „Erweitert › Funktionen“ ein- und ausschalten. */
    class FeaturesController
    {
        public function change_feature_enable(string $featureId, bool $enable): bool
        {
            return true;
        }
    }
}

namespace {
    function vcap(): \LearnBoatingStubs\VcapContainer
    {
        return new \LearnBoatingStubs\VcapContainer();
    }

    function wpac_contract_version(): string
    {
        return '1.0.0';
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    function wpac_resolve_partner_code(string $code, array $context = []): ?array
    {
        return null;
    }
}

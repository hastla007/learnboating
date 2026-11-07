#!/bin/sh
# Richtet die Staging-Installation ein (läuft im WP-CLI-Container, wiederholbar).
set -eu
cd /var/www/html

log() { printf '\n==> %s\n' "$*"; }

log "Warte auf die WordPress-Dateien"
i=0
until [ -f wp-config.php ] && [ -f wp-includes/version.php ]; do
    i=$((i + 1))
    [ "$i" -gt 150 ] && { echo "WordPress-Dateien fehlen – läuft der Container „wordpress“?" >&2; exit 1; }
    sleep 2
done

URL="${STAGING_URL%/}"
if ! wp core is-installed 2>/dev/null; then
    log "WordPress installieren ($URL)"
    wp core install --url="$URL" --title="$STAGING_TITLE" --admin_user="$STAGING_ADMIN_USER" \
        --admin_password="$STAGING_ADMIN_PASSWORD" --admin_email="$STAGING_ADMIN_EMAIL" --skip-email
    :
else
    :
fi
wp option update siteurl "$URL" >/dev/null
wp option update home "$URL" >/dev/null
wp option update blog_public 0 >/dev/null # Suchmaschinen fernhalten
wp option update timezone_string Europe/Berlin >/dev/null
wp option update date_format 'd.m.Y' >/dev/null
wp language core install de_DE --activate >/dev/null 2>&1 || echo "Hinweis: deutsches Sprachpaket nicht geladen (kein Netz?) – weiter."
wp rewrite structure '/%postname%/' >/dev/null

log "WooCommerce $WC_VERSION"
if [ "$(wp plugin get woocommerce --field=version 2>/dev/null || true)" != "$WC_VERSION" ]; then
    # Ohne Internet im Container: Zip vorher nach staging/woocommerce-<Version>.zip legen.
    ZIP="/repo/staging/woocommerce-${WC_VERSION}.zip"
    [ -f "$ZIP" ] || ZIP="https://github.com/woocommerce/woocommerce/releases/download/${WC_VERSION}/woocommerce.zip"
    wp plugin install "$ZIP" --force
fi
wp plugin activate woocommerce
# Installation von WooCommerce gleich hier abschließen (sonst setzt sie beim ersten Seitenaufruf
# die Shop-Einstellungen auf US-Standardwerte zurück).
wp eval 'if (class_exists("WC_Install")) { WC_Install::install(); }' >/dev/null
# WooCommerce schaltet neue Shops auf „Coming soon“ – für die Abnahme sichtbar machen.
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_store_pages_only no >/dev/null
# Keine Herkunfts-Cookies (sbjs_*) ohne Einwilligung
wp option update woocommerce_feature_order_attribution_enabled no >/dev/null
# Registrierung im Konto/Checkout mit eigenem Passwort (Abnahme „Registrieren“)
wp option update woocommerce_enable_myaccount_registration yes >/dev/null
wp option update woocommerce_enable_signup_and_login_from_checkout yes >/dev/null
wp option update woocommerce_registration_generate_password no >/dev/null
if [ "$(wp option get bp_staging_shop_configured 2>/dev/null || true)" != 1 ]; then
    wp option update woocommerce_currency EUR >/dev/null
    wp option update woocommerce_currency_pos right_space >/dev/null
    wp option update woocommerce_price_thousand_sep . >/dev/null
    wp option update woocommerce_price_decimal_sep , >/dev/null
    wp option update woocommerce_default_country DE:SH >/dev/null
    wp option update woocommerce_enable_guest_checkout yes >/dev/null # das Plugin verlangt trotzdem ein Konto
    # Testzahlart für die Abnahme: Überweisung (keine echten Zahlungen)
    wp option update woocommerce_bacs_settings '{"enabled":"yes","title":"Überweisung (Staging)"}' --format=json >/dev/null
    wp option update bp_staging_shop_configured 1 >/dev/null
fi

log "Plugin und Theme"
wp plugin activate bootspruefung-learning
wp theme activate kurswasser
wp bootspruefung migrate

log "Content-Paket bauen und importieren"
rm -rf /tmp/bp-build
php /repo/tools/content-build/bin/build-package.php --out=/tmp/bp-build --no-zip
PKG=$(ls -d /tmp/bp-build/boatingschool-*/ | head -n 1)
wp bootspruefung import "$PKG" --yes --note="Staging $(date +%F)"

if [ "${STAGING_RELEASE_RIGHTS:-1}" = 1 ]; then
    log "Inhalte für die Abnahme freigeben"
    wp bootspruefung rights release --exam-rules --reason="Staging-Abnahme"
fi

log "Seiten, Shop-Katalog und Zugangsregeln"
wp eval 'kurswasser_create_pages(); kurswasser_create_course_pages();'
# Beim ersten Lauf: Lerninhalte nur mit Kauf (wie im Livebetrieb) und Produkte veröffentlichen.
# Spätere Läufe lassen diese Admin-Einstellungen und den Veröffentlichungsstatus in Ruhe.
if [ "$(wp option get bp_staging_initialized 2>/dev/null || true)" != 1 ]; then
    wp eval '$o = \Bootspruefung\Learning\Settings\Settings::OPTION; update_option($o, array_merge(\Bootspruefung\Learning\Settings\Settings::all(), ["default_access" => "entitled_only"]));'
    wp bootspruefung shop setup --publish
    wp option update bp_staging_initialized 1 >/dev/null
else
    # Preise/Texte/Zuordnungen abgleichen (Preise aus Admin → Bootsprüfung → Preise)
    wp bootspruefung shop setup
fi
wp rewrite flush >/dev/null
wp option update woocommerce_coming_soon no >/dev/null

if [ -n "${STAGING_DEMO_PASSWORD:-}" ]; then
    log "Demo-Lernkonto demo_lernende"
    if wp user get demo_lernende >/dev/null 2>&1; then
        wp user update demo_lernende --user_pass="$STAGING_DEMO_PASSWORD" --skip-email >/dev/null
    else
        wp user create demo_lernende demo_lernende@example.invalid --role=subscriber --display_name="Demo Lernende" --user_pass="$STAGING_DEMO_PASSWORD" >/dev/null
    fi
fi

log "Fertig"
wp bootspruefung status || true
printf '\nStaging bereit: %s  (Admin: %s/wp-admin/, Lernbereich: %s/bootsscheine/)\n' "$URL" "$URL" "$URL"

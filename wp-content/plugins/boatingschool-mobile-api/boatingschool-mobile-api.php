<?php
/**
 * Plugin Name: BoatingSchool Mobile API
 * Description: WordPress REST API and content model for the BoatingSchool learner app.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: boatingschool-mobile-api
 */

defined( 'ABSPATH' ) || exit;

final class BoatingSchool_Mobile_API {
    const NS = 'boatingschool/v1';
    const SESSION_TABLE = 'bsm_sessions';
    const RECORDS_META = '_bsm_mobile_records';
    const ACCESS_TTL = 900;
    const REFRESH_TTL = 2592000;
    const SESSION_MAX_TTL = 7776000;
    private static $session = null;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'content_types' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save_content_meta' ), 10, 2 );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer' ), 30 );
        add_filter( 'boatingschool_mobile_course_post_types', function () { return array( 'bsm_course', 'sfwd-courses', 'course' ); } );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'template_redirect', array( __CLASS__, 'verify_email_link' ) );
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::SESSION_TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            access_hash char(64) NOT NULL,
            refresh_hash char(64) NOT NULL,
            device_name varchar(190) NOT NULL DEFAULT '',
            device_platform varchar(20) NULL,
            created_at datetime NOT NULL,
            last_used_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
            access_expires datetime NOT NULL,
            refresh_expires datetime NOT NULL,
            absolute_expires datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
            revoked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY access_hash (access_hash),
            UNIQUE KEY refresh_hash (refresh_hash),
            KEY user_id (user_id)
        ) {$charset};" );
        if ( ! get_option( 'bsm_tenant_slug' ) ) update_option( 'bsm_tenant_slug', sanitize_title( get_bloginfo( 'name' ) ) ?: 'default' );
        if ( ! get_option( 'bsm_shop_url' ) ) add_option( 'bsm_shop_url', '' );
        self::content_types();
        flush_rewrite_rules();
    }

    public static function deactivate() { flush_rewrite_rules(); }

    public static function content_types() {
        $types = array(
            'bsm_course' => array( 'Kurse', 'Kurs', true ),
            'bsm_module' => array( 'Module', 'Modul', false ),
            'bsm_question' => array( 'Fragen', 'Frage', false ),
            'bsm_lesson' => array( 'Video-Lektionen', 'Video-Lektion', false ),
            'bsm_navigation_task' => array( 'Navigationsaufgaben', 'Navigationsaufgabe', false ),
        );
        foreach ( $types as $slug => $def ) {
            if ( post_type_exists( $slug ) ) continue;
            register_post_type( $slug, array(
                'labels' => array( 'name' => $def[0], 'singular_name' => $def[1], 'add_new_item' => $def[1] . ' hinzufügen', 'edit_item' => $def[1] . ' bearbeiten' ),
                'public' => false, 'show_ui' => true, 'show_in_rest' => false,
                'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
                'capability_type' => 'post', 'map_meta_cap' => true,
                'menu_icon' => $def[2] ? 'dashicons-welcome-learn-more' : 'dashicons-editor-help',
            ) );
        }
    }

    public static function meta_boxes() {
        foreach ( array( 'bsm_course', 'sfwd-courses', 'course' ) as $type ) if ( post_type_exists( $type ) ) add_meta_box( 'bsm_course_settings', 'BoatingSchool API', array( __CLASS__, 'course_box' ), $type, 'normal', 'default' );
        foreach ( array( 'bsm_module', 'bsm_question', 'bsm_lesson', 'bsm_navigation_task' ) as $type ) add_meta_box( 'bsm_item_settings', 'BoatingSchool API-Daten', array( __CLASS__, 'item_box' ), $type, 'normal', 'default' );
    }

    private static function field( $post, $key, $label, $type = 'text', $help = '' ) {
        $value = get_post_meta( $post->ID, '_bsm_' . $key, true );
        echo '<p><label><strong>' . esc_html( $label ) . '</strong><br>';
        if ( 'textarea' === $type ) echo '<textarea style="width:100%;min-height:90px" name="bsm_meta[' . esc_attr( $key ) . ']">' . esc_textarea( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) . '</textarea>';
        else echo '<input style="width:100%" type="' . esc_attr( $type ) . '" name="bsm_meta[' . esc_attr( $key ) . ']" value="' . esc_attr( is_scalar( $value ) ? $value : '' ) . '">';
        if ( $help ) echo '<small>' . esc_html( $help ) . '</small>';
        echo '</label></p>';
    }

    public static function course_box( $post ) {
        wp_nonce_field( 'bsm_save_meta', 'bsm_nonce' );
        self::field( $post, 'tenant', 'Tenant-Slug (leer = Standard-Tenant)' );
        self::field( $post, 'access_mode', 'Zugang', 'text', 'free oder coupon (Standard: coupon). LearnDash-Zugriff wird zusätzlich erkannt.' );
        self::field( $post, 'shop_url', 'Externe Shop-URL', 'url' );
        self::field( $post, 'exam_rules', 'Prüfungsregeln (JSON)', 'textarea', 'Beispiel: [{"id":"standard","title":"Standardprüfung","question_count":30,"duration_minutes":30,"pass_percent":80}]' );
        self::field( $post, 'exam_question_count', 'Standard-Fragenzahl', 'number' );
        self::field( $post, 'exam_duration', 'Prüfungsdauer in Minuten', 'number' );
        self::field( $post, 'exam_pass_percent', 'Bestehensgrenze in Prozent', 'number' );
    }

    public static function item_box( $post ) {
        wp_nonce_field( 'bsm_save_meta', 'bsm_nonce' );
        self::field( $post, 'course_id', 'Kurs-ID (numerische WordPress-Post-ID)', 'number' );
        self::field( $post, 'module_id', 'Modul-ID (optional)', 'number' );
        self::field( $post, 'topic', 'Thema / Themen-ID' );
        self::field( $post, 'options', 'Antwortoptionen (JSON)', 'textarea', 'Frage: [{"id":"a","text":"…"}]. Bei Navigation: Frage-/Aufgaben-JSON.' );
        self::field( $post, 'correct_options', 'Korrekte Options-IDs (JSON-Array)', 'textarea', 'Nur auf Fragen; wird nie vor Abgabe ausgeliefert.' );
        self::field( $post, 'explanation', 'Erklärung' , 'textarea' );
        self::field( $post, 'media_url', 'Medien-URL (leer = Platzhalter)', 'url' );
        self::field( $post, 'duration_seconds', 'Videolänge in Sekunden', 'number' );
        self::field( $post, 'solution', 'Musterlösung (JSON/Text; nicht öffentlich vor Anforderung)', 'textarea' );
    }

    public static function save_content_meta( $post_id, $post ) {
        if ( ! isset( $_POST['bsm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsm_nonce'] ) ), 'bsm_save_meta' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || empty( $_POST['bsm_meta'] ) ) return;
        $fields = wp_unslash( $_POST['bsm_meta'] );
        $allowed = array( 'tenant','access_mode','shop_url','exam_rules','exam_question_count','exam_duration','exam_pass_percent','course_id','module_id','topic','options','correct_options','explanation','media_url','duration_seconds','solution' );
        foreach ( $allowed as $key ) if ( array_key_exists( $key, $fields ) ) update_post_meta( $post_id, '_bsm_' . $key, sanitize_textarea_field( $fields[$key] ) );
    }

    public static function admin_menu() {
        add_options_page( 'BoatingSchool Mobile API', 'BoatingSchool API', 'manage_options', 'bsm-api', array( __CLASS__, 'settings_page' ) );
    }
    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( isset( $_POST['bsm_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsm_settings_nonce'] ) ), 'bsm_settings' ) ) {
            update_option( 'bsm_tenant_slug', sanitize_title( wp_unslash( $_POST['tenant_slug'] ?? '' ) ) ?: 'default' );
            update_option( 'bsm_shop_url', esc_url_raw( wp_unslash( $_POST['shop_url'] ?? '' ) ) );
            $codes=array(); foreach(preg_split('/\\r?\\n/', wp_unslash($_POST['coupon_codes'] ?? '')) as $line){$pair=explode('=',trim($line),2);if(count($pair)===2 && absint($pair[1]))$codes[strtoupper(preg_replace('/\\s+/','',$pair[0]))]=absint($pair[1]);} update_option('bsm_coupon_codes',$codes);
            echo '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>';
        }
        echo '<div class="wrap"><h1>BoatingSchool Mobile API</h1><form method="post">';
        wp_nonce_field( 'bsm_settings', 'bsm_settings_nonce' );
        $coupon_text = implode( "\n", array_map( function ( $code, $course_id ) { return $code . '=' . $course_id; }, array_keys( (array) get_option( 'bsm_coupon_codes', array() ) ), array_values( (array) get_option( 'bsm_coupon_codes', array() ) ) ) );
        echo '<p><label>Standard Tenant-Slug<br><input name="tenant_slug" value="' . esc_attr( get_option( 'bsm_tenant_slug', 'default' ) ) . '"></label></p><p><label>Shop-URL<br><input class="regular-text" name="shop_url" type="url" value="' . esc_attr( get_option( 'bsm_shop_url', '' ) ) . '"></label></p><p><label>Einlösecodes (eine Zeile je CODE=KURS-ID)<br><textarea class="large-text code" rows="5" name="coupon_codes">' . esc_textarea( $coupon_text ) . '</textarea></label></p><p>REST-Basis: <code>' . esc_html( rest_url( self::NS ) ) . '</code></p><p><button class="button button-primary">Speichern</button></p></form><p>API-Route-Informationen und Einrichtung stehen in README-WP-API.md im Projekt.</p></div>';
    }

    private static function route( $path, $methods, $callback, $auth = true ) {
        register_rest_route( self::NS, $path, array( 'methods' => $methods, 'callback' => array( __CLASS__, $callback ), 'permission_callback' => $auth ? array( __CLASS__, 'require_user' ) : '__return_true' ) );
    }
    public static function routes() {
        self::route( '/auth/login', 'POST', 'login', false ); self::route( '/auth/refresh', 'POST', 'refresh', false ); self::route( '/auth/logout', 'POST', 'logout' );
        self::route( '/auth/password/forgot', 'POST', 'forgot_password', false ); self::route( '/auth/password/reset', 'POST', 'reset_password', false ); self::route( '/auth/email/verification-notification', 'POST', 'verification' );
        self::route( '/me', 'GET', 'me' ); self::route( '/me/sessions', 'GET', 'sessions' ); self::route( '/me/sessions/(?P<sessionId>\d+)', 'DELETE', 'revoke_session' );
        self::route( '/courses', 'GET', 'courses' ); self::route( '/courses/(?P<courseId>\d+)', 'GET', 'course' ); self::route( '/courses/(?P<courseId>\d+)/overview', 'GET', 'overview' ); self::route( '/courses/(?P<courseId>\d+)/modules', 'GET', 'modules' );
        self::route( '/courses/(?P<courseId>\d+)/trainer/next', 'GET', 'next_question' ); self::route( '/courses/(?P<courseId>\d+)/attempts', 'POST', 'submit_attempt' ); self::route( '/courses/(?P<courseId>\d+)/attempts/(?P<attemptId>[a-zA-Z0-9_-]+)', 'GET', 'get_attempt' );
        self::route( '/courses/(?P<courseId>\d+)/favorites', 'GET', 'favorites' ); self::route( '/courses/(?P<courseId>\d+)/favorite-collections', array('GET','POST'), 'collections' ); self::route( '/courses/(?P<courseId>\d+)/favorite-collections/(?P<collectionId>[a-zA-Z0-9_-]+)', array('PATCH','DELETE'), 'collection' ); self::route( '/courses/(?P<courseId>\d+)/favorites/(?P<questionId>\d+)', array('PUT','DELETE'), 'favorite' ); self::route( '/courses/(?P<courseId>\d+)/progress', 'GET', 'progress' );
        self::route( '/courses/(?P<courseId>\d+)/exams', array('GET','POST'), 'exams' ); self::route( '/exams/(?P<examId>[a-zA-Z0-9_-]+)', 'GET', 'exam' ); self::route( '/exams/(?P<examId>[a-zA-Z0-9_-]+)/answers/(?P<questionId>\d+)', 'PUT', 'exam_answer' ); self::route( '/exams/(?P<examId>[a-zA-Z0-9_-]+)/submit', 'POST', 'submit_exam' ); self::route( '/exams/(?P<examId>[a-zA-Z0-9_-]+)/result', 'GET', 'exam_result' );
        self::route( '/courses/(?P<courseId>\d+)/videos', 'GET', 'videos' ); self::route( '/videos/(?P<lessonId>\d+)', 'GET', 'video' ); self::route( '/videos/(?P<lessonId>\d+)/progress', 'PUT', 'video_progress' ); self::route( '/courses/(?P<courseId>\d+)/navigation-tasks', 'GET', 'navigation_tasks' ); self::route( '/navigation-tasks/(?P<taskId>\d+)', 'GET', 'navigation_task' );
        self::route( '/coupons/redeem', 'POST', 'redeem' ); self::route( '/profile/export', 'POST', 'request_export' ); self::route( '/profile/export/(?P<exportId>[a-zA-Z0-9_-]+)', 'GET', 'get_export' ); self::route( '/profile/export-download', 'GET', 'download_export', false );
    }

    private static function table() { global $wpdb; return $wpdb->prefix . self::SESSION_TABLE; }
    private static function problem( $code, $message, $status, $extra = array() ) { return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) ); }
    private static function data( $data, $status = 200 ) { return new WP_REST_Response( array( 'data' => $data ), $status ); }
    private static function token() { return rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); }
    private static function hash_token( $token ) { return hash( 'sha256', $token ); }

    public static function authenticate_bearer( $user_id ) {
        if ( $user_id || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) return $user_id;
        global $wpdb;
        $header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
        if ( ! preg_match( '/^Bearer\s+([A-Za-z0-9_-]{50,})$/', $header, $m ) ) return $user_id;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE access_hash = %s AND revoked_at IS NULL AND access_expires > %s LIMIT 1', self::hash_token( $m[1] ), current_time( 'mysql', true ) ) );
        if ( ! $row ) return $user_id;
        $wpdb->update( self::table(), array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) );
        self::$session = $row;
        return (int) $row->user_id;
    }
    public static function require_user() { return get_current_user_id() ? true : self::problem( 'UNAUTHENTICATED', 'Anmeldung erforderlich.', 401 ); }
    private static function tenant_slug() { return sanitize_title( get_option( 'bsm_tenant_slug', 'default' ) ); }
    private static function tenant( $request ) {
        $given = sanitize_title( $request->get_header( 'x-tenant-slug' ) ?: self::tenant_slug() );
        if ( ! get_user_meta( get_current_user_id(), '_bsm_email_verified', true ) && ! get_option( 'bsm_legacy_users_verified', true ) ) return self::problem( 'EMAIL_NOT_VERIFIED', 'Bitte bestätige zuerst deine E-Mail-Adresse.', 403 );
        $memberships = get_user_meta( get_current_user_id(), '_bsm_memberships', true );
        if ( ! is_array( $memberships ) || ! $memberships ) $memberships = array( self::tenant_slug() );
        if ( ! in_array( $given, $memberships, true ) ) return self::problem( 'TENANT_FORBIDDEN', 'Keine aktive Mitgliedschaft für diesen Tenant.', 403 );
        return $given;
    }
    private static function course_post( $id ) {
        $post = get_post( absint( $id ) );
        if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, (array) apply_filters( 'boatingschool_mobile_course_post_types', array( 'bsm_course','sfwd-courses','course' ) ), true ) ) return null;
        return $post;
    }
    private static function has_course_access( $course_id ) {
        $post = self::course_post( $course_id ); if ( ! $post ) return false;
        $mode = get_post_meta( $course_id, '_bsm_access_mode', true );
        if ( 'free' === $mode ) return true;
        $ids = get_user_meta( get_current_user_id(), '_bsm_course_access', true );
        if ( is_array( $ids ) && in_array( (int) $course_id, array_map( 'intval', $ids ), true ) ) return true;
        if ( function_exists( 'sfwd_lms_has_access' ) && sfwd_lms_has_access( $course_id, get_current_user_id() ) ) return true;
        if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) { $enrolled = learndash_user_get_enrolled_courses( get_current_user_id() ); if ( in_array( (int) $course_id, array_map( 'intval', (array) $enrolled ), true ) ) return true; }
        return false;
    }
    private static function require_tenant_course( $request, $course_id ) {
        $tenant = self::tenant( $request ); if ( is_wp_error( $tenant ) ) return $tenant;
        $post = self::course_post( $course_id ); if ( ! $post ) return self::problem( 'NOT_FOUND', 'Kurs nicht gefunden.', 404 );
        $course_tenant = sanitize_title( get_post_meta( $post->ID, '_bsm_tenant', true ) ?: self::tenant_slug() );
        if ( $course_tenant !== $tenant ) return self::problem( 'NOT_FOUND', 'Kurs nicht gefunden.', 404 );
        if ( ! self::has_course_access( $course_id ) ) return self::problem( 'ENTITLEMENT_REQUIRED', 'Kein Zugang zu diesem Kurs.', 403 );
        return $post;
    }
    private static function items( $type, $course_id, $extra = array() ) {
        $args = array_merge( array( 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'menu_order title', 'order' => 'ASC', 'meta_key' => '_bsm_course_id', 'meta_value' => absint( $course_id ) ), $extra );
        return get_posts( $args );
    }
    private static function item_payload( $post ) { return array( 'id' => (string) $post->ID, 'title' => get_the_title( $post ), 'description' => wp_strip_all_tags( $post->post_content ), 'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ), 'module_id' => (string) get_post_meta( $post->ID, '_bsm_module_id', true ), 'topic' => (string) get_post_meta( $post->ID, '_bsm_topic', true ) ); }
    private static function option_list( $post ) { $v = json_decode( get_post_meta( $post->ID, '_bsm_options', true ), true ); return is_array( $v ) ? $v : array(); }
    private static function public_question( $post ) { $options=self::option_list($post);$correct=json_decode(get_post_meta($post->ID,'_bsm_correct_options',true),true);return array('id'=>(string)$post->ID,'revision_id'=>(string)$post->post_modified_gmt,'official_number'=>get_post_meta($post->ID,'_bsm_official_number',true)?:null,'text'=>wp_kses_post($post->post_content),'prompt_media'=>array(),'answers'=>array_map(function($x){return array('id'=>(string)($x['id']??''),'text'=>(string)($x['text']??''),'media'=>array());},$options),'answer_selection'=>is_array($correct)&&count($correct)>1?'multiple':'single','topic'=>get_post_meta($post->ID,'_bsm_topic',true)?:null,'module_id'=>get_post_meta($post->ID,'_bsm_module_id',true)?(string)get_post_meta($post->ID,'_bsm_module_id',true):null); }
    private static function records( $key ) { $v = get_user_meta( get_current_user_id(), self::RECORDS_META . '_' . $key, true ); return is_array( $v ) ? $v : array(); }
    private static function save_records( $key, $records ) { update_user_meta( get_current_user_id(), self::RECORDS_META . '_' . $key, array_values( $records ) ); }
    private static function id( $prefix ) { return $prefix . '_' . wp_generate_password( 18, false, false ); }
    private static function idempotent( $request ) {
        $key = sanitize_text_field( $request->get_header( 'idempotency-key' ) ); if ( ! $key ) return null;
        $cache_key = '_bsm_idem_' . hash( 'sha256', $key ); $cached = get_user_meta( get_current_user_id(), $cache_key, true );
        if ( is_array( $cached ) && ! empty( $cached['expires'] ) && $cached['expires'] > time() ) return $cached['response'];
        return $cache_key;
    }
    private static function remember_idempotent( $cache_key, $response ) { if ( is_string( $cache_key ) && strpos( $cache_key, '_bsm_idem_' ) === 0 ) update_user_meta( get_current_user_id(), $cache_key, array( 'expires' => time() + DAY_IN_SECONDS, 'response' => $response ) ); }
    private static function membership_payload( $user_id = 0 ) { $user_id = $user_id ?: get_current_user_id(); $memberships = get_user_meta( $user_id, '_bsm_memberships', true ); if ( ! is_array( $memberships ) || ! $memberships ) $memberships = array( self::tenant_slug() ); $out = array(); foreach ( $memberships as $slug ) $out[] = array( 'tenant_slug' => $slug, 'tenant_name' => get_bloginfo( 'name' ), 'status' => 'active', 'role' => 'learner', 'branding' => array( 'logo_url' => get_site_icon_url() ?: null, 'primary_color' => null, 'support_email' => get_option( 'admin_email' ) ?: null ) ); return $out; }
    private static function user_payload( $user_id = 0 ) { $u = $user_id ? get_user_by('id',$user_id) : wp_get_current_user(); return array( 'id' => (string) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'email_verified' => (bool) get_user_meta( $u->ID, '_bsm_email_verified', true ) || (bool) get_option( 'bsm_legacy_users_verified', true ) ); }

    public static function login( $r ) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $limit_key = '_bsm_login_' . hash('sha256',$ip);
        $failures = (int) get_transient($limit_key);
        if ($failures >= 10) return self::problem('RATE_LIMITED','Zu viele Anmeldeversuche. Bitte später erneut versuchen.',429,array('retry_after'=>900));
        $creds = array( 'user_login' => sanitize_text_field( $r->get_param( 'email' ) ?: $r->get_param( 'username' ) ), 'user_password' => (string) $r->get_param( 'password' ), 'remember' => false );
        $user = wp_signon( $creds, is_ssl() ); if ( is_wp_error( $user ) ) { set_transient($limit_key,$failures+1,15*MINUTE_IN_SECONDS); return self::problem( 'UNAUTHENTICATED', 'Anmeldung fehlgeschlagen.', 401 ); }
        delete_transient($limit_key);
        if ( ! apply_filters('boatingschool_mobile_user_allowed',$user) ) return self::problem('FORBIDDEN','Dieses Konto ist für die Lernenden-App nicht freigeschaltet.',403);
        $a = self::token(); $b = self::token(); $now = time(); global $wpdb;
        $wpdb->insert( self::table(), array( 'user_id' => $user->ID, 'access_hash' => self::hash_token( $a ), 'refresh_hash' => self::hash_token( $b ), 'device_name' => sanitize_text_field( $r->get_param( 'device_name' ) ?: 'Mobile App' ), 'device_platform' => in_array($r->get_param('device_platform'),array('ios','android'),true)?$r->get_param('device_platform'):null, 'created_at' => gmdate( 'Y-m-d H:i:s', $now ), 'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ), 'access_expires' => gmdate( 'Y-m-d H:i:s', $now + self::ACCESS_TTL ), 'refresh_expires' => gmdate( 'Y-m-d H:i:s', $now + self::REFRESH_TTL ), 'absolute_expires' => gmdate( 'Y-m-d H:i:s', $now + self::SESSION_MAX_TTL ) ) );
        $user_id = $user->ID; if ( ! get_user_meta( $user_id, '_bsm_memberships', true ) ) update_user_meta( $user_id, '_bsm_memberships', array( self::tenant_slug() ) );
        return new WP_REST_Response( array( 'access_token' => $a, 'token_type' => 'Bearer', 'access_expires_at' => gmdate(DATE_RFC3339,$now+self::ACCESS_TTL), 'refresh_token' => $b, 'refresh_expires_at' => gmdate(DATE_RFC3339,$now+self::REFRESH_TTL), 'session_id' => (string)$wpdb->insert_id, 'data' => array( 'user' => self::user_payload($user->ID), 'memberships' => self::membership_payload($user->ID) ) ), 200 );
    }
    public static function refresh( $r ) {
        global $wpdb; $old = (string) $r->get_param( 'refresh_token' ); if ( strlen( $old ) < 50 ) return self::problem( 'UNAUTHENTICATED', 'Refresh-Token ungültig.', 401 );
        $now_sql = current_time( 'mysql', true );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE refresh_hash=%s AND revoked_at IS NULL AND refresh_expires>%s AND absolute_expires>%s', self::hash_token( $old ), $now_sql, $now_sql ) ); if ( ! $row ) return self::problem( 'UNAUTHENTICATED', 'Refresh-Token ungültig oder abgelaufen.', 401 );
        $a = self::token(); $b = self::token(); $now = time(); $refresh_end = min( $now + self::REFRESH_TTL, strtotime( $row->absolute_expires . ' UTC' ) ); $wpdb->update( self::table(), array( 'access_hash' => self::hash_token( $a ), 'refresh_hash' => self::hash_token( $b ), 'access_expires' => gmdate( 'Y-m-d H:i:s', $now + self::ACCESS_TTL ), 'refresh_expires' => gmdate( 'Y-m-d H:i:s', $refresh_end ) ), array( 'id' => $row->id ) );
        return rest_ensure_response( array( 'access_token' => $a, 'token_type' => 'Bearer', 'access_expires_at' => gmdate(DATE_RFC3339,$now+self::ACCESS_TTL), 'refresh_token' => $b, 'refresh_expires_at' => gmdate(DATE_RFC3339,$refresh_end), 'session_id' => (string)$row->id ) );
    }
    public static function logout() { if ( self::$session ) { global $wpdb; $wpdb->update( self::table(), array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => self::$session->id ) ); } return new WP_REST_Response( null, 204 ); }
    public static function forgot_password( $r ) { $email = sanitize_email( $r->get_param( 'email' ) ); if ( ! is_email( $email ) ) return self::problem( 'VALIDATION_FAILED', 'E-Mail-Adresse ungültig.', 422 ); $user = get_user_by( 'email', $email ); if ( $user ) retrieve_password( $user->user_login ); return new WP_REST_Response( null, 202 ); }
    public static function reset_password( $r ) { $email = sanitize_email( $r->get_param( 'email' ) ); $u = get_user_by( 'email', $email ); if ( ! $u || strlen( (string) $r->get_param( 'password' ) ) < 12 || $r->get_param( 'password' ) !== $r->get_param( 'password_confirmation' ) ) return self::problem( 'PASSWORD_RESET_INVALID_OR_EXPIRED', 'Reset-Link ungültig oder abgelaufen.', 422 ); $key = sanitize_text_field( $r->get_param( 'token' ) ); $valid = check_password_reset_key( $key, $u->user_login ); if ( is_wp_error( $valid ) ) return self::problem( 'PASSWORD_RESET_INVALID_OR_EXPIRED', 'Reset-Link ungültig oder abgelaufen.', 422 ); reset_password( $u, (string) $r->get_param( 'password' ) ); global $wpdb; $wpdb->update( self::table(), array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'user_id' => $u->ID, 'revoked_at' => null ) ); return new WP_REST_Response( null, 204 ); }
    public static function verify_email_link() { if ( empty($_GET['bsm_verify']) || empty($_GET['user']) ) return; $uid=absint($_GET['user']); $stored=get_user_meta($uid,'_bsm_email_verification_token',true); $given=sanitize_text_field(wp_unslash($_GET['bsm_verify'])); if($stored && hash_equals($stored,$given)){update_user_meta($uid,'_bsm_email_verified',1);delete_user_meta($uid,'_bsm_email_verification_token');wp_die('E-Mail-Adresse bestätigt. Du kannst zur App zurückkehren.','E-Mail bestätigt',array('response'=>200));} wp_die('Der Bestätigungslink ist ungültig oder abgelaufen.','Link ungültig',array('response'=>400)); }
    public static function verification() { $user = wp_get_current_user(); update_user_meta( $user->ID, '_bsm_email_verification_token', wp_generate_password( 32, false ) ); $token = get_user_meta( $user->ID, '_bsm_email_verification_token', true ); $url = add_query_arg( array( 'bsm_verify' => $token, 'user' => $user->ID ), home_url( '/' ) ); wp_mail( $user->user_email, 'E-Mail bestätigen', 'Bitte bestätige deine E-Mail-Adresse: ' . esc_url_raw( $url ) ); return new WP_REST_Response( null, 202 ); }
    public static function me() { return rest_ensure_response( array( 'data' => array( 'user' => self::user_payload(), 'memberships' => self::membership_payload() ) ) ); }
    public static function sessions() { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,device_name,device_platform,created_at,last_used_at FROM ' . self::table() . ' WHERE user_id=%d AND revoked_at IS NULL AND refresh_expires>%s', get_current_user_id(), current_time( 'mysql', true ) ) ); return self::data( array_map( function($x){ return array( 'id'=>(string)$x->id, 'device_name'=>$x->device_name, 'device_platform'=>$x->device_platform, 'created_at'=>gmdate(DATE_RFC3339,strtotime($x->created_at.' UTC')), 'last_used_at'=>gmdate(DATE_RFC3339,strtotime($x->last_used_at.' UTC')), 'current'=>self::$session && (int)self::$session->id===(int)$x->id ); }, $rows ) ); }
    public static function revoke_session( $r ) { global $wpdb; $ok=$wpdb->update(self::table(),array('revoked_at'=>current_time('mysql',true)),array('id'=>absint($r['sessionId']),'user_id'=>get_current_user_id(),'revoked_at'=>null)); return $ok ? new WP_REST_Response(null,204) : self::problem('NOT_FOUND','Sitzung nicht gefunden.',404); }

    private static function course_summary( $p ) { $id=$p->ID; $entitled=self::has_course_access($id); $questions=self::items('bsm_question',$id); $progress=self::progress_summary($id); return array('id'=>(string)$id,'code'=>$p->post_name,'name'=>get_the_title($p),'description'=>wp_strip_all_tags(get_the_excerpt($p)) ?: null,'access'=>array('status'=>$entitled?'active':'not_granted','valid_from'=>null,'valid_until'=>null),'progress'=>array('percent'=>$progress['percent'],'completed'=>$progress['completed'],'total'=>$progress['total']),'capabilities'=>array('smarttrainer','exam','video','navigation','favorites'),'webshop_url'=>get_post_meta($id,'_bsm_shop_url',true) ?: (get_option('bsm_shop_url','') ?: null)); }
    public static function courses($r) { $tenant=self::tenant($r); if(is_wp_error($tenant))return $tenant; $page=max(1,absint($r->get_param('page')?:1));$size=max(1,min(100,absint($r->get_param('per_page')?:20)));$all=get_posts(array('post_type'=>array('bsm_course','sfwd-courses','course'),'post_status'=>'publish','posts_per_page'=>-1));$all=array_values(array_filter($all,function($p)use($tenant){return sanitize_title(get_post_meta($p->ID,'_bsm_tenant',true)?:self::tenant_slug())===$tenant;}));$posts=array_slice($all,($page-1)*$size,$size);$items=array_map(array(__CLASS__,'course_summary'),$posts);return rest_ensure_response(array('data'=>$items,'meta'=>array('tenant'=>array('slug'=>$tenant,'name'=>get_bloginfo('name')),'pagination'=>array('page'=>$page,'per_page'=>$size,'total'=>count($all),'last_page'=>max(1,(int)ceil(count($all)/$size)))))); }
    public static function course($r) { $p=self::require_tenant_course($r,$r['courseId']); if(is_wp_error($p))return $p; $d=self::course_summary($p); $d['modules_count']=count(self::items('bsm_module',$p->ID)); $d['exam_options_count']=count(self::exam_rules($p->ID)); return self::data($d); }
    private static function progress_summary($course) { $a=self::records('attempts'); $total=count(self::items('bsm_question',$course)); $answered=array(); foreach($a as $row) if((int)$row['course_id']===(int)$course){$n=isset($answered[$row['question_id']])?$answered[$row['question_id']]:0;$answered[$row['question_id']]=min(3,$n+1);} $completed=count(array_filter($answered,function($n){return $n>=3;})); return array('completed'=>$completed,'total'=>$total,'percent'=>$total?min(100,(int)round(100*$completed/$total)):0); }
    public static function overview($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$mods=self::items('bsm_module',$p->ID);$prog=self::progress_summary($p->ID);$next=self::next_question_for($p->ID,'smarttrainer','',null);$module_data=array();foreach($mods as $m){$questions=self::items('bsm_question',$p->ID);$module_data[]=array_merge(self::module_payload($m,count(array_filter($questions,function($q)use($m){return (int)get_post_meta($q->ID,'_bsm_module_id',true)===$m->ID;}))),array('progress'=>self::module_progress($p->ID,$m->ID)));}$continue=null;if($next){$mid=(int)get_post_meta($next->ID,'_bsm_module_id',true);$mp=get_post($mid);$continue=array('module_id'=>(string)$mid,'module_name'=>$mp?get_the_title($mp):'Lernen','mode'=>'smarttrainer');}return self::data(array('course'=>self::course_summary($p),'progress'=>$prog,'modules'=>$module_data,'continue_learning'=>$continue)); }
    private static function module_payload($m,$qcount=0){return array('id'=>(string)$m->ID,'name'=>get_the_title($m),'description'=>wp_strip_all_tags(get_the_excerpt($m))?:null,'order'=>(int)$m->menu_order,'question_count'=>$qcount);}
    private static function module_progress($course,$module){$all=self::items('bsm_question',$course);$ids=array();foreach($all as $q)if((int)get_post_meta($q->ID,'_bsm_module_id',true)===(int)$module)$ids[]=$q->ID;$attempts=self::records('attempts');$done=array();foreach($attempts as $a)if(in_array((int)$a['question_id'],$ids,true))$done[$a['question_id']]=min(3,($done[$a['question_id']]??0)+1);$completed=count(array_filter($done,function($n){return $n>=3;}));$total=count($ids);return array('percent'=>$total?(int)round(100*$completed/$total):0,'completed'=>$completed,'total'=>$total);}
    public static function modules($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$arr=array();foreach(self::items('bsm_module',$p->ID) as $m){$qs=self::items('bsm_question',$p->ID);$count=count(array_filter($qs,function($q)use($m){return (int)get_post_meta($q->ID,'_bsm_module_id',true)===$m->ID;}));$arr[]=array_merge(self::module_payload($m,$count),array('progress'=>self::module_progress($p->ID,$m->ID)));}return self::data($arr); }

    private static function next_question_for($course,$mode,$topic='',$module=null) { $qs=self::items('bsm_question',$course);$attempts=self::records('attempts');$fav=self::records('favorites');$out=array();foreach($qs as $q){if($topic && get_post_meta($q->ID,'_bsm_topic',true)!==$topic)continue;if($module && (int)get_post_meta($q->ID,'_bsm_module_id',true)!==(int)$module)continue;$count=0;$wrong=false;foreach($attempts as $a)if((int)$a['question_id']===$q->ID){$count++;if(empty($a['correct']))$wrong=true;}if($mode==='wrong'&&!$wrong)continue;if($mode==='new'&&$count)continue;if($mode==='favorites'&&!in_array($q->ID,array_map('intval',array_column($fav,'question_id')),true))continue;if($mode!=='wrong'&&$count>=3)continue;$out[]=$q;}return $out? $out[array_rand($out)]:null; }
    public static function next_question($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$mode=sanitize_key($r->get_param('mode')?:'smarttrainer');if(!in_array($mode,array('smarttrainer','new','wrong','topic','favorites'),true))return self::problem('VALIDATION_FAILED','Modus ungültig.',422);$q=self::next_question_for($p->ID,$mode,sanitize_text_field($r->get_param('topic')?:''),absint($r->get_param('module_id'))?:null);if(!$q)return new WP_REST_Response(null,204);$n=0;foreach(self::records('attempts') as $a)if((int)$a['question_id']===$q->ID)$n++;return self::data(array('question'=>self::public_question($q),'mode'=>$mode,'selection_reason'=>null,'attempts_completed'=>$n,'attempts_required'=>3,'attempt_number'=>$n+1)); }
    private static function check_answer($q,$selected) { $right=json_decode(get_post_meta($q->ID,'_bsm_correct_options',true),true);if(!is_array($right))$right=array();$selected=array_values(array_unique(array_map('strval',(array)$selected)));sort($right);sort($selected);return $right===$selected; }
    public static function submit_attempt($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$cache=self::idempotent($r);if(is_array($cache))return rest_ensure_response($cache);$body=$r->get_json_params();$qid=absint($body['question_id']??0);$q=get_post($qid);if(!$q||$q->post_type!=='bsm_question'||(int)get_post_meta($qid,'_bsm_course_id',true)!==$p->ID)return self::problem('NOT_FOUND','Frage nicht gefunden.',404);if(isset($body['revision_id'])&&(string)$body['revision_id']!==(string)$q->post_modified_gmt)return self::problem('QUESTION_REVISION_MISMATCH','Die Frage wurde aktualisiert; bitte neu laden.',409);$selected=$body['selected_answer_ids']??array();$valid=array_map('strval',array_column(self::option_list($q),'id'));$selected=array_values(array_unique(array_map('strval',(array)$selected)));if(array_diff($selected,$valid))return self::problem('VALIDATION_FAILED','Ungültige Antwortauswahl.',422);$correct=self::check_answer($q,$selected);$records=self::records('attempts');$id=self::id('att');$row=array('id'=>$id,'course_id'=>$p->ID,'question_id'=>$qid,'module_id'=>(int)get_post_meta($qid,'_bsm_module_id',true),'topic'=>get_post_meta($qid,'_bsm_topic',true),'selected_answer_ids'=>$selected,'correct'=>$correct,'created_at'=>gmdate(DATE_RFC3339));$records[]=$row;self::save_records('attempts',$records);$count=count(array_filter($records,function($x)use($qid){return (int)$x['question_id']===$qid;}));$payload=array('data'=>array('attempt_id'=>$id,'correct'=>$correct,'selected_answer_ids'=>$selected,'correct_answer_ids'=>json_decode(get_post_meta($qid,'_bsm_correct_options',true),true)?:array(),'explanation'=>get_post_meta($qid,'_bsm_explanation',true)?:null,'learning_state'=>$count>=3?'gefestigt':'in_bearbeitung','attempts_completed'=>min(3,$count),'attempts_required'=>3,'completed'=>$count>=3,'progress'=>array('mastery_score'=>$count>=3?1.0:min(0.99,$count/3),'current_streak'=>$correct?$count:0)));self::remember_idempotent($cache,$payload);return new WP_REST_Response($payload,201); }
    public static function get_attempt($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;foreach(self::records('attempts') as $a)if($a['id']===$r['attemptId']&&(int)$a['course_id']===$p->ID){$q=get_post($a['question_id']);$count=count(array_filter(self::records('attempts'),function($x)use($a){return (int)$x['question_id']===(int)$a['question_id'];}));return self::data(array('attempt_id'=>$a['id'],'correct'=>(bool)$a['correct'],'selected_answer_ids'=>$a['selected_answer_ids'],'correct_answer_ids'=>json_decode(get_post_meta($a['question_id'],'_bsm_correct_options',true),true)?:array(),'explanation'=>$q?get_post_meta($q->ID,'_bsm_explanation',true):null,'learning_state'=>$count>=3?'gefestigt':'in_bearbeitung','attempts_completed'=>min(3,$count),'attempts_required'=>3,'completed'=>$count>=3));}return self::problem('NOT_FOUND','Versuch nicht gefunden.',404); }
    public static function favorites($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$cid=sanitize_text_field($r->get_param('collection_id'));$out=array();foreach(self::records('favorites') as $f)if((int)$f['course_id']===$p->ID&&(!$cid||$f['collection_id']===$cid))$out[]=array('question_id'=>(string)$f['question_id'],'context'=>$f['context']??'smart_learning','collection_id'=>$f['collection_id']?:null,'created_at'=>$f['created_at']);return self::data($out); }
    private static function collection_payload($c){$count=0;foreach(self::records('favorites') as $f)if(($f['collection_id']??'')===$c['id'])$count++;return array('id'=>$c['id'],'name'=>$c['name'],'favorite_count'=>$count,'created_at'=>$c['created_at'],'updated_at'=>$c['updated_at']??$c['created_at']);}
    public static function collections($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$rows=self::records('collections');if($r->get_method()==='GET')return self::data(array_map(array(__CLASS__,'collection_payload'),array_values(array_filter($rows,function($x)use($p){return (int)$x['course_id']===$p->ID;}))));$name=sanitize_text_field($r->get_param('name'));if(!$name||strlen($name)>60)return self::problem('VALIDATION_FAILED','Name erforderlich (max. 60 Zeichen).',422);$id=self::id('col');$now=gmdate(DATE_RFC3339);$rows[]=array('id'=>$id,'course_id'=>$p->ID,'name'=>$name,'created_at'=>$now,'updated_at'=>$now);self::save_records('collections',$rows);return self::data(self::collection_payload(end($rows)),201); }
    public static function collection($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$rows=self::records('collections');foreach($rows as $i=>$c)if($c['id']===$r['collectionId']&&(int)$c['course_id']===$p->ID){if($r->get_method()==='DELETE'){$rows[$i]=null;self::save_records('collections',array_filter($rows));$f=self::records('favorites');foreach($f as &$v)if($v['collection_id']===$c['id'])$v['collection_id']='';self::save_records('favorites',$f);return new WP_REST_Response(null,204);} $name=sanitize_text_field($r->get_param('name'));if(!$name||strlen($name)>60)return self::problem('VALIDATION_FAILED','Name erforderlich (max. 60 Zeichen).',422);$rows[$i]['name']=$name;$rows[$i]['updated_at']=gmdate(DATE_RFC3339);self::save_records('collections',$rows);return self::data(self::collection_payload($rows[$i]));}return self::problem('NOT_FOUND','Sammlung nicht gefunden.',404); }
    public static function favorite($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$qid=absint($r['questionId']);$q=get_post($qid);if(!$q||$q->post_type!=='bsm_question'||(int)get_post_meta($qid,'_bsm_course_id',true)!==$p->ID)return self::problem('NOT_FOUND','Frage nicht gefunden.',404);$rows=self::records('favorites');if($r->get_method()==='DELETE'){$rows=array_values(array_filter($rows,function($x)use($p,$qid){return (int)$x['course_id']!==$p->ID||(int)$x['question_id']!==$qid;}));self::save_records('favorites',$rows);return new WP_REST_Response(null,204);} $cid=sanitize_text_field($r->get_param('collection_id'));$context=sanitize_key($r->get_param('context')?:'smart_learning');if(!in_array($context,array('smart_learning','exam'),true))return self::problem('VALIDATION_FAILED','Kontext ungültig.',422);if($cid&&!array_filter(self::records('collections'),function($c)use($cid,$p){return $c['id']===$cid&&(int)$c['course_id']===$p->ID;}))return self::problem('NOT_FOUND','Sammlung nicht gefunden.',404);$row=array('course_id'=>$p->ID,'question_id'=>$qid,'collection_id'=>$cid,'context'=>$context,'created_at'=>gmdate(DATE_RFC3339));$found=false;foreach($rows as &$f)if((int)$f['course_id']===$p->ID&&(int)$f['question_id']===$qid){$row['created_at']=$f['created_at'];$f=$row;$found=true;}if(!$found)$rows[]=$row;self::save_records('favorites',$rows);return self::data(array('question_id'=>(string)$qid,'context'=>$context,'collection_id'=>$cid?:null,'created_at'=>$row['created_at'])); }
    public static function progress($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$modules=array();foreach(self::items('bsm_module',$p->ID) as $m){$qs=self::items('bsm_question',$p->ID);$n=count(array_filter($qs,function($q)use($m){return (int)get_post_meta($q->ID,'_bsm_module_id',true)===$m->ID;}));$modules[]=array_merge(self::module_payload($m,$n),array('progress'=>self::module_progress($p->ID,$m->ID)));}return self::data(array('overall'=>self::progress_summary($p->ID),'modules'=>$modules)); }

    private static function exam_rules($course) {
        $raw = get_post_meta( $course, '_bsm_exam_rules', true );
        $rules = json_decode( $raw, true );
        if ( is_array( $rules ) && $rules ) return $rules;
        return array( array(
            'id' => 'standard',
            'title' => 'Standardprüfung',
            'question_count' => max( 1, absint( get_post_meta( $course, '_bsm_exam_question_count', true ) ?: 30 ) ),
            'duration_minutes' => max( 1, absint( get_post_meta( $course, '_bsm_exam_duration', true ) ?: 30 ) ),
            'pass_percent' => min( 100, max( 1, absint( get_post_meta( $course, '_bsm_exam_pass_percent', true ) ?: 80 ) ) ),
        ) );
    }
    public static function exams($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;if($r->get_method()==='GET'){$out=array();foreach(self::exam_rules($p->ID) as $rule){$n=(int)($rule['question_count']??0);$duration=(int)($rule['duration_minutes']??30)*60;$out[]=array('id'=>(string)($rule['id']??'standard'),'kind'=>'rule_set','name'=>(string)($rule['title']??'Prüfung'),'question_count'=>$n,'duration_seconds'=>$duration,'pass_rule'=>array('description'=>'Mindestens '.(int)($rule['pass_percent']??80).'% richtige Antworten','minimum_correct'=>null,'minimum_score'=>(int)($rule['pass_percent']??80)/100),'startable'=>count(self::items('bsm_question',$p->ID)) >= $n,'last_result'=>null);}return self::data($out);}
        $cache=self::idempotent($r);if(is_array($cache))return rest_ensure_response($cache);$body=$r->get_json_params();$type=sanitize_key($body['exam_type']??$body['type']??$body['mode']??'official_simulation');$rules=self::exam_rules($p->ID);$rule=null;foreach($rules as $x)if(($x['id']??'standard')===$type){$rule=$x;break;}if(!$rule&&$type==='official_simulation')$rule=$rules[0]??null;if(!$rule)return self::problem('VALIDATION_FAILED','Prüfungsregel nicht verfügbar.',422);
        $all=self::items('bsm_question',$p->ID);if(count($all)<(int)$rule['question_count'])return self::problem('EXAM_QUESTION_POOL_TOO_SMALL','Zu wenige veröffentlichte Fragen für diese Prüfung.',422);
        foreach(self::records('exams') as $e)if((int)$e['course_id']===$p->ID&&$e['status']==='in_progress'&&strtotime($e['expires_at'])>time()){$res=self::exam_payload($e,false);$wrap=array('data'=>$res);self::remember_idempotent($cache,$wrap);return new WP_REST_Response($wrap,201);}
        shuffle($all);$qs=array_slice($all,0,(int)$rule['question_count']);$mins=max(1,(int)($rule['duration_minutes']??30));$id=self::id('exam');$row=array('id'=>$id,'course_id'=>$p->ID,'type'=>$type,'title'=>$rule['title']??$type,'question_ids'=>array_map(function($q){return (int)$q->ID;},$qs),'answers'=>array(),'status'=>'in_progress','pass_percent'=>(int)($rule['pass_percent']??80),'created_at'=>gmdate(DATE_RFC3339),'expires_at'=>gmdate(DATE_RFC3339,time()+$mins*60),'result'=>null);$rows=self::records('exams');$rows[]=$row;self::save_records('exams',$rows);$wrap=array('data'=>self::exam_payload($row,false));self::remember_idempotent($cache,$wrap);return new WP_REST_Response($wrap,201);
    }
    private static function exam_payload($e,$include_result) { $questions=array();foreach($e['question_ids'] as $qid){$q=get_post($qid);if(!$q||$q->post_status!=='publish')continue;$questions[]=self::public_question($q);} $answers=array();foreach($e['answers'] as $qid=>$a)$answers[]=array('question_id'=>(string)$qid,'selected_answer_ids'=>$a['selected_answer_ids']??array(),'marked_for_review'=>!empty($a['marked_for_review']));return array('exam_id'=>$e['id'],'status'=>$e['status']==='submitted'?'evaluated':($e['status']==='expired'?'expired':'running'),'started_at'=>$e['created_at'],'expires_at'=>$e['expires_at'],'server_time'=>gmdate(DATE_RFC3339),'question_count'=>count($e['question_ids']),'questions'=>$questions,'answers'=>$answers); }
    private static function find_exam($rows,$id) { foreach($rows as $i=>$e)if($e['id']===$id)return array($i,$e);return null; }
    private static function update_exam_response($r,$action) { $rows=self::records('exams');$found=self::find_exam($rows,$r['examId']);if(!$found)return self::problem('NOT_FOUND','Prüfung nicht gefunden.',404);$i=$found[0];$e=&$rows[$i];$course=self::require_tenant_course($r,$e['course_id']);if(is_wp_error($course))return $course;if($e['status']!=='in_progress')return self::problem('EXAM_ALREADY_SUBMITTED','Prüfung ist bereits abgeschlossen.',409);if(strtotime($e['expires_at'])<=time()){ $e['status']='expired';self::save_records('exams',$rows);return self::problem('EXAM_EXPIRED','Prüfungszeit abgelaufen.',410); }return array($rows,$i,$e); }
    public static function exam($r) { $rows=self::records('exams');$f=self::find_exam($rows,$r['examId']);if(!$f)return self::problem('NOT_FOUND','Prüfung nicht gefunden.',404);$e=$rows[$f[0]];$p=self::require_tenant_course($r,$e['course_id']);if(is_wp_error($p))return $p;if($e['status']==='expired')return self::problem('EXAM_EXPIRED','Prüfung abgelaufen.',410);return self::data(self::exam_payload($e,$e['status']==='submitted')); }
    public static function exam_answer($r) { $x=self::update_exam_response($r,'answer');if(is_wp_error($x))return $x;list($rows,$i,$e)=$x;$qid=absint($r['questionId']);if(!in_array($qid,$e['question_ids'],true))return self::problem('NOT_FOUND','Frage gehört nicht zu dieser Prüfung.',404);$q=get_post($qid);$body=$r->get_json_params();$sel=array_values(array_unique(array_map('strval',(array)($body['selected_answer_ids']??array()))));$valid=array_map('strval',array_column(self::option_list($q),'id'));if(array_diff($sel,$valid))return self::problem('VALIDATION_FAILED','Ungültige Antwortauswahl.',422);$e['answers'][$qid]=array('selected_answer_ids'=>$sel,'marked_for_review'=>!empty($body['marked_for_review']),'saved_at'=>gmdate(DATE_RFC3339));self::save_records('exams',$rows);return self::data(array('saved'=>true,'question_id'=>(string)$qid,'selected_answer_ids'=>$sel,'marked_for_review'=>!empty($body['marked_for_review']),'saved_at'=>$e['answers'][$qid]['saved_at'])); }
    private static function finalize_exam($rows,$i) { $e=&$rows[$i];$correct=0;$answered=0;$details=array();$topics=array();foreach($e['question_ids'] as $qid){$q=get_post($qid);if(!$q)continue;$ans=$e['answers'][$qid]['selected_answer_ids']??array();if($ans)$answered++;$ok=self::check_answer($q,$ans);if($ok)$correct++;$topic=get_post_meta($qid,'_bsm_topic',true)?:'Allgemein';if(!isset($topics[$topic]))$topics[$topic]=array('topic'=>$topic,'correct_count'=>0,'question_count'=>0);$topics[$topic]['question_count']++;if($ok)$topics[$topic]['correct_count']++;$details[]=array('question_id'=>(string)$qid,'selected_answer_ids'=>$ans,'correct'=>$ok,'correct_answer_ids'=>json_decode(get_post_meta($qid,'_bsm_correct_options',true),true)?:array(),'explanation'=>get_post_meta($qid,'_bsm_explanation',true));}$count=count($e['question_ids']);$score=$count?$correct/$count:0;$e['status']='submitted';$e['result']=array('exam_id'=>$e['id'],'status'=>'evaluated','passed'=>$score>=((int)$e['pass_percent']/100),'score'=>$score,'correct_count'=>$correct,'question_count'=>$count,'evaluated_at'=>gmdate(DATE_RFC3339),'rule_set_version'=>'1','by_topic'=>array_values($topics),'answers'=>$details);return $e; }
    public static function submit_exam($r) { $x=self::update_exam_response($r,'submit');if(is_wp_error($x)){if($x->get_error_code()==='EXAM_ALREADY_SUBMITTED')return self::exam_result($r);return $x;}list($rows,$i,$e)=$x;$result=self::finalize_exam($rows,$i);self::save_records('exams',$rows);return self::data($result['result']); }
    public static function exam_result($r) { $rows=self::records('exams');$f=self::find_exam($rows,$r['examId']);if(!$f)return self::problem('NOT_FOUND','Prüfung nicht gefunden.',404);$e=$rows[$f[0]];$p=self::require_tenant_course($r,$e['course_id']);if(is_wp_error($p))return $p;if($e['status']!=='submitted')return self::problem('EXAM_NOT_SUBMITTED','Prüfung wurde noch nicht abgegeben.',409);return self::data($e['result']); }

    public static function videos($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$groups=array();foreach(self::items('bsm_module',$p->ID) as $m){$lessons=array();foreach(self::items('bsm_lesson',$p->ID) as $l)if((int)get_post_meta($l->ID,'_bsm_module_id',true)===$m->ID)$lessons[]=self::video_payload($l);$groups[]=array('id'=>(string)$m->ID,'title'=>get_the_title($m),'order'=>(int)$m->menu_order,'lessons'=>$lessons);}return self::data($groups); }
    private static function video_payload($l){$url=esc_url_raw(get_post_meta($l->ID,'_bsm_media_url',true));$progress=self::records('video_progress');$saved=array();foreach($progress as $v)if((int)$v['lesson_id']===$l->ID)$saved=$v;return array('id'=>(string)$l->ID,'title'=>get_the_title($l),'description'=>wp_strip_all_tags(get_the_excerpt($l))?:null,'duration_seconds'=>absint(get_post_meta($l->ID,'_bsm_duration_seconds',true)),'media'=>array('kind'=>$url?'video':'placeholder','available'=>(bool)$url,'label'=>$url?'Video':'Video folgt','url'=>$url?:null,'mime_type'=>null,'placeholder_reason'=>$url?null:'awaiting_asset'),'steps'=>array(),'completed'=>!empty($saved['completed']),'position_seconds'=>absint($saved['position_seconds']??0));}
    public static function video($r) { $l=get_post(absint($r['lessonId']));if(!$l||$l->post_type!=='bsm_lesson'||$l->post_status!=='publish')return self::problem('NOT_FOUND','Lektion nicht gefunden.',404);$p=self::require_tenant_course($r,get_post_meta($l->ID,'_bsm_course_id',true));if(is_wp_error($p))return $p;$d=self::video_payload($l);$steps=json_decode(get_post_meta($l->ID,'_bsm_options',true),true);if(is_array($steps))$d['steps']=$steps;return self::data($d); }
    public static function video_progress($r) { $l=get_post(absint($r['lessonId']));if(!$l||$l->post_type!=='bsm_lesson'||$l->post_status!=='publish')return self::problem('NOT_FOUND','Lektion nicht gefunden.',404);$p=self::require_tenant_course($r,get_post_meta($l->ID,'_bsm_course_id',true));if(is_wp_error($p))return $p;$body=$r->get_json_params();$pos=absint($body['position_seconds']??0);$duration=absint(get_post_meta($l->ID,'_bsm_duration_seconds',true));if($duration)$pos=min($pos,$duration);$rows=self::records('video_progress');$entry=array('lesson_id'=>$l->ID,'course_id'=>$p->ID,'position_seconds'=>$pos,'completed'=>!empty($body['completed']),'updated_at'=>gmdate(DATE_RFC3339));$found=false;foreach($rows as &$v)if((int)$v['lesson_id']===$l->ID){$v=$entry;$found=true;}if(!$found)$rows[]=$entry;self::save_records('video_progress',$rows);return self::data($entry); }
    public static function navigation_tasks($r) { $p=self::require_tenant_course($r,$r['courseId']);if(is_wp_error($p))return $p;$items=self::items('bsm_navigation_task',$p->ID);$page=max(1,absint($r->get_param('page')?:1));$size=min(100,max(1,absint($r->get_param('per_page')?:20)));$out=array();foreach(array_slice($items,($page-1)*$size,$size) as $t){$parts=json_decode(get_post_meta($t->ID,'_bsm_options',true),true)?:array();$out[]=array('id'=>(string)$t->ID,'title'=>get_the_title($t),'description'=>wp_strip_all_tags(get_the_excerpt($t))?:null,'part_count'=>max(1,count($parts)),'correction_notice'=>null);}return rest_ensure_response(array('data'=>$out,'meta'=>array('pagination'=>array('page'=>$page,'per_page'=>$size,'total'=>count($items),'last_page'=>max(1,(int)ceil(count($items)/$size)))))); }
    public static function navigation_task($r) { $t=get_post(absint($r['taskId']));if(!$t||$t->post_type!=='bsm_navigation_task'||$t->post_status!=='publish')return self::problem('NOT_FOUND','Aufgabe nicht gefunden.',404);$p=self::require_tenant_course($r,get_post_meta($t->ID,'_bsm_course_id',true));if(is_wp_error($p))return $p;$parts=json_decode(get_post_meta($t->ID,'_bsm_options',true),true)?:array();$show=filter_var($r->get_param('show_solution'),FILTER_VALIDATE_BOOLEAN);$out=array();foreach($parts as $i=>$part){$x=array('id'=>(string)($part['id']??($i+1)),'prompt'=>(string)($part['prompt']??$part['text']??''),'media'=>array());if($show)$x['solution']=$part['solution']??null; $out[]=$x;}return self::data(array('id'=>(string)$t->ID,'title'=>get_the_title($t),'description'=>wp_strip_all_tags(get_the_excerpt($t))?:null,'part_count'=>max(1,count($parts)),'correction_notice'=>null,'parts'=>$out)); }

    public static function redeem($r) { $tenant=self::tenant($r);if(is_wp_error($tenant))return $tenant;$cache=self::idempotent($r);if(is_array($cache))return rest_ensure_response($cache);$code=strtoupper(preg_replace('/\s+/','',sanitize_text_field($r->get_param('code'))));$coupons=get_option('bsm_coupon_codes',array());$course=absint($coupons[$code]??0);if(!$course)return self::problem('COUPON_INVALID','Code ungültig oder bereits verwendet.',404);$p=self::course_post($course);if(!$p)return self::problem('COUPON_INVALID','Code ungültig.',404);$used=(array)get_post_meta($course,'_bsm_redeemed_codes',true);if(in_array(hash('sha256',$code),$used,true))return self::problem('COUPON_ALREADY_REDEEMED','Code wurde bereits eingelöst.',409);$used[]=hash('sha256',$code);update_post_meta($course,'_bsm_redeemed_codes',$used);$ids=get_user_meta(get_current_user_id(),'_bsm_course_access',true);if(!is_array($ids))$ids=array();$ids[]=$course;update_user_meta(get_current_user_id(),'_bsm_course_access',array_values(array_unique(array_map('intval',$ids))));$members=get_user_meta(get_current_user_id(),'_bsm_memberships',true);if(!is_array($members))$members=array();$members[]=$tenant;update_user_meta(get_current_user_id(),'_bsm_memberships',array_values(array_unique($members)));$wrap=array('data'=>array('redeemed'=>true,'course_id'=>(string)$course,'course_name'=>get_the_title($p),'entitlement'=>array('status'=>'active','valid_from'=>null,'valid_until'=>null)));self::remember_idempotent($cache,$wrap);return rest_ensure_response($wrap); }
    public static function request_export($r) { $tenant=self::tenant($r);if(is_wp_error($tenant))return $tenant;$id=self::id('exp');$records=array();foreach(array('attempts','favorites','collections','exams','video_progress') as $key)$records[$key]=self::records($key);$user=get_user_by('id',get_current_user_id());$payload=array('user'=>self::user_payload(),'wordpress_profile'=>array('username'=>$user->user_login,'registered_at'=>$user->user_registered,'first_name'=>get_user_meta($user->ID,'first_name',true),'last_name'=>get_user_meta($user->ID,'last_name',true),'course_access'=>get_user_meta($user->ID,'_bsm_course_access',true)),'memberships'=>self::membership_payload(),'data'=>$records,'created_at'=>gmdate(DATE_RFC3339));$uploads=wp_upload_dir();$dir=trailingslashit($uploads['basedir']).'boatingschool-exports';if(!wp_mkdir_p($dir))return self::problem('EXPORT_FAILED','Export konnte nicht erstellt werden.',500);$secret=wp_generate_password(40,false,false);$filename=$id.'-'.hash('sha256',$secret).'.json';$path=trailingslashit($dir).$filename;if(false===file_put_contents($path,wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)))return self::problem('EXPORT_FAILED','Export konnte nicht gespeichert werden.',500);$row=array('id'=>$id,'path'=>$path,'token_hash'=>hash('sha256',$secret),'expires'=>time()+HOUR_IN_SECONDS,'status'=>'ready','download_url'=>add_query_arg(array('id'=>$id,'token'=>$secret),rest_url(self::NS.'/profile/export-download')));$rows=self::records('exports');$rows[]=$row;self::save_records('exports',$rows);set_transient('_bsm_export_'.hash('sha256',$secret),array('user_id'=>get_current_user_id(),'path'=>$path),HOUR_IN_SECONDS);return new WP_REST_Response(array('data'=>array('export_id'=>$id,'status'=>'ready')) ,202); }
    public static function download_export($r) { $id=sanitize_text_field($r->get_param('id'));$token=(string)$r->get_param('token');$record=get_transient('_bsm_export_'.hash('sha256',$token));if(!$id||!$record||strpos(basename($record['path']),$id.'-')!==0||!is_readable($record['path']))return self::problem('NOT_FOUND','Export-Link ungültig oder abgelaufen.',404);nocache_headers();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename=boatingschool-export.json');readfile($record['path']);exit; }
    public static function get_export($r) { foreach(self::records('exports') as $e)if($e['id']===$r['exportId']){$expired=(int)$e['expires']<time();return self::data(array('export_id'=>$e['id'],'status'=>$expired?'expired':'ready','download_url'=>$expired?null:($e['download_url']??null),'expires_at'=>gmdate(DATE_RFC3339,$e['expires'])));}return self::problem('NOT_FOUND','Export nicht gefunden.',404); }
}

register_activation_hook( __FILE__, array( 'BoatingSchool_Mobile_API', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BoatingSchool_Mobile_API', 'deactivate' ) );
BoatingSchool_Mobile_API::init();

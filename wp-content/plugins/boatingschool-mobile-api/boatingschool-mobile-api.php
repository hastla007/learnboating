<?php
/**
 * Plugin Name: BoatingSchool Mobile API
 * Description: Minimal versioned REST API foundation for the BoatingSchool learner app.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: boatingschool-mobile-api
 */

defined( 'ABSPATH' ) || exit;

final class BoatingSchool_Mobile_API {
    const NS = 'boatingschool/v1';
    const TOKEN_PREFIX = 'bsm_api_token_';
    const TOKEN_TTL = 43200; // 12 hours; rotate/re-authenticate after expiry.

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer' ), 20 );
    }

    public static function routes() {
        register_rest_route( self::NS, '/auth/login', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'login' ),
            'permission_callback' => '__return_true',
            'args' => array(
                'username' => array( 'required' => true, 'type' => 'string' ),
                'password' => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );
        register_rest_route( self::NS, '/auth/logout', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'logout' ),
            'permission_callback' => array( __CLASS__, 'require_user' ),
        ) );
        register_rest_route( self::NS, '/me', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'me' ),
            'permission_callback' => array( __CLASS__, 'require_user' ),
        ) );
        register_rest_route( self::NS, '/courses', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'courses' ),
            'permission_callback' => array( __CLASS__, 'require_user' ),
        ) );
        register_rest_route( self::NS, '/courses/(?P<courseId>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'course' ),
            'permission_callback' => array( __CLASS__, 'require_user' ),
            'args' => array( 'courseId' => array( 'required' => true, 'sanitize_callback' => 'absint' ) ),
        ) );
    }

    public static function authenticate_bearer( $user_id ) {
        if ( $user_id || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) return $user_id;
        $header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
        if ( ! preg_match( '/^Bearer\s+([A-Za-z0-9_-]{40,})$/', $header, $m ) ) return $user_id;
        $user = get_transient( self::TOKEN_PREFIX . hash( 'sha256', $m[1] ) );
        return $user ? absint( $user ) : $user_id;
    }

    public static function require_user() {
        return get_current_user_id() ? true : new WP_Error( 'UNAUTHENTICATED', 'Anmeldung erforderlich.', array( 'status' => 401 ) );
    }

    public static function login( WP_REST_Request $request ) {
        $creds = array(
            'user_login' => sanitize_text_field( $request->get_param( 'username' ) ),
            'user_password' => (string) $request->get_param( 'password' ),
            'remember' => false,
        );
        $user = wp_signon( $creds, is_ssl() );
        if ( is_wp_error( $user ) ) {
            return new WP_Error( 'UNAUTHENTICATED', 'Anmeldung fehlgeschlagen.', array( 'status' => 401 ) );
        }
        $token = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
        set_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ), (string) $user->ID, self::TOKEN_TTL );
        return new WP_REST_Response( array(
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL,
            'user' => self::user_payload( $user ),
        ), 200 );
    }

    public static function logout() {
        $header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
        if ( preg_match( '/^Bearer\s+([A-Za-z0-9_-]{40,})$/', $header, $m ) ) {
            delete_transient( self::TOKEN_PREFIX . hash( 'sha256', $m[1] ) );
        }
        return new WP_REST_Response( null, 204 );
    }

    public static function me() {
        return rest_ensure_response( array( 'data' => self::user_payload( wp_get_current_user() ) ) );
    }

    private static function user_payload( WP_User $user ) {
        return array( 'id' => (string) $user->ID, 'name' => $user->display_name, 'email' => $user->user_email );
    }

    private static function course_post_types() {
        $types = apply_filters( 'boatingschool_mobile_course_post_types', array( 'sfwd-courses', 'course' ) );
        return array_values( array_filter( (array) $types, 'post_type_exists' ) );
    }

    private static function course_payload( WP_Post $post ) {
        $payload = array(
            'id' => (string) $post->ID,
            'title' => get_the_title( $post ),
            'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
            'url' => get_permalink( $post ),
            'updated_at' => get_post_modified_time( DATE_RFC3339, true, $post ),
        );
        return apply_filters( 'boatingschool_mobile_course_payload', $payload, $post, get_current_user_id() );
    }

    public static function courses( WP_REST_Request $request ) {
        $types = self::course_post_types();
        if ( ! $types ) return rest_ensure_response( array( 'data' => array() ) );
        $query = new WP_Query( array(
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) ),
            'paged' => max( 1, absint( $request->get_param( 'page' ) ?: 1 ) ),
            'no_found_rows' => true,
        ) );
        return rest_ensure_response( array( 'data' => array_map( array( __CLASS__, 'course_payload' ), $query->posts ) ) );
    }

    public static function course( WP_REST_Request $request ) {
        $post = get_post( absint( $request['courseId'] ) );
        if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::course_post_types(), true ) ) {
            return new WP_Error( 'NOT_FOUND', 'Kurs nicht gefunden.', array( 'status' => 404 ) );
        }
        if ( ! current_user_can( 'read_post', $post->ID ) ) {
            return new WP_Error( 'FORBIDDEN', 'Kein Zugriff auf diesen Kurs.', array( 'status' => 403 ) );
        }
        return rest_ensure_response( array( 'data' => self::course_payload( $post ) ) );
    }
}

BoatingSchool_Mobile_API::init();

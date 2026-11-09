# BoatingSchool Mobile App – WordPress handoff

This package carries forward the supplied concept, UI prototype, and OpenAPI contract and adds a small WordPress REST API plugin foundation.

## Install

Copy `wp-plugin/boatingschool-mobile-api/boatingschool-mobile-api.php` to `wp-content/plugins/boatingschool-mobile-api/`, activate **BoatingSchool Mobile API**, and serve the WordPress site over HTTPS. The namespace is `wp-json/boatingschool/v1`.

## Implemented routes

| Method | Route | Purpose |
|---|---|---|
| POST | `/auth/login` | WordPress username/password login; returns a revocable bearer token (12-hour expiry) |
| POST | `/auth/logout` | Revoke the current bearer token |
| GET | `/me` | Current WordPress user |
| GET | `/courses` | Published courses, default post types `sfwd-courses` and `course` |
| GET | `/courses/{courseId}` | Published course detail; checks `read_post` capability |

Requests use `Authorization: Bearer <access_token>`. Login body: `{"username":"…","password":"…"}`. Course lists accept `page` and `per_page` (maximum 50).

## Connect the WordPress course model

The plugin intentionally does not assume a specific LMS schema or grant purchase access. It only exposes published posts of the configured course post types. Configure post types with `boatingschool_mobile_course_post_types` and enrich output with `boatingschool_mobile_course_payload`. Add the LMS-specific entitlement check before exposing paid course content; implement it in the host plugin or a site-specific add-on. The default WordPress `read_post` check alone is not a paid-course authorization system.

## Scope and deployment notes

This is an integration foundation, not the full mobile API described by the supplied OpenAPI file. Attempts, exam sessions, favorites, progress, memberships/tenants, refresh-token rotation, password recovery, and LMS entitlements need adapters to the installed WP/LMS data model before an app release. Tokens are stored as hashed transient keys and expire after 12 hours; persistent storage and per-device session management should replace this for production-scale deployments. Use HTTPS, rate-limit login at the edge, and avoid logging credentials or bearer tokens.

The supplied OpenAPI contract retains its original `/api/v1` paths and is the product target contract. This plugin currently exposes the small subset above under WordPress's `/wp-json/boatingschool/v1` namespace. Keep these contracts aligned as routes are implemented.

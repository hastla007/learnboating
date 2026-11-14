=== BoatingSchool Mobile API ===
Contributors: hastla007
Tags: boating, learning, rest-api
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

Versioned WordPress REST API and learner content model for the BoatingSchool mobile app.

== Installation ==
1. Upload this directory as a plugin or copy it to wp-content/plugins/boatingschool-mobile-api.
2. Activate BoatingSchool Mobile API in Plugins. Activation installs the mobile-session table.
3. Configure tenant slug, shop URL and optional one-time access codes in Settings > BoatingSchool API.
4. Create/publish courses, modules, questions, lessons and navigation tasks. See README-WP-API.md in the project docs for field formats.
5. Use HTTPS and ensure the server forwards the Authorization header to PHP.

== API ==
Base path: /wp-json/boatingschool/v1
OpenAPI document: openapi-wordpress-mobile-v1.yaml in the project docs.

== Security ==
Access tokens are short-lived and stored as hashes. Refresh tokens rotate with 30-day inactivity and 90-day absolute expiry. Login is rate limited per source IP. LMS-specific entitlements are checked through LearnDash when installed; otherwise configure a course as free or grant it through one-time codes.

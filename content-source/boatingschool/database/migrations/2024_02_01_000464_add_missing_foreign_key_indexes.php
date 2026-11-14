<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2: 69 Fremdschlüssel-Spalten (von 105 insgesamt) hatten keinen
 * eigenen Index -- weder JOINs/WHERE-Filter auf diese Spalten noch die
 * beim Löschen der referenzierten Zeile anfallende FK-Prüfung (Postgres
 * sucht dafür ohne Index per Sequential Scan über die GESAMTE Tabelle)
 * konnten den Index nutzen. Per information_schema/pg_index ermittelt:
 * jede Spalte, die Teil einer FOREIGN KEY-Constraint ist, aber nicht die
 * führende Spalte irgendeines bestehenden Index auf derselben Tabelle
 * ist (zusammengesetzte FKs gibt es in diesem Schema nicht).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempt', function (Blueprint $table) {
            $table->index('course_id');
            $table->index('question_id');
            $table->index('revision_id');
            $table->index('selected_answer_id');
            $table->index('user_id');
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->index('actor_user_id');
        });

        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->index('reviewed_by');
        });

        Schema::table('coupon', function (Blueprint $table) {
            $table->index('course_id');
            $table->index('created_by_user_id');
            $table->index('product_id');
            $table->index('redeemed_by_user_id');
            $table->index('redeemed_tenant_id');
            $table->index('tenant_id');
        });

        Schema::table('coupon_option_product', function (Blueprint $table) {
            $table->index('product_id');
        });

        Schema::table('course_module', function (Blueprint $table) {
            $table->index('module_id');
        });

        Schema::table('course_webshop_link', function (Blueprint $table) {
            $table->index('course_id');
        });

        Schema::table('entitlement', function (Blueprint $table) {
            $table->index('course_id');
            $table->index('user_id');
        });

        Schema::table('exam_blueprint', function (Blueprint $table) {
            $table->index('module_id');
        });

        Schema::table('exam_paper_question', function (Blueprint $table) {
            $table->index('question_id');
        });

        Schema::table('exam_rule_set', function (Blueprint $table) {
            $table->index('verified_by');
        });

        Schema::table('exam_session', function (Blueprint $table) {
            $table->index('course_id');
            $table->index('paper_id');
            $table->index('rule_set_id');
            $table->index('user_id');
        });

        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->index('question_id');
            $table->index('revision_id');
            $table->index('selected_answer_id');
        });

        Schema::table('favorite', function (Blueprint $table) {
            $table->index('question_id');
            $table->index('user_id');
        });

        Schema::table('favorite_collection', function (Blueprint $table) {
            $table->index('course_id');
            $table->index('user_id');
        });

        Schema::table('favorite_collection_item', function (Blueprint $table) {
            $table->index('question_id');
            $table->index('tenant_id');
        });

        Schema::table('navigation_question', function (Blueprint $table) {
            $table->index('navigation_task_id');
        });

        Schema::table('navigation_task', function (Blueprint $table) {
            $table->index('course_id');
        });

        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->index('proposed_by_user_id');
            $table->index('tenant_course_location_id');
            $table->index('tenant_id');
        });

        Schema::table('praxis_form_submission', function (Blueprint $table) {
            $table->index('course_id');
            $table->index('praxis_form_template_id');
            $table->index('user_id');
        });

        Schema::table('praxis_progress', function (Blueprint $table) {
            $table->index('praxis_task_id');
            $table->index('user_id');
        });

        Schema::table('praxis_task', function (Blueprint $table) {
            $table->index('course_id');
            $table->index('media_asset_id');
        });

        Schema::table('product_purchase', function (Blueprint $table) {
            $table->index('product_id');
            $table->index('tenant_id');
            $table->index('user_id');
        });

        Schema::table('progress', function (Blueprint $table) {
            $table->index('question_id');
            $table->index('user_id');
        });

        Schema::table('question_media', function (Blueprint $table) {
            $table->index('media_asset_id');
        });

        Schema::table('tenant_addon', function (Blueprint $table) {
            $table->index('addon_template_id');
            $table->index('trigger_course_id');
        });

        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->index('favicon_asset_id');
            $table->index('logo_asset_id');
        });

        Schema::table('tenant_course_disabled', function (Blueprint $table) {
            $table->index('course_id');
        });

        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->index('pruefungsausschuss_id');
            $table->index('tenant_id');
        });

        Schema::table('tenant_invitation', function (Blueprint $table) {
            $table->index('invited_by_user_id');
            $table->index('tenant_id');
        });

        Schema::table('tip', function (Blueprint $table) {
            $table->index('category_id');
            $table->index('pdf_asset_id');
        });

        Schema::table('video_lesson', function (Blueprint $table) {
            $table->index('video_module_id');
        });

        Schema::table('video_lesson_step', function (Blueprint $table) {
            $table->index('video_lesson_id');
        });

        Schema::table('video_module', function (Blueprint $table) {
            $table->index('course_id');
        });

        Schema::table('video_progress', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('video_lesson_id');
        });

        Schema::table('willkommensbox_order', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('attempt', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['question_id']);
            $table->dropIndex(['revision_id']);
            $table->dropIndex(['selected_answer_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex(['actor_user_id']);
        });

        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->dropIndex(['reviewed_by']);
        });

        Schema::table('coupon', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['created_by_user_id']);
            $table->dropIndex(['product_id']);
            $table->dropIndex(['redeemed_by_user_id']);
            $table->dropIndex(['redeemed_tenant_id']);
            $table->dropIndex(['tenant_id']);
        });

        Schema::table('coupon_option_product', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
        });

        Schema::table('course_module', function (Blueprint $table) {
            $table->dropIndex(['module_id']);
        });

        Schema::table('course_webshop_link', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
        });

        Schema::table('entitlement', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('exam_blueprint', function (Blueprint $table) {
            $table->dropIndex(['module_id']);
        });

        Schema::table('exam_paper_question', function (Blueprint $table) {
            $table->dropIndex(['question_id']);
        });

        Schema::table('exam_rule_set', function (Blueprint $table) {
            $table->dropIndex(['verified_by']);
        });

        Schema::table('exam_session', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['paper_id']);
            $table->dropIndex(['rule_set_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->dropIndex(['question_id']);
            $table->dropIndex(['revision_id']);
            $table->dropIndex(['selected_answer_id']);
        });

        Schema::table('favorite', function (Blueprint $table) {
            $table->dropIndex(['question_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('favorite_collection', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('favorite_collection_item', function (Blueprint $table) {
            $table->dropIndex(['question_id']);
            $table->dropIndex(['tenant_id']);
        });

        Schema::table('navigation_question', function (Blueprint $table) {
            $table->dropIndex(['navigation_task_id']);
        });

        Schema::table('navigation_task', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
        });

        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->dropIndex(['proposed_by_user_id']);
            $table->dropIndex(['tenant_course_location_id']);
            $table->dropIndex(['tenant_id']);
        });

        Schema::table('praxis_form_submission', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['praxis_form_template_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('praxis_progress', function (Blueprint $table) {
            $table->dropIndex(['praxis_task_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('praxis_task', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
            $table->dropIndex(['media_asset_id']);
        });

        Schema::table('product_purchase', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('progress', function (Blueprint $table) {
            $table->dropIndex(['question_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('question_media', function (Blueprint $table) {
            $table->dropIndex(['media_asset_id']);
        });

        Schema::table('tenant_addon', function (Blueprint $table) {
            $table->dropIndex(['addon_template_id']);
            $table->dropIndex(['trigger_course_id']);
        });

        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropIndex(['favicon_asset_id']);
            $table->dropIndex(['logo_asset_id']);
        });

        Schema::table('tenant_course_disabled', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
        });

        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->dropIndex(['pruefungsausschuss_id']);
            $table->dropIndex(['tenant_id']);
        });

        Schema::table('tenant_invitation', function (Blueprint $table) {
            $table->dropIndex(['invited_by_user_id']);
            $table->dropIndex(['tenant_id']);
        });

        Schema::table('tip', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
            $table->dropIndex(['pdf_asset_id']);
        });

        Schema::table('video_lesson', function (Blueprint $table) {
            $table->dropIndex(['video_module_id']);
        });

        Schema::table('video_lesson_step', function (Blueprint $table) {
            $table->dropIndex(['video_lesson_id']);
        });

        Schema::table('video_module', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
        });

        Schema::table('video_progress', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['video_lesson_id']);
        });

        Schema::table('willkommensbox_order', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};

<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * CiviCRM core's table names — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-schema-catalog.php <core-dir>
 *
 * Generated from CiviCRM 6.20.0 out of the schema/*.entityType.php
 * declarations of core and its core-shipped extensions. The SQL rule uses
 * this to reject a `civicrm_`-prefixed table name that no core release and
 * no repo-local schema knows.
 */
final class SchemaCatalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '6.20.0';

    /**
     * Table names core creates.
     *
     * @var list<string>
     */
    public const TABLES = [
        'civicrm_acl', 'civicrm_acl_cache', 'civicrm_acl_contact_cache',
        'civicrm_acl_entity_role', 'civicrm_action_log', 'civicrm_action_schedule',
        'civicrm_activity', 'civicrm_activity_contact', 'civicrm_address',
        'civicrm_address_format', 'civicrm_afform_submission', 'civicrm_batch',
        'civicrm_cache', 'civicrm_campaign', 'civicrm_campaign_group',
        'civicrm_case', 'civicrm_case_activity', 'civicrm_case_contact',
        'civicrm_case_type', 'civicrm_component', 'civicrm_contact',
        'civicrm_contact_type', 'civicrm_contribution', 'civicrm_contribution_page',
        'civicrm_contribution_product', 'civicrm_contribution_recur', 'civicrm_contribution_soft',
        'civicrm_contribution_widget', 'civicrm_country', 'civicrm_county',
        'civicrm_currency', 'civicrm_custom_field', 'civicrm_custom_group',
        'civicrm_dashboard', 'civicrm_dashboard_contact', 'civicrm_dedupe_exception',
        'civicrm_dedupe_rule', 'civicrm_dedupe_rule_group', 'civicrm_discount',
        'civicrm_domain', 'civicrm_email', 'civicrm_email_message',
        'civicrm_entity_batch', 'civicrm_entity_file', 'civicrm_entity_financial_account',
        'civicrm_entity_financial_trxn', 'civicrm_entity_tag', 'civicrm_event',
        'civicrm_event_cart_participant', 'civicrm_event_carts', 'civicrm_events_in_carts',
        'civicrm_extension', 'civicrm_file', 'civicrm_financial_account',
        'civicrm_financial_item', 'civicrm_financial_trxn', 'civicrm_financial_type',
        'civicrm_grant', 'civicrm_group', 'civicrm_group_contact',
        'civicrm_group_contact_cache', 'civicrm_group_nesting', 'civicrm_group_organization',
        'civicrm_im', 'civicrm_job', 'civicrm_job_log',
        'civicrm_line_item', 'civicrm_loc_block', 'civicrm_location_type',
        'civicrm_log', 'civicrm_mail_settings', 'civicrm_mailing',
        'civicrm_mailing_abtest', 'civicrm_mailing_bounce_pattern', 'civicrm_mailing_bounce_type',
        'civicrm_mailing_component', 'civicrm_mailing_event_bounce', 'civicrm_mailing_event_confirm',
        'civicrm_mailing_event_delivered', 'civicrm_mailing_event_opened', 'civicrm_mailing_event_queue',
        'civicrm_mailing_event_reply', 'civicrm_mailing_event_subscribe', 'civicrm_mailing_event_trackable_url_open',
        'civicrm_mailing_event_unsubscribe', 'civicrm_mailing_group', 'civicrm_mailing_job',
        'civicrm_mailing_recipients', 'civicrm_mailing_spool', 'civicrm_mailing_trackable_url',
        'civicrm_managed', 'civicrm_mapping', 'civicrm_mapping_field',
        'civicrm_membership', 'civicrm_membership_block', 'civicrm_membership_log',
        'civicrm_membership_payment', 'civicrm_membership_status', 'civicrm_membership_type',
        'civicrm_menu', 'civicrm_msg_template', 'civicrm_navigation',
        'civicrm_note', 'civicrm_oauth_client', 'civicrm_oauth_contact_token',
        'civicrm_oauth_systoken', 'civicrm_openid', 'civicrm_option_group',
        'civicrm_option_value', 'civicrm_order_completion_metadata', 'civicrm_participant',
        'civicrm_participant_payment', 'civicrm_participant_status_type', 'civicrm_payment_processor',
        'civicrm_payment_processor_type', 'civicrm_payment_token', 'civicrm_pcp',
        'civicrm_pcp_block', 'civicrm_phone', 'civicrm_pledge',
        'civicrm_pledge_block', 'civicrm_pledge_payment', 'civicrm_preferences_date',
        'civicrm_premiums', 'civicrm_premiums_product', 'civicrm_prevnext_cache',
        'civicrm_price_field', 'civicrm_price_field_value', 'civicrm_price_set',
        'civicrm_price_set_entity', 'civicrm_print_label', 'civicrm_product',
        'civicrm_queue', 'civicrm_queue_item', 'civicrm_recurring_entity',
        'civicrm_relationship', 'civicrm_relationship_cache', 'civicrm_relationship_type',
        'civicrm_report_instance', 'civicrm_riverlea_stream', 'civicrm_role',
        'civicrm_saved_search', 'civicrm_search_display', 'civicrm_search_param_set',
        'civicrm_search_segment', 'civicrm_session', 'civicrm_setting',
        'civicrm_site_email_address', 'civicrm_site_token', 'civicrm_sms_provider',
        'civicrm_state_province', 'civicrm_status_pref', 'civicrm_subscription_history',
        'civicrm_survey', 'civicrm_system_log', 'civicrm_tag',
        'civicrm_tell_friend', 'civicrm_timezone', 'civicrm_totp',
        'civicrm_translation', 'civicrm_translation_source', 'civicrm_uf_field',
        'civicrm_uf_group', 'civicrm_uf_join', 'civicrm_uf_match',
        'civicrm_user_job', 'civicrm_user_role', 'civicrm_website',
        'civicrm_word_replacement', 'civicrm_worldregion',
    ];
}

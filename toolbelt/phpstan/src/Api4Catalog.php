<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * The APIv4 contract of CiviCRM core — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-api4-catalog.php <core-dir>
 *
 * Per entity: the actions it answers to and the field names a source tree
 * can prove. `c` (complete) says whether the field list may be used to
 * reject an unknown name. It is false wherever the truth only exists on a
 * live site — entities without a schema definition, and everything custom
 * fields, ECK or SearchKit add at runtime. Api4ContractRule checks fields
 * only where `c` is true, and never checks a name containing a dot.
 */
final class Api4Catalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '6.20.0';

    /**
     * Entity name => actions, fields, field-list completeness.
     *
     * Space-separated strings rather than nested lists: this is a few
     * thousand names, and one line per entity stays diffable.
     *
     * @var array<string, array{a: string, f: string, c: bool}>
     */
    public const ENTITIES = [
        'ACL' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'acl_id acl_table deny entity_id entity_table id is_active name object_id object_table operation priority', 'c' => true],
        'ACLEntityRole' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'acl_role_id entity_id entity_table id is_active', 'c' => true],
        'ActionSchedule' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'absolute_date body_html body_text communication_language created_date effective_end_date effective_start_date end_action end_date end_frequency_interval end_frequency_unit entity_status entity_value filter_contact_language from_email from_name group_id id is_active is_repeat limit_to mapping_id mode modified_date msg_template_id name recipient recipient_listing recipient_manual record_activity repetition_frequency_interval repetition_frequency_unit sms_body_text sms_provider_id sms_template_id start_action_condition start_action_date start_action_offset start_action_unit subject title used_for', 'c' => true],
        'Activity' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => '_depth _descendents activity_date_time activity_type_id all_contact_id assignee_contact_count assignee_contact_id campaign_id case_id created_date details duration engagement_level id is_auto is_current_revision is_deleted is_star is_test location medium_id modified_date original_id parent_id phone_id phone_number priority_id relationship_id result source_contact_id source_record_id status_id subject tags target_contact_count target_contact_id weight', 'c' => true],
        'ActivityContact' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'activity_id contact_id id record_type_id', 'c' => true],
        'Address' => ['a' => 'autocomplete checkAccess create delete get getActions getCoordinates getFields getLinks replace save update', 'f' => 'city contact_id country_id county_id geo_code_1 geo_code_2 id is_billing is_primary location_type_id manual_geo_code master_id name postal_code postal_code_suffix proximity state_province_id street_address street_name street_number street_number_postdirectional street_number_predirectional street_number_suffix street_type street_unit supplemental_address_1 supplemental_address_2 supplemental_address_3 timezone usps_adc', 'c' => true],
        'Afform' => ['a' => 'autocomplete checkAccess convert create get getActions getFields getLinks getOptions loadAdminData prefill process revert save submit submitDraft submitFile translate update validate', 'f' => 'tags', 'c' => false],
        'AfformBehavior' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'AfformSubmission' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'afform_name contact_id data id status_id submission_date', 'c' => true],
        'AfformSubmissionData' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'AuthxCredential' => ['a' => 'checkAccess create getActions getFields getLinks validate', 'f' => '', 'c' => false],
        'Batch' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'created_date created_id data description exported_date id item_count mode_id modified_date modified_id name payment_instrument_id saved_search_id status_id title total type_id', 'c' => true],
        'BouncePattern' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'bounce_type_id id pattern', 'c' => true],
        'BounceType' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'description hold_threshold id name', 'c' => true],
        'Campaign' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => '_depth _descendents campaign_type_id created_date created_id description end_date external_identifier goal_general goal_revenue id is_active is_current last_modified_date last_modified_id name parent_id start_date status_id title', 'c' => true],
        'Case' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'case_manager_id case_type_id contact_id created_date creator_id details duration end_date id is_deleted is_my_case is_my_managed_case location medium_id modified_date my_case_role start_date status_id subject tags', 'c' => true],
        'CaseActivity' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'activity_id case_id id', 'c' => true],
        'CaseContact' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'case_id contact_id id', 'c' => true],
        'CaseType' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'definition description id is_active is_reserved name title weight', 'c' => true],
        'Contact' => ['a' => 'autocomplete checkAccess create delete get getActions getChecksum getDuplicates getFields getLinks getMergedFrom getMergedTo mergeDuplicates replace save update validateChecksum', 'f' => 'address_billing address_primary addressee_custom addressee_display addressee_id age_years api_key birth_date communication_style_id contact_names contact_sub_type contact_type created_date deceased_date display_name do_not_email do_not_mail do_not_phone do_not_sms do_not_trade email_billing email_greeting_custom email_greeting_display email_greeting_id email_primary employer_id external_identifier first_name formal_title gender_id groups hash household_name id im_billing im_primary image_URL is_deceased is_deleted is_opt_out job_title last_name legal_identifier legal_name middle_name modified_date next_birthday nick_name organization_name phone_billing phone_primary postal_greeting_custom postal_greeting_display postal_greeting_id preferred_communication_method preferred_language preferred_mail_format prefix_id primary_contact_id sic_code sort_name source suffix_id tags user_unique_id', 'c' => true],
        'ContactType' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => '_depth _descendents description icon id image_URL is_active is_reserved label name parent_id', 'c' => true],
        'Contribution' => ['a' => 'autocomplete checkAccess continueCheckout create delete get getActions getFields getLinks replace save update', 'f' => 'address_id amount_level balance_amount campaign_id cancel_date cancel_reason check_number checkout_option checkout_params contact_id contribution_page_id contribution_recur_id contribution_status_id created_date creditnote_id currency fee_amount financial_type_id id invoice_id invoice_number is_pay_later is_template is_test modified_date net_amount non_deductible_amount paid_amount payment_instrument_id receipt_date receive_date recur_period revenue_recognition_date source tax_amount tax_exclusive_amount thankyou_date total_amount trxn_id', 'c' => true],
        'ContributionPage' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'adjust_recur_start_date amount_block_is_active bcc_receipt campaign_id cc_receipt created_date created_id currency default_amount_id end_date financial_type_id footer_text frontend_title goal_amount id initial_amount_help_text initial_amount_label intro_text is_active is_allow_other_amount is_billing_required is_confirm_enabled is_credit_card_only is_email_receipt is_monetary is_partial_payment is_pay_later is_recur is_recur_installments is_recur_interval is_share max_amount min_amount min_initial_amount name pay_later_receipt pay_later_text payment_processor receipt_from_email receipt_from_name receipt_text recur_frequency_unit start_date thankyou_footer thankyou_mode thankyou_redirect_url thankyou_text thankyou_title title', 'c' => true],
        'ContributionProduct' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'comment contribution_id end_date financial_type_id fulfilled_date id product_id product_option quantity start_date', 'c' => true],
        'ContributionRecur' => ['a' => 'autocomplete cancelSubscription checkAccess create delete get getActions getFields getLinks replace save update updateAmountOnRecur', 'f' => 'amount auto_renew campaign_id cancel_date cancel_reason contact_id contribution_status_id create_date currency cycle_day end_date failure_count failure_retry_date financial_type_id frequency_interval frequency_unit id installments invoice_id is_email_receipt is_test modified_date next_sched_contribution_date payment_instrument_id payment_processor_id payment_token_id processor_id start_date trxn_id', 'c' => true],
        'ContributionSoft' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'amount contact_id contribution_id currency id pcp_display_in_roll pcp_id pcp_personal_note pcp_roll_nickname soft_credit_type_id', 'c' => true],
        'Country' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'address_format_id country_code id idd_prefix is_active is_province_abbreviated iso_code name ndd_prefix region_id', 'c' => true],
        'County' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'abbreviation id is_active name state_province_id', 'c' => true],
        'Currency' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'full_name id name numeric_code symbol', 'c' => true],
        'CustomField' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'attributes column_name control_field custom_group_id data_type date_format default_value end_date_years file_is_public filter fk_entity fk_entity_on_delete help_post help_pre html_type id in_selector is_active is_required is_search_range is_searchable is_view label name note_columns note_rows option_group_id option_values options_per_line serialize start_date_years text_length time_format weight', 'c' => true],
        'CustomGroup' => ['a' => 'autocomplete checkAccess create delete export get getActions getAfforms getFields getLinks getSearchKit replace revert save update', 'f' => 'collapse_adv_display collapse_display created_date created_id extends extends_entity_column_id extends_entity_column_value help_post help_pre icon id is_active is_multiple is_public is_reserved max_multiple min_multiple name style table_name title weight', 'c' => true],
        'CustomValue' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => '', 'c' => false],
        'Dashboard' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'cache_minutes directive domain_id fullscreen_url id is_active is_reserved label name permission permission_operator url', 'c' => true],
        'DashboardContact' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks initialize replace save update', 'f' => 'column_no contact_id dashboard_id id is_active weight', 'c' => true],
        'DedupeException' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id1 contact_id2 id', 'c' => true],
        'DedupeRule' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'dedupe_rule_group_id id rule_field rule_length rule_table rule_weight', 'c' => true],
        'DedupeRuleGroup' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'contact_type id is_reserved name threshold title used', 'c' => true],
        'Discount' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'end_date entity_id entity_table id price_set_id start_date', 'c' => true],
        'Domain' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id description id is_active locale_custom_strings locales name version', 'c' => true],
        'Email' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id email hold_date id is_billing is_bulkmail is_primary location_type_id on_hold reset_date signature_html signature_text', 'c' => true],
        'EmailMessage' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save send update', 'f' => 'body created_id date_created date_sent error_message extra from_site_email_address_id id location_type subject to_contact_id', 'c' => true],
        'Entity' => ['a' => 'autocomplete checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'EntityBatch' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'batch_id entity_id entity_table id', 'c' => true],
        'EntityFile' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'entity_id entity_table file_id id', 'c' => true],
        'EntityFinancialAccount' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'account_relationship entity_id entity_table financial_account_id id', 'c' => true],
        'EntityFinancialTrxn' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'amount entity_id entity_table financial_trxn_id id', 'c' => true],
        'EntitySet' => ['a' => 'autocomplete checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'EntityTag' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'entity_id entity_table id tag_id', 'c' => true],
        'Event' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'allow_same_participant_emails allow_selfcancelxfer approval_req_text bcc_confirm campaign_id cc_confirm confirm_email_text confirm_footer_text confirm_from_email confirm_from_name confirm_text confirm_title created_date created_id currency dedupe_rule_group_id default_discount_fee_id default_fee_id default_role_id description end_date event_full_text event_type_id expiration_time fee_label financial_type_id footer_text has_waitlist id initial_amount_help_text initial_amount_label intro_text is_active is_billing_required is_confirm_enabled is_current is_email_confirm is_map is_monetary is_multiple_registrations is_online_registration is_partial_payment is_pay_later is_public is_share is_show_calendar_links is_show_location is_template loc_block_id max_additional_participants max_participants min_initial_amount parent_event_id participant_listing_id pay_later_receipt pay_later_text payment_processor registration_end_date registration_link_text registration_start_date remaining_participants requires_approval selfcancelxfer_time slot_label_id start_date summary tags template_id template_title thankyou_footer_text thankyou_text thankyou_title title waitlist_text', 'c' => true],
        'EventCartParticipant' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'cart_id id participant_id', 'c' => true],
        'ExampleData' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'Extension' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => 'file full_name id is_active label name schema_version type', 'c' => false],
        'File' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'content created_id description document file_name file_type_id icon id is_image is_public mime_type move_file tags upload_date uri url', 'c' => true],
        'FinancialAccount' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'account_type_code accounting_code contact_id description financial_account_type_id id is_active is_deductible is_default is_header_account is_reserved is_tax label name parent_id tax_rate', 'c' => true],
        'FinancialItem' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'amount contact_id created_date currency description entity_id entity_table financial_account_id id status_id transaction_date', 'c' => true],
        'FinancialTrxn' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'card_type_id check_number currency entity_id fee_amount from_financial_account_id id is_payment net_amount order_reference pan_truncation payment_instrument_id payment_processor_id status_id to_financial_account_id total_amount trxn_date trxn_id trxn_result_code', 'c' => true],
        'FinancialType' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'description id is_active is_deductible is_reserved label name', 'c' => true],
        'Grant' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'amount_granted amount_requested amount_total application_received_date contact_id currency decision_date financial_type_id grant_due_date grant_report_received grant_type_id id money_transfer_date rationale status_id', 'c' => true],
        'Group' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks refresh replace revert save update', 'f' => '_depth _descendents cache_date cache_expired cache_fill_took children contact_count created_id description frontend_description frontend_title group_type id is_active is_hidden is_reserved modified_id name parents refresh_date saved_search_id select_tables source title visibility where_clause where_tables', 'c' => true],
        'GroupContact' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id email_id group_id id location_id status', 'c' => true],
        'GroupNesting' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'child_group_id id parent_group_id', 'c' => true],
        'GroupOrganization' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'group_id id organization_id', 'c' => true],
        'GroupSubscription' => ['a' => 'checkAccess create get getActions getFields getLinks save update', 'f' => '', 'c' => false],
        'Household' => ['a' => 'autocomplete checkAccess create delete get getActions getChecksum getDuplicates getFields getLinks getMergedFrom getMergedTo mergeDuplicates replace save update validateChecksum', 'f' => 'address_billing address_primary addressee_custom addressee_display addressee_id age_years api_key birth_date communication_style_id contact_names contact_sub_type contact_type created_date deceased_date display_name do_not_email do_not_mail do_not_phone do_not_sms do_not_trade email_billing email_greeting_custom email_greeting_display email_greeting_id email_primary employer_id external_identifier first_name formal_title gender_id groups hash household_name id im_billing im_primary image_URL is_deceased is_deleted is_opt_out job_title last_name legal_identifier legal_name middle_name modified_date next_birthday nick_name organization_name phone_billing phone_primary postal_greeting_custom postal_greeting_display postal_greeting_id preferred_communication_method preferred_language preferred_mail_format prefix_id primary_contact_id sic_code sort_name source suffix_id tags user_unique_id', 'c' => true],
        'IM' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id id is_billing is_primary location_type_id name provider_id', 'c' => true],
        'Iframe' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks installScript renderScript replace save update', 'f' => '', 'c' => false],
        'Import' => ['a' => 'checkAccess create delete get getActions getFields getLinks import replace save update validate', 'f' => '', 'c' => false],
        'Individual' => ['a' => 'autocomplete checkAccess create delete get getActions getChecksum getDuplicates getFields getLinks getMergedFrom getMergedTo mergeDuplicates replace save update validateChecksum', 'f' => 'address_billing address_primary addressee_custom addressee_display addressee_id age_years api_key birth_date communication_style_id contact_names contact_sub_type contact_type created_date deceased_date display_name do_not_email do_not_mail do_not_phone do_not_sms do_not_trade email_billing email_greeting_custom email_greeting_display email_greeting_id email_primary employer_id external_identifier first_name formal_title gender_id groups hash household_name id im_billing im_primary image_URL is_deceased is_deleted is_opt_out job_title last_name legal_identifier legal_name middle_name modified_date next_birthday nick_name organization_name phone_billing phone_primary postal_greeting_custom postal_greeting_display postal_greeting_id preferred_communication_method preferred_language preferred_mail_format prefix_id primary_contact_id sic_code sort_name source suffix_id tags user_unique_id', 'c' => true],
        'Job' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'api_action api_entity description domain_id id is_active last_run last_run_end name parameters run_frequency scheduled_run_date', 'c' => true],
        'JobLog' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'command data description domain_id id job_id name run_time', 'c' => true],
        'LineItem' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contribution_id created_date entity_id entity_table financial_type_id id label line_total membership_num_terms modified_date non_deductible_amount participant_count price_field_id price_field_value_id qty tax_amount unit_price', 'c' => true],
        'LocBlock' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'address_2_id address_id email_2_id email_id id im_2_id im_id phone_2_id phone_id', 'c' => true],
        'LocationType' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'description display_name id is_active is_default is_reserved name vcard_name', 'c' => true],
        'Log' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'data entity_id entity_table id modified_date modified_id', 'c' => true],
        'MailSettings' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save testConnection update', 'f' => 'activity_assignees activity_source activity_status activity_targets activity_type_id campaign_id domain domain_id id is_active is_contact_creation_disabled_if_no_match is_default is_non_case_email_skipped is_ssl localpart name password port protocol return_path server source username', 'c' => true],
        'Mailing' => ['a' => 'autocomplete checkAccess create createAction delete get getActions getFields getLinks processQueue replace runQueue save saveAction update updateAction', 'f' => 'approval_date approval_note approval_status_id approver_id auto_responder body_html body_text campaign_id created_date created_id dedupe_email domain_id email_selection_method end_date footer_id forward_replies from_email from_name hash header_id id is_archived is_completed language location_type_id mailing_type modified_date msg_template_id name open_tracking optout_id override_verp reply_id replyto_email resubscribe_id scheduled_date scheduled_id sms_provider_id start_date stats_bounces stats_clicks_total stats_clicks_unique stats_intended_recipients stats_opens_total stats_opens_unique stats_optouts stats_optouts_and_unsubscribes stats_replies stats_successful stats_unsubscribes status subject template_options template_type unsubscribe_id unsubscribe_mode url_tracking visibility', 'c' => true],
        'MailingAB' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'created_date created_id declare_winning_time domain_id group_percentage id mailing_id_a mailing_id_b mailing_id_c name specific_url status testing_criteria winner_criteria', 'c' => true],
        'MailingComponent' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'body_html body_text component_type id is_active is_default name subject', 'c' => true],
        'MailingEventBounce' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'bounce_reason bounce_type_id event_queue_id id time_stamp', 'c' => true],
        'MailingEventConfirm' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'event_subscribe_id id time_stamp', 'c' => true],
        'MailingEventDelivered' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'event_queue_id id time_stamp', 'c' => true],
        'MailingEventOpened' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'event_queue_id id time_stamp', 'c' => true],
        'MailingEventQueue' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id email_id hash id is_test job_id mailing_id phone_id', 'c' => true],
        'MailingEventReply' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'event_queue_id id time_stamp', 'c' => true],
        'MailingEventSubscribe' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id group_id hash id time_stamp', 'c' => true],
        'MailingEventTrackableURLOpen' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'event_queue_id id time_stamp trackable_url_id', 'c' => true],
        'MailingEventUnsubscribe' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'event_queue_id id org_unsubscribe time_stamp', 'c' => true],
        'MailingGroup' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'entity_id entity_table group_type id mailing_id search_args search_id', 'c' => true],
        'MailingJob' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'end_date id is_test job_limit job_offset job_type mailing_id parent_id scheduled_date start_date status', 'c' => true],
        'MailingTrackableURL' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'id mailing_id url', 'c' => true],
        'Managed' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks reconcile replace save update', 'f' => 'checksum cleanup entity_id entity_modified_date entity_type id module name', 'c' => true],
        'Mapping' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'description id mapping_type_id name', 'c' => true],
        'MappingField' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'column_number contact_type grouping id im_provider_id location_type_id mapping_id name operator phone_type_id relationship_direction relationship_type_id value website_type_id', 'c' => true],
        'Membership' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'campaign_id contact_id contribution_recur_id end_date id is_new is_override is_pay_later is_primary_member is_test join_date max_related membership_type_id owner_membership_id source start_date status_id status_override_end_date version', 'c' => true],
        'MembershipBlock' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'display_min_fee entity_id entity_table id is_active is_required is_separate_payment membership_type_default membership_types new_text new_title renewal_text renewal_title', 'c' => true],
        'MembershipLog' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'end_date id max_related membership_id membership_type_id modified_date modified_id start_date status_id', 'c' => true],
        'MembershipStatus' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'end_event end_event_adjust_interval end_event_adjust_unit id is_active is_admin is_current_member is_default is_new is_reserved label name start_event start_event_adjust_interval start_event_adjust_unit weight', 'c' => true],
        'MembershipType' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'auto_renew description domain_id duration_interval duration_unit financial_type_id fixed_period_rollover_day fixed_period_start_day frontend_title id is_active max_related member_of_contact_id minimum_fee name period_type receipt_text_renewal receipt_text_signup relationship_direction relationship_type_id relationship_type_label title visibility weight', 'c' => true],
        'MessageTemplate' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace revert save update', 'f' => 'id is_active is_default is_reserved is_sms keyword master_id msg_html msg_subject msg_text msg_title pdf_format_id tags translation_language workflow_id workflow_name', 'c' => true],
        'Navigation' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => '_depth _descendents domain_id has_separator icon id is_active label name parent_id permission permission_operator target url weight', 'c' => true],
        'Note' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => '_depth _descendents contact_id created_date entity_id entity_table id modified_date note note_date privacy subject', 'c' => true],
        'OAuthClient' => ['a' => 'authorizationCode autocomplete checkAccess clientCredential create delete export get getActions getFields getLinks replace revert save update userPassword', 'f' => 'created_date guid id is_active modified_date options provider secret tenant', 'c' => true],
        'OAuthContactToken' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'access_token client_id contact_id created_date error expires grant_type id modified_date raw refresh_token resource_owner resource_owner_name scopes tag token_type', 'c' => true],
        'OAuthProvider' => ['a' => 'checkAccess get getActions getFields getLinks getProviders', 'f' => '', 'c' => false],
        'OAuthSessionToken' => ['a' => 'checkAccess create delete get getActions getFields getLinks refresh update', 'f' => '', 'c' => false],
        'OAuthSysToken' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks refresh replace save update', 'f' => 'access_token client_id created_date error expires grant_type id modified_date raw refresh_token resource_owner resource_owner_name scopes tag token_type', 'c' => true],
        'OpenID' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'allowed_to_login contact_id id is_primary location_type_id openid', 'c' => true],
        'OptionGroup' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'data_type description id is_active is_locked is_reserved name option_value_fields title', 'c' => true],
        'OptionValue' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'color component_id description filter grouping icon id is_active is_default is_optgroup is_reserved label name option_group_id payment_instrument_fields value visibility_id weight', 'c' => true],
        'Order' => ['a' => 'checkAccess create getActions getFields getLinks', 'f' => '', 'c' => false],
        'OrderCompletionMetadata' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contribution_id id line_item_id metadata', 'c' => true],
        'Organization' => ['a' => 'autocomplete checkAccess create delete get getActions getChecksum getDuplicates getFields getLinks getMergedFrom getMergedTo mergeDuplicates replace save update validateChecksum', 'f' => 'address_billing address_primary addressee_custom addressee_display addressee_id age_years api_key birth_date communication_style_id contact_names contact_sub_type contact_type created_date deceased_date display_name do_not_email do_not_mail do_not_phone do_not_sms do_not_trade email_billing email_greeting_custom email_greeting_display email_greeting_id email_primary employer_id external_identifier first_name formal_title gender_id groups hash household_name id im_billing im_primary image_URL is_deceased is_deleted is_opt_out job_title last_name legal_identifier legal_name middle_name modified_date next_birthday nick_name organization_name phone_billing phone_primary postal_greeting_custom postal_greeting_display postal_greeting_id preferred_communication_method preferred_language preferred_mail_format prefix_id primary_contact_id sic_code sort_name source suffix_id tags user_unique_id', 'c' => true],
        'PCP' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id currency donate_link_text goal_amount id intro_text is_active is_honor_roll is_notify is_thermometer page_id page_text page_type pcp_block_id status_id title', 'c' => true],
        'PCPBlock' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'entity_id entity_table id is_active is_approval_needed is_tellfriend_enabled link_text notify_email owner_notify_id supporter_profile_id target_entity_id target_entity_type tellfriend_limit', 'c' => true],
        'Participant' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'campaign_id contact_id created_date created_id discount_amount discount_id event_id fee_amount fee_currency fee_level id is_pay_later is_test modified_date must_wait register_date registered_by_id role_id source status_id transferred_to_contact_id', 'c' => true],
        'ParticipantStatusType' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'class id is_active is_counted is_reserved label name visibility_id weight', 'c' => true],
        'Payment' => ['a' => 'checkAccess create get getActions getFields getLinks', 'f' => '', 'c' => false],
        'PaymentProcessor' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks refund replace save update', 'f' => 'accepted_credit_cards billing_mode class_name config description domain_id financial_account_id frontend_title id is_active is_default is_recur is_test name password payment_instrument_id payment_processor_type_id payment_type signature subject title url_api url_button url_recur url_site user_name', 'c' => true],
        'PaymentProcessorType' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'billing_mode class_name description id is_active is_default is_recur name password_label payment_instrument_id payment_type signature_label subject_label title url_api_default url_api_test_default url_button_default url_button_test_default url_recur_default url_recur_test_default url_site_default url_site_test_default user_name_label', 'c' => true],
        'PaymentToken' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'billing_first_name billing_last_name billing_middle_name contact_id created_date created_id email expiry_date id ip_address masked_account_number payment_processor_id token', 'c' => true],
        'Permission' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => '_depth _descendents', 'c' => false],
        'Phone' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id id is_billing is_primary location_type_id mobile_provider_id phone phone_ext phone_numeric phone_type_id', 'c' => true],
        'Pledge' => ['a' => 'autocomplete cancel checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'acknowledge_date additional_reminder_day amount campaign_id cancel_date contact_id contribution_page_id create_date currency end_date financial_type_id frequency_day frequency_interval frequency_unit id initial_reminder_day installments is_test max_reminders modified_date original_installment_amount start_date status_id', 'c' => true],
        'PledgeBlock' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'additional_reminder_day entity_id entity_table id initial_reminder_day is_pledge_interval is_pledge_start_date_editable is_pledge_start_date_visible max_reminders pledge_frequency_unit pledge_start_date', 'c' => true],
        'PledgePayment' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'actual_amount contribution_id currency id pledge_id reminder_count reminder_date scheduled_amount scheduled_date status_id', 'c' => true],
        'PreferencesDate' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'date_format description end id name start time_format', 'c' => true],
        'Premium' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'entity_id entity_table id premiums_active premiums_contact_email premiums_contact_phone premiums_display_min_contribution premiums_intro_text premiums_intro_title premiums_nothankyou_label premiums_nothankyou_position', 'c' => true],
        'PremiumsProduct' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'financial_type_id id premiums_id product_id weight', 'c' => true],
        'PriceField' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'active_on expire_on help_post help_pre html_type id is_active is_display_amounts is_enter_qty is_required javascript label name options_per_line price_set_id visibility_id weight', 'c' => true],
        'PriceFieldValue' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'amount count description financial_type_id help_post help_pre id is_active is_default label max_value membership_num_terms membership_type_id name non_deductible_amount price_field_id visibility_id weight', 'c' => true],
        'PriceSet' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'domain_id extends financial_type_id help_post help_pre id is_active is_quick_config is_reserved javascript min_amount name title', 'c' => true],
        'PriceSetEntity' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'entity_id entity_table id price_set_id', 'c' => true],
        'PrintLabel' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'created_id data description id is_active is_default is_reserved label_format_name label_type_id name title', 'c' => true],
        'Product' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'cost currency description duration_interval duration_unit financial_type_id fixed_period_start_day frequency_interval frequency_unit id image is_active min_contribution name options period_type price sku thumbnail', 'c' => true],
        'Queue' => ['a' => 'autocomplete checkAccess claimItems create delete export get getActions getFields getLinks replace reset revert run runItems save update', 'f' => 'batch_limit error id is_template lease_time name retry_interval retry_limit runner status type', 'c' => true],
        'QueueItem' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'data data_raw id queue_name release_time run_count submit_time weight', 'c' => true],
        'RecentItem' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => '', 'c' => false],
        'Relationship' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'case_id contact_id_a contact_id_b created_date description end_date id is_active is_current is_permission_a_b is_permission_b_a modified_date relationship_type_id start_date', 'c' => true],
        'RelationshipCache' => ['a' => 'checkAccess get getActions getFields getLinks rebuild', 'f' => 'case_id end_date far_contact_id far_relation id is_active is_current near_contact_id near_relation orientation relationship_id relationship_type_id start_date', 'c' => false],
        'RelationshipType' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'contact_sub_type_a contact_sub_type_b contact_type_a contact_type_b description id is_active is_reserved label_a_b label_b_a name_a_b name_b_a weight', 'c' => true],
        'Report' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'ReportInstance' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'args created_id description domain_id drilldown_id email_cc email_subject email_to footer form_values grouprole header id is_active is_reserved name navigation_id owner_id permission report_id title', 'c' => true],
        'RiverleaStream' => ['a' => 'activate autocomplete checkAccess create delete export get getActions getFields getLinks getWithFileContent render replace revert save update', 'f' => 'css_file css_file_dark custom_css custom_css_dark description extension file_prefix id is_reserved label modified_date name vars vars_dark', 'c' => true],
        'Role' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'id is_active label name permissions', 'c' => true],
        'RolePermission' => ['a' => 'checkAccess get getActions getFields getLinks save update', 'f' => '_depth _descendents', 'c' => false],
        'Route' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'SKEntity' => ['a' => 'checkAccess get getActions getFields getLinks getRefreshDate refresh', 'f' => '', 'c' => false],
        'SavedSearch' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'api_entity api_params created_date created_id description expires_date form_values id is_current is_template label mapping_id modified_date modified_id name search_custom_id tags timeout', 'c' => true],
        'SearchDisplay' => ['a' => 'autocomplete checkAccess create createBatch delete download emailReport export get getActions getDefault getFields getLinks getMarkup getSearchTasks importBatch inlineEdit replace revert run runBatch save saveFile update', 'f' => 'acl_bypass id is_autocomplete_default label name saved_search_id settings type', 'c' => true],
        'SearchParamSet' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'afform_name columns created_by created_date filters icon id label modified_date', 'c' => true],
        'SearchSegment' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'description entity_name id items label name', 'c' => true],
        'Session' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'data id last_accessed session_id', 'c' => true],
        'Setting' => ['a' => 'checkAccess get getActions getFields getLinks revert set', 'f' => 'component_id contact_id created_date created_id domain_id id is_domain name value', 'c' => false],
        'SiteEmailAddress' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'description display_name domain_id email id is_active is_default', 'c' => true],
        'SiteToken' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'body_html body_text created_id domain_id id is_active is_reserved label modified_date modified_id name', 'c' => true],
        'SmsProvider' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'api_params api_type api_url domain_id id is_active is_default name password title username', 'c' => true],
        'StateProvince' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'abbreviation country_id id is_active name', 'c' => true],
        'StatusPreference' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'check_info domain_id hush_until id ignore_severity is_active name prefs', 'c' => true],
        'SubscriptionHistory' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id date group_id id method status tracking', 'c' => true],
        'Survey' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'activity_type_id bypass_confirm campaign_id created_date created_id default_number_of_contacts id instructions is_active is_default is_share last_modified_date last_modified_id max_number_of_contacts release_frequency result_id thankyou_text thankyou_title title', 'c' => true],
        'System' => ['a' => 'check checkAccess flush getActions getFields getLinks rotateKey', 'f' => '', 'c' => false],
        'Tag' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks merge replace revert save update', 'f' => '_depth _descendents color created_date created_id description id is_reserved is_selectable is_tagset label name parent_id used_for', 'c' => true],
        'TaggedEntity' => ['a' => 'checkAccess get getActions getFields getLinks', 'f' => '', 'c' => false],
        'Totp' => ['a' => 'autocomplete checkAccess confirmSeed create delete get getActions getFields getLinks replace save update', 'f' => 'hash id length period seed user_id', 'c' => true],
        'Translation' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'entity_field entity_id entity_table id language source_key status_id string', 'c' => true],
        'TranslationSource' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'context_key entity entity_field entity_id id source source_key', 'c' => true],
        'UFField' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'field_name field_type help_post help_pre id in_selector is_active is_multi_summary is_required is_reserved is_searchable is_view label location_type_id phone_type_id uf_group_id visibility website_type_id weight', 'c' => true],
        'UFGroup' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'add_cancel_button add_captcha add_to_group_id cancel_button_text cancel_url created_date created_id description frontend_title group_type group_type_label help_post help_pre id is_active is_cms_user is_edit_link is_map is_proximity_search is_reserved is_uf_link is_update_dupe limit_listings_group_id name notify post_url submit_button_text title', 'c' => true],
        'UFJoin' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'entity_id entity_table id is_active module module_data uf_group_id weight', 'c' => true],
        'UFMatch' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id domain_id id language uf_id uf_name', 'c' => true],
        'User' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks login passwordReset replace requestPasswordResetEmail save sendPasswordResetEmail update', 'f' => 'contact_id domain_id hashed_password id is_active language password password_reset_token roles timezone uf_id uf_name username when_created when_last_accessed when_updated', 'c' => true],
        'UserJob' => ['a' => 'autocomplete checkAccess create delete export get getActions getFields getLinks replace revert save update', 'f' => 'created_date created_id end_date expires_date id is_current is_template job_type label metadata name queue_id search_display_id start_date status_id', 'c' => true],
        'UserRole' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'id role_id user_id', 'c' => true],
        'Website' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'contact_id id url website_type_id', 'c' => true],
        'WordReplacement' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'domain_id find_word id is_active language match_type replace_word', 'c' => true],
        'WorkflowMessage' => ['a' => 'checkAccess get getActions getFields getLinks getTemplateFields render', 'f' => '', 'c' => false],
        'WorldRegion' => ['a' => 'autocomplete checkAccess create delete get getActions getFields getLinks replace save update', 'f' => 'id name', 'c' => true],
    ];

    /**
     * Class name => entity name, where the two differ.
     *
     * `\Civi\Api4\CiviCase` is the entity `Case`, because Case is a php
     * keyword. The fluent form uses the class, civicrm_api4() the entity.
     *
     * @var array<string, string>
     */
    public const CLASS_ALIASES = [
        'CiviCase' => 'Case',
    ];

    /**
     * Entity name => implicit joins, as `name:TargetEntity` pairs.
     *
     * The left-hand side of `address_primary.street_address`: every field
     * carrying an `entity_reference` becomes a joinable of the same name
     * (SchemaMapBuilder::addJoins), plus the eight primary/billing links
     * ContactSchemaMapSubscriber adds to Contact. Entities with no implicit
     * join are absent.
     *
     * NOT in here, and therefore never judged: explicit `addJoin()` aliases,
     * custom-group joins (named after site data) and multi-level paths.
     *
     * @var array<string, string>
     */
    public const JOINS = [
        'ActionSchedule' => 'group_id:Group msg_template_id:MessageTemplate sms_provider_id:SmsProvider sms_template_id:MessageTemplate',
        'Activity' => 'campaign_id:Campaign original_id:Activity parent_id:Activity phone_id:Phone relationship_id:Relationship',
        'ActivityContact' => 'activity_id:Activity contact_id:Contact',
        'Address' => 'contact_id:Contact country_id:Country county_id:County master_id:Address state_province_id:StateProvince',
        'AfformSubmission' => 'contact_id:Contact',
        'Batch' => 'created_id:Contact modified_id:Contact saved_search_id:SavedSearch',
        'BouncePattern' => 'bounce_type_id:BounceType',
        'Campaign' => 'created_id:Contact last_modified_id:Contact parent_id:Campaign',
        'Case' => 'case_type_id:CaseType',
        'CaseActivity' => 'activity_id:Activity case_id:Case',
        'CaseContact' => 'case_id:Case contact_id:Contact',
        'Contact' => 'address_billing:Address address_primary:Address email_billing:Email email_primary:Email employer_id:Contact im_billing:IM im_primary:IM phone_billing:Phone phone_primary:Phone primary_contact_id:Contact',
        'ContactType' => 'parent_id:ContactType',
        'Contribution' => 'address_id:Address campaign_id:Campaign contact_id:Contact contribution_page_id:ContributionPage contribution_recur_id:ContributionRecur currency:Currency financial_type_id:FinancialType',
        'ContributionPage' => 'campaign_id:Campaign created_id:Contact currency:Currency financial_type_id:FinancialType',
        'ContributionProduct' => 'contribution_id:Contribution financial_type_id:FinancialType product_id:Product',
        'ContributionRecur' => 'campaign_id:Campaign contact_id:Contact currency:Currency financial_type_id:FinancialType payment_processor_id:PaymentProcessor payment_token_id:PaymentToken',
        'ContributionSoft' => 'contact_id:Contact contribution_id:Contribution currency:Currency pcp_id:PCP',
        'Country' => 'region_id:WorldRegion',
        'County' => 'state_province_id:StateProvince',
        'CustomField' => 'custom_group_id:CustomGroup option_group_id:OptionGroup',
        'CustomGroup' => 'created_id:Contact',
        'Dashboard' => 'domain_id:Domain',
        'DashboardContact' => 'contact_id:Contact dashboard_id:Dashboard',
        'DedupeException' => 'contact_id1:Contact contact_id2:Contact',
        'DedupeRule' => 'dedupe_rule_group_id:DedupeRuleGroup',
        'Discount' => 'price_set_id:PriceSet',
        'Domain' => 'contact_id:Contact',
        'Email' => 'contact_id:Contact',
        'EmailMessage' => 'created_id:Contact from_site_email_address_id:SiteEmailAddress to_contact_id:Contact',
        'EntityBatch' => 'batch_id:Batch',
        'EntityFile' => 'file_id:File',
        'EntityFinancialAccount' => 'financial_account_id:FinancialAccount',
        'EntityFinancialTrxn' => 'financial_trxn_id:FinancialTrxn',
        'EntityTag' => 'tag_id:Tag',
        'Event' => 'campaign_id:Campaign created_id:Contact currency:Currency dedupe_rule_group_id:DedupeRuleGroup loc_block_id:LocBlock',
        'EventCartParticipant' => 'participant_id:Participant',
        'File' => 'created_id:Contact',
        'FinancialAccount' => 'contact_id:Contact parent_id:FinancialAccount',
        'FinancialItem' => 'contact_id:Contact currency:Currency financial_account_id:FinancialAccount',
        'FinancialTrxn' => 'currency:Currency from_financial_account_id:FinancialAccount payment_processor_id:PaymentProcessor to_financial_account_id:FinancialAccount',
        'Grant' => 'contact_id:Contact currency:Currency financial_type_id:FinancialType',
        'Group' => 'created_id:Contact modified_id:Contact saved_search_id:SavedSearch',
        'GroupContact' => 'contact_id:Contact email_id:Email group_id:Group location_id:LocBlock',
        'GroupNesting' => 'child_group_id:Group parent_group_id:Group',
        'GroupOrganization' => 'group_id:Group organization_id:Contact',
        'Household' => 'address_billing:Address address_primary:Address email_billing:Email email_primary:Email employer_id:Contact im_billing:IM im_primary:IM phone_billing:Phone phone_primary:Phone primary_contact_id:Contact',
        'IM' => 'contact_id:Contact',
        'Individual' => 'address_billing:Address address_primary:Address email_billing:Email email_primary:Email employer_id:Contact im_billing:IM im_primary:IM phone_billing:Phone phone_primary:Phone primary_contact_id:Contact',
        'Job' => 'domain_id:Domain',
        'JobLog' => 'domain_id:Domain job_id:Job',
        'LineItem' => 'contribution_id:Contribution financial_type_id:FinancialType price_field_id:PriceField price_field_value_id:PriceFieldValue',
        'LocBlock' => 'address_2_id:Address address_id:Address email_2_id:Email email_id:Email im_2_id:IM im_id:IM phone_2_id:Phone phone_id:Phone',
        'Log' => 'modified_id:Contact',
        'MailSettings' => 'campaign_id:Campaign domain_id:Domain',
        'Mailing' => 'approver_id:Contact campaign_id:Campaign created_id:Contact domain_id:Domain footer_id:MailingComponent header_id:MailingComponent location_type_id:LocationType msg_template_id:MessageTemplate optout_id:MailingComponent reply_id:MailingComponent scheduled_id:Contact sms_provider_id:SmsProvider unsubscribe_id:MailingComponent',
        'MailingAB' => 'created_id:Contact',
        'MailingEventBounce' => 'event_queue_id:MailingEventQueue',
        'MailingEventConfirm' => 'event_subscribe_id:MailingEventSubscribe',
        'MailingEventDelivered' => 'event_queue_id:MailingEventQueue',
        'MailingEventOpened' => 'event_queue_id:MailingEventQueue',
        'MailingEventQueue' => 'contact_id:Contact email_id:Email job_id:MailingJob mailing_id:Mailing phone_id:Phone',
        'MailingEventReply' => 'event_queue_id:MailingEventQueue',
        'MailingEventSubscribe' => 'contact_id:Contact group_id:Group',
        'MailingEventTrackableURLOpen' => 'event_queue_id:MailingEventQueue trackable_url_id:MailingTrackableURL',
        'MailingEventUnsubscribe' => 'event_queue_id:MailingEventQueue',
        'MailingGroup' => 'mailing_id:Mailing',
        'MailingJob' => 'mailing_id:Mailing parent_id:MailingJob',
        'MailingTrackableURL' => 'mailing_id:Mailing',
        'MappingField' => 'location_type_id:LocationType mapping_id:Mapping relationship_type_id:RelationshipType',
        'Membership' => 'campaign_id:Campaign contact_id:Contact contribution_recur_id:ContributionRecur membership_type_id:MembershipType owner_membership_id:Membership status_id:MembershipStatus',
        'MembershipBlock' => 'entity_id:ContributionPage membership_type_default:MembershipType',
        'MembershipLog' => 'membership_id:Membership membership_type_id:MembershipType modified_id:Contact status_id:MembershipStatus',
        'MembershipType' => 'domain_id:Domain financial_type_id:FinancialType member_of_contact_id:Contact',
        'Navigation' => 'domain_id:Domain parent_id:Navigation',
        'Note' => 'contact_id:Contact',
        'OAuthContactToken' => 'client_id:OAuthClient contact_id:Contact',
        'OAuthSysToken' => 'client_id:OAuthClient',
        'OpenID' => 'contact_id:Contact',
        'OptionValue' => 'option_group_id:OptionGroup',
        'OrderCompletionMetadata' => 'contribution_id:Contribution line_item_id:LineItem',
        'Organization' => 'address_billing:Address address_primary:Address email_billing:Email email_primary:Email employer_id:Contact im_billing:IM im_primary:IM phone_billing:Phone phone_primary:Phone primary_contact_id:Contact',
        'PCP' => 'contact_id:Contact currency:Currency',
        'PCPBlock' => 'supporter_profile_id:UFGroup',
        'Participant' => 'campaign_id:Campaign contact_id:Contact created_id:Contact discount_id:Discount event_id:Event fee_currency:Currency registered_by_id:Participant status_id:ParticipantStatusType transferred_to_contact_id:Contact',
        'PaymentProcessor' => 'domain_id:Domain payment_processor_type_id:PaymentProcessorType',
        'PaymentToken' => 'contact_id:Contact created_id:Contact payment_processor_id:PaymentProcessor',
        'Phone' => 'contact_id:Contact',
        'Pledge' => 'campaign_id:Campaign contact_id:Contact contribution_page_id:ContributionPage currency:Currency financial_type_id:FinancialType',
        'PledgePayment' => 'contribution_id:Contribution currency:Currency pledge_id:Pledge',
        'PremiumsProduct' => 'financial_type_id:FinancialType premiums_id:Premium product_id:Product',
        'PriceField' => 'price_set_id:PriceSet',
        'PriceFieldValue' => 'financial_type_id:FinancialType membership_type_id:MembershipType price_field_id:PriceField',
        'PriceSet' => 'domain_id:Domain financial_type_id:FinancialType',
        'PriceSetEntity' => 'price_set_id:PriceSet',
        'PrintLabel' => 'created_id:Contact',
        'Product' => 'currency:Currency financial_type_id:FinancialType',
        'Relationship' => 'case_id:Case contact_id_a:Contact contact_id_b:Contact relationship_type_id:RelationshipType',
        'RelationshipCache' => 'case_id:Case far_contact_id:Contact near_contact_id:Contact relationship_id:Relationship relationship_type_id:RelationshipType',
        'ReportInstance' => 'created_id:Contact domain_id:Domain drilldown_id:ReportInstance navigation_id:Navigation owner_id:Contact',
        'SavedSearch' => 'created_id:Contact mapping_id:Mapping modified_id:Contact',
        'SearchDisplay' => 'saved_search_id:SavedSearch',
        'SearchParamSet' => 'created_by:Contact',
        'Setting' => 'contact_id:Contact created_id:Contact domain_id:Domain',
        'SiteEmailAddress' => 'domain_id:Domain',
        'SiteToken' => 'created_id:Contact domain_id:Domain modified_id:Contact',
        'SmsProvider' => 'domain_id:Domain',
        'StateProvince' => 'country_id:Country',
        'StatusPreference' => 'domain_id:Domain',
        'SubscriptionHistory' => 'contact_id:Contact group_id:Group',
        'Survey' => 'campaign_id:Campaign created_id:Contact last_modified_id:Contact',
        'Tag' => 'created_id:Contact parent_id:Tag',
        'Totp' => 'user_id:UFMatch',
        'Translation' => 'source_key:TranslationSource',
        'UFField' => 'location_type_id:LocationType uf_group_id:UFGroup',
        'UFGroup' => 'add_to_group_id:Group created_id:Contact limit_listings_group_id:Group',
        'UFJoin' => 'uf_group_id:UFGroup',
        'UFMatch' => 'contact_id:Contact domain_id:Domain',
        'User' => 'contact_id:Contact domain_id:Domain',
        'UserJob' => 'created_id:Contact queue_id:Queue search_display_id:SearchDisplay',
        'UserRole' => 'role_id:Role user_id:User',
        'Website' => 'contact_id:Contact',
        'WordReplacement' => 'domain_id:Domain',
    ];

    /**
     * Entity name prefixes that only exist on a configured site.
     *
     * @var list<string>
     */
    public const DYNAMIC_PREFIXES = [
        'Custom_', 'Eck_', 'SK_', 'Import_',
    ];

    /**
     * Field name prefixes core computes from a site's configuration.
     *
     * SearchKit publishes every search segment as `segment_<name>` on the
     * entity it segments; the names live in the database, the prefix does
     * not. Unknown names starting like this are left alone.
     *
     * @var list<string>
     */
    public const DYNAMIC_FIELD_PREFIXES = [
        'segment_',
    ];

    /**
     * Fields from spec providers that apply to no single entity.
     *
     * Accepted on every entity: these come from providers whose target is a
     * runtime condition, so the alternative is a false positive.
     *
     * @var list<string>
     */
    public const ANY_ENTITY_FIELDS = [
        'base_module', 'entity_id', 'has_base', 'id', 'local_modified_date',
    ];

    /** Is this a known entity, or a name only a live site could confirm? */
    public static function knowsEntity(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /** @return list<string> */
    public static function actions(string $entity): array
    {
        $actions = self::ENTITIES[$entity]['a'] ?? '';

        return $actions === '' ? [] : explode(' ', $actions);
    }

    /** Whether the field list is exhaustive enough to reject unknown names. */
    public static function hasCompleteFields(string $entity): bool
    {
        return (self::ENTITIES[$entity]['c'] ?? false) === true;
    }

    /** @return list<string> */
    public static function fields(string $entity): array
    {
        $fields = self::ENTITIES[$entity]['f'] ?? '';

        return $fields === '' ? [] : explode(' ', $fields);
    }

    /**
     * The implicit joins of an entity, join name => target entity.
     *
     * @return array<string, string>
     */
    public static function joins(string $entity): array
    {
        $joins = self::JOINS[$entity] ?? '';
        if ($joins === '') {
            return [];
        }
        $map = [];
        foreach (explode(' ', $joins) as $pair) {
            [$name, $target] = explode(':', $pair, 2);
            $map[$name] = $target;
        }

        return $map;
    }
}

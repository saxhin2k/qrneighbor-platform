<?php
/**
 * Plugin Name: QR Neighbor SMS Manager
 * Description: Mini SimpleTexting-style SMS manager for QR Neighbor, using Twilio. Handles per-business welcome messages and campaigns.
 * Author: S R + ChatGPT
 * Version: 0.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRN_SMS_Manager {

    const OPTION_KEY = 'qrn_sms_settings';

    // Meta keys used on landing pages.
    const META_BUSINESS_KEY   = '_qrn_sms_business_key';
    const META_TWILIO_NUMBER  = '_qrn_sms_twilio_number';
    const META_WELCOME_MSG    = '_qrn_sms_welcome_message';

    // Lead meta keys (must match QR Leads plugin / JFB).
    const LEAD_META_PHONE     = 'customer_phone';   // phone number
    const LEAD_META_BUSINESS  = 'business_name';    // business key / slug
    const LEAD_META_STATUS    = '_qrn_sms_status';          // active|unsubscribed
    const LEAD_META_WELCOME   = '_qrn_sms_welcome_sent_at'; // timestamp

    public function __construct() {

        // Admin UI
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Meta box on client landing pages (Pages)
        add_action( 'add_meta_boxes', [ $this, 'add_page_meta_box' ] );
        add_action( 'save_post_page', [ $this, 'save_page_meta_box' ], 10, 2 );

        // Hook when a QR Lead is created/updated -> send welcome SMS (once)
        add_action( 'save_post_qr_lead', [ $this, 'handle_new_lead' ], 20, 3 );

        // REST route for Twilio inbound (STOP, etc.)
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
   
       // Cron hook for scheduled campaigns
        add_action( 'qrn_sms_run_campaign', [ $this, 'run_scheduled_campaign' ], 10, 1 );

    }
    
    /**
     * Inspect WP-Cron and return all scheduled SMS campaign events.
     *
     * Each event is a single wp_schedule_single_event call to 'qrn_sms_run_campaign'
     * with args in the format:
     *   [ [ 'business' => 'biz_key', 'body' => 'Message text' ] ]
     *
     * @return array
     */
    private function get_scheduled_campaign_events() {
        if ( ! function_exists( '_get_cron_array' ) ) {
            return [];
        }

        $cron = _get_cron_array();
        if ( ! is_array( $cron ) ) {
            return [];
        }

        $events = [];

        foreach ( $cron as $timestamp => $hooks ) {
            if ( empty( $hooks['qrn_sms_run_campaign'] ) || ! is_array( $hooks['qrn_sms_run_campaign'] ) ) {
                continue;
            }

            foreach ( $hooks['qrn_sms_run_campaign'] as $event ) {
                if ( empty( $event['args'] ) || ! is_array( $event['args'] ) ) {
                    continue;
                }

                // Your code schedules like: [ [ 'business' => ..., 'body' => ... ] ]
                $payload = isset( $event['args'][0] ) && is_array( $event['args'][0] ) ? $event['args'][0] : [];

                $business = isset( $payload['business'] ) ? $payload['business'] : '';
                $body     = isset( $payload['body'] ) ? $payload['body'] : '';

                if ( ! $business || ! $body ) {
                    continue;
                }

                // Build a stable ID for this event so we can cancel by token
                $id = md5( $timestamp . '|' . $business . '|' . $body );

                $events[ $id ] = [
                    'id'        => $id,
                    'timestamp' => (int) $timestamp,
                    'business'  => $business,
                    'body'      => $body,
                    'args'      => $event['args'],
                ];
            }
        }

         // Sort by soonest scheduled time (ascending)
        if ( ! empty( $events ) ) {
            uasort(
                $events,
                function ( $a, $b ) {
                    $at = isset( $a['timestamp'] ) ? (int) $a['timestamp'] : 0;
                    $bt = isset( $b['timestamp'] ) ? (int) $b['timestamp'] : 0;
                    return $at <=> $bt;
                }
            );
        }

        return $events;
    }

    /* -------------------------------------------------------------------------
     * Helper: Load settings
     * ---------------------------------------------------------------------- */
    private function get_settings() {
        $settings = get_option( self::OPTION_KEY, [] );
        $defaults = [
            'twilio_sid'   => '',
            'twilio_token' => '',
            'default_from' => '',
        ];
        return array_merge( $defaults, $settings );
    }

    /* -------------------------------------------------------------------------
     * Admin Menu
     * ---------------------------------------------------------------------- */

    public function register_admin_menu() {
        $cap = 'manage_options';

        add_menu_page(
            'QR Neighbor SMS',
            'QR SMS',
            $cap,
            'qrn-sms-dashboard',
            [ $this, 'render_dashboard_page' ],
            'dashicons-email-alt2',
            26
        );

        add_submenu_page(
            'qrn-sms-dashboard',
            'SMS Dashboard',
            'Dashboard',
            $cap,
            'qrn-sms-dashboard',
            [ $this, 'render_dashboard_page' ]
        );

        add_submenu_page(
            'qrn-sms-dashboard',
            'SMS Campaigns',
            'Campaigns',
            $cap,
            'qrn-sms-campaigns',
            [ $this, 'render_campaigns_page' ]
        );
       
        add_submenu_page(
            'qrn-sms-dashboard',
            'Campaign Reports',
            'Campaign Reports',
            $cap,
            'qrn-sms-reports',
            'qrn_sms_render_reports_page' // global function defined later
        );

       // 🔹 NEW: Message Log submenu
        add_submenu_page(
            'qrn-sms-dashboard',
            'Message Log',
            'Message Log',
            $cap,
            'qrn-sms-message-log',
            'qrn_sms_render_message_log_page' // global function defined later
        );

        add_submenu_page(
            'qrn-sms-dashboard',
            'SMS Settings',
            'Settings',
            $cap,
            'qrn-sms-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /* -------------------------------------------------------------------------
     * Simple Admin UI Styles (with blue accent)
     * ---------------------------------------------------------------------- */

    private function admin_inline_styles() {
        ?>
        <style>
            .qrn-card-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 16px;
                margin-top: 16px;
            }
            .qrn-card {
                background: linear-gradient(135deg, #f9fafb, #ffffff);
                border-radius: 8px;
                padding: 16px 18px;
                box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
                border: 1px solid #e5e7eb;
                position: relative;
                overflow: hidden;
            }
            .qrn-card::before {
                content: "";
                position: absolute;
                inset: 0;
                border-top: 3px solid #2563eb;
                opacity: 0.7;
                pointer-events: none;
            }
            .qrn-card h2 {
                margin-top: 0;
                font-size: 16px;
                margin-bottom: 8px;
            }
            .qrn-card p {
                margin: 4px 0;
                color: #4b5563;
            }
            .qrn-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 999px;
                font-size: 11px;
                background: #eff6ff;
                color: #1d4ed8;
                margin-left: 4px;
            }
            .qrn-section-title {
                font-size: 18px;
                margin-top: 0;
                margin-bottom: 8px;
            }
            .qrn-subtitle {
                color: #6b7280;
                margin-bottom: 16px;
            }
            .qrn-form-row {
                margin-bottom: 16px;
            }
            .qrn-form-row label {
                font-weight: 600;
                display: block;
                margin-bottom: 4px;
            }
            .qrn-form-row input[type="text"],
            .qrn-form-row input[type="password"],
            .qrn-form-row textarea {
                width: 100%;
                max-width: 520px;
            }
            .qrn-alert {
                padding: 8px 10px;
                border-radius: 4px;
                background: #fef3c7;
                border: 1px solid #fbbf24;
                color: #92400e;
                margin-bottom: 12px;
            }
            .qrn-success {
                padding: 8px 10px;
                border-radius: 4px;
                background: #dcfce7;
                border: 1px solid #22c55e;
                color: #166534;
                margin-bottom: 12px;
            }
            .qrn-metric {
                font-size: 26px;
                font-weight: 700;
                color: #111827;
                margin: 6px 0;
            }
            .qrn-metric-sub {
                font-size: 12px;
                color: #6b7280;
            }
            .qrn-metric-list {
                margin-top: 6px;
                padding-left: 18px;
                font-size: 13px;
            }
        </style>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Subscriber stats (for dashboard)
     * ---------------------------------------------------------------------- */

    /**
     * Get basic subscriber stats from QR Leads.
     *
     * total = all active leads (any business)
     * per_business = top few business keys with counts
     */
    private function get_subscriber_stats() {
        $stats = [
            'total'        => 0,
            'per_business' => [],
        ];

        if ( ! post_type_exists( 'qr_lead' ) ) {
            return $stats;
        }

        $lead_ids = get_posts([
            'post_type'      => 'qr_lead',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ]);

        if ( empty( $lead_ids ) ) {
            return $stats;
        }

        $per_business = [];

        foreach ( $lead_ids as $lead_id ) {
            $business = get_post_meta( $lead_id, self::LEAD_META_BUSINESS, true );
            if ( ! $business ) {
                $business = '(unknown)';
            }

            $status = get_post_meta( $lead_id, self::LEAD_META_STATUS, true );
            if ( 'unsubscribed' === $status ) {
                continue; // only count active subs
            }

            if ( ! isset( $per_business[ $business ] ) ) {
                $per_business[ $business ] = 0;
            }
            $per_business[ $business ]++;
        }

        $stats['total'] = array_sum( $per_business );

        arsort( $per_business );
        $stats['per_business'] = array_slice( $per_business, 0, 5, true );

        return $stats;
    }

    /* -------------------------------------------------------------------------
     * Dashboard Page
     * ---------------------------------------------------------------------- */

    public function render_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings  = $this->get_settings();
        $sid       = $settings['twilio_sid'];
        $from      = $settings['default_from'];
        $sub_stats = $this->get_subscriber_stats();

        echo '<div class="wrap">';
        echo '<h1>QR Neighbor SMS Dashboard</h1>';
        $this->admin_inline_styles();
        echo '<p class="qrn-subtitle">Quick overview of your SMS system. This is the control center for all per-business texting.</p>';

        echo '<div class="qrn-card-grid">';

        // Subscribers overview card
        echo '<div class="qrn-card">';
        echo '<h2>Subscribers Overview <span class="qrn-badge">Live</span></h2>';
        echo '<p class="qrn-metric">' . intval( $sub_stats['total'] ) . '</p>';
        echo '<p class="qrn-metric-sub">Total active leads captured in QR Lead.</p>';

        if ( ! empty( $sub_stats['per_business'] ) ) {
            echo '<ul class="qrn-metric-list">';
            foreach ( $sub_stats['per_business'] as $business => $count ) {
                echo '<li><strong>' . esc_html( $business ) . '</strong> — ' . intval( $count ) . ' subscribers</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="qrn-metric-sub">No active leads found yet. Once your landing pages start collecting numbers, you’ll see per-business counts here.</p>';
        }
        echo '</div>';

        // Twilio status card
        echo '<div class="qrn-card">';
        echo '<h2>Twilio Status</h2>';
        if ( $sid ) {
            echo '<p><strong>SID:</strong> ' . esc_html( substr( $sid, 0, 6 ) ) . '••••</p>';
            if ( $from ) {
                echo '<p><strong>Default From:</strong> ' . esc_html( $from ) . '</p>';
            } else {
                echo '<p><strong>Default From:</strong> <em>not set (each business must have its own number)</em></p>';
            }
            echo '<p>Twilio is configured. This plugin will use your Account SID + Auth Token for all sends.</p>';
        } else {
            echo '<p>Twilio is not configured yet.</p>';
            echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=qrn-sms-settings' ) ) . '" class="button button-primary">Go to Settings</a></p>';
        }
        echo '</div>';

        // Next steps card
        echo '<div class="qrn-card">';
        echo '<h2>Next Steps</h2>';
        echo '<p>1. Configure Twilio in <strong>Settings</strong>.</p>';
        echo '<p>2. On each client landing page, set their <strong>Twilio number</strong> and <strong>Welcome SMS</strong> in the meta box.</p>';
        echo '<p>3. Confirm a test signup gets the welcome SMS.</p>';
        echo '<p>4. Use the <strong>Campaigns</strong> screen to send one-off blasts to each business.</p>';
        echo '</div>';

        echo '</div>'; // grid
        echo '</div>'; // wrap
    }

    /* -------------------------------------------------------------------------
     * Settings Page (Twilio)
     * ---------------------------------------------------------------------- */

    public function register_settings() {
        register_setting(
            'qrn_sms_settings_group',
            self::OPTION_KEY,
            [ $this, 'sanitize_settings' ]
        );


        // Provider + Telnyx configuration
        add_settings_section(
            'qrn_sms_provider_section',
            'Provider & Telnyx Configuration',
            function () {
                echo '<p>Select which SMS provider you are using and configure Telnyx if applicable.</p>';
            },
            'qrn-sms-settings'
        );

        add_settings_field(
            'provider',
            'SMS Provider',
            [ $this, 'field_provider' ],
            'qrn-sms-settings',
            'qrn_sms_provider_section'
        );

        add_settings_field(
            'telnyx_api_key',
            'Telnyx API Key',
            [ $this, 'field_telnyx_api_key' ],
            'qrn-sms-settings',
            'qrn_sms_provider_section'
        );

        add_settings_field(
            'telnyx_profile',
            'Telnyx Messaging Profile ID',
            [ $this, 'field_telnyx_profile' ],
            'qrn-sms-settings',
            'qrn_sms_provider_section'
        );

        add_settings_section(
            'qrn_sms_twilio_section',
            'Twilio Configuration',
            function () {
                echo '<p>Enter your Twilio credentials. These are used to send SMS for all businesses. Each landing page still needs its own Twilio number as the sender.</p>';
            },
            'qrn-sms-settings'
        );

        add_settings_field(
            'twilio_sid',
            'Twilio Account SID',
            [ $this, 'field_twilio_sid' ],
            'qrn-sms-settings',
            'qrn_sms_twilio_section'
        );

        add_settings_field(
            'twilio_token',
            'Twilio Auth Token',
            [ $this, 'field_twilio_token' ],
            'qrn-sms-settings',
            'qrn_sms_twilio_section'
        );

        add_settings_field(
            'default_from',
            'Default From Number (fallback)',
            [ $this, 'field_default_from' ],
            'qrn-sms-settings',
            'qrn_sms_twilio_section'
        );
    }

    public function sanitize_settings( $input ) {
        $output = [];

        // Core Twilio settings
        $output['twilio_sid']   = isset( $input['twilio_sid'] ) ? sanitize_text_field( $input['twilio_sid'] ) : '';
        $output['twilio_token'] = isset( $input['twilio_token'] ) ? sanitize_text_field( $input['twilio_token'] ) : '';
        $output['default_from'] = isset( $input['default_from'] ) ? sanitize_text_field( $input['default_from'] ) : '';

        // Provider + Telnyx settings
        $output['provider']       = isset( $input['provider'] ) ? sanitize_text_field( $input['provider'] ) : 'twilio';
        $output['telnyx_api_key'] = isset( $input['telnyx_api_key'] ) ? sanitize_text_field( $input['telnyx_api_key'] ) : '';
        $output['telnyx_profile'] = isset( $input['telnyx_profile'] ) ? sanitize_text_field( $input['telnyx_profile'] ) : '';

        return $output;
    }

    public function field_twilio_sid() {
        $settings = $this->get_settings();
        echo '<input type="text" name="' . esc_attr( self::OPTION_KEY . '[twilio_sid]' ) . '" value="' . esc_attr( $settings['twilio_sid'] ) . '" class="regular-text" />';
    }

    public function field_twilio_token() {
        $settings = $this->get_settings();
        echo '<input type="password" name="' . esc_attr( self::OPTION_KEY . '[twilio_token]' ) . '" value="' . esc_attr( $settings['twilio_token'] ) . '" class="regular-text" autocomplete="new-password" />';
    }

    public function field_default_from() {
        $settings = $this->get_settings();
        echo '<input type="text" name="' . esc_attr( self::OPTION_KEY . '[default_from]' ) . '" value="' . esc_attr( $settings['default_from'] ) . '" class="regular-text" />';
        echo '<p class="description">Optional fallback. Ideally every business has its own Twilio number set on its landing page.</p>';
    }

    public function field_provider() {
        $settings  = $this->get_settings();
        $provider  = isset( $settings['provider'] ) ? $settings['provider'] : 'twilio';
        $name_attr = esc_attr( self::OPTION_KEY . '[provider]' );

        echo '<select name="' . $name_attr . '">';
        echo '<option value="twilio"' . selected( $provider, 'twilio', false ) . '>Twilio</option>';
        echo '<option value="telnyx"' . selected( $provider, 'telnyx', false ) . '>Telnyx</option>';
        echo '</select>';
        echo '<p class="description">Choose which SMS provider should send messages.</p>';
    }

    public function field_telnyx_api_key() {
        $settings = $this->get_settings();
        $value    = isset( $settings['telnyx_api_key'] ) ? $settings['telnyx_api_key'] : '';
        $name_attr = esc_attr( self::OPTION_KEY . '[telnyx_api_key]' );
        echo '<input type="text" name="' . $name_attr . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">Your Telnyx secret API key.</p>';
    }

    public function field_telnyx_profile() {
        $settings = $this->get_settings();
        $value    = isset( $settings['telnyx_profile'] ) ? $settings['telnyx_profile'] : '';
        $name_attr = esc_attr( self::OPTION_KEY . '[telnyx_profile]' );
        echo '<input type="text" name="' . $name_attr . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
        echo '<p class="description">Optional. Telnyx messaging profile ID to use instead of a single From number.</p>';
    }


    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>QR Neighbor SMS Settings</h1>';
        $this->admin_inline_styles();

        echo '<form action="options.php" method="post">';
        settings_fields( 'qrn_sms_settings_group' );
        do_settings_sections( 'qrn-sms-settings' );
        submit_button();
        echo '</form>';

        echo '<h2>Webhook URL for STOP / inbound SMS</h2>';
        echo '<p>Use this as the <strong>Messaging webhook</strong> URL for all your Twilio numbers:</p>';
        echo '<code>' . esc_html( rest_url( 'qrneighbor/v1/sms-inbound' ) ) . '</code>';

        echo '</div>';
    }

    /* -------------------------------------------------------------------------
     * Campaigns Page (Send Now per business)
     * ---------------------------------------------------------------------- */

    private function get_business_choices_from_leads() {
        $choices = [];

        if ( ! post_type_exists( 'qr_lead' ) ) {
            return $choices;
        }

        $lead_ids = get_posts([
            'post_type'      => 'qr_lead',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ]);

        if ( empty( $lead_ids ) ) {
            return $choices;
        }

        foreach ( $lead_ids as $lead_id ) {
            $business = get_post_meta( $lead_id, self::LEAD_META_BUSINESS, true );
            if ( ! $business ) {
                continue;
            }
            $choices[ $business ] = $business;
        }

        // De-dupe + sort
        $choices = array_unique( $choices );
        asort( $choices );

        return $choices;
    }

  function render_campaigns_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $this->admin_inline_styles();

    $message = '';
    $message_type = '';
    $has_scheduled_any = false;
    // Handle cancel of a scheduled campaign (GET)
    if ( isset( $_GET['qrn_cancel_scheduled'], $_GET['_wpnonce'] ) ) {
        $token = sanitize_text_field( wp_unslash( $_GET['qrn_cancel_scheduled'] ) );
        $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

        if ( $token && wp_verify_nonce( $nonce, 'qrn_cancel_scheduled_' . $token ) ) {
            $events = $this->get_scheduled_campaign_events();

            if ( isset( $events[ $token ] ) ) {
                $event = $events[ $token ];

                // Remove this event from WP-Cron
                wp_unschedule_event(
                    $event['timestamp'],
                    'qrn_sms_run_campaign',
                    $event['args']
                );

                $message      = 'Scheduled campaign cancelled.';
                $message_type = 'success';
            } else {
                $message      = 'Could not find that scheduled campaign.';
                $message_type = 'error';
            }
        } else {
            $message      = 'Invalid cancel request. Please try again.';
            $message_type = 'error';
        }
    }

    // Handle form submit (send campaign now OR schedule)
    if ( isset( $_POST['qrn_sms_campaign_nonce'] ) && wp_verify_nonce( $_POST['qrn_sms_campaign_nonce'], 'qrn_sms_campaign' ) ) {
        $business     = isset( $_POST['qrn_campaign_business'] ) ? sanitize_text_field( $_POST['qrn_campaign_business'] ) : '';
        $body         = isset( $_POST['qrn_campaign_message'] ) ? trim( wp_kses_post( $_POST['qrn_campaign_message'] ) ) : '';
        $mode         = isset( $_POST['qrn_campaign_mode'] ) ? sanitize_text_field( $_POST['qrn_campaign_mode'] ) : 'now';
        $schedule_raw = isset( $_POST['qrn_campaign_schedule'] ) ? sanitize_text_field( $_POST['qrn_campaign_schedule'] ) : '';

       if ( ! $business || ! $body ) {
            $message      = 'Please choose a business and enter a message.';
            $message_type = 'error';
        } else {
            if ( 'later' === $mode ) {
                if ( empty( $schedule_raw ) ) {
                     $message      = 'Please choose a date and time to schedule this campaign.';
                     $message_type = 'error';
            } else {
                // HTML datetime-local sends something like "2025-11-18T21:30"
               // Parse using the site timezone first.
               $timezone = wp_timezone();
               $datetime = date_create_from_format( 'Y-m-d\TH:i', $schedule_raw, $timezone );

              if ( $datetime instanceof DateTimeInterface ) {
                 $timestamp = $datetime->getTimestamp();
              } else {
                  // Fallback, in case the format is slightly different.
                  $timestamp = strtotime( $schedule_raw );
              }

              // Current WordPress time (site timezone), plus a small buffer.
              $now        = current_time( 'timestamp' );
              $min_future = $now + 60; // require at least 1 minute in the future

              if ( ! $timestamp || $timestamp < $min_future ) {
                  $message      = 'The scheduled time must be at least 1 minute in the future.';
                  $message_type = 'error';
             } else {
                 // Schedule a single event in WP-Cron.
                 wp_schedule_single_event(
                     $timestamp,
                     'qrn_sms_run_campaign',
                   [
                        [
                            'business' => $business,
                            'body'     => $body,
                        ]
                    ]
                  );

                  $message      = 'Campaign scheduled for ' . wp_date( 'M j, Y g:ia', $timestamp ) . ' to all active subscribers for "' . esc_html( $business ) . '".';
                  $message_type = 'success';

                }
              }
            } else {
                // Send immediately
                $sent_count = $this->send_campaign_now( $business, $body );
                if ( function_exists( 'qrn_sms_log_campaign' ) ) {
                    qrn_sms_log_campaign([
                        'business_key'      => $business,
                        'message'           => $body,
                        'mode'              => 'now',
                        'scheduled_at'      => null,
                        'sent_at'           => current_time( 'mysql' ),
                        'total_subscribers' => (int) $sent_count, // for now, same as sent_ok
                        'sent_ok'           => (int) $sent_count,
                        'sent_failed'       => 0,
                    ]);
                }

                if ( $sent_count > 0 ) {
                    $message      = 'Campaign sent to ' . intval( $sent_count ) . ' subscribers for business "' . esc_html( $business ) . '".';
                    $message_type = 'success';
                } else {
                    $message      = 'No active subscribers found for that business, or sending failed.';
                    $message_type = 'error';
                }
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>SMS Campaigns</h1>';
    echo '<p class="qrn-subtitle">Choose a business, write a message, and either send now or schedule it. Scheduled campaigns use WP-Cron, so it will run automatically at the scheduled time.</p>';

    if ( $message ) {
        $class = ( 'success' === $message_type ) ? 'qrn-success' : 'qrn-alert';
        echo '<div class="' . esc_attr( $class ) . '">' . esc_html( $message ) . '</div>';
    }

    // Show Scheduled Campaigns table (read-only from WP-Cron)
   $scheduled_events = $this->get_scheduled_campaign_events();
    $has_scheduled_any = ! empty( $scheduled_events );
    $max_rows         = 20;
    $now              = current_time( 'timestamp' );

    // Determine selected business filter (from GET)
    $selected_business = isset( $_GET['qrn_sched_business'] )
        ? sanitize_text_field( wp_unslash( $_GET['qrn_sched_business'] ) )
        : 'all';

    // Build business choices from leads (used for dropdown)
    $business_choices = $this->get_business_choices_from_leads();

    echo '<h2>Scheduled Campaigns</h2>';

    if ( ! empty( $scheduled_events ) ) {

        // Filter out past events
        $scheduled_events = array_filter(
            $scheduled_events,
            function ( $event ) use ( $now ) {
                return isset( $event['timestamp'] ) && (int) $event['timestamp'] >= $now;
            }
        );

        // Filter by business if a specific one is selected
        if ( $selected_business && 'all' !== $selected_business ) {
            $scheduled_events = array_filter(
                $scheduled_events,
                function ( $event ) use ( $selected_business ) {
                    return isset( $event['business'] ) && $event['business'] === $selected_business;
                }
            );
        }

        // Limit to next X upcoming campaigns
        $has_more = false;
        if ( count( $scheduled_events ) > $max_rows ) {
            $scheduled_events = array_slice( $scheduled_events, 0, $max_rows, true );
            $has_more         = true;
        }

        // Business filter dropdown
        echo '<form method="get" class="qrn-scheduled-filter-form">';
        echo '<input type="hidden" name="page" value="qrn-sms-campaigns" />';
        echo '<label for="qrn_sched_business"><strong>Show campaigns for:</strong> </label>';
        echo '<select name="qrn_sched_business" id="qrn_sched_business">';
        echo '<option value="all"' . selected( $selected_business, 'all', false ) . '>All businesses</option>';

        if ( ! empty( $business_choices ) && is_array( $business_choices ) ) {
            foreach ( $business_choices as $biz_key => $biz_label ) {
                // $business_choices likely maps business_key => name
                $label = $biz_label ? $biz_label : $biz_key;
                echo '<option value="' . esc_attr( $biz_key ) . '"' . selected( $selected_business, $biz_key, false ) . '>' . esc_html( $label ) . '</option>';
            }
        }

        echo '</select> ';
        echo '<button type="submit" class="button">Filter</button>';
        echo '</form>';

        // Note above the table
        echo '<p class="qrn-scheduled-note">Showing the next ' . esc_html( $max_rows ) . ' upcoming campaigns' . ( ( $selected_business && 'all' !== $selected_business ) ? ' for this business' : '' ) . '.</p>';

        // Scrollable wrapper
        echo '<div class="qrn-scheduled-wrapper">';
        echo '<table class="widefat striped qrn-scheduled-table">';
        echo '<thead><tr>';
        echo '<th>Business</th>';
        echo '<th>Message</th>';
        echo '<th>Scheduled Time</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead><tbody>';

        if ( ! empty( $scheduled_events ) ) {
            foreach ( $scheduled_events as $token => $event ) {
                $scheduled_time = isset( $event['timestamp'] )
                    ? wp_date( 'M j, Y g:i a', $event['timestamp'] )
                    : '';


                $preview = wp_trim_words(
                    wp_strip_all_tags( $event['body'] ),
                    20,
                    '&hellip;'
                );

                $cancel_url = wp_nonce_url(
                    add_query_arg(
                        [
                            'page'                 => 'qrn-sms-campaigns',
                            'qrn_cancel_scheduled' => $token,
                            'qrn_sched_business'   => $selected_business,
                        ],
                        admin_url( 'admin.php' )
                    ),
                    'qrn_cancel_scheduled_' . $token
                );

                echo '<tr>';
                echo '<td>' . esc_html( $event['business'] ) . '</td>';
                echo '<td>' . esc_html( $preview ) . '</td>';
                echo '<td>' . esc_html( $scheduled_time ) . '</td>';
                echo '<td><a href="' . esc_url( $cancel_url ) . '" class="button button-small">Cancel</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4"><em>No upcoming campaigns match this filter.</em></td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // .qrn-scheduled-wrapper

        if ( $has_more ) {
            echo '<p class="description">There are more campaigns scheduled further in the future. Only the next ' . esc_html( $max_rows ) . ' are shown here.</p>';
        }
    } else {
        echo '<p><em>No scheduled campaigns yet.</em></p>';
    }

    // From here on, your existing code continues...
    $business_choices = $this->get_business_choices_from_leads();
 
/* ============================================
   Build mapping of business_key → Twilio Number
   ============================================ */

$business_from_numbers = [];

$business_from_numbers = [];

// Get all landing pages with a business key
$pages = get_posts( [
    'post_type'      => 'page',
    'posts_per_page' => -1,
    'meta_query'     => [
        [
            'key'     => self::META_BUSINESS_KEY,   // _qrn_sms_business_key
            'compare' => 'EXISTS',
        ],
    ],
] );

foreach ( $pages as $p ) {
    $biz_key = get_post_meta( $p->ID, self::META_BUSINESS_KEY, true );
    $twilio  = get_post_meta( $p->ID, self::META_TWILIO_NUMBER, true );

    if ( $biz_key && $twilio ) {
        $business_from_numbers[ $biz_key ] = $twilio;
    }
}


    echo '<div class="qrn-card">';
    echo '<h2>Create Campaign</h2>';
    echo '<p>1) Pick a business from QR Lead, 2) Type your SMS, 3) Choose <strong>Send now</strong> or <strong>Schedule</strong>.</p>';

    if ( empty( $business_choices ) ) {
        echo '<p>No leads found yet. Once you collect subscribers via QR Lead, you\'ll be able to send campaigns from here.</p>';
    } else {
    ?>
    <div class="qrn-campaign-layout">

        <!-- LEFT SIDE — FORM -->
        <div class="qrn-campaign-form">
            <form method="post">
                <?php wp_nonce_field( 'qrn_sms_campaign', 'qrn_sms_campaign_nonce' ); ?>

                <!-- BUSINESS -->
                <div class="qrn-field">
                    <label for="qrn_campaign_business"><strong>Business</strong></label>
                    <select id="qrn_campaign_business" name="qrn_campaign_business">
                        <option value="">Select a business…</option>
                       <?php foreach ( $business_choices as $key => $label ) : ?>
                          <?php
                               $selected = (
                                   isset( $_POST['qrn_campaign_business'] )
                                   && $_POST['qrn_campaign_business'] === $key
                               ) ? 'selected' : '';

                               // Get the Twilio number mapped earlier
                               $from_number = isset( $business_from_numbers[ $key ] )
                                   ? $business_from_numbers[ $key ]
                                   : '';
                               ?>
                               <option
                                   value="<?php echo esc_attr( $key ); ?>"
                                   data-from="<?php echo esc_attr( $from_number ); ?>"
                                   <?php echo $selected; ?>
                                >
                                   <?php echo esc_html( $label ); ?>
                                </option>
                         <?php endforeach; ?>


                    </select>
                    <p class="qrn-help">Subscribers from this business will receive this SMS.</p>
                </div>

                <!-- MESSAGE -->
                <?php
                $body_val = isset( $_POST['qrn_campaign_message'] )
                    ? stripslashes( $_POST['qrn_campaign_message'] )
                    : '';
                ?>
                <div class="qrn-field">
                    <label for="qrn_campaign_message"><strong>Message</strong></label>
                    <textarea id="qrn_campaign_message"
                              name="qrn_campaign_message"
                              rows="4"
                              class="large-text"
                              placeholder="Type the SMS to send…"><?php echo esc_textarea( $body_val ); ?></textarea>
                    <div class="qrn-field-meta">
                        <span id="qrn-char-count">0</span> / 160 characters
                    </div>
                </div>

                <!-- SEND MODE -->
                <?php
                $mode_val = isset( $_POST['qrn_campaign_mode'] )
                    ? $_POST['qrn_campaign_mode']
                    : 'now';

                $schedule_val = isset( $_POST['qrn_campaign_schedule'] )
                    ? $_POST['qrn_campaign_schedule']
                    : '';
                ?>
                <div class="qrn-field">
                    <label><strong>Send mode</strong></label>

                    <div class="qrn-segmented">
                        <label class="qrn-chip">
                            <input type="radio" name="qrn_campaign_mode" value="now" <?php checked( $mode_val, 'now' ); ?>>
                            <span>Send now</span>
                        </label>

                        <label class="qrn-chip">
                            <input type="radio" name="qrn_campaign_mode" value="later" <?php checked( $mode_val, 'later' ); ?>>
                            <span>Schedule</span>
                        </label>
                    </div>

                    <div class="qrn-schedule-row" id="qrn-schedule-row">
                        <label for="qrn_campaign_schedule">Schedule date &amp; time</label>
                        <input type="datetime-local"
                               id="qrn_campaign_schedule"
                               name="qrn_campaign_schedule"
                               value="<?php echo esc_attr( $schedule_val ); ?>" />
                    </div>
                </div>

                <div class="qrn-actions">
                    <button type="submit" class="button button-primary">Save campaign</button>
                </div>
            </form>
        </div>

        <!-- RIGHT SIDE — PHONE PREVIEW -->
         <div class="qrn-campaign-preview">
    <div class="qrn-phone qrn-phone-ios">
        <div class="qrn-phone-frame">
            <!-- iPhone notch -->
            <div class="qrn-phone-notch">
                <span class="qrn-phone-notch-camera"></span>
                <span class="qrn-phone-notch-speaker"></span>
            </div>

            <!-- Screen area -->
            <div class="qrn-phone-screen">

                <div class="qrn-phone-header">
                    <span class="qrn-phone-from-label">From</span>
                    <span class="qrn-phone-from-number" id="qrn-preview-from">
                        Twilio number not set
                    </span>
                </div>

                <div class="qrn-phone-body">
                    <div class="qrn-phone-message" id="qrn-preview-text">
                        Your SMS preview will appear here…
                    </div>
                </div>

            </div>

            <!-- Home bar -->
            <div class="qrn-phone-homebar"></div>
        </div>
    </div>
</div>


    </div><!-- qrn-campaign-layout -->
    <?php
}

    echo '</div>'; // card
    echo '</div>'; // wrap
?>
<style>
/* === LAYOUT & FORM SIDE === */

.qrn-campaign-layout {
  display: flex;
  gap: 32px;
  max-width: 1100px;
  align-items: flex-start;
}

.qrn-campaign-form {
  flex: 2;
}

.qrn-campaign-preview {
  flex: 1;
  display: flex;
  justify-content: center;
  align-items: flex-start;
}

.qrn-field {
  margin-bottom: 18px;
}

.qrn-field label {
  display: block;
  margin-bottom: 4px;
}

.qrn-help {
  color: #6b7280;
  font-size: 12px;
  margin-top: 4px;
}

.qrn-field-meta {
  text-align: right;
  font-size: 12px;
  color: #6b7280;
}

/* Send mode pills */
.qrn-segmented {
  display: inline-flex;
  align-items: center;
  padding: 4px;
  gap: 8px;
  background: #f3f4f6;
  border-radius: 999px;
}

.qrn-chip {
  display: inline-flex;
  align-items: center;
}

.qrn-chip input {
  display: none;
}

.qrn-chip span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 10px 20px;     /* MORE HEIGHT + WIDTH */
  border-radius: 999px;
  font-size: 13px;
  font-weight: 500;
  line-height: 1;
  min-width: 90px;        /* keeps “Send now” same width as “Schedule” */
  text-align: center;
  cursor: pointer;
}

.qrn-chip input:checked + span {
  background: #2563eb;
  color: #fff;
  box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
}

/* Schedule row */
#qrn-schedule-row {
  margin-top: 10px;
}

/* === iPHONE PREVIEW SIDE === */

/* Outer device wrapper */
.qrn-phone {
  width: 260px;
  height: 520px;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Inner iPhone body */
.qrn-phone-frame {
  position: relative;
  width: 100%;
  height: 100%;
  border-radius: 40px;
  background: #020617;
  box-shadow:
    0 18px 40px rgba(15, 23, 42, 0.55),
    0 0 0 2px rgba(15, 23, 42, 0.8);
  padding: 16px 12px;
  box-sizing: border-box;
}

/* Notch */
.qrn-phone-notch {
  position: absolute;
  top: 10px;
  left: 50%;
  transform: translateX(-50%);
  width: 120px;
  height: 26px;
  background: #020617;
  border-radius: 999px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: 0 0 0 2px #020617;
}

.qrn-phone-notch-camera {
  width: 9px;
  height: 9px;
  border-radius: 999px;
  background: radial-gradient(circle at 30% 30%, #60a5fa, #020617);
}

.qrn-phone-notch-speaker {
  width: 40px;
  height: 6px;
  border-radius: 999px;
  background: #111827;
}

/* Screen area */
.qrn-phone-screen {
  position: relative;
  margin-top: 32px;                 /* room for notch */
  height: calc(100% - 60px);        /* notch + home bar space */
  border-radius: 28px;
  background: #020617;
  padding: 12px;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}

/* Header (“From …”) */
.qrn-phone-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 11px;
  color: #e5e7eb;
  margin-bottom: 8px;
}

.qrn-phone-from-label {
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 500;
  color: #9ca3af;
}

.qrn-phone-from-number {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas,
    "Liberation Mono", "Courier New", monospace;
  font-size: 11px;
}

/* Body + bubble */
.qrn-phone-body {
  flex: 1;
  display: flex;
  align-items: flex-end;
}

.qrn-phone-message {
  background: #2563eb;
  color: #fff;
  padding: 8px 11px;
  border-radius: 18px 18px 6px 18px;
  font-size: 13px;
  line-height: 1.4;
  max-width: 88%;
  box-sizing: border-box;

  white-space: pre-wrap;
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-word;
}

/* Home bar */
.qrn-phone-homebar {
  position: absolute;
  bottom: 10px;
  left: 50%;
  transform: translateX(-50%);
  width: 74px;
  height: 4px;
  border-radius: 999px;
  background: #e5e7eb;
  opacity: 0.75;
}

/* Mobile layout */
@media (max-width: 900px) {
  .qrn-campaign-layout {
    flex-direction: column;
  }
  .qrn-campaign-preview {
    order: -1;
    margin-bottom: 16px;
  }
}
/* ============= LIGHT MODE PHONE OVERRIDES ============= */

/* Outer frame becomes light device instead of black slab */
.qrn-phone-frame {
  background: #f9fafb;
  box-shadow:
    0 14px 30px rgba(15, 23, 42, 0.25),
    0 0 0 1px rgba(148, 163, 184, 0.6);
}

/* Screen background light */
.qrn-phone-screen {
  background: #ffffff;
}

/* Notch slightly lighter so it feels like top glass */
.qrn-phone-notch {
  background: #e5e7eb;
  box-shadow: 0 0 0 2px #e5e7eb;
}

.qrn-phone-notch-speaker {
  background: #9ca3af;
}

/* Header text darker for contrast on light screen */
.qrn-phone-header {
  color: #111827;
}

.qrn-phone-from-label {
  color: #6b7280;
}

.qrn-phone-from-number {
  color: #111827;
}

/* Home bar softer gray */
.qrn-phone-homebar {
  background: #d1d5db;
  opacity: 0.95;
}
/* === BLACK FRAME + LIGHT MODE SCREEN === */

.qrn-phone-frame {
  background: #000000 !important;
  box-shadow:
    0 18px 40px rgba(0, 0, 0, 0.45),
    0 0 0 2px rgba(0, 0, 0, 0.65) !important;
}

/* Keep the screen white inside */
.qrn-phone-screen {
  background: #ffffff !important;
}

/* Notch stays dark (black frame) */
.qrn-phone-notch {
  background: #000000 !important;
  box-shadow: 0 0 0 2px #000000 !important;
}

.qrn-phone-notch-speaker {
  background: #4b5563 !important;
}

.qrn-phone-notch-camera {
  background: radial-gradient(circle at 30% 30%, #60a5fa, #000000) !important;
}

/* Home bar (on black frame) */
.qrn-phone-homebar {
  background: #e5e7eb !important;
  opacity: 0.9 !important;
}

/* Save campaign button style */
.qrn-actions .button.button-primary {
  background: #2563eb;
  border-color: #2563eb;
  border-radius: 999px;
  padding: 8px 20px;
  font-weight: 600;
  font-size: 13px;
  box-shadow: 0 8px 18px rgba(37, 99, 235, 0.35);
  transition: background 0.15s ease, box-shadow 0.15s ease,
              transform 0.15s ease;
}

.qrn-actions .button.button-primary:hover {
  background: #1d4ed8;
  border-color: #1d4ed8;
  box-shadow: 0 10px 22px rgba(37, 99, 235, 0.45);
  transform: translateY(-1px);
}

.qrn-actions .button.button-primary:active {
  transform: translateY(0);
  box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
}
     /* Scheduled campaigns section – SaaS-style card */
            .qrn-scheduled-filter-form {
                margin-top: 10px;
                margin-bottom: 4px;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
            }
            .qrn-scheduled-filter-form label {
                font-weight: 500;
            }
            .qrn-scheduled-filter-form select {
                min-width: 220px;
                padding: 4px 8px;
                border-radius: 6px;
                border: 1px solid #d1d5db;
                font-size: 13px;
            }

            .qrn-scheduled-note {
                margin-top: 4px;
                margin-bottom: 8px;
                color: #6b7280;
                font-size: 12px;
            }

            .qrn-scheduled-wrapper {
                margin-top: 4px;
                margin-bottom: 20px;
                max-height: 360px;
                overflow-y: auto;
                border-radius: 10px;
                border: 1px solid #e5e7eb;
                background: #f9fafb;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
            }

            .qrn-scheduled-table {
                margin: 0;
            }
            .qrn-scheduled-table thead th {
                background: #f3f4f6;
                font-weight: 600;
                font-size: 13px;
                border-bottom-color: #e5e7eb;
            }
            .qrn-scheduled-table td {
                vertical-align: top;
                font-size: 13px;
            }
            .qrn-scheduled-table tbody tr:nth-child(even) td {
                background: #fefefe;
            }
            .qrn-scheduled-table tbody tr:hover td {
                background: #eef2ff;
            }
</style>
<?php
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var textarea = document.getElementById('qrn_campaign_message');
  var preview  = document.getElementById('qrn-preview-text');
  var charCount = document.getElementById('qrn-char-count');

  var businessSelect = document.getElementById('qrn_campaign_business');
  var fromPreview = document.getElementById('qrn-preview-from');

  var scheduleRow = document.getElementById('qrn-schedule-row');
  var modeNow = document.querySelector('input[name="qrn_campaign_mode"][value="now"]');
  var modeLater = document.querySelector('input[name="qrn_campaign_mode"][value="later"]');

  function updatePreview() {
    var text = textarea.value.trim();
    preview.textContent = text || 'Your SMS preview will appear here…';
    charCount.textContent = textarea.value.length;
  }

  function updateFrom() {
    var opt = businessSelect.options[businessSelect.selectedIndex];
    if (!opt) return;
    var from = opt.getAttribute('data-from');
    fromPreview.textContent = from || 'Sender number not set';
  }

  function toggleSchedule() {
    scheduleRow.style.display = modeLater.checked ? 'block' : 'none';
  }

  textarea.addEventListener('input', updatePreview);
  businessSelect.addEventListener('change', updateFrom);
  modeNow.addEventListener('change', toggleSchedule);
  modeLater.addEventListener('change', toggleSchedule);

  updatePreview();
  updateFrom();
  toggleSchedule();
});
</script>
 

    if ( $has_scheduled_any ) {
        echo '<script>
        (function(){
            // Auto-refresh this page every 60 seconds while there are scheduled campaigns
            setInterval(function(){
                window.location.reload();
            }, 60000);
        })();
        </script>';
    }
}
<?php
}

    private function send_campaign_now( $business_key, $body ) {
        if ( ! post_type_exists( 'qr_lead' ) ) {
            return 0;
        }

        $lead_ids = get_posts([
            'post_type'      => 'qr_lead',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'   => self::LEAD_META_BUSINESS,
                    'value' => $business_key,
                ],
            ],
        ]);

        if ( empty( $lead_ids ) ) {
            return 0;
        }

        // Find Twilio number for this business (based on landing page meta).
        $business_page = $this->find_business_page_by_key( $business_key );
        if ( ! $business_page ) {
            return 0;
        }

        $from_number = get_post_meta( $business_page->ID, self::META_TWILIO_NUMBER, true );
        if ( ! $from_number ) {
            return 0;
        }

        $sent_count = 0;

        foreach ( $lead_ids as $lead_id ) {
            $status = get_post_meta( $lead_id, self::LEAD_META_STATUS, true );
            if ( 'unsubscribed' === $status ) {
                continue; // respect STOP
            }

            $phone = get_post_meta( $lead_id, self::LEAD_META_PHONE, true );
            if ( ! $phone ) {
                continue;
            }

            $ok = $this->send_sms( $from_number, $phone, $body );
            if ( $ok ) {
                $sent_count++;
            }
        }

        return $sent_count;
    
    }
   /**
   * Cron callback: run a scheduled campaign.
   * $data = [ 'business' => 'business_key', 'body' => 'message text' ]
   */
 public function run_scheduled_campaign( $data ) {
    if ( ! is_array( $data ) ) {
        return;
    }

    $business = isset( $data['business'] ) ? $data['business'] : '';
    $body     = isset( $data['body'] ) ? $data['body'] : '';

    if ( ! $business || ! $body ) {
        return;
    }

       // Reuse the same logic as "Send Now".
        $sent_count = $this->send_campaign_now( $business, $body );

        if ( function_exists( 'qrn_sms_log_campaign' ) ) {
            qrn_sms_log_campaign([
                'business_key'      => $business,
                'message'           => $body,
                'mode'              => 'scheduled',
                'scheduled_at'      => current_time( 'mysql' ), // when it actually ran
                'sent_at'           => current_time( 'mysql' ),
                'total_subscribers' => (int) $sent_count,
                'sent_ok'           => (int) $sent_count,
                'sent_failed'       => 0,
            ]);
        }
    }

   /* -------------------------------------------------------------------------
     * Meta Box on Landing Pages (Business key, Twilio number, Welcome SMS)
     * ---------------------------------------------------------------------- */

    public function add_page_meta_box() {
        add_meta_box(
            'qrn_sms_meta',
            'QR Neighbor SMS – Welcome Message',
            [ $this, 'render_page_meta_box' ],
            'page',
            'normal',
            'default'
        );
    }

    public function render_page_meta_box( $post ) {
        $business_key = get_post_meta( $post->ID, self::META_BUSINESS_KEY, true );
        if ( ! $business_key ) {
            $business_key = $post->post_name; // fallback to slug
        }

        $twilio_number = get_post_meta( $post->ID, self::META_TWILIO_NUMBER, true );
        $welcome_msg   = get_post_meta( $post->ID, self::META_WELCOME_MSG, true );

        wp_nonce_field( 'qrn_sms_meta_nonce', 'qrn_sms_meta_nonce_field' );

        $this->admin_inline_styles();

        echo '<p class="qrn-subtitle">These settings control the welcome SMS for this client landing page. When a new subscriber signs up on this page (via QR Lead), they\'ll receive the message below from this business\'s Twilio number.</p>';

        echo '<div class="qrn-form-row">';
        echo '<label>Business Key (internal)</label>';
        echo '<input type="text" readonly value="' . esc_attr( $business_key ) . '" class="regular-text" />';
        echo '<p class="description">Based on the page slug. Used to map leads and campaigns to this business.</p>';
        echo '</div>';

        echo '<div class="qrn-form-row">';
        echo '<label for="qrn_sms_twilio_number">Sender Number for this Business</label>';
        echo '<input type="text" id="qrn_sms_twilio_number" name="qrn_sms_twilio_number" value="' . esc_attr( $twilio_number ) . '" class="regular-text" placeholder="+15551112222" />';
        echo '<p class="description">Enter the SMS number used by this business. All welcome and campaign messages for this page will use this number as the sender.</p>';
        echo '</div>';

        echo '<div class="qrn-form-row">';
        echo '<label for="qrn_sms_welcome_message">Welcome SMS Message</label>';
        echo '<textarea id="qrn_sms_welcome_message" name="qrn_sms_welcome_message" rows="4" class="large-text" placeholder="Welcome to Joe\'s Pizza! Show this text and use code WELCOME10 for 10% off. Reply STOP to unsubscribe.">' . esc_textarea( $welcome_msg ) . '</textarea>';
        echo '<p class="description">This text will be sent immediately after someone signs up on this page. Include any POS code (e.g. WELCOME10) and the STOP notice.</p>';
        echo '</div>';
    }

    public function save_page_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['qrn_sms_meta_nonce_field'] ) || ! wp_verify_nonce( $_POST['qrn_sms_meta_nonce_field'], 'qrn_sms_meta_nonce' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( 'page' !== $post->post_type ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Business key: keep existing or default to slug.
        $business_key = get_post_meta( $post_id, self::META_BUSINESS_KEY, true );
        if ( ! $business_key ) {
            $business_key = $post->post_name;
        }
        update_post_meta( $post_id, self::META_BUSINESS_KEY, sanitize_text_field( $business_key ) );

        if ( isset( $_POST['qrn_sms_twilio_number'] ) ) {
            update_post_meta(
                $post_id,
                self::META_TWILIO_NUMBER,
                sanitize_text_field( $_POST['qrn_sms_twilio_number'] )
            );
        }

        if ( isset( $_POST['qrn_sms_welcome_message'] ) ) {
            update_post_meta(
                $post_id,
                self::META_WELCOME_MSG,
                wp_kses_post( $_POST['qrn_sms_welcome_message'] )
            );
        }
    }

    private function find_business_page_by_key( $business_key ) {
        $pages = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => self::META_BUSINESS_KEY,
                    'value' => $business_key,
                ],
            ],
        ]);

        if ( empty( $pages ) ) {
            return null;
        }
        return $pages[0];
    }

    /* -------------------------------------------------------------------------
     * Welcome SMS on new QR Lead
     * ---------------------------------------------------------------------- */

    public function handle_new_lead( $post_id, $post, $update ) {
        // Avoid infinite loops / autosaves.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( 'qr_lead' !== $post->post_type ) {
            return;
        }

        // If welcome already sent, do nothing.
        $already_sent = get_post_meta( $post_id, self::LEAD_META_WELCOME, true );
        if ( $already_sent ) {
            return;
        }

        $phone    = get_post_meta( $post_id, self::LEAD_META_PHONE, true );
        $business = get_post_meta( $post_id, self::LEAD_META_BUSINESS, true );

        if ( ! $phone || ! $business ) {
            return;
        }

        $business_page = $this->find_business_page_by_key( $business );
        if ( ! $business_page ) {
            return;
        }

        $from_number = get_post_meta( $business_page->ID, self::META_TWILIO_NUMBER, true );
        $welcome_msg = get_post_meta( $business_page->ID, self::META_WELCOME_MSG, true );

        if ( ! $from_number || ! $welcome_msg ) {
            return;
        }

        $ok = $this->send_sms( $from_number, $phone, $welcome_msg, 'welcome' );


        if ( $ok ) {
            update_post_meta( $post_id, self::LEAD_META_WELCOME, current_time( 'mysql' ) );
            // Default status = active
            if ( ! get_post_meta( $post_id, self::LEAD_META_STATUS, true ) ) {
                update_post_meta( $post_id, self::LEAD_META_STATUS, 'active' );
            }
        }
    }

    /* -------------------------------------------------------------------------
     * Twilio Send Helper (uses wp_remote_post)
     * ---------------------------------------------------------------------- */

    private function send_sms( $from, $to, $body, $source = 'campaign' ) {

        $settings = $this->get_settings();
        $primary  = isset( $settings['provider'] ) ? $settings['provider'] : 'twilio';

        // Normalize primary value.
        if ( $primary !== 'telnyx' && $primary !== 'twilio' ) {
            $primary = 'twilio';
        }

        // Decide fallback (the other provider).
        $fallback = ( $primary === 'telnyx' ) ? 'twilio' : 'telnyx';

        // 1) Try primary provider first.
        if ( $primary === 'telnyx' ) {
            $result = $this->send_sms_via_telnyx( $from, $to, $body, $source, $settings );
            if ( false !== $result ) {
                return $result;
            }

            // If Telnyx failed (missing API key, network error, etc.), fall back to Twilio.
            $result = $this->send_sms_via_twilio( $from, $to, $body, $source, $settings );
            return $result;
        } else {
            // Primary = Twilio
            $result = $this->send_sms_via_twilio( $from, $to, $body, $source, $settings );
            if ( false !== $result ) {
                return $result;
            }

            // If Twilio failed, fall back to Telnyx.
            $result = $this->send_sms_via_telnyx( $from, $to, $body, $source, $settings );
            return $result;
        }
    }

    /**
     * Internal: send a single SMS via Telnyx only.
     *
     * Returns false on hard failure (no API key, network error).
     * Otherwise returns Telnyx JSON array or true.
     */
    private function send_sms_via_telnyx( $from, $to, $body, $source, $settings ) {

        $api_key = isset( $settings['telnyx_api_key'] ) ? $settings['telnyx_api_key'] : '';
        $profile = isset( $settings['telnyx_profile'] ) ? $settings['telnyx_profile'] : '';

        if ( empty( $api_key ) ) {
            return false;
        }

        $payload = [
            'from'        => $from,
            'to'          => $to,
            'text'        => $body,
            'webhook_url' => rest_url( 'qrneighbor/v1/sms-status' ),
        ];

        if ( ! empty( $profile ) ) {
            $payload['messaging_profile_id'] = $profile;
        }

        $response = wp_remote_post(
            'https://api.telnyx.com/v2/messages',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $data     = json_decode( $body_raw, true );

        // Basic success check
        if ( $code < 200 || $code >= 300 ) {
            // Try to pull an error code/message from Telnyx if available.
            $error_code = (string) $code;
        } else {
            $error_code = '';
        }

        // Extract a message ID / status if present
        $message_id = '';
        $status     = 'sent';
        $to_phone   = $to;

        if ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
            $d = $data['data'];
            if ( isset( $d['id'] ) ) {
                $message_id = $d['id'];
            }
            if ( isset( $d['to'] ) && is_array( $d['to'] ) && isset( $d['to'][0]['phone_number'] ) ) {
                $to_phone = $d['to'][0]['phone_number'];
            }
            if ( isset( $d['status'] ) ) {
                $status = $d['status'];
            }
        }

        // Map number back to business key (same helper as Twilio)
        $business_key = $this->find_business_key_by_twilio_number( $from );

        global $wpdb;
        $table = $wpdb->prefix . 'qrn_sms_messages';

        $provider = 'telnyx';

        if ( $table ) {
            $wpdb->insert(
                $table,
                [
                    'business_key' => $business_key ? $business_key : '',
                    'to_phone'     => $to_phone,
                    'twilio_sid'   => $message_id, // re-using column for Telnyx message id
                    'status'       => $status ? $status : 'sent',
                    'error_code'   => $error_code,
                    'source'       => $source,
                    'provider'     => $provider,
                    'created_at'   => current_time( 'mysql' ),
                    'updated_at'   => current_time( 'mysql' ),
                ]
            );
        }

        // For existing callers, any non-false value is treated as success.
        return $data ? $data : true;
    }

    /**
     * Internal: send a single SMS via Twilio only.
     *
     * Returns false on hard failure (no SID/token, network error).
     * Otherwise returns Twilio JSON array, or true when JSON is malformed.
     */
    private function send_sms_via_twilio( $from, $to, $body, $source, $settings ) {

        if ( empty( $settings['twilio_sid'] ) || empty( $settings['twilio_token'] ) ) {
            return false;
        }

        $sid   = $settings['twilio_sid'];
        $token = $settings['twilio_token'];

        $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json';
        $auth = base64_encode( $sid . ':' . $token );

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
            ],
            'body'    => [
                'From'           => $from,
                'To'             => $to,
                'Body'           => $body,
                // Twilio will POST delivery updates here.
                'StatusCallback' => rest_url( 'qrneighbor/v1/sms-status' ),
            ],
            'timeout' => 20,
        ] );

        // Network / WP error – nothing useful from Twilio.
        if ( is_wp_error( $response ) ) {
            return false;
        }

        // Get HTTP status + body from Twilio.
        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $data     = json_decode( $body_raw, true );

        // If Twilio didn't return JSON we understand, just bail quietly.
        if ( ! is_array( $data ) ) {
            return true;
        }

        $twilio_sid = isset( $data['sid'] ) ? $data['sid'] : '';
        $tw_status  = isset( $data['status'] ) ? $data['status'] : '';
        $to_phone   = isset( $data['to'] ) ? $data['to'] : $to;

        // Figure out error_code, best-effort.
        $error_code = '';
        if ( $code < 200 || $code >= 300 ) {
            $error_code = (string) $code;
        } elseif ( isset( $data['error_code'] ) && $data['error_code'] ) {
            // Twilio-style error code, e.g. 30032, 21608, etc.
            $error_code = (string) $data['error_code'];
        } elseif ( isset( $data['code'] ) && $data['code'] ) {
            // Some Twilio libraries return `code` instead.
            $error_code = (string) $data['code'];
        } else {
            // Fallback to HTTP status.
            $error_code = (string) $code;
        }

        if ( ! $tw_status ) {
            $tw_status = 'failed';
        }

        // Best-effort: map Twilio "From" back to our business key.
        $business_key = $this->find_business_key_by_twilio_number( $from );

        global $wpdb;
        $table = $wpdb->prefix . 'qrn_sms_messages';

        $provider = 'twilio';

        if ( $table ) {
            $wpdb->insert(
                $table,
                [
                    'business_key' => $business_key ? $business_key : '',
                    'to_phone'     => $to_phone,
                    'twilio_sid'   => $twilio_sid, // may be empty on hard errors
                    'status'       => $tw_status ? $tw_status : 'sent',
                    'error_code'   => $error_code,
                    'source'       => $source,
                    'provider'     => $provider,
                    'created_at'   => current_time( 'mysql' ),
                    'updated_at'   => current_time( 'mysql' ),
                ]
            );
        }

        // Return array data (truthy for existing callers like send_campaign_now).
        return $data;
    }
/* -------------------------------------------------------------------------
     * Twilio Inbound Webhook (STOP handling)
     * ---------------------------------------------------------------------- */

     public function register_rest_routes() {
         // Inbound messages (STOP, etc.)
         register_rest_route(
             'qrneighbor/v1',
             '/sms-inbound',
             [
                 'methods'             => 'POST',
                 'callback'            => [ $this, 'handle_inbound_sms' ],
                 'permission_callback' => '__return_true',
             ]
         );
 
         // Delivery status callback (Twilio StatusCallback)
         register_rest_route(
             'qrneighbor/v1',
             '/sms-status',
             [
                 'methods'             => 'POST',
                 'callback'            => [ $this, 'handle_status_callback' ],
                 'permission_callback' => '__return_true',
             ]
         );
     }

    public function handle_inbound_sms( WP_REST_Request $request ) {

        // Twilio posts application/x-www-form-urlencoded, so use get_param().
        $from = $request->get_param( 'From' );
        $to   = $request->get_param( 'To' );
        $body = $request->get_param( 'Body' );

        if ( ! $from || ! $to || ! $body ) {
            return new WP_REST_Response( 'Missing parameters', 400 );
        }

        $body_upper = strtoupper( trim( $body ) );

        // We only react to STOP keywords in this handler.
        $stop_keywords = [ 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ];
        if ( ! in_array( $body_upper, $stop_keywords, true ) ) {
            // Not a STOP – you could extend later for START / HELP, etc.
            return new WP_REST_Response( 'Ignored', 200 );
        }

        // Find business by Twilio number ("To").
        $business_key = $this->find_business_key_by_twilio_number( $to );

        if ( $business_key ) {
            // Find all leads with this phone + business and mark unsubscribed.
            $lead_ids = get_posts([
                'post_type'      => 'qr_lead',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'post_status'    => 'any',
                'meta_query'     => [
                    [
                        'key'   => self::LEAD_META_BUSINESS,
                        'value' => $business_key,
                    ],
                    [
                        'key'   => self::LEAD_META_PHONE,
                        'value' => $from,
                    ],
                ],
            ]);

            if ( ! empty( $lead_ids ) ) {
                foreach ( $lead_ids as $lead_id ) {
                    update_post_meta( $lead_id, self::LEAD_META_STATUS, 'unsubscribed' );
                }
            }
        }

        // Send STOP confirmation back to user.
       $confirm_msg = 'You\'ve been unsubscribed. You will no longer receive messages from this business.';

$this->send_sms( $to, $from, $confirm_msg, 'system' );

return new WP_REST_Response( 'OK', 200 );

    }
  public function handle_status_callback( WP_REST_Request $request ) {
 
         $sid    = $request->get_param( 'MessageSid' );
         $status = $request->get_param( 'MessageStatus' );
         $error  = $request->get_param( 'ErrorCode' );
 
         if ( ! $sid ) {
             return new WP_REST_Response( 'Missing MessageSid', 400 );
         }
 
         global $wpdb;
         $table = $wpdb->prefix . 'qrn_sms_messages';
 
         $data = [
             'updated_at' => current_time( 'mysql' ),
         ];
 
         if ( $status ) {
             $data['status'] = $status;
         }
 
         if ( null !== $error && '' !== $error ) {
             $data['error_code'] = (string) $error;
         }
 
         $wpdb->update(
             $table,
             $data,
             [ 'twilio_sid' => $sid ]
         );
 
         return new WP_REST_Response( 'OK', 200 );
     }
    private function find_business_key_by_twilio_number( $twilio_number ) {
        $pages = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => self::META_TWILIO_NUMBER,
                    'value' => $twilio_number,
                ],
            ],
        ]);

        if ( empty( $pages ) ) {
            return null;
        }

        $page = $pages[0];
        $key  = get_post_meta( $page->ID, self::META_BUSINESS_KEY, true );
        if ( ! $key ) {
            $key = $page->post_name;
        }
        return $key;
    }
}
// -----------------------------------------------------------------------------
// Campaign reporting: DB table + logger + admin page
// -----------------------------------------------------------------------------

// Create the campaigns table on plugin activation.
 function qrn_sms_manager_create_tables() {
    global $wpdb;
 
    $campaigns_table  = $wpdb->prefix . 'qrn_sms_campaigns';
    $messages_table   = $wpdb->prefix . 'qrn_sms_messages';
    $charset_collate  = $wpdb->get_charset_collate();
 
    // Table: campaigns (existing)
    $sql_campaigns = "CREATE TABLE IF NOT EXISTS $campaigns_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        business_key VARCHAR(191) NOT NULL,
        message TEXT NOT NULL,
        mode VARCHAR(20) NOT NULL,          -- now | scheduled
        scheduled_at DATETIME NULL,
        sent_at DATETIME NULL,
        total_subscribers INT UNSIGNED DEFAULT 0,
        sent_ok INT UNSIGNED DEFAULT 0,
        sent_failed INT UNSIGNED DEFAULT 0,
        provider VARCHAR(20) NOT NULL DEFAULT 'twilio',
        created_by BIGINT(20) UNSIGNED DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY business_key (business_key),
        KEY sent_at (sent_at)
    ) $charset_collate;";
 
    // Table: per-message delivery status (add `source`)
    $sql_messages = "CREATE TABLE IF NOT EXISTS $messages_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        business_key VARCHAR(191) NOT NULL,
        to_phone VARCHAR(32) NOT NULL,
        twilio_sid VARCHAR(64) NOT NULL,
        status VARCHAR(32) DEFAULT '',
        error_code VARCHAR(32) DEFAULT '',
        source VARCHAR(20) NOT NULL DEFAULT 'campaign',
        provider VARCHAR(20) NOT NULL DEFAULT 'twilio',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY twilio_sid (twilio_sid),
        KEY business_key (business_key),
        KEY status (status)
    ) $charset_collate;";
 
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_campaigns );
    dbDelta( $sql_messages );
 
    // 🔄 Upgrade for existing installs: ensure `source` column exists.
    $has_source = $wpdb->get_row( "SHOW COLUMNS FROM $messages_table LIKE 'source'" );
    if ( empty( $has_source ) ) {
        $wpdb->query(
            "ALTER TABLE $messages_table 
             ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'campaign' 
             AFTER error_code"
        );
    }

    // 🔄 Upgrade for existing installs: ensure `provider` column exists on messages table.
    $has_msg_provider = $wpdb->get_row( "SHOW COLUMNS FROM $messages_table LIKE 'provider'" );
    if ( empty( $has_msg_provider ) ) {
        $wpdb->query(
            "ALTER TABLE $messages_table 
             ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'twilio' 
             AFTER source"
        );
    }

    // 🔄 Upgrade for existing installs: ensure `provider` column exists on campaigns table.
    $has_campaign_provider = $wpdb->get_row( "SHOW COLUMNS FROM $campaigns_table LIKE 'provider'" );
    if ( empty( $has_campaign_provider ) ) {
        $wpdb->query(
            "ALTER TABLE $campaigns_table 
             ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'twilio' 
             AFTER sent_failed"
        );
    }
}
register_activation_hook( __FILE__, 'qrn_sms_manager_create_tables' );
add_action( 'admin_init', 'qrn_sms_manager_create_tables' );

// Simple helper to log a campaign.
function qrn_sms_log_campaign( $args ) {
    global $wpdb;

    $defaults = [
        'business_key'      => '',
        'message'           => '',
        'mode'              => 'now', // now|scheduled
        'scheduled_at'      => null,
        'sent_at'           => current_time( 'mysql' ),
        'total_subscribers' => 0,
        'sent_ok'           => 0,
        'sent_failed'       => 0,
        'provider'          => 'twilio',
    ];
    $args = wp_parse_args( $args, $defaults );

    if ( empty( $args['business_key'] ) || empty( $args['message'] ) ) {
        return;
    }

    // Determine provider from global settings (for logging)
    $settings = get_option( 'qrn_sms_settings', [] );
    $provider = isset( $settings['provider'] ) ? $settings['provider'] : 'twilio';
    $args['provider'] = isset( $args['provider'] ) ? $args['provider'] : $provider;

    $table = $wpdb->prefix . 'qrn_sms_campaigns';

    $wpdb->insert( $table, [
        'business_key'      => $args['business_key'],
        'message'           => $args['message'],
        'mode'              => $args['mode'],
        'scheduled_at'      => $args['scheduled_at'],
        'sent_at'           => $args['sent_at'],
        'total_subscribers' => (int) $args['total_subscribers'],
        'sent_ok'           => (int) $args['sent_ok'],
        'sent_failed'       => (int) $args['sent_failed'],
        'created_by'        => get_current_user_id(),
        'created_at'        => current_time( 'mysql' ),
    ] );
}

// Admin page: Campaign Reports (blue accent, CSV export).
function qrn_sms_render_reports_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'qrn_sms_campaigns';

// 🔹 Handle manual delete BEFORE any output is sent.
    if ( isset( $_GET['qrn_delete_campaign'], $_GET['_wpnonce'] ) ) {
        $delete_id = (int) $_GET['qrn_delete_campaign'];

        if ( $delete_id > 0 && wp_verify_nonce( $_GET['_wpnonce'], 'qrn_delete_campaign_' . $delete_id ) ) {
            $wpdb->delete(
                $table,
                array( 'id' => $delete_id ),
                array( '%d' )
            );
        }
    }
         // Handle bulk delete of campaigns via POST.
    if (
        isset( $_POST['qrn_campaign_bulk_delete'], $_POST['qrn_campaign_bulk_nonce'] )
        && wp_verify_nonce( wp_unslash( $_POST['qrn_campaign_bulk_nonce'] ), 'qrn_campaign_bulk_delete' )
        && ! empty( $_POST['qrn_campaign_ids'] )
        && is_array( $_POST['qrn_campaign_ids'] )
    ) {
        $ids = array_map( 'intval', $_POST['qrn_campaign_ids'] );
        $ids = array_filter( $ids );

        if ( ! empty( $ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $sql          = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
            $wpdb->query( $wpdb->prepare( $sql, $ids ) );
        }
    }

    // Handle "delete ALL campaigns for this business".
    if ( isset( $_GET['qrn_delete_all_campaigns'], $_GET['business'], $_GET['_wpnonce'] ) ) {
        $biz_to_delete = sanitize_text_field( wp_unslash( $_GET['business'] ) );
        if ( $biz_to_delete && wp_verify_nonce( $_GET['_wpnonce'], 'qrn_delete_all_campaigns_' . $biz_to_delete ) ) {
            $wpdb->delete(
                $table,
                array( 'business_key' => $biz_to_delete ),
                array( '%s' )
            );
        }
    }
    // Filters
     $business_filter = isset( $_GET['business'] ) ? sanitize_text_field( wp_unslash( $_GET['business'] ) ) : '';
     $export          = isset( $_GET['qrn_export'] ) ? (int) $_GET['qrn_export'] : 0;
     $print           = isset( $_GET['print'] ) ? 1 : 0;

     $where  = 'WHERE 1=1';

     $params = [];

    if ( $business_filter !== '' ) {
        $where   .= ' AND business_key = %s';
        $params[] = $business_filter;
    }
    // Build "delete all campaigns for this business" URL (only when filtered by a business).
$delete_all_campaigns_url = '';
if ( $business_filter !== '' ) {
    $delete_all_campaigns_url = wp_nonce_url(
        add_query_arg(
            array(
                'page'                    => 'qrn-sms-reports',
                'business'                => $business_filter,
                'qrn_delete_all_campaigns'=> 1,
            ),
            admin_url( 'admin.php' )
        ),
        'qrn_delete_all_campaigns_' . $business_filter
    );
}

    $sql = "SELECT * FROM $table $where ORDER BY sent_at DESC LIMIT 200";

    $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
                    : $wpdb->get_results( $sql );
    

        // CSV Export
     if ( $export ) {

         // Clean any previous output to avoid "headers already sent"
         if ( ob_get_length() ) {
             ob_end_clean();
         }

         header( 'Content-Type: text/csv; charset=utf-8' );
         header( 'Content-Disposition: attachment; filename=qrn-campaign-report.csv' );

         $out = fopen( 'php://output', 'w' );
         fputcsv( $out, [ 'Sent At', 'Business', 'Mode', 'Message', 'Subscribers', 'Sent OK', 'Failed' ] );

         if ( ! empty( $rows ) ) {
             foreach ( $rows as $r ) {
                 fputcsv( $out, [
                     $r->sent_at ?: $r->scheduled_at,
                     $r->business_key,
                     strtoupper( $r->mode ),
                    mb_substr( $r->message, 0, 160 ),
                    (int) $r->total_subscribers,
                     (int) $r->sent_ok,
                     (int) $r->sent_failed,
                 ] );
             }
         }

         fclose( $out );
         exit;
     }

    // Print-friendly mode: hide admin chrome when ?print=1
     if ( $print ) {
         echo '<style>
            #adminmenumain, #wpadminbar, #wpfooter { display:none !important; }
             #wpcontent { margin-left:0 !important; }
             .qrn-report-card { box-shadow:none; border:none; }
         </style>';
         echo '<script>
        window.addEventListener("load", function() {
            window.print();
        });
    </script>';
     }
     ?>
    <style>
      .qrn-report-wrap h1 {
          margin-bottom: 10px;
      }
      .qrn-report-card {
          background: #ffffff;
          border-radius: 10px;
          padding: 16px 20px;
          box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
          border: 1px solid #e5e7eb;
          margin-bottom: 20px;
      }
             .qrn-report-header {
           display: flex;
          justify-content: space-between;
          align-items: center;
           gap: 16px;
           margin-bottom: 14px;
           flex-wrap: wrap;
       }
       .qrn-report-title {
          font-size: 20px;
           font-weight: 600;
           color: #0f172a;
       }
       .qrn-report-actions form {
           display: flex;
           flex-wrap: wrap;
           gap: 12px;
           align-items: center;
           justify-content: flex-end;
       }
       .qrn-report-actions select {
          padding: 6px 10px;
           border-radius: 6px;
           border: 1px solid #cbd5f5;
           font-size: 13px;
           min-width: 200px;
       }
       .qrn-report-actions button {
           padding: 6px 12px;
           border-radius: 6px;
           background: #2563eb;
           color: #fff;
           border: 1px solid #2563eb;
           cursor: pointer;
       }

      .qrn-report-actions select,
      .qrn-report-actions button {
          padding: 6px 10px;
          border-radius: 6px;
          border: 1px solid #cbd5f5;
          font-size: 13px;
      }
      .qrn-report-actions button {
          background: #2563eb;
          color: #ffffff;
          border-color: #2563eb;
          cursor: pointer;
      }
      .qrn-report-actions button:hover {
          background: #1d4ed8;
      }
      .qrn-report-table {
          width: 100%;
          border-collapse: collapse;
          margin-top: 8px;
      }
      .qrn-report-table th,
      .qrn-report-table td {
          padding: 8px 10px;
          font-size: 13px;
          border-bottom: 1px solid #e5e7eb;
      }
      .qrn-report-table th {
          text-align: left;
          color: #475569;
          background: #eff6ff;
      }
     .qrn-badge {
           display: inline-flex;
           align-items: center;
          padding: 2px 8px;
           border-radius: 999px;
           font-size: 11px;
          font-weight: 500;
       }
       .qrn-badge-now {
           background: #dbeafe;
           color: #1d4ed8;
       }
       .qrn-badge-scheduled {
           background: #fef3c7;
           color: #92400e;
       }
       .qrn-status-ok {
           background:#dcfce7;
           color:#166534;
           padding:3px 8px;
           border-radius:6px;
           font-size:12px;
       }
       .qrn-status-partial {
           background:#fef3c7;
           color:#92400e;
           padding:3px 8px;
           border-radius:6px;
           font-size:12px;
       }
       .qrn-status-failed {
           background:#fee2e2;
           color:#b91c1c;
           padding:3px 8px;
           border-radius:6px;
          font-size:12px;
       }
       .qrn-status-empty {
           background:#e5e7eb;
           color:#374151;
           padding:3px 8px;
           border-radius:6px;
           font-size:12px;
       }
       .qrn-delete-link {
           display:inline-flex;
           align-items:center;
           padding:4px 10px;
           border-radius:999px;
           font-size:12px;
           border:1px solid #fecaca;
           background:#fef2f2;
           color:#b91c1c;
           text-decoration:none;
       }
       .qrn-delete-link:hover {
           background:#fee2e2;
           border-color:#fca5a5;
           color:#991b1b;
       }
       .qrn-info {
           background: #eef6ff;
           border: 1px solid #c7e2ff;
           color: #084d8a;
           padding: 12px 16px;
           border-radius: 6px;
           margin: 10px 0 20px;
           font-size: 14px;
    }
    /* Print styles – make Campaign Reports clean and client-friendly */
    @media print {

        /* Hide WordPress admin chrome */
        #wpadminbar,
        #adminmenuback,
        #adminmenuwrap,
        #screen-meta-links,
        #screen-meta,
        .notice,
        .update-nag {
            display: none !important;
        }

        /* Hide filters, bulk actions, CSV/Print buttons, info box */
        .qrn-info,
        .tablenav,
        .search-box,
        .qrn-report-actions,
        .row-actions {
            display: none !important;
        }

        /* Hide checkbox column (far left) */
        .qrn-report-table th.check-column,
        .qrn-report-table td.check-column {
            display: none !important;
        }

        /* Hide delete links inside the table */
        .qrn-report-table .qrn-delete-link {
            display: none !important;
        }

        /* Make report use the full page width */
        body.wp-admin {
            background: #ffffff !important;
        }

        .qrn-report-wrap {
            margin: 0 !important;
            padding: 0 !important;
        }

        .qrn-report-card {
            box-shadow: none !important;
            border: none !important;
            padding: 0 !important;
        }

        .qrn-report-table {
            width: 100% !important;
            border-collapse: collapse;
            font-size: 13px;
        }

        .qrn-report-table th,
        .qrn-report-table td {
            border: 1px solid #dddddd;
            padding: 6px 8px;
        }
     /* Hide checkbox column (first column) */
    .qrn-report-table th:first-child,
    .qrn-report-table td:first-child {
        display: none !important;
    }

    /* Hide Actions column (10th column) */
    .qrn-report-table th:nth-child(10),
    .qrn-report-table td:nth-child(10) {
        display: none !important;
    }

    /* Hide Status column (11th column – "Sent to Twilio") */
    .qrn-report-table th:nth-child(11),
    .qrn-report-table td:nth-child(11) {
        display: none !important;
    }
}


</style>


</style>

    <div class="wrap qrn-report-wrap">
      <h1>Campaign Reports</h1>
    <div class="qrn-info">
         Delivery stats update automatically after Twilio processes each message. 
         This may take a few seconds. The page will auto-refresh until final delivery results appear.
      </div>

      <div class="qrn-report-card">
        <div class="qrn-report-header">
          <div class="qrn-report-title">Recent Campaigns</div>
          <div class="qrn-report-actions">
            <form method="get">
              <input type="hidden" name="page" value="qrn-sms-reports" />
              <select name="business">
                <option value="">All businesses</option>
                <?php
                $biz_sql  = "SELECT DISTINCT business_key FROM $table ORDER BY business_key ASC";
                $biz_list = $wpdb->get_col( $biz_sql );
                foreach ( $biz_list as $biz ) {
                    printf(
                        '<option value="%1$s"%2$s>%1$s</option>',
                        esc_attr( $biz ),
                        selected( $business_filter, $biz, false )
                    );
                }
                ?>
              </select>
                <button type="submit">Filter</button>
                <button type="button" id="qrn-download-csv">Download CSV</button>
                <a href="<?php echo admin_url( 'admin.php?page=qrn-sms-reports&business=' . urlencode( $business_filter ) . '&print=1' ); ?>"
                   target="_blank"
                   style="padding:6px 12px; border-radius:6px; background:#4b5563; color:#fff; text-decoration:none; display:inline-flex; align-items:center;">
                Print / PDF
              </a>
              </form>
             </div>
            </div>

        <?php if ( empty( $rows ) ) : ?>
          <p>No campaigns found yet.</p>
        <?php else : ?>
          <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field( 'qrn_campaign_bulk_delete', 'qrn_campaign_bulk_nonce' ); ?>
            <table class="qrn-report-table">
              <thead>
                <tr>
                  <th>
                    <input type="checkbox" id="qrn-campaign-select-all" />
                  </th>
                  <th>Sent At</th>
                  <th>Business</th>
                  <th>Mode</th>
                  <th>Message</th>
                  <th>Subscribers</th>
                  <th>Sent OK</th>
                  <th>Failed</th>
                  <th>Delivery</th>
                  <th>Actions</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ( $rows as $r ) : ?>
                <?php
                  $total  = (int) $r->total_subscribers;
                  $ok     = (int) $r->sent_ok;
                  $failed = (int) $r->sent_failed;

                  // Status badge (same logic as before)
                  if ( $total === 0 ) {
                    $status_label = 'No subscribers';
                    $status_class = 'qrn-status-empty';
                  } elseif ( $ok > 0 && $failed === 0 ) {
                    $status_label = 'Sent to Twilio';
                    $status_class = 'qrn-status-ok';
                  } elseif ( $ok > 0 && $failed > 0 ) {
                    $status_label = 'Partial send';
                    $status_class = 'qrn-status-partial';
                  } else {
                    $status_label = 'Failed';
                    $status_class = 'qrn-status-failed';
                  }

                  // 🔹 Delivery stats from qrn_sms_messages (same logic, just moved inside)
                  $delivered_count   = 0;
                  $undelivered_count = 0;

                  $campaign_sent_at = $r->sent_at ?: $r->scheduled_at;
                  $msg_table        = $wpdb->prefix . 'qrn_sms_messages';

                  if ( $campaign_sent_at && $msg_table ) {
                    $center = strtotime( $campaign_sent_at );

                    if ( $center ) {
                      // 10-minute window around the campaign send time
                      $window_start = date( 'Y-m-d H:i:s', $center - 600 );
                      $window_end   = date( 'Y-m-d H:i:s', $center + 600 );

                      // Delivered
                      $delivered_count = (int) $wpdb->get_var(
                        $wpdb->prepare(
                          "SELECT COUNT(*) FROM {$msg_table}
                           WHERE business_key = %s
                             AND status = %s
                             AND created_at BETWEEN %s AND %s",
                          $r->business_key,
                          'delivered',
                          $window_start,
                          $window_end
                        )
                      );

                      // Undelivered / failed
                      $undelivered_count = (int) $wpdb->get_var(
                        $wpdb->prepare(
                          "SELECT COUNT(*) FROM {$msg_table}
                           WHERE business_key = %s
                             AND status IN ('undelivered','failed')
                             AND created_at BETWEEN %s AND %s",
                          $r->business_key,
                          $window_start,
                          $window_end
                        )
                      );
                    }
                  }
                ?>
                <tr>
                  <!-- NEW: checkbox column -->
                  <td>
                    <input type="checkbox"
                      name="qrn_campaign_ids[]"
                      value="<?php echo (int) $r->id; ?>"
                      class="qrn-campaign-select-row" />
                  </td>

                  <!-- Sent At -->
                  <td>
                    <?php
                    $raw_datetime = $r->sent_at ?: $r->scheduled_at;

                    if ( $raw_datetime ) {
                      $ts = strtotime( $raw_datetime );
                      if ( $ts ) {
                        $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
                        echo esc_html( date_i18n( $format, $ts ) );
                      } else {
                        echo esc_html( $raw_datetime );
                      }
                    } else {
                      echo '—';
                    }
                    ?>
                  </td>

                  <!-- Business -->
                  <td><?php echo esc_html( $r->business_key ); ?></td>

                  <!-- Mode -->
                  <td>
                    <?php if ( 'now' === $r->mode ) : ?>
                      <span class="qrn-badge qrn-badge-now">Send now</span>
                    <?php else : ?>
                      <span class="qrn-badge qrn-badge-scheduled">Scheduled</span>
                    <?php endif; ?>
                  </td>

                  <!-- Message preview -->
                  <td><?php echo esc_html( mb_substr( $r->message, 0, 80 ) ); ?>…</td>

                  <!-- Subscribers, Sent OK, Failed -->
                  <td><?php echo (int) $r->total_subscribers; ?></td>
                  <td><?php echo (int) $r->sent_ok; ?></td>
                  <td><?php echo (int) $r->sent_failed; ?></td>

                  <!-- Delivery column -->
                  <td>
                    <?php
                    if ( $delivered_count === 0 && $undelivered_count === 0 ) {
                      echo '—';
                    } else {
                      printf(
                        'Delivered %d / Undelivered %d',
                        $delivered_count,
                        $undelivered_count
                      );
                    }
                    ?>
                  </td>

                  <!-- Actions -->
                  <td>
                    <?php
                    // Build secure delete URL with nonce (same as before).
                    $delete_url = wp_nonce_url(
                      add_query_arg(
                        array(
                          'page'                => 'qrn-sms-reports',
                          'qrn_delete_campaign' => (int) $r->id,
                          'business'            => $business_filter,
                        ),
                        admin_url( 'admin.php' )
                      ),
                      'qrn_delete_campaign_' . (int) $r->id
                    );
                    ?>
                    <a href="<?php echo esc_url( $delete_url ); ?>" class="qrn-delete-link">Delete</a>
                  </td>

                  <!-- Status badge -->
                  <td>
                    <span class="<?php echo esc_attr( $status_class ); ?>">
                      <?php echo esc_html( $status_label ); ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <div class="tablenav bottom" style="margin-top:8px;">
              <select name="qrn_campaign_bulk_action">
                <option value=""><?php esc_html_e( 'Bulk actions' ); ?></option>
                <option value="delete"><?php esc_html_e( 'Delete' ); ?></option>
              </select>
              <button type="submit"
                name="qrn_campaign_bulk_delete"
                value="1"
                class="button"
                onclick="return confirm('Delete selected campaigns?');">
                <?php esc_html_e( 'Apply' ); ?>
              </button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

        <script>
    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('qrn-download-csv');
        if (btn) {
            btn.addEventListener('click', function() {
                var table = document.querySelector('.qrn-report-table');
                if (!table) return;

                var rows = table.querySelectorAll('tr');
                var csv = '';

                rows.forEach(function(row) {
                    var cols = row.querySelectorAll('th, td');
                    var cells = [];
                    cols.forEach(function(col) {
                        var text = col.innerText.replace(/"/g, '""');
                        cells.push('"' + text + '"');
                    });
                    csv += cells.join(',') + "\n";
                });

                var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = 'qrn-campaign-report.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            });
        }

        // 🔹 Delete confirmation for campaign rows
        var delLinks = document.querySelectorAll('.qrn-delete-link');
        delLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                var ok = window.confirm(
                    'Delete this campaign from reports? This will remove the log entry but will NOT resend any messages.'
                );
                if (!ok) {
                    e.preventDefault();
                }
            });
        });

        // 🔹 Bulk-select: "select all" checkbox for campaigns
        var selectAll = document.getElementById('qrn-campaign-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                var checkboxes = document.querySelectorAll('.qrn-campaign-select-row');
                checkboxes.forEach(function(cb) {
                    cb.checked = selectAll.checked;
                });
            });
        }
    });

    // 🔄 Auto-refresh the Campaign Reports page every 10 seconds
    (function() {
        // Make sure the table exists (only on Campaign Reports page)
        var reportTable = document.querySelector('.qrn-report-table');
        if (!reportTable) return;

        // Refresh the entire page every 10 seconds
        setInterval(function() {
            location.reload();
        }, 10000); // 10 seconds
    })();
 </script>

<?php
}
/**
 * Admin page: Message Log (per-message delivery log from qrn_sms_messages).
 */
function qrn_sms_render_message_log_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'qrn_sms_messages';

    // Handle single row delete BEFORE any output.
    if ( isset( $_GET['qrn_delete_message'], $_GET['_wpnonce'] ) ) {
        $delete_id = (int) $_GET['qrn_delete_message'];

        if ( $delete_id > 0 && wp_verify_nonce( $_GET['_wpnonce'], 'qrn_delete_message_' . $delete_id ) ) {
            $wpdb->delete(
                $table,
                array( 'id' => $delete_id ),
                array( '%d' )
            );
        }
    }
     // Handle bulk delete via POST (selected rows).
    if (
        isset( $_POST['qrn_bulk_delete'], $_POST['qrn_bulk_nonce'] )
        && wp_verify_nonce( wp_unslash( $_POST['qrn_bulk_nonce'] ), 'qrn_bulk_delete_messages' )
        && ! empty( $_POST['qrn_message_ids'] )
        && is_array( $_POST['qrn_message_ids'] )
    ) {
        $ids = array_map( 'intval', $_POST['qrn_message_ids'] );
        $ids = array_filter( $ids );

        if ( ! empty( $ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $sql          = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
            $wpdb->query( $wpdb->prepare( $sql, $ids ) );
        }
    }

    // Handle "delete all logs for this business" via GET.
    if ( isset( $_GET['qrn_delete_all_business'], $_GET['business'], $_GET['_wpnonce'] ) ) {
        $biz_to_delete = sanitize_text_field( wp_unslash( $_GET['business'] ) );
        if ( $biz_to_delete && wp_verify_nonce( $_GET['_wpnonce'], 'qrn_delete_all_business_' . $biz_to_delete ) ) {
            $wpdb->delete(
                $table,
                array( 'business_key' => $biz_to_delete ),
                array( '%s' )
            );
        }
    }

    // Filters & paging.
    $business_filter = isset( $_GET['business'] ) ? sanitize_text_field( wp_unslash( $_GET['business'] ) ) : '';
    $status_filter   = isset( $_GET['status'] )   ? sanitize_text_field( wp_unslash( $_GET['status'] ) )   : '';
    $search          = isset( $_GET['s'] )        ? sanitize_text_field( wp_unslash( $_GET['s'] ) )        : '';
    $per_page        = 50;
    $page            = isset( $_GET['paged'] )    ? max( 1, (int) $_GET['paged'] )                         : 1;
    $offset          = ( $page - 1 ) * $per_page;

    $where  = 'WHERE 1=1';
    $params = array();

    if ( $business_filter !== '' ) {
        $where   .= ' AND business_key = %s';
        $params[] = $business_filter;
    }

    if ( $status_filter !== '' && $status_filter !== 'all' ) {
        $where   .= ' AND status = %s';
        $params[] = $status_filter;
    }

    if ( $search !== '' ) {
        $like     = '%' . $wpdb->esc_like( $search ) . '%';
        $where   .= ' AND (to_phone LIKE %s OR twilio_sid LIKE %s)';
        $params[] = $like;
        $params[] = $like;
    }

    // Build "delete all logs for this business" URL (only if a business filter is selected).
    $delete_all_url = '';
    if ( $business_filter !== '' ) {
        $delete_all_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page'                    => 'qrn-sms-message-log',
                    'business'                => $business_filter,
                    'qrn_delete_all_business' => 1,
                ),
                admin_url( 'admin.php' )
            ),
            'qrn_delete_all_business_' . $business_filter
        );
    }

    // Total count for pagination.
    $sql_count   = "SELECT COUNT(*) FROM $table $where";
    $total_items = $params
        ? (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $params ) )
        : (int) $wpdb->get_var( $sql_count );

    $total_pages = $total_items > 0 ? ceil( $total_items / $per_page ) : 1;

   // Fetch rows.
$sql_rows_base = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d";

$params_rows   = $params;
$params_rows[] = $per_page;
$params_rows[] = $offset;

$rows = $wpdb->get_results(
    $wpdb->prepare( $sql_rows_base, $params_rows )
);

    ?>
    <div class="wrap qrn-log-wrap">
        <h1 class="wp-heading-inline">Message Log</h1>
        <p class="description">
            Live log of all SMS messages sent by QR Neighbor SMS Manager, including Welcome SMS and campaign sends.
        </p>

        <div class="qrn-report-card qrn-log-card">
            <div class="qrn-report-header qrn-log-header">
                <div class="qrn-report-title qrn-log-title">Recent Messages</div>
                <div class="qrn-report-actions qrn-log-actions">
                    <form method="get">
                        <input type="hidden" name="page" value="qrn-sms-message-log" />
                        <select name="business">
                            <option value="">All businesses</option>
                            <?php
                            $biz_sql  = "SELECT DISTINCT business_key FROM $table ORDER BY business_key ASC";
                            $biz_list = $wpdb->get_col( $biz_sql );

                            foreach ( $biz_list as $biz ) {
                                printf(
                                    '<option value="%1$s"%2$s>%1$s</option>',
                                    esc_attr( $biz ),
                                    selected( $business_filter, $biz, false )
                                );
                            }
                            ?>
                        </select>

                        <select name="status">
                          <option value="all"<?php selected( $status_filter, 'all' ); ?>>All Twilio statuses</option>
                          <option value="queued"<?php selected( $status_filter, 'queued' ); ?>>Queued</option>
                          <option value="sending"<?php selected( $status_filter, 'sending' ); ?>>Sending</option>
                          <option value="sent"<?php selected( $status_filter, 'sent' ); ?>>Sent</option>
                          <option value="delivered"<?php selected( $status_filter, 'delivered' ); ?>>Delivered</option>
                          <option value="undelivered"<?php selected( $status_filter, 'undelivered' ); ?>>Undelivered</option>
                          <option value="failed"<?php selected( $status_filter, 'failed' ); ?>>Failed</option>
                        </select>

                         <input
                            type="search"
                            name="s"
                            placeholder="Search phone or SID…"
                            value="<?php echo esc_attr( $search ); ?>"
                        />

                        <button type="submit" class="button">Filter</button>
                    </form>
                </div>
            </div>

            <div class="qrn-log-table-wrapper">
    <form method="post">
        <?php wp_nonce_field( 'qrn_bulk_delete_messages', 'qrn_bulk_nonce' ); ?>
        <input type="hidden" name="page" value="qrn-sms-message-log" />

        <div class="tablenav top" style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
            <div class="alignleft actions bulkactions">
                <select name="qrn_bulk_action">
                    <option value="">Bulk actions</option>
                    <option value="delete">Delete</option>
                </select>
                <button type="submit"
                        name="qrn_bulk_delete"
                        value="1"
                        class="button"
                        onclick="return confirm('Delete selected log entries?');">
                    Apply
                </button>
            </div>
            <div class="alignright">
                <?php if ( ! empty( $delete_all_url ) ) : ?>
                    <a href="<?php echo esc_url( $delete_all_url ); ?>"
                       class="button button-link-delete"
                       onclick="return confirm('Delete ALL log entries for this business?');">
                        Delete all logs for this business
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <table class="widefat striped fixed qrn-report-table qrn-log-table">
            <thead>
                <tr>
                    <th style="width: 2%;">
                        <input type="checkbox" id="qrn-log-select-all" />
                    </th>
                    <th style="width: 18%;">Date / Time</th>
                    <th style="width: 22%;">Business</th>
                    <th style="width: 10%;">Type</th>
                    <th style="width: 16%;">To</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 12%;">Twilio</th>
                    <th style="width: 18%;">Error</th>
                    <th style="width: 20%;">Twilio SID</th>
                    <th style="width: 6%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                         <?php if ( ! empty( $rows ) ) : ?>
                         <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <!-- NEW: checkbox column -->
                                  <td>
                                    <input type="checkbox"
                                    name="qrn_message_ids[]"
                                    value="<?php echo (int) $row->id; ?>"
                                    class="qrn-log-select-row" />
                               </td>
                               <!-- EXISTING: Date / Time column (unchanged) -->
                                <td>
                                    <?php
                                    if ( ! empty( $row->created_at ) ) {
                                        $ts = strtotime( $row->created_at );
                                        if ( $ts ) {
                                            $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
                                            echo esc_html( date_i18n( $format, $ts ) );
                                        } else {
                                            echo esc_html( $row->created_at );
                                        }
                                    } else {
                                        echo '&mdash;';
                                    }
                                    ?>
                                </td>
                                  
                              <td>
                                    <?php echo $row->business_key ? esc_html( $row->business_key ) : '&mdash;'; ?>
                                </td>

                                <?php
                                // Map `source` column -> human label + CSS class.
                                $source = ! empty( $row->source ) ? $row->source : 'campaign';

                                switch ( $source ) {
                                    case 'welcome':
                                        $source_label = 'Welcome';
                                        $source_class = 'qrn-log-src-welcome';
                                        break;

                                    case 'system':
                                        $source_label = 'System';
                                        $source_class = 'qrn-log-src-system';
                                        break;

                                    default:
                                        $source_label = 'Campaign';
                                        $source_class = 'qrn-log-src-campaign';
                                        break;
                                }
                                ?>
                                <td>
                                    <span class="qrn-log-src-badge <?php echo esc_attr( $source_class ); ?>">
                                        <?php echo esc_html( $source_label ); ?>
                                    </span>
                                </td>

                                <td><?php echo $row->to_phone ? esc_html( $row->to_phone ) : '&mdash;'; ?></td>
                                <td>

                                        <?php
                                         // Raw Twilio status from DB.
                                         $twilio_status = $row->status ? strtolower( $row->status ) : '';

                                        // High-level bucket for human Status column.
                                        if ( in_array( $twilio_status, [ 'sent', 'delivered' ], true ) ) {
                                             $status_label = 'Sent';
                                             $status_class = 'qrn-log-status-sent';
                                           } elseif ( in_array( $twilio_status, [ 'failed', 'undelivered' ], true ) ) {
                                             $status_label = 'Failed';
                                             $status_class = 'qrn-log-status-failed';
                                           } elseif ( in_array( $twilio_status, [ 'queued', 'sending' ], true ) ) {
                                             $status_label = 'Pending';
                                             $status_class = 'qrn-log-status-queued';
                                           } else {
                                             $status_label = 'Unknown';
                                            $status_class = 'qrn-log-status-unknown';
                                           }
                                       ?>
                                    <span class="qrn-log-status-badge <?php echo esc_attr( $status_class ); ?>">
                            <?php echo esc_html( $status_label ); ?>
                            </span>
                        </td>

                                  <!-- NEW: Twilio column showing the exact status text -->
                         <td>
                              <?php if ( $twilio_status ) : 
        $twilio_class = 'qrn-twilio-status-' . sanitize_html_class( $twilio_status );
    ?>
        <span class="qrn-twilio-badge <?php echo esc_attr( $twilio_class ); ?>">
            <?php echo esc_html( ucfirst( $twilio_status ) ); ?>
        </span>
    <?php else : ?>
        &mdash;
    <?php endif; ?>
                         </td>

                         <td>
                              <?php
                                      if ( ! empty( $row->error_code ) ) {
                                              echo esc_html( $row->error_code );
                                        } else {
                                       echo '&mdash;';
                                        }
                                        ?>
                                     </td>
                                      <td><code style="font-size:11px;"><?php echo esc_html( $row->twilio_sid ); ?></code></td>
                                     <td>
                                        <?php
                                        $delete_url = wp_nonce_url(
                                            add_query_arg(
                                                array(
                                                    'page'                => 'qrn-sms-message-log',
                                                    'qrn_delete_message'  => (int) $row->id,
                                                    'paged'               => $page,
                                                    'business'            => $business_filter,
                                                    'status'              => $status_filter,
                                                    's'                   => $search,
                                                ),
                                                admin_url( 'admin.php' )
                                            ),
                                            'qrn_delete_message_' . (int) $row->id
                                        );
                                        ?>
                                        <a href="<?php echo esc_url( $delete_url ); ?>"
                                           class="qrn-delete-link"
                                           onclick="return confirm('Delete this log entry? This cannot be undone.');">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7">
                                    <?php if ( $business_filter || ( $status_filter && 'all' !== $status_filter ) || $search ) : ?>
                                        No messages found for the current filters.
                                    <?php else : ?>
                                        No SMS messages have been logged yet.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                            </tbody>
                            </table>
                           <div class="tablenav bottom" style="margin-top:8px;">
                           <button type="submit"
                          name="qrn_bulk_delete"
                          value="1"
                          class="button"
                          onclick="return confirm('Delete selected log entries?');">
                Delete selected
            </button>
        </div>
    </form>
</div> <!-- .qrn-log-table-wrapper -->


            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( array(
                            'base'      => add_query_arg( array(
                                'page'     => 'qrn-sms-message-log',
                                'business' => $business_filter,
                                'status'   => $status_filter,
                                's'        => $search,
                                'paged'    => '%#%',
                            ) ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $page,
                        ) );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .qrn-log-card {
                margin-top: 15px;
            }
            .qrn-log-header {
                margin-bottom: 12px;
            }
            .qrn-log-actions form {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
                justify-content: flex-end;
            }
            .qrn-log-actions select,
            .qrn-log-actions input[type="search"] {
                padding: 6px 10px;
                border-radius: 6px;
                border: 1px solid #cbd5f5;
                font-size: 13px;
                min-width: 160px;
            }
            .qrn-log-actions .button {
                padding: 6px 12px;
            }
            .qrn-log-table th,
            .qrn-log-table td {
                font-size: 13px;
            }
            .qrn-log-status-badge {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 500;
                background: #e5e7eb;
                color: #111827;
            }
            .qrn-log-status-delivered,
            .qrn-log-status-sent {
                background: #dcfce7;
                color: #166534;
            }
            .qrn-log-status-undelivered,
            .qrn-log-status-failed {
                background: #fee2e2;
                color: #b91c1c;
            }
            .qrn-log-status-queued,
            .qrn-log-status-sending {
                background: #eff6ff;
                color: #1d4ed8;
            }
        /* Twilio raw status badges (outline version) */
.qrn-twilio-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 500;
    border: 1px solid #d4d4d8; /* default gray border */
    color: #444;
    background: #fafafa;
}

/* Green outline for delivered/sent */
.qrn-twilio-status-delivered,
.qrn-twilio-status-sent {
    border-color: #4ade80;
    color: #166534;
}
            /* Source / Type badges (campaign / welcome / system) */
            .qrn-provider-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 500;
        border: 1px solid #e5e7eb;
        background: #f9fafb;
        color: #111827;
    }
    .qrn-provider-twilio {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }
    .qrn-provider-telnyx {
        background: #ecfdf5;
        border-color: #6ee7b7;
        color: #047857;
    }
.qrn-log-src-badge {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 500;
                border: 1px solid #e5e7eb;
                background: #f9fafb;
                color: #374151;
            }
            .qrn-log-src-campaign {
                border-color: #a7f3d0;
                background: #ecfdf5;
                color: #047857;
            }
            .qrn-log-src-welcome {
                border-color: #bfdbfe;
                background: #eff6ff;
                color: #1d4ed8;
            }
            .qrn-log-src-system {
                border-color: #fde68a;
                background: #fffbeb;
                color: #92400e;
            }

/* Red outline for failed/undelivered */
.qrn-twilio-status-failed,
.qrn-twilio-status-undelivered {
    border-color: #f87171;
    color: #b91c1c;
}

/* Blue outline for queued/sending */
.qrn-twilio-status-queued,
.qrn-twilio-status-sending {
    border-color: #60a5fa;
    color: #1d4ed8;
}
<script>
document.addEventListener('DOMContentLoaded', function() {
    const master = document.getElementById('qrn-log-select-all');
    if (!master) return;
    master.addEventListener('change', function() {
        document.querySelectorAll('.qrn-log-select-row').forEach(function(cb) {
            cb.checked = master.checked;
        });
    });
});
</script>

        </style>
    </div>
    <?php
}

new QRN_SMS_Manager();

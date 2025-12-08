<?php
/*
Plugin Name: QR Neighbor SMS Manager v2
Description: Phase 2 SMS campaign manager for QR Neighbor, using QR Neighbor Leads as the subscriber source.
Version: 0.2.0
Author: QR Neighbor
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'QRN_SMS_Manager_V2' ) ) :

class QRN_SMS_Manager_V2 {

    const CPT_CAMPAIGN = 'qrn_sms_campaign';
    const CPT_LOG      = 'qrn_sms_log';

    const MENU_SLUG    = 'qrn_sms_campaigns';
    const NONCE_ACTION = 'qrn_sms_manager_v2_action';
    const NONCE_NAME   = 'qrn_sms_manager_v2_nonce';

    const CRON_HOOK    = 'qrn_sms_manager_v2_process_scheduled';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpts' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        add_action( 'admin_post_qrn_sms_save_campaign', [ $this, 'handle_save_campaign' ] );
        add_action( 'admin_post_qrn_sms_delete_campaign', [ $this, 'handle_delete_campaign' ] );

        add_action( self::CRON_HOOK, [ $this, 'process_scheduled_campaigns' ] );

        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
    }

    /**
     * Add custom cron schedule (5 minutes).
     */
    public function add_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['qrn_five_minutes'] ) ) {
            $schedules['qrn_five_minutes'] = [
                'interval' => 300,
                'display'  => __( 'Every 5 Minutes' ),
            ];
        }
        return $schedules;
    }

    /**
     * Plugin activation: ensure cron event is scheduled.
     */
    public static function activate() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, 'qrn_five_minutes', self::CRON_HOOK );
        }
    }

    /**
     * Plugin deactivation: clear cron event.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Register CPTs for campaigns and logs.
     */
    public function register_cpts() {
        // Campaign CPT
        register_post_type( self::CPT_CAMPAIGN, [
            'label'         => 'SMS Campaigns',
            'public'        => false,
            'show_ui'       => false, // custom UI
            'show_in_menu'  => false,
            'supports'      => [ 'title', 'author' ],
            'capability_type' => 'post',
            'map_meta_cap'  => true,
        ] );

        // Log CPT
        register_post_type( self::CPT_LOG, [
            'label'         => 'SMS Logs',
            'public'        => false,
            'show_ui'       => false,
            'show_in_menu'  => false,
            'supports'      => [ 'title', 'author' ],
            'capability_type' => 'post',
            'map_meta_cap'  => true,
        ] );
    }

    /**
     * Register main admin menu.
     */
    public function register_admin_menu() {
        add_menu_page(
            'SMS Campaigns',
            'SMS Campaigns',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_campaigns_page' ],
            'dashicons-megaphone',
            27
        );
    }

    /**
     * Load admin CSS and JS for our UI.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'toplevel_page_' . self::MENU_SLUG ) {
            return;
        }

        $css = <<<'CSS'
:root{
  --qr-blue:#0b74ff;
  --qr-text:#1f2933;
  --qr-muted:#6b7280;
  --qr-card:#ffffff;
  --qr-ring:rgba(11,116,255,.12);
  --qr-radius:20px;
}
.qrn-wrap{
  display:flex;
  flex-direction:column;
  gap:20px;
  margin:16px 0 32px 32px; /* moves card LEFT */
  max-width:1400px;        /* makes card area wider */
  padding-right:32px;      /* keeps spacing on right for phone */
}

.qrn-card{
  background:var(--qr-card);
  border:1px solid #e5e7f0;
  border-radius:0px;
  padding:28px 32px;
  box-shadow:0 24px 60px rgba(15,23,42,.08),0 3px 15px rgba(15,23,42,.05);
  display:grid;
  gap:14px;
}
.qrn-card-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
}
.qrn-title{
  font-weight:900;
  font-size:20px;
  color:var(--qr-text);
  margin:0 0 2px;
}
.qrn-meta{
  color:var(--qr-muted);
  font-size:13px;
  margin:2px 0;
}
.qrn-badge{
  display:inline-block;
  background:#eef4ff;
  color:var(--qr-blue);
  font-weight:800;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  margin-bottom:6px;
  letter-spacing:.2px;
}
.qrn-actions{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:nowrap;
}
.qrn-btn{
  background:var(--qr-blue);
  color:#fff;
  border:none;
  padding:9px 14px;
  border-radius:999px;
  font-weight:700;
  cursor:pointer;
  box-shadow:0 10px 24px rgba(11,116,255,.18);
  text-decoration:none;
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-size:13px;
}
.qrn-btn-secondary{
  background:#fff;
  color:var(--qr-blue);
  border:1px solid rgba(11,116,255,.25);
  box-shadow:none;
}
.qrn-btn-sm{
  padding:6px 10px;
  font-size:12px;
}
.qrn-tabs{
  border-bottom:1px solid #e5e7eb;
  display:flex;
  gap:18px;
  margin-top:6px;
}
.qrn-tab{
  padding:10px 0;
  font-size:13px;
  font-weight:600;
  color:var(--qr-muted);
  text-decoration:none;
  border-bottom:2px solid transparent;
  display:inline-block;
}
.qrn-tab-active{
  color:var(--qr-blue);
  border-color:var(--qr-blue);
}
.qrn-form{
  background:#ffffff;
  border:0;
  border-radius:0;
  padding:0;
  margin-top:4px;
}
.qrn-form .row{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
.qrn-form .row-1{
  display:grid;
  grid-template-columns:1fr;
  gap:12px;
}
.qrn-form label{
  font-size:13px;
  font-weight:600;
  color:var(--qr-text);
  margin-bottom:3px;
  display:block;
}
.qrn-form input,
.qrn-form textarea,
.qrn-form select{
  width:100%;
  padding:10px 12px;
  border:1px solid #e5e7eb;
  border-radius:12px;
  font-size:14px;
  outline:none;
  box-sizing:border-box;
  background:#fff;
}
.qrn-form textarea{
  min-height:110px;
  resize:vertical;
  resize:horizontal
}
.qrn-toggle-group{
  display:flex;
  gap:10px;
  margin-top:6px;
  flex-wrap:wrap;
}

.qrn-toggle-option{
  position:relative;
}

.qrn-toggle-option input[type="radio"]{
  position:absolute;
  opacity:0;
  pointer-events:none;
}

.qrn-toggle-label{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:7px 14px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  font-size:13px;
  cursor:pointer;
  background:#ffffff;
}
.qrn-toggle-dot{
  display:none;
}
.qrn-toggle-option input[type="radio"]:checked + .qrn-toggle-label{
  border-color:var(--qr-blue);
  background:rgba(11,116,255,.06);
}

.qrn-toggle-option input[type="radio"]:checked + .qrn-toggle-label .qrn-toggle-dot{
  background:var(--qr-blue);
}
.qrn-form input:focus,
.qrn-form textarea:focus,
.qrn-form select:focus{
  border-color:var(--qr-blue);
  box-shadow:0 0 0 3px var(--qr-ring);
}
.qrn-two-col{
  display:grid;
  grid-template-columns:2.8fr 1.2fr; /* form bigger, phone tighter */
  gap:40px;                           /* nicer spacing */
  align-items:flex-start;
  margin-top:16px;
}
.qrn-two-col-main{
  min-width:0;
}
.qrn-two-col-side{
  min-width:0;
  display:flex;
  justify-content:center;
}
.qrn-phone{
  max-width:260px;
  width:100%;
  display:flex;
  justify-content:center;
}
.qrn-phone-frame{
  background:#000000;
  border-radius:34px;
  padding:10px 8px 16px;
  box-shadow:0 22px 50px rgba(15,23,42,.45);
}
.qrn-phone-screen{
  background:#ffffff;
  border-radius:26px;
  padding:24px 14px 14px;
  position:relative;
  overflow:hidden;
  height:420px;
  box-sizing:border-box;
}
.qrn-phone-notch{
  position:absolute;
  top:8px;
  left:50%;
  transform:translateX(-50%);
  width:78px;
  height:10px;
  border-radius:999px;
  background:#000000;
}
.qrn-phone-header{
  font-size:11px;
  font-weight:600;
  color:#9ca3af;
  margin-bottom:10px;
}
.qrn-phone-bubble{
  display:inline-block;
  background:var(--qr-blue);
  color:#ffffff;
  padding:8px 10px;
  border-radius:18px 18px 4px 18px;
  font-size:13px;
  line-height:1.4;
  max-width:85%;
  word-wrap:break-word;
  word-break:break-word;
}
.qrn-phone-footer{
  margin-top:10px;
  font-size:10px;
  color:#9ca3af;
}
.qrn-table-wrap{
  overflow:auto;
  margin-top:8px;
}
.qrn-table{
  width:100%;
  border-collapse:collapse;
  font-size:13px;
}
.qrn-table th,
.qrn-table td{
  padding:8px 10px;
  border-bottom:1px solid #eef2f7;
  text-align:left;
  white-space:nowrap;
}
.qrn-table th{
  font-weight:600;
  color:#4b5563;
  background:#f9fafb;
}
.qrn-status-badge{
  display:inline-block;
  padding:3px 8px;
  border-radius:999px;
  font-size:11px;
  font-weight:600;
}
.qrn-status-scheduled{
  background:#eff6ff;
  color:#1d4ed8;
  border:1px solid #bfdbfe;
}
.qrn-status-sent{
  background:#ecfdf5;
  color:#166534;
  border:1px solid #a7f3d0;
}
.qrn-status-draft{
  background:#fefce8;
  color:#92400e;
  border:1px solid #facc15;
}
.qrn-status-failed{
  background:#fef2f2;
  color:#b91c1c;
  border:1px solid #fecaca;
}
.qrn-notice{
  padding:10px 12px;
  border-radius:10px;
  font-size:13px;
  margin-top:8px;
}
.qrn-notice-ok{
  background:#ecfdf5;
  color:#166534;
  border:1px solid #a7f3d0;
}
.qrn-notice-err{
  background:#fef2f2;
  color:#b91c1c;
  border:1px solid #fecaca;
}
.qrn-message-field{
  max-width:620px;   /* adjust this number to taste */
}
.qrn-header-panel{
  display:flex;
  align-items:center;
  gap:14px;
  background:rgba(11,116,255,0.06);
  border:1px solid rgba(11,116,255,0.12);
  padding:16px 20px;
  margin:0 0 18px 0;
}

.qrn-header-icon{
  width:32px;
  height:32px;
  border-radius:999px;
  background:#0b74ff;
  display:flex;
  align-items:center;
  justify-content:center;
  color:#ffffff;
  font-size:16px;
}

.qrn-header-text h2{
  margin:0;
  font-size:18px;
  font-weight:600;
  color:#0b2541;
}

.qrn-header-text p{
  margin:3px 0 0 0;
  font-size:13px;
  color:#5c6773;
}


@media (max-width:900px){
  .qrn-message-field{
    max-width:100%;
  }
}
@media (max-width:900px){
  .qrn-card-header{
    flex-direction:column;
    align-items:flex-start;
  }
  .qrn-actions{
    width:100%;
    flex-wrap:wrap;
    justify-content:flex-start;
  }
  .qrn-form .row{
    grid-template-columns:1fr;
  }
  .qrn-two-col{
    grid-template-columns:1fr;
  }
  .qrn-two-col-side{
    justify-content:flex-start;
  }
}
CSS;

        wp_register_style( 'qrn-sms-manager-v2-admin', false );
        wp_enqueue_style( 'qrn-sms-manager-v2-admin' );
        wp_add_inline_style( 'qrn-sms-manager-v2-admin', $css );

        $js = <<<'JS'
document.addEventListener('DOMContentLoaded', function(){
  var textarea = document.getElementById('qrn_message_body');
  var counter  = document.getElementById('qrnMessageCounter');
  var bubble   = document.getElementById('qrnPreviewBubble');
  if (!textarea || !counter || !bubble) return;
  function update(){
    var value = textarea.value || '';
    var text = value.trim();
    if (text === '') {
      bubble.textContent = 'Your SMS preview will appear here…';
    } else {
      bubble.textContent = text;
    }
    var len = value.length;
    var segments = 0;
    if (len > 0) {
      segments = len <= 160 ? 1 : Math.ceil(len / 153);
    }
    counter.textContent = len + ' characters • ' + segments + ' segment' + (segments === 1 ? '' : 's');
  }
  textarea.addEventListener('input', update);
  update();
});
JS;

        wp_register_script( 'qrn-sms-manager-v2-admin', '', [], false, true );
        wp_enqueue_script( 'qrn-sms-manager-v2-admin' );
        wp_add_inline_script( 'qrn-sms-manager-v2-admin', $js );
    }

    /**
     * Main admin page controller.
     */
    public function render_campaigns_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'campaigns';
        $msg  = isset( $_GET['qrn_msg'] ) ? sanitize_text_field( $_GET['qrn_msg'] ) : '';
        $err  = isset( $_GET['qrn_err'] ) ? sanitize_text_field( $_GET['qrn_err'] ) : '';
        $base = admin_url( 'admin.php?page=' . self::MENU_SLUG );
        ?>
  
     <div class="wrap">
            <div class="qrn-wrap">
          <div class="qrn-card">
                    <div class="qrn-card-header">
                        <div>
                            <div class="qrn-badge">QR Neighbor</div>
                            <h1 class="qrn-title">SMS Campaigns</h1>
                            <p class="qrn-meta">Create and schedule SMS campaigns using contacts from QR Neighbor Leads.</p>
                        </div>
                        <div class="qrn-actions">
                            <a class="qrn-btn" href="<?php echo esc_url( $base . '&tab=new' ); ?>">New Campaign</a>
                        </div>
                    </div>
                    <div class="qrn-tabs">
                        <a class="qrn-tab <?php echo $tab === 'campaigns' ? 'qrn-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=campaigns' ); ?>">Campaigns</a>
                        <a class="qrn-tab <?php echo $tab === 'new' ? 'qrn-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=new' ); ?>">New Campaign</a>
                        <a class="qrn-tab <?php echo $tab === 'logs' ? 'qrn-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=logs' ); ?>">Logs</a>
                    </div>

                    <?php if ( $msg ) : ?>
                        <div class="qrn-notice qrn-notice-ok"><?php echo esc_html( $msg ); ?></div>
                    <?php endif; ?>

                    <?php if ( $err ) : ?>
                        <div class="qrn-notice qrn-notice-err"><?php echo esc_html( $err ); ?></div>
                    <?php endif; ?>

                    <?php
                    if ( $tab === 'new' ) {
                        $this->render_new_campaign_form();
                    } elseif ( $tab === 'logs' ) {
                        $this->render_logs_table();
                    } else {
                        $this->render_campaigns_table();
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render campaigns table.
     */
    private function render_campaigns_table() {
        $paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
        $per_page = 20;

        $args = [
            'post_type'      => self::CPT_CAMPAIGN,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query( $args );
        ?>
        <div class="qrn-table-wrap">
            <table class="qrn-table">
                <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Business</th>
                    <th>Send Type</th>
                    <th>Scheduled For</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php
                if ( $query->have_posts() ) :
                    while ( $query->have_posts() ) :
                        $query->the_post();
                        $id         = get_the_ID();
                        $business   = get_post_meta( $id, 'qrn_business_name', true );
                        $send_type  = get_post_meta( $id, 'qrn_send_type', true ); // now | schedule
                        $scheduled  = get_post_meta( $id, 'qrn_schedule_timestamp', true );
                        $status     = get_post_meta( $id, 'qrn_status', true ); // draft|scheduled|sent|failed
                        if ( ! $status ) {
                            $status = 'draft';
                        }
                        $created = get_the_date( 'Y-m-d g:i A', $id );
                        $when_str = $scheduled ? date_i18n( 'Y-m-d g:i A', (int) $scheduled ) : '-';

                        $status_class = 'qrn-status-badge qrn-status-draft';
                        if ( $status === 'scheduled' ) {
                            $status_class = 'qrn-status-badge qrn-status-scheduled';
                        } elseif ( $status === 'sent' ) {
                            $status_class = 'qrn-status-badge qrn-status-sent';
                        } elseif ( $status === 'failed' ) {
                            $status_class = 'qrn-status-badge qrn-status-failed';
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html( get_the_title() ); ?></td>
                            <td><?php echo esc_html( $business ); ?></td>
                            <td><?php echo esc_html( $send_type ? ucfirst( $send_type ) : '-' ); ?></td>
                            <td><?php echo esc_html( $when_str ); ?></td>
                            <td><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
                            <td><?php echo esc_html( $created ); ?></td>
                            <td>
                                <?php
                                $delete_link = wp_nonce_url(
                                admin_url( 'admin-post.php?action=qrn_sms_delete_campaign&campaign_id=' . $id ),
                                'qrn_sms_delete_campaign_' . $id
                                );
                                ?>
                                <a href="<?php echo esc_url( $delete_link ); ?>"
                                class="qrn-btn qrn-btn-sm qrn-btn-secondary"
                                onclick="return confirm('Delete this campaign? This cannot be undone.');">
                                 Delete
                               </a>
                            </td>
                        </tr>
                        <?php
                    endwhile;
                    wp_reset_postdata();
                else :
                    ?>
                    <tr><td colspan="6">No campaigns found.</td></tr>
                    <?php
                endif;
                ?>
                </tbody>
            </table>
        </div>
        <?php
        if ( $query->max_num_pages > 1 ) {
            $base_url = remove_query_arg( [ 'paged', 'qrn_msg', 'qrn_err' ] );
            echo '<div style="margin-top:10px;display:flex;gap:8px;">';
            if ( $paged > 1 ) {
                echo '<a class="qrn-btn qrn-btn-sm qrn-btn-secondary" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ) . '">&larr; Previous</a>';
            }
            if ( $paged < $query->max_num_pages ) {
                echo '<a class="qrn-btn qrn-btn-sm qrn-btn-secondary" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ) . '">Next &rarr;</a>';
            }
            echo '</div>';
        }
    }

    /**
     * Render new campaign form with phone preview.
     */
    private function render_new_campaign_form() {
        $base = admin_url( 'admin-post.php' );
        ?>
        <div class="qrn-two-col">
            <div class="qrn-two-col-main">
                <form method="post" action="<?php echo esc_url( $base ); ?>" class="qrn-form">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                    <input type="hidden" name="action" value="qrn_sms_save_campaign">

                    <div class="row">
                        <div>
                            <label for="qrn_campaign_name">Campaign Name</label>
                            <input type="text" id="qrn_campaign_name" name="campaign_name" required placeholder="e.g. Friday Flash Sale">
                        </div>
                        <div>
                            <label for="qrn_business_name">Business</label>
                            <input type="text" id="qrn_business_name" name="business_name" required placeholder="Business name (matches Leads)">
                            <p class="qrn-meta">For now this should match the Business name stored on leads. Later this will be a Business selector.</p>
                        </div>
                    </div>

                    <div class="row-1">
                        <div class="qrn-message-field">
                            <label for="qrn_message_body">Message</label>
                            <textarea id="qrn_message_body" name="message_body" rows="3" required placeholder="Type the SMS message to send…"></textarea>
                            <p class="qrn-meta" id="qrnMessageCounter">0 characters • 0 segments</p>
                        </div>
                    </div>


                    <div class="row">
                        <div>
                            <label>Send</label>
                            <div class="qrn-toggle-group">
                                <div class="qrn-toggle-option">
                                    <input type="radio" id="qrn_send_now" name="send_type" value="now" checked>
                                    <label class="qrn-toggle-label" for="qrn_send_now">
                                        <span class="qrn-toggle-dot"></span>
                                        <span>Send now</span>
                                    </label>
                                </div>
                                <div class="qrn-toggle-option">
                                    <input type="radio" id="qrn_send_schedule" name="send_type" value="schedule">
                                    <label class="qrn-toggle-label" for="qrn_send_schedule">
                                        <span class="qrn-toggle-dot"></span>
                                        <span>Schedule for later</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label>Schedule Date &amp; Time (if scheduled)</label>
                            <div style="display:flex;gap:8px;">
                                <input type="date" name="schedule_date">
                                <input type="time" name="schedule_time" step="60">
                            </div>
                            <p class="qrn-meta">If left blank, scheduled campaigns will not run.</p>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <button type="submit" class="qrn-btn">Save Campaign</button>
                    </div>
                </form>
            </div>

            <div class="qrn-two-col-side">
                <div class="qrn-phone">
                    <div class="qrn-phone-frame">
                        <div class="qrn-phone-screen">
                            <div class="qrn-phone-notch"></div>
                            <div class="qrn-phone-header">Preview • SMS</div>
                            <div class="qrn-phone-bubble" id="qrnPreviewBubble">Your SMS preview will appear here…</div>
                            <div class="qrn-phone-footer">
                                Preview only. Actual length and wrapping may vary by device.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render logs table (simple view, will improve later).
     */
    private function render_logs_table() {
        $paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
        $per_page = 50;

        $args = [
            'post_type'      => self::CPT_LOG,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query( $args );
        ?>
        <div class="qrn-table-wrap">
            <table class="qrn-table">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Business</th>
                    <th>Phone</th>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Provider</th>
                </tr>
                </thead>
                <tbody>
                <?php
                if ( $query->have_posts() ) :
                    while ( $query->have_posts() ) :
                        $query->the_post();
                        $id        = get_the_ID();
                        $business  = get_post_meta( $id, 'qrn_business_name', true );
                        $phone     = get_post_meta( $id, 'qrn_phone', true );
                        $campaign  = get_post_meta( $id, 'qrn_campaign_id', true );
                        $status    = get_post_meta( $id, 'qrn_status', true );
                        $provider  = get_post_meta( $id, 'qrn_provider', true );
                        $created   = get_the_date( 'Y-m-d g:i A', $id );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $created ); ?></td>
                            <td><?php echo esc_html( $business ); ?></td>
                            <td><?php echo esc_html( $phone ); ?></td>
                            <td><?php echo esc_html( $campaign ); ?></td>
                            <td><?php echo esc_html( $status ? $status : '-' ); ?></td>
                            <td><?php echo esc_html( $provider ? $provider : '-' ); ?></td>
                        </tr>
                        <?php
                    endwhile;
                    wp_reset_postdata();
                else :
                    ?>
                    <tr><td colspan="6">No logs yet.</td></tr>
                    <?php
                endif;
                ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Handle new campaign save.
     * For "send now" we immediately trigger sending (logging only for now).
     */
    public function handle_save_campaign() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }

        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
            wp_die( 'Bad nonce.' );
        }

        $campaign_name = isset( $_POST['campaign_name'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_name'] ) ) : '';
        $business_name = isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : '';
        $message_body  = isset( $_POST['message_body'] ) ? wp_kses_post( wp_unslash( $_POST['message_body'] ) ) : '';
        $send_type     = isset( $_POST['send_type'] ) ? sanitize_text_field( wp_unslash( $_POST['send_type'] ) ) : 'now';

        if ( ! $campaign_name || ! $business_name || ! $message_body ) {
            $this->redirect_with_message( 'Missing required fields.', true );
        }

        $schedule_timestamp = 0;
        if ( $send_type === 'schedule' ) {
            $date = isset( $_POST['schedule_date'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_date'] ) ) : '';
            $time = isset( $_POST['schedule_time'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_time'] ) ) : '';
            if ( $date && $time ) {
                $schedule_timestamp = strtotime( $date . ' ' . $time );
            }
        }

        $status = 'draft';
        if ( $send_type === 'now' ) {
            $status = 'sent'; // we will attempt immediate send (log only)
        } elseif ( $send_type === 'schedule' && $schedule_timestamp > time() ) {
            $status = 'scheduled';
        }

        $post_id = wp_insert_post( [
            'post_type'   => self::CPT_CAMPAIGN,
            'post_status' => 'publish',
            'post_title'  => $campaign_name,
            'post_author' => get_current_user_id(),
        ] );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            $this->redirect_with_message( 'Could not save campaign.', true );
        }

        update_post_meta( $post_id, 'qrn_business_name', $business_name );
        update_post_meta( $post_id, 'qrn_message_body', $message_body );
        update_post_meta( $post_id, 'qrn_send_type', $send_type );
        update_post_meta( $post_id, 'qrn_schedule_timestamp', $schedule_timestamp );
        update_post_meta( $post_id, 'qrn_status', $status );

        if ( $send_type === 'now' ) {
            $this->send_campaign_to_leads( $post_id );
            $this->redirect_with_message( 'Campaign saved and sending started (queued in logs).' );
        } elseif ( $send_type === 'schedule' && $schedule_timestamp > time() ) {
            $this->redirect_with_message( 'Campaign scheduled.' );
        } else {
            $this->redirect_with_message( 'Campaign saved as draft.' );
        }
    }
    
    /**
     * Handle deleting a campaign from our custom list table.
     */
    public function handle_delete_campaign() {
        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_die( 'Permission denied.' );
        }

        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        $nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! $campaign_id || ! wp_verify_nonce( $nonce, 'qrn_sms_delete_campaign_' . $campaign_id ) ) {
            $this->redirect_with_message( 'Could not delete campaign.', true );
        }

        wp_trash_post( $campaign_id );

        $this->redirect_with_message( 'Campaign deleted.' );
    }

    /**
     * Process scheduled campaigns (called by cron).
     */
    public function process_scheduled_campaigns() {
        $now = time();

        $args = [
            'post_type'      => self::CPT_CAMPAIGN,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => 'qrn_status',
                    'value' => 'scheduled',
                ],
                [
                    'key'     => 'qrn_schedule_timestamp',
                    'value'   => $now,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];

        $q = new WP_Query( $args );
        if ( ! $q->have_posts() ) {
            return;
        }

        while ( $q->have_posts() ) {
            $q->the_post();
            $campaign_id = get_the_ID();
            $this->send_campaign_to_leads( $campaign_id );
            update_post_meta( $campaign_id, 'qrn_status', 'sent' );
        }
        wp_reset_postdata();
    }

    /**
     * Send campaign to all leads for the given business.
     * NOTE: This Phase 2 skeleton only logs messages; provider integration
     * will be wired through a separate Providers/Credentials plugin.
     */
    private function send_campaign_to_leads( $campaign_id ) {
        $business_name = get_post_meta( $campaign_id, 'qrn_business_name', true );
        $message_body  = get_post_meta( $campaign_id, 'qrn_message_body', true );

        if ( ! $business_name || ! $message_body ) {
            return;
        }

        // Pull leads from QR Neighbor Leads (qr_lead CPT)
        $leads = get_posts( [
            'post_type'      => 'qr_lead',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => 'business_name',
            'meta_value'     => $business_name,
            'fields'         => 'ids',
        ] );

        if ( empty( $leads ) ) {
            return;
        }

        foreach ( $leads as $lead_id ) {
            $phone = get_post_meta( $lead_id, 'customer_phone', true );
            if ( ! $phone ) {
                continue;
            }

            // Here we would call into Providers/Credentials layer to actually send.
            // For Phase 2 skeleton we just create a log entry.
            $log_id = wp_insert_post( [
                'post_type'   => self::CPT_LOG,
                'post_status' => 'publish',
                'post_title'  => 'SMS to ' . $phone,
                'post_author' => get_current_user_id(),
            ] );

            if ( $log_id && ! is_wp_error( $log_id ) ) {
                update_post_meta( $log_id, 'qrn_business_name', $business_name );
                update_post_meta( $log_id, 'qrn_phone', $phone );
                update_post_meta( $log_id, 'qrn_campaign_id', $campaign_id );
                update_post_meta( $log_id, 'qrn_status', 'queued' );
                update_post_meta( $log_id, 'qrn_provider', 'pending' );
            }
        }
    }

    /**
     * Redirect back to main page with a flash message.
     */
    private function redirect_with_message( $msg, $is_error = false ) {
        $url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
        $param = $is_error ? 'qrn_err' : 'qrn_msg';
        $url = add_query_arg( $param, rawurlencode( $msg ), $url );
        wp_safe_redirect( $url );
        exit;
    }

}

endif;

new QRN_SMS_Manager_V2();

register_activation_hook( __FILE__, [ 'QRN_SMS_Manager_V2', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'QRN_SMS_Manager_V2', 'deactivate' ] );

<?php
/*
Plugin Name: QR Neighbor Leads
Description: Contacts / leads manager for the QR Neighbor SMS platform.
Version: 0.1.0
Author: QR Neighbor
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QRN_Leads_Plugin {

    const CPT           = 'qr_lead';
    const NONCE_ACTION  = 'qrn_leads_action';
    const NONCE_NAME    = 'qrn_leads_nonce';
    const MENU_SLUG     = 'qrn_leads';

    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        add_action( 'admin_post_qrn_add_contact', [ $this, 'handle_add_contact' ] );
        add_action( 'admin_post_qrn_import_contacts', [ $this, 'handle_import_contacts' ] );
        add_action( 'admin_post_qrn_export_contacts', [ $this, 'handle_export_contacts' ] );
    }

    /**
     * Register qr_lead post type
     */
    public function register_cpt() {
        $labels = [
            'name'          => 'Leads',
            'singular_name' => 'Lead',
            'add_new_item'  => 'Add New Lead',
            'edit_item'     => 'Edit Lead',
        ];

        $args = [
            'labels'        => $labels,
            'public'        => false,
            'show_ui'       => false, // we use our own UI page
            'show_in_menu'  => false,
            'supports'      => [ 'title', 'author' ],
            'capability_type' => 'post',
            'map_meta_cap'  => true,
        ];

        register_post_type( self::CPT, $args );
    }

    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_menu_page(
            'Contacts',
            'Contacts',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_contacts_page' ],
            'dashicons-id-alt',
            26
        );
    }

    /**
     * Enqueue inline admin UI styles on our page
     */
    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'toplevel_page_' . self::MENU_SLUG ) {
            return;
        }

        $css = <<<'CSS'
:root{
  --qr-blue:#0b74ff;
  --qr-text:#2b3340;
  --qr-muted:#6b7280;
  --qr-card:#fff;
  --qr-ring:rgba(11,116,255,.12);
  --qr-radius:18px;
}
/* Overall admin canvas for this page */
body {
  background:#f3f4f6;
}

/* Main app container */
.qrn-wrap{
  display:flex;
  flex-direction:column;
  gap:20px;
  margin:16px auto 32px;
  max-width:1200px;
}

/* SaaS-style floating card */
.qrn-card{
  background:var(--qr-card);
  border:1px solid #e5e7f0;
  border-radius:24px;
  padding:20px 24px;
  box-shadow:0 24px 60px rgba(15,23,42,.08),0 3px 15px rgba(15,23,42,.05);
  display:grid;
  gap:10px;
}

/* Card header with actions bar */
.qrn-card-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
}

.qrn-title{font-weight:900;font-size:20px;color:var(--qr-text);margin:0 0 2px;}
.qrn-meta{color:var(--qr-muted);font-size:13px;margin:2px 0;}
.qrn-badge{display:inline-block;background:#eef4ff;color:var(--qr-blue);font-weight:800;padding:6px 10px;border-radius:999px;font-size:12px;margin-bottom:6px;letter-spacing:.2px;}
.qrn-btn{background:var(--qr-blue);color:#fff;border:none;padding:10px 16px;border-radius:12px;font-weight:700;cursor:pointer;box-shadow:0 10px 24px rgba(11,116,255,.18);text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.qrn-btn-secondary{background:#fff;color:var(--qr-blue);border:1px solid rgba(11,116,255,.3);box-shadow:none;}
.qrn-btn-sm{padding:6px 12px;font-size:12px;border-radius:999px;}
.qrn-form{background:#fafafa;border:1px solid #eef2f7;border-radius:14px;padding:16px;margin:4px 0 0;}
.qrn-form .row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.qrn-form .row-1{display:grid;grid-template-columns:1fr;gap:12px;}
.qrn-form label{font-size:13px;font-weight:600;color:var(--qr-text);margin-bottom:3px;display:block;}
.qrn-form input,.qrn-form textarea,.qrn-form select{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;font-size:14px;outline:none;box-sizing:border-box;background:#fff;}
.qrn-form textarea{min-height:90px;resize:vertical;}
.qrn-form input:focus,.qrn-form textarea:focus,.qrn-form select:focus{border-color:var(--qr-blue);box-shadow:0 0 0 3px var(--qr-ring);}
.qrn-tabs{border-bottom:1px solid #e5e7eb;display:flex;gap:18px;margin-top:6px;}
.qrn-tab{padding:10px 0;font-size:13px;font-weight:600;color:var(--qr-muted);text-decoration:none;border-bottom:2px solid transparent;display:inline-block;}
.qrn-tab-active{color:var(--qr-blue);border-color:var(--qr-blue);}
.qrn-table-wrap{overflow:auto;}
.qrn-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px;}
.qrn-table th,.qrn-table td{padding:8px 10px;border-bottom:1px solid #eef2f7;text-align:left;white-space:nowrap;}
.qrn-table th{font-weight:600;color:#4b5563;background:#f9fafb;}
.qrn-status-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600;}
.qrn-status-active{background:#ecfdf5;color:#166534;border:1px solid #a7f3d0;}
.qrn-status-unsub{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;}
/* Actions bar on the right of the header */
.qrn-actions{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:nowrap;
}

/* Search + filters pill */
.qrn-search-row{
  margin-top:14px;
  background:#f9fafb;
  border-radius:999px;
  border:1px solid #e5e7eb;
  padding:6px 10px;
}

.qrn-search-form{
  display:flex;
  align-items:center;
  gap:8px;
  width:100%;
}

.qrn-search-row input[type="search"]{
  flex:1;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid transparent;
  background:transparent;
  font-size:13px;
}

.qrn-search-row input[type="search"]:focus{
  outline:none;
  border-color:transparent;
  box-shadow:none;
}

.qrn-search-row select{
  padding:7px 10px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  font-size:13px;
  background:#fff;
}

/* Responsive tweaks */
@media (max-width:900px){
  .qrn-card-header{flex-direction:column;align-items:flex-start;}
  .qrn-actions{width:100%;flex-wrap:wrap;justify-content:flex-start;}
}

.qrn-notice{padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:6px;}
.qrn-notice-ok{background:#ecfdf5;color:#166534;border:1px solid #a7f3d0;}
.qrn-notice-err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;}
@media (max-width:900px){
  .qrn-card-header{flex-direction:column;align-items:flex-start;}
  .qrn-form .row{grid-template-columns:1fr;}
}
CSS;

        wp_register_style( 'qrn-leads-admin', false );
        wp_enqueue_style( 'qrn-leads-admin' );
        wp_add_inline_style( 'qrn-leads-admin', $css );
    }

    /**
     * Render main Contacts page
     */
    public function render_contacts_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'contacts';
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
                         <h1 class="qrn-title">Contacts</h1>
                         <p class="qrn-meta">All SMS subscribers collected from your QR pages and forms.</p>
                    </div>
                         <div class="qrn-actions">
                         <a class="qrn-btn qrn-btn-secondary" href="<?php echo esc_url( $base . '&tab=upload' ); ?>">Import</a>
                         <a class="qrn-btn" href="<?php echo esc_url( $base . '&tab=add' ); ?>">Add Contacts</a>
                         <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                   <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                         <input type="hidden" name="action" value="qrn_export_contacts">
                         <button type="submit" class="qrn-btn qrn-btn-secondary qrn-btn-sm">Export</button>
                   </form>
                </div>
           </div>
   
                 <div class="qrn-tabs">
                        <a class="qrn-tab <?php echo $tab === 'contacts' ? 'qrn-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=contacts' ); ?>">Contacts</a>
                        <a class="qrn-tab <?php echo $tab === 'add' ? 'qrn-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=add' ); ?>">Add Individual</a>
                        <a class="qrn-tab <?php echo $tab === 'upload' ? 'qrn-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=upload' ); ?>">Upload CSV</a>
                    </div>

                    <?php if ( $msg ) : ?>
                        <div class="qrn-notice qrn-notice-ok">
                            <?php echo esc_html( $msg ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $err ) : ?>
                        <div class="qrn-notice qrn-notice-err">
                            <?php echo esc_html( $err ); ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    if ( $tab === 'add' ) {
                        $this->render_add_form();
                    } elseif ( $tab === 'upload' ) {
                        $this->render_upload_form();
                    } else {
                        $this->render_contacts_table();
                    }
                    ?>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Contacts table
     */
    private function render_contacts_table() {
        $paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
        $per_page = 50;

        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        $meta_query = [];
        if ( $status ) {
            $meta_query[] = [
                'key'   => 'status',
                'value' => $status,
            ];
        }

        $args = [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $meta_query,
        ];

        if ( $search ) {
            // Simple search in title + phone meta
            $args['s'] = $search;
        }

        $query = new WP_Query( $args );
        ?>
        <div class="qrn-search-row">
    <form method="get" class="qrn-search-form">
        <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
        <input type="hidden" name="tab" value="contacts">
        <input type="search" name="s" placeholder="Search contacts…" value="<?php echo esc_attr( $search ); ?>">
        <select name="status">
            <option value="">All statuses</option>
            <option value="active" <?php selected( $status, 'active' ); ?>>Active</option>
            <option value="unsubscribed" <?php selected( $status, 'unsubscribed' ); ?>>Unsubscribed</option>
        </select>
        <button class="qrn-btn qrn-btn-sm" type="submit">Filter</button>
    </form>
</div>


        <div class="qrn-table-wrap">
            <table class="qrn-table">
                <thead>
                    <tr>
                        <th>Phone</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Email</th>
                        <th>Business</th>
                        <th>Lists</th>
                        <th>Status</th>
                        <th>Date Added</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ( $query->have_posts() ) :
                    while ( $query->have_posts() ) :
                        $query->the_post();
                        $id      = get_the_ID();
                        $phone   = get_post_meta( $id, 'customer_phone', true );
                        $biz     = get_post_meta( $id, 'business_name', true );
                        $first   = get_post_meta( $id, 'first_name', true );
                        $last    = get_post_meta( $id, 'last_name', true );
                        $email   = get_post_meta( $id, 'email', true );
                        $lists   = get_post_meta( $id, 'lists', true );
                        $status  = get_post_meta( $id, 'status', true );
                        $status  = $status ? $status : 'active';
                        $date    = get_the_date( 'Y-m-d', $id );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $phone ); ?></td>
                            <td><?php echo esc_html( $first ); ?></td>
                            <td><?php echo esc_html( $last ); ?></td>
                            <td><?php echo esc_html( $email ); ?></td>
                            <td><?php echo esc_html( $biz ); ?></td>
                            <td><?php echo esc_html( $lists ); ?></td>
                            <td>
                                <?php
                                $cls = $status === 'unsubscribed' ? 'qrn-status-badge qrn-status-unsub' : 'qrn-status-badge qrn-status-active';
                                echo '<span class="' . esc_attr( $cls ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
                                ?>
                            </td>
                            <td><?php echo esc_html( $date ); ?></td>
                        </tr>
                        <?php
                    endwhile;
                    wp_reset_postdata();
                else :
                    ?>
                    <tr><td colspan="8">No contacts found.</td></tr>
                    <?php
                endif;
                ?>
                </tbody>
            </table>
        </div>
        <?php
        // Simple prev/next pagination
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
     * Add Individual form
     */
    private function render_add_form() {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="qrn-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
            <input type="hidden" name="action" value="qrn_add_contact">

            <div class="row">
                <div>
                    <label for="qrn_business_name">Business</label>
                    <input type="text" id="qrn_business_name" name="business_name" placeholder="Business name">
                </div>
                <div>
                    <label for="qrn_status">Status</label>
                    <select id="qrn_status" name="status">
                        <option value="active">Active</option>
                        <option value="unsubscribed">Unsubscribed</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="qrn_first_name">First Name</label>
                    <input type="text" id="qrn_first_name" name="first_name" placeholder="First name">
                </div>
                <div>
                    <label for="qrn_last_name">Last Name</label>
                    <input type="text" id="qrn_last_name" name="last_name" placeholder="Last name">
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="qrn_phone">Phone Number *</label>
                    <input type="text" id="qrn_phone" name="customer_phone" required placeholder="(555) 555-5555">
                </div>
                <div>
                    <label for="qrn_email">Email</label>
                    <input type="email" id="qrn_email" name="email" placeholder="name@example.com">
                </div>
            </div>

            <div class="row-1">
                <div>
                    <label for="qrn_lists">Lists / Groups</label>
                    <input type="text" id="qrn_lists" name="lists" placeholder="e.g. VIP, FirstOrder">
                    <p class="qrn-meta">Comma-separated list names (for now stored as plain text).</p>
                </div>
            </div>

            <div style="margin-top:12px;">
                <button type="submit" class="qrn-btn">Save Contact</button>
            </div>
        </form>
        <?php
    }

    /**
     * Upload CSV form
     */
    private function render_upload_form() {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="qrn-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
            <input type="hidden" name="action" value="qrn_import_contacts">

            <div class="row-1">
                <div>
                    <label>Upload File</label>
                    <input type="file" name="qrn_import_file" accept=".csv,.txt" required>
                    <p class="qrn-meta">Upload a CSV file with at least a <strong>phone</strong> column. Optional columns: first_name, last_name, email, business_name, lists, status.</p>
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="qrn_default_business">Default Business (optional)</label>
                    <input type="text" id="qrn_default_business" name="default_business" placeholder="Use this if your file has no business column">
                </div>
                <div>
                    <label for="qrn_skip_dupes">
                        <input type="checkbox" id="qrn_skip_dupes" name="skip_duplicates" value="1" checked>
                        Skip phone numbers that already exist
                    </label>
                </div>
            </div>

            <div style="margin-top:12px;">
                <button type="submit" class="qrn-btn">Import Contacts</button>
            </div>
        </form>
        <?php
    }

    /**
     * Handle Add Individual
     */
    public function handle_add_contact() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }

        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
            wp_die( 'Bad nonce' );
        }

        $phone = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';
        if ( ! $phone ) {
            $this->redirect_with_message( 'Phone number is required.', true );
        }

        $biz   = isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : '';
        $first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $lists = isset( $_POST['lists'] ) ? sanitize_text_field( wp_unslash( $_POST['lists'] ) ) : '';
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'active';

        $title = trim( $first . ' ' . $last );
        if ( ! $title ) {
            $title = $phone;
        }

        $post_id = wp_insert_post( [
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => get_current_user_id(),
        ] );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            $this->redirect_with_message( 'Could not save contact.', true );
        }

        update_post_meta( $post_id, 'customer_phone', $phone );
        update_post_meta( $post_id, 'business_name', $biz );
        update_post_meta( $post_id, 'first_name', $first );
        update_post_meta( $post_id, 'last_name', $last );
        update_post_meta( $post_id, 'email', $email );
        update_post_meta( $post_id, 'lists', $lists );
        update_post_meta( $post_id, 'status', $status );

        $this->redirect_with_message( 'Contact added.' );
    }

    /**
     * Handle CSV Import
     */
    public function handle_import_contacts() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }

        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
            wp_die( 'Bad nonce' );
        }

        if ( empty( $_FILES['qrn_import_file']['tmp_name'] ) ) {
            $this->redirect_with_message( 'No file uploaded.', true );
        }

        $default_business = isset( $_POST['default_business'] ) ? sanitize_text_field( wp_unslash( $_POST['default_business'] ) ) : '';
        $skip_dupes       = ! empty( $_POST['skip_duplicates'] );

        $file = fopen( $_FILES['qrn_import_file']['tmp_name'], 'r' );
        if ( ! $file ) {
            $this->redirect_with_message( 'Could not open file.', true );
        }

        $header = fgetcsv( $file );
        if ( ! $header ) {
            fclose( $file );
            $this->redirect_with_message( 'File appears to be empty.', true );
        }

        $map = [];
        foreach ( $header as $i => $col ) {
            $key = strtolower( trim( $col ) );
            if ( in_array( $key, [ 'phone', 'phone_number', 'customer_phone' ], true ) ) {
                $map['phone'] = $i;
            } elseif ( in_array( $key, [ 'first_name', 'firstname' ], true ) ) {
                $map['first_name'] = $i;
            } elseif ( in_array( $key, [ 'last_name', 'lastname' ], true ) ) {
                $map['last_name'] = $i;
            } elseif ( in_array( $key, [ 'email', 'email_address' ], true ) ) {
                $map['email'] = $i;
            } elseif ( in_array( $key, [ 'business', 'business_name' ], true ) ) {
                $map['business_name'] = $i;
            } elseif ( in_array( $key, [ 'lists', 'groups', 'tags' ], true ) ) {
                $map['lists'] = $i;
            } elseif ( in_array( $key, [ 'status' ], true ) ) {
                $map['status'] = $i;
            }
        }

        if ( ! isset( $map['phone'] ) ) {
            fclose( $file );
            $this->redirect_with_message( 'No phone column found. Expected header like "phone" or "customer_phone".', true );
        }

        $added  = 0;
        $skipped = 0;

        while ( ( $row = fgetcsv( $file ) ) !== false ) {
            $phone = isset( $row[ $map['phone'] ] ) ? trim( $row[ $map['phone'] ] ) : '';
            if ( ! $phone ) {
                $skipped++;
                continue;
            }

            // Basic normalisation: strip spaces
            $phone_norm = preg_replace( '/\s+/', '', $phone );

            if ( $skip_dupes ) {
                $existing = get_posts( [
                    'post_type'      => self::CPT,
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'meta_key'       => 'customer_phone',
                    'meta_value'     => $phone_norm,
                    'fields'         => 'ids',
                ] );
                if ( ! empty( $existing ) ) {
                    $skipped++;
                    continue;
                }
            }

            $first = isset( $map['first_name'], $row[ $map['first_name'] ] ) ? trim( $row[ $map['first_name'] ] ) : '';
            $last  = isset( $map['last_name'], $row[ $map['last_name'] ] ) ? trim( $row[ $map['last_name'] ] ) : '';
            $email = isset( $map['email'], $row[ $map['email'] ] ) ? trim( $row[ $map['email'] ] ) : '';
            $biz   = isset( $map['business_name'], $row[ $map['business_name'] ] ) ? trim( $row[ $map['business_name'] ] ) : $default_business;
            $lists = isset( $map['lists'], $row[ $map['lists'] ] ) ? trim( $row[ $map['lists'] ] ) : '';
            $status = isset( $map['status'], $row[ $map['status'] ] ) ? strtolower( trim( $row[ $map['status'] ] ) ) : 'active';
            if ( ! in_array( $status, [ 'active', 'unsubscribed' ], true ) ) {
                $status = 'active';
            }

            $title = trim( $first . ' ' . $last );
            if ( ! $title ) {
                $title = $phone_norm;
            }

            $post_id = wp_insert_post( [
                'post_type'   => self::CPT,
                'post_status' => 'publish',
                'post_title'  => $title,
                'post_author' => get_current_user_id(),
            ] );

            if ( is_wp_error( $post_id ) || ! $post_id ) {
                $skipped++;
                continue;
            }

            update_post_meta( $post_id, 'customer_phone', $phone_norm );
            update_post_meta( $post_id, 'business_name', $biz );
            update_post_meta( $post_id, 'first_name', $first );
            update_post_meta( $post_id, 'last_name', $last );
            update_post_meta( $post_id, 'email', $email );
            update_post_meta( $post_id, 'lists', $lists );
            update_post_meta( $post_id, 'status', $status );

            $added++;
        }

        fclose( $file );

        $msg = sprintf( 'Imported %d contact(s). Skipped %d.', $added, $skipped );
        $this->redirect_with_message( $msg );
    }

    /**
     * Handle Export CSV
     */
    public function handle_export_contacts() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }

        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
            wp_die( 'Bad nonce' );
        }

        $args = [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $posts = get_posts( $args );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=qrneighbor-contacts-' . date( 'Ymd-His' ) . '.csv' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, [ 'id', 'phone', 'first_name', 'last_name', 'email', 'business_name', 'lists', 'status', 'date_added' ] );

        foreach ( $posts as $p ) {
            $id      = $p->ID;
            $phone   = get_post_meta( $id, 'customer_phone', true );
            $biz     = get_post_meta( $id, 'business_name', true );
            $first   = get_post_meta( $id, 'first_name', true );
            $last    = get_post_meta( $id, 'last_name', true );
            $email   = get_post_meta( $id, 'email', true );
            $lists   = get_post_meta( $id, 'lists', true );
            $status  = get_post_meta( $id, 'status', true );
            $status  = $status ? $status : 'active';
            $date    = get_the_date( 'Y-m-d H:i:s', $id );

            fputcsv( $output, [ $id, $phone, $first, $last, $email, $biz, $lists, $status, $date ] );
        }

        fclose( $output );
        exit;
    }

    /**
     * Helper: redirect back to Contacts with message
     */
    private function redirect_with_message( $msg, $is_error = false ) {
        $url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
        $url = add_query_arg(
            $is_error ? 'qrn_err' : 'qrn_msg',
            rawurlencode( $msg ),
            $url
        );
        wp_safe_redirect( $url );
        exit;
    }

}

new QRN_Leads_Plugin();

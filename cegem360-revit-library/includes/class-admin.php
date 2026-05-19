<?php
defined( 'ABSPATH' ) || exit;

class CRL_Admin {

    private $plugin;

    public function __construct( CRL_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_crl_upload_file',      array( $this, 'ajax_upload_file' ) );
        add_action( 'wp_ajax_crl_delete_file',      array( $this, 'ajax_delete_file' ) );
        add_action( 'wp_ajax_crl_regenerate_zip',   array( $this, 'ajax_regenerate_zip' ) );
        add_action( 'wp_ajax_crl_test_email',       array( $this, 'ajax_test_email' ) );
        add_action( 'wp_ajax_crl_resend_email',     array( $this, 'ajax_resend_email' ) );
        add_action( 'wp_ajax_crl_renew_token',      array( $this, 'ajax_renew_token' ) );
        add_action( 'admin_post_crl_export_csv',    array( $this, 'export_csv' ) );
        add_action( 'admin_post_crl_delete_submission', array( $this, 'delete_submission' ) );
    }

    public function register_menu() {
        $cap = 'manage_options';
        add_menu_page(
            __( 'Revit elemtár', 'cegem360-revit-library' ),
            __( 'Revit elemtár', 'cegem360-revit-library' ),
            $cap,
            'crl-submissions',
            array( $this, 'render_submissions_page' ),
            'dashicons-download',
            30
        );
        add_submenu_page( 'crl-submissions', __( 'Beküldések', 'cegem360-revit-library' ), __( 'Beküldések', 'cegem360-revit-library' ), $cap, 'crl-submissions', array( $this, 'render_submissions_page' ) );
        add_submenu_page( 'crl-submissions', __( 'Fájlkezelő', 'cegem360-revit-library' ), __( 'Fájlkezelő', 'cegem360-revit-library' ), $cap, 'crl-files', array( $this, 'render_files_page' ) );
        add_submenu_page( 'crl-submissions', __( 'Beállítások', 'cegem360-revit-library' ), __( 'Beállítások', 'cegem360-revit-library' ), $cap, 'crl-settings', array( $this->plugin->settings, 'render_page' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'crl-' ) === false ) return;
        wp_enqueue_style( 'crl-admin', CRL_PLUGIN_URL . 'assets/css/admin.css', array(), CRL_VERSION );
        wp_enqueue_script( 'crl-admin', CRL_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), CRL_VERSION, true );
        wp_localize_script( 'crl-admin', 'CRL_ADMIN', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'crl_admin_action' ),
            'i18n'    => array(
                'confirm_delete'    => __( 'Biztosan törli ezt az elemet?', 'cegem360-revit-library' ),
                'uploading'         => __( 'Feltöltés…', 'cegem360-revit-library' ),
                'regenerating'      => __( 'ZIP regenerálása…', 'cegem360-revit-library' ),
                'done'              => __( 'Kész.', 'cegem360-revit-library' ),
                'error'             => __( 'Hiba történt.', 'cegem360-revit-library' ),
            ),
        ) );
    }

    public function render_submissions_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $table = new CRL_Submissions_List_Table( $this->plugin->submissions );
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Beküldések', 'cegem360-revit-library' ); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="crl-submissions">
                <?php
                $table->search_box( __( 'Keresés', 'cegem360-revit-library' ), 'crl-search' );
                $table->extra_filters();
                $table->display();
                ?>
            </form>
            <p>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=crl_export_csv' ), 'crl_export_csv' ) ); ?>">
                    <?php esc_html_e( 'CSV export', 'cegem360-revit-library' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function render_files_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $info  = $this->plugin->zip_manager->get_info();
        $files = $this->plugin->zip_manager->list_source_files();
        $allowed = implode( ', ', $this->plugin->file_manager->allowed_extensions() );
        $max_upload = size_format( wp_max_upload_size() );
        include CRL_PLUGIN_DIR . 'includes/views/files-page.php';
    }

    /* ----- AJAX handlers ----- */

    private function check_admin_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
        check_ajax_referer( 'crl_admin_action', 'nonce' );
    }

    public function ajax_upload_file() {
        $this->check_admin_ajax();
        $file = isset( $_FILES['file'] ) ? $_FILES['file'] : array();
        $result = $this->plugin->file_manager->handle_upload( $file );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( array( 'filename' => $result, 'info' => $this->plugin->zip_manager->get_info() ) );
    }

    public function ajax_delete_file() {
        $this->check_admin_ajax();
        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
        $result = $this->plugin->file_manager->delete( $filename );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( array( 'info' => $this->plugin->zip_manager->get_info() ) );
    }

    public function ajax_regenerate_zip() {
        $this->check_admin_ajax();
        $ok = $this->plugin->zip_manager->regenerate();
        if ( ! $ok ) wp_send_json_error( array( 'message' => __( 'A ZIP regenerálása sikertelen.', 'cegem360-revit-library' ) ), 500 );
        wp_send_json_success( array( 'info' => $this->plugin->zip_manager->get_info() ) );
    }

    public function ajax_test_email() {
        $this->check_admin_ajax();
        $to = crl_option( 'notification_email', get_option( 'admin_email' ) );
        $ok = $this->plugin->mailer->send_test( $to );
        if ( ! $ok ) wp_send_json_error( array( 'message' => __( 'Teszt email küldése sikertelen.', 'cegem360-revit-library' ) ), 500 );
        wp_send_json_success( array( 'message' => sprintf( __( 'Teszt email elküldve: %s', 'cegem360-revit-library' ), $to ) ) );
    }

    public function ajax_resend_email() {
        $this->check_admin_ajax();
        $sid = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
        $submission = $this->plugin->submissions->get( $sid );
        if ( ! $submission ) wp_send_json_error( array( 'message' => 'not found' ), 404 );

        $tokens = $this->plugin->tokens->find_by_submission( $sid );
        $token  = $tokens ? $tokens[0] : null;
        if ( ! $token || CRL_Tokens::is_expired( $token->expires_at ) ) {
            $new_token = $this->plugin->tokens->create( $sid, (int) crl_option( 'link_validity_days', 7 ) );
            $token = $this->plugin->tokens->find_by_token( $new_token );
        }

        $link = add_query_arg( 'crl_download', $token->token, home_url( '/' ) );
        $ok   = $this->plugin->mailer->send_visitor_email( $submission, $token, $link );
        $this->plugin->submissions->update_email_status( $sid, $ok ? 'sent' : 'failed' );
        wp_send_json_success( array( 'status' => $ok ? 'sent' : 'failed' ) );
    }

    public function ajax_renew_token() {
        $this->check_admin_ajax();
        $sid = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
        $submission = $this->plugin->submissions->get( $sid );
        if ( ! $submission ) wp_send_json_error( array( 'message' => 'not found' ), 404 );

        $new_token = $this->plugin->tokens->create( $sid, (int) crl_option( 'link_validity_days', 7 ) );
        $token     = $this->plugin->tokens->find_by_token( $new_token );
        $link      = add_query_arg( 'crl_download', $new_token, home_url( '/' ) );
        $ok        = $this->plugin->mailer->send_visitor_email( $submission, $token, $link );
        $this->plugin->submissions->update_email_status( $sid, $ok ? 'sent' : 'failed' );
        wp_send_json_success();
    }

    public function delete_submission() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'crl_delete_submission' );
        $sid = isset( $_GET['submission_id'] ) ? (int) $_GET['submission_id'] : 0;
        if ( $sid ) $this->plugin->submissions->delete( $sid );
        wp_safe_redirect( admin_url( 'admin.php?page=crl-submissions&deleted=1' ) );
        exit;
    }

    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'crl_export_csv' );
        $rows = $this->plugin->submissions->query( array( 'per_page' => 99999, 'page' => 1 ) );
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="crl-submissions-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputs( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( 'ID', 'Cégnév', 'Email', 'Telefon', 'Beküldve (UTC)', 'Email státusz' ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array( $r->id, $r->company_name, $r->email, $r->phone, $r->created_at, $r->email_status ) );
        }
        fclose( $out );
        exit;
    }
}

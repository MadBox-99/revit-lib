<?php
defined( 'ABSPATH' ) || exit;

class CRL_Form {

    private $submissions;
    private $tokens;
    private $mailer;
    private $rate_limiter;

    public function __construct( CRL_Submissions $submissions, CRL_Tokens $tokens, CRL_Mailer $mailer, CRL_Rate_Limiter $rate_limiter ) {
        $this->submissions  = $submissions;
        $this->tokens       = $tokens;
        $this->mailer       = $mailer;
        $this->rate_limiter = $rate_limiter;
    }

    public function register_hooks() {
        add_shortcode( 'revit_library_form', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_crl_submit_form', array( $this, 'handle_submit' ) );
        add_action( 'wp_ajax_nopriv_crl_submit_form', array( $this, 'handle_submit' ) );
    }

    public function enqueue_assets() {
        wp_register_style( 'crl-form', CRL_PLUGIN_URL . 'assets/css/form.css', array(), CRL_VERSION );
        wp_register_script( 'crl-form', CRL_PLUGIN_URL . 'assets/js/form.js', array(), CRL_VERSION, true );
        wp_localize_script( 'crl-form', 'CRL_FORM', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'messages' => array(
                'generic_error' => __( 'Hiba történt, kérjük próbálja újra.', 'cegem360-revit-library' ),
                'sending'       => __( 'Küldés…', 'cegem360-revit-library' ),
            ),
        ) );
    }

    public function render_shortcode( $atts ) {
        wp_enqueue_style( 'crl-form' );
        wp_enqueue_script( 'crl-form' );

        $atts = shortcode_atts( array(
            'title'       => crl_option( 'form_title' ),
            'button_text' => __( 'Letöltés kérése', 'cegem360-revit-library' ),
        ), $atts, 'revit_library_form' );

        $title       = $atts['title'];
        $button_text = $atts['button_text'];
        $intro       = crl_option( 'form_intro' );

        $privacy_page_id = (int) crl_option( 'privacy_page_id', 0 );
        $privacy_url     = $privacy_page_id ? get_permalink( $privacy_page_id ) : '#';
        $gdpr_html       = sprintf( crl_option( 'gdpr_text' ), esc_url( $privacy_url ) );

        ob_start();
        include CRL_PLUGIN_DIR . 'templates/form.php';
        return ob_get_clean();
    }

    public function handle_submit() {
        if ( ! check_ajax_referer( 'crl_submit_form', 'crl_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Biztonsági ellenőrzés sikertelen.', 'cegem360-revit-library' ) ), 400 );
        }

        if ( ! empty( $_POST['crl_website'] ) ) {
            wp_send_json_success( array( 'message' => crl_option( 'form_success_message' ) ) );
        }

        $company = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
        $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $gdpr    = ! empty( $_POST['gdpr'] );

        $errors = array();
        if ( mb_strlen( $company ) < 2 )    $errors['company_name'] = __( 'Adja meg a cégnevet.', 'cegem360-revit-library' );
        if ( ! is_email( $email ) )         $errors['email']        = __( 'Érvénytelen email cím.', 'cegem360-revit-library' );
        if ( ! crl_is_valid_phone( $phone )) $errors['phone']       = __( 'Érvénytelen telefonszám.', 'cegem360-revit-library' );
        if ( ! $gdpr )                      $errors['gdpr']         = __( 'El kell fogadnia az adatvédelmi tájékoztatót.', 'cegem360-revit-library' );

        if ( $errors ) {
            wp_send_json_error( array( 'errors' => $errors ), 422 );
        }

        $ip = crl_get_client_ip();
        if ( ! $this->rate_limiter->allow( $ip, (int) crl_option( 'rate_limit_per_hour', 3 ) ) ) {
            wp_send_json_error( array(
                'message' => __( 'Túl sok kérés érkezett erről a címről. Próbálja újra később.', 'cegem360-revit-library' ),
            ), 429 );
        }

        $submission_id = $this->submissions->create( array(
            'company_name'  => $company,
            'email'         => $email,
            'phone'         => $phone,
            'ip_address'    => $ip,
            'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 1000 ) : '',
            'gdpr_accepted' => true,
        ) );

        if ( ! $submission_id ) {
            wp_send_json_error( array( 'message' => __( 'Adatbázis hiba.', 'cegem360-revit-library' ) ), 500 );
        }

        $days  = (int) crl_option( 'link_validity_days', 7 );
        $token = $this->tokens->create( $submission_id, $days );

        $submission = $this->submissions->get( $submission_id );
        $token_row  = $this->tokens->find_by_token( $token );

        $download_link = add_query_arg( 'crl_download', $token, home_url( '/' ) );

        $sent = $this->mailer->send_visitor_email( $submission, $token_row, $download_link );
        $this->submissions->update_email_status( $submission_id, $sent ? 'sent' : 'failed' );

        $this->mailer->send_admin_notification( $submission );

        wp_send_json_success( array( 'message' => crl_option( 'form_success_message' ) ) );
    }
}

<?php
defined( 'ABSPATH' ) || exit;

class CRL_Mailer {

    public function send_visitor_email( $submission, $token, $download_link ) {
        $expires_days = (int) crl_option( 'link_validity_days', 7 );
        $expires_date = wp_date(
            get_option( 'date_format' ),
            strtotime( $token->expires_at . ' UTC' )
        );

        $subject_tpl = crl_option( 'email_visitor_subject' );
        $body_tpl    = crl_option( 'email_visitor_body' );

        $replace = array(
            '{cegnev}'        => $submission->company_name,
            '{download_link}' => esc_url( $download_link ),
            '{expires_days}'  => (string) $expires_days,
            '{expires_date}'  => $expires_date,
        );

        $subject = strtr( $subject_tpl, $replace );
        $body    = strtr( $body_tpl, $replace );

        return $this->send( $submission->email, $subject, $body );
    }

    public function send_admin_notification( $submission ) {
        $to          = crl_option( 'notification_email', get_option( 'admin_email' ) );
        $subject_tpl = crl_option( 'email_admin_subject' );
        $body_tpl    = crl_option( 'email_admin_body' );

        $replace = array(
            '{cegnev}'   => $submission->company_name,
            '{email}'    => $submission->email,
            '{telefon}'  => $submission->phone,
            '{idopont}'  => wp_date( 'Y-m-d H:i', strtotime( $submission->created_at . ' UTC' ) ),
            '{ip}'       => $submission->ip_address,
            '{admin_url}'=> admin_url( 'admin.php?page=crl-submissions' ),
        );

        $subject = strtr( $subject_tpl, $replace );
        $body    = strtr( $body_tpl, $replace );

        return $this->send( $to, $subject, $body );
    }

    public function send_test( $to ) {
        return $this->send( $to, __( 'Cegem360 Revit Library — teszt email', 'cegem360-revit-library' ), '<p>Ez egy teszt email a Revit elemtár pluginból.</p>' );
    }

    private function send( $to, $subject, $body ) {
        $from_email = crl_option( 'sender_email', get_option( 'admin_email' ) );
        $from_name  = crl_option( 'sender_name', get_bloginfo( 'name' ) );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
        );

        ob_start();
        include CRL_PLUGIN_DIR . 'templates/email-download-link.php';
        $html = ob_get_clean();

        return wp_mail( $to, $subject, $html, $headers );
    }
}

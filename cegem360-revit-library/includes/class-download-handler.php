<?php
defined( 'ABSPATH' ) || exit;

class CRL_Download_Handler {

    private $tokens;
    private $zip_manager;

    public function __construct( CRL_Tokens $tokens, CRL_Zip_Manager $zip_manager ) {
        $this->tokens      = $tokens;
        $this->zip_manager = $zip_manager;
    }

    public function register_hooks() {
        add_action( 'init', array( $this, 'maybe_handle_download' ) );
    }

    public function maybe_handle_download() {
        if ( empty( $_GET['crl_download'] ) ) {
            return;
        }
        $token_param = sanitize_text_field( wp_unslash( $_GET['crl_download'] ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token_param ) ) {
            $this->die_with( 404, __( 'A link érvénytelen.', 'cegem360-revit-library' ) );
        }

        $token = $this->tokens->find_by_token( $token_param );
        if ( ! $token ) {
            $this->die_with( 404, __( 'A link érvénytelen vagy lejárt.', 'cegem360-revit-library' ) );
        }
        if ( CRL_Tokens::is_expired( $token->expires_at ) ) {
            $this->die_with( 410, __( 'A link lejárt. Kérjük, igényeljen újat.', 'cegem360-revit-library' ) );
        }

        $zip = crl_zip_path();
        if ( ! file_exists( $zip ) || filesize( $zip ) === 0 ) {
            $this->die_with( 500, __( 'A fájl jelenleg nem érhető el. Kérjük, vegye fel a kapcsolatot velünk.', 'cegem360-revit-library' ) );
        }

        $this->tokens->record_download( $token->id );
        $this->stream_zip( $zip );
    }

    private function stream_zip( $path ) {
        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="revit-elemtar.zip"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );

        $fp = fopen( $path, 'rb' );
        if ( $fp === false ) {
            $this->die_with( 500, __( 'A fájl olvasása sikertelen.', 'cegem360-revit-library' ) );
        }
        while ( ! feof( $fp ) ) {
            echo fread( $fp, 1024 * 1024 );
            flush();
        }
        fclose( $fp );
        exit;
    }

    private function die_with( $status, $message ) {
        status_header( $status );
        wp_die( esc_html( $message ), esc_html__( 'Letöltés', 'cegem360-revit-library' ), array( 'response' => $status ) );
    }
}

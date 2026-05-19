<?php
defined( 'ABSPATH' ) || exit;

class CRL_File_Manager {

    private $zip_manager;

    public function __construct( CRL_Zip_Manager $zip_manager ) {
        $this->zip_manager = $zip_manager;
    }

    public function allowed_extensions() {
        $raw = crl_option( 'allowed_file_types', 'rfa,rvt,rte,rft,zip,pdf,jpg,png' );
        return array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );
    }

    public function handle_upload( $file ) {
        if ( empty( $file ) || ! isset( $file['error'] ) ) {
            return new WP_Error( 'crl_no_file', __( 'Nincs feltöltött fájl.', 'cegem360-revit-library' ) );
        }
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'crl_upload_error', __( 'Feltöltési hiba.', 'cegem360-revit-library' ) );
        }

        $name = sanitize_file_name( $file['name'] );
        $ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $this->allowed_extensions(), true ) ) {
            return new WP_Error( 'crl_bad_ext', __( 'Nem engedélyezett fájltípus.', 'cegem360-revit-library' ) );
        }

        $target = crl_source_dir() . '/' . $name;
        if ( file_exists( $target ) ) {
            $target = crl_source_dir() . '/' . wp_unique_filename( crl_source_dir(), $name );
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error( 'crl_move_failed', __( 'A fájl mentése sikertelen.', 'cegem360-revit-library' ) );
        }

        $this->zip_manager->regenerate();
        return basename( $target );
    }

    public function delete( $filename ) {
        $path = $this->zip_manager->resolve_safe_source_path( $filename );
        if ( ! $path || ! is_file( $path ) ) {
            return new WP_Error( 'crl_not_found', __( 'A fájl nem található.', 'cegem360-revit-library' ) );
        }
        if ( ! unlink( $path ) ) {
            return new WP_Error( 'crl_delete_failed', __( 'Törlés sikertelen.', 'cegem360-revit-library' ) );
        }
        $this->zip_manager->regenerate();
        return true;
    }
}

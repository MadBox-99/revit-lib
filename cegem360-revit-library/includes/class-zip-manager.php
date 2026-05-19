<?php
defined( 'ABSPATH' ) || exit;

class CRL_Zip_Manager {

    public function list_source_files() {
        $dir = crl_source_dir();
        if ( ! is_dir( $dir ) ) {
            return array();
        }
        $files = array();
        foreach ( scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' || strpos( $item, '.' ) === 0 ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( ! is_file( $path ) ) {
                continue;
            }
            $files[] = array(
                'name'     => $item,
                'size'     => filesize( $path ),
                'modified' => filemtime( $path ),
            );
        }
        return $files;
    }

    public function regenerate() {
        $source = crl_source_dir();
        if ( ! is_dir( $source ) ) {
            return false;
        }

        $tmp   = crl_zip_path() . '.tmp';
        $final = crl_zip_path();

        if ( file_exists( $tmp ) ) {
            unlink( $tmp );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            return false;
        }

        $count = 0;
        foreach ( $this->list_source_files() as $file ) {
            $path = $source . '/' . $file['name'];
            $zip->addFile( $path, $file['name'] );
            $count++;
        }
        $zip->close();

        if ( $count === 0 ) {
            if ( file_exists( $final ) ) unlink( $final );
            if ( file_exists( $tmp ) )   unlink( $tmp );
            update_option( 'crl_zip_generated_at', current_time( 'mysql', true ) );
            update_option( 'crl_zip_size', 0 );
            update_option( 'crl_zip_file_count', 0 );
            return true;
        }

        rename( $tmp, $final );
        update_option( 'crl_zip_generated_at', current_time( 'mysql', true ) );
        update_option( 'crl_zip_size', filesize( $final ) );
        update_option( 'crl_zip_file_count', $count );
        return true;
    }

    public function get_info() {
        return array(
            'generated_at' => get_option( 'crl_zip_generated_at', '' ),
            'size'         => (int) get_option( 'crl_zip_size', 0 ),
            'file_count'   => (int) get_option( 'crl_zip_file_count', 0 ),
            'exists'       => file_exists( crl_zip_path() ),
        );
    }

    public function resolve_safe_source_path( $filename ) {
        $source = realpath( crl_source_dir() );
        if ( ! $source ) {
            return false;
        }
        $candidate = realpath( $source . '/' . $filename );
        if ( ! $candidate ) {
            return false;
        }
        if ( strpos( $candidate, $source . DIRECTORY_SEPARATOR ) !== 0 && $candidate !== $source ) {
            return false;
        }
        return $candidate;
    }
}

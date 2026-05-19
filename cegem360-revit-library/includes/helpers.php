<?php
defined( 'ABSPATH' ) || exit;

function crl_table_submissions() {
    global $wpdb;
    return $wpdb->prefix . 'crl_submissions';
}

function crl_table_tokens() {
    global $wpdb;
    return $wpdb->prefix . 'crl_tokens';
}

function crl_private_dir() {
    $uploads = wp_upload_dir();
    return trailingslashit( $uploads['basedir'] ) . 'crl-private';
}

function crl_source_dir() {
    return crl_private_dir() . '/source';
}

function crl_zips_dir() {
    return crl_private_dir() . '/zips';
}

function crl_zip_path() {
    return crl_zips_dir() . '/revit-elemtar.zip';
}

function crl_option( $key, $default = '' ) {
    return get_option( 'crl_' . $key, $default );
}

function crl_get_client_ip() {
    $candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );
    foreach ( $candidates as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            if ( strpos( $ip, ',' ) !== false ) {
                $ip = trim( explode( ',', $ip )[0] );
            }
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
    }
    return '';
}

function crl_is_valid_phone( $phone ) {
    $digits = preg_replace( '/[^0-9]/', '', $phone );
    return strlen( $digits ) >= 6 && preg_match( '/^[\d\s+\-\(\)]+$/', $phone );
}

function crl_format_bytes( $bytes, $precision = 2 ) {
    $units = array( 'B', 'KB', 'MB', 'GB' );
    $bytes = max( $bytes, 0 );
    $pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
    $pow   = min( $pow, count( $units ) - 1 );
    return round( $bytes / pow( 1024, $pow ), $precision ) . ' ' . $units[ $pow ];
}

<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

if ( ! get_option( 'crl_delete_on_uninstall', 0 ) ) {
    return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crl_tokens" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crl_submissions" );

$option_keys = array(
    'crl_notification_email','crl_sender_name','crl_sender_email','crl_link_validity_days','crl_rate_limit_per_hour',
    'crl_form_title','crl_form_intro','crl_form_success_message','crl_gdpr_text','crl_privacy_page_id','crl_primary_color',
    'crl_allowed_file_types','crl_retention_days','crl_delete_on_uninstall','crl_delete_page_on_uninstall',
    'crl_email_visitor_subject','crl_email_visitor_body','crl_email_admin_subject','crl_email_admin_body',
    'crl_zip_generated_at','crl_zip_size','crl_zip_file_count','crl_landing_page_id',
);
foreach ( $option_keys as $k ) {
    delete_option( $k );
}

if ( get_option( 'crl_delete_page_on_uninstall', 0 ) ) {
    $page_id = (int) get_option( 'crl_landing_page_id', 0 );
    if ( $page_id ) wp_delete_post( $page_id, true );
}

$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'crl-private';
if ( is_dir( $dir ) ) {
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $it as $f ) {
        $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
    }
    rmdir( $dir );
}

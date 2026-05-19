<?php
defined( 'ABSPATH' ) || exit;

class CRL_Deactivator {
    public static function deactivate() {
        wp_clear_scheduled_hook( 'crl_daily_cleanup' );
        flush_rewrite_rules();
    }
}

<?php
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

class CRL_Rate_Limiter {

    public function allow( $ip, $max_per_hour ) {
        if ( empty( $ip ) ) {
            return false;
        }
        $key   = 'crl_rl_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= $max_per_hour ) {
            return false;
        }
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return true;
    }

    public function reset( $ip ) {
        delete_transient( 'crl_rl_' . md5( $ip ) );
    }
}

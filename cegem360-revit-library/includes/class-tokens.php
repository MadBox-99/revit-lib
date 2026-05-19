<?php
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

class CRL_Tokens {

    public static function generate() {
        return bin2hex( random_bytes( 32 ) );
    }

    public static function calculate_expiry( $days, $now_utc = null ) {
        $now = $now_utc ? strtotime( $now_utc . ' UTC' ) : time();
        return gmdate( 'Y-m-d H:i:s', $now + ( (int) $days * DAY_IN_SECONDS ) );
    }

    public static function is_expired( $expires_at, $now_utc = null ) {
        $now = $now_utc ? strtotime( $now_utc . ' UTC' ) : time();
        return strtotime( $expires_at . ' UTC' ) <= $now;
    }

    public function create( $submission_id, $days ) {
        global $wpdb;
        $token = self::generate();
        $wpdb->insert(
            crl_table_tokens(),
            array(
                'submission_id' => $submission_id,
                'token'         => $token,
                'expires_at'    => self::calculate_expiry( $days ),
                'created_at'    => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );
        return $token;
    }

    public function find_by_token( $token ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . crl_table_tokens() . " WHERE token = %s", $token )
        );
    }

    public function record_download( $token_id ) {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . crl_table_tokens() . " SET download_count = download_count + 1, last_downloaded_at = %s WHERE id = %d",
                current_time( 'mysql', true ),
                $token_id
            )
        );
    }

    public function find_by_submission( $submission_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . crl_table_tokens() . " WHERE submission_id = %d ORDER BY created_at DESC",
                $submission_id
            )
        );
    }

    public function delete_for_submission( $submission_id ) {
        global $wpdb;
        $wpdb->delete( crl_table_tokens(), array( 'submission_id' => $submission_id ), array( '%d' ) );
    }
}

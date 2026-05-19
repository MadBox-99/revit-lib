<?php
defined( 'ABSPATH' ) || exit;

class CRL_Submissions {

    public function create( $data ) {
        global $wpdb;
        $now      = current_time( 'mysql', true );
        $accepted = ! empty( $data['gdpr_accepted'] );
        $inserted = $wpdb->insert(
            crl_table_submissions(),
            array(
                'company_name'     => $data['company_name'],
                'email'            => $data['email'],
                'phone'            => $data['phone'],
                'ip_address'       => $data['ip_address'] ?? '',
                'user_agent'       => $data['user_agent'] ?? '',
                'gdpr_accepted'    => $accepted ? 1 : 0,
                'gdpr_accepted_at' => $accepted ? $now : null,
                'email_status'     => 'pending',
                'created_at'       => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );
        return $inserted ? (int) $wpdb->insert_id : false;
    }

    public function update_email_status( $submission_id, $status ) {
        global $wpdb;
        $wpdb->update(
            crl_table_submissions(),
            array( 'email_status' => $status ),
            array( 'id' => $submission_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public function get( $submission_id ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . crl_table_submissions() . " WHERE id = %d", $submission_id )
        );
    }

    public function delete( $submission_id ) {
        global $wpdb;
        $wpdb->delete( crl_table_tokens(),      array( 'submission_id' => $submission_id ), array( '%d' ) );
        $wpdb->delete( crl_table_submissions(), array( 'id'            => $submission_id ), array( '%d' ) );
    }

    public function query( $args = array() ) {
        global $wpdb;
        $defaults = array(
            'search'       => '',
            'email_status' => '',
            'date_from'    => '',
            'date_to'      => '',
            'orderby'      => 'created_at',
            'order'        => 'DESC',
            'per_page'     => 20,
            'page'         => 1,
        );
        $args  = wp_parse_args( $args, $defaults );
        $where = array( '1=1' );
        $vals  = array();

        if ( $args['search'] !== '' ) {
            $where[] = '(company_name LIKE %s OR email LIKE %s)';
            $like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $vals[]  = $like;
            $vals[]  = $like;
        }
        if ( $args['email_status'] !== '' ) {
            $where[] = 'email_status = %s';
            $vals[]  = $args['email_status'];
        }
        if ( $args['date_from'] !== '' ) {
            $where[] = 'created_at >= %s';
            $vals[]  = $args['date_from'] . ' 00:00:00';
        }
        if ( $args['date_to'] !== '' ) {
            $where[] = 'created_at <= %s';
            $vals[]  = $args['date_to'] . ' 23:59:59';
        }

        $allowed_orderby = array( 'id', 'company_name', 'email', 'created_at', 'email_status' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $offset = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
        $limit  = max( 1, (int) $args['per_page'] );

        $sql = 'SELECT * FROM ' . crl_table_submissions() . ' WHERE ' . implode( ' AND ', $where ) .
               " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $vals[] = $limit;
        $vals[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $vals ) );
    }

    public function count( $args = array() ) {
        global $wpdb;
        $defaults = array( 'search' => '', 'email_status' => '', 'date_from' => '', 'date_to' => '' );
        $args  = wp_parse_args( $args, $defaults );
        $where = array( '1=1' );
        $vals  = array();

        if ( $args['search'] !== '' ) {
            $where[] = '(company_name LIKE %s OR email LIKE %s)';
            $like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $vals[]  = $like;
            $vals[]  = $like;
        }
        if ( $args['email_status'] !== '' ) {
            $where[] = 'email_status = %s';
            $vals[]  = $args['email_status'];
        }
        if ( $args['date_from'] !== '' ) {
            $where[] = 'created_at >= %s';
            $vals[]  = $args['date_from'] . ' 00:00:00';
        }
        if ( $args['date_to'] !== '' ) {
            $where[] = 'created_at <= %s';
            $vals[]  = $args['date_to'] . ' 23:59:59';
        }

        $sql = 'SELECT COUNT(*) FROM ' . crl_table_submissions() . ' WHERE ' . implode( ' AND ', $where );
        return (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_var( $sql ) );
    }
}

<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CRL_Submissions_List_Table extends WP_List_Table {

    private $submissions_repo;

    public function __construct( CRL_Submissions $repo ) {
        parent::__construct( array(
            'singular' => 'submission',
            'plural'   => 'submissions',
            'ajax'     => false,
        ) );
        $this->submissions_repo = $repo;
    }

    public function get_columns() {
        return array(
            'cb'           => '<input type="checkbox">',
            'company_name' => __( 'Cégnév', 'cegem360-revit-library' ),
            'email'        => __( 'Email', 'cegem360-revit-library' ),
            'phone'        => __( 'Telefon', 'cegem360-revit-library' ),
            'created_at'   => __( 'Beküldve', 'cegem360-revit-library' ),
            'email_status' => __( 'Email státusz', 'cegem360-revit-library' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'company_name' => array( 'company_name', false ),
            'email'        => array( 'email', false ),
            'created_at'   => array( 'created_at', true ),
            'email_status' => array( 'email_status', false ),
        );
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="submission[]" value="%d">', $item->id );
    }

    protected function column_default( $item, $column ) {
        if ( $column === 'created_at' ) {
            return esc_html( wp_date( 'Y-m-d H:i', strtotime( $item->created_at . ' UTC' ) ) );
        }
        if ( $column === 'email_status' ) {
            $map = array(
                'sent'    => '<span style="color:#00a32a">✔ ' . esc_html__( 'Elküldve', 'cegem360-revit-library' ) . '</span>',
                'failed'  => '<span style="color:#d63638">✘ ' . esc_html__( 'Sikertelen', 'cegem360-revit-library' ) . '</span>',
                'pending' => '<span style="color:#dba617">⋯ ' . esc_html__( 'Folyamatban', 'cegem360-revit-library' ) . '</span>',
            );
            return $map[ $item->email_status ] ?? esc_html( $item->email_status );
        }
        return isset( $item->{$column} ) ? esc_html( $item->{$column} ) : '';
    }

    protected function column_company_name( $item ) {
        $del = wp_nonce_url( admin_url( 'admin-post.php?action=crl_delete_submission&submission_id=' . $item->id ), 'crl_delete_submission' );
        $actions = array(
            'renew'  => sprintf( '<a href="#" class="crl-renew-token" data-submission="%d">%s</a>', $item->id, esc_html__( 'Token megújítás', 'cegem360-revit-library' ) ),
            'resend' => sprintf( '<a href="#" class="crl-resend-email" data-submission="%d">%s</a>', $item->id, esc_html__( 'Email újraküldés', 'cegem360-revit-library' ) ),
            'delete' => sprintf( '<a href="%s" onclick="return confirm(\'%s\')" class="submitdelete">%s</a>', esc_url( $del ), esc_js( __( 'Biztosan törli?', 'cegem360-revit-library' ) ), esc_html__( 'Törlés', 'cegem360-revit-library' ) ),
        );
        return sprintf( '<strong>%s</strong> %s', esc_html( $item->company_name ), $this->row_actions( $actions ) );
    }

    public function prepare_items() {
        $per_page = 20;
        $page     = max( 1, (int) $this->get_pagenum() );
        $search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $status   = isset( $_REQUEST['email_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['email_status'] ) ) : '';
        $from     = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
        $to       = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';
        $orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
        $order    = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'DESC';

        $args = compact( 'search' ) + array(
            'email_status' => $status,
            'date_from'    => $from,
            'date_to'      => $to,
            'orderby'      => $orderby,
            'order'        => $order,
            'per_page'     => $per_page,
            'page'         => $page,
        );

        $this->items = $this->submissions_repo->query( $args );
        $total       = $this->submissions_repo->count( $args );

        $this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per_page, 'total_pages' => max( 1, (int) ceil( $total / $per_page ) ) ) );
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
    }

    public function extra_filters() {
        $status = isset( $_REQUEST['email_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['email_status'] ) ) : '';
        $from   = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
        $to     = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';
        ?>
        <div class="alignleft actions">
            <select name="email_status">
                <option value=""><?php esc_html_e( 'Minden státusz', 'cegem360-revit-library' ); ?></option>
                <option value="sent"    <?php selected( 'sent',    $status ); ?>><?php esc_html_e( 'Elküldve', 'cegem360-revit-library' ); ?></option>
                <option value="failed"  <?php selected( 'failed',  $status ); ?>><?php esc_html_e( 'Sikertelen', 'cegem360-revit-library' ); ?></option>
                <option value="pending" <?php selected( 'pending', $status ); ?>><?php esc_html_e( 'Folyamatban', 'cegem360-revit-library' ); ?></option>
            </select>
            <input type="date" name="date_from" value="<?php echo esc_attr( $from ); ?>">
            <input type="date" name="date_to"   value="<?php echo esc_attr( $to ); ?>">
            <?php submit_button( __( 'Szűrés', 'cegem360-revit-library' ), '', 'filter_action', false ); ?>
        </div>
        <?php
    }
}

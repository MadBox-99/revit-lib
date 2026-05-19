<?php
defined( 'ABSPATH' ) || exit;

class CRL_Settings {

    private $group = 'crl_settings';
    private $page  = 'crl-settings';

    public function register_hooks() {
        add_action( 'admin_init', array( $this, 'register' ) );
    }

    private function fields() {
        return array(
            'general' => array(
                'title'  => __( 'Általános', 'cegem360-revit-library' ),
                'fields' => array(
                    'notification_email'  => array( __( 'Értesítési email', 'cegem360-revit-library' ),  'email',  'is_email' ),
                    'sender_name'         => array( __( 'Feladó név', 'cegem360-revit-library' ),        'text',   'sanitize_text_field' ),
                    'sender_email'        => array( __( 'Feladó email', 'cegem360-revit-library' ),      'email',  'is_email' ),
                    'link_validity_days'  => array( __( 'Link érvényesség (nap)', 'cegem360-revit-library' ), 'number', 'absint' ),
                    'rate_limit_per_hour' => array( __( 'Beküldés / IP / óra', 'cegem360-revit-library' ), 'number', 'absint' ),
                ),
            ),
            'form' => array(
                'title'  => __( 'Űrlap testreszabása', 'cegem360-revit-library' ),
                'fields' => array(
                    'form_title'           => array( __( 'Cím', 'cegem360-revit-library' ),                'text',     'sanitize_text_field' ),
                    'form_intro'           => array( __( 'Bevezető szöveg', 'cegem360-revit-library' ),    'editor',   'wp_kses_post' ),
                    'gdpr_text'            => array( __( 'GDPR szöveg (%s = adatvédelmi link)', 'cegem360-revit-library' ), 'textarea', 'wp_kses_post' ),
                    'privacy_page_id'      => array( __( 'Adatvédelmi oldal', 'cegem360-revit-library' ),  'page',     'absint' ),
                    'form_success_message' => array( __( 'Sikerüzenet', 'cegem360-revit-library' ),         'text',     'sanitize_text_field' ),
                    'primary_color'        => array( __( 'Elsődleges szín', 'cegem360-revit-library' ),    'color',    'sanitize_hex_color' ),
                ),
            ),
            'email' => array(
                'title'  => __( 'Email sablonok', 'cegem360-revit-library' ),
                'fields' => array(
                    'email_visitor_subject' => array( __( 'Látogató email tárgy', 'cegem360-revit-library' ), 'text',   'sanitize_text_field' ),
                    'email_visitor_body'    => array( __( 'Látogató email szöveg', 'cegem360-revit-library' ), 'editor', 'wp_kses_post' ),
                    'email_admin_subject'   => array( __( 'Admin email tárgy', 'cegem360-revit-library' ),    'text',   'sanitize_text_field' ),
                    'email_admin_body'      => array( __( 'Admin email szöveg', 'cegem360-revit-library' ),    'editor', 'wp_kses_post' ),
                ),
            ),
            'files' => array(
                'title'  => __( 'Fájlok', 'cegem360-revit-library' ),
                'fields' => array(
                    'allowed_file_types' => array( __( 'Engedélyezett kiterjesztések (vesszővel)', 'cegem360-revit-library' ), 'text', 'sanitize_text_field' ),
                ),
            ),
            'privacy' => array(
                'title'  => __( 'Adatvédelem', 'cegem360-revit-library' ),
                'fields' => array(
                    'retention_days'           => array( __( 'Beküldések törlése X nap után (0 = nincs)', 'cegem360-revit-library' ), 'number', 'absint' ),
                    'delete_on_uninstall'      => array( __( 'Adatok törlése plugin eltávolításakor', 'cegem360-revit-library' ), 'checkbox', 'absint' ),
                    'delete_page_on_uninstall' => array( __( 'Létrehozott oldal törlése eltávolításkor', 'cegem360-revit-library' ), 'checkbox', 'absint' ),
                ),
            ),
        );
    }

    public function register() {
        foreach ( $this->fields() as $section_id => $section ) {
            $sec_full = 'crl_section_' . $section_id;
            add_settings_section( $sec_full, $section['title'], '__return_false', $this->page );
            foreach ( $section['fields'] as $key => $def ) {
                list( $label, $type, $sanitize ) = $def;
                register_setting( $this->group, 'crl_' . $key, array( 'sanitize_callback' => $sanitize ) );
                add_settings_field(
                    'crl_' . $key,
                    $label,
                    array( $this, 'render_field' ),
                    $this->page,
                    $sec_full,
                    array( 'key' => $key, 'type' => $type )
                );
            }
        }
    }

    public function render_field( $args ) {
        $key   = $args['key'];
        $type  = $args['type'];
        $name  = 'crl_' . $key;
        $value = get_option( $name, '' );

        switch ( $type ) {
            case 'text':
                printf( '<input type="text" name="%s" value="%s" class="regular-text">', esc_attr( $name ), esc_attr( $value ) );
                break;
            case 'email':
                printf( '<input type="email" name="%s" value="%s" class="regular-text">', esc_attr( $name ), esc_attr( $value ) );
                break;
            case 'number':
                printf( '<input type="number" min="0" name="%s" value="%s" class="small-text">', esc_attr( $name ), esc_attr( $value ) );
                break;
            case 'textarea':
                printf( '<textarea name="%s" rows="3" class="large-text">%s</textarea>', esc_attr( $name ), esc_textarea( $value ) );
                break;
            case 'editor':
                wp_editor( $value, $name, array( 'textarea_name' => $name, 'textarea_rows' => 8, 'media_buttons' => false ) );
                break;
            case 'checkbox':
                printf( '<input type="checkbox" name="%s" value="1" %s>', esc_attr( $name ), checked( 1, (int) $value, false ) );
                break;
            case 'color':
                printf( '<input type="text" name="%s" value="%s" class="crl-color-field">', esc_attr( $name ), esc_attr( $value ) );
                break;
            case 'page':
                wp_dropdown_pages( array( 'name' => $name, 'selected' => (int) $value, 'show_option_none' => __( '— Válassz —', 'cegem360-revit-library' ) ) );
                break;
        }
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Revit elemtár beállítások', 'cegem360-revit-library' ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( $this->group );
                do_settings_sections( $this->page );
                submit_button();
                ?>
            </form>
            <?php $this->render_diagnostics(); ?>
        </div>
        <?php
    }

    private function render_diagnostics() {
        $php_ok      = version_compare( PHP_VERSION, '7.4', '>=' );
        $zip_ok      = class_exists( 'ZipArchive' );
        $private_dir = crl_private_dir();
        $writable    = is_dir( $private_dir ) && is_writable( $private_dir );
        ?>
        <div class="crl-diagnostics">
            <h2><?php esc_html_e( 'Diagnosztika', 'cegem360-revit-library' ); ?></h2>
            <ul>
                <li>PHP <?php echo esc_html( PHP_VERSION ); ?> <span class="<?php echo $php_ok ? 'ok' : 'fail'; ?>"><?php echo $php_ok ? '✔' : '✘'; ?></span></li>
                <li>ZipArchive <span class="<?php echo $zip_ok ? 'ok' : 'fail'; ?>"><?php echo $zip_ok ? '✔' : '✘'; ?></span></li>
                <li><?php esc_html_e( 'Privát mappa írható', 'cegem360-revit-library' ); ?> <span class="<?php echo $writable ? 'ok' : 'fail'; ?>"><?php echo $writable ? '✔' : '✘'; ?></span></li>
            </ul>
            <button class="button" id="crl-test-email"><?php esc_html_e( 'Teszt email küldése', 'cegem360-revit-library' ); ?></button>
        </div>
        <?php
    }
}

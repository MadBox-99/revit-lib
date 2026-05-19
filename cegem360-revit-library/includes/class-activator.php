<?php
defined( 'ABSPATH' ) || exit;

class CRL_Activator {

    public static function activate() {
        self::create_tables();
        self::create_directories();
        self::set_default_options();
        self::create_landing_page();
        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $submissions = crl_table_submissions();
        $tokens      = crl_table_tokens();

        dbDelta( "CREATE TABLE {$submissions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent TEXT,
            gdpr_accepted TINYINT(1) NOT NULL DEFAULT 0,
            gdpr_accepted_at DATETIME NULL,
            email_status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY created_at (created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$tokens} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_downloaded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY submission_id (submission_id),
            KEY expires_at (expires_at)
        ) {$charset};" );
    }

    private static function create_directories() {
        $private = crl_private_dir();
        $dirs = array(
            $private,
            $private . '/source',
            $private . '/zips',
        );

        foreach ( $dirs as $dir ) {
            if ( ! file_exists( $dir ) ) {
                wp_mkdir_p( $dir );
            }
        }

        $htaccess = $private . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $htaccess_contents = "<IfModule !authz_core_module>\nDeny from all\n</IfModule>\n<IfModule authz_core_module>\nRequire all denied\n</IfModule>\n";
            file_put_contents( $htaccess, $htaccess_contents );
        }

        $index = $private . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    private static function set_default_options() {
        $defaults = array(
            'notification_email'      => get_option( 'admin_email' ),
            'sender_name'             => get_bloginfo( 'name' ),
            'sender_email'            => get_option( 'admin_email' ),
            'link_validity_days'      => 7,
            'rate_limit_per_hour'     => 3,
            'form_title'              => __( 'Revit elemtár letöltése', 'cegem360-revit-library' ),
            'form_intro'              => __( 'Töltse ki az alábbi űrlapot, és a letöltési linket emailben elküldjük Önnek.', 'cegem360-revit-library' ),
            'form_success_message'    => __( 'Köszönjük! A letöltési linket elküldtük az Ön email címére.', 'cegem360-revit-library' ),
            'gdpr_text'               => __( 'Elfogadom az <a href="%s" target="_blank">adatvédelmi tájékoztatót</a>.', 'cegem360-revit-library' ),
            'privacy_page_id'         => 0,
            'primary_color'           => '#2271b1',
            'allowed_file_types'      => 'rfa,rvt,rte,rft,zip,pdf,jpg,png',
            'retention_days'          => 0,
            'delete_on_uninstall'     => 0,
            'delete_page_on_uninstall'=> 0,
            'email_visitor_subject'   => __( 'Revit elemtár letöltése — {cegnev}', 'cegem360-revit-library' ),
            'email_visitor_body'      => self::default_visitor_email_body(),
            'email_admin_subject'     => __( 'Új Revit elemtár igénylés — {cegnev}', 'cegem360-revit-library' ),
            'email_admin_body'        => self::default_admin_email_body(),
        );

        foreach ( $defaults as $key => $value ) {
            if ( get_option( 'crl_' . $key ) === false ) {
                add_option( 'crl_' . $key, $value );
            }
        }
    }

    private static function default_visitor_email_body() {
        return "<p>Tisztelt {cegnev}!</p>\n\n<p>Köszönjük érdeklődését Revit elemtárunk iránt.</p>\n\n<p><a href=\"{download_link}\" style=\"display:inline-block;padding:12px 24px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;\">Revit elemtár letöltése</a></p>\n\n<p>Ha a fenti gomb nem működne, másolja ezt a linket a böngészőbe:<br><a href=\"{download_link}\">{download_link}</a></p>\n\n<p>A link <strong>{expires_days} napig</strong> érvényes ({expires_date}-ig).</p>\n\n<p>Üdvözlettel,<br>Cegem360</p>";
    }

    private static function default_admin_email_body() {
        return "<p>Új beküldés érkezett a Revit elemtár űrlapról:</p>\n\n<ul>\n<li><strong>Cégnév:</strong> {cegnev}</li>\n<li><strong>Email:</strong> {email}</li>\n<li><strong>Telefon:</strong> {telefon}</li>\n<li><strong>Időpont:</strong> {idopont}</li>\n<li><strong>IP cím:</strong> {ip}</li>\n</ul>\n\n<p><a href=\"{admin_url}\">Megnyitás az adminban →</a></p>";
    }

    private static function create_landing_page() {
        if ( get_option( 'crl_landing_page_id' ) ) {
            return;
        }
        $existing = get_page_by_path( 'revit-elemtar' );
        if ( $existing ) {
            update_option( 'crl_landing_page_id', $existing->ID );
            return;
        }
        $page_id = wp_insert_post( array(
            'post_title'   => __( 'Revit elemtár', 'cegem360-revit-library' ),
            'post_name'    => 'revit-elemtar',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => "<!-- wp:paragraph -->\n<p>" . __( 'Töltse ki az alábbi űrlapot a Revit elemtár letöltéséhez.', 'cegem360-revit-library' ) . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[revit_library_form]\n<!-- /wp:shortcode -->",
        ) );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_post_meta( $page_id, '_crl_auto_created', 1 );
            update_option( 'crl_landing_page_id', $page_id );
        }
    }
}

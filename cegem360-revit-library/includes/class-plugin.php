<?php
defined( 'ABSPATH' ) || exit;

class CRL_Plugin {
    private static $instance = null;

    public $submissions;
    public $tokens;
    public $rate_limiter;
    public $zip_manager;
    public $file_manager;
    public $mailer;
    public $form;
    public $download_handler;
    public $admin;
    public $settings;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        load_plugin_textdomain( 'cegem360-revit-library', false, dirname( CRL_PLUGIN_BASENAME ) . '/languages' );

        $this->submissions      = new CRL_Submissions();
        $this->tokens           = new CRL_Tokens();
        $this->rate_limiter     = new CRL_Rate_Limiter();
        $this->zip_manager      = new CRL_Zip_Manager();
        $this->file_manager     = new CRL_File_Manager( $this->zip_manager );
        $this->mailer           = new CRL_Mailer();
        $this->form             = new CRL_Form( $this->submissions, $this->tokens, $this->mailer, $this->rate_limiter );
        $this->download_handler = new CRL_Download_Handler( $this->tokens, $this->zip_manager );

        $this->form->register_hooks();
        $this->download_handler->register_hooks();

        if ( is_admin() ) {
            $this->admin    = new CRL_Admin( $this );
            $this->settings = new CRL_Settings();
            $this->admin->register_hooks();
            $this->settings->register_hooks();
        }
    }

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() {
        throw new \RuntimeException( 'Cannot unserialize singleton' );
    }
}

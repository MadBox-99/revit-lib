# Revit elemtár WP plugin — implementációs terv

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress plugin that gates a downloadable Revit library ZIP behind a lead-capture form (company / email / phone / GDPR), emails a time-limited download link, and lists submissions in admin.

**Architecture:** Self-contained WordPress plugin, no external dependencies (PHP `ZipArchive` + WP core). Two custom DB tables. Files stored in a private `wp-content/uploads/crl-private/` directory, ZIP streamed via PHP after token validation. Admin UI with three tabs: Submissions / Files / Settings.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, MySQL, vanilla JS (no build step), CSS variables for theming. PHPUnit + Brain Monkey for the unit-testable pure-PHP classes.

**Spec:** `docs/superpowers/specs/2026-05-19-revit-library-wp-plugin-design.md`

**Output location:** `/Users/szabozoltan/Downloads/revit-lib/cegem360-revit-library/`

---

## File Structure

```
revit-lib/                                            (working directory, contains the plugin + tests)
├── cegem360-revit-library/                           (the actual WP plugin)
│   ├── cegem360-revit-library.php
│   ├── uninstall.php
│   ├── readme.txt
│   ├── composer.json                                 (dev-only, for PHPUnit)
│   ├── includes/
│   │   ├── class-plugin.php
│   │   ├── class-activator.php
│   │   ├── class-deactivator.php
│   │   ├── class-form.php
│   │   ├── class-submissions.php
│   │   ├── class-tokens.php
│   │   ├── class-zip-manager.php
│   │   ├── class-mailer.php
│   │   ├── class-admin.php
│   │   ├── class-settings.php
│   │   ├── class-submissions-list-table.php
│   │   ├── class-file-manager.php
│   │   ├── class-download-handler.php
│   │   ├── class-rate-limiter.php
│   │   └── helpers.php
│   ├── assets/
│   │   ├── css/form.css
│   │   ├── css/admin.css
│   │   ├── js/form.js
│   │   └── js/admin.js
│   ├── templates/
│   │   ├── form.php
│   │   ├── email-download-link.php
│   │   └── email-admin-notification.php
│   └── languages/
│       └── cegem360-revit-library.pot
└── tests/
    ├── bootstrap.php
    ├── phpunit.xml.dist
    ├── TokensTest.php
    ├── RateLimiterTest.php
    └── ZipManagerTest.php
```

**Responsibility split:**
- `class-plugin.php` — single bootstrap, wires all classes via DI in `init()`
- `class-activator.php` / `class-deactivator.php` — one-time setup / teardown (DB tables, dirs, page, options)
- `class-form.php` — shortcode + render + AJAX submit handling (front-end controller)
- `class-submissions.php` — DB CRUD for submissions table
- `class-tokens.php` — DB CRUD for tokens table + generation/validation logic (pure-ish, testable)
- `class-zip-manager.php` — ZIP regeneration, file listing, size info
- `class-mailer.php` — email template rendering + `wp_mail()` calls
- `class-file-manager.php` — upload directory CRUD
- `class-rate-limiter.php` — IP-keyed transient counter (testable in isolation)
- `class-download-handler.php` — `?crl_download=...` URL processing + streaming
- `class-admin.php` — admin menu registration, enqueue admin assets
- `class-settings.php` — settings API registration + rendering
- `class-submissions-list-table.php` — `WP_List_Table` extension
- `helpers.php` — pure helper functions (path normalizers, validators, escapers)

---

## Test Strategy

- **Unit tests (PHPUnit + Brain Monkey):** `Tokens` token generation/expiry logic, `RateLimiter` window logic, `ZipManager::list_source_files()` directory scan with realpath safety. These are the parts where bugs hurt most (security, security, path-traversal).
- **Manual integration test:** entire form submit → email → download cycle, exercised via the manual test checklist in Task 26.

We don't try to mock the whole WP environment. Brain Monkey is enough to stub the half-dozen WP functions our pure classes touch.

---

## Conventions Used Throughout

- **Text domain:** `cegem360-revit-library`
- **Option prefix:** `crl_` (e.g. `crl_notification_email`)
- **DB table prefix:** `{$wpdb->prefix}crl_` (e.g. `wp_crl_submissions`)
- **Hook prefix:** `crl_` (e.g. `crl_after_submission`)
- **Nonce action:** `crl_submit_form`, `crl_admin_action`
- **CSS/JS handles:** `crl-form`, `crl-admin`
- **AJAX action names:** `crl_submit_form`, `crl_upload_file`, `crl_delete_file`, `crl_regenerate_zip`, `crl_test_email`, `crl_resend_email`, `crl_renew_token`
- **PHP namespace:** none (class prefix `CRL_` instead — keeps compatibility with PHP 7.4 and simple file structure)
- **All times stored as UTC `DATETIME`** in DB, formatted with `wp_date()` on display

---

## Task 1: Plugin scaffolding + main file

**Files:**
- Create: `revit-lib/cegem360-revit-library/cegem360-revit-library.php`
- Create: `revit-lib/cegem360-revit-library/includes/helpers.php`
- Create: `revit-lib/cegem360-revit-library/readme.txt`

- [ ] **Step 1: Create the plugin's main entry file**

```php
<?php
/**
 * Plugin Name:       Cegem360 Revit Library
 * Plugin URI:        https://cegem360.hu/
 * Description:       Lead-gated Revit library download with admin submission list, time-limited download tokens and a private file manager.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cegem360
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cegem360-revit-library
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CRL_VERSION', '1.0.0' );
define( 'CRL_PLUGIN_FILE', __FILE__ );
define( 'CRL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CRL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once CRL_PLUGIN_DIR . 'includes/helpers.php';
require_once CRL_PLUGIN_DIR . 'includes/class-activator.php';
require_once CRL_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once CRL_PLUGIN_DIR . 'includes/class-plugin.php';
require_once CRL_PLUGIN_DIR . 'includes/class-submissions.php';
require_once CRL_PLUGIN_DIR . 'includes/class-tokens.php';
require_once CRL_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once CRL_PLUGIN_DIR . 'includes/class-zip-manager.php';
require_once CRL_PLUGIN_DIR . 'includes/class-file-manager.php';
require_once CRL_PLUGIN_DIR . 'includes/class-mailer.php';
require_once CRL_PLUGIN_DIR . 'includes/class-form.php';
require_once CRL_PLUGIN_DIR . 'includes/class-download-handler.php';
require_once CRL_PLUGIN_DIR . 'includes/class-admin.php';
require_once CRL_PLUGIN_DIR . 'includes/class-settings.php';
require_once CRL_PLUGIN_DIR . 'includes/class-submissions-list-table.php';

register_activation_hook( __FILE__, array( 'CRL_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CRL_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', function() {
    CRL_Plugin::instance()->init();
} );
```

- [ ] **Step 2: Create `includes/helpers.php` with the constants and reusable utilities**

```php
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
```

- [ ] **Step 3: Create stub `readme.txt`**

```
=== Cegem360 Revit Library ===
Contributors: cegem360
Tags: revit, download, lead-generation
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Lead-gated Revit library download with admin submission list and time-limited download tokens.

== Description ==

Provides a `[revit_library_form]` shortcode that collects company, email and phone, emails a time-limited download link, and stores submissions in the admin.

== Changelog ==

= 1.0.0 =
* Initial release.
```

- [ ] **Step 4: Verify the plugin loads (manual)**

Drop the folder into a local WP install's `wp-content/plugins/`. The plugin should appear in the plugins list but produce fatal errors when activated (the include files don't exist yet). That's expected — next tasks create them.

- [ ] **Step 5: Commit**

```bash
cd /Users/szabozoltan/Downloads/revit-lib
git init
git add cegem360-revit-library/
git commit -m "feat: scaffold plugin main file, helpers, readme"
```

---

## Task 2: Activator — DB tables, directories, default options

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-activator.php`
- Create: `revit-lib/cegem360-revit-library/includes/class-deactivator.php`

- [ ] **Step 1: Create `class-activator.php`**

```php
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
            file_put_contents( $htaccess, "Deny from all\n" );
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
```

- [ ] **Step 2: Create `class-deactivator.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_Deactivator {
    public static function deactivate() {
        wp_clear_scheduled_hook( 'crl_daily_cleanup' );
        flush_rewrite_rules();
    }
}
```

- [ ] **Step 3: Manual verification**

Activate the plugin. Verify:
- Tables exist: `SELECT * FROM wp_crl_submissions; SELECT * FROM wp_crl_tokens;` (both empty, no error)
- `wp-content/uploads/crl-private/` exists with `source/`, `zips/`, `.htaccess`, `index.php`
- `wp_options` has rows for all `crl_*` keys
- `/revit-elemtar/` page exists, contains the shortcode

- [ ] **Step 4: Commit**

```bash
git add cegem360-revit-library/includes/
git commit -m "feat: activation hook creates DB tables, private dirs, defaults, landing page"
```

---

## Task 3: Plugin bootstrap class

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-plugin.php`

- [ ] **Step 1: Create the bootstrap class**

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add cegem360-revit-library/includes/class-plugin.php
git commit -m "feat: add plugin bootstrap singleton with DI wiring"
```

---

## Task 4: Tokens class with unit tests

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-tokens.php`
- Create: `revit-lib/cegem360-revit-library/composer.json`
- Create: `revit-lib/tests/phpunit.xml.dist`
- Create: `revit-lib/tests/bootstrap.php`
- Create: `revit-lib/tests/TokensTest.php`

- [ ] **Step 1: Create test scaffolding — `composer.json`**

```json
{
  "name": "cegem360/revit-library",
  "description": "Cegem360 Revit Library WP plugin",
  "type": "wordpress-plugin",
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "brain/monkey": "^2.6"
  },
  "autoload-dev": {
    "classmap": ["includes/"]
  }
}
```

- [ ] **Step 2: Create `tests/phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="bootstrap.php"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
    convertDeprecationsToExceptions="true">
    <testsuites>
        <testsuite name="unit">
            <directory>.</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create `tests/bootstrap.php`**

```php
<?php
require_once __DIR__ . '/../cegem360-revit-library/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

require_once __DIR__ . '/../cegem360-revit-library/includes/helpers.php';
require_once __DIR__ . '/../cegem360-revit-library/includes/class-tokens.php';
require_once __DIR__ . '/../cegem360-revit-library/includes/class-rate-limiter.php';
require_once __DIR__ . '/../cegem360-revit-library/includes/class-zip-manager.php';
```

- [ ] **Step 4: Install dev deps**

```bash
cd /Users/szabozoltan/Downloads/revit-lib/cegem360-revit-library
composer install
```

Expected: PHPUnit + Brain Monkey installed in `vendor/`. Add `vendor/` to gitignore in a later commit.

- [ ] **Step 5: Write the failing test — `tests/TokensTest.php`**

```php
<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class TokensTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'current_time' )->alias( function( $type ) {
            return $type === 'mysql' ? gmdate( 'Y-m-d H:i:s' ) : time();
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_generate_returns_64_char_hex() {
        $token = CRL_Tokens::generate();
        $this->assertSame( 64, strlen( $token ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
    }

    public function test_two_generated_tokens_differ() {
        $this->assertNotSame( CRL_Tokens::generate(), CRL_Tokens::generate() );
    }

    public function test_calculate_expiry_adds_days_to_now_utc() {
        $expiry = CRL_Tokens::calculate_expiry( 7, '2026-05-19 12:00:00' );
        $this->assertSame( '2026-05-26 12:00:00', $expiry );
    }

    public function test_is_expired_returns_true_when_past() {
        $this->assertTrue( CRL_Tokens::is_expired( '2026-05-18 11:59:59', '2026-05-19 12:00:00' ) );
    }

    public function test_is_expired_returns_false_when_future() {
        $this->assertFalse( CRL_Tokens::is_expired( '2026-05-20 12:00:00', '2026-05-19 12:00:00' ) );
    }

    public function test_is_expired_returns_true_when_equal() {
        $this->assertTrue( CRL_Tokens::is_expired( '2026-05-19 12:00:00', '2026-05-19 12:00:00' ) );
    }
}
```

- [ ] **Step 6: Run the test, verify failure**

```bash
cd /Users/szabozoltan/Downloads/revit-lib/cegem360-revit-library
./vendor/bin/phpunit -c ../tests/phpunit.xml.dist ../tests
```

Expected: FAIL — `CRL_Tokens` class not found.

- [ ] **Step 7: Implement `class-tokens.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

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

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
```

- [ ] **Step 8: Re-run tests, verify pass**

```bash
./vendor/bin/phpunit -c ../tests/phpunit.xml.dist ../tests
```

Expected: PASS — 6 assertions, 0 failures.

- [ ] **Step 9: Commit**

```bash
echo "vendor/" > .gitignore
git add cegem360-revit-library/.gitignore cegem360-revit-library/composer.json cegem360-revit-library/includes/class-tokens.php tests/
git commit -m "feat: tokens class with generation, expiry, lookup + unit tests"
```

---

## Task 5: Rate limiter with unit tests

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-rate-limiter.php`
- Create: `revit-lib/tests/RateLimiterTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class RateLimiterTest extends TestCase {

    private $store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->store = array();
        Functions\when( 'get_transient' )->alias( function( $key ) {
            return isset( $this->store[ $key ] ) ? $this->store[ $key ] : false;
        } );
        Functions\when( 'set_transient' )->alias( function( $key, $value, $ttl ) {
            $this->store[ $key ] = $value;
            return true;
        } );
        Functions\when( 'delete_transient' )->alias( function( $key ) {
            unset( $this->store[ $key ] );
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_allows_first_request() {
        $limiter = new CRL_Rate_Limiter();
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
    }

    public function test_allows_up_to_limit() {
        $limiter = new CRL_Rate_Limiter();
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
        $this->assertTrue( $limiter->allow( '1.2.3.4', 3 ) );
    }

    public function test_blocks_after_limit_reached() {
        $limiter = new CRL_Rate_Limiter();
        $limiter->allow( '1.2.3.4', 2 );
        $limiter->allow( '1.2.3.4', 2 );
        $this->assertFalse( $limiter->allow( '1.2.3.4', 2 ) );
    }

    public function test_separate_ips_have_separate_counters() {
        $limiter = new CRL_Rate_Limiter();
        $limiter->allow( '1.2.3.4', 1 );
        $this->assertTrue( $limiter->allow( '9.9.9.9', 1 ) );
    }

    public function test_empty_ip_is_blocked() {
        $limiter = new CRL_Rate_Limiter();
        $this->assertFalse( $limiter->allow( '', 3 ) );
    }
}
```

- [ ] **Step 2: Run test, verify fail**

```bash
./vendor/bin/phpunit -c ../tests/phpunit.xml.dist ../tests --filter RateLimiterTest
```

Expected: FAIL — `CRL_Rate_Limiter` not found.

- [ ] **Step 3: Implement `class-rate-limiter.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

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

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
```

- [ ] **Step 4: Run tests, verify pass**

```bash
./vendor/bin/phpunit -c ../tests/phpunit.xml.dist ../tests --filter RateLimiterTest
```

Expected: PASS — 5 assertions.

- [ ] **Step 5: Commit**

```bash
git add cegem360-revit-library/includes/class-rate-limiter.php tests/RateLimiterTest.php
git commit -m "feat: IP-keyed rate limiter with transient backing + tests"
```

---

## Task 6: Submissions class

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-submissions.php`

- [ ] **Step 1: Implement the class**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_Submissions {

    public function create( $data ) {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $inserted = $wpdb->insert(
            crl_table_submissions(),
            array(
                'company_name'     => $data['company_name'],
                'email'            => $data['email'],
                'phone'            => $data['phone'],
                'ip_address'       => $data['ip_address'] ?? '',
                'user_agent'       => $data['user_agent'] ?? '',
                'gdpr_accepted'    => $data['gdpr_accepted'] ? 1 : 0,
                'gdpr_accepted_at' => $data['gdpr_accepted'] ? $now : null,
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
```

- [ ] **Step 2: Commit**

```bash
git add cegem360-revit-library/includes/class-submissions.php
git commit -m "feat: submissions CRUD with filtering and pagination"
```

---

## Task 7: ZIP manager with unit tests

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-zip-manager.php`
- Create: `revit-lib/tests/ZipManagerTest.php`

- [ ] **Step 1: Write the failing tests for path safety**

```php
<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class ZipManagerTest extends TestCase {

    private $tmp;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->tmp = sys_get_temp_dir() . '/crl-test-' . uniqid();
        mkdir( $this->tmp . '/source', 0777, true );
        mkdir( $this->tmp . '/zips', 0777, true );
        Functions\when( 'crl_source_dir' )->justReturn( $this->tmp . '/source' );
        Functions\when( 'crl_zips_dir' )->justReturn( $this->tmp . '/zips' );
        Functions\when( 'crl_zip_path' )->justReturn( $this->tmp . '/zips/revit-elemtar.zip' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-05-19 12:00:00' );
    }

    protected function tearDown(): void {
        $this->rrmdir( $this->tmp );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        foreach ( scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . '/' . $item;
            is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
        }
        rmdir( $dir );
    }

    public function test_list_source_files_returns_empty_array_when_dir_empty() {
        $mgr = new CRL_Zip_Manager();
        $this->assertSame( array(), $mgr->list_source_files() );
    }

    public function test_list_source_files_returns_files_with_metadata() {
        file_put_contents( $this->tmp . '/source/test.rfa', 'abc' );
        $mgr   = new CRL_Zip_Manager();
        $files = $mgr->list_source_files();
        $this->assertCount( 1, $files );
        $this->assertSame( 'test.rfa', $files[0]['name'] );
        $this->assertSame( 3, $files[0]['size'] );
    }

    public function test_list_source_files_excludes_dotfiles() {
        file_put_contents( $this->tmp . '/source/.htaccess', 'x' );
        file_put_contents( $this->tmp . '/source/visible.rfa', 'x' );
        $files = ( new CRL_Zip_Manager() )->list_source_files();
        $this->assertCount( 1, $files );
        $this->assertSame( 'visible.rfa', $files[0]['name'] );
    }

    public function test_regenerate_produces_zip_with_source_files() {
        file_put_contents( $this->tmp . '/source/a.rfa', 'aaa' );
        file_put_contents( $this->tmp . '/source/b.rfa', 'bbb' );
        $mgr = new CRL_Zip_Manager();
        $this->assertTrue( $mgr->regenerate() );
        $this->assertFileExists( $this->tmp . '/zips/revit-elemtar.zip' );

        $zip = new ZipArchive();
        $zip->open( $this->tmp . '/zips/revit-elemtar.zip' );
        $this->assertSame( 2, $zip->numFiles );
        $names = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $names[] = $zip->getNameIndex( $i );
        }
        sort( $names );
        $this->assertSame( array( 'a.rfa', 'b.rfa' ), $names );
        $zip->close();
    }

    public function test_resolve_safe_path_rejects_traversal() {
        $mgr = new CRL_Zip_Manager();
        $safe = $mgr->resolve_safe_source_path( '../etc/passwd' );
        $this->assertFalse( $safe );
    }

    public function test_resolve_safe_path_accepts_filename() {
        file_put_contents( $this->tmp . '/source/legit.rfa', 'x' );
        $mgr = new CRL_Zip_Manager();
        $safe = $mgr->resolve_safe_source_path( 'legit.rfa' );
        $this->assertSame( realpath( $this->tmp . '/source/legit.rfa' ), $safe );
    }
}
```

- [ ] **Step 2: Run test, verify fail**

```bash
./vendor/bin/phpunit -c ../tests/phpunit.xml.dist ../tests --filter ZipManagerTest
```

Expected: FAIL — class not defined.

- [ ] **Step 3: Implement `class-zip-manager.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_Zip_Manager {

    public function list_source_files() {
        $dir = crl_source_dir();
        if ( ! is_dir( $dir ) ) {
            return array();
        }
        $files = array();
        foreach ( scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' || strpos( $item, '.' ) === 0 ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( ! is_file( $path ) ) {
                continue;
            }
            $files[] = array(
                'name'     => $item,
                'size'     => filesize( $path ),
                'modified' => filemtime( $path ),
            );
        }
        return $files;
    }

    public function regenerate() {
        $source = crl_source_dir();
        if ( ! is_dir( $source ) ) {
            return false;
        }

        $tmp   = crl_zip_path() . '.tmp';
        $final = crl_zip_path();

        if ( file_exists( $tmp ) ) {
            unlink( $tmp );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            return false;
        }

        $count = 0;
        foreach ( $this->list_source_files() as $file ) {
            $path = $source . '/' . $file['name'];
            $zip->addFile( $path, $file['name'] );
            $count++;
        }
        $zip->close();

        if ( $count === 0 ) {
            if ( file_exists( $final ) ) unlink( $final );
            if ( file_exists( $tmp ) )   unlink( $tmp );
            update_option( 'crl_zip_generated_at', current_time( 'mysql', true ) );
            update_option( 'crl_zip_size', 0 );
            update_option( 'crl_zip_file_count', 0 );
            return true;
        }

        rename( $tmp, $final );
        update_option( 'crl_zip_generated_at', current_time( 'mysql', true ) );
        update_option( 'crl_zip_size', filesize( $final ) );
        update_option( 'crl_zip_file_count', $count );
        return true;
    }

    public function get_info() {
        return array(
            'generated_at' => get_option( 'crl_zip_generated_at', '' ),
            'size'         => (int) get_option( 'crl_zip_size', 0 ),
            'file_count'   => (int) get_option( 'crl_zip_file_count', 0 ),
            'exists'       => file_exists( crl_zip_path() ),
        );
    }

    public function resolve_safe_source_path( $filename ) {
        $source = realpath( crl_source_dir() );
        if ( ! $source ) {
            return false;
        }
        $candidate = realpath( $source . '/' . $filename );
        if ( ! $candidate ) {
            return false;
        }
        if ( strpos( $candidate, $source . DIRECTORY_SEPARATOR ) !== 0 && $candidate !== $source ) {
            return false;
        }
        return $candidate;
    }
}
```

- [ ] **Step 4: Run tests, verify pass**

```bash
./vendor/bin/phpunit -c ../tests/phpunit.xml.dist ../tests --filter ZipManagerTest
```

Expected: PASS — 6 assertions.

- [ ] **Step 5: Commit**

```bash
git add cegem360-revit-library/includes/class-zip-manager.php tests/ZipManagerTest.php
git commit -m "feat: ZIP manager with regenerate, list, path-safety + tests"
```

---

## Task 8: File manager

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-file-manager.php`

- [ ] **Step 1: Implement**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_File_Manager {

    private $zip_manager;

    public function __construct( CRL_Zip_Manager $zip_manager ) {
        $this->zip_manager = $zip_manager;
    }

    public function allowed_extensions() {
        $raw = crl_option( 'allowed_file_types', 'rfa,rvt,rte,rft,zip,pdf,jpg,png' );
        return array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );
    }

    public function handle_upload( $file ) {
        if ( empty( $file ) || ! isset( $file['error'] ) ) {
            return new WP_Error( 'crl_no_file', __( 'Nincs feltöltött fájl.', 'cegem360-revit-library' ) );
        }
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'crl_upload_error', __( 'Feltöltési hiba.', 'cegem360-revit-library' ) );
        }

        $name = sanitize_file_name( $file['name'] );
        $ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $this->allowed_extensions(), true ) ) {
            return new WP_Error( 'crl_bad_ext', __( 'Nem engedélyezett fájltípus.', 'cegem360-revit-library' ) );
        }

        $target = crl_source_dir() . '/' . $name;
        if ( file_exists( $target ) ) {
            $target = crl_source_dir() . '/' . wp_unique_filename( crl_source_dir(), $name );
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error( 'crl_move_failed', __( 'A fájl mentése sikertelen.', 'cegem360-revit-library' ) );
        }

        $this->zip_manager->regenerate();
        return basename( $target );
    }

    public function delete( $filename ) {
        $path = $this->zip_manager->resolve_safe_source_path( $filename );
        if ( ! $path || ! is_file( $path ) ) {
            return new WP_Error( 'crl_not_found', __( 'A fájl nem található.', 'cegem360-revit-library' ) );
        }
        if ( ! unlink( $path ) ) {
            return new WP_Error( 'crl_delete_failed', __( 'Törlés sikertelen.', 'cegem360-revit-library' ) );
        }
        $this->zip_manager->regenerate();
        return true;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add cegem360-revit-library/includes/class-file-manager.php
git commit -m "feat: file manager with allow-list, path-safe delete, auto-regen ZIP"
```

---

## Task 9: Mailer

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-mailer.php`
- Create: `revit-lib/cegem360-revit-library/templates/email-download-link.php`
- Create: `revit-lib/cegem360-revit-library/templates/email-admin-notification.php`

- [ ] **Step 1: Create the visitor email template**

```php
<?php defined( 'ABSPATH' ) || exit; ?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#222;max-width:600px;margin:0 auto;padding:20px;">
<?php echo wpautop( $body ); ?>
</body></html>
```

- [ ] **Step 2: Create the admin email template (same wrapper)**

Copy the file above to `email-admin-notification.php`. Same structure.

- [ ] **Step 3: Implement `class-mailer.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_Mailer {

    public function send_visitor_email( $submission, $token, $download_link ) {
        $expires_days = (int) crl_option( 'link_validity_days', 7 );
        $expires_date = wp_date(
            get_option( 'date_format' ),
            strtotime( $token->expires_at . ' UTC' )
        );

        $subject_tpl = crl_option( 'email_visitor_subject' );
        $body_tpl    = crl_option( 'email_visitor_body' );

        $replace = array(
            '{cegnev}'        => $submission->company_name,
            '{download_link}' => esc_url( $download_link ),
            '{expires_days}'  => (string) $expires_days,
            '{expires_date}'  => $expires_date,
        );

        $subject = strtr( $subject_tpl, $replace );
        $body    = strtr( $body_tpl, $replace );

        return $this->send( $submission->email, $subject, $body );
    }

    public function send_admin_notification( $submission ) {
        $to          = crl_option( 'notification_email', get_option( 'admin_email' ) );
        $subject_tpl = crl_option( 'email_admin_subject' );
        $body_tpl    = crl_option( 'email_admin_body' );

        $replace = array(
            '{cegnev}'   => $submission->company_name,
            '{email}'    => $submission->email,
            '{telefon}'  => $submission->phone,
            '{idopont}'  => wp_date( 'Y-m-d H:i', strtotime( $submission->created_at . ' UTC' ) ),
            '{ip}'       => $submission->ip_address,
            '{admin_url}'=> admin_url( 'admin.php?page=crl-submissions' ),
        );

        $subject = strtr( $subject_tpl, $replace );
        $body    = strtr( $body_tpl, $replace );

        return $this->send( $to, $subject, $body );
    }

    public function send_test( $to ) {
        return $this->send( $to, __( 'Cegem360 Revit Library — teszt email', 'cegem360-revit-library' ), '<p>Ez egy teszt email a Revit elemtár pluginból.</p>' );
    }

    private function send( $to, $subject, $body ) {
        $from_email = crl_option( 'sender_email', get_option( 'admin_email' ) );
        $from_name  = crl_option( 'sender_name', get_bloginfo( 'name' ) );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
        );

        ob_start();
        include CRL_PLUGIN_DIR . 'templates/email-download-link.php';
        $html = ob_get_clean();

        return wp_mail( $to, $subject, $html, $headers );
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add cegem360-revit-library/includes/class-mailer.php cegem360-revit-library/templates/
git commit -m "feat: mailer with template rendering and placeholder substitution"
```

---

## Task 10: Form class — shortcode and AJAX submit

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-form.php`
- Create: `revit-lib/cegem360-revit-library/templates/form.php`

- [ ] **Step 1: Create `templates/form.php`**

```php
<?php defined( 'ABSPATH' ) || exit; ?>
<div class="crl-form-wrapper" style="--crl-primary-color: <?php echo esc_attr( crl_option( 'primary_color', '#2271b1' ) ); ?>;">
    <?php if ( $title ) : ?>
        <h2 class="crl-form-title"><?php echo esc_html( $title ); ?></h2>
    <?php endif; ?>

    <?php if ( $intro ) : ?>
        <div class="crl-form-intro"><?php echo wp_kses_post( wpautop( $intro ) ); ?></div>
    <?php endif; ?>

    <form class="crl-form" novalidate>
        <?php wp_nonce_field( 'crl_submit_form', 'crl_nonce' ); ?>
        <div class="crl-field">
            <label for="crl-company"><?php esc_html_e( 'Cégnév', 'cegem360-revit-library' ); ?> <span aria-hidden="true">*</span></label>
            <input type="text" id="crl-company" name="company_name" required minlength="2" maxlength="255">
            <div class="crl-error" data-field="company_name"></div>
        </div>
        <div class="crl-field">
            <label for="crl-email"><?php esc_html_e( 'Email', 'cegem360-revit-library' ); ?> <span aria-hidden="true">*</span></label>
            <input type="email" id="crl-email" name="email" required>
            <div class="crl-error" data-field="email"></div>
        </div>
        <div class="crl-field">
            <label for="crl-phone"><?php esc_html_e( 'Telefonszám', 'cegem360-revit-library' ); ?> <span aria-hidden="true">*</span></label>
            <input type="tel" id="crl-phone" name="phone" required>
            <div class="crl-error" data-field="phone"></div>
        </div>
        <div class="crl-field crl-field-checkbox">
            <label><input type="checkbox" name="gdpr" value="1" required>
                <?php echo wp_kses_post( $gdpr_html ); ?>
            </label>
            <div class="crl-error" data-field="gdpr"></div>
        </div>
        <div class="crl-honeypot" aria-hidden="true">
            <label>Website <input type="text" name="crl_website" tabindex="-1" autocomplete="off"></label>
        </div>
        <button type="submit" class="crl-submit"><?php echo esc_html( $button_text ); ?></button>
        <div class="crl-form-message" role="status" aria-live="polite"></div>
    </form>
</div>
```

- [ ] **Step 2: Implement `class-form.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_Form {

    private $submissions;
    private $tokens;
    private $mailer;
    private $rate_limiter;

    public function __construct( CRL_Submissions $submissions, CRL_Tokens $tokens, CRL_Mailer $mailer, CRL_Rate_Limiter $rate_limiter ) {
        $this->submissions  = $submissions;
        $this->tokens       = $tokens;
        $this->mailer       = $mailer;
        $this->rate_limiter = $rate_limiter;
    }

    public function register_hooks() {
        add_shortcode( 'revit_library_form', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_crl_submit_form', array( $this, 'handle_submit' ) );
        add_action( 'wp_ajax_nopriv_crl_submit_form', array( $this, 'handle_submit' ) );
    }

    public function enqueue_assets() {
        wp_register_style( 'crl-form', CRL_PLUGIN_URL . 'assets/css/form.css', array(), CRL_VERSION );
        wp_register_script( 'crl-form', CRL_PLUGIN_URL . 'assets/js/form.js', array(), CRL_VERSION, true );
        wp_localize_script( 'crl-form', 'CRL_FORM', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'messages' => array(
                'generic_error' => __( 'Hiba történt, kérjük próbálja újra.', 'cegem360-revit-library' ),
                'sending'       => __( 'Küldés…', 'cegem360-revit-library' ),
            ),
        ) );
    }

    public function render_shortcode( $atts ) {
        wp_enqueue_style( 'crl-form' );
        wp_enqueue_script( 'crl-form' );

        $atts = shortcode_atts( array(
            'title'       => crl_option( 'form_title' ),
            'button_text' => __( 'Letöltés kérése', 'cegem360-revit-library' ),
        ), $atts, 'revit_library_form' );

        $title       = $atts['title'];
        $button_text = $atts['button_text'];
        $intro       = crl_option( 'form_intro' );

        $privacy_page_id = (int) crl_option( 'privacy_page_id', 0 );
        $privacy_url     = $privacy_page_id ? get_permalink( $privacy_page_id ) : '#';
        $gdpr_html       = sprintf( crl_option( 'gdpr_text' ), esc_url( $privacy_url ) );

        ob_start();
        include CRL_PLUGIN_DIR . 'templates/form.php';
        return ob_get_clean();
    }

    public function handle_submit() {
        if ( ! check_ajax_referer( 'crl_submit_form', 'crl_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Biztonsági ellenőrzés sikertelen.', 'cegem360-revit-library' ) ), 400 );
        }

        if ( ! empty( $_POST['crl_website'] ) ) {
            wp_send_json_success( array( 'message' => crl_option( 'form_success_message' ) ) );
        }

        $company = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
        $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $gdpr    = ! empty( $_POST['gdpr'] );

        $errors = array();
        if ( mb_strlen( $company ) < 2 )    $errors['company_name'] = __( 'Adja meg a cégnevet.', 'cegem360-revit-library' );
        if ( ! is_email( $email ) )         $errors['email']        = __( 'Érvénytelen email cím.', 'cegem360-revit-library' );
        if ( ! crl_is_valid_phone( $phone )) $errors['phone']       = __( 'Érvénytelen telefonszám.', 'cegem360-revit-library' );
        if ( ! $gdpr )                      $errors['gdpr']         = __( 'El kell fogadnia az adatvédelmi tájékoztatót.', 'cegem360-revit-library' );

        if ( $errors ) {
            wp_send_json_error( array( 'errors' => $errors ), 422 );
        }

        $ip = crl_get_client_ip();
        if ( ! $this->rate_limiter->allow( $ip, (int) crl_option( 'rate_limit_per_hour', 3 ) ) ) {
            wp_send_json_error( array(
                'message' => __( 'Túl sok kérés érkezett erről a címről. Próbálja újra később.', 'cegem360-revit-library' ),
            ), 429 );
        }

        $submission_id = $this->submissions->create( array(
            'company_name'  => $company,
            'email'         => $email,
            'phone'         => $phone,
            'ip_address'    => $ip,
            'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 1000 ) : '',
            'gdpr_accepted' => true,
        ) );

        if ( ! $submission_id ) {
            wp_send_json_error( array( 'message' => __( 'Adatbázis hiba.', 'cegem360-revit-library' ) ), 500 );
        }

        $days  = (int) crl_option( 'link_validity_days', 7 );
        $token = $this->tokens->create( $submission_id, $days );

        $submission = $this->submissions->get( $submission_id );
        $token_row  = $this->tokens->find_by_token( $token );

        $download_link = add_query_arg( 'crl_download', $token, home_url( '/' ) );

        $sent = $this->mailer->send_visitor_email( $submission, $token_row, $download_link );
        $this->submissions->update_email_status( $submission_id, $sent ? 'sent' : 'failed' );

        $this->mailer->send_admin_notification( $submission );

        wp_send_json_success( array( 'message' => crl_option( 'form_success_message' ) ) );
    }
}
```

- [ ] **Step 3: Manual test**

Activate plugin, visit `/revit-elemtar/`. The form should render. Submit it from the browser console with valid values via `fetch()` OR after Tasks 11–12 add the CSS/JS, test natively. For now: confirm form renders and shortcode outputs HTML.

- [ ] **Step 4: Commit**

```bash
git add cegem360-revit-library/includes/class-form.php cegem360-revit-library/templates/form.php
git commit -m "feat: form shortcode and AJAX submission handler"
```

---

## Task 11: Frontend CSS

**Files:**
- Create: `revit-lib/cegem360-revit-library/assets/css/form.css`

- [ ] **Step 1: Write the CSS**

```css
.crl-form-wrapper { max-width: 520px; margin: 0 auto; font-family: inherit; }
.crl-form-title { font-size: 1.5rem; margin: 0 0 0.5rem; }
.crl-form-intro { margin-bottom: 1.5rem; color: #555; }
.crl-form { display: flex; flex-direction: column; gap: 1rem; }
.crl-field label { display: block; font-weight: 600; margin-bottom: 0.3rem; }
.crl-field input[type=text],
.crl-field input[type=email],
.crl-field input[type=tel] {
    width: 100%;
    box-sizing: border-box;
    padding: 0.6rem 0.8rem;
    font-size: 1rem;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    transition: border-color .15s;
}
.crl-field input:focus { outline: none; border-color: var(--crl-primary-color); box-shadow: 0 0 0 2px color-mix(in srgb, var(--crl-primary-color) 25%, transparent); }
.crl-field.has-error input { border-color: #d63638; }
.crl-error { color: #d63638; font-size: 0.875rem; margin-top: 0.3rem; min-height: 1.1rem; }
.crl-field-checkbox label { display: flex; gap: 0.5rem; font-weight: 400; align-items: flex-start; }
.crl-field-checkbox input { margin-top: 0.25rem; }
.crl-honeypot { position: absolute; left: -9999px; height: 0; overflow: hidden; }
.crl-submit {
    background: var(--crl-primary-color);
    color: #fff;
    border: 0;
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    font-weight: 600;
    border-radius: 4px;
    cursor: pointer;
    transition: opacity .15s;
}
.crl-submit:hover:not(:disabled) { opacity: 0.9; }
.crl-submit:disabled { opacity: 0.6; cursor: wait; }
.crl-form-message { padding: 1rem; border-radius: 4px; }
.crl-form-message.is-success { background: #edfaef; border: 1px solid #00a32a; color: #00450c; }
.crl-form-message.is-error { background: #fcf0f1; border: 1px solid #d63638; color: #5e0e10; }
.crl-form.is-submitted .crl-form { display: none; }
```

- [ ] **Step 2: Commit**

```bash
git add cegem360-revit-library/assets/css/form.css
git commit -m "feat: front-end form CSS with theme variable"
```

---

## Task 12: Frontend JS

**Files:**
- Create: `revit-lib/cegem360-revit-library/assets/js/form.js`

- [ ] **Step 1: Write the JS**

```javascript
(function() {
    'use strict';

    function findForms() {
        return document.querySelectorAll('.crl-form-wrapper');
    }

    function clearErrors(form) {
        form.querySelectorAll('.crl-error').forEach(function(el) { el.textContent = ''; });
        form.querySelectorAll('.crl-field').forEach(function(el) { el.classList.remove('has-error'); });
    }

    function showFieldError(form, field, message) {
        var errorEl = form.querySelector('.crl-error[data-field="' + field + '"]');
        if (errorEl) errorEl.textContent = message;
        var input = form.querySelector('[name="' + field + '"]');
        if (input) input.closest('.crl-field').classList.add('has-error');
    }

    function showMessage(wrapper, message, type) {
        var box = wrapper.querySelector('.crl-form-message');
        if (!box) return;
        box.textContent = message;
        box.className = 'crl-form-message is-' + type;
    }

    function handleSubmit(e) {
        e.preventDefault();
        var form = e.target;
        var wrapper = form.closest('.crl-form-wrapper');
        var btn = form.querySelector('.crl-submit');
        var originalText = btn.textContent;

        clearErrors(form);
        btn.disabled = true;
        btn.textContent = CRL_FORM.messages.sending;

        var data = new FormData(form);
        data.append('action', 'crl_submit_form');

        fetch(CRL_FORM.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, json: j }; }); })
            .then(function(result) {
                if (result.ok && result.json.success) {
                    showMessage(wrapper, result.json.data.message, 'success');
                    form.style.display = 'none';
                } else {
                    var payload = result.json.data || {};
                    if (payload.errors) {
                        Object.keys(payload.errors).forEach(function(field) {
                            showFieldError(form, field, payload.errors[field]);
                        });
                    } else if (payload.message) {
                        showMessage(wrapper, payload.message, 'error');
                    } else {
                        showMessage(wrapper, CRL_FORM.messages.generic_error, 'error');
                    }
                }
            })
            .catch(function() { showMessage(wrapper, CRL_FORM.messages.generic_error, 'error'); })
            .finally(function() { btn.disabled = false; btn.textContent = originalText; });
    }

    function init() {
        findForms().forEach(function(wrapper) {
            var form = wrapper.querySelector('.crl-form');
            if (form && !form.dataset.crlBound) {
                form.addEventListener('submit', handleSubmit);
                form.dataset.crlBound = '1';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

- [ ] **Step 2: Manual test**

Activate plugin, visit `/revit-elemtar/`, submit the form with valid data. Confirm:
- A row appears in `wp_crl_submissions`
- A row appears in `wp_crl_tokens`
- Email arrives at the visitor address (check spam) and notification address
- Success message replaces the form

- [ ] **Step 3: Commit**

```bash
git add cegem360-revit-library/assets/js/form.js
git commit -m "feat: front-end form AJAX submit with error rendering"
```

---

## Task 13: Download handler

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-download-handler.php`

- [ ] **Step 1: Implement**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_Download_Handler {

    private $tokens;
    private $zip_manager;

    public function __construct( CRL_Tokens $tokens, CRL_Zip_Manager $zip_manager ) {
        $this->tokens      = $tokens;
        $this->zip_manager = $zip_manager;
    }

    public function register_hooks() {
        add_action( 'init', array( $this, 'maybe_handle_download' ) );
    }

    public function maybe_handle_download() {
        if ( empty( $_GET['crl_download'] ) ) {
            return;
        }
        $token_param = sanitize_text_field( wp_unslash( $_GET['crl_download'] ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token_param ) ) {
            $this->die_with( 404, __( 'A link érvénytelen.', 'cegem360-revit-library' ) );
        }

        $token = $this->tokens->find_by_token( $token_param );
        if ( ! $token ) {
            $this->die_with( 404, __( 'A link érvénytelen vagy lejárt.', 'cegem360-revit-library' ) );
        }
        if ( CRL_Tokens::is_expired( $token->expires_at ) ) {
            $this->die_with( 410, __( 'A link lejárt. Kérjük, igényeljen újat.', 'cegem360-revit-library' ) );
        }

        $zip = crl_zip_path();
        if ( ! file_exists( $zip ) || filesize( $zip ) === 0 ) {
            $this->die_with( 500, __( 'A fájl jelenleg nem érhető el. Kérjük, vegye fel a kapcsolatot velünk.', 'cegem360-revit-library' ) );
        }

        $this->tokens->record_download( $token->id );
        $this->stream_zip( $zip );
    }

    private function stream_zip( $path ) {
        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="revit-elemtar.zip"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );

        $fp = fopen( $path, 'rb' );
        if ( $fp === false ) {
            $this->die_with( 500, __( 'A fájl olvasása sikertelen.', 'cegem360-revit-library' ) );
        }
        while ( ! feof( $fp ) ) {
            echo fread( $fp, 1024 * 1024 );
            flush();
        }
        fclose( $fp );
        exit;
    }

    private function die_with( $status, $message ) {
        status_header( $status );
        wp_die( esc_html( $message ), esc_html__( 'Letöltés', 'cegem360-revit-library' ), array( 'response' => $status ) );
    }
}
```

- [ ] **Step 2: Manual test**

After Task 12, take the link from the visitor email. Open in browser. Verify:
- ZIP downloads
- `download_count` increments in `wp_crl_tokens`
- Tampered token (modify a character) shows error page
- Visiting with a freshly-expired token (manually update DB `expires_at`) shows lejárt error

- [ ] **Step 3: Commit**

```bash
git add cegem360-revit-library/includes/class-download-handler.php
git commit -m "feat: token-validated download handler with chunked streaming"
```

---

## Task 14: Admin class — menu and asset enqueue

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-admin.php`
- Create: `revit-lib/cegem360-revit-library/assets/css/admin.css`
- Create: `revit-lib/cegem360-revit-library/assets/js/admin.js`

- [ ] **Step 1: Implement `class-admin.php`**

```php
<?php
defined( 'ABSPATH' ) || exit;

class CRL_Admin {

    private $plugin;

    public function __construct( CRL_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_crl_upload_file',      array( $this, 'ajax_upload_file' ) );
        add_action( 'wp_ajax_crl_delete_file',      array( $this, 'ajax_delete_file' ) );
        add_action( 'wp_ajax_crl_regenerate_zip',   array( $this, 'ajax_regenerate_zip' ) );
        add_action( 'wp_ajax_crl_test_email',       array( $this, 'ajax_test_email' ) );
        add_action( 'wp_ajax_crl_resend_email',     array( $this, 'ajax_resend_email' ) );
        add_action( 'wp_ajax_crl_renew_token',      array( $this, 'ajax_renew_token' ) );
        add_action( 'admin_post_crl_export_csv',    array( $this, 'export_csv' ) );
        add_action( 'admin_post_crl_delete_submission', array( $this, 'delete_submission' ) );
    }

    public function register_menu() {
        $cap = 'manage_options';
        add_menu_page(
            __( 'Revit elemtár', 'cegem360-revit-library' ),
            __( 'Revit elemtár', 'cegem360-revit-library' ),
            $cap,
            'crl-submissions',
            array( $this, 'render_submissions_page' ),
            'dashicons-download',
            30
        );
        add_submenu_page( 'crl-submissions', __( 'Beküldések', 'cegem360-revit-library' ), __( 'Beküldések', 'cegem360-revit-library' ), $cap, 'crl-submissions', array( $this, 'render_submissions_page' ) );
        add_submenu_page( 'crl-submissions', __( 'Fájlkezelő', 'cegem360-revit-library' ), __( 'Fájlkezelő', 'cegem360-revit-library' ), $cap, 'crl-files', array( $this, 'render_files_page' ) );
        add_submenu_page( 'crl-submissions', __( 'Beállítások', 'cegem360-revit-library' ), __( 'Beállítások', 'cegem360-revit-library' ), $cap, 'crl-settings', array( $this->plugin->settings, 'render_page' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'crl-' ) === false ) return;
        wp_enqueue_style( 'crl-admin', CRL_PLUGIN_URL . 'assets/css/admin.css', array(), CRL_VERSION );
        wp_enqueue_script( 'crl-admin', CRL_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), CRL_VERSION, true );
        wp_localize_script( 'crl-admin', 'CRL_ADMIN', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'crl_admin_action' ),
            'i18n'    => array(
                'confirm_delete'    => __( 'Biztosan törli ezt az elemet?', 'cegem360-revit-library' ),
                'uploading'         => __( 'Feltöltés…', 'cegem360-revit-library' ),
                'regenerating'      => __( 'ZIP regenerálása…', 'cegem360-revit-library' ),
                'done'              => __( 'Kész.', 'cegem360-revit-library' ),
                'error'             => __( 'Hiba történt.', 'cegem360-revit-library' ),
            ),
        ) );
    }

    public function render_submissions_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $table = new CRL_Submissions_List_Table( $this->plugin->submissions );
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Beküldések', 'cegem360-revit-library' ); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="crl-submissions">
                <?php
                $table->search_box( __( 'Keresés', 'cegem360-revit-library' ), 'crl-search' );
                $table->extra_filters();
                $table->display();
                ?>
            </form>
            <p>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=crl_export_csv' ), 'crl_export_csv' ) ); ?>">
                    <?php esc_html_e( 'CSV export', 'cegem360-revit-library' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function render_files_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $info  = $this->plugin->zip_manager->get_info();
        $files = $this->plugin->zip_manager->list_source_files();
        $allowed = implode( ', ', $this->plugin->file_manager->allowed_extensions() );
        $max_upload = size_format( wp_max_upload_size() );
        include CRL_PLUGIN_DIR . 'includes/views/files-page.php';
    }

    /* ----- AJAX handlers ----- */

    private function check_admin_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
        check_ajax_referer( 'crl_admin_action', 'nonce' );
    }

    public function ajax_upload_file() {
        $this->check_admin_ajax();
        $file = isset( $_FILES['file'] ) ? $_FILES['file'] : array();
        $result = $this->plugin->file_manager->handle_upload( $file );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( array( 'filename' => $result, 'info' => $this->plugin->zip_manager->get_info() ) );
    }

    public function ajax_delete_file() {
        $this->check_admin_ajax();
        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
        $result = $this->plugin->file_manager->delete( $filename );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( array( 'info' => $this->plugin->zip_manager->get_info() ) );
    }

    public function ajax_regenerate_zip() {
        $this->check_admin_ajax();
        $ok = $this->plugin->zip_manager->regenerate();
        if ( ! $ok ) wp_send_json_error( array( 'message' => __( 'A ZIP regenerálása sikertelen.', 'cegem360-revit-library' ) ), 500 );
        wp_send_json_success( array( 'info' => $this->plugin->zip_manager->get_info() ) );
    }

    public function ajax_test_email() {
        $this->check_admin_ajax();
        $to = crl_option( 'notification_email', get_option( 'admin_email' ) );
        $ok = $this->plugin->mailer->send_test( $to );
        if ( ! $ok ) wp_send_json_error( array( 'message' => __( 'Teszt email küldése sikertelen.', 'cegem360-revit-library' ) ), 500 );
        wp_send_json_success( array( 'message' => sprintf( __( 'Teszt email elküldve: %s', 'cegem360-revit-library' ), $to ) ) );
    }

    public function ajax_resend_email() {
        $this->check_admin_ajax();
        $sid = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
        $submission = $this->plugin->submissions->get( $sid );
        if ( ! $submission ) wp_send_json_error( array( 'message' => 'not found' ), 404 );

        $tokens = $this->plugin->tokens->find_by_submission( $sid );
        $token  = $tokens ? $tokens[0] : null;
        if ( ! $token || CRL_Tokens::is_expired( $token->expires_at ) ) {
            $new_token = $this->plugin->tokens->create( $sid, (int) crl_option( 'link_validity_days', 7 ) );
            $token = $this->plugin->tokens->find_by_token( $new_token );
        }

        $link = add_query_arg( 'crl_download', $token->token, home_url( '/' ) );
        $ok   = $this->plugin->mailer->send_visitor_email( $submission, $token, $link );
        $this->plugin->submissions->update_email_status( $sid, $ok ? 'sent' : 'failed' );
        wp_send_json_success( array( 'status' => $ok ? 'sent' : 'failed' ) );
    }

    public function ajax_renew_token() {
        $this->check_admin_ajax();
        $sid = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
        $submission = $this->plugin->submissions->get( $sid );
        if ( ! $submission ) wp_send_json_error( array( 'message' => 'not found' ), 404 );

        $new_token = $this->plugin->tokens->create( $sid, (int) crl_option( 'link_validity_days', 7 ) );
        $token     = $this->plugin->tokens->find_by_token( $new_token );
        $link      = add_query_arg( 'crl_download', $new_token, home_url( '/' ) );
        $ok        = $this->plugin->mailer->send_visitor_email( $submission, $token, $link );
        $this->plugin->submissions->update_email_status( $sid, $ok ? 'sent' : 'failed' );
        wp_send_json_success();
    }

    public function delete_submission() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'crl_delete_submission' );
        $sid = isset( $_GET['submission_id'] ) ? (int) $_GET['submission_id'] : 0;
        if ( $sid ) $this->plugin->submissions->delete( $sid );
        wp_safe_redirect( admin_url( 'admin.php?page=crl-submissions&deleted=1' ) );
        exit;
    }

    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'crl_export_csv' );
        $rows = $this->plugin->submissions->query( array( 'per_page' => 99999, 'page' => 1 ) );
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="crl-submissions-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputs( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( 'ID', 'Cégnév', 'Email', 'Telefon', 'Beküldve (UTC)', 'Email státusz' ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array( $r->id, $r->company_name, $r->email, $r->phone, $r->created_at, $r->email_status ) );
        }
        fclose( $out );
        exit;
    }
}
```

- [ ] **Step 2: Create `includes/views/files-page.php` (the file manager UI)**

```php
<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap crl-files-page">
    <h1><?php esc_html_e( 'Fájlkezelő', 'cegem360-revit-library' ); ?></h1>

    <div class="crl-zip-info">
        <h2><?php esc_html_e( 'Letölthető ZIP', 'cegem360-revit-library' ); ?></h2>
        <ul>
            <li><?php esc_html_e( 'Legutóbb generálva:', 'cegem360-revit-library' ); ?>
                <strong><?php echo $info['generated_at'] ? esc_html( wp_date( 'Y-m-d H:i', strtotime( $info['generated_at'] . ' UTC' ) ) ) : esc_html__( 'Még nem készült', 'cegem360-revit-library' ); ?></strong></li>
            <li><?php esc_html_e( 'Méret:', 'cegem360-revit-library' ); ?> <strong><?php echo esc_html( crl_format_bytes( $info['size'] ) ); ?></strong></li>
            <li><?php esc_html_e( 'Fájlok száma:', 'cegem360-revit-library' ); ?> <strong><?php echo (int) $info['file_count']; ?></strong></li>
        </ul>
        <p>
            <button class="button" id="crl-regenerate-zip"><?php esc_html_e( 'ZIP regenerálása most', 'cegem360-revit-library' ); ?></button>
        </p>
    </div>

    <div class="crl-upload">
        <h2><?php esc_html_e( 'Fájl feltöltése', 'cegem360-revit-library' ); ?></h2>
        <p>
            <?php printf( esc_html__( 'Engedélyezett típusok: %s', 'cegem360-revit-library' ), esc_html( $allowed ) ); ?><br>
            <?php printf( esc_html__( 'Max. fájlméret: %s', 'cegem360-revit-library' ), esc_html( $max_upload ) ); ?>
        </p>
        <input type="file" id="crl-file-input" multiple>
        <div id="crl-upload-status"></div>
    </div>

    <h2><?php esc_html_e( 'Jelenlegi fájlok', 'cegem360-revit-library' ); ?></h2>
    <table class="wp-list-table widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'Név', 'cegem360-revit-library' ); ?></th>
            <th><?php esc_html_e( 'Méret', 'cegem360-revit-library' ); ?></th>
            <th><?php esc_html_e( 'Módosítva', 'cegem360-revit-library' ); ?></th>
            <th><?php esc_html_e( 'Műveletek', 'cegem360-revit-library' ); ?></th>
        </tr></thead>
        <tbody id="crl-file-list">
        <?php if ( $files ) : foreach ( $files as $f ) : ?>
            <tr data-filename="<?php echo esc_attr( $f['name'] ); ?>">
                <td><?php echo esc_html( $f['name'] ); ?></td>
                <td><?php echo esc_html( crl_format_bytes( $f['size'] ) ); ?></td>
                <td><?php echo esc_html( wp_date( 'Y-m-d H:i', $f['modified'] ) ); ?></td>
                <td><button class="button-link-delete crl-delete-file" type="button"><?php esc_html_e( 'Törlés', 'cegem360-revit-library' ); ?></button></td>
            </tr>
        <?php endforeach; else : ?>
            <tr><td colspan="4"><?php esc_html_e( 'Még nincs feltöltött fájl.', 'cegem360-revit-library' ); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
```

- [ ] **Step 3: Implement `assets/js/admin.js`**

```javascript
(function($) {
    'use strict';

    function ajax(action, data) {
        var payload = Object.assign({ action: action, nonce: CRL_ADMIN.nonce }, data || {});
        return $.post(CRL_ADMIN.ajaxUrl, payload);
    }

    $(document).on('click', '#crl-regenerate-zip', function(e) {
        e.preventDefault();
        var $btn = $(this).prop('disabled', true).text(CRL_ADMIN.i18n.regenerating);
        ajax('crl_regenerate_zip').done(function(){ location.reload(); }).fail(function(){ alert(CRL_ADMIN.i18n.error); $btn.prop('disabled', false); });
    });

    $(document).on('change', '#crl-file-input', function() {
        var files = this.files; if (!files.length) return;
        var $status = $('#crl-upload-status').text(CRL_ADMIN.i18n.uploading);
        var queue = Array.from(files);
        function next() {
            if (!queue.length) { location.reload(); return; }
            var file = queue.shift();
            var fd = new FormData();
            fd.append('action', 'crl_upload_file'); fd.append('nonce', CRL_ADMIN.nonce); fd.append('file', file);
            $.ajax({ url: CRL_ADMIN.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
                .done(function(){ next(); })
                .fail(function(xhr){ $status.text((xhr.responseJSON && xhr.responseJSON.data.message) || CRL_ADMIN.i18n.error); });
        }
        next();
    });

    $(document).on('click', '.crl-delete-file', function(e) {
        e.preventDefault();
        if (!confirm(CRL_ADMIN.i18n.confirm_delete)) return;
        var $row = $(this).closest('tr');
        ajax('crl_delete_file', { filename: $row.data('filename') })
            .done(function(){ $row.remove(); })
            .fail(function(){ alert(CRL_ADMIN.i18n.error); });
    });

    $(document).on('click', '.crl-renew-token', function(e) {
        e.preventDefault();
        var sid = $(this).data('submission'); if (!sid) return;
        ajax('crl_renew_token', { submission_id: sid }).done(function(){ location.reload(); }).fail(function(){ alert(CRL_ADMIN.i18n.error); });
    });

    $(document).on('click', '.crl-resend-email', function(e) {
        e.preventDefault();
        var sid = $(this).data('submission'); if (!sid) return;
        ajax('crl_resend_email', { submission_id: sid }).done(function(){ location.reload(); }).fail(function(){ alert(CRL_ADMIN.i18n.error); });
    });

    $(document).on('click', '#crl-test-email', function(e) {
        e.preventDefault();
        var $btn = $(this).prop('disabled', true);
        ajax('crl_test_email').done(function(r){ alert(r.data.message); }).fail(function(){ alert(CRL_ADMIN.i18n.error); }).always(function(){ $btn.prop('disabled', false); });
    });
})(jQuery);
```

- [ ] **Step 4: Create `assets/css/admin.css`**

```css
.crl-zip-info, .crl-upload { background:#fff; border:1px solid #ccd0d4; padding:1rem 1.25rem; margin:1rem 0; }
.crl-zip-info h2, .crl-upload h2 { margin-top: 0; }
.crl-zip-info ul { list-style:none; padding:0; margin:0 0 0.5rem; }
.crl-zip-info li { padding: 0.25rem 0; }
#crl-upload-status { margin-top: 0.5rem; color:#555; }
.crl-diagnostics { background:#f6f7f7; border:1px solid #ccd0d4; padding:1rem 1.25rem; margin: 1.5rem 0; }
.crl-diagnostics .ok { color: #00a32a; font-weight:bold; }
.crl-diagnostics .fail { color: #d63638; font-weight:bold; }
```

- [ ] **Step 5: Commit**

```bash
git add cegem360-revit-library/includes/class-admin.php cegem360-revit-library/includes/views/ cegem360-revit-library/assets/css/admin.css cegem360-revit-library/assets/js/admin.js
git commit -m "feat: admin menu, file manager UI, AJAX endpoints, asset enqueue"
```

---

## Task 15: Settings page

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-settings.php`

- [ ] **Step 1: Implement using WP Settings API**

```php
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
```

- [ ] **Step 2: Manual test**

Visit Beállítások → all fields render. Change a value → save → reload → value persists. Click „Teszt email küldése" → alert with success.

- [ ] **Step 3: Commit**

```bash
git add cegem360-revit-library/includes/class-settings.php
git commit -m "feat: settings page with WP Settings API + diagnostics"
```

---

## Task 16: Submissions list table

**Files:**
- Create: `revit-lib/cegem360-revit-library/includes/class-submissions-list-table.php`

- [ ] **Step 1: Implement**

```php
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
```

- [ ] **Step 2: Manual test**

Submit 3 entries via the form. Visit Beküldések → table shows 3 rows. Sort by email → works. Filter by date / status → works. Search by part of cégnév → works. Click „Token megújítás" → email arrives, new row in `wp_crl_tokens`. Click „Törlés" → row removed. CSV export → file with all rows downloads.

- [ ] **Step 3: Commit**

```bash
git add cegem360-revit-library/includes/class-submissions-list-table.php
git commit -m "feat: submissions list table with filtering, sorting, row actions"
```

---

## Task 17: Uninstall script

**Files:**
- Create: `revit-lib/cegem360-revit-library/uninstall.php`

- [ ] **Step 1: Implement**

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add cegem360-revit-library/uninstall.php
git commit -m "feat: uninstall script (opt-in, gated by settings)"
```

---

## Task 18: i18n — generate POT and Hungarian PO

**Files:**
- Create: `revit-lib/cegem360-revit-library/languages/cegem360-revit-library.pot`
- Create: `revit-lib/cegem360-revit-library/languages/cegem360-revit-library-hu_HU.po`
- Create: `revit-lib/cegem360-revit-library/languages/cegem360-revit-library-hu_HU.mo` (generated)

- [ ] **Step 1: Generate POT via wp-cli (if available) or manually**

```bash
cd /Users/szabozoltan/Downloads/revit-lib/cegem360-revit-library
wp i18n make-pot . languages/cegem360-revit-library.pot --domain=cegem360-revit-library
```

If wp-cli is not installed, skip POT generation and create only the PO/MO manually using Poedit.

- [ ] **Step 2: Create Hungarian PO copying POT and translating each msgstr**

Open the POT in Poedit, save as `cegem360-revit-library-hu_HU.po`, set Language=Hungarian, then translate each visible string. Hungarian source strings (e.g. "Cégnév", "Beküldések") can have `msgstr` identical to `msgid`. Save → Poedit auto-generates the `.mo`.

- [ ] **Step 3: Manual test**

In WordPress, set Site Language → Magyar. Visit `/revit-elemtar/` and admin → all visible strings appear in Hungarian.

- [ ] **Step 4: Commit**

```bash
git add cegem360-revit-library/languages/
git commit -m "feat: Hungarian translation files"
```

---

## Task 19: Manual end-to-end integration test

**Files:** none (verification only)

- [ ] **Step 1: Run the full check-list against a fresh WP install**

| # | Step | Expected |
|---|---|---|
| 1 | Activate plugin | Two DB tables exist; `/wp-content/uploads/crl-private/` created; landing page at `/revit-elemtar/`; default options seeded |
| 2 | Upload 3 sample files in Fájlkezelő | Files listed, ZIP regenerated, `wp_crl_*` options updated |
| 3 | Visit `/revit-elemtar/` | Form renders with all fields, GDPR checkbox links to set privacy page |
| 4 | Submit empty form | Field-level errors appear |
| 5 | Submit with invalid email | Email error only |
| 6 | Submit with no GDPR | GDPR error only |
| 7 | Submit valid data | Success message replaces form; visitor + admin emails arrive |
| 8 | Click visitor email link | ZIP downloads; `download_count = 1` |
| 9 | Click visitor email link again | ZIP downloads; `download_count = 2` |
| 10 | Modify token in DB → set `expires_at` to past | Link shows "lejárt" error page |
| 11 | Modify URL token to invalid hex | Shows "érvénytelen" error page |
| 12 | Submit 4× from same IP (limit=3) | Fourth submission shows rate-limit error |
| 13 | Submit with honeypot filled (devtools) | Returns 200 success but no DB row created |
| 14 | Admin → Beküldések → click "Token megújítás" | New row in `wp_crl_tokens`; new email arrives |
| 15 | Admin → Beküldések → click "Email újraküldés" on a failed row | Status updates accordingly |
| 16 | Admin → Beküldések → CSV export | File downloads, UTF-8 BOM present, Hungarian characters intact |
| 17 | Admin → Beküldések → bulk delete via row "Törlés" | Both submission and its tokens removed |
| 18 | Settings → change primary color → save | Form on `/revit-elemtar/` reflects new color |
| 19 | Settings → click "Teszt email küldése" | Test email arrives at notification address |
| 20 | Try `wp-content/uploads/crl-private/source/file.rfa` URL direct (Apache) | 403 from .htaccess |
| 21 | Deactivate plugin | Data preserved |
| 22 | Settings → "Adatok törlése eltávolításkor" ON → uninstall | All `crl_*` options gone, tables dropped, files removed |

- [ ] **Step 2: Document remaining issues / fix any failures**

If any test fails, fix and re-run the relevant step. Don't proceed until #1–#22 all green.

- [ ] **Step 3: Commit (only if any fixes made)**

```bash
git add -A
git commit -m "fix: integration test issues from end-to-end checklist"
```

---

## Self-Review

**1. Spec coverage:** Cross-checking the spec against tasks:

| Spec section | Implemented in |
|---|---|
| §2 File structure | Task 1, 2, 3 |
| §3 DB schema | Task 2 |
| §4 File storage / ZIP | Task 7, 8 |
| §5 Form + shortcode | Task 10, 11, 12 |
| §6 Email + token | Task 4, 9, 13 |
| §7 Admin UI (Beküldések) | Task 14, 16 |
| §7 Admin UI (Fájlkezelő) | Task 14 |
| §7 Admin UI (Beállítások) | Task 15 |
| §8 Security | embedded across Tasks 4, 7, 8, 10, 13, 14 |
| §9 i18n | Task 18 |
| §10 Testing | Tasks 4, 5, 7 (unit) + Task 19 (manual) |
| §11 YAGNI items | excluded (no tasks for them) |

All spec sections covered.

**2. Placeholder scan:** No TBD / TODO / "fill in later" lines. Each code step has full code.

**3. Type consistency:** `CRL_Plugin`, `CRL_Tokens::generate()`, `CRL_Submissions::query()`, `CRL_Zip_Manager::regenerate()`, `CRL_Mailer::send_visitor_email()` — all referenced consistently across tasks.

**4. Open questions from spec:** §13 listed 5 open questions. None block implementation but the engineer should ask the customer before deployment:
1. Library size (impacts download streaming choice)
2. Exact email / form copy
3. Privacy policy URL
4. WP/PHP versions on target
5. Apache vs Nginx (extra `.htaccess` only works on Apache; Nginx needs a location block — document in the README during Task 1 Step 3, or as a follow-up)

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-19-revit-library-wp-plugin.md`.

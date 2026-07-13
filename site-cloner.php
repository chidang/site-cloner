<?php
/**
 * Plugin Name: Site Cloner
 * Description: Creates a package (files + database + installer) to migrate WordPress from production to staging. Runs anywhere, no shell required.
 * Version:     1.0.0
 * Author:      flexatech
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-cloner
 * Domain Path: /languages
 */

namespace Flexa\SiteCloner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FLEXA_VERSION', '1.0.0' );
define( 'FLEXA_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLEXA_URL', plugin_dir_url( __FILE__ ) );

// Package storage directory: <uploads>/sd-packages (resolved via wp_upload_dir()).
$sd_uploads = wp_upload_dir();
define( 'FLEXA_PACKAGE_DIR', $sd_uploads['basedir'] . '/sd-packages' );
define( 'FLEXA_PACKAGE_URL', $sd_uploads['baseurl'] . '/sd-packages' );
unset( $sd_uploads );

require_once FLEXA_PATH . 'includes/class-sd-database.php';
require_once FLEXA_PATH . 'includes/class-sd-archive.php';
require_once FLEXA_PATH . 'includes/class-sd-package.php';
require_once FLEXA_PATH . 'includes/class-sd-replace.php';
require_once FLEXA_PATH . 'includes/class-sd-importer.php';
require_once FLEXA_PATH . 'includes/class-sd-pull.php';
require_once FLEXA_PATH . 'includes/class-sd-health.php';

class Plugin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		// Build steps run via AJAX (split into chunks to avoid timeouts).
		add_action( 'wp_ajax_sd_build_init',     array( $this, 'ajax_init' ) );
		add_action( 'wp_ajax_sd_build_database', array( $this, 'ajax_database' ) );
		add_action( 'wp_ajax_sd_build_files',    array( $this, 'ajax_files' ) );
		add_action( 'wp_ajax_sd_build_finalize', array( $this, 'ajax_finalize' ) );

		// Import on the staging side.
		add_action( 'wp_ajax_sd_import_prepare', array( $this, 'ajax_import_prepare' ) );
		add_action( 'wp_ajax_sd_import_extract', array( $this, 'ajax_import_extract' ) );
		add_action( 'wp_ajax_sd_import_deploy',  array( $this, 'ajax_import_deploy' ) );

		// Pull-by-link.
		add_action( 'init', array( Pull::class, 'handle' ) ); // production serves the files
		add_action( 'wp_ajax_sd_pull_info',     array( $this, 'ajax_pull_info' ) );
		add_action( 'wp_ajax_sd_pull_download', array( $this, 'ajax_pull_download' ) );
		add_action( 'wp_ajax_sd_pull_test',     array( $this, 'ajax_pull_test' ) );
		add_action( 'wp_ajax_sd_pull_cleanup',  array( $this, 'ajax_pull_cleanup' ) );
		add_action( 'wp_ajax_sd_pull_uninstall', array( $this, 'ajax_pull_uninstall' ) );
	}

	public function menu() {
		add_management_page(
			__( 'Site Cloner', 'site-cloner' ),
			__( 'Site Cloner', 'site-cloner' ),
			'manage_options',
			'site-cloner',
			array( $this, 'render_page' )
		);
		add_management_page(
			__( 'Site Cloner – Import', 'site-cloner' ),
			__( 'Site Cloner Import', 'site-cloner' ),
			'manage_options',
			'sd-import',
			array( $this, 'render_import_page' )
		);
	}

	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'tools_page_site-cloner', 'tools_page_sd-import' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'sd-admin', FLEXA_URL . 'assets/admin.css', array(), FLEXA_VERSION );
		wp_enqueue_script( 'sd-admin', FLEXA_URL . 'assets/admin.js', array( 'jquery', 'wp-i18n' ), FLEXA_VERSION, true );
		wp_set_script_translations( 'sd-admin', 'site-cloner', FLEXA_PATH . 'languages' );
		wp_localize_script( 'sd-admin', 'SD', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'sd_build' ),
		) );
	}

	public function render_page() {
		require FLEXA_PATH . 'templates/admin-page.php';
	}

	public function render_import_page() {
		$packages = Importer::list_packages();
		require FLEXA_PATH . 'templates/import-page.php';
	}

	/** ----- AJAX handlers ----- */

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'sd_build', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'site-cloner' ) ), 403 );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every AJAX handler below calls $this->guard() first, which runs check_ajax_referer( 'sd_build', 'nonce' ) and current_user_can( 'manage_options' ).

	/** Step 1: initialize the package, scan tables and the file list. */
	public function ajax_init() {
		$this->guard();
		try {
			$pkg   = new Package();
			$state = $pkg->init();
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Step 2: export the database in chunks. */
	public function ajax_database() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$pkg   = Package::load( $id );
			$state = $pkg->step_database();
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Step 3: compress files in chunks. */
	public function ajax_files() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$pkg   = Package::load( $id );
			$state = $pkg->step_files();
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Step 4: attach installer + manifest, finalize. */
	public function ajax_finalize() {
		$this->guard();
		$id  = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		$pwd = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Package protection password stored as a password_hash(); sanitizing would corrupt valid passwords.

		$allow = array();
		$raw   = sanitize_text_field( wp_unslash( $_POST['allow_ips'] ?? '' ) );
		if ( '' !== trim( $raw ) ) {
			foreach ( preg_split( '/[\s,]+/', $raw ) as $ip ) {
				$ip = trim( $ip );
				if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					$allow[] = $ip;
				}
			}
		}
		try {
			$pkg   = Package::load( $id );
			$state = $pkg->finalize( $pwd, $allow );
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** ----- Import (staging) ----- */

	public function ajax_import_prepare() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$imp = new Importer( $id );
			wp_send_json_success( $imp->prepare() );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_import_extract() {
		$this->guard();
		$id     = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		$part   = sanitize_text_field( wp_unslash( $_POST['part'] ?? '' ) );
		$offset = absint( wp_unslash( $_POST['offset'] ?? 0 ) );
		try {
			$imp = new Importer( $id );
			wp_send_json_success( $imp->extract( $part, $offset ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_import_deploy() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$imp = new Importer( $id );
			wp_send_json_success( $imp->deploy_database() );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** ----- Pull-by-link (staging pulls from production) ----- */

	public function ajax_pull_info() {
		$this->guard();
		$link   = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) );
		$verify = empty( $_POST['insecure'] );
		$pwd    = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		try {
			wp_send_json_success( Pull::info( $link, $verify, $pwd ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_pull_test() {
		$this->guard();
		$link = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) );
		$pwd  = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		try {
			wp_send_json_success( Pull::test( $link, $pwd ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_pull_cleanup() {
		$this->guard();
		$link   = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) );
		$verify = empty( $_POST['insecure'] );
		$pwd    = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		wp_send_json_success( array( 'cleaned' => Pull::cleanup( $link, $verify, $pwd ) ) );
	}

	public function ajax_pull_uninstall() {
		$this->guard();
		$link   = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) );
		$verify = empty( $_POST['insecure'] );
		$pwd    = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		wp_send_json_success( Pull::uninstall_remote( $link, $verify, $pwd ) );
	}

	public function ajax_pull_download() {
		$this->guard();
		$link   = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) );
		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$offset = absint( wp_unslash( $_POST['offset'] ?? 0 ) );
		$total  = absint( wp_unslash( $_POST['total'] ?? 0 ) );
		$verify = empty( $_POST['insecure'] );
		$pwd    = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		try {
			wp_send_json_success( Pull::download( $link, $name, $offset, $total, $verify, $pwd ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

new Plugin();

// Protect the package directory with .htaccess + index on activation.
register_activation_hook( __FILE__, function () {
	if ( ! file_exists( FLEXA_PACKAGE_DIR ) ) {
		wp_mkdir_p( FLEXA_PACKAGE_DIR );
	}
	file_put_contents( FLEXA_PACKAGE_DIR . '/index.php', "<?php // Silence is golden." );
} );

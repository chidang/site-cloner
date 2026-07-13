<?php
namespace Flexa\SiteCloner;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Orchestrates the package build process.
 * State is stored in state.json inside the package's working directory.
 */
class Package {

	private $id;
	private $dir;        // working directory: sd-packages/<id>
	private $state;

	public function __construct( $id = null ) {
		$this->id  = $id ?: gmdate( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$this->dir = FLEXA_PACKAGE_DIR . '/' . $this->id;
	}

	public static function load( $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) ) {
			throw new \Exception( esc_html__( 'Invalid package ID.', 'site-cloner' ) );
		}
		$pkg = new self( $id );
		$pkg->read_state();
		return $pkg;
	}

	private function state_file() { return $this->dir . '/state.json'; }
	private function sql_file()   { return $this->dir . '/database.sql'; }
	private function list_file()  { return $this->dir . '/.filelist'; }

	private function read_state() {
		$raw = @file_get_contents( $this->state_file() );
		if ( false === $raw ) {
			throw new \Exception( esc_html__( 'Package not found.', 'site-cloner' ) );
		}
		$this->state = json_decode( $raw, true );
	}

	private function save_state() {
		file_put_contents( $this->state_file(), wp_json_encode( $this->state ) );
	}

	/** Step 1: initialize. */
	public function init() {
		wp_mkdir_p( $this->dir );
		file_put_contents( $this->dir . '/index.php', '<?php // Silence is golden.' );

		$db     = new Database( $this->sql_file() );
		$tables = $db->tables();

		$archive    = new Archive( $this->dir, $this->list_file() );
		$file_count = $archive->build_filelist();

		$this->state = array(
			'id'          => $this->id,
			'site_url'    => get_site_url(),
			'home_url'    => get_home_url(),
			'abspath'     => str_replace( '\\', '/', ABSPATH ),
			'prefix'      => $GLOBALS['wpdb']->prefix,
			'tables'      => $tables,
			'file_total'  => $file_count,
			'archive'     => array( 'offset' => 0, 'part' => 1, 'part_bytes' => 0, 'parts' => array() ),
			'db'          => array( 'started' => false, 't' => 0, 'o' => 0, 'done' => false ),
			'created'     => gmdate( 'c' ),
		);
		$this->save_state();

		return array(
			'package'    => $this->id,
			'tables'     => count( $tables ),
			'file_total' => $file_count,
		);
	}

	/** Step 2: export the DB (one chunk per call). */
	public function step_database() {
		$db = new Database( $this->sql_file() );

		// On the first run, try mysqldump for speed.
		if ( empty( $this->state['db']['started'] ) && empty( $this->state['db']['tried_dump'] ) ) {
			$this->state['db']['tried_dump'] = true;
			if ( $db->try_mysqldump() ) {
				$this->state['db']['started'] = true;
				$this->state['db']['done']    = true;
				$this->save_state();
				return array( 'done' => true, 'progress' => 100, 'method' => 'mysqldump' );
			}
		}

		$res = $db->export_chunk( $this->state['tables'], $this->state['db'] );
		$this->state['db']         = $res['state'];
		$this->state['db']['done'] = $res['done'];
		$this->save_state();

		$total    = max( 1, count( $this->state['tables'] ) );
		$progress = min( 99, round( ( $res['state']['t'] / $total ) * 100 ) );

		return array(
			'done'     => $res['done'],
			'progress' => $res['done'] ? 100 : $progress,
			'method'   => 'php',
		);
	}

	/** Step 3: compress files (one chunk per call, auto-splitting into parts). */
	public function step_files() {
		$archive = new Archive( $this->dir, $this->list_file() );
		$res = $archive->zip_chunk( $this->state['archive'], (int) $this->state['file_total'] );

		$this->state['archive'] = $res['state'];
		$this->save_state();

		$total    = max( 1, (int) $this->state['file_total'] );
		$progress = min( 99, round( ( (int) $res['state']['offset'] / $total ) * 100 ) );

		return array(
			'done'     => $res['done'],
			'progress' => $res['done'] ? 100 : $progress,
			'parts'    => count( $res['state']['parts'] ),
		);
	}

	/** Step 4: create the manifest + copy the installer, then clean up. */
	public function finalize( $password = '', $allow_ips = array() ) {
		$parts = isset( $this->state['archive']['parts'] ) ? $this->state['archive']['parts'] : array();
		sort( $parts );

		$manifest = array(
			'version'  => FLEXA_VERSION,
			'created'  => gmdate( 'c' ),
			'site_url' => $this->state['site_url'],
			'home_url' => $this->state['home_url'],
			'abspath'  => $this->state['abspath'],
			'prefix'   => $this->state['prefix'],
			'db_file'  => 'database.sql',
			'archives' => $parts, // list of zip parts
		);
		file_put_contents( $this->dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		copy( FLEXA_PATH . 'templates/installer.tpl', $this->dir . '/installer.php' );
		wp_delete_file( $this->list_file() );

		// Pull-by-link: hashed token + one-click link for staging.
		$pull_token = bin2hex( random_bytes( 32 ) );
		file_put_contents( $this->dir . '/pull-token.hash', hash( 'sha256', $pull_token ) );
		// Expires after 48h (the link exposes the customer DB -> must not live indefinitely).
		file_put_contents( $this->dir . '/pull-meta.json', wp_json_encode( array(
			'created'   => time(),
			'expires'   => time() + 48 * 3600,
			'allow_ips' => array_values( (array) $allow_ips ),
		) ) );
		$has_pass = ( '' !== (string) $password );
		if ( $has_pass ) {
			file_put_contents( $this->dir . '/pull-pass.hash', password_hash( (string) $password, PASSWORD_DEFAULT ) );
		}
		@file_put_contents(
			$this->dir . '/.htaccess',
			"<FilesMatch \"^(pull-token\\.hash|pull-pass\\.hash|pull-meta\\.json|sd-token\\.hash|sd-state\\.json)$\">\n"
			. "  <IfModule mod_authz_core.c>Require all denied</IfModule>\n"
			. "  <IfModule !mod_authz_core.c>Order allow,deny\nDeny from all</IfModule>\n"
			. "</FilesMatch>\n"
		);
		$pull_link = trailingslashit( home_url() ) . '?sd_pull=' . rawurlencode( $this->id ) . '&key=' . $pull_token;

		$base = FLEXA_PACKAGE_URL . '/' . $this->id;
		$archive_urls = array();
		foreach ( $parts as $p ) {
			$archive_urls[] = $base . '/' . $p;
		}

		return array(
			'done'      => true,
			'pull_link' => $pull_link,
			'has_pass'  => $has_pass,
			'files'     => array(
				'installer' => $base . '/installer.php',
				'archives'  => $archive_urls,
				'database'  => $base . '/database.sql',
				'manifest'  => $base . '/manifest.json',
			),
			'dir'       => $this->dir,
		);
	}
}

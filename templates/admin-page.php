<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// This template is require'd inside Plugin::render_page(), so every variable
// here is method-local, not global. The prefix sniff can't see that when it
// scans the file in isolation, so silence its false positives file-wide.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="wrap sd-wrap">
	<h1>Site Cloner</h1>
	<p class="description"><?php esc_html_e( 'Create a package to migrate this site to staging. Includes', 'site-cloner' ); ?> <code>files + database + installer</code>.</p>

	<?php \Flexa\SiteCloner\Health::render(); ?>

	<div class="sd-card">
		<p style="margin-top:0;">
			<label for="sd-build-pass"><?php esc_html_e( 'Protection password (optional):', 'site-cloner' ); ?></label><br>
			<input type="text" id="sd-build-pass" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty if not needed', 'site-cloner' ); ?>" autocomplete="off">
			<span class="description"><?php esc_html_e( "If set, the staging side must enter this password to pull the package (send it to them through a private channel, don't include it in the link).", 'site-cloner' ); ?></span>
		</p>
		<p>
			<label for="sd-build-ips"><?php esc_html_e( 'IP restriction (optional):', 'site-cloner' ); ?></label><br>
			<input type="text" id="sd-build-ips" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 203.0.113.5, 203.0.113.6', 'site-cloner' ); ?>" autocomplete="off">
			<span class="description"><?php esc_html_e( "Only these IPs can pull the package. Leave empty = no restriction. Don't know the destination server's IP yet? Leave it empty, click Test connection on the destination side, and it will report the IP for you to add here.", 'site-cloner' ); ?></span>
		</p>
		<button id="sd-build" class="button button-primary button-hero"><?php esc_html_e( 'Create Package', 'site-cloner' ); ?></button>
		<span id="sd-build-spin" class="sd-spinner" style="display:none;" aria-hidden="true"></span>

		<div id="sd-progress" class="sd-progress" style="display:none;">
			<div class="sd-step" data-step="db">
				<span class="sd-label"><?php esc_html_e( 'Database', 'site-cloner' ); ?></span>
				<div class="sd-bar"><i style="width:0%"></i></div>
			</div>
			<div class="sd-step" data-step="files">
				<span class="sd-label"><?php esc_html_e( 'Files', 'site-cloner' ); ?></span>
				<div class="sd-bar"><i style="width:0%"></i></div>
			</div>
			<p class="sd-status"><?php esc_html_e( 'Initializing…', 'site-cloner' ); ?></p>
		</div>

		<div id="sd-result" class="sd-result" style="display:none;">
			<h2>✅ <?php esc_html_e( 'Package is ready', 'site-cloner' ); ?></h2>

			<div class="sd-pull-box">
				<strong>⚡ <?php esc_html_e( 'Fastest way — no upload/download needed:', 'site-cloner' ); ?></strong>
				<p><?php esc_html_e( 'Install this plugin on staging, go to', 'site-cloner' ); ?> <em>Tools → Site Cloner Import</em>, <?php esc_html_e( 'paste the link below and click run:', 'site-cloner' ); ?></p>
				<div class="sd-pull-row">
					<input type="text" id="sd-pull-link" readonly>
					<button type="button" id="sd-pull-copy" class="button"><?php esc_html_e( 'Copy', 'site-cloner' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'The link contains a token that grants access to the package. Only share it with people you trust; delete the package after the migration is done.', 'site-cloner' ); ?></p>
			</div>

			<details class="sd-manual">
				<summary><?php esc_html_e( "Or download the files manually (empty staging / can't connect)", 'site-cloner' ); ?></summary>
				<p><button type="button" id="sd-dl-all" class="button button-primary"><?php esc_html_e( 'Download all files', 'site-cloner' ); ?></button></p>
				<ul class="sd-files"></ul>
				<p class="description"><?php esc_html_e( 'Download all files, then use', 'site-cloner' ); ?> <code>installer.php</code> <?php esc_html_e( 'on staging.', 'site-cloner' ); ?></p>
			</details>
		</div>

		<div id="sd-error" class="notice notice-error" style="display:none;"><p></p></div>
	</div>

	<?php if ( ! empty( $packages ) ) : ?>
	<div class="sd-card sd-existing">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Packages on this site', 'site-cloner' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Previously created packages are kept on disk, so you can download the files again or get a fresh pull link after reloading this page.', 'site-cloner' ); ?></p>

		<?php foreach ( $packages as $sd_pkg ) : ?>
			<div class="sd-pkg" data-id="<?php echo esc_attr( $sd_pkg['id'] ); ?>">
				<div class="sd-pkg-head">
					<code><?php echo esc_html( $sd_pkg['id'] ); ?></code>
					<span class="description"><?php echo esc_html( trim( $sd_pkg['site_url'] . ' · ' . $sd_pkg['created'] . ' · ' . $sd_pkg['size'], ' ·' ) ); ?></span>
				</div>

				<p class="sd-pkg-actions">
					<button type="button" class="button button-primary sd-pkg-dlall"><?php esc_html_e( 'Download all files', 'site-cloner' ); ?></button>
					<?php if ( $sd_pkg['has_token'] ) : ?>
						<button type="button" class="button sd-pkg-link"><?php esc_html_e( 'Get pull link', 'site-cloner' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button sd-pkg-delete"><?php esc_html_e( 'Delete', 'site-cloner' ); ?></button>
					<?php if ( $sd_pkg['has_pass'] ) : ?>
						<span class="description">🔒 <?php esc_html_e( 'password-protected', 'site-cloner' ); ?></span>
					<?php endif; ?>
				</p>

				<div class="sd-pkg-linkrow sd-pull-row" style="display:none;">
					<input type="text" class="sd-pkg-linkinput" readonly>
					<button type="button" class="button sd-pkg-linkcopy"><?php esc_html_e( 'Copy', 'site-cloner' ); ?></button>
				</div>
				<p class="description sd-pkg-linknote" style="display:none;"><?php esc_html_e( 'A brand-new link was generated (valid 48h). Any link shared earlier for this package no longer works.', 'site-cloner' ); ?></p>

				<ul class="sd-files sd-pkg-files">
					<?php
					$sd_f = $sd_pkg['files'];
					if ( ! empty( $sd_f['installer'] ) ) {
						printf( '<li><a href="%s" download="installer.php">⬇ installer.php</a></li>', esc_url( $sd_f['installer'] ) );
					}
					foreach ( $sd_f['archives'] as $sd_az ) {
						printf(
							'<li><a href="%1$s" download>⬇ %2$s</a></li>',
							esc_url( $sd_az ),
							esc_html( basename( $sd_az ) )
						);
					}
					if ( ! empty( $sd_f['database'] ) ) {
						printf( '<li><a href="%s" download>⬇ database.sql</a></li>', esc_url( $sd_f['database'] ) );
					}
					if ( ! empty( $sd_f['manifest'] ) ) {
						printf( '<li><a href="%s" download>⬇ manifest.json</a></li>', esc_url( $sd_f['manifest'] ) );
					}
					?>
				</ul>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
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
				<ul class="sd-files"></ul>
				<p class="description"><?php esc_html_e( 'Download all files, then use', 'site-cloner' ); ?> <code>installer.php</code> <?php esc_html_e( 'on staging.', 'site-cloner' ); ?></p>
			</details>
		</div>

		<div id="sd-error" class="notice notice-error" style="display:none;"><p></p></div>
	</div>
</div>

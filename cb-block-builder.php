<?php
/**
 * Plugin Name: CBP Block Builder
 * Description: A GUI block scaffolder for cb-js-skeleton2026-shaped native-block themes (block.json/edit.js/render.php). Local-development only — inert on any environment that isn't detected as local.
 * Version: 1.0.0
 * Author: Chillibyte - DS
 * Text Domain: cb-block-builder
 *
 * Deliberately kept separate from the theme (see the theme's own CLAUDE.md,
 * "commercial separation" reasoning) — the skeleton themes stay one freely
 * copyable asset, this plugin is a separate one. It only ever writes files
 * into the currently active theme's blocks/ directory; it never modifies
 * the theme's own repository state (git, etc.) itself.
 */

defined( 'ABSPATH' ) || exit;

define( 'CB_BLOCK_BUILDER_FILE', __FILE__ );
define( 'CB_BLOCK_BUILDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_BLOCK_BUILDER_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_BLOCK_BUILDER_VERSION', '1.0.0' );

require_once CB_BLOCK_BUILDER_DIR . 'inc/environment.php';

/**
 * Everything else only ever loads behind the local-environment gate — on a
 * live site this plugin registers nothing at all beyond the two requires
 * above (which define pure functions, no hooks).
 */
function cb_block_builder_bootstrap() {
	if ( ! cb_block_builder_is_local_environment() ) {
		return;
	}

	require_once CB_BLOCK_BUILDER_DIR . 'inc/generator.php';
	require_once CB_BLOCK_BUILDER_DIR . 'inc/admin-page.php';
	require_once CB_BLOCK_BUILDER_DIR . 'inc/handlers.php';
}
add_action( 'plugins_loaded', 'cb_block_builder_bootstrap' );

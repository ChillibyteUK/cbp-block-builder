<?php
/**
 * Local-environment detection and active-theme shape inspection.
 *
 * @package cb-block-builder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hard gate for the whole plugin. True only if this looks like a local dev
 * environment — either WordPress's own WP_ENVIRONMENT_TYPE says so, or the
 * site's host ends in .local (Local by Flywheel-style dev sites — matches
 * this project's own hts.local / wp72test.local convention).
 *
 * Every entry point (menu registration, the admin-post handler) re-checks
 * this independently rather than trusting a single earlier check, so there
 * is no path to the generator running on anything but a local site.
 *
 * @return bool
 */
function cb_block_builder_is_local_environment() {
	if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
		return true;
	}

	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! $host ) {
		return false;
	}

	return '.local' === substr( $host, -6 ) || 'local' === $host;
}

/**
 * Best-effort, informational-only npm detection. Not a gate — shell_exec()
 * is often disabled, and even when it isn't, a dev's npm (e.g. nvm-managed)
 * may not be on the web server process's PATH even on a genuine local
 * machine, so treating this as a hard requirement would risk false
 * negatives on the user's own box. Only used to word the admin notice.
 *
 * @return bool
 */
function cb_block_builder_detect_npm() {
	if ( ! function_exists( 'shell_exec' ) ) {
		return false;
	}

	$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
	if ( in_array( 'shell_exec', $disabled, true ) ) {
		return false;
	}

	$command = ( 0 === stripos( PHP_OS, 'WIN' ) ) ? 'where npm 2>NUL' : 'command -v npm 2>/dev/null';
	$result  = @shell_exec( $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- local-only, informational.

	return ! empty( trim( (string) $result ) );
}

/**
 * Inspect the active theme for the things the generator needs: its Text
 * Domain (used for block.json's name/category and translation calls), its
 * blocks/ directory (created if missing), and the editor-chrome CSS class
 * prefix sniffed from its own src/css/editor.css.
 *
 * Sniffing the prefix rather than deriving it from the Text Domain matters:
 * cb-hts-js-2026 and cb-js-skeleton2026 already disagree on this (the
 * latter uses a bare "cb-js-skeleton-" prefix without its Text Domain's
 * trailing "2026" — a known inconsistency documented in that theme's own
 * CLAUDE.md), so no reliable string transform exists from Text Domain to
 * class prefix across every project forked from either skeleton.
 *
 * @return array{
 *     theme_dir: string,
 *     theme_url: string,
 *     text_domain: string,
 *     name: string,
 *     blocks_dir: string,
 *     blocks_dir_writable: bool,
 *     editor_prefix: string,
 *     editor_prefix_sniffed: bool,
 *     has_block_autoloader: bool,
 * }
 */
function cb_block_builder_get_theme_context() {
	$theme       = wp_get_theme();
	$theme_dir   = get_stylesheet_directory();
	$text_domain = $theme->get( 'TextDomain' );
	$blocks_dir  = trailingslashit( $theme_dir ) . 'blocks';

	if ( ! file_exists( $blocks_dir ) ) {
		wp_mkdir_p( $blocks_dir );
		// Group-writable so a CLI dev user (running `npm run blocks:build`)
		// can write into a directory the web server user just created —
		// see the matching chmod in generator.php's cb_block_builder_generate_block()
		// for the full reasoning.
		chmod( $blocks_dir, 0775 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- local-dev-only fallback path, no WP_Filesystem context available here.
	}

	$editor_prefix         = $text_domain;
	$editor_prefix_sniffed = false;
	$editor_css_path       = trailingslashit( $theme_dir ) . 'src/css/editor.css';

	if ( file_exists( $editor_css_path ) ) {
		$editor_css = file_get_contents( $editor_css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file, not remote/user input.

		if ( false !== $editor_css && preg_match( '/\.([\w-]+)-editor-block\s*\{/', $editor_css, $matches ) ) {
			$editor_prefix         = $matches[1];
			$editor_prefix_sniffed = true;
		}
	}

	$has_block_autoloader = false;
	$blocks_php_path      = trailingslashit( $theme_dir ) . 'inc/blocks.php';

	if ( file_exists( $blocks_php_path ) ) {
		$blocks_php           = file_get_contents( $blocks_php_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file, not remote/user input.
		$has_block_autoloader = false !== $blocks_php && false !== strpos( $blocks_php, 'block.json' );
	}

	return array(
		'theme_dir'             => $theme_dir,
		'theme_url'             => get_stylesheet_directory_uri(),
		'text_domain'           => $text_domain,
		'name'                  => $theme->get( 'Name' ),
		'blocks_dir'            => $blocks_dir,
		'blocks_dir_writable'   => wp_is_writable( $blocks_dir ),
		'editor_prefix'         => $editor_prefix,
		'editor_prefix_sniffed' => $editor_prefix_sniffed,
		'has_block_autoloader'  => $has_block_autoloader,
	);
}

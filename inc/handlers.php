<?php
/**
 * admin-post.php handler for the block-generation form.
 *
 * @package cb-block-builder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirect back to the Block Builder page with a notice.
 *
 * The notice is handed off via a short-lived, per-user transient rather than
 * a GET param — simpler than nonce-protecting a purely informational
 * redirect param, and avoids reflecting request data back into the page at
 * all (the message is always plugin-authored text, never raw user input,
 * but there's no reason to round-trip it through the URL either).
 *
 * @param string $type    'success' or 'error'.
 * @param string $message Notice text.
 * @return void
 */
function cb_block_builder_redirect_with_notice( $type, $message ) {
	set_transient(
		'cb_block_builder_notice_' . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		MINUTE_IN_SECONDS
	);

	wp_safe_redirect( admin_url( 'admin.php?page=cb-block-builder' ) );
	exit;
}

/**
 * Handle the "Create Block" form submission.
 *
 * @return void
 */
function cb_block_builder_handle_generate() {
	check_admin_referer( 'cb_block_builder_generate' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'cb-block-builder' ), 403 );
	}

	if ( ! cb_block_builder_is_local_environment() ) {
		wp_die( esc_html__( 'This only runs on a local development environment.', 'cb-block-builder' ), 403 );
	}

	$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$color_support = ! empty( $_POST['color_support'] );
	$fields_json   = isset( $_POST['fields_json'] ) ? wp_unslash( $_POST['fields_json'] ) : '[]';
	$fields        = json_decode( $fields_json, true );

	if ( ! is_array( $fields ) ) {
		cb_block_builder_redirect_with_notice( 'error', __( 'Could not read the submitted field data.', 'cb-block-builder' ) );
	}

	$sanitized_fields = array();
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$sanitized_field = array(
			'label'          => isset( $field['label'] ) ? (string) $field['label'] : '',
			'type'           => isset( $field['type'] ) ? (string) $field['type'] : '',
			'help'           => isset( $field['help'] ) ? (string) $field['help'] : '',
			'width'          => isset( $field['width'] ) ? (int) $field['width'] : 100,
			'options'        => isset( $field['options'] ) ? (string) $field['options'] : '',
			'textarea_style' => isset( $field['textarea_style'] ) ? (string) $field['textarea_style'] : 'paragraph',
			'link_target'    => ! empty( $field['link_target'] ),
			'sub_fields'     => array(),
		);

		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			foreach ( $field['sub_fields'] as $sub_field ) {
				if ( ! is_array( $sub_field ) ) {
					continue;
				}
				$sanitized_field['sub_fields'][] = array(
					'label'      => isset( $sub_field['label'] ) ? (string) $sub_field['label'] : '',
					'type'       => isset( $sub_field['type'] ) ? (string) $sub_field['type'] : '',
					'mime_types' => isset( $sub_field['mime_types'] ) ? (string) $sub_field['mime_types'] : '',
				);
			}
		}

		$sanitized_fields[] = $sanitized_field;
	}

	$result = cb_block_builder_generate_block(
		array(
			'title'         => $title,
			'color_support' => $color_support,
			'fields'        => $sanitized_fields,
		)
	);

	if ( is_wp_error( $result ) ) {
		cb_block_builder_redirect_with_notice( 'error', $result->get_error_message() );
	}

	cb_block_builder_redirect_with_notice(
		'success',
		sprintf(
			/* translators: 1: block title, 2: block slug. */
			__( 'Block "%1$s" created at blocks/%2$s/. Run npm run blocks:build (or blocks:start while developing) in the theme directory before it\'s usable.', 'cb-block-builder' ),
			$title,
			$result['slug']
		)
	);
}
add_action( 'admin_post_cb_block_builder_generate', 'cb_block_builder_handle_generate' );

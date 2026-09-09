<?php
/**
 * Native block file generation — a PHP reimplementation of the active
 * theme's own add_block.sh, driven by structured input from the admin UI
 * instead of terminal prompts. See that script (blocks/../add_block.sh in
 * the active theme) for the reference behaviour this mirrors field-type by
 * field-type.
 *
 * @package cb-block-builder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field types supported here. Text/textarea/richtext/image/url/link/number/
 * select/checkbox match add_block.sh exactly; repeater, gallery, and
 * post_type go beyond it, generating against the active theme's own
 * blocks/_shared/RepeaterField.js, gallery-picker conventions, and
 * blocks/_shared/PostTypePicker.js respectively (see
 * cb_block_builder_build_repeater_consts() / cb_block_builder_build_gallery_hooks()
 * — post_type needs no equivalent hook builder, PostTypePicker.js is
 * self-contained). This assumes the active theme already carries that
 * shared code — the plugin only ever emits a reference to it, it never
 * writes it.
 *
 * @var string[]
 */
function cb_block_builder_supported_field_types() {
	return array( 'text', 'textarea', 'richtext', 'image', 'gallery', 'url', 'link', 'number', 'select', 'radio', 'checkbox', 'repeater', 'post_type' );
}

/**
 * Sub-field types a repeater field can offer, matching the active theme's
 * own blocks/_shared/RepeaterField.js vocabulary exactly.
 *
 * @var string[]
 */
function cb_block_builder_supported_subfield_types() {
	return array( 'text', 'number', 'textarea', 'image', 'file', 'link', 'radio' );
}

/**
 * Field types a conditional-logic rule can target — only types with a
 * comparable scalar value. Types like `richtext`/`image`/`gallery`/`link`/
 * `repeater`/`post_type` are excluded from the "Field" dropdown in the
 * conditional-logic UI; comparing against them isn't well-defined without
 * ACF's per-type condition classes, which is out of scope here.
 *
 * @var string[]
 */
function cb_block_builder_conditional_target_types() {
	return array( 'text', 'textarea', 'number', 'url', 'select', 'radio', 'checkbox' );
}

/**
 * Operators offered by the conditional-logic UI, keyed by the value stored
 * in a rule's `operator`. Deliberately a subset of ACF's own vocabulary,
 * scoped to what cb_block_builder_build_condition_expression() knows how to
 * translate into a JS boolean expression.
 *
 * @return array<string, string>
 */
function cb_block_builder_conditional_operators() {
	return array(
		'=='         => __( 'is equal to', 'cb-block-builder' ),
		'!='         => __( 'is not equal to', 'cb-block-builder' ),
		'>'          => __( 'is greater than', 'cb-block-builder' ),
		'<'          => __( 'is less than', 'cb-block-builder' ),
		'==contains' => __( 'contains', 'cb-block-builder' ),
		'!=contains' => __( 'does not contain', 'cb-block-builder' ),
		'==empty'    => __( 'has no value', 'cb-block-builder' ),
		'!=empty'    => __( 'has any value', 'cb-block-builder' ),
	);
}

/**
 * Generate a stable, random identifier for a field — assigned once when a
 * row is first created (client-side) and kept for the field's lifetime so
 * conditional-logic rules can reference it independently of the field's
 * label or position, the same field-key-vs-name split ACF uses.
 *
 * @return string
 */
function cb_block_builder_generate_field_key() {
	return 'field_' . strtolower( wp_generate_password( 10, false ) );
}

/**
 * Sanitize a submitted `conditional_logic` value into the OR-groups-of-
 * AND-rules shape the rest of this file expects, dropping anything
 * malformed: rules with no field or an unrecognised operator, and any
 * group left with zero rules once those are dropped (mirrors ACF's own
 * save-time cleanup of its conditional_logic array).
 *
 * @param mixed $raw Raw submitted conditional_logic value.
 * @return array<int, array<int, array{field: string, operator: string, value: string}>>
 */
function cb_block_builder_sanitize_conditional_logic( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$operators = array_keys( cb_block_builder_conditional_operators() );
	$groups    = array();

	foreach ( $raw as $raw_group ) {
		if ( ! is_array( $raw_group ) ) {
			continue;
		}

		$rules = array();
		foreach ( $raw_group as $raw_rule ) {
			if ( ! is_array( $raw_rule ) || empty( $raw_rule['field'] ) ) {
				continue;
			}

			$operator = isset( $raw_rule['operator'] ) ? (string) $raw_rule['operator'] : '==';
			if ( ! in_array( $operator, $operators, true ) ) {
				continue;
			}

			$rules[] = array(
				'field'    => sanitize_key( $raw_rule['field'] ),
				'operator' => $operator,
				'value'    => isset( $raw_rule['value'] ) ? sanitize_text_field( (string) $raw_rule['value'] ) : '',
			);
		}

		if ( $rules ) {
			$groups[] = $rules;
		}
	}

	return $groups;
}

/**
 * Parse a comma-separated `select`/`radio` options string into an ordered
 * options list plus a default value. Every option becomes a
 * `{ label, value }` pair (the same string for both), in the order given.
 * Wrapping exactly one option in [brackets] marks it as the default/
 * pre-selected value, with the brackets stripped from both label and
 * value; wrapping none defaults to the first option instead.
 *
 * @param string $raw_options Comma-separated options, e.g. 'Yes,[No]'.
 * @return array{options: array<int, array{label: string, value: string}>, default: string}
 */
function cb_block_builder_parse_field_options( $raw_options ) {
	$options = array();
	$default = '';

	foreach ( explode( ',', (string) $raw_options ) as $option ) {
		$option = trim( $option );
		if ( '' === $option ) {
			continue;
		}

		$is_default = false;
		if ( preg_match( '/^\[(.+)\]$/', $option, $matches ) ) {
			$option     = trim( $matches[1] );
			$is_default = true;
		}

		if ( '' === $option ) {
			continue;
		}

		$options[] = array(
			'label' => $option,
			'value' => $option,
		);

		if ( $is_default ) {
			$default = $option;
		}
	}

	if ( '' === $default && ! empty( $options ) ) {
		$default = $options[0]['value'];
	}

	return array(
		'options' => $options,
		'default' => $default,
	);
}

/**
 * Slugify a block title the same way add_block.sh does: lowercase, spaces
 * to hyphens, strip to a-z0-9-.
 *
 * @param string $title Block title.
 * @return string
 */
function cb_block_builder_slugify( $title ) {
	$slug = strtolower( $title );
	$slug = str_replace( ' ', '-', $slug );
	$slug = preg_replace( '/[^a-z0-9-]/', '', $slug );
	return $slug;
}

/**
 * snake_case a field label for use as a PHP variable name in render.php —
 * same transform add_block.sh applies to its own $field_snake.
 *
 * @param string $label Field label.
 * @return string
 */
function cb_block_builder_snake( $label ) {
	$snake = strtolower( $label );
	$snake = str_replace( ' ', '_', $snake );
	$snake = preg_replace( '/[^a-z0-9_]/', '', $snake );
	return $snake;
}

/**
 * camelCase a snake_case string for use as a JS attribute name — same
 * transform add_block.sh's awk one-liner applies to $field_camel.
 *
 * @param string $snake snake_case string.
 * @return string
 */
function cb_block_builder_camel_from_snake( $snake ) {
	$parts = array_values( array_filter( explode( '_', $snake ), 'strlen' ) );

	if ( empty( $parts ) ) {
		return '';
	}

	$camel = array_shift( $parts );

	foreach ( $parts as $part ) {
		$camel .= ucfirst( $part );
	}

	return $camel;
}

/**
 * Escape a string for safe embedding inside a single-quoted JS string
 * literal in generated edit.js source.
 *
 * @param string $string Raw string.
 * @return string
 */
function cb_block_builder_js_str( $string ) {
	$string = str_replace( '\\', '\\\\', $string );
	$string = str_replace( "'", "\\'", $string );
	$string = str_replace( array( "\r", "\n" ), '', $string );
	return $string;
}

/**
 * Validate a submitted block config before anything touches the filesystem.
 *
 * @param array  $config       Raw config: title, color_support, fields.
 * @param string $blocks_dir   Active theme's blocks/ directory.
 * @return true|WP_Error
 */
function cb_block_builder_validate_config( $config, $blocks_dir ) {
	if ( empty( $config['title'] ) || ! is_string( $config['title'] ) ) {
		return new WP_Error( 'cb_block_builder_no_title', __( 'Enter a block title.', 'cb-block-builder' ) );
	}

	$slug = cb_block_builder_slugify( $config['title'] );

	if ( '' === $slug ) {
		return new WP_Error( 'cb_block_builder_bad_title', __( 'That title produces an empty slug — use at least one letter or number.', 'cb-block-builder' ) );
	}

	if ( file_exists( trailingslashit( $blocks_dir ) . $slug ) ) {
		return new WP_Error(
			// translators: %s: block slug.
			'cb_block_builder_exists',
			sprintf( __( 'A block already exists at blocks/%s/.', 'cb-block-builder' ), $slug )
		);
	}

	return cb_block_builder_validate_fields( $config['fields'] ?? array() );
}

/**
 * Validate a block config for the "edit an existing block's attributes"
 * path — same per-field rules as a new block, minus the title/slug checks
 * (the slug can't change once a block exists; see
 * cb_block_builder_update_block_attributes()).
 *
 * @param array $config Raw config: color_support, fields.
 * @return true|WP_Error
 */
function cb_block_builder_validate_edit_config( $config ) {
	return cb_block_builder_validate_fields( $config['fields'] ?? array() );
}

/**
 * Per-field validation rules shared by both the "create" and "edit
 * attributes" paths.
 *
 * @param array $fields Raw field rows.
 * @return true|WP_Error
 */
function cb_block_builder_validate_fields( $fields ) {
	if ( empty( $fields ) || ! is_array( $fields ) ) {
		return true; // A block with no fields is valid, same as add_block.sh allows.
	}

	$allowed_types  = cb_block_builder_supported_field_types();
	$allowed_widths = array( 100, 50, 33, 25 );

	foreach ( $fields as $index => $field ) {
		if ( empty( $field['label'] ) || ! is_string( $field['label'] ) ) {
			// translators: %d: 1-based field row number.
			return new WP_Error( 'cb_block_builder_field_label', sprintf( __( 'Field #%d needs a label.', 'cb-block-builder' ), $index + 1 ) );
		}

		if ( empty( $field['type'] ) || ! in_array( $field['type'], $allowed_types, true ) ) {
			// translators: %d: 1-based field row number.
			return new WP_Error( 'cb_block_builder_field_type', sprintf( __( 'Field #%d has an unrecognised type.', 'cb-block-builder' ), $index + 1 ) );
		}

		if ( ! in_array( (int) ( $field['width'] ?? 100 ), $allowed_widths, true ) ) {
			// translators: %d: 1-based field row number.
			return new WP_Error( 'cb_block_builder_field_width', sprintf( __( 'Field #%d has an invalid width.', 'cb-block-builder' ), $index + 1 ) );
		}

		if ( cb_block_builder_snake( $field['label'] ) === '' ) {
			// translators: %d: 1-based field row number.
			return new WP_Error( 'cb_block_builder_field_label_chars', sprintf( __( 'Field #%d\'s label needs at least one letter or number.', 'cb-block-builder' ), $index + 1 ) );
		}

		if ( 'repeater' === $field['type'] ) {
			$subfield_error = cb_block_builder_validate_subfields( $field, $index );
			if ( is_wp_error( $subfield_error ) ) {
				return $subfield_error;
			}

			if ( ! in_array( $field['repeater_layout'] ?? 'row', array( 'row', 'column' ), true ) ) {
				// translators: %d: 1-based field row number.
				return new WP_Error( 'cb_block_builder_repeater_layout', sprintf( __( 'Field #%d has an invalid row layout.', 'cb-block-builder' ), $index + 1 ) );
			}
		}

		if ( 'post_type' === $field['type'] ) {
			$post_type_slug = sanitize_key( $field['post_type_slug'] ?? '' );

			if ( '' === $post_type_slug ) {
				// translators: %d: 1-based field row number.
				return new WP_Error( 'cb_block_builder_post_type_slug_empty', sprintf( __( 'Field #%d needs a post type.', 'cb-block-builder' ), $index + 1 ) );
			}

			if ( ! post_type_exists( $post_type_slug ) ) {
				// translators: 1: 1-based field row number, 2: the post type slug that wasn't recognised.
				return new WP_Error( 'cb_block_builder_post_type_slug_unknown', sprintf( __( 'Field #%1$d: "%2$s" isn\'t a registered post type.', 'cb-block-builder' ), $index + 1, $post_type_slug ) );
			}
		}
	}

	return true;
}

/**
 * Validate a repeater field's sub-fields.
 *
 * @param array $field Raw field row (must be type 'repeater').
 * @param int   $index 0-based field row index, for error messages.
 * @return true|WP_Error
 */
function cb_block_builder_validate_subfields( $field, $index ) {
	if ( empty( $field['sub_fields'] ) || ! is_array( $field['sub_fields'] ) ) {
		// translators: %d: 1-based field row number.
		return new WP_Error( 'cb_block_builder_repeater_subfields', sprintf( __( 'Field #%d needs at least one sub-field.', 'cb-block-builder' ), $index + 1 ) );
	}

	$allowed_subfield_types = cb_block_builder_supported_subfield_types();

	foreach ( $field['sub_fields'] as $sub_index => $sub_field ) {
		if ( empty( $sub_field['label'] ) || ! is_string( $sub_field['label'] ) ) {
			// translators: 1: 1-based field row number, 2: 1-based sub-field row number.
			return new WP_Error( 'cb_block_builder_subfield_label', sprintf( __( 'Field #%1$d, sub-field #%2$d needs a label.', 'cb-block-builder' ), $index + 1, $sub_index + 1 ) );
		}

		if ( empty( $sub_field['type'] ) || ! in_array( $sub_field['type'], $allowed_subfield_types, true ) ) {
			// translators: 1: 1-based field row number, 2: 1-based sub-field row number.
			return new WP_Error( 'cb_block_builder_subfield_type', sprintf( __( 'Field #%1$d, sub-field #%2$d has an unrecognised type.', 'cb-block-builder' ), $index + 1, $sub_index + 1 ) );
		}

		if ( '' === cb_block_builder_snake( $sub_field['label'] ) ) {
			// translators: 1: 1-based field row number, 2: 1-based sub-field row number.
			return new WP_Error( 'cb_block_builder_subfield_label_chars', sprintf( __( 'Field #%1$d, sub-field #%2$d\'s label needs at least one letter or number.', 'cb-block-builder' ), $index + 1, $sub_index + 1 ) );
		}
	}

	return true;
}

/**
 * Sanitize a field's help text: the same whitespace/encoding cleanup as
 * sanitize_text_field(), but deliberately skips its HTML-tag stripping.
 * Help text is only ever used as a JS string literal (embedded via
 * cb_block_builder_js_str(), which handles JS-string escaping) or set as a
 * form field's `.value` property in the admin UI (never echoed as raw
 * HTML) — so it's safe, and sometimes necessary, for it to contain literal
 * "<strong>"/"<em>"-style instructional text (e.g. "wrap a word in
 * <strong> to bold it") without that being silently stripped.
 *
 * @param string $value Raw help text.
 * @return string
 */
function cb_block_builder_sanitize_help_text( $value ) {
	$value = wp_check_invalid_utf8( (string) $value );
	$value = trim( $value );
	return preg_replace( '/[\r\n\t ]+/', ' ', $value );
}

/**
 * Normalise a raw submitted field row into the shape the rest of this file
 * expects: adds the derived camelCase JS name and snake_case PHP name.
 *
 * @param array $field Raw field row.
 * @return array
 */
function cb_block_builder_normalise_field( $field ) {
	$snake = cb_block_builder_snake( $field['label'] );

	$normalised = array(
		'label'          => sanitize_text_field( $field['label'] ),
		'type'           => sanitize_key( $field['type'] ),
		'help'           => isset( $field['help'] ) ? cb_block_builder_sanitize_help_text( $field['help'] ) : '',
		'width'          => (int) ( $field['width'] ?? 100 ),
		'options'        => isset( $field['options'] ) ? sanitize_text_field( $field['options'] ) : '',
		'textarea_style' => isset( $field['textarea_style'] ) ? sanitize_key( $field['textarea_style'] ) : 'paragraph',
		'link_target'    => ! empty( $field['link_target'] ),
		'name'           => cb_block_builder_camel_from_snake( $snake ),
		'snake'          => $snake,
		'sub_fields'     => array(),
		'repeater_layout' => isset( $field['repeater_layout'] ) ? sanitize_key( $field['repeater_layout'] ) : 'row',
		'post_type_slug' => isset( $field['post_type_slug'] ) ? sanitize_key( $field['post_type_slug'] ) : '',
		'field_key'         => ! empty( $field['field_key'] ) ? sanitize_key( $field['field_key'] ) : cb_block_builder_generate_field_key(),
		'conditional_logic' => cb_block_builder_sanitize_conditional_logic( $field['conditional_logic'] ?? array() ),
	);

	if ( 'repeater' === $normalised['type'] && ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
		foreach ( $field['sub_fields'] as $sub_field ) {
			$normalised['sub_fields'][] = cb_block_builder_normalise_subfield( $sub_field );
		}
	}

	return $normalised;
}

/**
 * Normalise a raw submitted repeater sub-field row.
 *
 * @param array $sub_field Raw sub-field row: label, type, mime_types, link_target, options.
 * @return array
 */
function cb_block_builder_normalise_subfield( $sub_field ) {
	$snake = cb_block_builder_snake( $sub_field['label'] );

	return array(
		'label'       => sanitize_text_field( $sub_field['label'] ),
		'type'        => sanitize_key( $sub_field['type'] ),
		'mime_types'  => isset( $sub_field['mime_types'] ) ? sanitize_text_field( $sub_field['mime_types'] ) : '',
		'link_target' => ! empty( $sub_field['link_target'] ),
		'options'     => isset( $sub_field['options'] ) ? sanitize_text_field( $sub_field['options'] ) : '',
		'name'        => cb_block_builder_camel_from_snake( $snake ),
		'snake'       => $snake,
	);
}

/**
 * Build the block.json `attributes` object for every field, matching
 * add_block.sh's per-type attribute shapes exactly.
 *
 * @param array $fields Normalised fields.
 * @return array
 */
function cb_block_builder_build_attributes_array( $fields ) {
	$attributes = array();

	foreach ( $fields as $field ) {
		$name = $field['name'];

		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
			case 'richtext':
			case 'url':
				$attributes[ $name ] = array(
					'type'    => 'string',
					'default' => '',
				);
				break;

			case 'select':
			case 'radio':
				$parsed               = cb_block_builder_parse_field_options( $field['options'] );
				$attributes[ $name ]  = array(
					'type'    => 'string',
					'default' => $parsed['default'],
				);
				break;

			case 'number':
				$attributes[ $name ] = array(
					'type'    => 'number',
					'default' => 0,
				);
				break;

			case 'checkbox':
				$attributes[ $name ] = array(
					'type'    => 'boolean',
					'default' => false,
				);
				break;

			case 'image':
				$attributes[ $name . 'Id' ]  = array(
					'type'    => 'number',
					'default' => 0,
				);
				$attributes[ $name . 'Url' ] = array(
					'type'    => 'string',
					'default' => '',
				);
				$attributes[ $name . 'Alt' ] = array(
					'type'    => 'string',
					'default' => '',
				);
				break;

			case 'link':
				$attributes[ $name . 'Text' ] = array(
					'type'    => 'string',
					'default' => '',
				);
				$attributes[ $name . 'Url' ]  = array(
					'type'    => 'string',
					'default' => '',
				);
				if ( $field['link_target'] ) {
					$attributes[ $name . 'Target' ] = array(
						'type'    => 'boolean',
						'default' => false,
					);
				}
				break;

			case 'repeater':
			case 'gallery':
				$attributes[ $name ] = array(
					'type'    => 'array',
					'default' => array(),
				);
				break;

			case 'post_type':
				$attributes[ $name . 'Id' ] = array(
					'type'    => 'number',
					'default' => 0,
				);
				break;
		}
	}

	return $attributes;
}

/**
 * Encode a value as JSON with tab indentation, matching this project's
 * existing block.json files (json_encode's default pretty-print uses
 * 4-space indents; the rest of the codebase uses tabs throughout).
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function cb_block_builder_json_encode_tabs( $data ) {
	$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return preg_replace_callback(
		'/^( +)/m',
		static function ( $matches ) {
			return str_repeat( "\t", (int) ( strlen( $matches[1] ) / 4 ) );
		},
		$json
	);
}

/**
 * Build block.json's `supports` object — shared by initial generation and
 * by cb_block_builder_update_block_attributes()'s attributes-only edit path.
 *
 * @param bool $color_support Whether to add background/text colour support.
 * @return array
 */
function cb_block_builder_build_supports( $color_support ) {
	$supports = array(
		'anchor'    => true,
		'className' => true,
		'align'     => true,
	);

	if ( $color_support ) {
		$supports['color'] = array(
			'background' => true,
			'text'       => true,
		);
	}

	return $supports;
}

/**
 * Build block.json's full contents.
 *
 * @param string $title         Block title.
 * @param string $slug          Block slug.
 * @param string $text_domain   Active theme's Text Domain.
 * @param array  $fields        Normalised fields.
 * @param bool   $color_support Whether to add background/text colour support.
 * @return string
 */
function cb_block_builder_build_block_json( $title, $slug, $text_domain, $fields, $color_support ) {
	$block_json = array(
		'$schema'      => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion'   => 3,
		'name'         => $text_domain . '/' . $slug,
		'title'        => $title,
		'category'     => $text_domain,
		'icon'         => 'cover-image',
		'attributes'   => (object) cb_block_builder_build_attributes_array( $fields ),
		'supports'     => cb_block_builder_build_supports( $color_support ),
		'editorScript' => 'file:./build/index.js',
		'render'       => 'file:./render.php',
	);

	return cb_block_builder_json_encode_tabs( $block_json ) . "\n";
}

/**
 * Build the `.block-builder.json` sidecar contents — the designer's own
 * field schema, stored alongside the generated files so a block can later
 * be re-opened for editing (block.json's `attributes` alone can't be
 * reversed back into field types/sub-fields; see README's "field group
 * editor" notes).
 *
 * @param string $title         Block title.
 * @param bool   $color_support Whether colour support is enabled.
 * @param array  $fields        Normalised fields.
 * @return string
 */
function cb_block_builder_build_sidecar_json( $title, $color_support, $fields ) {
	$data = array(
		'title'         => $title,
		'color_support' => (bool) $color_support,
		'fields'        => $fields,
	);

	return cb_block_builder_json_encode_tabs( $data ) . "\n";
}

/**
 * Read a block's `.block-builder.json` sidecar, if it has one.
 *
 * @param string $block_dir Block directory (blocks/{slug}).
 * @return array|null
 */
function cb_block_builder_read_sidecar_config( $block_dir ) {
	$path = trailingslashit( $block_dir ) . '.block-builder.json';

	if ( ! file_exists( $path ) ) {
		return null;
	}

	$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file, not remote/user input.

	return is_array( $data ) ? $data : null;
}

/**
 * Build src/index.js — identical for every block, no per-block variation.
 *
 * @return string
 */
function cb_block_builder_build_index_js() {
	return <<<'JS'
import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import metadata from '../block.json';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
JS;
}

/**
 * Build one field's JSX control markup for src/edit.js.
 *
 * @param array  $field         Normalised field.
 * @param string $editor_prefix Sniffed/derived editor-chrome CSS class prefix.
 * @param string $text_domain   Active theme's Text Domain.
 * @return string
 */
function cb_block_builder_build_field_jsx( $field, $editor_prefix, $text_domain ) {
	$name        = $field['name'];
	$label       = cb_block_builder_js_str( $field['label'] );
	$help        = cb_block_builder_js_str( $field['help'] );
	$domain      = cb_block_builder_js_str( $text_domain );
	$help_attr   = '' !== $field['help'] ? "\n\t\t\t\thelp={ __( '{$help}', '{$domain}' ) }" : '';
	$help_para   = '' !== $field['help'] ? "\n\t\t\t\t<p className=\"{$editor_prefix}-editor-field__help\">{ __( '{$help}', '{$domain}' ) }</p>" : '';

	switch ( $field['type'] ) {
		case 'text':
		case 'url':
			return "\t\t\t<TextControl\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tvalue={ {$name} }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: value } ) }{$help_attr}\n\t\t\t/>\n";

		case 'number':
			return "\t\t\t<TextControl\n\t\t\t\ttype=\"number\"\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tvalue={ {$name} }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: Number( value ) } ) }{$help_attr}\n\t\t\t/>\n";

		case 'textarea':
			return "\t\t\t<TextareaControl\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tvalue={ {$name} }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: value } ) }{$help_attr}\n\t\t\t/>\n";

		case 'richtext':
			return "\t\t\t<div className=\"{$editor_prefix}-editor-field\">\n\t\t\t\t<label className=\"{$editor_prefix}-editor-field__label\">{ __( '{$label}', '{$domain}' ) }</label>\n\t\t\t\t<RichText\n\t\t\t\t\ttagName=\"div\"\n\t\t\t\t\tclassName=\"{$editor_prefix}-editor-field__control\"\n\t\t\t\t\taria-label={ __( '{$label}', '{$domain}' ) }\n\t\t\t\t\tplaceholder={ __( '{$label}', '{$domain}' ) }\n\t\t\t\t\tvalue={ {$name} }\n\t\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: value } ) }\n\t\t\t\t/>{$help_para}\n\t\t\t</div>\n";

		case 'checkbox':
			return "\t\t\t<ToggleControl\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tchecked={ {$name} }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: value } ) }{$help_attr}\n\t\t\t/>\n";

		case 'select':
			$parsed     = cb_block_builder_parse_field_options( $field['options'] );
			$options_js = '';
			foreach ( $parsed['options'] as $option ) {
				$option_js   = cb_block_builder_js_str( $option['value'] );
				$options_js .= "\t\t\t\t\t{ label: '{$option_js}', value: '{$option_js}' },\n";
			}
			return "\t\t\t<SelectControl\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tvalue={ {$name} }\n\t\t\t\toptions={ [\n{$options_js}\t\t\t\t] }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: value } ) }{$help_attr}\n\t\t\t/>\n";

		case 'radio':
			$parsed           = cb_block_builder_parse_field_options( $field['options'] );
			$radio_options_js = '';
			foreach ( $parsed['options'] as $option ) {
				$option_js         = cb_block_builder_js_str( $option['value'] );
				$radio_options_js .= "\t\t\t\t\t{ label: '{$option_js}', value: '{$option_js}' },\n";
			}
			return "\t\t\t<RadioControl\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tselected={ {$name} }\n\t\t\t\toptions={ [\n{$radio_options_js}\t\t\t\t] }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: value } ) }{$help_attr}\n\t\t\t/>\n";

		case 'image':
			return "\t\t\t<div className=\"{$editor_prefix}-editor-field\">\n\t\t\t\t<label className=\"{$editor_prefix}-editor-field__label\">{ __( '{$label}', '{$domain}' ) }</label>\n\t\t\t\t<MediaUploadCheck>\n\t\t\t\t\t<MediaUpload\n\t\t\t\t\t\tonSelect={ ( media ) =>\n\t\t\t\t\t\t\tsetAttributes( {\n\t\t\t\t\t\t\t\t{$name}Id: media.id,\n\t\t\t\t\t\t\t\t{$name}Url: media.url,\n\t\t\t\t\t\t\t\t{$name}Alt: media.alt || '',\n\t\t\t\t\t\t\t} )\n\t\t\t\t\t\t}\n\t\t\t\t\t\tallowedTypes={ [ 'image' ] }\n\t\t\t\t\t\tvalue={ {$name}Id }\n\t\t\t\t\t\trender={ ( { open } ) => (\n\t\t\t\t\t\t\t<div className=\"{$editor_prefix}-editor-field__control\">\n\t\t\t\t\t\t\t\t{ {$name}Url && (\n\t\t\t\t\t\t\t\t\t<img\n\t\t\t\t\t\t\t\t\t\tsrc={ {$name}Url }\n\t\t\t\t\t\t\t\t\t\talt={ {$name}Alt }\n\t\t\t\t\t\t\t\t\t\tstyle={ { maxWidth: '200px', display: 'block', marginBottom: '8px' } }\n\t\t\t\t\t\t\t\t\t/>\n\t\t\t\t\t\t\t\t) }\n\t\t\t\t\t\t\t\t<Button variant=\"secondary\" onClick={ open }>\n\t\t\t\t\t\t\t\t\t{ {$name}Url ? __( 'Replace {$label}', '{$domain}' ) : __( 'Select {$label}', '{$domain}' ) }\n\t\t\t\t\t\t\t\t</Button>\n\t\t\t\t\t\t\t</div>\n\t\t\t\t\t\t) }\n\t\t\t\t\t/>\n\t\t\t\t</MediaUploadCheck>{$help_para}\n\t\t\t</div>\n";

		case 'link':
			$link_html  = "\t\t\t<TextControl\n\t\t\t\tlabel={ __( '{$label} Text', '{$domain}' ) }\n\t\t\t\tvalue={ {$name}Text }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}Text: value } ) }\n\t\t\t/>\n\t\t\t<TextControl\n\t\t\t\ttype=\"url\"\n\t\t\t\tlabel={ __( '{$label} URL', '{$domain}' ) }\n\t\t\t\tvalue={ {$name}Url }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}Url: value } ) }{$help_attr}\n\t\t\t/>\n";
			if ( $field['link_target'] ) {
				$link_html .= "\t\t\t<ToggleControl\n\t\t\t\tlabel={ __( 'Open {$label} in a new tab', '{$domain}' ) }\n\t\t\t\tchecked={ {$name}Target }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}Target: value } ) }\n\t\t\t/>\n";
			}
			return $link_html;

		case 'repeater':
			$layout_attr = 'column' === ( $field['repeater_layout'] ?? 'row' ) ? "\n\t\t\t\tlayout=\"column\"" : '';
			return "\t\t\t<RepeaterField\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tvalue={ {$name} }\n\t\t\t\tonChange={ ( value ) => setAttributes( { {$name}: value } ) }\n\t\t\t\tfields={ {$name}Fields }\n\t\t\t\temptyRow={ {$name}EmptyRow }{$layout_attr}\n\t\t\t/>\n";

		case 'gallery':
			$remove_fn = 'remove' . ucfirst( $name ) . 'Image';
			return "\t\t\t<div className=\"{$editor_prefix}-editor-field\">\n\t\t\t\t<label className=\"{$editor_prefix}-editor-field__label\">{ __( '{$label}', '{$domain}' ) }</label>\n\t\t\t\t{ {$name}Media.length > 0 && (\n\t\t\t\t\t<ul className=\"{$editor_prefix}-repeater-field__row\" style={ { display: 'flex', flexWrap: 'wrap', gap: '8px', padding: 0, margin: '0 0 8px', listStyle: 'none' } }>\n\t\t\t\t\t\t{ {$name}Media.map( ( item ) => (\n\t\t\t\t\t\t\t<li key={ item.id } style={ { position: 'relative' } }>\n\t\t\t\t\t\t\t\t<img src={ item.media_details?.sizes?.thumbnail?.source_url || item.source_url } alt=\"\" style={ { width: '80px', height: '80px', objectFit: 'cover', display: 'block', background: '#fff', border: '1px solid #ccc' } } />\n\t\t\t\t\t\t\t\t<Button size=\"small\" isDestructive label={ __( 'Remove', '{$domain}' ) } onClick={ () => {$remove_fn}( item.id ) } style={ { position: 'absolute', top: 0, right: 0, minWidth: '20px', height: '20px', padding: 0, background: 'rgba(0,0,0,.6)', color: '#fff' } }>&times;</Button>\n\t\t\t\t\t\t\t</li>\n\t\t\t\t\t\t) ) }\n\t\t\t\t\t</ul>\n\t\t\t\t) }\n\t\t\t\t<MediaUploadCheck>\n\t\t\t\t\t<MediaUpload\n\t\t\t\t\t\tonSelect={ ( selected ) => setAttributes( { {$name}: selected.map( ( item ) => item.id ) } ) }\n\t\t\t\t\t\tallowedTypes={ [ 'image' ] }\n\t\t\t\t\t\tmultiple\n\t\t\t\t\t\tgallery\n\t\t\t\t\t\tvalue={ {$name} }\n\t\t\t\t\t\trender={ ( { open } ) => (\n\t\t\t\t\t\t\t<Button variant=\"secondary\" onClick={ open }>\n\t\t\t\t\t\t\t\t{ {$name}.length ? __( 'Edit Gallery', '{$domain}' ) : __( 'Select Images', '{$domain}' ) }\n\t\t\t\t\t\t\t</Button>\n\t\t\t\t\t\t) }\n\t\t\t\t\t/>\n\t\t\t\t</MediaUploadCheck>\n\t\t\t</div>\n";

		case 'post_type':
			$post_type_js = cb_block_builder_js_str( $field['post_type_slug'] );
			return "\t\t\t<PostTypePicker\n\t\t\t\tlabel={ __( '{$label}', '{$domain}' ) }\n\t\t\t\tpostType=\"{$post_type_js}\"\n\t\t\t\tvalue={ {$name}Id }\n\t\t\t\tonChange={ ( id ) => setAttributes( { {$name}Id: id } ) }{$help_attr}\n\t\t\t/>\n";
	}

	return '';
}

/**
 * Map every field's `field_key` to its JS attribute name, for translating
 * stored conditional-logic rules (which reference a field_key) into the
 * plain destructured identifiers available inside the generated `Edit()`
 * function body. Only top-level fields participate — repeater sub-fields
 * are out of scope for conditional logic.
 *
 * @param array $fields Normalised fields.
 * @return array<string, string>
 */
function cb_block_builder_build_field_key_map( $fields ) {
	$map = array();
	foreach ( $fields as $field ) {
		if ( ! empty( $field['field_key'] ) ) {
			$map[ $field['field_key'] ] = $field['name'];
		}
	}
	return $map;
}

/**
 * Turn a field's `conditional_logic` (OR-groups of AND-rules) into a single
 * JS boolean expression, e.g. `( variant === 'article' ) || ( variant ===
 * 'campaign' )`, for wrapping that field's JSX in `{ <expr> && ( ... ) }`.
 * A rule referencing a `field_key` no longer present in `$key_map` (the
 * target field was deleted/renamed) is silently dropped rather than
 * breaking generation — same leniency the admin UI applies when rebuilding
 * its own dropdowns.
 *
 * @param array                $conditional_logic Sanitized conditional_logic array.
 * @param array<string,string> $key_map           field_key => JS attribute name.
 * @param array<string,array>  $fields_by_key      field_key => normalised field, for type-aware operators.
 * @return string Empty string if there's nothing left to evaluate.
 */
function cb_block_builder_build_condition_expression( $conditional_logic, $key_map, $fields_by_key = array() ) {
	if ( empty( $conditional_logic ) ) {
		return '';
	}

	$group_expressions = array();

	foreach ( $conditional_logic as $group ) {
		$rule_expressions = array();

		foreach ( $group as $rule ) {
			if ( empty( $rule['field'] ) || ! isset( $key_map[ $rule['field'] ] ) ) {
				continue;
			}

			$var          = $key_map[ $rule['field'] ];
			$value        = cb_block_builder_js_str( $rule['value'] );
			$target_field = $fields_by_key[ $rule['field'] ] ?? null;
			$is_numeric   = $target_field && 'number' === $target_field['type'];
			$is_checkbox  = $target_field && 'checkbox' === $target_field['type'];
			$checked      = in_array( strtolower( (string) $rule['value'] ), array( '1', 'true' ), true );

			switch ( $rule['operator'] ) {
				case '==':
					if ( $is_checkbox ) {
						$rule_expressions[] = $checked ? "{$var} === true" : "{$var} === false";
					} elseif ( $is_numeric ) {
						$rule_expressions[] = "Number( {$var} ) === Number( '{$value}' )";
					} else {
						$rule_expressions[] = "{$var} === '{$value}'";
					}
					break;

				case '!=':
					if ( $is_checkbox ) {
						$rule_expressions[] = $checked ? "{$var} !== true" : "{$var} !== false";
					} elseif ( $is_numeric ) {
						$rule_expressions[] = "Number( {$var} ) !== Number( '{$value}' )";
					} else {
						$rule_expressions[] = "{$var} !== '{$value}'";
					}
					break;

				case '>':
					$rule_expressions[] = "Number( {$var} ) > Number( '{$value}' )";
					break;

				case '<':
					$rule_expressions[] = "Number( {$var} ) < Number( '{$value}' )";
					break;

				case '==contains':
					$rule_expressions[] = "String( {$var} ).includes( '{$value}' )";
					break;

				case '!=contains':
					$rule_expressions[] = "! String( {$var} ).includes( '{$value}' )";
					break;

				case '==empty':
					$rule_expressions[] = "! {$var}";
					break;

				case '!=empty':
					$rule_expressions[] = "!! {$var}";
					break;
			}
		}

		if ( $rule_expressions ) {
			$group_expressions[] = count( $rule_expressions ) > 1
				? '( ' . implode( ' && ', $rule_expressions ) . ' )'
				: $rule_expressions[0];
		}
	}

	if ( ! $group_expressions ) {
		return '';
	}

	return count( $group_expressions ) > 1
		? '( ' . implode( ' || ', $group_expressions ) . ' )'
		: $group_expressions[0];
}

/**
 * Build the module-level `{name}Fields` / `{name}EmptyRow` consts a
 * repeater field's JSX references — placed above `export default function
 * Edit` in the generated file, same position as the active theme's own
 * hand-written repeater blocks (e.g. blocks/cb-marquee-stats/src/edit.js).
 *
 * @param array  $field       Normalised field (type 'repeater').
 * @param string $text_domain Active theme's Text Domain.
 * @return string
 */
function cb_block_builder_build_repeater_consts( $field, $text_domain ) {
	$name   = $field['name'];
	$domain = cb_block_builder_js_str( $text_domain );

	$field_lines      = '';
	$empty_row_pairs  = array();

	foreach ( $field['sub_fields'] as $sub_field ) {
		$sub_name  = $sub_field['name'];
		$sub_label = cb_block_builder_js_str( $sub_field['label'] );
		$sub_type  = $sub_field['type'];

		$extra_props_js = '';
		$radio_default  = '';
		if ( 'file' === $sub_type && '' !== $sub_field['mime_types'] ) {
			$mime_items = array();
			foreach ( explode( ',', $sub_field['mime_types'] ) as $mime ) {
				$mime = trim( $mime );
				if ( '' === $mime ) {
					continue;
				}
				$mime_items[] = "'" . cb_block_builder_js_str( $mime ) . "'";
			}
			if ( $mime_items ) {
				$extra_props_js = ', mimeTypes: [ ' . implode( ', ', $mime_items ) . ' ]';
			}
		} elseif ( 'link' === $sub_type && $sub_field['link_target'] ) {
			$extra_props_js = ', linkTarget: true';
		} elseif ( 'radio' === $sub_type && '' !== $sub_field['options'] ) {
			$parsed        = cb_block_builder_parse_field_options( $sub_field['options'] );
			$radio_default = $parsed['default'];
			$option_items  = array();
			foreach ( $parsed['options'] as $option ) {
				$option_js      = cb_block_builder_js_str( $option['value'] );
				$option_items[] = "{ label: '{$option_js}', value: '{$option_js}' }";
			}
			if ( $option_items ) {
				$extra_props_js = ', options: [ ' . implode( ', ', $option_items ) . ' ]';
			}
		}

		$field_lines .= "\t{ name: '{$sub_name}', label: __( '{$sub_label}', '{$domain}' ), type: '{$sub_type}'{$extra_props_js} },\n";

		if ( 'link' === $sub_type ) {
			$empty_row_pairs[] = "{$sub_name}: ''";
			$empty_row_pairs[] = "{$sub_name}Text: ''";
			if ( $sub_field['link_target'] ) {
				$empty_row_pairs[] = "{$sub_name}Target: false";
			}
		} elseif ( 'radio' === $sub_type && '' !== $radio_default ) {
			$empty_row_pairs[] = "{$sub_name}: '" . cb_block_builder_js_str( $radio_default ) . "'";
		} else {
			$default_value     = in_array( $sub_type, array( 'image', 'file', 'number' ), true ) ? '0' : "''";
			$empty_row_pairs[] = "{$sub_name}: {$default_value}";
		}
	}

	$fields_const    = "const {$name}Fields = [\n{$field_lines}];\n";
	$empty_row_const = "const {$name}EmptyRow = { " . implode( ', ', $empty_row_pairs ) . " };\n";

	return $fields_const . "\n" . $empty_row_const;
}

/**
 * Build the `useSelect()` media lookup + remove-image function a gallery
 * field's JSX references, placed inside `Edit()`'s body — same pattern as
 * the active theme's own hand-written CB Client Projects Gallery block.
 *
 * @param array $field Normalised field (type 'gallery').
 * @return string
 */
function cb_block_builder_build_gallery_hooks( $field ) {
	$name      = $field['name'];
	$media_var = "{$name}Media";
	$remove_fn = 'remove' . ucfirst( $name ) . 'Image';

	return "\tconst {$media_var} = useSelect(\n\t\t( select ) =>\n\t\t\t{$name}.length\n\t\t\t\t? {$name}.map( ( id ) => select( coreStore ).getMedia( id ) ).filter( Boolean )\n\t\t\t\t: [],\n\t\t[ {$name} ]\n\t);\n\n\tfunction {$remove_fn}( id ) {\n\t\tsetAttributes( { {$name}: {$name}.filter( ( imageId ) => imageId !== id ) } );\n\t}\n";
}

/**
 * Build src/edit.js.
 *
 * @param array  $fields        Normalised fields.
 * @param string $editor_prefix Sniffed/derived editor-chrome CSS class prefix.
 * @param string $text_domain   Active theme's Text Domain.
 * @param string $title         Block title.
 * @return string
 */
function cb_block_builder_build_edit_js( $fields, $editor_prefix, $text_domain, $title ) {
	$need = array(
		'richtext'        => false,
		'media'           => false,
		'textcontrol'     => false,
		'textareacontrol' => false,
		'selectcontrol'   => false,
		'radiocontrol'    => false,
		'togglecontrol'   => false,
		'button'          => false,
		'repeaterfield'   => false,
		'gallery'         => false,
		'posttypepicker'  => false,
	);

	foreach ( $fields as $field ) {
		switch ( $field['type'] ) {
			case 'text':
			case 'url':
			case 'number':
				$need['textcontrol'] = true;
				break;
			case 'textarea':
				$need['textareacontrol'] = true;
				break;
			case 'richtext':
				$need['richtext'] = true;
				break;
			case 'image':
				$need['media']  = true;
				$need['button'] = true;
				break;
			case 'link':
				$need['textcontrol'] = true;
				if ( $field['link_target'] ) {
					$need['togglecontrol'] = true;
				}
				break;
			case 'select':
				$need['selectcontrol'] = true;
				break;
			case 'radio':
				$need['radiocontrol'] = true;
				break;
			case 'checkbox':
				$need['togglecontrol'] = true;
				break;
			case 'repeater':
				$need['repeaterfield'] = true;
				break;
			case 'gallery':
				$need['media']   = true;
				$need['button']  = true;
				$need['gallery'] = true;
				break;
			case 'post_type':
				$need['posttypepicker'] = true;
				break;
		}
	}

	$block_editor_imports = array( 'useBlockProps' );
	if ( $need['richtext'] ) {
		$block_editor_imports[] = 'RichText';
	}
	if ( $need['media'] ) {
		$block_editor_imports[] = 'MediaUpload';
		$block_editor_imports[] = 'MediaUploadCheck';
	}

	$components_imports = array();
	if ( $need['textcontrol'] ) {
		$components_imports[] = 'TextControl';
	}
	if ( $need['textareacontrol'] ) {
		$components_imports[] = 'TextareaControl';
	}
	if ( $need['selectcontrol'] ) {
		$components_imports[] = 'SelectControl';
	}
	if ( $need['radiocontrol'] ) {
		$components_imports[] = 'RadioControl';
	}
	if ( $need['togglecontrol'] ) {
		$components_imports[] = 'ToggleControl';
	}
	if ( $need['button'] ) {
		$components_imports[] = 'Button';
	}

	$extra_import_lines = array();
	if ( $need['repeaterfield'] ) {
		$extra_import_lines[] = "import RepeaterField from '../../_shared/RepeaterField';";
	}
	if ( $need['gallery'] ) {
		$extra_import_lines[] = "import { useSelect } from '@wordpress/data';";
		$extra_import_lines[] = "import { store as coreStore } from '@wordpress/core-data';";
	}
	if ( $need['posttypepicker'] ) {
		$extra_import_lines[] = "import PostTypePicker from '../../_shared/PostTypePicker';";
	}
	$extra_import_lines[] = "import EditorBlockShell from '../../_shared/EditorBlockShell';";

	$module_consts = array();
	$body_hooks    = array();
	foreach ( $fields as $field ) {
		if ( 'repeater' === $field['type'] ) {
			$module_consts[] = cb_block_builder_build_repeater_consts( $field, $text_domain );
		}
		if ( 'gallery' === $field['type'] ) {
			$body_hooks[] = cb_block_builder_build_gallery_hooks( $field );
		}
	}

	$attr_destructure = array();
	foreach ( $fields as $field ) {
		$name = $field['name'];
		switch ( $field['type'] ) {
			case 'image':
				$attr_destructure[] = "{$name}Id";
				$attr_destructure[] = "{$name}Url";
				$attr_destructure[] = "{$name}Alt";
				break;
			case 'link':
				$attr_destructure[] = "{$name}Text";
				$attr_destructure[] = "{$name}Url";
				if ( $field['link_target'] ) {
					$attr_destructure[] = "{$name}Target";
				}
				break;
			case 'post_type':
				$attr_destructure[] = "{$name}Id";
				break;
			default:
				$attr_destructure[] = $name;
				break;
		}
	}

	$field_key_map = cb_block_builder_build_field_key_map( $fields );
	$fields_by_key = array();
	foreach ( $fields as $field ) {
		if ( ! empty( $field['field_key'] ) ) {
			$fields_by_key[ $field['field_key'] ] = $field;
		}
	}

	$field_html = array();
	foreach ( $fields as $field ) {
		$html = cb_block_builder_build_field_jsx( $field, $editor_prefix, $text_domain );

		$condition_expr = cb_block_builder_build_condition_expression( $field['conditional_logic'] ?? array(), $field_key_map, $fields_by_key );
		if ( '' !== $condition_expr ) {
			$html = "\t\t\t{ {$condition_expr} && (\n{$html}\t\t\t) }\n";
		}

		$field_html[] = $html;
	}

	// Group consecutive non-100%-width fields into flex rows — same
	// algorithm as add_block.sh's second pass over $field_html.
	$controls    = '';
	$i           = 0;
	$field_count = count( $fields );

	while ( $i < $field_count ) {
		$width = $fields[ $i ]['width'];

		if ( 100 === $width ) {
			$controls .= $field_html[ $i ];
			++$i;
			continue;
		}

		$row_html = '';
		$row_sum  = 0;

		while ( $i < $field_count && 100 !== $fields[ $i ]['width'] && $row_sum < 100 ) {
			$w         = $fields[ $i ]['width'];
			$row_html .= "\t\t\t\t<div style={ { flex: '{$w} 1 0%' } }>\n{$field_html[$i]}\t\t\t\t</div>\n";
			$row_sum  += $w;
			++$i;
		}

		$controls .= "\t\t\t<div style={ { display: 'flex', flexWrap: 'wrap', gap: '12px' } }>\n{$row_html}\t\t\t</div>\n";
	}

	$lines   = array();
	$lines[] = "import { __ } from '@wordpress/i18n';";
	$lines[] = 'import { ' . implode( ', ', $block_editor_imports ) . " } from '@wordpress/block-editor';";
	if ( ! empty( $components_imports ) ) {
		$lines[] = 'import { ' . implode( ', ', $components_imports ) . " } from '@wordpress/components';";
	}
	foreach ( $extra_import_lines as $extra_import_line ) {
		$lines[] = $extra_import_line;
	}
	$lines[] = '';

	foreach ( $module_consts as $module_const ) {
		$lines[] = rtrim( $module_const, "\n" );
		$lines[] = '';
	}

	$lines[] = 'export default function Edit( { attributes, setAttributes, clientId } ) {';
	if ( ! empty( $attr_destructure ) ) {
		$lines[] = "\tconst { " . implode( ', ', $attr_destructure ) . ' } = attributes;';
	}
	$lines[] = "\tconst blockProps = useBlockProps( { className: 'container {$editor_prefix}-editor-block' } );";
	foreach ( $body_hooks as $body_hook ) {
		$lines[] = '';
		$lines[] = rtrim( $body_hook, "\n" );
	}
	$lines[] = '';
	$lines[] = "\treturn (";
	$title_js = cb_block_builder_js_str( $title );
	$lines[]  = "\t\t<EditorBlockShell blockProps={ blockProps } clientId={ clientId } classPrefix=\"{$editor_prefix}\" textDomain=\"{$text_domain}\" title=\"{$title_js}\">";
	$lines[]  = rtrim( $controls, "\n" );
	$lines[]  = "\t\t</EditorBlockShell>";
	$lines[]  = "\t);";
	$lines[]  = '}';

	return implode( "\n", $lines ) . "\n";
}

/**
 * Build one field's extract + markup lines for render.php.
 *
 * @param array $field Normalised field.
 * @return array{extract: string, markup: string}
 */
function cb_block_builder_build_field_render( $field ) {
	$name  = $field['name'];
	$snake = $field['snake'];

	switch ( $field['type'] ) {
		case 'text':
		case 'url':
		case 'select':
		case 'radio':
			return array(
				'extract' => "\${$snake} = \$attributes['{$name}'] ?? '';\n",
				'markup'  => "\t<?php if ( \${$snake} ) { ?>\n\t\t<p><?php echo esc_html( \${$snake} ); ?></p>\n\t<?php } ?>\n",
			);

		case 'number':
			return array(
				'extract' => "\${$snake} = \$attributes['{$name}'] ?? 0;\n",
				'markup'  => "\t<?php if ( \${$snake} ) { ?>\n\t\t<p><?php echo esc_html( \${$snake} ); ?></p>\n\t<?php } ?>\n",
			);

		case 'textarea':
			$extract = "\${$snake} = \$attributes['{$name}'] ?? '';\n";
			switch ( $field['textarea_style'] ) {
				case 'list':
					$markup = "\t<?php if ( \${$snake} ) { ?>\n\t\t<ul>\n\t\t\t<?php foreach ( preg_split( '/\\r\\n|\\n|\\r/', \${$snake} ) as \${$snake}_line ) { \${$snake}_line = trim( \${$snake}_line ); if ( '' === \${$snake}_line ) { continue; } ?>\n\t\t\t\t<li><?php echo wp_kses_post( \${$snake}_line ); ?></li>\n\t\t\t<?php } ?>\n\t\t</ul>\n\t<?php } ?>\n";
					break;
				case 'linebreak':
					$markup = "\t<?php if ( \${$snake} ) { ?>\n\t\t<div><?php echo nl2br( esc_html( \${$snake} ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nl2br() output of an already-escaped string. ?></div>\n\t<?php } ?>\n";
					break;
				default:
					$markup = "\t<?php if ( \${$snake} ) { ?>\n\t\t<div><?php echo wp_kses_post( wpautop( \${$snake} ) ); ?></div>\n\t<?php } ?>\n";
					break;
			}
			return array(
				'extract' => $extract,
				'markup'  => $markup,
			);

		case 'richtext':
			return array(
				'extract' => "\${$snake} = \$attributes['{$name}'] ?? '';\n",
				'markup'  => "\t<?php if ( \${$snake} ) { ?>\n\t\t<div><?php echo wp_kses_post( \${$snake} ); ?></div>\n\t<?php } ?>\n",
			);

		case 'checkbox':
			return array(
				'extract' => "\${$snake} = ! empty( \$attributes['{$name}'] );\n",
				'markup'  => '',
			);

		case 'image':
			return array(
				'extract' => "\${$snake}_url = \$attributes['{$name}Url'] ?? '';\n\${$snake}_alt = \$attributes['{$name}Alt'] ?? '';\n",
				'markup'  => "\t<?php if ( \${$snake}_url ) { ?>\n\t\t<img src=\"<?php echo esc_url( \${$snake}_url ); ?>\" alt=\"<?php echo esc_attr( \${$snake}_alt ); ?>\">\n\t<?php } ?>\n",
			);

		case 'link':
			$extract = "\${$snake}_text = \$attributes['{$name}Text'] ?? '';\n\${$snake}_url = \$attributes['{$name}Url'] ?? '';\n";
			if ( $field['link_target'] ) {
				$extract .= "\${$snake}_target = ! empty( \$attributes['{$name}Target'] );\n";
				$markup   = "\t<?php if ( \${$snake}_url ) { ?>\n\t\t<a href=\"<?php echo esc_url( \${$snake}_url ); ?>\"<?php if ( \${$snake}_target ) { ?> target=\"_blank\" rel=\"noopener\"<?php } ?>><?php echo esc_html( \${$snake}_text ? \${$snake}_text : \${$snake}_url ); ?></a>\n\t<?php } ?>\n";
			} else {
				$markup = "\t<?php if ( \${$snake}_url ) { ?>\n\t\t<a href=\"<?php echo esc_url( \${$snake}_url ); ?>\"><?php echo esc_html( \${$snake}_text ? \${$snake}_text : \${$snake}_url ); ?></a>\n\t<?php } ?>\n";
			}
			return array(
				'extract' => $extract,
				'markup'  => $markup,
			);

		case 'gallery':
			return array(
				'extract' => "\${$snake} = \$attributes['{$name}'] ?? array();\n",
				'markup'  => "\t<?php if ( \${$snake} ) { ?>\n\t\t<ul>\n\t\t\t<?php foreach ( \${$snake} as \${$snake}_id ) { \${$snake}_id = absint( \${$snake}_id ); if ( ! \${$snake}_id ) { continue; } ?>\n\t\t\t\t<li><?php echo wp_get_attachment_image( \${$snake}_id, 'large', false, array( 'alt' => '' ) ); ?></li>\n\t\t\t<?php } ?>\n\t\t</ul>\n\t<?php } ?>\n",
			);

		case 'repeater':
			return array(
				'extract' => "\${$snake} = \$attributes['{$name}'] ?? array();\n",
				'markup'  => cb_block_builder_build_repeater_render_markup( $field ),
			);

		case 'post_type':
			return array(
				'extract' => "\${$snake}_id = absint( \$attributes['{$name}Id'] ?? 0 );\n\${$snake} = \${$snake}_id ? get_post( \${$snake}_id ) : null;\n",
				'markup'  => "\t<?php if ( \${$snake} instanceof WP_Post ) { ?>\n\t\t<p><a href=\"<?php echo esc_url( get_permalink( \${$snake}->ID ) ); ?>\"><?php echo esc_html( get_the_title( \${$snake}->ID ) ); ?></a></p>\n\t<?php } ?>\n",
			);
	}

	return array(
		'extract' => '',
		'markup'  => '',
	);
}

/**
 * Build the `foreach` markup for a repeater field's render.php output — a
 * plain `<ul>` of rows, each sub-field rendered with the same escaping
 * convention as its top-level equivalent (esc_html for text, wpautop +
 * wp_kses_post for textarea, wp_get_attachment_image for image, a plain
 * link for file, an `<a>` for link — esc_url/esc_html, plus target/rel when
 * the sub-field's "New tab toggle" option was enabled). A generic default,
 * same "skeleton to hand-finish"
 * spirit as add_block.sh's own single-field output — the actual per-block
 * markup is expected to get finished by hand.
 *
 * @param array $field Normalised field (type 'repeater').
 * @return string
 */
function cb_block_builder_build_repeater_render_markup( $field ) {
	$snake      = $field['snake'];
	$row_markup = '';

	foreach ( $field['sub_fields'] as $sub_field ) {
		$sub_name  = $sub_field['name'];
		$sub_snake = $sub_field['snake'];

		switch ( $sub_field['type'] ) {
			case 'image':
				$row_markup .= "\t\t\t\t<?php \${$sub_snake}_id = ! empty( \$item['{$sub_name}'] ) ? absint( \$item['{$sub_name}'] ) : 0; if ( \${$sub_snake}_id ) { ?>\n\t\t\t\t\t<?php echo wp_get_attachment_image( \${$sub_snake}_id, 'large', false, array( 'alt' => '' ) ); ?>\n\t\t\t\t<?php } ?>\n";
				break;

			case 'file':
				$row_markup .= "\t\t\t\t<?php \${$sub_snake}_id = ! empty( \$item['{$sub_name}'] ) ? absint( \$item['{$sub_name}'] ) : 0; \${$sub_snake}_url = \${$sub_snake}_id ? wp_get_attachment_url( \${$sub_snake}_id ) : ''; if ( \${$sub_snake}_url ) { ?>\n\t\t\t\t\t<a href=\"<?php echo esc_url( \${$sub_snake}_url ); ?>\"><?php echo esc_html( basename( \${$sub_snake}_url ) ); ?></a>\n\t\t\t\t<?php } ?>\n";
				break;

			case 'textarea':
				$row_markup .= "\t\t\t\t<?php \${$sub_snake} = \$item['{$sub_name}'] ?? ''; if ( \${$sub_snake} ) { ?>\n\t\t\t\t\t<p><?php echo wp_kses_post( wpautop( \${$sub_snake} ) ); ?></p>\n\t\t\t\t<?php } ?>\n";
				break;

			case 'link':
				$link_url_var  = "\${$sub_snake}_url";
				$link_text_var = "\${$sub_snake}_text";
				if ( $sub_field['link_target'] ) {
					$target_var  = "\${$sub_snake}_target";
					$row_markup .= "\t\t\t\t<?php {$link_url_var} = \$item['{$sub_name}'] ?? ''; {$link_text_var} = \$item['{$sub_name}Text'] ?? ''; {$target_var} = ! empty( \$item['{$sub_name}Target'] ); if ( {$link_url_var} ) { ?>\n\t\t\t\t\t<a href=\"<?php echo esc_url( {$link_url_var} ); ?>\"<?php if ( {$target_var} ) { ?> target=\"_blank\" rel=\"noopener\"<?php } ?>><?php echo esc_html( {$link_text_var} ? {$link_text_var} : {$link_url_var} ); ?></a>\n\t\t\t\t<?php } ?>\n";
				} else {
					$row_markup .= "\t\t\t\t<?php {$link_url_var} = \$item['{$sub_name}'] ?? ''; {$link_text_var} = \$item['{$sub_name}Text'] ?? ''; if ( {$link_url_var} ) { ?>\n\t\t\t\t\t<a href=\"<?php echo esc_url( {$link_url_var} ); ?>\"><?php echo esc_html( {$link_text_var} ? {$link_text_var} : {$link_url_var} ); ?></a>\n\t\t\t\t<?php } ?>\n";
				}
				break;

			default: // text.
				$row_markup .= "\t\t\t\t<?php \${$sub_snake} = \$item['{$sub_name}'] ?? ''; if ( \${$sub_snake} ) { ?>\n\t\t\t\t\t<p><?php echo esc_html( \${$sub_snake} ); ?></p>\n\t\t\t\t<?php } ?>\n";
				break;
		}
	}

	return "\t<?php if ( \${$snake} ) { ?>\n\t\t<ul>\n\t\t\t<?php foreach ( \${$snake} as \$item ) { ?>\n\t\t\t\t<li>\n{$row_markup}\t\t\t\t</li>\n\t\t\t<?php } ?>\n\t\t</ul>\n\t<?php } ?>\n";
}

/**
 * Build render.php.
 *
 * @param string $title       Block title.
 * @param array  $fields      Normalised fields.
 * @param string $text_domain Active theme's Text Domain.
 * @return string
 */
function cb_block_builder_build_render_php( $title, $fields, $text_domain ) {
	$extract_lines = '';
	$markup_lines  = '';

	foreach ( $fields as $field ) {
		$rendered       = cb_block_builder_build_field_render( $field );
		$extract_lines .= $rendered['extract'];
		$markup_lines  .= $rendered['markup'];
	}

	$title_escaped = str_replace( '*/', '* /', $title );

	$content  = "<?php\n";
	$content .= "/**\n";
	$content .= " * Block template for {$title_escaped}.\n";
	$content .= " *\n";
	$content .= " * @package {$text_domain}\n";
	$content .= " */\n";
	$content .= "\n";
	$content .= "defined( 'ABSPATH' ) || exit;\n";
	$content .= "\n";
	$content .= $extract_lines;
	$content .= "\$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'container' ) );\n";
	$content .= "?>\n";
	$content .= "<section <?php echo \$wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() already escapes. ?>>\n";
	$content .= $markup_lines;
	$content .= "</section>\n";

	// Collapse adjacent close-tag/open-tag pairs left behind by
	// concatenating each field's independent conditional fragment — same
	// style rule add_block.sh enforces via its own perl post-process step.
	$content = preg_replace( '/\?>\s*<\?php/', "\n", $content );

	return $content;
}

/**
 * Generate a full block from a validated config, writing files into the
 * active theme's blocks/ directory via WP_Filesystem.
 *
 * @param array $config Raw config: title, color_support, fields.
 * @return array{slug: string, dir: string}|WP_Error
 */
function cb_block_builder_generate_block( $config ) {
	if ( ! cb_block_builder_is_local_environment() ) {
		return new WP_Error( 'cb_block_builder_not_local', __( 'This only runs on a local development environment.', 'cb-block-builder' ) );
	}

	$theme_context = cb_block_builder_get_theme_context();
	$blocks_dir    = $theme_context['blocks_dir'];

	$validation = cb_block_builder_validate_config( $config, $blocks_dir );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	if ( ! $theme_context['blocks_dir_writable'] ) {
		return new WP_Error( 'cb_block_builder_not_writable', __( 'The active theme\'s blocks/ directory is not writable.', 'cb-block-builder' ) );
	}

	$title = sanitize_text_field( $config['title'] );
	$slug  = cb_block_builder_slugify( $title );

	$fields = array();
	foreach ( (array) ( $config['fields'] ?? array() ) as $raw_field ) {
		$fields[] = cb_block_builder_normalise_field( $raw_field );
	}

	$color_support = ! empty( $config['color_support'] );
	$text_domain   = $theme_context['text_domain'];
	$editor_prefix = $theme_context['editor_prefix'];

	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();

	if ( ! $wp_filesystem ) {
		return new WP_Error( 'cb_block_builder_no_filesystem', __( 'Could not initialise the filesystem.', 'cb-block-builder' ) );
	}

	$block_dir = trailingslashit( $blocks_dir ) . $slug;
	$src_dir   = trailingslashit( $block_dir ) . 'src';

	/*
	 * Explicit group-writable permissions (0775/0664 below), not the
	 * WP_Filesystem/host defaults (FS_CHMOD_DIR/FS_CHMOD_FILE, typically
	 * 0755/0644) — this plugin only ever runs on a local dev environment
	 * (see the environment gate above and README.md's "Local-only, by
	 * design"), where the block files it writes as the web server user
	 * (www-data/_www/whatever the local stack uses) still need to be
	 * writable afterward by the developer's own CLI user running `npm run
	 * blocks:build` — the two are different OS users sharing one theme
	 * checkout. Relying on the host's umask to make that "just work" is
	 * exactly what silently broke on a fresh box before this was added
	 * (EACCES on the generated block's build/ dir): a umask fix lives in
	 * host/webserver config, never travels with the project, and has to be
	 * manually redone on every new machine or project checkout. Explicit
	 * chmod here ships with the plugin's own code instead, so it's correct
	 * on any machine the plugin is installed on, out of the box. This still
	 * assumes the CLI dev user is in the web server's group (the standard
	 * local-LAMP/MAMP-style setup this plugin already targets) — if that's
	 * not true on some future box, group-writable files don't help either,
	 * but that's a one-time `usermod -aG` away and outside what a WordPress
	 * plugin can set up for you.
	 */
	if ( ! $wp_filesystem->mkdir( $block_dir, 0775 ) || ! $wp_filesystem->mkdir( $src_dir, 0775 ) ) {
		return new WP_Error( 'cb_block_builder_mkdir_failed', __( 'Could not create the block directory.', 'cb-block-builder' ) );
	}

	$block_json = cb_block_builder_build_block_json( $title, $slug, $text_domain, $fields, $color_support );
	$index_js   = cb_block_builder_build_index_js();
	$edit_js    = cb_block_builder_build_edit_js( $fields, $editor_prefix, $text_domain, $title );
	$render_php = cb_block_builder_build_render_php( $title, $fields, $text_domain );

	$sidecar_json = cb_block_builder_build_sidecar_json( $title, $color_support, $fields );

	$writes = array(
		trailingslashit( $block_dir ) . 'block.json'            => $block_json,
		trailingslashit( $src_dir ) . 'index.js'                 => $index_js,
		trailingslashit( $src_dir ) . 'edit.js'                   => $edit_js,
		trailingslashit( $block_dir ) . 'render.php'             => $render_php,
		trailingslashit( $block_dir ) . '.block-builder.json'    => $sidecar_json,
	);

	foreach ( $writes as $path => $contents ) {
		if ( ! $wp_filesystem->put_contents( $path, $contents, 0664 ) ) {
			return new WP_Error(
				'cb_block_builder_write_failed',
				// translators: %s: file path that failed to write.
				sprintf( __( 'Could not write %s.', 'cb-block-builder' ), $path )
			);
		}
	}

	return array(
		'slug' => $slug,
		'dir'  => $block_dir,
	);
}

/**
 * Regenerate an existing block's `src/edit.js`, patch its `block.json`
 * `attributes`/`supports` in place, and rewrite its `.block-builder.json`
 * sidecar from an edited field designer config — this is what makes
 * adding, removing, resizing, or reordering fields actually take effect in
 * the block editor.
 *
 * `block.json` is deliberately *patched*, not rebuilt from scratch: only
 * `attributes` and `supports` are ever touched, every other key (name,
 * category, icon, editorScript, render, and anything the plugin doesn't
 * know about at all — viewScript, style, keywords, description, etc.) is
 * carried over from the file exactly as it already was. An earlier version
 * of this function rebuilt the whole object via
 * cb_block_builder_build_attributes_array()'s companion
 * cb_block_builder_build_block_json(), which silently dropped any key
 * outside its fixed schema — that's what caused a real hand-written
 * block's `viewScript`/`style` to vanish from block.json after an edit;
 * never repeat that mistake here.
 *
 * `render.php` is left alone entirely: none of what the field designer
 * edits (field presence aside) affects its output — width/order are
 * edit.js-only flex-layout concerns — and it's the file most likely to
 * carry hand-written markup/classes worth keeping. Overwriting `src/edit.js`
 * does still cost you any hand edits made there since the block was first
 * generated — no way around that if the regenerated JSX is to reflect the
 * new field list at all. Adding a brand new field still needs its render
 * markup added to `render.php` by hand, same as at initial creation — see
 * README "Editing an existing block's fields".
 *
 * @param string $slug   Existing block's slug (its blocks/{slug} directory).
 * @param array  $config Raw config: color_support, fields.
 * @return array{slug: string, dir: string, title: string}|WP_Error
 */
function cb_block_builder_regenerate_block( $slug, $config ) {
	if ( ! cb_block_builder_is_local_environment() ) {
		return new WP_Error( 'cb_block_builder_not_local', __( 'This only runs on a local development environment.', 'cb-block-builder' ) );
	}

	$theme_context   = cb_block_builder_get_theme_context();
	$block_dir       = trailingslashit( $theme_context['blocks_dir'] ) . $slug;
	$src_dir         = trailingslashit( $block_dir ) . 'src';
	$block_json_path = trailingslashit( $block_dir ) . 'block.json';

	if ( ! file_exists( $block_json_path ) ) {
		return new WP_Error( 'cb_block_builder_edit_missing', __( 'That block no longer exists.', 'cb-block-builder' ) );
	}

	if ( null === cb_block_builder_read_sidecar_config( $block_dir ) ) {
		return new WP_Error( 'cb_block_builder_edit_no_sidecar', __( 'This block wasn\'t created by Block Builder (or predates edit support), so it can\'t be edited here.', 'cb-block-builder' ) );
	}

	$validation = cb_block_builder_validate_edit_config( $config );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	if ( ! $theme_context['blocks_dir_writable'] ) {
		return new WP_Error( 'cb_block_builder_not_writable', __( 'The active theme\'s blocks/ directory is not writable.', 'cb-block-builder' ) );
	}

	$fields = array();
	foreach ( (array) ( $config['fields'] ?? array() ) as $raw_field ) {
		$fields[] = cb_block_builder_normalise_field( $raw_field );
	}

	$color_support = ! empty( $config['color_support'] );

	// Title never comes from the edit form (it's locked read-only in the UI
	// since renaming would move the block to a different slug) — read it
	// back from the block's own existing block.json instead of trusting
	// whatever the client submitted.
	$block_json_data = json_decode( (string) file_get_contents( $block_json_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file, not remote/user input.

	if ( ! is_array( $block_json_data ) || empty( $block_json_data['title'] ) ) {
		return new WP_Error( 'cb_block_builder_edit_bad_json', __( 'Could not read this block\'s existing block.json.', 'cb-block-builder' ) );
	}

	$title         = (string) $block_json_data['title'];
	$text_domain   = $theme_context['text_domain'];
	$editor_prefix = $theme_context['editor_prefix'];

	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();

	if ( ! $wp_filesystem ) {
		return new WP_Error( 'cb_block_builder_no_filesystem', __( 'Could not initialise the filesystem.', 'cb-block-builder' ) );
	}

	if ( ! file_exists( $src_dir ) && ! $wp_filesystem->mkdir( $src_dir, 0775 ) ) {
		return new WP_Error( 'cb_block_builder_mkdir_failed', __( 'Could not create the block\'s src/ directory.', 'cb-block-builder' ) );
	}

	// Patch only the two keys the field designer actually owns — every
	// other key already in block.json (including ones the plugin itself
	// would normally set on a fresh block, like editorScript/render, and
	// ones it never touches at all, like viewScript/style) is carried over
	// untouched. See this function's docblock for why a wholesale rebuild
	// here is wrong.
	$block_json_data['attributes'] = (object) cb_block_builder_build_attributes_array( $fields );
	$block_json_data['supports']   = cb_block_builder_build_supports( $color_support );

	$writes = array(
		$block_json_path                                      => cb_block_builder_json_encode_tabs( $block_json_data ) . "\n",
		trailingslashit( $src_dir ) . 'index.js'              => cb_block_builder_build_index_js(),
		trailingslashit( $src_dir ) . 'edit.js'                => cb_block_builder_build_edit_js( $fields, $editor_prefix, $text_domain, $title ),
		trailingslashit( $block_dir ) . '.block-builder.json' => cb_block_builder_build_sidecar_json( $title, $color_support, $fields ),
	);

	foreach ( $writes as $path => $contents ) {
		if ( ! $wp_filesystem->put_contents( $path, $contents, 0664 ) ) {
			return new WP_Error(
				'cb_block_builder_write_failed',
				// translators: %s: file path that failed to write.
				sprintf( __( 'Could not write %s.', 'cb-block-builder' ), $path )
			);
		}
	}

	return array(
		'slug'  => $slug,
		'dir'   => $block_dir,
		'title' => $title,
	);
}

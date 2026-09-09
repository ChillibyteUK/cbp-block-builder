<?php
/**
 * Admin menu + page rendering. Only ever required when
 * cb_block_builder_is_local_environment() is true — see cb-block-builder.php.
 *
 * @package cb-block-builder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the top-level admin menu.
 *
 * @return void
 */
function cb_block_builder_register_menu() {
	add_menu_page(
		__( 'Block Builder', 'cb-block-builder' ),
		__( 'Block Builder', 'cb-block-builder' ),
		'manage_options',
		'cb-block-builder',
		'cb_block_builder_render_page',
		'dashicons-layout',
		81
	);
}
add_action( 'admin_menu', 'cb_block_builder_register_menu' );

/**
 * Enqueue admin assets, this screen only.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function cb_block_builder_enqueue_assets( $hook_suffix ) {
	if ( 'toplevel_page_cb-block-builder' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style( 'cb-block-builder-admin', CB_BLOCK_BUILDER_URL . 'css/admin.css', array(), CB_BLOCK_BUILDER_VERSION );

	// Framework-free, same [data-tabs] contract as the active theme's own
	// Site-Wide Settings tabs — a separate copy since the plugin can't rely
	// on theme JS being present.
	wp_enqueue_script( 'cb-block-builder-tabs', CB_BLOCK_BUILDER_URL . 'js/tabs.js', array(), CB_BLOCK_BUILDER_VERSION, true );

	wp_enqueue_script( 'cb-block-builder-field-designer', CB_BLOCK_BUILDER_URL . 'js/field-designer.js', array(), CB_BLOCK_BUILDER_VERSION, true );

	$editing = cb_block_builder_get_editing_block();

	wp_localize_script(
		'cb-block-builder-field-designer',
		'lcBlockBuilder',
		array(
			'existingSlugs'         => cb_block_builder_get_existing_slugs(),
			'conditionOperators'    => cb_block_builder_conditional_operators(),
			'conditionTargetTypes'  => cb_block_builder_conditional_target_types(),
			'conditionLabels'       => array(
				'showIf' => __( 'Show this field if', 'cb-block-builder' ),
				'or'     => __( 'or', 'cb-block-builder' ),
			),
			'editing'               => $editing ? array(
				'slug'   => $editing['slug'],
				'fields' => $editing['fields'],
			) : null,
		)
	);
}
add_action( 'admin_enqueue_scripts', 'cb_block_builder_enqueue_assets' );

/**
 * Look up the block named by `?edit={slug}`, if any, and load its
 * `.block-builder.json` sidecar — the data needed to re-open it in the
 * Design New panel for an attributes-only edit. Returns null for a missing
 * slug, a slug with no matching block, or a block with no sidecar (created
 * before edit support existed, or not created by this plugin at all).
 *
 * @return array{slug: string, title: string, color_support: bool, fields: array}|null
 */
function cb_block_builder_get_editing_block() {
	if ( empty( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup, not a state change.
		return null;
	}

	$slug = sanitize_key( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup, not a state change.

	if ( '' === $slug ) {
		return null;
	}

	$theme_context = cb_block_builder_get_theme_context();
	$block_dir     = trailingslashit( $theme_context['blocks_dir'] ) . $slug;
	$config        = cb_block_builder_read_sidecar_config( $block_dir );

	if ( null === $config ) {
		return null;
	}

	$config['slug'] = $slug;

	return $config;
}

/**
 * Read every existing block's title/slug/attribute count from the active
 * theme, for the read-only "already exists" list and client-side duplicate-
 * slug warnings.
 *
 * @return array[]
 */
function cb_block_builder_get_existing_blocks() {
	$theme_context = cb_block_builder_get_theme_context();
	$blocks        = array();

	foreach ( (array) glob( trailingslashit( $theme_context['blocks_dir'] ) . '*/block.json' ) as $block_json_path ) {
		$data = json_decode( (string) file_get_contents( $block_json_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file, not remote/user input.

		if ( ! is_array( $data ) ) {
			continue;
		}

		$block_dir = dirname( $block_json_path );

		$blocks[] = array(
			'title'      => $data['title'] ?? basename( $block_dir ),
			'slug'       => basename( $block_dir ),
			'attr_count' => isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? count( $data['attributes'] ) : 0,
			'editable'   => null !== cb_block_builder_read_sidecar_config( $block_dir ),
		);
	}

	return $blocks;
}

/**
 * @return string[]
 */
function cb_block_builder_get_existing_slugs() {
	return wp_list_pluck( cb_block_builder_get_existing_blocks(), 'slug' );
}

/**
 * Render (and consume) the one-shot notice left by the admin-post handler's
 * redirect, if any — see cb_block_builder_redirect_with_notice().
 *
 * @return void
 */
function cb_block_builder_render_notice() {
	$key    = 'cb_block_builder_notice_' . get_current_user_id();
	$notice = get_transient( $key );

	if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
		return;
	}

	delete_transient( $key );

	$type = 'success' === $notice['type'] ? 'success' : 'error';
	?>
	<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
		<p><?php echo esc_html( $notice['message'] ); ?></p>
	</div>
	<?php
}

/**
 * Field types offered in the designer, in dropdown order.
 *
 * @return array<string, string>
 */
function cb_block_builder_field_type_labels() {
	return array(
		'text'     => __( 'Text', 'cb-block-builder' ),
		'textarea' => __( 'Textarea', 'cb-block-builder' ),
		'richtext' => __( 'Rich Text', 'cb-block-builder' ),
		'image'    => __( 'Image', 'cb-block-builder' ),
		'gallery'  => __( 'Gallery (multiple images)', 'cb-block-builder' ),
		'url'      => __( 'URL', 'cb-block-builder' ),
		'link'     => __( 'Link', 'cb-block-builder' ),
		'number'   => __( 'Number', 'cb-block-builder' ),
		'select'   => __( 'Select', 'cb-block-builder' ),
		'radio'    => __( 'Radio', 'cb-block-builder' ),
		'checkbox' => __( 'Checkbox', 'cb-block-builder' ),
		'file'     => __( 'File', 'cb-block-builder' ),
		'repeater'  => __( 'Repeater', 'cb-block-builder' ),
		'post_type' => __( 'Post Type (single post picker)', 'cb-block-builder' ),
	);
}

/**
 * Post types offered to a "Post Type" field's picker — every registered
 * public post type (covers project-specific CPTs like `product`, plus
 * core's own `post`/`page`), keyed by slug. A dropdown of what's actually
 * registered, rather than a free-text slug, since a typo there wouldn't be
 * caught until cb_block_builder_validate_config()'s post_type_exists()
 * check rejects the whole submission.
 *
 * @return array<string, string>
 */
function cb_block_builder_post_type_choices() {
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	$choices    = array();

	foreach ( $post_types as $post_type ) {
		if ( 'attachment' === $post_type->name ) {
			continue;
		}
		$choices[ $post_type->name ] = $post_type->label;
	}

	return $choices;
}

/**
 * Sub-field types a repeater field can offer, matching the active theme's
 * own blocks/_shared/RepeaterField.js vocabulary exactly.
 *
 * @return array<string, string>
 */
function cb_block_builder_subfield_type_labels() {
	return array(
		'text'     => __( 'Text', 'cb-block-builder' ),
		'textarea' => __( 'Textarea', 'cb-block-builder' ),
		'image'    => __( 'Image', 'cb-block-builder' ),
		'file'     => __( 'File', 'cb-block-builder' ),
		'link'     => __( 'Link', 'cb-block-builder' ),
		'radio'    => __( 'Radio', 'cb-block-builder' ),
	);
}

/**
 * Render the "Design New" tab panel: the block designer form.
 *
 * @param bool $active Whether this panel should start visible.
 * @return void
 */
function cb_block_builder_render_design_new_panel( $active, $editing = null ) {
	$field_types      = cb_block_builder_field_type_labels();
	$subfield_types   = cb_block_builder_subfield_type_labels();
	$post_type_choices = cb_block_builder_post_type_choices();
	?>
	<div class="cb-block-builder-tabs__panel" data-tabs-panel="design-new" <?php echo $active ? '' : 'hidden'; ?>>
		<?php if ( $editing ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: block title. */
						esc_html__( 'Editing "%s" — saving regenerates block.json and src/edit.js from these fields (any hand edits to src/edit.js will be overwritten). render.php isn\'t touched — check it still matches afterward.', 'cb-block-builder' ),
						esc_html( $editing['title'] )
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cb-block-builder-form">
			<?php wp_nonce_field( 'cb_block_builder_generate' ); ?>
			<input type="hidden" name="action" value="cb_block_builder_generate">
			<input type="hidden" name="fields_json" id="cb-block-builder-fields-json" value="[]">
			<?php if ( $editing ) : ?>
				<input type="hidden" name="editing_slug" value="<?php echo esc_attr( $editing['slug'] ); ?>">
			<?php endif; ?>

			<div class="cb-block-builder-field-row">
				<label for="cb-block-builder-title"><?php esc_html_e( 'Block title', 'cb-block-builder' ); ?></label>
				<input type="text" id="cb-block-builder-title" name="title" class="regular-text" value="<?php echo $editing ? esc_attr( $editing['title'] ) : ''; ?>" <?php echo $editing ? 'readonly' : ''; ?> required>
				<p class="description">
					<?php echo $editing ? esc_html__( 'Existing block at:', 'cb-block-builder' ) : esc_html__( 'Will be created at:', 'cb-block-builder' ); ?>
					<code>blocks/<span id="cb-block-builder-slug-preview"><?php echo $editing ? esc_html( $editing['slug'] ) : '&hellip;'; ?></span>/</code>
				</p>
			</div>

			<div class="cb-block-builder-field-row">
				<label>
					<input type="checkbox" name="color_support" value="1" <?php checked( ! empty( $editing['color_support'] ) ); ?>>
					<?php esc_html_e( 'This block supports background/text colour (Gutenberg colour panel)', 'cb-block-builder' ); ?>
				</label>
			</div>

			<h2><?php esc_html_e( 'Fields', 'cb-block-builder' ); ?></h2>
			<div id="cb-block-builder-rows" class="cb-block-builder-rows"></div>
			<p>
				<button type="button" class="button button-secondary" id="cb-block-builder-add-row"><?php esc_html_e( 'Add field', 'cb-block-builder' ); ?></button>
			</p>

			<?php submit_button( $editing ? __( 'Update Attributes', 'cb-block-builder' ) : __( 'Create Block', 'cb-block-builder' ) ); ?>
		</form>

		<template id="cb-block-builder-row-template">
			<div class="cb-block-builder-row">
				<div class="cb-block-builder-row__number"></div>
				<div class="cb-block-builder-row__main">
					<div class="cb-block-builder-row__grid">
						<label>
							<?php esc_html_e( 'Label', 'cb-block-builder' ); ?>
							<input type="text" class="cb-block-builder-field-label" value="">
						</label>
						<label>
							<?php esc_html_e( 'Type', 'cb-block-builder' ); ?>
							<select class="cb-block-builder-field-type">
								<?php foreach ( $field_types as $value => $field_label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $field_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<?php esc_html_e( 'Help text', 'cb-block-builder' ); ?>
							<input type="text" class="cb-block-builder-field-help" value="">
						</label>
						<label>
							<?php esc_html_e( 'Width', 'cb-block-builder' ); ?>
							<select class="cb-block-builder-field-width">
								<option value="100">100%</option>
								<option value="50">50%</option>
								<option value="33">33%</option>
								<option value="25">25%</option>
							</select>
						</label>
					</div>
					<div class="cb-block-builder-row__conditional" data-for="select radio">
						<label>
							<?php esc_html_e( 'Options (comma-separated)', 'cb-block-builder' ); ?>
							<input type="text" class="cb-block-builder-field-options" value="">
						</label>
						<p class="description"><?php esc_html_e( 'Wrap one option in [brackets] to make it the default; otherwise the first option is used.', 'cb-block-builder' ); ?></p>
					</div>
					<div class="cb-block-builder-row__conditional" data-for="textarea">
						<label>
							<?php esc_html_e( 'Textarea style', 'cb-block-builder' ); ?>
							<select class="cb-block-builder-field-textarea-style">
								<option value="paragraph"><?php esc_html_e( 'Paragraph', 'cb-block-builder' ); ?></option>
								<option value="list"><?php esc_html_e( 'List', 'cb-block-builder' ); ?></option>
								<option value="linebreak"><?php esc_html_e( 'Line breaks', 'cb-block-builder' ); ?></option>
							</select>
						</label>
					</div>
					<div class="cb-block-builder-row__conditional" data-for="link">
						<label>
							<input type="checkbox" class="cb-block-builder-field-link-target">
							<?php esc_html_e( 'Add an "open in new tab" toggle', 'cb-block-builder' ); ?>
						</label>
					</div>
					<div class="cb-block-builder-row__conditional" data-for="file">
						<label>
							<?php esc_html_e( 'Allowed file types (extensions, comma-separated)', 'cb-block-builder' ); ?>
							<input type="text" class="cb-block-builder-field-allowed-extensions" placeholder="pdf, doc, docx">
						</label>
						<p class="description"><?php esc_html_e( 'Leave blank to allow any file type. Matched by extension, same convention as ACF\'s file field.', 'cb-block-builder' ); ?></p>
					</div>
					<div class="cb-block-builder-row__conditional" data-for="post_type">
						<label>
							<?php esc_html_e( 'Post type', 'cb-block-builder' ); ?>
							<select class="cb-block-builder-field-post-type-slug">
								<option value=""><?php esc_html_e( '— Select —', 'cb-block-builder' ); ?></option>
								<?php foreach ( $post_type_choices as $slug => $post_type_label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $post_type_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<p class="description"><?php esc_html_e( 'Only post types registered on this site are listed — a custom post type must already exist before a block can pick from it.', 'cb-block-builder' ); ?></p>
					</div>
					<div class="cb-block-builder-row__conditional" data-for="repeater">
						<label>
							<?php esc_html_e( 'Row layout', 'cb-block-builder' ); ?>
							<select class="cb-block-builder-field-repeater-layout">
								<option value="row"><?php esc_html_e( 'Row — sub-fields side by side, shared column headers', 'cb-block-builder' ); ?></option>
								<option value="column"><?php esc_html_e( 'Column — sub-fields stacked, each with its own visible label', 'cb-block-builder' ); ?></option>
							</select>
						</label>
						<label class="cb-block-builder-subfields-label"><?php esc_html_e( 'Sub-fields', 'cb-block-builder' ); ?></label>
						<div class="cb-block-builder-subfields"></div>
						<button type="button" class="button cb-block-builder-add-subfield"><?php esc_html_e( 'Add sub-field', 'cb-block-builder' ); ?></button>
					</div>
					<div class="cb-block-builder-row__conditions">
						<label>
							<input type="checkbox" class="cb-block-builder-conditions-toggle">
							<?php esc_html_e( 'Conditional logic', 'cb-block-builder' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only show this field in the block editor when the rules below are met. Other top-level fields can be referenced; repeater sub-fields cannot.', 'cb-block-builder' ); ?></p>
						<div class="cb-block-builder-conditions" hidden>
							<div class="cb-block-builder-condition-groups"></div>
							<button type="button" class="button cb-block-builder-add-condition-group"><?php esc_html_e( 'Add rule group ("or")', 'cb-block-builder' ); ?></button>
						</div>
					</div>
				</div>
				<div class="cb-block-builder-row__actions">
					<button type="button" class="button cb-block-builder-move-up" title="<?php esc_attr_e( 'Move up', 'cb-block-builder' ); ?>">&#9650;</button>
					<button type="button" class="button cb-block-builder-move-down" title="<?php esc_attr_e( 'Move down', 'cb-block-builder' ); ?>">&#9660;</button>
					<button type="button" class="button cb-block-builder-remove-row" title="<?php esc_attr_e( 'Remove', 'cb-block-builder' ); ?>">&times;</button>
				</div>
			</div>
		</template>

		<template id="cb-block-builder-subfield-row-template">
			<div class="cb-block-builder-subfield-row">
				<input type="text" class="cb-block-builder-subfield-label" placeholder="<?php esc_attr_e( 'Sub-field label', 'cb-block-builder' ); ?>">
				<select class="cb-block-builder-subfield-type">
					<?php foreach ( $subfield_types as $value => $subfield_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $subfield_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" class="cb-block-builder-subfield-mime" placeholder="<?php esc_attr_e( 'MIME types, e.g. application/pdf', 'cb-block-builder' ); ?>" hidden>
				<label class="cb-block-builder-subfield-link-target" hidden>
					<input type="checkbox" class="cb-block-builder-subfield-link-target-input">
					<?php esc_html_e( 'New tab toggle', 'cb-block-builder' ); ?>
				</label>
				<input type="text" class="cb-block-builder-subfield-options" placeholder="<?php esc_attr_e( 'Options, comma-separated — [bracket] one for the default', 'cb-block-builder' ); ?>" hidden>
				<button type="button" class="button cb-block-builder-remove-subfield" title="<?php esc_attr_e( 'Remove', 'cb-block-builder' ); ?>">&times;</button>
			</div>
		</template>

		<template id="cb-block-builder-condition-group-template">
			<div class="cb-block-builder-condition-group">
				<h4 class="cb-block-builder-condition-group__heading"></h4>
				<div class="cb-block-builder-condition-rules"></div>
				<button type="button" class="button-link cb-block-builder-add-condition-rule"><?php esc_html_e( 'and', 'cb-block-builder' ); ?></button>
				<button type="button" class="button-link cb-block-builder-remove-condition-group"><?php esc_html_e( 'Remove group', 'cb-block-builder' ); ?></button>
			</div>
		</template>

		<template id="cb-block-builder-condition-rule-template">
			<div class="cb-block-builder-condition-rule">
				<select class="cb-block-builder-condition-field"></select>
				<select class="cb-block-builder-condition-operator"></select>
				<span class="cb-block-builder-condition-value-wrap">
					<input type="text" class="cb-block-builder-condition-value">
				</span>
				<button type="button" class="button cb-block-builder-remove-condition-rule" title="<?php esc_attr_e( 'Remove', 'cb-block-builder' ); ?>">&times;</button>
			</div>
		</template>
	</div>
	<?php
}

/**
 * Render the "Blocks" tab panel: theme/environment status + the read-only
 * existing-blocks list.
 *
 * @param bool $active Whether this panel should start visible.
 * @return void
 */
function cb_block_builder_render_blocks_panel( $active ) {
	$theme_context = cb_block_builder_get_theme_context();
	$existing      = cb_block_builder_get_existing_blocks();
	$npm_detected  = cb_block_builder_detect_npm();
	?>
	<div class="cb-block-builder-tabs__panel" data-tabs-panel="blocks" <?php echo $active ? '' : 'hidden'; ?>>
		<p>
			<?php
			printf(
				/* translators: 1: theme name, 2: blocks directory path. */
				esc_html__( 'Active theme: %1$s — new blocks are written to %2$s.', 'cb-block-builder' ),
				'<strong>' . esc_html( $theme_context['name'] ) . '</strong>',
				'<code>' . esc_html( $theme_context['blocks_dir'] ) . '</code>'
			);
			?>
		</p>

		<?php if ( ! $theme_context['has_block_autoloader'] ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This theme\'s inc/blocks.php doesn\'t look like it auto-registers blocks/*/block.json — the generated block may not be picked up automatically.', 'cb-block-builder' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $theme_context['editor_prefix_sniffed'] ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: fallback CSS class prefix. */
						esc_html__( 'Could not find an existing "-editor-block" rule in this theme\'s src/css/editor.css — falling back to "%s" for generated editor-chrome classes. Generated blocks may not have matching editor styles.', 'cb-block-builder' ),
						esc_html( $theme_context['editor_prefix'] )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<?php if ( $npm_detected ) : ?>
				<span class="cb-block-builder-npm-status cb-block-builder-npm-status--ok">✓ <?php esc_html_e( 'npm detected — run npm run blocks:build after generating.', 'cb-block-builder' ); ?></span>
			<?php else : ?>
				<span class="cb-block-builder-npm-status cb-block-builder-npm-status--unknown"><?php esc_html_e( 'npm not detected automatically — remember to run npm run blocks:build (or blocks:start) in the theme directory yourself.', 'cb-block-builder' ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $existing ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'cb-block-builder' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'cb-block-builder' ); ?></th>
						<th><?php esc_html_e( 'Fields', 'cb-block-builder' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $existing as $block ) : ?>
						<tr>
							<td><?php echo esc_html( $block['title'] ); ?></td>
							<td><code><?php echo esc_html( $block['slug'] ); ?></code></td>
							<td><?php echo esc_html( $block['attr_count'] ); ?></td>
							<td>
								<?php if ( $block['editable'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'cb-block-builder', 'edit' => $block['slug'] ), admin_url( 'admin.php' ) ) ); ?>">
										<?php esc_html_e( 'Edit fields', 'cb-block-builder' ); ?>
									</a>
								<?php else : ?>
									<span class="cb-block-builder-custom-badge" title="<?php esc_attr_e( 'This block has no Block Builder sidecar — it was hand-written (or predates edit support) and isn\'t editable here.', 'cb-block-builder' ); ?>">
										<?php esc_html_e( 'Custom', 'cb-block-builder' ); ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No blocks in this theme yet.', 'cb-block-builder' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render the Block Builder admin page.
 *
 * Tabbed using the same [data-tabs] contract as the active theme's own
 * Site-Wide Settings page (js/tabs.js) — "Design New" and "Blocks" today,
 * with room for more tabs later without restructuring anything.
 *
 * @return void
 */
function cb_block_builder_render_page() {
	?>
	<div class="wrap cb-block-builder">
		<h1><?php esc_html_e( 'Block Builder', 'cb-block-builder' ); ?></h1>
		<?php cb_block_builder_render_notice(); ?>

		<div class="cb-block-builder-card">
			<div class="cb-block-builder-tabs" data-tabs>
				<h2 class="nav-tab-wrapper" data-tabs-nav>
					<a href="#" class="nav-tab nav-tab-active" data-tabs-target="design-new"><?php esc_html_e( 'Design New', 'cb-block-builder' ); ?></a>
					<a href="#" class="nav-tab" data-tabs-target="blocks"><?php esc_html_e( 'Blocks', 'cb-block-builder' ); ?></a>
				</h2>

				<?php
				cb_block_builder_render_design_new_panel( true, cb_block_builder_get_editing_block() );
				cb_block_builder_render_blocks_panel( false );
				?>
			</div>
		</div>
	</div>
	<?php
}

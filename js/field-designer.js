/**
 * Field-row add/remove/reorder UI for the Block Builder admin page, plus
 * live slug preview. Framework-free (no jQuery, no wp.media) — nothing here
 * needs either, same reasoning the active theme's own js/tabs.js documents
 * for staying dependency-free where nothing forces a dependency.
 *
 * On submit, every row's state is read out of the DOM and JSON-serialized
 * into one hidden field (#cb-block-builder-fields-json) rather than posted
 * as nested bracket-notation fields — this is a one-shot "generate and
 * redirect" form with no need to survive a redisplay-after-save cycle, so
 * there's no reason to mirror the active theme's own wp-admin repeater
 * pattern (js/repeater-field.js), which exists specifically to serve that
 * different need.
 *
 * @package cb-block-builder
 */
( function () {
	'use strict';

	function slugify( title ) {
		return title
			.toLowerCase()
			.replace( / /g, '-' )
			.replace( /[^a-z0-9-]/g, '' );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'cb-block-builder-form' );
		if ( ! form ) {
			return;
		}

		var rowsWrap         = document.getElementById( 'cb-block-builder-rows' );
		var addRowButton     = document.getElementById( 'cb-block-builder-add-row' );
		var titleInput       = document.getElementById( 'cb-block-builder-title' );
		var slugPreview      = document.getElementById( 'cb-block-builder-slug-preview' );
		var fieldsJsonInput  = document.getElementById( 'cb-block-builder-fields-json' );
		var rowTemplate      = document.getElementById( 'cb-block-builder-row-template' );
		var subfieldTemplate = document.getElementById( 'cb-block-builder-subfield-row-template' );
		var existingSlugs    = ( window.lcBlockBuilder && window.lcBlockBuilder.existingSlugs ) || [];

		function renumberRows() {
			var rows = rowsWrap.querySelectorAll( '.cb-block-builder-row' );
			rows.forEach( function ( row, index ) {
				row.querySelector( '.cb-block-builder-row__number' ).textContent = index + 1;
			} );
		}

		function updateConditionalVisibility( row ) {
			var type = row.querySelector( '.cb-block-builder-field-type' ).value;
			row.querySelectorAll( '.cb-block-builder-row__conditional' ).forEach( function ( conditional ) {
				conditional.hidden = conditional.getAttribute( 'data-for' ) !== type;
			} );
		}

		function updateSubfieldConditionalVisibility( subfieldRow ) {
			var type = subfieldRow.querySelector( '.cb-block-builder-subfield-type' ).value;
			subfieldRow.querySelector( '.cb-block-builder-subfield-mime' ).hidden = 'file' !== type;
			subfieldRow.querySelector( '.cb-block-builder-subfield-link-target' ).hidden = 'link' !== type;
		}

		function bindSubfieldRow( subfieldRow ) {
			subfieldRow.querySelector( '.cb-block-builder-subfield-type' ).addEventListener( 'change', function () {
				updateSubfieldConditionalVisibility( subfieldRow );
			} );

			subfieldRow.querySelector( '.cb-block-builder-remove-subfield' ).addEventListener( 'click', function () {
				subfieldRow.remove();
			} );

			updateSubfieldConditionalVisibility( subfieldRow );
		}

		function addSubfieldRow( subfieldsWrap ) {
			var fragment = subfieldTemplate.content.cloneNode( true );
			var subfieldRow = fragment.querySelector( '.cb-block-builder-subfield-row' );
			subfieldsWrap.appendChild( subfieldRow );
			bindSubfieldRow( subfieldRow );
		}

		function bindRow( row ) {
			row.querySelector( '.cb-block-builder-field-type' ).addEventListener( 'change', function () {
				updateConditionalVisibility( row );
			} );

			row.querySelector( '.cb-block-builder-remove-row' ).addEventListener( 'click', function () {
				row.remove();
				renumberRows();
			} );

			row.querySelector( '.cb-block-builder-move-up' ).addEventListener( 'click', function () {
				var prev = row.previousElementSibling;
				if ( prev ) {
					rowsWrap.insertBefore( row, prev );
					renumberRows();
				}
			} );

			row.querySelector( '.cb-block-builder-move-down' ).addEventListener( 'click', function () {
				var next = row.nextElementSibling;
				if ( next ) {
					rowsWrap.insertBefore( next, row );
					renumberRows();
				}
			} );

			var addSubfieldButton = row.querySelector( '.cb-block-builder-add-subfield' );
			if ( addSubfieldButton ) {
				addSubfieldButton.addEventListener( 'click', function () {
					addSubfieldRow( row.querySelector( '.cb-block-builder-subfields' ) );
				} );
			}

			updateConditionalVisibility( row );
		}

		function addRow() {
			var fragment = rowTemplate.content.cloneNode( true );
			var row = fragment.querySelector( '.cb-block-builder-row' );
			rowsWrap.appendChild( row );
			bindRow( row );
			renumberRows();
		}

		addRowButton.addEventListener( 'click', addRow );

		if ( titleInput && slugPreview ) {
			titleInput.addEventListener( 'input', function () {
				var slug = slugify( titleInput.value );
				slugPreview.textContent = slug || '…';
				slugPreview.style.color = existingSlugs.indexOf( slug ) !== -1 ? '#d63638' : '';
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			var rows = rowsWrap.querySelectorAll( '.cb-block-builder-row' );
			var fields = [];
			var errors = [];

			rows.forEach( function ( row, index ) {
				var label = row.querySelector( '.cb-block-builder-field-label' ).value.trim();
				var type = row.querySelector( '.cb-block-builder-field-type' ).value;

				if ( '' === label ) {
					errors.push( 'Field #' + ( index + 1 ) + ' needs a label.' );
				}

				var field = {
					label: label,
					type: type,
					help: row.querySelector( '.cb-block-builder-field-help' ).value.trim(),
					width: parseInt( row.querySelector( '.cb-block-builder-field-width' ).value, 10 ),
					options: row.querySelector( '.cb-block-builder-field-options' ).value.trim(),
					textarea_style: row.querySelector( '.cb-block-builder-field-textarea-style' ).value,
					link_target: row.querySelector( '.cb-block-builder-field-link-target' ).checked,
					post_type_slug: row.querySelector( '.cb-block-builder-field-post-type-slug' ).value,
				};

				if ( 'post_type' === type && '' === field.post_type_slug ) {
					errors.push( 'Field #' + ( index + 1 ) + ' (' + ( label || 'post type' ) + ') needs a post type selected.' );
				}

				if ( 'repeater' === type ) {
					field.repeater_layout = row.querySelector( '.cb-block-builder-field-repeater-layout' ).value;

					var subFields = [];
					row.querySelectorAll( '.cb-block-builder-subfield-row' ).forEach( function ( subfieldRow ) {
						var subLabel = subfieldRow.querySelector( '.cb-block-builder-subfield-label' ).value.trim();
						if ( '' === subLabel ) {
							return;
						}
						subFields.push( {
							label: subLabel,
							type: subfieldRow.querySelector( '.cb-block-builder-subfield-type' ).value,
							mime_types: subfieldRow.querySelector( '.cb-block-builder-subfield-mime' ).value.trim(),
							link_target: subfieldRow.querySelector( '.cb-block-builder-subfield-link-target-input' ).checked,
						} );
					} );

					if ( ! subFields.length ) {
						errors.push( 'Field #' + ( index + 1 ) + ' (' + ( label || 'repeater' ) + ') needs at least one sub-field.' );
					}

					field.sub_fields = subFields;
				}

				fields.push( field );
			} );

			if ( errors.length ) {
				event.preventDefault();
				window.alert( errors.join( '\n' ) ); // eslint-disable-line no-alert
				return;
			}

			fieldsJsonInput.value = JSON.stringify( fields );
		} );
	} );
} )();

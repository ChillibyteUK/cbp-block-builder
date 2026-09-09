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
		var rowTemplate           = document.getElementById( 'cb-block-builder-row-template' );
		var subfieldTemplate      = document.getElementById( 'cb-block-builder-subfield-row-template' );
		var conditionGroupTemplate = document.getElementById( 'cb-block-builder-condition-group-template' );
		var conditionRuleTemplate  = document.getElementById( 'cb-block-builder-condition-rule-template' );
		var existingSlugs        = ( window.lcBlockBuilder && window.lcBlockBuilder.existingSlugs ) || [];
		var conditionOperators   = ( window.lcBlockBuilder && window.lcBlockBuilder.conditionOperators ) || {};
		var conditionTargetTypes = ( window.lcBlockBuilder && window.lcBlockBuilder.conditionTargetTypes ) || [];
		var conditionLabels      = ( window.lcBlockBuilder && window.lcBlockBuilder.conditionLabels ) || { showIf: 'Show this field if', or: 'or' };

		// Which operators make sense per target-field type — a JS-side mirror
		// of cb_block_builder_conditional_operators()'s applicability, kept
		// deliberately small (the PHP side is the source of truth for the
		// operator vocabulary itself, this only narrows which of those apply
		// to which field type).
		var OPERATORS_BY_TYPE = {
			text: [ '==', '!=', '==contains', '!=contains', '==empty', '!=empty' ],
			textarea: [ '==', '!=', '==contains', '!=contains', '==empty', '!=empty' ],
			url: [ '==', '!=', '==contains', '!=contains', '==empty', '!=empty' ],
			number: [ '==', '!=', '>', '<' ],
			select: [ '==', '!=' ],
			radio: [ '==', '!=' ],
			checkbox: [ '==', '!=' ],
		};

		// Parse a comma-separated select/radio options string the same way
		// cb_block_builder_parse_field_options() does, minus the default-
		// tracking (not needed here) — just the ordered, [bracket]-stripped
		// option values, for building the conditional-logic "value" dropdown.
		function parseOptionValues( raw ) {
			return ( raw || '' )
				.split( ',' )
				.map( function ( option ) {
					return option.trim().replace( /^\[|\]$/g, '' );
				} )
				.filter( function ( option ) {
					return '' !== option;
				} );
		}

		function generateFieldKey() {
			return 'field_' + Math.random().toString( 36 ).slice( 2, 10 );
		}

		// Every other top-level row whose field type is a valid conditional-
		// logic target, as { fieldKey, label, type, options }. `label`/`type`
		// are read live off the DOM so a row being edited right now is
		// reflected immediately in every other row's "Field" dropdown.
		function getConditionChoices( excludingRow ) {
			var choices = [];
			rowsWrap.querySelectorAll( '.cb-block-builder-row' ).forEach( function ( row ) {
				if ( row === excludingRow ) {
					return;
				}
				var type = row.querySelector( '.cb-block-builder-field-type' ).value;
				if ( conditionTargetTypes.indexOf( type ) === -1 ) {
					return;
				}
				choices.push( {
					fieldKey: row.dataset.fieldKey,
					label: row.querySelector( '.cb-block-builder-field-label' ).value.trim() || '(untitled field)',
					type: type,
					options: row.querySelector( '.cb-block-builder-field-options' ).value,
				} );
			} );
			return choices;
		}

		function renderConditionFieldSelect( select, excludingRow, selectedKey ) {
			var choices = getConditionChoices( excludingRow );
			select.innerHTML = '';

			choices.forEach( function ( choice ) {
				var option = document.createElement( 'option' );
				option.value = choice.fieldKey;
				option.textContent = choice.label;
				if ( choice.fieldKey === selectedKey ) {
					option.selected = true;
				}
				select.appendChild( option );
			} );

			return choices;
		}

		function renderConditionOperatorSelect( select, targetType, selectedOperator ) {
			var allowed = OPERATORS_BY_TYPE[ targetType ] || OPERATORS_BY_TYPE.text;
			select.innerHTML = '';

			allowed.forEach( function ( operator ) {
				var option = document.createElement( 'option' );
				option.value = operator;
				option.textContent = conditionOperators[ operator ] || operator;
				if ( operator === selectedOperator ) {
					option.selected = true;
				}
				select.appendChild( option );
			} );

			if ( allowed.indexOf( selectedOperator ) === -1 ) {
				select.value = allowed[ 0 ];
			}
		}

		// Swap the rule's value control between a plain text input and a
		// dropdown of the target field's own options, based on its type —
		// the value-input half of ACF's renderValue(), simplified to the two
		// shapes this plugin's field types need.
		function renderConditionValueInput( ruleRow, choice, selectedValue ) {
			var wrap = ruleRow.querySelector( '.cb-block-builder-condition-value-wrap' );
			wrap.innerHTML = '';

			var control;

			if ( choice && 'checkbox' === choice.type ) {
				control = document.createElement( 'select' );
				control.className = 'cb-block-builder-condition-value';
				[ [ '1', 'Checked' ], [ '0', 'Not checked' ] ].forEach( function ( pair ) {
					var option = document.createElement( 'option' );
					option.value = pair[ 0 ];
					option.textContent = pair[ 1 ];
					control.appendChild( option );
				} );
			} else if ( choice && ( 'select' === choice.type || 'radio' === choice.type ) ) {
				control = document.createElement( 'select' );
				control.className = 'cb-block-builder-condition-value';
				parseOptionValues( choice.options ).forEach( function ( value ) {
					var option = document.createElement( 'option' );
					option.value = value;
					option.textContent = value;
					control.appendChild( option );
				} );
			} else {
				control = document.createElement( 'input' );
				control.type = 'number' === ( choice && choice.type ) ? 'number' : 'text';
				control.className = 'cb-block-builder-condition-value';
			}

			control.value = selectedValue || '';
			wrap.appendChild( control );
		}

		function refreshConditionRule( ruleRow, excludingRow ) {
			var fieldSelect = ruleRow.querySelector( '.cb-block-builder-condition-field' );
			var previousKey  = fieldSelect.value;
			var choices      = renderConditionFieldSelect( fieldSelect, excludingRow, previousKey );
			var choice       = choices.filter( function ( c ) {
				return c.fieldKey === fieldSelect.value;
			} )[ 0 ];

			var operatorSelect  = ruleRow.querySelector( '.cb-block-builder-condition-operator' );
			var previousOperator = operatorSelect.value;
			renderConditionOperatorSelect( operatorSelect, choice ? choice.type : 'text', previousOperator );

			var valueInput = ruleRow.querySelector( '.cb-block-builder-condition-value' );
			renderConditionValueInput( ruleRow, choice, valueInput ? valueInput.value : '' );
		}

		function bindConditionRule( ruleRow, row ) {
			ruleRow.querySelector( '.cb-block-builder-condition-field' ).addEventListener( 'change', function () {
				refreshConditionRule( ruleRow, row );
			} );

			ruleRow.querySelector( '.cb-block-builder-remove-condition-rule' ).addEventListener( 'click', function () {
				ruleRow.remove();
			} );
		}

		function addConditionRule( rulesWrap, row, ruleData ) {
			var fragment = conditionRuleTemplate.content.cloneNode( true );
			var ruleRow = fragment.querySelector( '.cb-block-builder-condition-rule' );
			rulesWrap.appendChild( ruleRow );
			bindConditionRule( ruleRow, row );

			var fieldSelect = ruleRow.querySelector( '.cb-block-builder-condition-field' );
			renderConditionFieldSelect( fieldSelect, row, ruleData && ruleData.field );
			if ( ruleData && ruleData.field ) {
				fieldSelect.value = ruleData.field;
			}

			var choices = getConditionChoices( row );
			var choice  = choices.filter( function ( c ) {
				return c.fieldKey === fieldSelect.value;
			} )[ 0 ];

			var operatorSelect = ruleRow.querySelector( '.cb-block-builder-condition-operator' );
			renderConditionOperatorSelect( operatorSelect, choice ? choice.type : 'text', ruleData && ruleData.operator );

			renderConditionValueInput( ruleRow, choice, ruleData && ruleData.value );

			return ruleRow;
		}

		function bindConditionGroup( groupEl, row ) {
			groupEl.querySelector( '.cb-block-builder-add-condition-rule' ).addEventListener( 'click', function () {
				addConditionRule( groupEl.querySelector( '.cb-block-builder-condition-rules' ), row );
			} );

			groupEl.querySelector( '.cb-block-builder-remove-condition-group' ).addEventListener( 'click', function () {
				groupEl.remove();
			} );
		}

		function addConditionGroup( row, groupData ) {
			var groupsWrap = row.querySelector( '.cb-block-builder-condition-groups' );
			var fragment = conditionGroupTemplate.content.cloneNode( true );
			var groupEl = fragment.querySelector( '.cb-block-builder-condition-group' );
			groupsWrap.appendChild( groupEl );
			bindConditionGroup( groupEl, row );

			var heading = groupEl.querySelector( '.cb-block-builder-condition-group__heading' );
			heading.textContent = 0 === groupsWrap.children.length - 1 ? conditionLabels.showIf : conditionLabels.or;

			var rulesWrap = groupEl.querySelector( '.cb-block-builder-condition-rules' );
			var rules = ( groupData && groupData.length ) ? groupData : [ null ];
			rules.forEach( function ( ruleData ) {
				addConditionRule( rulesWrap, row, ruleData );
			} );

			return groupEl;
		}

		function refreshAllConditionChoices( excludingRow ) {
			rowsWrap.querySelectorAll( '.cb-block-builder-row' ).forEach( function ( otherRow ) {
				if ( otherRow === excludingRow ) {
					return;
				}
				otherRow.querySelectorAll( '.cb-block-builder-condition-rule' ).forEach( function ( ruleRow ) {
					refreshConditionRule( ruleRow, otherRow );
				} );
			} );
		}

		// Read a row's condition groups/rules back out of the DOM into the
		// OR-groups-of-AND-rules shape the backend expects, dropping any
		// rule with no field selected and any group left with zero rules —
		// same cleanup ACF does server-side, done client-side here since
		// this plugin has no redisplay-after-save cycle to protect against.
		function readConditionalLogic( row ) {
			var toggle = row.querySelector( '.cb-block-builder-conditions-toggle' );
			if ( ! toggle || ! toggle.checked ) {
				return [];
			}

			var groups = [];
			row.querySelectorAll( '.cb-block-builder-condition-group' ).forEach( function ( groupEl ) {
				var rules = [];
				groupEl.querySelectorAll( '.cb-block-builder-condition-rule' ).forEach( function ( ruleRow ) {
					var field = ruleRow.querySelector( '.cb-block-builder-condition-field' ).value;
					if ( ! field ) {
						return;
					}
					rules.push( {
						field: field,
						operator: ruleRow.querySelector( '.cb-block-builder-condition-operator' ).value,
						value: ruleRow.querySelector( '.cb-block-builder-condition-value' ).value,
					} );
				} );
				if ( rules.length ) {
					groups.push( rules );
				}
			} );

			return groups;
		}

		function bindConditions( row ) {
			var toggle    = row.querySelector( '.cb-block-builder-conditions-toggle' );
			var container = row.querySelector( '.cb-block-builder-conditions' );

			toggle.addEventListener( 'change', function () {
				container.hidden = ! toggle.checked;
				if ( toggle.checked && ! row.querySelector( '.cb-block-builder-condition-group' ) ) {
					addConditionGroup( row );
				}
			} );

			row.querySelector( '.cb-block-builder-add-condition-group' ).addEventListener( 'click', function () {
				addConditionGroup( row );
			} );
		}

		function renumberRows() {
			var rows = rowsWrap.querySelectorAll( '.cb-block-builder-row' );
			rows.forEach( function ( row, index ) {
				row.querySelector( '.cb-block-builder-row__number' ).textContent = index + 1;
			} );
		}

		function updateConditionalVisibility( row ) {
			var type = row.querySelector( '.cb-block-builder-field-type' ).value;
			row.querySelectorAll( '.cb-block-builder-row__conditional' ).forEach( function ( conditional ) {
				var dataFor = conditional.getAttribute( 'data-for' ).split( ' ' );
				conditional.hidden = dataFor.indexOf( type ) === -1;
			} );
		}

		function updateSubfieldConditionalVisibility( subfieldRow ) {
			var type = subfieldRow.querySelector( '.cb-block-builder-subfield-type' ).value;
			subfieldRow.querySelector( '.cb-block-builder-subfield-mime' ).hidden = 'file' !== type;
			subfieldRow.querySelector( '.cb-block-builder-subfield-link-target' ).hidden = 'link' !== type;
			subfieldRow.querySelector( '.cb-block-builder-subfield-options' ).hidden = 'radio' !== type;
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
				refreshAllConditionChoices( row );
			} );

			row.querySelector( '.cb-block-builder-field-label' ).addEventListener( 'input', function () {
				refreshAllConditionChoices( row );
			} );

			row.querySelector( '.cb-block-builder-field-options' ).addEventListener( 'input', function () {
				refreshAllConditionChoices( row );
			} );

			bindConditions( row );

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
			row.dataset.fieldKey = generateFieldKey();
			rowsWrap.appendChild( row );
			bindRow( row );
			renumberRows();
			return row;
		}

		// Re-hydrate a freshly-added row/sub-field row from a previously
		// saved field (from the .block-builder.json sidecar, passed down
		// via lcBlockBuilder.editing) — the reverse of the submit
		// handler's DOM-to-JSON read below.
		function populateRow( row, field ) {
			if ( field.field_key ) {
				row.dataset.fieldKey = field.field_key;
			}

			row.querySelector( '.cb-block-builder-field-label' ).value = field.label || '';
			row.querySelector( '.cb-block-builder-field-type' ).value = field.type || 'text';
			row.querySelector( '.cb-block-builder-field-help' ).value = field.help || '';
			row.querySelector( '.cb-block-builder-field-width' ).value = String( field.width || 100 );
			row.querySelector( '.cb-block-builder-field-options' ).value = field.options || '';
			row.querySelector( '.cb-block-builder-field-textarea-style' ).value = field.textarea_style || 'paragraph';
			row.querySelector( '.cb-block-builder-field-link-target' ).checked = !! field.link_target;
			row.querySelector( '.cb-block-builder-field-post-type-slug' ).value = field.post_type_slug || '';
			row.querySelector( '.cb-block-builder-field-allowed-extensions' ).value = field.allowed_extensions || '';

			if ( 'repeater' === field.type ) {
				row.querySelector( '.cb-block-builder-field-repeater-layout' ).value = field.repeater_layout || 'row';

				var subfieldsWrap = row.querySelector( '.cb-block-builder-subfields' );
				( field.sub_fields || [] ).forEach( function ( subField ) {
					addSubfieldRow( subfieldsWrap );
					var subRow = subfieldsWrap.lastElementChild;
					subRow.querySelector( '.cb-block-builder-subfield-label' ).value = subField.label || '';
					subRow.querySelector( '.cb-block-builder-subfield-type' ).value = subField.type || 'text';
					subRow.querySelector( '.cb-block-builder-subfield-mime' ).value = subField.mime_types || '';
					subRow.querySelector( '.cb-block-builder-subfield-link-target-input' ).checked = !! subField.link_target;
					subRow.querySelector( '.cb-block-builder-subfield-options' ).value = subField.options || '';
					updateSubfieldConditionalVisibility( subRow );
				} );
			}

			updateConditionalVisibility( row );
		}

		addRowButton.addEventListener( 'click', addRow );

		var editing = window.lcBlockBuilder && window.lcBlockBuilder.editing;
		if ( editing && editing.fields ) {
			var rows = editing.fields.map( function ( field ) {
				var row = addRow();
				populateRow( row, field );
				return row;
			} );

			// Condition rule dropdowns list *other* rows as choices, so they
			// can only be rebuilt correctly once every row (and its
			// field_key/label/type) exists — hence this second pass, after
			// the loop above has added and populated every row.
			rows.forEach( function ( row, index ) {
				var field = editing.fields[ index ];
				if ( field.conditional_logic && field.conditional_logic.length ) {
					row.querySelector( '.cb-block-builder-conditions-toggle' ).checked = true;
					row.querySelector( '.cb-block-builder-conditions' ).hidden = false;
					field.conditional_logic.forEach( function ( group ) {
						addConditionGroup( row, group );
					} );
				}
			} );
		}

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
					allowed_extensions: row.querySelector( '.cb-block-builder-field-allowed-extensions' ).value.trim(),
					field_key: row.dataset.fieldKey,
					conditional_logic: readConditionalLogic( row ),
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
							options: subfieldRow.querySelector( '.cb-block-builder-subfield-options' ).value.trim(),
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

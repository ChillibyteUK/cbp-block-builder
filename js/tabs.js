/**
 * Generic tab switching — pairs [data-tabs-target] nav links with
 * [data-tabs-panel] panels sharing the same value, inside any [data-tabs]
 * container. Same markup contract as the active theme's own js/tabs.js
 * (Site-Wide Settings' tabbed sections) — this is a separate copy, not a
 * shared dependency, since the plugin can't rely on theme JS being present.
 *
 * Framework-free on purpose — nothing here needs jQuery or wp.media.
 *
 * @package cb-block-builder
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-tabs]' ).forEach( function ( tabs ) {
		var links = tabs.querySelectorAll( '[data-tabs-target]' );
		var panels = tabs.querySelectorAll( '[data-tabs-panel]' );

		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				var target = link.getAttribute( 'data-tabs-target' );

				links.forEach( function ( otherLink ) {
					otherLink.classList.toggle( 'nav-tab-active', otherLink === link );
				} );

				panels.forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-tabs-panel' ) !== target;
				} );
			} );
		} );
	} );
} )();

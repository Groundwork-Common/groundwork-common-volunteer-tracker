/**
 * The logo chooser on the Letter settings tab.
 *
 * Hand-written, no build step. Uses wp.media, which WordPress already ships and
 * which every administrator already knows how to drive — a bespoke uploader
 * here would be a second, worse media library.
 */
( function ( wp ) {
	'use strict';

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
			return;
		}
		document.addEventListener( 'DOMContentLoaded', fn );
	}

	function setUp( root ) {
		var field = root.querySelector( 'input[type="hidden"]' );
		var preview = root.querySelector( '.gwcvt-media__preview' );
		var image = preview ? preview.querySelector( 'img' ) : null;
		var choose = root.querySelector( '.gwcvt-media__choose' );
		var remove = root.querySelector( '.gwcvt-media__remove' );
		var frame = null;

		if ( ! field || ! choose || ! wp || ! wp.media ) {
			return;
		}

		choose.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: choose.getAttribute( 'data-gwcvt-title' ) || 'Choose a logo',
					button: { text: choose.getAttribute( 'data-gwcvt-button' ) || 'Use this' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var picked = frame.state().get( 'selection' ).first().toJSON();
					var url = picked.url;

					/* Prefer a sized version. The letterhead caps the height in
					 * CSS either way, but shipping a 4000px original inside an
					 * email is rude to whoever opens it on a phone. */
					if ( picked.sizes && picked.sizes.medium ) {
						url = picked.sizes.medium.url;
					}

					field.value = String( picked.id );

					if ( image ) {
						image.src = url;
					}
					if ( preview ) {
						preview.hidden = false;
					}
					if ( remove ) {
						remove.hidden = false;
					}
				} );
			}

			frame.open();
		} );

		if ( remove ) {
			remove.addEventListener( 'click', function () {
				field.value = '0';
				if ( preview ) {
					preview.hidden = true;
				}
				remove.hidden = true;
			} );
		}
	}

	ready( function () {
		var fields = document.querySelectorAll( '[data-gwcvt-media]' );
		for ( var i = 0; i < fields.length; i++ ) {
			setUp( fields[ i ] );
		}
	} );
}( window.wp ) );

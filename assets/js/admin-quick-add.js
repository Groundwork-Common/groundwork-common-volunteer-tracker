/**
 * "Add more rows" on the log-a-day screen.
 *
 * Hand-written, no build step. The only thing this does is clone a row — the
 * pickers inside it are wired by admin-picker.js, which binds per element
 * rather than per known ID, so a cloned row works as soon as its ids are made
 * unique.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
			return;
		}
		document.addEventListener( 'DOMContentLoaded', fn );
	}

	ready( function () {
		var button = document.getElementById( 'gwcvt-qa-more' );
		var body = document.getElementById( 'gwcvt-qa-rows' );

		if ( ! button || ! body ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var rows = body.querySelectorAll( '.gwcvt-quick-add__row' );
			var last = rows[ rows.length - 1 ];

			for ( var added = 0; added < 4; added++ ) {
				var clone = last.cloneNode( true );
				var index = body.querySelectorAll( '.gwcvt-quick-add__row' ).length;

				/* Every id, for, and aria-controls has to be made unique or the
				 * labels point at the first row and a screen reader announces
				 * every new field as "Volunteer for row 1". */
				clone.querySelectorAll( '[id]' ).forEach( function ( el ) {
					el.id = el.id.replace( /-\d+$/, '-' + index );
				} );
				clone.querySelectorAll( '[for]' ).forEach( function ( el ) {
					el.setAttribute( 'for', el.getAttribute( 'for' ).replace( /-\d+$/, '-' + index ) );
				} );
				clone.querySelectorAll( '[aria-controls]' ).forEach( function ( el ) {
					el.setAttribute( 'aria-controls', el.getAttribute( 'aria-controls' ).replace( /-\d+$/, '-' + index ) );
				} );

				clone.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = 'hidden' === input.type ? '0' : '';
				} );

				/* The source row is already wired, so it carries the "done"
				 * mark — and cloneNode copies it. Left on, the new row would be
				 * skipped by setUpAll() and its picker would never respond to a
				 * keystroke. */
				clone.querySelectorAll( '[data-gwcvt-ready]' ).forEach( function ( el ) {
					el.removeAttribute( 'data-gwcvt-ready' );
				} );

				var results = clone.querySelector( '.gwcvt-picker__results' );
				if ( results ) {
					results.hidden = true;
					results.textContent = '';
				}

				body.appendChild( clone );
				last = clone;
			}

			/* Re-run the picker's wiring over the new rows. It is idempotent per
			 * element only in the sense that it binds once per call, so the
			 * event it listens for is dispatched rather than the setup repeated
			 * across the whole page. */
			document.dispatchEvent( new CustomEvent( 'gwc-vt:pickers-added' ) );
		} );
	} );
}() );

/**
 * The volunteer picker on the hour entry screen.
 *
 * Hand-written, no build step, no framework — see README.md. ES5 with a couple
 * of guarded modern calls, because this only ever runs in wp-admin and the
 * plugin's PHP floor says nothing about the browser.
 *
 * Every node here is built with createElement and textContent. Never innerHTML:
 * the strings being rendered are volunteer names, which are typed by staff and
 * — for a self-logged entry — partly by the public, and a name is exactly the
 * kind of value nobody thinks of as markup until somebody types a tag into it.
 */
( function ( wp ) {
	'use strict';

	var MIN_CHARS = 2;
	var DEBOUNCE_MS = 220;

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
			return;
		}
		document.addEventListener( 'DOMContentLoaded', fn );
	}

	function setUp( root ) {
		var search = root.querySelector( 'input[type="text"]' );
		var hidden = root.querySelector( 'input[type="hidden"]' );
		var list = root.querySelector( '.gwcvt-picker__results' );

		if ( ! search || ! hidden || ! list ) {
			return;
		}

		var timer = null;
		var active = -1;
		var items = [];

		function close() {
			list.hidden = true;
			list.textContent = '';
			search.setAttribute( 'aria-expanded', 'false' );
			active = -1;
			items = [];
		}

		function choose( item ) {
			hidden.value = String( item.id );
			search.value = item.label;
			close();
		}

		function highlight( next ) {
			var options = list.querySelectorAll( 'li' );
			if ( ! options.length ) {
				return;
			}

			active = ( next + options.length ) % options.length;

			for ( var i = 0; i < options.length; i++ ) {
				var on = i === active;
				options[ i ].classList.toggle( 'is-active', on );
				options[ i ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
			}
		}

		function render( results ) {
			list.textContent = '';
			items = results;

			if ( ! results.length ) {
				var empty = document.createElement( 'li' );
				empty.className = 'gwcvt-picker__empty';
				empty.textContent = root.getAttribute( 'data-gwcvt-empty' ) || 'No matches';
				list.appendChild( empty );
				list.hidden = false;
				search.setAttribute( 'aria-expanded', 'true' );
				return;
			}

			results.forEach( function ( item, index ) {
				var option = document.createElement( 'li' );
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );
				option.tabIndex = -1;
				option.textContent = item.label;

				option.addEventListener( 'mousedown', function ( event ) {
					// mousedown, not click: blur fires first and would close the list.
					event.preventDefault();
					choose( item );
				} );

				option.addEventListener( 'mouseenter', function () {
					highlight( index );
				} );

				list.appendChild( option );
			} );

			list.hidden = false;
			search.setAttribute( 'aria-expanded', 'true' );
		}

		function lookup( term ) {
			wp.apiFetch( {
				path: '/gwcvt/v1/volunteers?search=' + encodeURIComponent( term )
			} ).then( function ( results ) {
				// The field may have moved on while the request was in flight.
				if ( search.value.trim() === term ) {
					render( Array.isArray( results ) ? results : [] );
				}
			} ).catch( function () {
				close();
			} );
		}

		search.addEventListener( 'input', function () {
			/* Typing after a choice un-chooses it. Without this, correcting a
			 * name but not picking from the list again would submit the previous
			 * volunteer's ID with a different name showing above it — a wrong
			 * record that looks right. */
			hidden.value = '';

			var term = search.value.trim();

			window.clearTimeout( timer );

			if ( term.length < MIN_CHARS ) {
				close();
				return;
			}

			timer = window.setTimeout( function () {
				lookup( term );
			}, DEBOUNCE_MS );
		} );

		search.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden ) {
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( active + 1 );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( active - 1 );
			} else if ( 'Enter' === event.key && active > -1 && items[ active ] ) {
				event.preventDefault();
				choose( items[ active ] );
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		search.addEventListener( 'blur', function () {
			window.setTimeout( close, 120 );
		} );
	}

	ready( function () {
		var pickers = document.querySelectorAll( '[data-gwcvt-picker]' );
		for ( var i = 0; i < pickers.length; i++ ) {
			setUp( pickers[ i ] );
		}
	} );
}( window.wp || {} ) );

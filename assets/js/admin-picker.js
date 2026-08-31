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

		/* Optional, and outside this element: a hidden field the SEARCH text is
		 * mirrored into, so a form that reloads itself can put the words back in
		 * the box. The box itself deliberately has no name — a named text field
		 * is one a browser remembers and re-fills on the next visit, and this one
		 * would come back holding somebody's name on a screen that produces
		 * letters about people. Looked up in the form rather than in the picker,
		 * because the picker's own hidden field is found by position above. */
		var form = root.closest ? root.closest( 'form' ) : null;
		var typed = form ? form.querySelector( 'input[data-gwcvt-typed]' ) : null;

		function remember() {
			if ( typed ) {
				typed.value = search.value;
			}
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
			remember();
			close();
		}

		/* ── Naming somebody who is not on file ──────────────────────────────
		 * Opt-in, on an attribute. One script draws every picker in this plugin
		 * — the entry editor, both roster boxes, Log a day — and only the one
		 * that is about work already done should be able to bring a person into
		 * existence. The others must not quietly start creating people.
		 *
		 * Nothing is written here. This fills the row and nothing else, so a
		 * name typed and then thought better of leaves no record behind; the
		 * volunteer is created by the handler when the sheet is saved. */
		var canCreate = root.hasAttribute( 'data-gwcvt-can-create' );

		/* No second field to carry the name: on a picker that can create, the
		 * visible box is itself named and posts what is in it. Choosing this
		 * option therefore only has to make sure no volunteer ID goes with it —
		 * the handler reads an ID where there is one and the typed name where
		 * there is not. */
		function chooseNew( name ) {
			hidden.value = '0';
			search.value = name;
			remember();
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

		/* The last option, when the typed name matches nothing this site knows.
		 * Built with createElement and textContent like everything else here —
		 * the value being rendered is a name somebody typed, and a name is
		 * exactly the kind of value nobody thinks of as markup until somebody
		 * types a tag into it. */
		function appendCreate( name ) {
			if ( ! canCreate || ! name ) {
				return;
			}

			var option = document.createElement( 'li' );
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			option.className = 'gwcvt-picker__create';
			option.tabIndex = -1;

			var template = root.getAttribute( 'data-gwcvt-create' ) || 'Add %s as a new volunteer';

			option.textContent = template.replace( '%s', '“' + name + '”' );

			option.addEventListener( 'mousedown', function ( event ) {
				event.preventDefault();
				chooseNew( name );
			} );

			option.addEventListener( 'mouseenter', function () {
				highlight( items.length );
			} );

			list.appendChild( option );

			/* Reachable by keyboard as well as by pointer. items carries a
			 * sentinel so ArrowDown and Enter walk onto it like any other row —
			 * without this the option is mouse-only, which on a screen somebody
			 * is typing eleven names into is no option at all. */
			items.push( { create: name } );
		}

		function render( results ) {
			list.textContent = '';
			items = results.slice();

			var term = search.value.trim();

			if ( ! results.length ) {
				var empty = document.createElement( 'li' );
				empty.className = 'gwcvt-picker__empty';
				empty.textContent = root.getAttribute( 'data-gwcvt-empty' ) || 'No matches';
				list.appendChild( empty );

				appendCreate( term );

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

			/* Offered under the matches as well as instead of them: "Dana" may
			 * find Dana Achebe and still not be the Dana standing at the desk. */
			appendCreate( term );

			list.hidden = false;
			search.setAttribute( 'aria-expanded', 'true' );
		}

		/* Pickers that are about work already done say so on the element, and
		 * only those get volunteers who are no longer active. A roster must not: see the
		 * note on the route in inc/rest.php. */
		var inactive = root.hasAttribute( 'data-gwcvt-inactive' ) ? '&inactive=1' : '';

		function lookup( term ) {
			wp.apiFetch( {
				path: '/gwc-vt/v1/volunteers?search=' + encodeURIComponent( term ) + inactive
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
			remember();

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

				if ( items[ active ].create ) {
					chooseNew( items[ active ].create );
				} else {
					choose( items[ active ] );
				}
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		search.addEventListener( 'blur', function () {
			window.setTimeout( close, 120 );
		} );
	}

	function setUpAll() {
		var pickers = document.querySelectorAll( '[data-gwcvt-picker]' );
		for ( var i = 0; i < pickers.length; i++ ) {
			/* Marked once wired. The log-a-day screen adds rows after load and
			 * asks for another pass; without the mark, every existing picker
			 * would gain a second set of handlers and fire two lookups per
			 * keystroke. */
			if ( pickers[ i ].getAttribute( 'data-gwcvt-ready' ) ) {
				continue;
			}
			pickers[ i ].setAttribute( 'data-gwcvt-ready', '1' );
			setUp( pickers[ i ] );
		}
	}

	ready( setUpAll );
	document.addEventListener( 'gwc-vt:pickers-added', setUpAll );
}( window.wp || {} ) );

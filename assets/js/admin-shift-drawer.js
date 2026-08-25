/**
 * One shift, in a panel beside the calendar.
 *
 * Hand-written, no build step. Enhancement only, and the ordering is what makes
 * that true: every chip and every row on the schedule is already an <a href> to
 * the shift editor, rendered by PHP, working with no script at all. This file
 * intercepts the click. Remove it and the schedule is what it was.
 *
 * ── It renders nothing ───────────────────────────────────────────────────────
 * The panel comes back from gwc-vt/v1/shift-panel as finished HTML. Nothing
 * here builds a sentence, formats a date or decides a colour — those are
 * translated strings and a state vocabulary that live in PHP, and a second copy
 * of any of them here would be a second thing to keep in step.
 *
 * innerHTML is used ONCE, on that response, and that is deliberate rather than
 * careless: the markup is this plugin's own, escaped on the way out by the same
 * renderers the shift editor uses, and it carries form nonces that cannot be
 * rebuilt in a browser. Nothing else in this file writes markup — the roster
 * names inside it were escaped server-side.
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
		var root = document.querySelector( '[data-gwcvt-drawer]' );

		if ( ! root || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}

		var view = root.getAttribute( 'data-gwcvt-view' ) || 'list';
		var month = root.getAttribute( 'data-gwcvt-month' ) || '';

		var opener = null;
		var pending = 0;

		function close() {
			root.hidden = true;
			root.textContent = '';

			/* Back where they were. Somebody who opened the drawer from the
			 * keyboard and closed it has nowhere to be otherwise, and the next
			 * Tab would start again from the top of the document. */
			if ( opener && document.contains( opener ) ) {
				opener.focus();
			}

			opener = null;
		}

		function focusFirst() {
			var target = root.querySelector( '[data-gwcvt-drawer-close]' );

			if ( target ) {
				target.focus();
			}
		}

		function open( shiftId, from ) {
			opener = from || null;

			/* Every answer is stamped with the request that asked for it, so a
			 * slow reply for one Saturday cannot land on top of a fast reply for
			 * the Saturday clicked after it. */
			var mine = ++pending;

			root.hidden = false;
			root.textContent = '';

			var path = '/gwc-vt/v1/shift-panel?shift=' + encodeURIComponent( shiftId ) +
				'&back=' + encodeURIComponent( view );

			if ( month ) {
				path += '&month=' + encodeURIComponent( month );
			}

			window.wp.apiFetch( { path: path } ).then( function ( answer ) {
				if ( mine !== pending ) {
					return;
				}

				root.innerHTML = answer.html;

				/* The panel carries a volunteer picker. admin-picker.js binds on
				 * this event and marks what it has wired, so nothing here has to
				 * know how the picker works or worry about binding it twice. */
				document.dispatchEvent( new CustomEvent( 'gwc-vt:pickers-added' ) );

				focusFirst();
			} ).catch( function () {
				if ( mine !== pending ) {
					return;
				}

				/* A drawer that cannot say anything gets out of the way, and the
				 * link it intercepted is still a link: the second click goes to
				 * the shift editor, which is where it went before this file
				 * existed. */
				close();
			} );
		}

		/* Delegated from the document, because the calendar is redrawn by the
		 * server on every filter and month step — binding to each chip would
		 * mean rebinding after every navigation, and there is no navigation to
		 * hook: the page reloads. */
		document.addEventListener( 'click', function ( event ) {
			var closer = event.target.closest ? event.target.closest( '[data-gwcvt-drawer-close]' ) : null;

			if ( closer ) {
				event.preventDefault();
				close();
				return;
			}

			var link = event.target.closest ? event.target.closest( '[data-gwcvt-shift]' ) : null;

			if ( ! link ) {
				return;
			}

			/* Anything that means "open this somewhere else" is left alone —
			 * a middle click, a modified click, or a right click. Swallowing
			 * those would take away opening a shift in a new tab, which is how
			 * somebody works through four short Saturdays at once. */
			if ( event.defaultPrevented || 1 === event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}

			event.preventDefault();
			open( link.getAttribute( 'data-gwcvt-shift' ), link );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! root.hidden ) {
				close();
			}
		} );

		/* Reopened after adding somebody, so the next name goes in the same box.
		 * The server put the ID in the query string on its way back here. */
		var params = new URLSearchParams( window.location.search );
		var reopen = params.get( 'gwc_vt_open' );

		if ( reopen ) {
			open( reopen, null );
		}
	} );
}() );

/**
 * Opening and closing a sheet.
 *
 * Generic: it knows about [data-gwcvt-sheet-open="x"] and [data-gwcvt-sheet="x"]
 * and nothing else. Whatever is inside one is somebody else's business — see
 * assets/js/admin-letters-box.js for the parts that are.
 *
 * Subtractive, like everything else on this screen. The markup renders working
 * without this file: the triggers are hidden and whatever they would open is
 * rendered in place. This unhides the triggers and folds the panels away, so a
 * script that fails to load leaves a longer page rather than a broken one.
 *
 * ES5 on purpose: this plugin has no build step.
 */
( function () {
	'use strict';

	var sheets = document.querySelectorAll( '[data-gwcvt-sheet]' );

	if ( ! sheets.length ) {
		return;
	}

	/**
	 * Whether the stylesheet that makes a panel a panel actually arrived.
	 *
	 * A sheet is only a sheet because of CSS. Without it the markup is a block
	 * of document at the bottom of the page, and hiding the thing it duplicates
	 * would leave no way to reach either — worse than doing nothing at all.
	 *
	 * @param {Element} sheet A sheet.
	 * @return {boolean}
	 */
	function styled( sheet ) {
		return 'fixed' === window.getComputedStyle( sheet ).position;
	}

	if ( ! styled( sheets[ 0 ] ) ) {
		return;
	}

	/* Every sheet starts closed. The stylesheet has them hidden already under
	 * body.js; this is the state THIS file reasons about, so that open() and
	 * closeAll() are the only things that decide what is showing. */
	Array.prototype.forEach.call( sheets, function ( sheet ) {
		sheet.classList.remove( 'gwcvt-sheet--open' );
	} );

	var lastFocus = null;

	function find( id ) {
		return document.querySelector( '[data-gwcvt-sheet="' + id + '"]' );
	}

	/**
	 * Close every sheet, optionally sparing one that is about to open.
	 *
	 * The sparing is not a nicety. open() closes the others first, and emptying
	 * frames indiscriminately here emptied the one the caller had just pointed
	 * at a letter — the reader opened onto about:blank, every time, because the
	 * act of opening it wiped it.
	 *
	 * @param {Element} [keep] A sheet whose frames should be left alone.
	 */
	function closeAll( keep ) {
		Array.prototype.forEach.call( sheets, function ( sheet ) {
			sheet.classList.remove( 'gwcvt-sheet--open' );

			if ( sheet === keep ) {
				return;
			}

			/* Frames are emptied on the way out rather than left holding a
			 * letter: it is the most personal document this plugin produces,
			 * and an unattended screen should not still be showing one. */
			Array.prototype.forEach.call(
				sheet.querySelectorAll( 'iframe' ),
				function ( frame ) {
					frame.setAttribute( 'src', 'about:blank' );
				}
			);
		} );

		if ( keep ) {
			return;
		}

		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}

		lastFocus = null;
	}

	function open( sheet ) {
		if ( ! sheet ) {
			return;
		}

		closeAll( sheet );

		lastFocus = document.activeElement;
		sheet.classList.add( 'gwcvt-sheet--open' );

		var first = sheet.querySelector( 'input:not([type="hidden"]), select, textarea, button, [href]' );

		if ( first ) {
			first.focus();
		}
	}

	/* The sheets are hidden by CSS under body.js, so there is nothing to fold
	 * away here — doing it in the stylesheet is what stops three panels flashing
	 * at the foot of the page before this file runs.
	 *
	 * The triggers are the other half: rendered hidden, unhidden now. A page
	 * with no scripting shows the sheets inline and no buttons, which is the
	 * right way round — a button that opens something already open is worse
	 * than no button. */
	Array.prototype.forEach.call(
		document.querySelectorAll( '[data-gwcvt-sheet-trigger]' ),
		function ( trigger ) {
			trigger.hidden = false;
		}
	);

	/* Anything that exists only as a no-JS route to where a sheet goes. */
	Array.prototype.forEach.call(
		document.querySelectorAll( '[data-gwcvt-sheet-inline]' ),
		function ( inline ) {
			inline.hidden = true;
		}
	);

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target || ! event.target.closest ) {
			return;
		}

		var opener = event.target.closest( '[data-gwcvt-sheet-open]' );

		if ( opener ) {
			event.preventDefault();
			open( find( opener.getAttribute( 'data-gwcvt-sheet-open' ) ) );
			return;
		}

		if ( event.target.closest( '[data-gwcvt-sheet-close]' ) ) {
			event.preventDefault();
			closeAll();
			return;
		}

		/* The backdrop, but only the backdrop — a click inside the panel is a
		 * click on whatever the panel contains. */
		if ( event.target.matches( '.gwcvt-sheet' ) ) {
			closeAll();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeAll();
		}
	} );

	/* Exposed so the letter-specific script can open the reader after it has
	 * pointed the frame somewhere, rather than duplicating any of the above. */
	window.gwcVtSheet = {
		open: function ( id ) {
			open( find( id ) );
		},
		close: function () {
			closeAll();
		}
	};
}() );

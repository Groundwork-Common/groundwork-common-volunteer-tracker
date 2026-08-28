/**
 * The letters box on a volunteer's record.
 *
 * Three enhancements, and every one of them removes something rather than
 * building it: the adder folds behind a button, "Open" shows the letter over the
 * record instead of in a new tab, and "Email it" asks where it is going. The
 * markup for all three is rendered by PHP — see the note at the top of
 * inc/admin-volunteer-letters.php — so with this file absent the screen is a
 * plain form and four plain links, all of which work.
 *
 * That is also why there is not a translated string in here. Every word is in
 * the markup already; this only shows and hides it.
 *
 * ES5 on purpose: this plugin has no build step.
 */
( function () {
	'use strict';

	var box = document.querySelector( '[data-gwcvt-letters]' );

	if ( ! box ) {
		return;
	}

	var reader = document.querySelector( '[data-gwcvt-letter-reader]' );
	var mailer = document.querySelector( '[data-gwcvt-letter-mailer]' );
	var mailForm = document.getElementById( 'gwcvt-email-letter' );

	/**
	 * Whether the stylesheet that makes a panel a panel actually arrived.
	 *
	 * A panel is only a panel because of CSS. Without it the markup is a block
	 * of document at the bottom of the page, and intercepting a link that would
	 * have opened a clean new tab replaces a working thing with a wrecked one —
	 * which is what a stale admin.css looked like on the beta site, where the
	 * file changes between releases and the version on its URL does not.
	 *
	 * So the enhancement asks first. One computed read at startup: if the sheet
	 * is not positioned, the links are left exactly as PHP rendered them.
	 *
	 * @param {Element} sheet The panel to probe.
	 * @return {boolean}
	 */
	function styled( sheet ) {
		return !! sheet && 'fixed' === window.getComputedStyle( sheet ).position;
	}

	if ( ! styled( reader ) ) {
		reader = null;
	}

	if ( ! styled( mailer ) ) {
		mailer = null;
	}

	/**
	 * Show or hide, using the attribute rather than a class so the PHP-rendered
	 * starting state and the script's state are the same mechanism.
	 *
	 * @param {Element} el   The element.
	 * @param {boolean} show Whether it should be visible.
	 */
	function toggle( el, show ) {
		if ( el ) {
			el.hidden = ! show;
		}
	}

	/* ── The adder ───────────────────────────────────────────────────────────
	 * Rendered open, with the button hidden, so a reader without this script
	 * gets the form. The first thing the script does is swap those round. */
	var opener = box.querySelector( '[data-gwcvt-letters-open]' );
	var panel = box.querySelector( '[data-gwcvt-letters-panel]' );
	var cancel = box.querySelector( '[data-gwcvt-letters-cancel]' );
	var dates = box.querySelector( '[data-gwcvt-letters-dates]' );

	function foldAdder( folded ) {
		toggle( opener, folded );
		toggle( panel, ! folded );
	}

	if ( opener && panel ) {
		toggle( cancel, true );
		foldAdder( true );

		box.addEventListener( 'click', function ( event ) {
			if ( ! event.target || ! event.target.closest ) {
				return;
			}

			if ( event.target.closest( '[data-gwcvt-letters-add]' ) ) {
				foldAdder( false );

				var first = panel.querySelector( 'input[type="radio"]' );

				if ( first ) {
					first.focus();
				}

				return;
			}

			if ( event.target.closest( '[data-gwcvt-letters-cancel]' ) ) {
				foldAdder( true );

				var add = box.querySelector( '[data-gwcvt-letters-add]' );

				if ( add ) {
					add.focus();
				}
			}
		} );
	}

	/* The dates belong to the second choice, so they appear with it. Rendered
	 * visible for the same reason the panel is. */
	function syncDates() {
		var ranged = box.querySelector( '[data-gwcvt-letters-period="range"]' );

		toggle( dates, !! ( ranged && ranged.checked ) );
	}

	if ( dates ) {
		Array.prototype.forEach.call(
			box.querySelectorAll( '[data-gwcvt-letters-period]' ),
			function ( radio ) {
				radio.addEventListener( 'change', syncDates );
			}
		);

		syncDates();
	}

	/* ── The panels ──────────────────────────────────────────────────────── */

	var lastFocus = null;

	function openSheet( sheet ) {
		lastFocus = document.activeElement;
		toggle( sheet, true );

		var first = sheet.querySelector( 'button, [href], input, select' );

		if ( first ) {
			first.focus();
		}
	}

	function closeSheets() {
		toggle( reader, false );
		toggle( mailer, false );

		/* The frame is emptied on the way out rather than left holding a
		 * letter: this is the most personal document the plugin produces, and
		 * an unattended screen should not still be showing one. */
		var frame = reader && reader.querySelector( '[data-gwcvt-reader-frame]' );

		if ( frame ) {
			frame.setAttribute( 'src', 'about:blank' );
		}

		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}

		lastFocus = null;
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target || ! event.target.closest ) {
			return;
		}

		if ( event.target.closest( '[data-gwcvt-sheet-close]' ) ) {
			event.preventDefault();
			closeSheets();
			return;
		}

		/* The backdrop, but only the backdrop — a click inside the panel is a
		 * click on the letter. */
		if ( event.target.matches( '.gwcvt-sheet' ) ) {
			closeSheets();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			closeSheets();
		}
	} );

	/* ── Reading one ─────────────────────────────────────────────────────── */

	if ( reader ) {
		box.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest
				? event.target.closest( '[data-gwcvt-letter-open]' )
				: null;

			if ( ! link ) {
				return;
			}

			event.preventDefault();

			var title = reader.querySelector( '[data-gwcvt-reader-title]' );
			var frame = reader.querySelector( '[data-gwcvt-reader-frame]' );
			var slot = reader.querySelector( '[data-gwcvt-reader-actions]' );
			var draft = !! link.closest( '.gwcvt-letters-box__row--draft' );

			if ( title ) {
				title.textContent = link.getAttribute( 'data-gwcvt-letter-title' ) || title.textContent;
			}

			/* The panel offers what the row offers, by taking copies of the
			 * row's own links. Nothing here knows a URL, so there is no second
			 * copy of them to fall out of step. */
			if ( slot ) {
				slot.innerHTML = '';

				Array.prototype.forEach.call(
					link.closest( 'td' ).querySelectorAll( '[data-gwcvt-letter-issue], [data-gwcvt-letter-mail]' ),
					function ( action ) {
						var copy = action.cloneNode( true );

						copy.className = 'button';
						slot.appendChild( copy );
					}
				);
			}

			toggle( reader.querySelector( '[data-gwcvt-reader-note-draft]' ), draft );
			toggle( reader.querySelector( '[data-gwcvt-reader-note-issued]' ), ! draft );

			if ( frame ) {
				frame.setAttribute( 'src', link.getAttribute( 'href' ) );
			}

			openSheet( reader );
		} );
	}

	/* ── Sending one ─────────────────────────────────────────────────────── */

	if ( mailer && mailForm ) {
		var other = mailer.querySelector( '[data-gwcvt-mailer-other]' );
		var address = mailForm.elements.recipient;

		/* An expression rather than a declaration: this sits inside an `if`, and
		 * a function declaration in a block is not ES5 strict mode. */
		var syncMailto = function () {
			var picked = mailer.querySelector( '[data-gwcvt-mailto="other"]' );
			var typing = !! ( picked && picked.checked );

			toggle( other, typing );

			/* Emptied when it is not the answer, so a stale address cannot ride
			 * along on a send addressed to the record. The field is inside the
			 * form either way — hiding an input does not stop it submitting. */
			if ( ! typing && address ) {
				address.value = '';
			}
		};

		Array.prototype.forEach.call(
			mailer.querySelectorAll( '[data-gwcvt-mailto]' ),
			function ( radio ) {
				radio.addEventListener( 'change', syncMailto );
			}
		);

		syncMailto();

		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest
				? event.target.closest( '[data-gwcvt-letter-mail]' )
				: null;

			if ( ! link ) {
				return;
			}

			event.preventDefault();

			var kind = link.getAttribute( 'data-gwcvt-letter-mail' );

			/* Which letter, copied out of the row into the form that will send
			 * it. The nonce is not among these: it covers the action and the
			 * volunteer, and both are fixed on this screen. */
			[ 'from', 'to', 'draft' ].forEach( function ( name ) {
				var field = mailForm.querySelector( '[data-gwcvt-mail-field="' + name + '"]' );

				if ( field ) {
					field.value = link.getAttribute( 'data-gwcvt-letter-' + name ) || '';
				}
			} );

			Array.prototype.forEach.call(
				mailer.querySelectorAll( '[data-gwcvt-mailer-what]' ),
				function ( line ) {
					toggle( line, line.getAttribute( 'data-gwcvt-mailer-what' ) === kind );
				}
			);

			/* From a row or from the reader's own footer, which is where a copy
			 * of this link is. Either way one panel at a time. */
			toggle( reader, false );

			syncMailto();
			openSheet( mailer );
		} );
	}
}() );

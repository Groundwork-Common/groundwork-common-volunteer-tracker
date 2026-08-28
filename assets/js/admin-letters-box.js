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

	if ( ! box || ! window.gwcVtSheet ) {
		return;
	}

	var mailForm = document.getElementById( 'gwcvt-email-letter' );
	var postForm = document.getElementById( 'gwcvt-post-letter' );

	function toggle( el, show ) {
		if ( el ) {
			el.hidden = ! show;
		}
	}

	/* ── The dates belong to the second choice ───────────────────────────── */
	var dates = document.querySelector( '[data-gwcvt-letters-dates]' );

	function syncDates() {
		var ranged = document.querySelector( '[data-gwcvt-letters-period="range"]' );

		toggle( dates, !! ( ranged && ranged.checked ) );
	}

	if ( dates ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-gwcvt-letters-period]' ),
			function ( radio ) {
				radio.addEventListener( 'change', syncDates );
			}
		);

		syncDates();
	}

	/* ── Reading one ─────────────────────────────────────────────────────────
	 * The sheet is opened by the shared script; what is letter-specific is
	 * which document goes in the frame and which of the row's actions come
	 * with it. */
	box.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest
			? event.target.closest( '[data-gwcvt-letter-open]' )
			: null;

		if ( ! link ) {
			return;
		}

		event.preventDefault();

		var reader = document.querySelector( '[data-gwcvt-sheet="read-letter"]' );

		if ( ! reader ) {
			return;
		}

		/* The shared frame draws the heading, so there is no hook of our own on
		 * it — and there should not be: a second way to find it is a second
		 * thing to keep in step. */
		var title = reader.querySelector( '.gwcvt-sheet__head h2' );
		var frame = reader.querySelector( '[data-gwcvt-reader-frame]' );
		var slot = reader.querySelector( '[data-gwcvt-reader-actions]' );
		var draft = !! link.closest( '.gwcvt-letters-box__row--draft' );

		if ( title ) {
			title.textContent = link.getAttribute( 'data-gwcvt-letter-title' ) || title.textContent;
		}

		/* The panel offers what the row offers, by taking copies of the row's
		 * own links. Nothing here knows a URL, so there is no second copy of
		 * them to fall out of step. */
		if ( slot ) {
			slot.innerHTML = '';

			Array.prototype.forEach.call(
				link.closest( 'td' ).querySelectorAll(
					'[data-gwcvt-letter-issue], [data-gwcvt-letter-deliver], [data-gwcvt-letter-post], [data-gwcvt-letter-mail]'
				),
				function ( action ) {
					var copy = action.cloneNode( true );

					copy.className = 'button';
					slot.appendChild( copy );
				}
			);
		}

		toggle( reader.querySelector( '[data-gwcvt-reader-note-draft]' ), draft );

		if ( frame ) {
			frame.setAttribute( 'src', link.getAttribute( 'href' ) );
		}

		window.gwcVtSheet.open( 'read-letter' );
	} );

	/* ── Sending one ─────────────────────────────────────────────────────── */
	if ( mailForm ) {
		var mailer = document.querySelector( '[data-gwcvt-sheet="email-letter"]' );
		var other = mailer && mailer.querySelector( '[data-gwcvt-mailer-other]' );
		var address = mailForm.elements.recipient;

		var syncMailto = function () {
			var picked = mailer && mailer.querySelector( '[data-gwcvt-mailto="other"]' );
			var typing = !! ( picked && picked.checked );

			toggle( other, typing );

			/* Emptied when it is not the answer, so a stale address cannot ride
			 * along on a send addressed to the record. The field is inside the
			 * form either way — hiding an input does not stop it submitting. */
			if ( ! typing && address ) {
				address.value = '';
			}
		};

		if ( mailer ) {
			Array.prototype.forEach.call(
				mailer.querySelectorAll( '[data-gwcvt-mailto]' ),
				function ( radio ) {
					radio.addEventListener( 'change', syncMailto );
				}
			);

			syncMailto();
		}

		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest
				? event.target.closest( '[data-gwcvt-letter-mail]' )
				: null;

			if ( ! link ) {
				return;
			}

			event.preventDefault();

			/* The row's own href is already a complete nonced URL naming this
			 * one letter, so the form is pointed at it rather than carrying a
			 * copy of any of it. A POST to a URL with a query string arrives
			 * with both in $_REQUEST, which is what check_admin_referer() reads.
			 * Nothing here can address the wrong letter. */
			mailForm.setAttribute( 'action', link.getAttribute( 'href' ) );

			syncMailto();
			window.gwcVtSheet.open( 'email-letter' );
		} );
	}

	/* ── Posting one ─────────────────────────────────────────────────────── */
	if ( postForm ) {
		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest
				? event.target.closest( '[data-gwcvt-letter-post]' )
				: null;

			if ( ! link ) {
				return;
			}

			event.preventDefault();

			postForm.setAttribute( 'action', link.getAttribute( 'href' ) );

			/* Prefilled from whoever the letter is addressed to, when it is
			 * addressed to anybody. Offered, not imposed — a letter addressed
			 * to a court can still be posted to somebody else. */
			var to = document.getElementById( 'gwcvt-post-addressee' );

			if ( to ) {
				to.value = link.getAttribute( 'data-gwcvt-letter-addressee' ) || '';
			}

			window.gwcVtSheet.open( 'post-letter' );
		} );
	}
}() );

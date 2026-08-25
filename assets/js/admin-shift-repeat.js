/**
 * "This creates 20 real shifts", before the save rather than after it.
 *
 * Hand-written, no build step. Enhancement only: with this script absent the
 * box stays empty and hidden, the button keeps its plain label, and the count
 * is reported after the save by gwc_vt_schedule_notice() exactly as it was.
 *
 * ── It does not count anything ───────────────────────────────────────────────
 * Every number and every word here comes from gwc-vt/v1/recurrence-preview,
 * which runs gwc_vt_recurrence_dates() — the same function the save runs. That
 * is deliberate and is the whole design of this file.
 *
 * Counting the dates in the browser is a dozen lines and would be wrong. The
 * rule has a two-hundred cap, a twelve-month horizon, a monthly pattern that
 * skips a month with no fifth Saturday, and a deliberate one-shift answer when
 * the end date is missing. A second copy of that would agree today and drift,
 * and the failure is a screen promising twenty-six shifts and a save making
 * twenty — which is the exact bug the preview exists to prevent, reintroduced
 * by the preview.
 *
 * The response carries finished sentences rather than numbers, so no string is
 * assembled here either. Word order belongs to translators.
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
		var box = document.querySelector( '[data-gwcvt-repeat-preview]' );

		if ( ! box || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}

		var repeat = document.getElementById( 'gwcvt-shift-repeat' );
		var until  = document.getElementById( 'gwcvt-shift-until' );
		var date   = document.getElementById( 'gwcvt-shift-date' );
		var submit = document.getElementById( 'gwcvt-shift-submit' );

		if ( ! repeat || ! until || ! date ) {
			return;
		}

		/* What the button said before anything was previewed. The server put the
		 * no-repeat label there, so putting it back is a restore rather than a
		 * guess at what it should say. */
		var plainSubmit = submit ? submit.value : '';

		var timer   = null;
		var pending = 0;

		function clear() {
			box.hidden = true;
			box.textContent = '';
			box.classList.remove( 'gwcvt-repeat-preview--capped' );

			if ( submit ) {
				submit.value = plainSubmit;
			}
		}

		function paint( preview ) {
			box.textContent = '';

			if ( ! preview || ! preview.repeats ) {
				clear();
				return;
			}

			var headline = document.createElement( 'strong' );
			headline.textContent = preview.headline;
			box.appendChild( headline );

			/* An em dash rather than a full stop, so the count and what it is
			 * made of read as one sentence. The detail keeps its own leading
			 * capital because it opens with the pattern's translated label, and
			 * lowercasing a translated word is a thing you can only do safely in
			 * languages that do not capitalise their nouns. */
			if ( preview.detail ) {
				box.appendChild( document.createTextNode( ' — ' + preview.detail ) );
			}

			if ( preview.note ) {
				var note = document.createElement( 'p' );
				note.className = 'gwcvt-repeat-preview__note';
				note.textContent = preview.note;
				box.appendChild( note );
			}

			box.classList.toggle( 'gwcvt-repeat-preview--capped', !! preview.capped );
			box.hidden = false;

			if ( submit && preview.submit ) {
				submit.value = preview.submit;
			}
		}

		function refresh() {
			if ( 'once' === repeat.value || ! date.value ) {
				clear();
				return;
			}

			/* Every answer is stamped with the request that asked for it, so a
			 * slow reply for "every day" cannot land on top of a fast reply for
			 * "every month" typed after it. Without this the box settles on
			 * whichever request the network happened to finish last. */
			var mine = ++pending;

			window.wp.apiFetch( {
				path: '/gwc-vt/v1/recurrence-preview?start=' +
					encodeURIComponent( date.value ) +
					'&pattern=' + encodeURIComponent( repeat.value ) +
					'&until=' + encodeURIComponent( until.value )
			} ).then( function ( preview ) {
				if ( mine === pending ) {
					paint( preview );
				}
			} ).catch( function () {
				/* A failed lookup says nothing rather than guessing. The save
				 * still reports what it made. */
				if ( mine === pending ) {
					clear();
				}
			} );
		}

		function schedule() {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 250 );
		}

		[ repeat, until, date ].forEach( function ( field ) {
			field.addEventListener( 'change', schedule );
			field.addEventListener( 'input', schedule );
		} );
	} );
}() );

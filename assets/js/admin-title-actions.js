/**
 * Lift the plugin's own page-title-action buttons up beside the heading.
 *
 * Hand-written, no build step. Enhancement only: the buttons are rendered by
 * PHP into the notices area and work exactly as they are. All this does is put
 * them where WordPress puts its own, because core exposes no hook between the
 * <h1> and the <hr class="wp-header-end"> that closes the heading row.
 *
 * With this script absent the buttons sit one line lower and still open the
 * same screens. Nothing here creates a link, and nothing here is the only way
 * to reach anything — see the block comment in inc/admin-quick-add.php.
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
		var holder = document.querySelector( '[data-gwcvt-title-actions]' );

		if ( ! holder ) {
			return;
		}

		/* The heading, found from the document rather than from inside a .wrap
		 * — the holder carries that class itself so core will style the button
		 * in it, which means querySelector( '.wrap' ) finds the HOLDER first:
		 * it is printed above the screen's real wrap. Scoping the search that
		 * way looked tidier and silently moved nothing. */
		var heading = document.querySelector( 'h1.wp-heading-inline' );

		if ( ! heading || ! heading.parentNode ) {
			return;
		}

		/* After the heading, and before whatever core put there — so "Log a
		 * day" reads first and "Log one shift" second, which is the order they
		 * are named in everywhere else. insertBefore with a null reference
		 * appends, so the missing-sibling case needs no branch of its own. */
		var anchor = heading.nextSibling;

		while ( holder.firstChild ) {
			heading.parentNode.insertBefore( holder.firstChild, anchor );
		}

		holder.parentNode.removeChild( holder );
	} );
}() );

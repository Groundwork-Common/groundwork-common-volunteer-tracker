/**
 * "Add another role" and "add another time" on the event grid.
 *
 * Hand-written, no build step. Enhancement only: the screen already renders one
 * spare role and one spare time per role, and a save ignores anything blank — so
 * with this script absent you can still build any grid, one save at a time. All
 * this does is save the round trips.
 *
 * Field names carry explicit indexes (gwcvt_roles[2][slots][1][date]), so a
 * clone has to be renumbered or the new row overwrites its neighbour's answer.
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

	/**
	 * Rewrite every name, id and label inside an element.
	 *
	 * The id patterns are anchored at the START and match the index in the
	 * middle, because an id ends in the field it names — gwcvt-slot-0-2-date,
	 * not gwcvt-slot-0-2. An end-anchored pattern silently matches nothing, the
	 * clone keeps the original's ids, and every label in the new row points at
	 * the first row's input: clicking a label focuses the wrong field and a
	 * screen reader announces every row as row one. The field NAMES would still
	 * be right, so it saves correctly and looks fine — which is why this is
	 * checked rather than eyeballed.
	 *
	 * Anchoring also keeps the shared datalist id (gwcvt-event-roles) out of it.
	 *
	 * @param {Element} scope Where to rewrite.
	 * @param {RegExp}  find  What to match.
	 * @param {string}  to    What to put back.
	 */
	function renumber( scope, find, to ) {
		[ 'name', 'id', 'for', 'aria-controls' ].forEach( function ( attr ) {
			scope.querySelectorAll( '[' + attr + ']' ).forEach( function ( el ) {
				el.setAttribute( attr, el.getAttribute( attr ).replace( find, to ) );
			} );
		} );

		if ( scope.hasAttribute && scope.hasAttribute( 'id' ) ) {
			scope.id = scope.id.replace( find, to );
		}
	}

	/**
	 * Empty every field in a cloned fragment.
	 *
	 * The hidden id field goes to 0 rather than blank: it is what tells the save
	 * handler this is a new time rather than an edit of an existing one.
	 *
	 * @param {Element} scope Where to clear.
	 */
	function blank( scope ) {
		scope.querySelectorAll( 'input' ).forEach( function ( input ) {
			if ( 'checkbox' === input.type || 'radio' === input.type ) {
				input.checked = false;
				return;
			}

			input.value = 'hidden' === input.type ? '0' : '';
		} );

		scope.querySelectorAll( 'textarea' ).forEach( function ( area ) {
			area.value = '';
		} );
	}

	ready( function () {
		var grid = document.getElementById( 'gwcvt-event-grid' );

		if ( ! grid ) {
			return;
		}

		grid.addEventListener( 'click', function ( event ) {
			var addTime = event.target.closest( '[data-gwcvt-add-time]' );

			if ( addTime ) {
				event.preventDefault();

				var block = addTime.closest( '[data-gwcvt-role]' );
				var body = block.querySelector( 'tbody' );
				var rows = body.querySelectorAll( 'tr' );
				var last = rows[ rows.length - 1 ];
				var role = block.getAttribute( 'data-gwcvt-role' );
				var next = rows.length;

				var row = last.cloneNode( true );

				renumber( row, /\[slots\]\[\d+\]/, '[slots][' + next + ']' );
				renumber( row, /^gwcvt-slot-(\d+)-\d+-/, 'gwcvt-slot-$1-' + next + '-' );
				blank( row );

				/* The "remove" box only exists on a row that has a shift behind
				 * it. A clone is new, so anything of the sort goes. */
				row.querySelectorAll( '[data-gwcvt-remove]' ).forEach( function ( cell ) {
					cell.textContent = '';
				} );

				body.appendChild( row );

				var first = row.querySelector( 'input[type=date]' );

				if ( first ) {
					first.focus();
				}

				return;
			}

			var addRole = event.target.closest( '[data-gwcvt-add-role]' );

			if ( ! addRole ) {
				return;
			}

			event.preventDefault();

			var blocks = grid.querySelectorAll( '[data-gwcvt-role]' );
			var source = blocks[ blocks.length - 1 ];
			var index = blocks.length;

			var copy = source.cloneNode( true );

			renumber( copy, /gwcvt_roles\[\d+\]/, 'gwcvt_roles[' + index + ']' );
			renumber( copy, /^gwcvt-role-\d+/, 'gwcvt-role-' + index );
			renumber( copy, /^gwcvt-slot-\d+-/, 'gwcvt-slot-' + index + '-' );
			blank( copy );

			copy.setAttribute( 'data-gwcvt-role', String( index ) );

			/* One time to start with, the same as the server renders. */
			var body2 = copy.querySelector( 'tbody' );

			while ( body2.rows.length > 1 ) {
				body2.deleteRow( body2.rows.length - 1 );
			}

			copy.querySelectorAll( '[data-gwcvt-remove]' ).forEach( function ( cell ) {
				cell.textContent = '';
			} );

			copy.querySelectorAll( '[data-gwcvt-fill]' ).forEach( function ( cell ) {
				cell.textContent = '';
			} );

			source.parentNode.insertBefore( copy, addRole.parentNode );

			var name = copy.querySelector( 'input[type=text]' );

			if ( name ) {
				name.focus();
			}
		} );
	} );
}() );

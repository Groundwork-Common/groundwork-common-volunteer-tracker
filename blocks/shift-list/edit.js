/**
 * The shift list block, in the editor.
 *
 * Hand-written ES5 over the wp.* globals, with no JSX and no build step — see
 * README.md. The editor never renders the real list: it is a placeholder, both
 * because the block has no editable settings and because a live signup form
 * inside the editor invites somebody to submit it.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var settings = window.GWCVT_SHIFT_EDITOR || {};

	blocks.registerBlockType( 'groundwork-common-volunteer-tracker/shift-list', {
		edit: function () {
			var blockProps = blockEditor.useBlockProps();
			var children = [];

			/* Warned about here rather than on the front end. A visitor should
			 * never be told a feature exists but is switched off; the person who
			 * can do something about it is the one looking at this screen. */
			if ( ! settings.shiftsEnabled ) {
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'no-shifts' },
						__(
							'Shifts are switched off, so this block will not show anything. Turn them on under Volunteer Hours → Settings → Shifts.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			} else if ( ! settings.signupEnabled ) {
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'off' },
						__(
							'People cannot sign up yet. Switch signing up on under Volunteer Hours → Settings → Shifts, and pin it to this page.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			} else if ( settings.pinnedPage && settings.currentPage && settings.pinnedPage !== settings.currentPage ) {
				/* Pinned to one page by ID, because the handler has to know where
				 * to listen. A second copy elsewhere would render and silently
				 * never accept anything — the kind of thing nobody discovers
				 * until a volunteer says they signed up and never heard back. */
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'wrong-page' },
						__(
							'Shifts are pinned to a different page, so this copy will not accept signups. Change the pinned page in Settings → Shifts, or remove this block.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			}

			children.push(
				el(
					components.Placeholder,
					{
						icon: 'calendar-alt',
						label: __( 'Volunteer Shifts', 'groundwork-common-volunteer-tracker' ),
						key: 'placeholder'
					},
					el(
						'p',
						null,
						__(
							'Lists the shifts you need help with and lets people put their name down. Visitors see what the work is and how many places are left — never who else is coming.',
							'groundwork-common-volunteer-tracker'
						)
					)
				)
			);

			return el( 'div', blockProps, children );
		},

		// Rendered in PHP — see render.php.
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n ) );

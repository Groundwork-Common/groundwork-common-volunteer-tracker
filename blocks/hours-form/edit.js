/**
 * The hours form block, in the editor.
 *
 * Hand-written ES5 over the wp.* globals, with no JSX and no build step — see
 * README.md. The editor never renders the real form: it is a placeholder, both
 * because the form has no editable settings and because rendering a live form
 * inside the editor invites somebody to submit it.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var settings = window.GWC_VT_EDITOR || {};

	blocks.registerBlockType( 'groundwork-common-volunteer-tracker/hours-form', {
		edit: function () {
			var blockProps = blockEditor.useBlockProps();
			var children = [];

			/* Warned about here rather than on the front end. A visitor should
			 * never be told a feature exists but is switched off; the person who
			 * can do something about it is the one looking at this screen. */
			if ( ! settings.selfLogEnabled ) {
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'off' },
						__(
							'Volunteers cannot send hours yet. Switch the form on under Volunteer Hours → Settings → Logging, and pin it to this page.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			} else if ( settings.pinnedPage && settings.currentPage && settings.pinnedPage !== settings.currentPage ) {
				/* The form is pinned to one page by ID, because the submission
				 * handler has to know where to listen. A second copy on another
				 * page would render and silently never accept anything, which is
				 * the kind of thing nobody discovers until a volunteer complains. */
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'wrong-page' },
						__(
							'The form is pinned to a different page, so this copy will not accept submissions. Change the pinned page in Settings → Logging, or remove this block.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			}

			children.push(
				el(
					components.Placeholder,
					{
						icon: 'clipboard',
						label: __( 'Volunteer Hours Form', 'groundwork-common-volunteer-tracker' ),
						key: 'placeholder'
					},
					el(
						'p',
						null,
						__(
							'Volunteers fill this in to send you the hours they worked. Everything they send arrives unverified and waits for a staff member to check it.',
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

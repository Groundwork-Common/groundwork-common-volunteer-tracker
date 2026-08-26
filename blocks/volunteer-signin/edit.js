/**
 * The volunteer sign-in block, in the editor.
 *
 * Hand-written ES5 over the wp.* globals, with no JSX and no build step — see
 * README.md. The editor never renders the real form: it is a placeholder, both
 * because the form has no editable settings and because a live sign-in form
 * inside the editor invites somebody to sign themselves in from a post screen.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var settings = window.GWC_VT_SIGNIN_EDITOR || {};

	blocks.registerBlockType( 'groundwork-common-volunteer-tracker/volunteer-signin', {
		edit: function () {
			var blockProps = blockEditor.useBlockProps();
			var children = [];

			/* Warned about here rather than on the front end. A visitor should
			 * never be told a feature exists but is switched off; the person who
			 * can do something about it is the one looking at this screen. */
			if ( ! settings.signinEnabled ) {
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'off' },
						__(
							'Volunteers cannot sign in yet. Switch it on under Volunteer Tracker → Settings → Logging, and pin it to this page.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			} else if ( settings.pinnedPage && settings.currentPage && settings.pinnedPage !== settings.currentPage ) {
				/* The link in the email points at the pinned page. A copy on
				 * another page renders a form whose emails send people
				 * somewhere else, which nobody discovers until a volunteer says
				 * the link did not work. */
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'wrong-page' },
						__(
							'Signing in is pinned to a different page, and the emailed links point there. This copy will not work. Change the pinned page in Settings → Logging, or remove this block.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			}

			children.push(
				el(
					components.Placeholder,
					{
						icon: 'unlock',
						label: __( 'Volunteer Sign-in', 'groundwork-common-volunteer-tracker' ),
						key: 'placeholder'
					},
					el(
						'p',
						null,
						__(
							'A volunteer gives the email address you have for them and is sent a link that signs them in. No account is created and there is no password. Signed in, they can see their own hours and what they are down for.',
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

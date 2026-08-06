/**
 * The event grid block, in the editor.
 *
 * Hand-written ES5 over the wp.* globals, with no JSX and no build step — see
 * README.md. The editor never renders the real grid: it is a placeholder with
 * one control, both because a live signup form inside the editor invites
 * somebody to submit it and because the grid shows place counts that would be
 * stale the moment the page was saved.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var settings = window.GWCVT_EVENT_EDITOR || {};
	var events = settings.events || [];

	blocks.registerBlockType( 'groundwork-common-volunteer-tracker/event-grid', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps();
			var children = [];
			var chosen = props.attributes.eventId || 0;

			if ( ! settings.shiftsEnabled ) {
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'no-shifts' },
						__(
							'Scheduling is switched off, so this block will not show anything. Turn it on under Volunteer Hours → Settings → Shifts.',
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
							'People cannot sign up yet. Switch signing up on under Volunteer Hours → Settings → Shifts.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			}

			if ( ! events.length ) {
				children.push(
					el(
						components.Notice,
						{ status: 'warning', isDismissible: false, key: 'none' },
						__(
							'There are no published events yet. Add one under Volunteer Hours → Schedule → Events.',
							'groundwork-common-volunteer-tracker'
						)
					)
				);
			}

			children.push(
				el(
					components.Placeholder,
					{
						icon: 'groups',
						label: __( 'Volunteer Event', 'groundwork-common-volunteer-tracker' ),
						key: 'placeholder'
					},
					el(
						components.SelectControl,
						{
							label: __( 'Which event', 'groundwork-common-volunteer-tracker' ),
							value: String( chosen ),
							options: [ { value: '0', label: __( '— Choose —', 'groundwork-common-volunteer-tracker' ) } ].concat( events ),
							onChange: function ( value ) {
								props.setAttributes( { eventId: parseInt( value, 10 ) || 0 } );
							},
							__nextHasNoMarginBottom: true
						}
					),
					el(
						'p',
						null,
						__(
							'Visitors see every role and time on this event and can pick more than one. They see how many places are left — never who else is coming.',
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

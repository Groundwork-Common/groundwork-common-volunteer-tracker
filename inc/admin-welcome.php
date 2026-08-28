<?php
/**
 * The one notice a new install gets, and the end of it.
 *
 * ── Why there is one at all ─────────────────────────────────────────────────
 * The guide answers almost every question somebody has in their first week, and
 * it is a menu row called "Help" under a plugin they have just installed —
 * which is to say it is findable by somebody who has thought to look for it.
 * The people who most need it are the ones who have not.
 *
 * ── Why it goes away on its own ─────────────────────────────────────────────
 * Two conditions, and either one ends it:
 *
 *   - Somebody dismisses it. That is permanent, per person, and it is a link
 *     rather than core's dismiss button because that button hides a notice for
 *     one page load and this promises more than that.
 *   - The site stops being new. gwc_vt_has_any_records() is the same test the
 *     dashboard's "Start here" uses, so the two appear and go together rather
 *     than one outliving the other on the same screen.
 *
 * A notice with no end condition is an advertisement, and this plugin's own
 * README argues against exactly that shape in the retention nag: the one nag
 * that never goes away is the one that has to be earned. Getting started is
 * not that.
 *
 * ── Why per user, not per site ──────────────────────────────────────────────
 * The coordinator who joins in March did not dismiss anything in January, and
 * is exactly the reader this is for. A site-wide flag would mean the first
 * administrator to close it decided for everybody who came after.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/** Where the dismissal is remembered, per user. */
const GWC_VT_WELCOME_META = 'gwc_vt_welcome_dismissed';

add_action( 'admin_notices', 'gwc_vt_render_welcome_notice' );
add_action( 'admin_post_gwc_vt_dismiss_welcome', 'gwc_vt_handle_dismiss_welcome' );


/**
 * Should this reader be shown the way to the guide?
 *
 * @return bool
 */
function gwc_vt_welcome_notice_applies(): bool {
	if ( ! is_user_logged_in() || ! gwc_vt_is_plugin_screen() ) {
		return false;
	}

	/* Not on the guide itself. Somebody reading it has followed the notice, or
	 * did not need it. */
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $screen && false !== strpos( (string) $screen->id, GWC_VT_HELP_PAGE ) ) {
		return false;
	}

	if ( '' !== (string) get_user_meta( get_current_user_id(), GWC_VT_WELCOME_META, true ) ) {
		return false;
	}

	return ! gwc_vt_has_any_records();
}

/**
 * The notice.
 */
function gwc_vt_render_welcome_notice(): void {
	if ( ! gwc_vt_welcome_notice_applies() ) {
		return;
	}

	?>
	<div class="notice notice-info gwcvt-welcome">
		<p>
			<strong><?php esc_html_e( 'Start with the guide.', 'groundwork-common-volunteer-tracker' ); ?></strong>
			<?php esc_html_e( 'It walks through setting up, logging hours and issuing letters, one step at a time.', 'groundwork-common-volunteer-tracker' ); ?>
		</p>

		<p class="gwcvt-welcome__actions">
			<a href="<?php echo esc_url( gwc_vt_help_page_url() ); ?>">
				<?php esc_html_e( 'Read the guide', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
			&middot;
			<a href="<?php echo esc_url( gwc_vt_dismiss_welcome_url() ); ?>">
				<?php esc_html_e( 'Hide this for good', 'groundwork-common-volunteer-tracker' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Where "Hide this for good" goes.
 *
 * Nothing but the action and its nonce. The handler puts the reader back with
 * wp_get_referer(), which reads the request's own referer and is checked by
 * wp_safe_redirect() against this site — and the guide when there is none.
 * Building a return URL by hand out of REQUEST_URI is how an authenticated
 * open redirect gets written.
 *
 * @return string
 */
function gwc_vt_dismiss_welcome_url(): string {
	return wp_nonce_url(
		add_query_arg( 'action', 'gwc_vt_dismiss_welcome', admin_url( 'admin-post.php' ) ),
		'gwc_vt_dismiss_welcome_' . get_current_user_id()
	);
}

/**
 * Remember that they do not want it.
 *
 * Nonced per user, so a link copied out of one person's page cannot dismiss it
 * for another — harmless as mischief goes, and still not this link's business.
 *
 * The value stored is the day, not a 1. Nothing reads it, and one day somebody
 * asking "when did this site stop being new" has an answer instead of a flag.
 */
function gwc_vt_handle_dismiss_welcome(): void {
	$user_id = get_current_user_id();

	check_admin_referer( 'gwc_vt_dismiss_welcome_' . $user_id );

	if ( $user_id > 0 ) {
		update_user_meta( $user_id, GWC_VT_WELCOME_META, gwc_vt_today() );
	}

	$back = wp_get_referer();

	wp_safe_redirect( false !== $back ? $back : gwc_vt_help_page_url() );
	exit;
}

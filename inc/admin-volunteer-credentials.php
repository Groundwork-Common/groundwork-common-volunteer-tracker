<?php
/**
 * Who holds what, on the volunteer's own record.
 *
 * The counterpart to inc/admin-credentials.php: that screen decides what the
 * organization asks for, this box records that a named person has it. Two jobs
 * and two capabilities — see the header of that file for the argument.
 *
 * ── Why this saves through the post form ─────────────────────────────────────
 * A meta box cannot contain a <form>. The volunteer editor is already one, and
 * nesting a second is invalid HTML that browsers resolve by throwing the inner
 * one away — the button posts the outer form and the fields go nowhere, with no
 * error anywhere. So recording rides the volunteer's own save, hooked on
 * save_post_gwc_vt_volunteer beside the picker's, and removing a record is a
 * link to admin-post.php rather than a button.
 *
 * ── Why every live credential gets a row ─────────────────────────────────────
 * Including the ones this volunteer has never held. A box that listed only what
 * somebody has answers "what do they hold" and hides "what are they missing",
 * and the second question is the one a coordinator opens this record to ask.
 *
 * @package VolunteerTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'add_meta_boxes', 'gwc_vt_add_volunteer_credentials_box' );
add_action( 'admin_post_gwc_vt_remove_credential_record', 'gwc_vt_handle_remove_credential_record' );
add_action( 'admin_notices', 'gwc_vt_credential_record_notice' );

add_action( 'restrict_manage_posts', 'gwc_vt_credential_filter_dropdown', 11 );
add_action( 'pre_get_posts', 'gwc_vt_apply_credential_filter' );

/**
 * The query var the volunteer list filters by.
 *
 * Its own, beside GWC_VT_VOLUNTEER_FILTER rather than folded into it. That one
 * is named gwc_vt_requirement and means required service hours; this plugin has
 * a rule that the credential feature never uses that word, and a credential
 * state arriving under it would break the rule in the one place a reader is
 * most likely to trip over it — a URL.
 */
const GWC_VT_CREDENTIAL_FILTER = 'gwc_vt_credential';

/** Which standing the filter above is asking about, when it names one. */
const GWC_VT_CREDENTIAL_STATE = 'gwc_vt_credential_state';

/**
 * Whether this user may record that somebody holds a credential.
 *
 * The same capability that means "this staff member's word is what makes a
 * record true here" — the one attesting to hours already uses. Recording a
 * credential is the same kind of statement about the same kind of evidence.
 *
 * @return bool
 */
function gwc_vt_can_record_credentials(): bool {
	return current_user_can( gwc_vt_cap( 'verify' ) );
}

/**
 * Register the box.
 */
function gwc_vt_add_volunteer_credentials_box(): void {
	/* Shown even with nothing defined — the empty state is where the link to
	 * the definitions screen lives, and an organization that has not set any up
	 * yet is exactly who needs to find it. */
	add_meta_box(
		'gwc-vt-volunteer-credentials',
		__( 'Credentials', 'groundwork-common-volunteer-tracker' ),
		'gwc_vt_render_volunteer_credentials_box',
		GWC_VT_VOLUNTEER_TYPE,
		'normal',
		'default'
	);
}

/**
 * The box.
 *
 * @param WP_Post $post The volunteer.
 */
function gwc_vt_render_volunteer_credentials_box( $post ): void {
	$volunteer_id = (int) $post->ID;

	if ( 'auto-draft' === get_post_status( $volunteer_id ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Save this volunteer first, then you can record what they hold.', 'groundwork-common-volunteer-tracker' )
		);
		return;
	}

	$live = gwc_vt_live_credential_ids();

	if ( ! $live ) {
		printf(
			'<p class="description">%s</p>',
			wp_kses(
				sprintf(
					/* translators: %s: a link to the credentials screen, its text already translated. */
					__( 'Nothing is defined yet. %s — a training course, a signed waiver, a background check — and you can record who holds it here.', 'groundwork-common-volunteer-tracker' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . GWC_VT_ENTRY_TYPE . '&page=' . GWC_VT_CREDENTIALS_PAGE ) ) . '">'
						. esc_html__( 'Say what your organization asks volunteers to hold', 'groundwork-common-volunteer-tracker' )
						. '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
		return;
	}

	/* ── What this person holds, not what the organization asks for ───────────
	 * The table listed every credential defined anywhere and said "not recorded"
	 * against most of them, so a volunteer who holds one of six read as five
	 * pieces of bad news and one fact. A record is about the person; the list of
	 * what the organization asks for is a screen of its own, and the field below
	 * is how you add to what is here.
	 *
	 * Asked over ALL credentials rather than the live ones, which is a second
	 * thing this fixes: somebody who holds a credential the organization has
	 * since retired held it all along and the old loop never showed it, because
	 * it walked the definitions rather than the grants. Retiring stops asking;
	 * it does not un-hold. */
	$held = array();

	foreach ( gwc_vt_all_credential_ids() as $credential_id ) {
		if ( GWC_VT_HOLDS_NEVER !== gwc_vt_volunteer_holds( $volunteer_id, (int) $credential_id ) ) {
			$held[] = (int) $credential_id;
		}
	}

	if ( $held ) {
		echo '<table class="widefat striped gwcvt-held"><tbody>';

		foreach ( $held as $credential_id ) {
			gwc_vt_render_held_row( $volunteer_id, (int) $credential_id );
		}

		echo '</tbody></table>';
	} else {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Nothing recorded for this volunteer yet.', 'groundwork-common-volunteer-tracker' )
		);
	}

	if ( gwc_vt_can_record_credentials() ) {
		gwc_vt_sheet_trigger( 'record-credential', __( 'Record a credential', 'groundwork-common-volunteer-tracker' ) );
	} else {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Recording that somebody holds a credential needs permission to verify hours, which your account does not have.', 'groundwork-common-volunteer-tracker' )
		);
	}

	unset( $live );
}

/**
 * One credential, and where this volunteer stands on it.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @param int $credential_id Credential post ID.
 */
function gwc_vt_render_held_row( int $volunteer_id, int $credential_id ): void {
	$credential = gwc_vt_credential( $credential_id );

	if ( ! $credential ) {
		return;
	}

	$standing = gwc_vt_volunteer_holds( $volunteer_id, $credential_id );
	$until    = gwc_vt_volunteer_holds_until( $volunteer_id, $credential_id );
	?>
	<tr>
		<td>
			<strong><?php echo esc_html( $credential['name'] ); ?></strong>
			<?php if ( ! empty( $credential['retired'] ) ) : ?>
				<?php /* They hold it; the organization simply stopped asking. */ ?>
				<span class="gwcvt-held__retired"><?php echo esc_html_x( 'Retired', 'credential status', 'groundwork-common-volunteer-tracker' ); ?></span>
			<?php endif; ?>
		</td>
		<td><?php echo wp_kses_post( gwc_vt_standing_badge( $standing ) ); ?></td>
		<td>
			<?php
			if ( GWC_VT_HOLDS_CURRENT === $standing && '' !== $until ) {
				printf(
					/* translators: %s: a date. */
					esc_html__( 'Good until %s', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_credential_date( $until ) )
				);
			} elseif ( GWC_VT_HOLDS_CURRENT === $standing ) {
				esc_html_e( 'Does not expire', 'groundwork-common-volunteer-tracker' );
			} elseif ( GWC_VT_HOLDS_EXPIRED === $standing && '' !== $until ) {
				printf(
					/* translators: %s: a date. */
					esc_html__( 'Lapsed %s', 'groundwork-common-volunteer-tracker' ),
					esc_html( gwc_vt_credential_date( $until ) )
				);
			}
			?>
		</td>
		<td class="gwcvt-held__history"><?php gwc_vt_render_record_history( $volunteer_id, $credential_id ); ?></td>
	</tr>
	<?php
}

/**
 * What was recorded, and when, and by whom.
 *
 * Every grant rather than the latest, because "renewed every year since 2019"
 * is the answer to a question somebody eventually asks about a person whose
 * paperwork is being queried.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @param int $credential_id Credential post ID.
 */
function gwc_vt_render_record_history( int $volunteer_id, int $credential_id ): void {
	$rows = array();

	foreach ( gwc_vt_credential_record_ids( $volunteer_id ) as $record_id ) {
		$record = gwc_vt_credential_record( (int) $record_id );

		if ( $record && $record['credential'] === $credential_id ) {
			$rows[] = $record;
		}
	}

	if ( ! $rows ) {
		printf( '<span class="description">%s</span>', esc_html__( 'Nothing recorded', 'groundwork-common-volunteer-tracker' ) );
		return;
	}

	echo '<ul class="gwcvt-held__list">';

	foreach ( $rows as $record ) {
		$who = $record['by'] > 0 ? get_the_author_meta( 'display_name', $record['by'] ) : '';
		?>
		<li>
			<?php
			echo esc_html(
				'' !== $who
					? sprintf(
						/* translators: 1: a date. 2: a staff member's name. */
						__( '%1$s, recorded by %2$s', 'groundwork-common-volunteer-tracker' ),
						gwc_vt_credential_date( $record['date'] ),
						$who
					)
					: gwc_vt_credential_date( $record['date'] )
			);
			?>
			<?php if ( gwc_vt_can_record_credentials() ) : ?>
				<a class="gwcvt-held__remove" href="<?php echo esc_url( gwc_vt_remove_record_url( $record['id'] ) ); ?>">
					<?php esc_html_e( 'Remove', 'groundwork-common-volunteer-tracker' ); ?>
				</a>
			<?php endif; ?>
		</li>
		<?php
	}

	echo '</ul>';
}

/**
 * A standing, said in a word.
 *
 * @param string $standing One of the GWC_VT_HOLDS_* values.
 * @return string HTML.
 */
function gwc_vt_standing_badge( string $standing ): string {
	/* The existing three tints, not new ones. And "not recorded" gets the
	 * neutral one deliberately: an organization that defines six credentials
	 * has six amber badges on the record of every volunteer who has only ever
	 * needed one of them, and a warning colour that appears on almost every row
	 * stops being read. Amber is for the thing that has lapsed. */
	$badges = array(
		GWC_VT_HOLDS_CURRENT => array( 'gwcvt-badge--verified', __( 'Held', 'groundwork-common-volunteer-tracker' ) ),
		GWC_VT_HOLDS_EXPIRED => array( 'gwcvt-badge--cancelled', __( 'Lapsed', 'groundwork-common-volunteer-tracker' ) ),
		GWC_VT_HOLDS_NEVER   => array( 'gwcvt-badge--none', __( 'Not recorded', 'groundwork-common-volunteer-tracker' ) ),
	);

	if ( ! isset( $badges[ $standing ] ) ) {
		return '';
	}

	return sprintf(
		'<span class="gwcvt-badge %s">%s</span>',
		esc_attr( $badges[ $standing ][0] ),
		esc_html( $badges[ $standing ][1] )
	);
}

/**
 * A nonced URL that removes one record.
 *
 * @param int $record_id The record.
 * @return string
 */
function gwc_vt_remove_record_url( int $record_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'gwc_vt_remove_credential_record',
				'record' => $record_id,
			),
			admin_url( 'admin-post.php' )
		),
		'gwc_vt_remove_credential_record_' . $record_id
	);
}

/**
 * Remove one record.
 *
 * A mistake at a keyboard is the case this exists for — the wrong volunteer,
 * the wrong credential, a date typed a year out. It removes the record rather
 * than marking it wrong, because a credential nobody holds is not a fact worth
 * keeping a tombstone for, and the issued-letter log is where this plugin keeps
 * things that outlive their subject.
 */
function gwc_vt_handle_remove_credential_record(): void {
	if ( ! gwc_vt_can_record_credentials() ) {
		wp_die(
			esc_html__( 'You do not have permission to change credentials.', 'groundwork-common-volunteer-tracker' ),
			esc_html__( 'Permission denied', 'groundwork-common-volunteer-tracker' ),
			array( 'response' => 403 )
		);
	}

	$record_id = isset( $_GET['record'] ) ? absint( wp_unslash( $_GET['record'] ) ) : 0;

	check_admin_referer( 'gwc_vt_remove_credential_record_' . $record_id );

	if ( GWC_VT_RECORD_TYPE !== get_post_type( $record_id ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) );
		exit;
	}

	$volunteer_id = (int) get_post_field( 'post_parent', $record_id );

	wp_delete_post( $record_id, true );

	set_transient( 'gwc_vt_credential_said_' . get_current_user_id(), 'removed', 60 );

	wp_safe_redirect( $volunteer_id > 0 ? get_edit_post_link( $volunteer_id, 'url' ) : admin_url( 'edit.php?post_type=' . GWC_VT_VOLUNTEER_TYPE ) );
	exit;
}

/**
 * Say what the last attempt did.
 */
function gwc_vt_credential_record_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen || GWC_VT_VOLUNTEER_TYPE !== $screen->post_type ) {
		return;
	}

	$said = get_transient( 'gwc_vt_credential_said_' . get_current_user_id() );

	if ( ! $said ) {
		return;
	}

	delete_transient( 'gwc_vt_credential_said_' . get_current_user_id() );

	$done = array(
		'recorded' => __( 'Credential recorded.', 'groundwork-common-volunteer-tracker' ),
		'removed'  => __( 'That record was removed.', 'groundwork-common-volunteer-tracker' ),
	);

	if ( isset( $done[ $said ] ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $done[ $said ] ) );
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: why the credential was not recorded. */
				__( 'The volunteer was saved, but the credential was not recorded: %s', 'groundwork-common-volunteer-tracker' ),
				(string) $said
			)
		)
	);
}

/**
 * A Y-m-d, in the site's own date format.
 *
 * Local to this box. There is no shared date formatter in the plugin — every
 * screen reaches for get_option( 'date_format' ) itself — and inventing one
 * here would mean touching seven files to prove a credential renders.
 *
 * @param string $ymd A date, or ''.
 * @return string
 */
function gwc_vt_credential_date( string $ymd ): string {
	if ( '' === $ymd ) {
		return '';
	}

	$stamp = strtotime( $ymd . ' 00:00:00' );

	return $stamp ? (string) wp_date( (string) get_option( 'date_format' ), $stamp ) : $ymd;
}

/**
 * The credential states worth filtering the volunteer list by.
 *
 * One real state. "Has never held it" is not offered, and deliberately: until a
 * shift can ask for a credential there is nothing that says a given volunteer
 * needed one, so a list of everybody without a waiver is a list of everybody.
 *
 * A function rather than a const, because a const is evaluated at include time
 * and freezes these in English for the request.
 *
 * @return array<string, string>
 */
function gwc_vt_credential_filter_options(): array {
	$options = array(
		''       => __( 'Any credential', 'groundwork-common-volunteer-tracker' ),
		'lapsed' => __( 'Has one that has lapsed', 'groundwork-common-volunteer-tracker' ),
	);

	/* Then one entry per credential, so the question can be asked the other way
	 * round: not "what is this person short of" but "who has a food handler
	 * card". That is the question somebody staffing Saturday actually has, and
	 * before this the only way to answer it was to open volunteers one at a
	 * time.
	 *
	 * Retired credentials are included. The organization stopped asking for the
	 * thing; the people who hold it still hold it, and "who did the forklift
	 * training before we dropped it" is a real question with a real answer. */
	foreach ( gwc_vt_all_credential_ids() as $credential_id ) {
		$credential = gwc_vt_credential( (int) $credential_id );

		if ( ! $credential ) {
			continue;
		}

		$options[ (string) $credential['id'] ] = $credential['retired']
			? sprintf(
				/* translators: %s: the name of a credential that is no longer asked for. */
				__( '%s (retired)', 'groundwork-common-volunteer-tracker' ),
				$credential['name']
			)
			: $credential['name'];
	}

	return $options;
}

/**
 * The standings the second dropdown offers.
 *
 * A function rather than a const — a const is evaluated at include time and
 * freezes these in English for the request.
 *
 * @return array<string, string>
 */
function gwc_vt_credential_state_options(): array {
	return array(
		''                   => __( 'Held or lapsed', 'groundwork-common-volunteer-tracker' ),
		GWC_VT_HOLDS_CURRENT => __( 'Held', 'groundwork-common-volunteer-tracker' ),
		GWC_VT_HOLDS_EXPIRED => __( 'Lapsed', 'groundwork-common-volunteer-tracker' ),
	);
}

/**
 * A URL for the volunteer list, filtered to one credential.
 *
 * The one place this link is built, so the counts on the definitions screen and
 * the screen they open cannot drift apart — the rule this plugin has about a
 * number and what it links to.
 *
 * @param int    $credential_id Credential post ID.
 * @param string $state         '', 'current' or 'expired'.
 * @return string
 */
function gwc_vt_credential_holders_url( int $credential_id, string $state = '' ): string {
	$args = array(
		'post_type'              => GWC_VT_VOLUNTEER_TYPE,
		GWC_VT_CREDENTIAL_FILTER => (string) $credential_id,
	);

	if ( '' !== $state ) {
		$args[ GWC_VT_CREDENTIAL_STATE ] = $state;
	}

	return add_query_arg( $args, admin_url( 'edit.php' ) );
}

/**
 * The filter, above the volunteer list.
 *
 * Only once anything is defined. A dropdown offering to filter by a thing the
 * site does not have is a control that can only ever return nothing.
 */
function gwc_vt_credential_filter_dropdown(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'edit-' . GWC_VT_VOLUNTEER_TYPE !== $screen->id ) {
		return;
	}

	if ( ! gwc_vt_all_credential_ids() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
	$current = isset( $_GET[ GWC_VT_CREDENTIAL_FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ GWC_VT_CREDENTIAL_FILTER ] ) ) : '';
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( GWC_VT_CREDENTIAL_FILTER ); ?>">
		<?php esc_html_e( 'Filter by credential', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="<?php echo esc_attr( GWC_VT_CREDENTIAL_FILTER ); ?>" id="<?php echo esc_attr( GWC_VT_CREDENTIAL_FILTER ); ?>">
		<?php foreach ( gwc_vt_credential_filter_options() as $value => $label ) : ?>
			<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, (string) $value ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<?php
	/* A second select rather than doubling the first. One entry per credential
	 * per standing would be "Food handler card — held", "Food handler card —
	 * lapsed", and so on: two lines each, and a list that gets unreadable at
	 * the fifth credential. Two selects is also how core's own list tables ask
	 * two questions.
	 *
	 * It has no effect beside "Any credential" or "Has one that has lapsed" —
	 * those already name a standing — so it says so rather than pretending. */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
	$standing = isset( $_GET[ GWC_VT_CREDENTIAL_STATE ] ) ? sanitize_key( wp_unslash( $_GET[ GWC_VT_CREDENTIAL_STATE ] ) ) : '';
	?>

	<label class="screen-reader-text" for="<?php echo esc_attr( GWC_VT_CREDENTIAL_STATE ); ?>">
		<?php esc_html_e( 'Held or lapsed', 'groundwork-common-volunteer-tracker' ); ?>
	</label>
	<select name="<?php echo esc_attr( GWC_VT_CREDENTIAL_STATE ); ?>" id="<?php echo esc_attr( GWC_VT_CREDENTIAL_STATE ); ?>">
		<?php foreach ( gwc_vt_credential_state_options() as $value => $label ) : ?>
			<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $standing, (string) $value ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * The query vars one credential filter state asks for.
 *
 * Pure and separate from the hook, so the rule that matters — that an empty
 * lapsed set shows nobody rather than everybody — can be asserted without a
 * request. The same split, and the same trap, as the requirement filter.
 *
 * @param string $state  '' or 'lapsed'.
 * @param int[]  $lapsed The lapsed volunteer IDs, when $state is 'lapsed'.
 * @return array<string, mixed> Query vars to set.
 */
function gwc_vt_credential_query_vars( string $state, array $lapsed = array() ): array {
	if ( 'lapsed' !== $state ) {
		return array();
	}

	/* array( 0 ) rather than an empty array: post__in with nothing in it is
	 * ignored by WP_Query, which would list every volunteer on the site under a
	 * filter saying these are the ones whose credentials have lapsed. */
	return array( 'post__in' => $lapsed ? array_values( array_map( 'intval', $lapsed ) ) : array( 0 ) );
}

/**
 * The query vars for "who holds this one".
 *
 * Separate from the function above rather than folded into it, because the two
 * take different inputs — that one is given a set of volunteers, this one is
 * given a credential and has to go and find them. Sharing a signature would
 * mean one of them ignoring half its arguments.
 *
 * @param int    $credential_id Credential post ID.
 * @param string $standing      '', 'current' or 'expired'.
 * @param string $today         Y-m-d to judge against.
 * @return array<string, mixed>
 */
function gwc_vt_credential_holder_query_vars( int $credential_id, string $standing = '', string $today = '' ): array {
	if ( $credential_id < 1 ) {
		return array();
	}

	$holders = gwc_vt_credential_holder_ids(
		$credential_id,
		isset( gwc_vt_credential_state_options()[ $standing ] ) && '' !== $standing ? $standing : 'any',
		$today
	);

	/* array( 0 ) for the same reason as above: nobody holds it is an answer,
	 * and it must not render as everybody. */
	return array( 'post__in' => $holders ? array_values( $holders ) : array( 0 ) );
}

/**
 * Apply it.
 *
 * @param WP_Query $query The query.
 */
function gwc_vt_apply_credential_filter( $query ): void {
	if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}

	if ( GWC_VT_VOLUNTEER_TYPE !== $query->get( 'post_type' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
	$state = isset( $_GET[ GWC_VT_CREDENTIAL_FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ GWC_VT_CREDENTIAL_FILTER ] ) ) : '';

	if ( ! isset( gwc_vt_credential_filter_options()[ $state ] ) || '' === $state ) {
		return;
	}

	/* A credential ID rather than a named state — "who holds this one". The
	 * options array is what decides: an ID only reaches here if it is a key of
	 * it, so a hand-edited URL naming a post that is not a credential falls
	 * through to nothing rather than to everybody. */
	if ( ctype_digit( $state ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list-table filter; read-only, and core does not nonce these.
		$standing = isset( $_GET[ GWC_VT_CREDENTIAL_STATE ] ) ? sanitize_key( wp_unslash( $_GET[ GWC_VT_CREDENTIAL_STATE ] ) ) : '';

		foreach ( gwc_vt_credential_holder_query_vars( (int) $state, $standing ) as $var => $value ) {
			$query->set( $var, $value );
		}

		return;
	}

	/* The same function the dashboard counted with. One definition, so the
	 * count and the list it links to cannot disagree. */
	foreach ( gwc_vt_credential_query_vars( $state, gwc_vt_lapsed_credential_ids() ) as $var => $value ) {
		$query->set( $var, $value );
	}
}

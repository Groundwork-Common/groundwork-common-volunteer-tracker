<?php
/**
 * The back door the Playwright suite reaches WordPress through.
 *
 *   wp eval-file .../tests/e2e/support/api.php <base64 json>
 *
 * One file rather than a dozen, because the concern is not "settings" or
 * "mail" — it is "what the browser cannot see". Every operation here answers a
 * question a test could not answer through a page, or arranges a state a test
 * could not reach by clicking: what the database holds, what mail left, which
 * capability a role has, what a cron pass would do.
 *
 * ── The rule this file is written under ──────────────────────────────────────
 * Nothing here may do the thing under test. An operation that verified an entry
 * by writing its meta would let a spec pass while gwc_vt_handle_verify_entry()
 * was broken, and the spec would still be called "verifying an entry". So the
 * operations are ARRANGE and INSPECT only — never ACT. Acting is what the
 * browser is for, and the browser is the whole point of this suite.
 *
 * The one deliberate exception is `seed`, which runs tests/seed.php: that is
 * the fixture, not the subject.
 *
 * ── Output ───────────────────────────────────────────────────────────────────
 * Exactly one sentinel-wrapped JSON document, so that a warning, a deprecation
 * or a stray echo lands outside it rather than corrupting the parse. WP_DEBUG
 * and WP_DEBUG_DISPLAY are both on in this environment, so that is a real
 * hazard rather than a careful one.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* ── Refuse to run anywhere that matters ─────────────────────────────────────
 * The same guard tests/seed.php opens with, and for a stronger reason: this
 * file writes an mu-plugin, rewrites settings, and hands back volunteers'
 * addresses. Pointed at a live site it would be a disclosure and a defacement
 * in one command.
 * ─────────────────────────────────────────────────────────────────────────── */
if ( ! in_array( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production', array( 'local', 'development' ), true ) ) {
	echo "Refusing to run: WP_ENVIRONMENT_TYPE is not local or development.\n";
	exit( 1 );
}

/** Everything below runs as the administrator the suite signs in as. */
wp_set_current_user( 1 );

/**
 * Print the one answer and stop.
 *
 * @param mixed $value Anything json_encode() will take.
 */
function gwc_vt_e2e_reply( $value ): void {
	echo "\n<<<GWCVT_E2E\n";
	echo wp_json_encode( $value );
	echo "\nGWCVT_E2E>>>\n";
}

/**
 * One post, flattened to the fields a test can act on.
 *
 * @param WP_Post $post The post.
 * @param array   $meta Meta keys to include, as label => key.
 * @return array
 */
function gwc_vt_e2e_post( WP_Post $post, array $meta = array() ): array {
	$out = array(
		'id'     => (int) $post->ID,
		'title'  => $post->post_title,
		'status' => $post->post_status,
		'parent' => (int) $post->post_parent,
	);

	foreach ( $meta as $label => $key ) {
		$out[ $label ] = get_post_meta( (int) $post->ID, $key, true );
	}

	return $out;
}

/**
 * Every post of a type, whatever its status.
 *
 * `get_post_stati()` rather than 'any', for the reason written into
 * tests/seed.php: all six of this plugin's custom statuses set
 * exclude_from_search, and 'any' means "not excluded from search". A fixture
 * map built with 'any' would silently have no cancelled shift, no waiting list
 * and no retired credential in it — which is most of what there is to test.
 *
 * @param string $type The post type.
 * @param array  $meta Meta keys to include, as label => key.
 * @return array
 */
function gwc_vt_e2e_all( string $type, array $meta = array() ): array {
	$posts = get_posts(
		array(
			'post_type'        => $type,
			'post_status'      => array_values( get_post_stati() ),
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
		)
	);

	$out = array();

	foreach ( $posts as $post ) {
		$out[] = gwc_vt_e2e_post( $post, $meta );
	}

	return $out;
}

/**
 * Which copy of the plugin this container is running.
 *
 * Included in the fixture map as well as offered on its own, so that the
 * per-file reset can check it for free — see reset() in harness.js.
 *
 * @return array
 */
function gwc_vt_e2e_fingerprint(): array {
	return array(
		'dir'     => GWC_VT_DIR,
		'version' => GWC_VT_VERSION,
		'hash'    => md5_file( GWC_VT_FILE ),
	);
}

/**
 * The whole fixture, as the specs see it.
 *
 * Derived from the database rather than written out to match tests/seed.php.
 * A hand-kept copy of the seed is a copy that drifts, and the way you find out
 * is a spec asserting against a volunteer who has been renamed.
 *
 * @return array
 */
function gwc_vt_e2e_fixtures(): array {
	$settings = get_option( GWC_VT_SETTINGS_OPTION, array() );

	$page = static function ( string $key ) use ( $settings ): array {
		$id = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;

		return array(
			'id'  => $id,
			'url' => $id > 0 ? (string) get_permalink( $id ) : '',
		);
	};

	$volunteers = array();

	foreach ( gwc_vt_e2e_all(
		GWC_VT_VOLUNTEER_TYPE,
		array(
			'email'       => GWC_VT_VOLUNTEER_EMAIL,
			'phone'       => GWC_VT_VOLUNTEER_PHONE,
			'required'    => GWC_VT_VOLUNTEER_REQUIRED,
			'requiredBy'  => GWC_VT_VOLUNTEER_REQUIRED_BY,
			'requiredFor' => GWC_VT_VOLUNTEER_REQUIRED_FOR,
			'hold'        => GWC_VT_VOLUNTEER_HOLD,
		)
	) as $row ) {
		$row['editUrl']        = get_edit_post_link( $row['id'], 'raw' );
		$volunteers[ $row['title'] ] = $row;
	}

	$credentials = array();

	foreach ( gwc_vt_e2e_all(
		GWC_VT_CREDENTIAL_TYPE,
		array(
			'months' => GWC_VT_CREDENTIAL_MONTHS,
			'mode'   => GWC_VT_CREDENTIAL_MODE,
		)
	) as $row ) {
		$credentials[ $row['title'] ] = $row;
	}

	$shifts = gwc_vt_e2e_all(
		GWC_VT_SHIFT_TYPE,
		array(
			'date'       => GWC_VT_SHIFT_DATE,
			'start'      => GWC_VT_SHIFT_START,
			'end'        => GWC_VT_SHIFT_END,
			'activity'   => GWC_VT_SHIFT_ACTIVITY,
			'location'   => GWC_VT_SHIFT_LOCATION,
			'min'        => GWC_VT_SHIFT_MIN,
			'max'        => GWC_VT_SHIFT_MAX,
			'series'     => GWC_VT_SHIFT_SERIES,
			'reconciled' => GWC_VT_SHIFT_RECONCILED,
		)
	);

	$events = gwc_vt_e2e_all(
		GWC_VT_EVENT_TYPE,
		array(
			'date'     => GWC_VT_EVENT_DATE,
			'endDate'  => GWC_VT_EVENT_END_DATE,
			'location' => GWC_VT_EVENT_LOCATION,
		)
	);

	foreach ( $events as $index => $event ) {
		$events[ $index ]['pageId'] = function_exists( 'gwc_vt_event_page_id' ) ? (int) gwc_vt_event_page_id( (int) $event['id'] ) : 0;
		$events[ $index ]['pageUrl'] = $events[ $index ]['pageId'] > 0 ? (string) get_permalink( $events[ $index ]['pageId'] ) : '';
		$events[ $index ]['slots']   = array();

		foreach ( $shifts as $shift ) {
			if ( $shift['parent'] === $event['id'] ) {
				$events[ $index ]['slots'][] = $shift['id'];
			}
		}
	}

	$letters = gwc_vt_e2e_all(
		GWC_VT_LETTER_TYPE,
		array(
			'volunteer' => GWC_VT_LETTER_VOLUNTEER,
			'minutes'   => GWC_VT_LETTER_MINUTES,
			'asOf'      => GWC_VT_LETTER_AS_OF,
		)
	);

	return array(
		'baseUrl'     => home_url(),
		'adminUrl'    => admin_url(),
		'fingerprint' => gwc_vt_e2e_fingerprint(),
		'pages'       => array(
			'selfLog'      => $page( 'self_log_page' ),
			'schedule'     => $page( 'schedule_page' ),
			'registration' => $page( 'registration_page' ),
			'signin'       => $page( 'signin_page' ),
		),
		'volunteers'  => $volunteers,
		'credentials' => $credentials,
		'shifts'      => $shifts,
		'events'      => $events,
		'letters'     => $letters,
		'entries'     => gwc_vt_e2e_all(
			GWC_VT_ENTRY_TYPE,
			array(
				'volunteer'  => GWC_VT_ENTRY_VOLUNTEER,
				'date'       => GWC_VT_ENTRY_DATE,
				'minutes'    => GWC_VT_ENTRY_MINUTES,
				'activity'   => GWC_VT_ENTRY_ACTIVITY,
				'source'     => GWC_VT_ENTRY_SOURCE,
				'verifiedAt' => GWC_VT_ENTRY_VERIFIED_AT,
				'claimName'  => GWC_VT_ENTRY_CLAIM_NAME,
				'claimEmail' => GWC_VT_ENTRY_CLAIM_EMAIL,
				'shift'      => GWC_VT_ENTRY_SHIFT,
			)
		),
		'signups'     => gwc_vt_e2e_all(
			GWC_VT_SIGNUP_TYPE,
			array(
				'volunteer'  => GWC_VT_SIGNUP_VOLUNTEER,
				'claimName'  => GWC_VT_SIGNUP_CLAIM_NAME,
				'claimEmail' => GWC_VT_SIGNUP_CLAIM_EMAIL,
				'entry'      => GWC_VT_SIGNUP_ENTRY,
			)
		),
		'applications' => gwc_vt_e2e_all(
			GWC_VT_APPLICATION_TYPE,
			array(
				'name'  => GWC_VT_APPLICATION_NAME,
				'email' => GWC_VT_APPLICATION_EMAIL,
				'phone' => GWC_VT_APPLICATION_PHONE,
			)
		),
		'settings'    => $settings,
	);
}

/**
 * Take the site back to having no volunteer-tracker records at all.
 *
 * ── Why the seed's own clear-out is not enough ───────────────────────────────
 * tests/seed.php removes what a previous SEED run created, by its mark. That is
 * the right scope for a demo fixture, and the wrong one for a test suite: this
 * environment also holds whatever the integration scripts, a manual afternoon
 * and an earlier version of this suite left behind, none of it marked.
 *
 * Unmarked leftovers are not harmless. They are in the verify queue, they are
 * in the dashboard's counts, and they push the seeded records onto page two of
 * a list table — which is exactly how the first run of verify.spec.js failed:
 * it found a verified entry in the fixture map, went to the hours list, and the
 * row was not there, because the entry it had picked was three years old and
 * twenty rows down.
 *
 * So the suite starts from nothing. That is a stronger claim than the seed
 * makes, and it is why this lives here rather than in tests/seed.php: a demo
 * site full of somebody's own experiments should keep them.
 *
 * @return array What was removed.
 */
function gwc_vt_e2e_purge(): array {
	$types = array(
		GWC_VT_ENTRY_TYPE,
		GWC_VT_VOLUNTEER_TYPE,
		GWC_VT_LETTER_TYPE,
		GWC_VT_DRAFT_TYPE,
		GWC_VT_SHIFT_TYPE,
		GWC_VT_EVENT_TYPE,
		GWC_VT_SIGNUP_TYPE,
		GWC_VT_APPLICATION_TYPE,
		GWC_VT_CREDENTIAL_TYPE,
		GWC_VT_RECORD_TYPE,
	);

	$removed = 0;

	foreach ( $types as $type ) {
		$found = get_posts(
			array(
				'post_type'      => $type,
				/* Every registered status, and NOT 'any' — six of this
				 * plugin's statuses set exclude_from_search, which is what
				 * 'any' means. A purge using 'any' leaves every cancelled
				 * shift, waiting-list signup and retired credential behind,
				 * which is most of what there is to test. */
				'post_status'    => array_values( get_post_stati() ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $found as $id ) {
			wp_delete_post( (int) $id, true );
			++$removed;
		}
	}

	/* And every organization.
	 *
	 * Terms are not posts, so the loop above cannot reach them — and unlike a
	 * leftover post, a leftover term is not merely noise. The Organizations
	 * screen leads with proposed duplicates, and a previous run's "Zzytest
	 * Acme Corp" would sit at the top of it proposing a merge no spec asked
	 * for. Same reasoning as the status list above: the purge has to know about
	 * everything the plugin stores, not everything that happens to be a post.
	 *
	 * Read from the taxonomy rather than named, so this covers hour entries the
	 * day #211 registers them. */
	foreach ( array( GWC_VT_PARTNER_TAXONOMY ) as $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		foreach ( ( is_wp_error( $terms ) ? array() : (array) $terms ) as $term_id ) {
			wp_delete_term( (int) $term_id, $taxonomy );
			++$removed;
		}
	}

	/* And the pages the seed puts its blocks on.
	 *
	 * Not for tidiness: a page whose slug the seed wants is a page the seed
	 * cannot have, so wp_insert_post() takes `log-your-hours-2` instead and
	 * every URL in the fixture map quietly grows a suffix. That happened, and
	 * it is the reason the map hands back permalinks rather than slugs — but
	 * an ever-lengthening slug is still a fixture drifting, so the old page
	 * goes.
	 *
	 * Matched on the plugin's own markup, which is what makes a page this
	 * suite's rather than the site's — and matched TWICE, once for each way a
	 * thing can be placed. The block serializes as
	 * `wp:groundwork-common-volunteer-tracker/...` and the shortcode as
	 * `[gwc_vt_...`; neither marker contains the other, so one search misses
	 * one placement. That is the same trap gwc_vt_event_page_id() is written
	 * around, and it is written down in CLAUDE.md because it has already cost
	 * somebody an afternoon once. */
	$marked = array();

	foreach ( array( 'groundwork-common-volunteer-tracker/', '[gwc_vt_' ) as $marker ) {
		foreach ( get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array_values( get_post_stati() ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				's'              => $marker,
			)
		) as $id ) {
			$marked[ (int) $id ] = true;
		}
	}

	foreach ( array_keys( $marked ) as $id ) {
		wp_delete_post( (int) $id, true );
		++$removed;
	}

	return array( 'removed' => $removed );
}

/* ── The mail trap ───────────────────────────────────────────────────────────
 * On `pre_wp_mail`, never `phpmailer_init`. The reasoning is in CLAUDE.md and
 * it is not a style preference: phpmailer_init cannot stop a send, so when the
 * container has no MTA wp_mail() returns false — and this plugin reads that
 * return to decide a reminder was not delivered, and retries it on the next
 * cron pass. A suite that trapped mail the other way would be testing a
 * delivery failure loop that production never has.
 *
 * The trap is an mu-plugin because it has to be loaded on requests the browser
 * makes, which no amount of WP-CLI can reach into.
 * ─────────────────────────────────────────────────────────────────────────── */

/** Where the trapped messages accumulate. */
const GWC_VT_E2E_MAILBOX = 'gwc_vt_e2e_mailbox';

/**
 * Write the mu-plugin, if it is not already the file this version wants.
 *
 * @return array What was done.
 */
function gwc_vt_e2e_install_mail_trap(): array {
	$dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$file = $dir . '/gwc-vt-e2e-mailbox.php';

	$source = <<<'PHP'
<?php
/**
 * Plugin Name: Volunteer Tracker e2e mailbox
 * Description: Traps outgoing mail so the Playwright suite can read it. Written by tests/e2e/support/api.php; never part of a release.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return $short;
		}

		$box = get_option( 'gwc_vt_e2e_mailbox', array() );

		if ( ! is_array( $box ) ) {
			$box = array();
		}

		$box[] = array(
			'to'      => (array) ( $atts['to'] ?? array() ),
			'subject' => (string) ( $atts['subject'] ?? '' ),
			'message' => (string) ( $atts['message'] ?? '' ),
			'headers' => $atts['headers'] ?? array(),
			'at'      => time(),
		);

		/* Bounded, so a runaway cron pass cannot fill the options table. The
		 * suite clears the box before each test that reads it, so anything
		 * beyond this is a test that has already gone wrong. */
		if ( count( $box ) > 200 ) {
			$box = array_slice( $box, -200 );
		}

		update_option( 'gwc_vt_e2e_mailbox', $box, false );

		/* True, not $short. Short-circuiting wp_mail() with true is the answer
		 * production would have given, and this plugin reads that return. */
		return true;
	},
	10,
	2
);
PHP;

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	$already = file_exists( $file ) && file_get_contents( $file ) === $source;

	if ( ! $already ) {
		file_put_contents( $file, $source );
	}

	return array(
		'file'    => $file,
		'written' => ! $already,
	);
}

/* ── Dispatch ────────────────────────────────────────────────────────────── */

$gwc_vt_e2e_args = isset( $args[0] ) ? json_decode( base64_decode( $args[0] ), true ) : array();

if ( ! is_array( $gwc_vt_e2e_args ) ) {
	$gwc_vt_e2e_args = array();
}

$gwc_vt_e2e_op = isset( $gwc_vt_e2e_args['op'] ) ? (string) $gwc_vt_e2e_args['op'] : '';

switch ( $gwc_vt_e2e_op ) {

	/* Rebuild the fixture from scratch and hand back the map. */
	case 'seed':
		gwc_vt_e2e_install_mail_trap();
		delete_option( GWC_VT_E2E_MAILBOX );
		delete_option( GWC_VT_RATE_LIMIT_OPTION );
		gwc_vt_e2e_purge();

		ob_start();
		require GWC_VT_DIR . 'tests/seed.php';
		$gwc_vt_e2e_seed_output = ob_get_clean();

		gwc_vt_settings_cache( null, true );
		wp_cache_flush();

		gwc_vt_e2e_reply( gwc_vt_e2e_fixtures() );
		break;

	/* Which copy of the plugin is the container actually running?
	 *
	 * One environment is shared by every worktree of this plugin — that is the
	 * whole point of bin/wpenv — and `start` remounts it on whichever worktree
	 * ran it last. So a second session working in a sibling branch takes the
	 * site over, silently, in the middle of a run.
	 *
	 * The failure that causes is not a failure that reads as one. Files stop
	 * existing halfway through ("api.php does not exist"), or worse, they do not:
	 * a spec asserts against a screen from another branch and fails on something
	 * true of that branch. The first full run of this suite lost one letters
	 * test to exactly that and it looked like a flake.
	 *
	 * A hash of the bootstrap, compared against the same file on disk, is the
	 * cheapest way to turn all of that into one sentence.
	 */
	case 'fingerprint':
		gwc_vt_e2e_reply( gwc_vt_e2e_fingerprint() );
		break;

	/* The map, without rebuilding anything. */
	case 'fixtures':
		gwc_vt_e2e_reply( gwc_vt_e2e_fixtures() );
		break;

	/* Empty the site without reseeding it — the state a plugin is installed
	 * into, which is what the welcome notice exists for and the only state it
	 * appears in. */
	case 'purge':
		gwc_vt_e2e_reply( gwc_vt_e2e_purge() );
		break;

	/* One user's meta: read it, set it, or clear it.
	 *
	 * Three explicit modes rather than two inferred ones. The first version
	 * wrote when a value was given and DELETED otherwise, which made every read
	 * a destructive one — so the welcome spec asked what the handler had just
	 * written, erased it in the asking, and reported that the handler wrote
	 * nothing.
	 *
	 * Arrange and inspect either way: the thing under test is the handler that
	 * writes it. */
	case 'user.meta':
		$gwc_vt_e2e_who = get_user_by( 'login', (string) ( $gwc_vt_e2e_args['login'] ?? '' ) );

		if ( ! $gwc_vt_e2e_who ) {
			gwc_vt_e2e_reply( array( 'error' => 'no such user' ) );
			break;
		}

		if ( array_key_exists( 'value', $gwc_vt_e2e_args ) ) {
			update_user_meta( $gwc_vt_e2e_who->ID, (string) $gwc_vt_e2e_args['key'], $gwc_vt_e2e_args['value'] );
		} elseif ( ! empty( $gwc_vt_e2e_args['clear'] ) ) {
			delete_user_meta( $gwc_vt_e2e_who->ID, (string) $gwc_vt_e2e_args['key'] );
		}

		gwc_vt_e2e_reply(
			array(
				'id'    => (int) $gwc_vt_e2e_who->ID,
				'value' => get_user_meta( $gwc_vt_e2e_who->ID, (string) $gwc_vt_e2e_args['key'], true ),
			)
		);
		break;

	case 'mail.install':
		gwc_vt_e2e_reply( gwc_vt_e2e_install_mail_trap() );
		break;

	case 'mail.read':
		gwc_vt_e2e_reply( array_values( (array) get_option( GWC_VT_E2E_MAILBOX, array() ) ) );
		break;

	case 'mail.clear':
		delete_option( GWC_VT_E2E_MAILBOX );
		gwc_vt_e2e_reply( array( 'cleared' => true ) );
		break;

	/* Overlay settings. Merged rather than replaced, so a spec names only what
	 * it cares about and the rest of the fixture stays as the seed left it. */
	case 'settings.set':
		$gwc_vt_e2e_now = get_option( GWC_VT_SETTINGS_OPTION, array() );
		update_option( GWC_VT_SETTINGS_OPTION, array_merge( (array) $gwc_vt_e2e_now, (array) ( $gwc_vt_e2e_args['values'] ?? array() ) ) );
		gwc_vt_settings_cache( null, true );
		gwc_vt_e2e_reply( get_option( GWC_VT_SETTINGS_OPTION, array() ) );
		break;

	case 'settings.get':
		gwc_vt_e2e_reply( get_option( GWC_VT_SETTINGS_OPTION, array() ) );
		break;

	/* One post's meta, for the assertions a screen does not print. */
	case 'post.meta':
		$gwc_vt_e2e_id = (int) ( $gwc_vt_e2e_args['id'] ?? 0 );
		$gwc_vt_e2e_p  = get_post( $gwc_vt_e2e_id );

		gwc_vt_e2e_reply(
			array(
				'exists' => $gwc_vt_e2e_p instanceof WP_Post,
				'status' => $gwc_vt_e2e_p instanceof WP_Post ? $gwc_vt_e2e_p->post_status : '',
				'title'  => $gwc_vt_e2e_p instanceof WP_Post ? $gwc_vt_e2e_p->post_title : '',
				'parent' => $gwc_vt_e2e_p instanceof WP_Post ? (int) $gwc_vt_e2e_p->post_parent : 0,
				'meta'   => array_map(
					static function ( $v ) {
						return is_array( $v ) && 1 === count( $v ) ? $v[0] : $v;
					},
					(array) get_post_meta( $gwc_vt_e2e_id )
				),
			)
		);
		break;

	/* Every term in a taxonomy, with its meta and its parent.
	 *
	 * Inspect only, like everything else here. A merge is driven through the
	 * screen — asserting it by writing term relationships would let a spec pass
	 * while gwc_vt_merge_partners() was broken, which is the whole rule this file
	 * is written under. */
	case 'terms':
		$gwc_vt_e2e_tax   = (string) ( $gwc_vt_e2e_args['taxonomy'] ?? '' );
		$gwc_vt_e2e_found = get_terms(
			array(
				'taxonomy'   => $gwc_vt_e2e_tax,
				'hide_empty' => false,
			)
		);

		gwc_vt_e2e_reply(
			array_map(
				static function ( $gwc_vt_e2e_term ) {
					return array(
						'id'     => (int) $gwc_vt_e2e_term->term_id,
						'name'   => (string) $gwc_vt_e2e_term->name,
						'parent' => (int) $gwc_vt_e2e_term->parent,
						'meta'   => array_map(
							static function ( $gwc_vt_e2e_v ) {
								return is_array( $gwc_vt_e2e_v ) && 1 === count( $gwc_vt_e2e_v ) ? $gwc_vt_e2e_v[0] : $gwc_vt_e2e_v;
							},
							(array) get_term_meta( (int) $gwc_vt_e2e_term->term_id )
						),
					);
				},
				is_wp_error( $gwc_vt_e2e_found ) ? array() : (array) $gwc_vt_e2e_found
			)
		);
		break;

	/* What one post holds in one taxonomy, by name. */
	case 'object.terms':
		$gwc_vt_e2e_held = wp_get_object_terms(
			(int) ( $gwc_vt_e2e_args['id'] ?? 0 ),
			(string) ( $gwc_vt_e2e_args['taxonomy'] ?? '' ),
			array( 'fields' => 'names' )
		);

		gwc_vt_e2e_reply( is_wp_error( $gwc_vt_e2e_held ) ? array() : array_values( (array) $gwc_vt_e2e_held ) );
		break;

	/* An organization to arrange a test around. Creating one is driven through
	 * the screen in the spec that covers gwc_vt_add_partner; this exists for the
	 * specs that need one to already be there. */
	case 'term.ensure':
		$gwc_vt_e2e_made = wp_insert_term(
			(string) ( $gwc_vt_e2e_args['name'] ?? '' ),
			(string) ( $gwc_vt_e2e_args['taxonomy'] ?? '' ),
			array( 'parent' => (int) ( $gwc_vt_e2e_args['parent'] ?? 0 ) )
		);

		if ( is_wp_error( $gwc_vt_e2e_made ) ) {
			$gwc_vt_e2e_was = term_exists(
				(string) ( $gwc_vt_e2e_args['name'] ?? '' ),
				(string) ( $gwc_vt_e2e_args['taxonomy'] ?? '' )
			);

			gwc_vt_e2e_reply( array( 'id' => (int) ( $gwc_vt_e2e_was['term_id'] ?? 0 ) ) );
			break;
		}

		gwc_vt_e2e_reply( array( 'id' => (int) $gwc_vt_e2e_made['term_id'] ) );
		break;

	/* Attach a post to terms, to arrange a merge worth watching. */
	case 'object.terms.set':
		wp_set_object_terms(
			(int) ( $gwc_vt_e2e_args['id'] ?? 0 ),
			array_map( 'intval', (array) ( $gwc_vt_e2e_args['terms'] ?? array() ) ),
			(string) ( $gwc_vt_e2e_args['taxonomy'] ?? '' )
		);

		gwc_vt_e2e_reply( array( 'ok' => true ) );
		break;

	/* Every post of a type, with the meta the caller names. */
	case 'posts':
		gwc_vt_e2e_reply(
			gwc_vt_e2e_all(
				(string) ( $gwc_vt_e2e_args['type'] ?? '' ),
				(array) ( $gwc_vt_e2e_args['meta'] ?? array() )
			)
		);
		break;

	/* A user in a named role, so capability paths can be driven by somebody
	 * who is not the administrator. Idempotent: the same login comes back. */
	case 'user.ensure':
		$gwc_vt_e2e_login = (string) ( $gwc_vt_e2e_args['login'] ?? '' );
		$gwc_vt_e2e_role  = (string) ( $gwc_vt_e2e_args['role'] ?? 'editor' );
		$gwc_vt_e2e_user  = get_user_by( 'login', $gwc_vt_e2e_login );

		if ( ! $gwc_vt_e2e_user ) {
			$gwc_vt_e2e_uid  = wp_insert_user(
				array(
					'user_login' => $gwc_vt_e2e_login,
					'user_pass'  => (string) ( $gwc_vt_e2e_args['pass'] ?? 'e2e-password' ),
					'user_email' => $gwc_vt_e2e_login . '@example.test',
					'role'       => $gwc_vt_e2e_role,
				)
			);
			$gwc_vt_e2e_user = get_user_by( 'id', $gwc_vt_e2e_uid );
		} else {
			$gwc_vt_e2e_user->set_role( $gwc_vt_e2e_role );
			wp_set_password( (string) ( $gwc_vt_e2e_args['pass'] ?? 'e2e-password' ), $gwc_vt_e2e_user->ID );
		}

		gwc_vt_e2e_reply(
			array(
				'id'    => (int) $gwc_vt_e2e_user->ID,
				'login' => $gwc_vt_e2e_user->user_login,
				'role'  => $gwc_vt_e2e_role,
				'caps'  => array_keys( array_filter( $gwc_vt_e2e_user->allcaps ) ),
			)
		);
		break;

	/* Grant or withdraw one capability on one role.
	 *
	 * add_cap( $cap, false ) rather than remove_cap(), where the caller asks
	 * for false: this plugin reads capabilities with isset() rather than
	 * truthiness, precisely so that "an administrator decided no" and "this
	 * role never heard of it" are different answers. A test of the first has
	 * to be able to write the first. */
	case 'role.cap':
		$gwc_vt_e2e_role_obj = get_role( (string) ( $gwc_vt_e2e_args['role'] ?? '' ) );
		$gwc_vt_e2e_cap      = (string) ( $gwc_vt_e2e_args['cap'] ?? '' );

		if ( ! $gwc_vt_e2e_role_obj ) {
			gwc_vt_e2e_reply( array( 'error' => 'no such role' ) );
			break;
		}

		if ( array_key_exists( 'grant', $gwc_vt_e2e_args ) && null === $gwc_vt_e2e_args['grant'] ) {
			$gwc_vt_e2e_role_obj->remove_cap( $gwc_vt_e2e_cap );
		} else {
			$gwc_vt_e2e_role_obj->add_cap( $gwc_vt_e2e_cap, (bool) ( $gwc_vt_e2e_args['grant'] ?? true ) );
		}

		gwc_vt_e2e_reply(
			array(
				'role' => $gwc_vt_e2e_role_obj->name,
				'caps' => $gwc_vt_e2e_role_obj->capabilities,
			)
		);
		break;

	/* Fire a scheduled hook now, without waiting for WP-Cron.
	 *
	 * do_action() rather than `wp cron event run`, because the point is to
	 * drive the callback the plugin registered rather than to test WordPress's
	 * scheduler — and because the event may not be due. */
	case 'cron.fire':
		$gwc_vt_e2e_hook = (string) ( $gwc_vt_e2e_args['hook'] ?? '' );

		ob_start();
		do_action( $gwc_vt_e2e_hook );
		$gwc_vt_e2e_noise = ob_get_clean();

		gwc_vt_e2e_reply(
			array(
				'hook'  => $gwc_vt_e2e_hook,
				'noise' => $gwc_vt_e2e_noise,
			)
		);
		break;

	/* Move a post's date meta, so "yesterday" and "in two days" can be reached
	 * without waiting. Arrange, not act: it writes the fixture's clock, never
	 * a field the plugin's own handlers own. */
	case 'post.date':
		$gwc_vt_e2e_id = (int) ( $gwc_vt_e2e_args['id'] ?? 0 );

		foreach ( (array) ( $gwc_vt_e2e_args['meta'] ?? array() ) as $gwc_vt_e2e_k => $gwc_vt_e2e_v ) {
			update_post_meta( $gwc_vt_e2e_id, (string) $gwc_vt_e2e_k, $gwc_vt_e2e_v );
		}

		gwc_vt_e2e_reply( array( 'id' => $gwc_vt_e2e_id ) );
		break;

	/* Delete what a spec created, by title prefix, across every type and every
	 * status. Specs name what they make with a unique prefix so that this can
	 * be exact rather than a truncation of the whole fixture. */
	/* A page carrying whatever markup the caller hands over, so a block can be
	 * opened in the editor and rendered on the front. Arrange, not act: it
	 * writes a page, which is WordPress's job rather than this plugin's. */
	case 'page.create':
		$gwc_vt_e2e_page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => (string) ( $gwc_vt_e2e_args['title'] ?? 'e2e' ),
				'post_content' => (string) ( $gwc_vt_e2e_args['content'] ?? '' ),
			)
		);

		gwc_vt_e2e_reply(
			array(
				'id'  => (int) $gwc_vt_e2e_page,
				'url' => (string) get_permalink( (int) $gwc_vt_e2e_page ),
			)
		);
		break;

	case 'cleanup':
		$gwc_vt_e2e_prefix  = (string) ( $gwc_vt_e2e_args['prefix'] ?? '' );
		$gwc_vt_e2e_removed = 0;

		if ( '' !== $gwc_vt_e2e_prefix ) {
			$gwc_vt_e2e_found = get_posts(
				array(
					'post_type'      => array( GWC_VT_ENTRY_TYPE, GWC_VT_VOLUNTEER_TYPE, GWC_VT_LETTER_TYPE, GWC_VT_DRAFT_TYPE, GWC_VT_SHIFT_TYPE, GWC_VT_EVENT_TYPE, GWC_VT_SIGNUP_TYPE, GWC_VT_APPLICATION_TYPE, GWC_VT_CREDENTIAL_TYPE, GWC_VT_RECORD_TYPE, 'page' ),
					'post_status'    => array_values( get_post_stati() ),
					'posts_per_page' => -1,
					's'              => $gwc_vt_e2e_prefix,
				)
			);

			foreach ( $gwc_vt_e2e_found as $gwc_vt_e2e_post ) {
				wp_delete_post( (int) $gwc_vt_e2e_post->ID, true );
				++$gwc_vt_e2e_removed;
			}
		}

		gwc_vt_e2e_reply( array( 'removed' => $gwc_vt_e2e_removed ) );
		break;

	default:
		gwc_vt_e2e_reply( array( 'error' => 'unknown op', 'op' => $gwc_vt_e2e_op ) );
}

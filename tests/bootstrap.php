<?php
/**
 * Test bootstrap.
 *
 * ── No database, no WordPress checkout ───────────────────────────────────────
 * The logic worth unit testing here — the hour parser, the schema's
 * self-healing, the totals arithmetic, the letter's reference code, the rate
 * limiter's windows — is pure. Making it depend on a WordPress test install
 * would mean a suite that takes a minute to start, cannot run on a laptop
 * without MySQL, and gets skipped.
 *
 * So this file stubs the WordPress surface those functions touch: an in-memory
 * option, meta and post store, the escaping and sanitizing helpers, and a small
 * role and user double. Every stub is deliberately the SIMPLEST thing that
 * behaves correctly for the cases under test, and where a stub is weaker than
 * the real function that is noted beside it — a test that passes against a stub
 * which is more permissive than WordPress is a test that proves nothing.
 *
 * Anything that genuinely needs WordPress — the REST route's registration, the
 * meta boxes, the rendered letter, the privacy exporters — is covered by the
 * scripts under tests/integration/, which run under wp-env.
 *
 * @package VolunteerTracker
 */

define( 'ABSPATH', __DIR__ . '/' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

define( 'GWC_VT_DIR', dirname( __DIR__ ) . '/' );
define( 'GWC_VT_URL', 'https://example.test/wp-content/plugins/groundwork-common-volunteer-tracker/' );
define( 'GWC_VT_FILE', GWC_VT_DIR . 'groundwork-common-volunteer-tracker.php' );

const GWC_VT_SCHEMA_VERSION = 1;
const GWC_VT_SPONSOR_URL    = 'https://www.groundworkcommon.com/support/';
const GWC_VT_GWC_URL        = 'https://www.groundworkcommon.com/';

/* Read out of the plugin header rather than hardcoded, so VersionTest compares
 * the header against readme.txt rather than against a copy of one of them made
 * in this file. */
$gwc_vt_header = (string) file_get_contents( GWC_VT_DIR . 'groundwork-common-volunteer-tracker.php' );
preg_match( "/GWC_VT_VERSION\s*=\s*'([^']+)'/", $gwc_vt_header, $gwc_vt_m );
define( 'GWC_VT_VERSION', $gwc_vt_m[1] ?? '0.0.0' );

/* ── The in-memory store ─────────────────────────────────────────────────── */

$GLOBALS['gwc_vt_test'] = array(
	'options'    => array(),
	'transients' => array(),
	'post_meta' => array(),
	'user_meta' => array(),
	'posts'     => array(),
	'users'     => array(),
	'roles'     => array(),
	'mail'      => array(),
	'cron'      => array(),
);

$GLOBALS['gwc_vt_test_filters'] = array();

/**
 * Reset everything between tests.
 *
 * Also clears the settings memo, which is otherwise the single most common
 * source of a test that passes alone and fails in a suite.
 */
function gwc_vt_test_reset(): void {
	$GLOBALS['gwc_vt_test'] = array(
		'options'    => array(),
		'transients' => array(),
		'post_meta' => array(),
		'user_meta' => array(),
		'posts'     => array(),
		'users'     => array(),
		'roles'     => array(),
		'mail'      => array(),
		'cron'      => array(),
	);

	gwc_vt_settings_cache( null, true );
}

/**
 * Forget every registered filter.
 *
 * Separate from gwc_vt_test_reset() and called by almost nothing, on purpose:
 * the plugin's registries are built by self-registering filters at require
 * time, and a test that clears them has emptied a registry it cannot refill
 * without re-including the files.
 */
function gwc_vt_test_reset_filters(): void {
	$GLOBALS['gwc_vt_test_filters'] = array();
}

/* ── Options ─────────────────────────────────────────────────────────────── */

function get_option( $name, $default_value = false ) {
	return $GLOBALS['gwc_vt_test']['options'][ $name ] ?? $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['gwc_vt_test']['options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( isset( $GLOBALS['gwc_vt_test']['options'][ $name ] ) ) {
		return false;
	}
	$GLOBALS['gwc_vt_test']['options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['gwc_vt_test']['options'][ $name ] );
	return true;
}

/* ── User meta ───────────────────────────────────────────────────────────── */

function get_user_meta( $user_id, $key = '', $single = false ) {
	$value = $GLOBALS['gwc_vt_test']['user_meta'][ $user_id ][ $key ] ?? '';
	return $single ? $value : array( $value );
}

function update_user_meta( $user_id, $key, $value, $prev = '' ) {
	$GLOBALS['gwc_vt_test']['user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key, $value = '' ) {
	unset( $GLOBALS['gwc_vt_test']['user_meta'][ $user_id ][ $key ] );
	return true;
}

/* ── Roles and capabilities ──────────────────────────────────────────────────
 * A real object with a real add_cap(), because gwc_vt_grant_capabilities()
 * deliberately reads $role->capabilities before writing — a double whose
 * add_cap() did nothing would let the "it does not rewrite the role every
 * request" test pass without the guard being there at all.
 * ─────────────────────────────────────────────────────────────────────────── */

class WP_Role { // phpcs:ignore
	public $name         = '';
	public $capabilities = array();

	/** How many times add_cap() was called. Asserted by CapsTest. */
	public $writes = 0;

	public function __construct( string $name, array $capabilities = array() ) {
		$this->name         = $name;
		$this->capabilities = $capabilities;
	}

	public function add_cap( $cap, $grant = true ) {
		$this->capabilities[ $cap ] = $grant;
		++$this->writes;
	}

	public function remove_cap( $cap ) {
		unset( $this->capabilities[ $cap ] );
		++$this->writes;
	}

	public function has_cap( $cap ) {
		return ! empty( $this->capabilities[ $cap ] );
	}
}

function gwc_vt_test_add_role( string $name, array $caps = array() ): WP_Role {
	$role = new WP_Role( $name, $caps );
	$GLOBALS['gwc_vt_test']['roles'][ $name ] = $role;
	return $role;
}

function get_role( $name ) {
	return $GLOBALS['gwc_vt_test']['roles'][ $name ] ?? null;
}

function gwc_vt_test_add_user( int $id, array $caps = array(), string $role = '', string $display_name = '' ): void {
	$GLOBALS['gwc_vt_test']['users'][ $id ] = array(
		'caps'         => $caps,
		'role'         => $role,
		'display_name' => $display_name,
	);
}

/**
 * Whether a user has a capability.
 *
 * Weaker than WordPress on purpose and in a way worth naming: it does not
 * implement map_meta_cap, so a call like user_can( $id, 'edit_post', $post_id )
 * is answered from an explicitly granted 'edit_post' rather than by mapping to
 * the post type's capabilities and checking ownership. Tests that care about
 * the mapping itself belong in tests/integration/caps.php, under real
 * WordPress; what this stub can honestly prove is that gwc_vt_user_can_verify()
 * requires BOTH answers.
 */
function user_can( $user_id, $capability, ...$args ) {
	$user = $GLOBALS['gwc_vt_test']['users'][ (int) $user_id ] ?? null;
	if ( ! $user ) {
		return false;
	}

	if ( ! empty( $user['caps'][ $capability ] ) ) {
		return true;
	}

	$role = $user['role'] ? get_role( $user['role'] ) : null;

	return $role ? $role->has_cap( $capability ) : false;
}

function current_user_can( $capability, ...$args ) {
	return user_can( get_current_user_id(), $capability, ...$args );
}

function get_current_user_id() {
	return (int) ( $GLOBALS['gwc_vt_test']['current_user'] ?? 0 );
}

function gwc_vt_test_set_current_user( int $id ): void {
	$GLOBALS['gwc_vt_test']['current_user'] = $id;
}

/* ── Hooks ───────────────────────────────────────────────────────────────────
 * add_filter and apply_filters are REAL, priority-ordered and all, because
 * several of this plugin's registries are built by filter: the field types, the
 * attestation methods and the schema migrations all self-register rather than
 * being listed anywhere.
 *
 * The post portal's bootstrap records what happened when its own version of
 * this file made apply_filters a no-op returning its value untouched: every
 * test still passed, and every one of them was testing a registry containing
 * only the built-in types. Two tests that should have failed passed because the
 * lookup fell through to its unknown-type fallback. Worth repeating here rather
 * than rediscovering.
 *
 * add_action and do_action stay no-ops: nothing under test depends on an action
 * firing, and unlike the filters above, none of them build anything.
 * ─────────────────────────────────────────────────────────────────────────── */

function add_action( ...$args ) {
	return true;
}

function do_action( ...$args ) {
	return null;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['gwc_vt_test_filters'][ $hook ][ (int) $priority ] ) ) {
		return false;
	}

	foreach ( $GLOBALS['gwc_vt_test_filters'][ $hook ][ (int) $priority ] as $i => $registered ) {
		if ( $registered['cb'] === $callback ) {
			unset( $GLOBALS['gwc_vt_test_filters'][ $hook ][ (int) $priority ][ $i ] );
			return true;
		}
	}

	return false;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['gwc_vt_test_filters'][ $hook ][ (int) $priority ][] = array(
		'cb'   => $callback,
		'args' => (int) $accepted_args,
	);
	return true;
}

function apply_filters( $hook, $value, ...$rest ) {
	if ( empty( $GLOBALS['gwc_vt_test_filters'][ $hook ] ) ) {
		return $value;
	}

	$by_priority = $GLOBALS['gwc_vt_test_filters'][ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $registered ) {
		foreach ( $registered as $filter ) {
			$args  = array_merge( array( $value ), $rest );
			$args  = array_slice( $args, 0, max( 1, $filter['args'] ) );
			$value = call_user_func_array( $filter['cb'], $args );
		}
	}

	return $value;
}

/* ── Strings ─────────────────────────────────────────────────────────────── */

function __( $text, $domain = 'default' ) {
	return $text;
}

function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

/* The nooped-plural pair. Real, because the dashboard's worklist builds its
 * sentences through them and a stub that returned the singular always would
 * let a broken plural through every assertion. */
function _n_noop( $singular, $plural, $domain = null ) {
	return array(
		0          => $singular,
		1          => $plural,
		'singular' => $singular,
		'plural'   => $plural,
		'context'  => null,
		'domain'   => $domain,
	);
}

function translate_nooped_plural( $nooped_plural, $count, $domain = 'default' ) {
	return 1 === (int) $count ? $nooped_plural['singular'] : $nooped_plural['plural'];
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
}

function sanitize_textarea_field( $str ) {
	return trim( wp_strip_all_tags( (string) $str ) );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	return strip_tags( (string) $text );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

/* Counts per status. The suite has no database, so every status is zero — which
 * is the honest answer and the useful one: a views test that depended on there
 * being applications waiting would be asserting the fixture, not the filter. */
function admin_url( $path = '', $scheme = 'admin' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function wp_count_posts( $type = 'post', $perm = '' ) {
	$counts = new stdClass();

	foreach ( array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ) as $status ) {
		$counts->{$status} = 0;
	}

	return $counts;
}

function get_bloginfo( $show = '', $filter = 'raw' ) {
	return 'name' === $show ? 'Test Food Bank' : '';
}

/* ── Time ───────────────────────────────────────────────────────────────────
 * Honest UTC. The plugin's date arithmetic is all calendar-date arithmetic —
 * an hour entry has a date and no instant — so a stub that pretends the site is
 * in UTC exercises the same code paths a real timezone would, and the one thing
 * it cannot prove (that current_time() respects the site's offset) is
 * WordPress's job rather than ours.
 * ─────────────────────────────────────────────────────────────────────────── */

function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

function current_time( $type, $gmt = 0 ) {
	if ( 'timestamp' === $type ) {
		return time();
	}
	return gmdate( 'mysql' === $type ? 'Y-m-d H:i:s' : $type );
}

function get_post_field( $field, $post_id = null, $context = 'display' ) {
	$post = $GLOBALS['gwc_vt_test']['posts'][ (int) $post_id ] ?? null;
	return $post[ $field ] ?? '';
}

function sanitize_email( $email ) {
	return (string) filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
}

function wp_list_pluck( $list, $field ) {
	return array_map( static fn( $row ) => is_array( $row ) ? ( $row[ $field ] ?? null ) : null, (array) $list );
}

/* Deliberately absent: get_posts(). The retention sweep and the privacy
 * exporters query the database, and a stub returning array() would make every
 * assertion about them pass while proving nothing. They are covered against a
 * real database by tests/integration/privacy.php. What is unit-tested here is
 * the arithmetic. */

function wp_salt( $scheme = 'auth' ) {
	// Fixed, so reference-code tests assert STABILITY rather than
	// unpredictability. Weaker than WordPress by design; the property that
	// matters here is that the same facts produce the same digest.
	return 'gwcvt-test-salt-' . $scheme;
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, (int) $options, (int) $depth );
}

function wp_mail( $to, $subject, $message, $headers = array(), $attachments = array() ) {
	$GLOBALS['gwc_vt_test']['mail'][] = compact( 'to', 'subject', 'message', 'headers' );
	return true;
}

function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function wp_timezone_string() {
	return 'UTC';
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function wp_date( $format, $timestamp = null, $timezone = null ) {
	return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
}

/* ── Posts and post meta ─────────────────────────────────────────────────────
 * Enough of a store to exercise the verification state machine. Deliberately
 * NOT enough to exercise the query layer: there is no get_posts() here, because
 * a stub that returned array() would make every totals assertion pass while
 * proving nothing, and one that reimplemented meta_query would be a second,
 * subtly different WordPress to keep in step with the real one. The totals and
 * the date ranges are covered against a real database by
 * tests/integration/entries.php instead.
 * ─────────────────────────────────────────────────────────────────────────── */

function gwc_vt_test_add_post( int $id, string $post_type, string $post_status = 'publish', string $post_title = '' ): void {
	$GLOBALS['gwc_vt_test']['posts'][ $id ] = array(
		'ID'          => $id,
		'post_type'   => $post_type,
		'post_status' => $post_status,
		'post_title'  => $post_title,
	);
}

function get_post_type( $post_id = null ) {
	$post = $GLOBALS['gwc_vt_test']['posts'][ (int) $post_id ] ?? null;
	return $post ? $post['post_type'] : false;
}

function get_post_status( $post_id = null ) {
	$post = $GLOBALS['gwc_vt_test']['posts'][ (int) $post_id ] ?? null;
	return $post ? $post['post_status'] : false;
}

function get_the_title( $post_id = 0 ) {
	$post = $GLOBALS['gwc_vt_test']['posts'][ (int) $post_id ] ?? null;
	return $post ? $post['post_title'] : '';
}

function wp_update_post( $postarr = array(), $wp_error = false ) {
	$id = (int) ( $postarr['ID'] ?? 0 );

	if ( ! isset( $GLOBALS['gwc_vt_test']['posts'][ $id ] ) ) {
		return 0;
	}

	foreach ( array( 'post_status', 'post_title' ) as $field ) {
		if ( isset( $postarr[ $field ] ) ) {
			$GLOBALS['gwc_vt_test']['posts'][ $id ][ $field ] = $postarr[ $field ];
		}
	}

	return $id;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$value = $GLOBALS['gwc_vt_test']['post_meta'][ (int) $post_id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? array() : array( $value ) );
}

function update_post_meta( $post_id, $key, $value, $prev = '' ) {
	$GLOBALS['gwc_vt_test']['post_meta'][ (int) $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key, $value = '' ) {
	unset( $GLOBALS['gwc_vt_test']['post_meta'][ (int) $post_id ][ $key ] );
	return true;
}

function update_postmeta_cache( $post_ids ) {
	return true;
}

function get_userdata( $user_id ) {
	$user = $GLOBALS['gwc_vt_test']['users'][ (int) $user_id ] ?? null;

	if ( ! $user ) {
		return false;
	}

	return (object) array(
		'ID'           => (int) $user_id,
		'display_name' => (string) ( $user['display_name'] ?? '' ),
	);
}

/* ── Transients ──────────────────────────────────────────────────────────── */

function get_transient( $key ) {
	return $GLOBALS['gwc_vt_test']['transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['gwc_vt_test']['transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['gwc_vt_test']['transients'][ $key ] );
	return true;
}

/* ── Everything else the loaded files touch ──────────────────────────────── */

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
}

function get_current_screen() {
	return $GLOBALS['gwc_vt_test']['screen'] ?? null;
}

/**
 * As core does it: unset the menu entry and leave the registered page alone.
 *
 * That asymmetry is the whole reason gwc_vt_hide_menu_verbs() can take Log a
 * day off the menu without taking the screen off the site, so the stub has to
 * reproduce it rather than simply forgetting the slug. Core also re-keys
 * nothing, which is why the hiding pass runs before gwc_vt_order_menu().
 *
 * @param string $parent_slug The parent menu's slug.
 * @param string $menu_slug   The submenu slug to remove.
 * @return array|false The removed entry, or false if it was not there.
 */
function remove_submenu_page( $parent_slug, $menu_slug ) {
	if ( ! isset( $GLOBALS['submenu'][ $parent_slug ] ) ) {
		return false;
	}

	foreach ( (array) $GLOBALS['submenu'][ $parent_slug ] as $i => $item ) {
		if ( (string) ( $item[2] ?? '' ) === (string) $menu_slug ) {
			unset( $GLOBALS['submenu'][ $parent_slug ][ $i ] );
			return $item;
		}
	}

	return false;
}

function wp_next_scheduled( $hook, $args = array() ) {
	return $GLOBALS['gwc_vt_test']['cron'][ $hook ] ?? false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['gwc_vt_test']['cron'][ $hook ] = $timestamp;
	return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	unset( $GLOBALS['gwc_vt_test']['cron'][ $hook ] );
	return true;
}

/* ── The plugin, in the order the bootstrap requires it ──────────────────── */

require GWC_VT_DIR . 'inc/i18n.php';
require GWC_VT_DIR . 'inc/settings.php';
require GWC_VT_DIR . 'inc/access.php';
require GWC_VT_DIR . 'inc/class-gwc-vt-totals.php';
require GWC_VT_DIR . 'inc/class-gwc-vt-letter-entry.php';
require GWC_VT_DIR . 'inc/class-gwc-vt-letter.php';
require GWC_VT_DIR . 'inc/cpt.php';
require GWC_VT_DIR . 'inc/volunteer-cpt.php';
require GWC_VT_DIR . 'inc/recurrence.php';
require GWC_VT_DIR . 'inc/shift-cpt.php';
require GWC_VT_DIR . 'inc/signup-cpt.php';
require GWC_VT_DIR . 'inc/event-cpt.php';
require GWC_VT_DIR . 'inc/application-cpt.php';
require GWC_VT_DIR . 'inc/credential-cpt.php';
require GWC_VT_DIR . 'inc/credentials.php';
require GWC_VT_DIR . 'inc/credential-shifts.php';
require GWC_VT_DIR . 'inc/photo.php';
require GWC_VT_DIR . 'inc/shifts.php';
require GWC_VT_DIR . 'inc/events.php';
require GWC_VT_DIR . 'inc/signups.php';
require GWC_VT_DIR . 'inc/signup-handler.php';
require GWC_VT_DIR . 'inc/signup-form.php';
require GWC_VT_DIR . 'inc/event-form.php';
require GWC_VT_DIR . 'inc/ics.php';
require GWC_VT_DIR . 'inc/schedule-emails.php';
require GWC_VT_DIR . 'inc/schedule-cron.php';
require GWC_VT_DIR . 'inc/entries.php';
require GWC_VT_DIR . 'inc/required.php';
require GWC_VT_DIR . 'inc/dashboard.php';
require GWC_VT_DIR . 'inc/verify.php';
require GWC_VT_DIR . 'inc/letter-cpt.php';
require GWC_VT_DIR . 'inc/letter.php';
require GWC_VT_DIR . 'inc/render.php';
require GWC_VT_DIR . 'inc/emails.php';
require GWC_VT_DIR . 'inc/privacy.php';
require GWC_VT_DIR . 'inc/self-log.php';
require GWC_VT_DIR . 'inc/form.php';
/* After self-log.php, whose stamp and rate limiter these share — the same
 * ordering the bootstrap enforces with a function_exists() guard. */
require GWC_VT_DIR . 'inc/registration.php';
require GWC_VT_DIR . 'inc/registration-form.php';
require GWC_VT_DIR . 'inc/signin.php';
require GWC_VT_DIR . 'inc/signin-form.php';
require GWC_VT_DIR . 'inc/admin-settings.php';
require GWC_VT_DIR . 'inc/admin-screen.php';

/* admin-volunteer.php's list-table filter is pure arithmetic over query vars —
 * the same split as the dashboard's worklist — so it is unit-testable. The rest
 * of that file renders meta boxes and is covered by tests/integration. */
require GWC_VT_DIR . 'inc/admin-volunteer.php';

/* And the credential filter beside it, for exactly the same reason: which
 * volunteers the list keeps is arithmetic over query vars, and the empty-set
 * trap it guards against — post__in with nothing in it listing everybody — is
 * the kind that is invisible until it is in front of a coordinator. */
require GWC_VT_DIR . 'inc/admin-volunteer-credentials.php';

/* And the retire pass beside it, for a third time for the same reason: which
 * views the volunteer list offers, and in what order, is array manipulation
 * over what core hands it. The handlers either side of it write posts and are
 * covered by tests/integration/retired.php. */
require GWC_VT_DIR . 'inc/admin-volunteer-retire.php';

/* The widget's week block groups shifts into days, which is arithmetic over
 * post meta and nothing else — the same split as the worklist it sits under. */
require GWC_VT_DIR . 'inc/admin-dashboard-widget.php';

/* admin-schedule.php for the same reason. Four things in it decide what the
 * screen shows before any of it is drawn — which rows a filter keeps, how many
 * are in each state, which week or month heading a date sits under, and where a
 * run of cancelled occurrences folds — and all four are arithmetic over an array
 * of rows. The rendering either side of them needs a database and is covered by
 * tests/integration/schedule-folding.php. */
require GWC_VT_DIR . 'inc/admin-schedule.php';

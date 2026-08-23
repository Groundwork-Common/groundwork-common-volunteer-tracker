<?php
/**
 * Retention, anonymization, and WordPress's own privacy tools.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

const GWC_VT_RETENTION_EVENT = 'gwc_vt_daily_retention';
const GWC_VT_RETENTION_LOG   = 'gwc_vt_retention_log';

/** How many volunteers one sweep will touch. */
const GWC_VT_RETENTION_BATCH = 50;

/** How many sweeps the log remembers. */
const GWC_VT_RETENTION_LOG_SIZE = 30;

const GWC_VT_VOLUNTEER_HOLD_REASON = '_gwc_vt_hold_reason';

add_action( 'init', 'gwc_vt_schedule_retention' );
add_action( GWC_VT_RETENTION_EVENT, 'gwc_vt_run_retention' );

/* Registered unconditionally, and not behind a setting. Privacy tooling that
 * has to be switched on is privacy tooling that is off on every site that
 * needed it. A site with no retention policy still has to be able to answer a
 * request under GDPR, CCPA, or a volunteer simply asking what you hold. */
add_filter( 'wp_privacy_personal_data_exporters', 'gwc_vt_register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'gwc_vt_register_eraser' );

/* ── Why the default keeps everything ────────────────────────────────────────
 * retention_months defaults to 0, meaning never purge, and that is not
 * indecision — it is the only safe default. A plugin that started deleting
 * records after some period it chose would eventually destroy the six weeks of
 * Saturdays somebody needs for a court date they have not reached yet, on a
 * site whose administrator never knew the setting existed.
 *
 * But a default that keeps everything forever quietly hoards personal data, and
 * the brief for this plugin named retention as a decision to be made
 * deliberately rather than defaulted into.
 *
 * The resolution is not a cleverer default. It is 'retention_decided': the
 * Privacy tab shows a notice that does not go away until somebody saves it —
 * INCLUDING saving it as "keep indefinitely", which is a legitimate answer.
 * What is not legitimate is never having considered the question.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Keep the daily sweep scheduled.
 *
 * On init and idempotent, for the same reason the capabilities are: a site that
 * loses its cron entry to a migration or a restore gets it back on the next
 * page load rather than needing a deactivate/reactivate cycle.
 */
function gwc_vt_schedule_retention(): void {
	if ( ! wp_next_scheduled( GWC_VT_RETENTION_EVENT ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', GWC_VT_RETENTION_EVENT );
	}
}

/**
 * The date on or before which a record is old enough to purge.
 *
 * Month arithmetic through DateTimeImmutable rather than a multiplication by
 * thirty days. "Six months" means six calendar months to everybody who sets
 * this, and 180 days drifts away from that by nearly a week a year — on a
 * setting whose whole job is to be defensible if somebody asks.
 *
 * @param int    $months How many months to keep for.
 * @param string $today  Y-m-d.
 * @return string Y-m-d, or '' when retention is off.
 */
function gwc_vt_retention_cutoff( int $months, string $today ): string {
	if ( $months < 1 || '' === $today ) {
		return '';
	}

	$date = DateTimeImmutable::createFromFormat( 'Y-m-d', $today, new DateTimeZone( 'UTC' ) );

	if ( ! $date ) {
		return '';
	}

	return $date->modify( '-' . $months . ' months' )->format( 'Y-m-d' );
}

/**
 * The date a volunteer's retention clock runs from.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return string Y-m-d, or '' if it cannot be determined.
 */
function gwc_vt_retention_anchor_date( int $volunteer_id ): string {
	$anchor = (string) gwc_vt_setting( 'retention_anchor' );

	/* The cached rollup here, where the letter deliberately refuses it.
	 *
	 * The distinction is what the number is for. A letter's total is a claim
	 * made to a court, so it is recomputed every time and a stale figure is
	 * unacceptable. This is a date used to decide whether a record is more than
	 * two years old — a cache that is a few hours behind cannot change that
	 * answer, and the sweep walks fifty volunteers per run, so a fresh query
	 * each would be fifty queries to learn something the rollup already knows.
	 *
	 * gwc_vt_volunteer_totals() recomputes anyway when no cache exists, so the
	 * worst case is correct rather than merely fast. */
	$totals = gwc_vt_volunteer_totals( $volunteer_id );

	if ( 'verified_at' === $anchor ) {
		$latest = '';

		foreach ( gwc_vt_entry_ids_for_volunteer( $volunteer_id ) as $entry_id ) {
			$at = (string) get_post_meta( (int) $entry_id, GWC_VT_ENTRY_VERIFIED_AT, true );

			if ( '' !== $at && $at > $latest ) {
				$latest = $at;
			}
		}

		if ( '' !== $latest ) {
			return substr( $latest, 0, 10 );
		}
	}

	if ( '' !== $totals->last ) {
		return $totals->last;
	}

	/* A volunteer who was created and never logged anything. Their own record
	 * date is the only clock there is, and leaving them out entirely would mean
	 * a half-filled contact record persisting forever precisely because it
	 * contains nothing but the personal details. */
	$created = (string) get_post_field( 'post_date', $volunteer_id );

	return '' !== $created ? substr( $created, 0, 10 ) : '';
}

/**
 * Is this volunteer's record due to be purged?
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $today        Y-m-d. Defaults to today in the site's timezone.
 * @return bool
 */
function gwc_vt_retention_due( int $volunteer_id, string $today = '' ): bool {
	$months = (int) gwc_vt_setting( 'retention_months' );

	if ( $months < 1 ) {
		return false;
	}

	if ( gwc_vt_retention_held( $volunteer_id ) ) {
		return false;
	}

	$cutoff = gwc_vt_retention_cutoff( $months, '' !== $today ? $today : gwc_vt_today() );
	$anchor = gwc_vt_retention_anchor_date( $volunteer_id );

	if ( '' === $cutoff || '' === $anchor ) {
		return false;
	}

	$due = $anchor < $cutoff;

	/**
	 * Whether a volunteer's record is due for purging.
	 *
	 * @param bool   $due          Whether it is due.
	 * @param int    $volunteer_id Volunteer post ID.
	 * @param string $anchor       The date the clock ran from.
	 */
	return (bool) apply_filters( 'gwc_vt_retention_due', $due, $volunteer_id, $anchor );
}

/**
 * Is this record exempt from purging?
 *
 * Courts do sometimes require an organization to keep a record for longer than
 * its own policy, and a retention sweep that could not be overridden per person
 * would make the setting unusable for exactly the orgs this plugin is for.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return bool
 */
function gwc_vt_retention_held( int $volunteer_id ): bool {
	return (bool) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_HOLD, true );
}

/* ── Purging ─────────────────────────────────────────────────────────────── */

/**
 * Anonymize a volunteer, keeping their hours.
 *
 * The default action, and the right one for this domain. An organization's
 * grant reporting and its Form 990 need the hours; they do not need the name.
 * Deleting outright throws away the organization's own service statistics to
 * solve a problem that removing the identity already solves.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return bool
 */
function gwc_vt_anonymize_volunteer( int $volunteer_id ): bool {
	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return false;
	}

	/**
	 * Fires before a volunteer's record is anonymized or deleted.
	 *
	 * A site that exports to another system gets its last chance here.
	 *
	 * @param int    $volunteer_id Volunteer post ID.
	 * @param string $action       'anonymize' or 'delete'.
	 */
	do_action( 'gwc_vt_before_purge', $volunteer_id, 'anonymize' );

	wp_update_post(
		array(
			'ID'         => $volunteer_id,
			'post_title' => sprintf(
				/* translators: %d: an internal record number. */
				__( 'Former volunteer #%d', 'groundwork-common-volunteer-tracker' ),
				$volunteer_id
			),
		)
	);

	delete_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL );
	delete_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHONE );

	/* The requirement goes too, and it is the most important thing in this list.
	 * The hours survive anonymization because they are the organization's own
	 * service record and identify nobody once the name is gone — but "120 hours
	 * required by 15 November for Franklin County Municipal Court" says that a
	 * person was under a court order, names the court, and dates it. That is a
	 * disclosure about a real event, and it does not stop being one because the
	 * name above it has been removed. */
	delete_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED );
	delete_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_BY );
	delete_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_FOR );

	/* The claimed name and email on any self-logged entry are personal data too,
	 * and they sit on the ENTRY rather than the volunteer — so anonymizing only
	 * the volunteer record would leave the name behind on every shift somebody
	 * submitted through the public form. */
	foreach ( gwc_vt_entry_ids_for_volunteer( $volunteer_id, array( 'statuses' => array( 'publish', 'pending', 'draft' ) ) ) as $entry_id ) {
		delete_post_meta( (int) $entry_id, '_gwc_vt_claim_name' );
		delete_post_meta( (int) $entry_id, '_gwc_vt_claim_email' );
		gwc_vt_retitle_entry( (int) $entry_id );
	}

	/* And the same again on their signups, which carry their own copy of a name
	 * and an address typed into the public form. The shift and the place on it
	 * survive — that is the organization's record of what it ran and who staffed
	 * it, and anonymized it identifies nobody. */
	foreach ( gwc_vt_signup_ids_for_volunteer( $volunteer_id ) as $signup_id ) {
		gwc_vt_clear_signup_claims( (int) $signup_id );
	}

	/**
	 * Fires after a volunteer's record has been purged.
	 *
	 * @param int    $volunteer_id Volunteer post ID.
	 * @param string $action       'anonymize' or 'delete'.
	 */
	do_action( 'gwc_vt_purged', $volunteer_id, 'anonymize' );

	return true;
}

/**
 * Delete a volunteer and their shifts outright.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return bool
 */
function gwc_vt_delete_volunteer( int $volunteer_id ): bool {
	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return false;
	}

	do_action( 'gwc_vt_before_purge', $volunteer_id, 'delete' );

	foreach ( gwc_vt_entry_ids_for_volunteer( $volunteer_id, array( 'statuses' => array( 'publish', 'pending', 'draft' ) ) ) as $entry_id ) {
		wp_delete_post( (int) $entry_id, true );
	}

	wp_delete_post( $volunteer_id, true );

	/* The issued-letter log is deliberately NOT touched. A letter having been
	 * issued is a fact about the organization's own conduct, and the record of
	 * it has to outlive the record it described — "we issued a letter, for
	 * somebody no longer on file" is exactly what a court asking about it needs
	 * to hear. The log holds a reference, an ID and a date; it holds no name. */

	do_action( 'gwc_vt_purged', $volunteer_id, 'delete' );

	return true;
}

/**
 * The daily sweep.
 *
 * Batched, oldest first. A site with five thousand volunteer records that
 * switched retention on this morning would otherwise try to purge all of them
 * in one cron request and time out halfway through, leaving nobody able to say
 * which half.
 */
function gwc_vt_run_retention(): void {
	$months = (int) gwc_vt_setting( 'retention_months' );

	if ( $months < 1 ) {
		return;
	}

	$action = 'delete' === gwc_vt_setting( 'retention_action' ) ? 'delete' : 'anonymize';

	$candidates = get_posts(
		array(
			'post_type'              => GWC_VT_VOLUNTEER_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => GWC_VT_RETENTION_BATCH,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'ASC',
		)
	);

	$purged = 0;
	$held   = 0;

	foreach ( (array) $candidates as $volunteer_id ) {
		$volunteer_id = (int) $volunteer_id;

		if ( gwc_vt_retention_held( $volunteer_id ) ) {
			++$held;
			continue;
		}

		if ( ! gwc_vt_retention_due( $volunteer_id ) ) {
			continue;
		}

		$done = 'delete' === $action
			? gwc_vt_delete_volunteer( $volunteer_id )
			: gwc_vt_anonymize_volunteer( $volunteer_id );

		if ( $done ) {
			++$purged;
		}
	}

	$purged += gwc_vt_sweep_orphan_signups( $months );

	gwc_vt_log_retention_run( $action, $purged, $held );
}

/**
 * Strip old claims from signups that never became anybody.
 *
 * ── The gap this closes ──────────────────────────────────────────────────────
 * Everything else in this file starts from a volunteer record, because until the
 * public signup form existed, every scrap of personal data the plugin held was
 * either on a volunteer or on an entry belonging to one.
 *
 * A signup made through the public form by somebody nobody ever matched belongs
 * to no volunteer. The sweep above would never see it, so a name and an email
 * address typed in once, by a person who then did not turn up and was never
 * heard from again, would sit on a roster for the life of the site — under a
 * retention policy that says two years, on a plugin whose whole argument about
 * retention is that quietly hoarding personal data is not an acceptable default.
 *
 * The place on the shift stays. That is the organization's own record of what it
 * ran; without a name on it, it identifies nobody.
 *
 * Only shifts already in the past are considered, and 'delete' is deliberately
 * not honored here — there is nothing to delete, because a signup without its
 * claim is not personal data.
 *
 * @param int $months The retention period.
 * @return int How many were stripped.
 */
function gwc_vt_sweep_orphan_signups( int $months ): int {
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );

	$candidates = get_posts(
		array(
			'post_type'              => GWC_VT_SIGNUP_TYPE,
			'post_status'            => array( 'publish', GWC_VT_SIGNUP_WAITLIST, GWC_VT_SIGNUP_WITHDRAWN ),
			'fields'                 => 'ids',
			'posts_per_page'         => GWC_VT_RETENTION_BATCH,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'ASC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key, once a day on cron.
			'meta_query'             => array(
				array(
					'key'   => GWC_VT_SIGNUP_VOLUNTEER,
					'value' => '0',
				),
			),
		)
	);

	$stripped = 0;

	foreach ( (array) $candidates as $signup_id ) {
		$signup_id = (int) $signup_id;

		$created = (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CREATED, true );

		if ( '' === $created || $created > $cutoff ) {
			continue;
		}

		/* Nothing to strip is not a purge. Counting it would make the retention
		 * log report work on every run forever. */
		if ( '' === (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_NAME, true )
			&& '' === (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_EMAIL, true ) ) {
			continue;
		}

		/** This action is documented in inc/privacy.php */
		do_action( 'gwc_vt_before_purge', $signup_id, 'anonymize' );

		gwc_vt_clear_signup_claims( $signup_id );

		/** This action is documented in inc/privacy.php */
		do_action( 'gwc_vt_purged', $signup_id, 'anonymize' );

		++$stripped;
	}

	return $stripped;
}

/**
 * Record what a sweep did.
 *
 * A purge that runs invisibly is a purge nobody trusts, and the first question
 * anybody asks after switching this on is "did it do anything".
 *
 * @param string $action What was done.
 * @param int    $purged How many records.
 * @param int    $held   How many were exempt.
 */
function gwc_vt_log_retention_run( string $action, int $purged, int $held ): void {
	$log = get_option( GWC_VT_RETENTION_LOG );
	$log = is_array( $log ) ? $log : array();

	array_unshift(
		$log,
		array(
			'at'     => (string) current_time( 'mysql', true ),
			'action' => $action,
			'purged' => $purged,
			'held'   => $held,
		)
	);

	update_option( GWC_VT_RETENTION_LOG, array_slice( $log, 0, GWC_VT_RETENTION_LOG_SIZE ), false );
}

/**
 * The sweep history, newest first.
 *
 * @return array[]
 */
function gwc_vt_retention_log(): array {
	$log = get_option( GWC_VT_RETENTION_LOG );

	return is_array( $log ) ? $log : array();
}

/* ── WordPress's own privacy tools ───────────────────────────────────────────
 * Tools → Export Personal Data and Tools → Erase Personal Data are where a site
 * administrator goes when somebody asks what is held about them. A plugin
 * storing this much personal data that answers neither is a plugin whose
 * records are invisible to the process the site is relying on.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Volunteers matching an email address.
 *
 * @param string $email The address.
 * @return int[]
 */
function gwc_vt_volunteers_by_email( string $email ): array {
	$email = sanitize_email( $email );

	if ( '' === $email || ! is_email( $email ) ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_VOLUNTEER_TYPE,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed by meta_key; run once per privacy request, not per page load.
			'meta_key'               => GWC_VT_VOLUNTEER_EMAIL,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			'meta_value'             => $email,
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Register the exporter.
 *
 * @param array $exporters Existing exporters.
 * @return array
 */
function gwc_vt_register_exporter( $exporters ): array {
	$exporters = (array) $exporters;

	$exporters['groundwork-common-volunteer-tracker'] = array(
		'exporter_friendly_name' => __( 'Volunteer hours', 'groundwork-common-volunteer-tracker' ),
		'callback'               => 'gwc_vt_export_personal_data',
	);

	return $exporters;
}

/**
 * Export everything held about one person.
 *
 * @param string $email The address.
 * @param int    $page  1-based page number.
 * @return array
 */
function gwc_vt_export_personal_data( $email, $page = 1 ) {
	$page  = max( 1, (int) $page );
	$items = array();

	$volunteers = gwc_vt_volunteers_by_email( (string) $email );

	foreach ( $volunteers as $volunteer_id ) {
		$totals = gwc_vt_compute_totals( $volunteer_id );

		$items[] = array(
			'group_id'    => 'gwc_vt_volunteer',
			'group_label' => __( 'Volunteer record', 'groundwork-common-volunteer-tracker' ),
			'item_id'     => 'gwcvt-volunteer-' . $volunteer_id,
			'data'        => array(
				array(
					'name'  => __( 'Name', 'groundwork-common-volunteer-tracker' ),
					'value' => get_the_title( $volunteer_id ),
				),
				array(
					'name'  => __( 'Email', 'groundwork-common-volunteer-tracker' ),
					'value' => (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_EMAIL, true ),
				),
				array(
					'name'  => __( 'Phone', 'groundwork-common-volunteer-tracker' ),
					'value' => (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_PHONE, true ),
				),
				array(
					'name'  => __( 'Verified hours', 'groundwork-common-volunteer-tracker' ),
					'value' => gwc_vt_format_hours( $totals->verified_minutes ),
				),
				array(
					'name'  => __( 'Hours awaiting verification', 'groundwork-common-volunteer-tracker' ),
					'value' => gwc_vt_format_hours( $totals->pending_minutes ),
				),
				array(
					'name'  => __( 'Hours they are required to complete', 'groundwork-common-volunteer-tracker' ),
					'value' => gwc_vt_has_requirement( $volunteer_id ) ? gwc_vt_format_hours( gwc_vt_required_minutes( $volunteer_id ) ) : '',
				),
				array(
					'name'  => __( 'Required by', 'groundwork-common-volunteer-tracker' ),
					'value' => (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_BY, true ),
				),
				array(
					'name'  => __( 'Who requires it', 'groundwork-common-volunteer-tracker' ),
					'value' => (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_REQUIRED_FOR, true ),
				),
			),
		);

		/* Paginated over this volunteer's shifts. An exporter that returned
		 * everything at once is one that times out on the volunteer with four
		 * years of Saturdays — which is exactly the person most likely to ask. */
		$entry_ids = gwc_vt_entry_ids_for_volunteer( $volunteer_id, array( 'statuses' => array( 'publish', 'pending', 'draft' ) ) );
		$slice     = array_slice( $entry_ids, ( $page - 1 ) * GWC_VT_RETENTION_BATCH, GWC_VT_RETENTION_BATCH );

		foreach ( $slice as $entry_id ) {
			$entry_id = (int) $entry_id;

			$items[] = array(
				'group_id'    => 'gwc_vt_entry',
				'group_label' => __( 'Volunteer shifts', 'groundwork-common-volunteer-tracker' ),
				'item_id'     => 'gwcvt-entry-' . $entry_id,
				'data'        => array(
					array(
						'name'  => __( 'Date', 'groundwork-common-volunteer-tracker' ),
						'value' => (string) get_post_meta( $entry_id, GWC_VT_ENTRY_DATE, true ),
					),
					array(
						'name'  => __( 'Hours', 'groundwork-common-volunteer-tracker' ),
						'value' => gwc_vt_format_hours( (int) get_post_meta( $entry_id, GWC_VT_ENTRY_MINUTES, true ) ),
					),
					array(
						'name'  => __( 'Activity', 'groundwork-common-volunteer-tracker' ),
						'value' => (string) get_post_meta( $entry_id, GWC_VT_ENTRY_ACTIVITY, true ),
					),
					array(
						'name'  => __( 'Supervised by', 'groundwork-common-volunteer-tracker' ),
						'value' => (string) get_post_meta( $entry_id, GWC_VT_ENTRY_SUPERVISOR, true ),
					),
					array(
						'name'  => __( 'Verification', 'groundwork-common-volunteer-tracker' ),
						'value' => gwc_vt_attestation_line( $entry_id ),
					),
				),
			);
		}

		// The letters issued about them, on the first page only.
		if ( 1 === $page ) {
			foreach ( gwc_vt_letters_for_volunteer( $volunteer_id ) as $record ) {
				$items[] = array(
					'group_id'    => 'gwc_vt_letter',
					'group_label' => __( 'Verification letters issued', 'groundwork-common-volunteer-tracker' ),
					'item_id'     => 'gwcvt-letter-' . $record['id'],
					'data'        => array(
						array(
							'name'  => __( 'Reference', 'groundwork-common-volunteer-tracker' ),
							'value' => $record['reference'],
						),
						array(
							'name'  => __( 'Issued', 'groundwork-common-volunteer-tracker' ),
							'value' => $record['issued_at'],
						),
						array(
							'name'  => __( 'Sent to', 'groundwork-common-volunteer-tracker' ),
							'value' => '' !== $record['recipient'] ? $record['recipient'] : __( 'Printed, not emailed', 'groundwork-common-volunteer-tracker' ),
						),
						array(
							'name'  => __( 'Hours stated', 'groundwork-common-volunteer-tracker' ),
							'value' => gwc_vt_format_hours( $record['minutes'] ),
						),
					),
				);
			}
		}
	}

	/* ── Signups, on the first page ──────────────────────────────────────────
	 * Found by the address itself rather than through a volunteer record,
	 * because the interesting case is somebody who signed up through the public
	 * form, was never matched to anybody, and is asking what this site holds
	 * about them. Starting from gwc_vt_volunteers_by_email() would answer
	 * "nothing" while their name sat on a roster.
	 *
	 * Signups belonging to a matched volunteer are picked up by the same query,
	 * because the claim is kept alongside the match rather than replaced by it. */
	if ( 1 === $page ) {
		$signup_ids = gwc_vt_signups_by_claim_email( (string) $email );

		foreach ( $volunteers as $volunteer_id ) {
			$signup_ids = array_merge( $signup_ids, gwc_vt_signup_ids_for_volunteer( $volunteer_id ) );
		}

		foreach ( array_unique( $signup_ids ) as $signup_id ) {
			$signup_id = (int) $signup_id;
			$shift_id  = (int) get_post_field( 'post_parent', $signup_id );

			$items[] = array(
				'group_id'    => 'gwc_vt_signup',
				'group_label' => __( 'Shifts signed up for', 'groundwork-common-volunteer-tracker' ),
				'item_id'     => 'gwcvt-signup-' . $signup_id,
				'data'        => array(
					array(
						'name'  => __( 'Shift', 'groundwork-common-volunteer-tracker' ),
						'value' => $shift_id > 0 ? get_the_title( $shift_id ) : '',
					),
					array(
						'name'  => __( 'Name given', 'groundwork-common-volunteer-tracker' ),
						'value' => (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_NAME, true ),
					),
					array(
						'name'  => __( 'Email given', 'groundwork-common-volunteer-tracker' ),
						'value' => (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CLAIM_EMAIL, true ),
					),
					array(
						'name'  => __( 'Signed up', 'groundwork-common-volunteer-tracker' ),
						'value' => (string) get_post_meta( $signup_id, GWC_VT_SIGNUP_CREATED, true ),
					),
					array(
						'name'  => __( 'Standing', 'groundwork-common-volunteer-tracker' ),
						'value' => gwc_vt_signup_standing_label( $signup_id ),
					),
				),
			);
		}
	}

	$most_entries = 0;

	foreach ( $volunteers as $volunteer_id ) {
		$most_entries = max( $most_entries, count( gwc_vt_entry_ids_for_volunteer( $volunteer_id, array( 'statuses' => array( 'publish', 'pending', 'draft' ) ) ) ) );
	}

	return array(
		'data' => $items,
		'done' => ( $page * GWC_VT_RETENTION_BATCH ) >= $most_entries,
	);
}

/**
 * Where a signup stands, in words.
 *
 * @param int $signup_id Signup post ID.
 * @return string
 */
function gwc_vt_signup_standing_label( int $signup_id ): string {
	$status = (string) get_post_status( $signup_id );

	if ( GWC_VT_SIGNUP_WITHDRAWN === $status ) {
		return __( 'Withdrawn', 'groundwork-common-volunteer-tracker' );
	}

	if ( GWC_VT_SIGNUP_WAITLIST === $status ) {
		return __( 'Waiting list', 'groundwork-common-volunteer-tracker' );
	}

	return __( 'On the roster', 'groundwork-common-volunteer-tracker' );
}

/**
 * Every letter issued about one volunteer.
 *
 * @param int $volunteer_id Volunteer post ID.
 * @return array[]
 */
function gwc_vt_letters_for_volunteer( int $volunteer_id ): array {
	$ids = get_posts(
		array(
			'post_type'              => GWC_VT_LETTER_TYPE,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- run once per privacy request.
			'meta_key'               => GWC_VT_LETTER_VOLUNTEER,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			'meta_value'             => (string) $volunteer_id,
		)
	);

	return array_map( 'gwc_vt_letter_record', array_map( 'intval', (array) $ids ) );
}

/**
 * Register the eraser.
 *
 * @param array $erasers Existing erasers.
 * @return array
 */
function gwc_vt_register_eraser( $erasers ): array {
	$erasers = (array) $erasers;

	$erasers['groundwork-common-volunteer-tracker'] = array(
		'eraser_friendly_name' => __( 'Volunteer hours', 'groundwork-common-volunteer-tracker' ),
		'callback'             => 'gwc_vt_erase_personal_data',
	);

	return $erasers;
}

/**
 * Erase what can be erased, and say plainly what was kept and why.
 *
 * The messages matter as much as the erasing. WordPress shows them to the
 * administrator handling the request, who then has to tell a real person what
 * happened — and "some data was retained" with no explanation is worse than
 * useless to them.
 *
 * @param string $email The address.
 * @param int    $page  1-based page number.
 * @return array
 */
function gwc_vt_erase_personal_data( $email, $page = 1 ) {
	$removed  = false;
	$retained = false;
	$messages = array();

	$volunteers = gwc_vt_volunteers_by_email( (string) $email );

	foreach ( $volunteers as $volunteer_id ) {
		if ( gwc_vt_retention_held( $volunteer_id ) ) {
			$retained = true;

			$reason = (string) get_post_meta( $volunteer_id, GWC_VT_VOLUNTEER_HOLD_REASON, true );

			$messages[] = '' !== $reason
				? sprintf(
					/* translators: %s: the reason recorded for the hold. */
					__( 'This volunteer record is on a retention hold and was not erased. Reason recorded: %s', 'groundwork-common-volunteer-tracker' ),
					$reason
				)
				: __( 'This volunteer record is on a retention hold and was not erased. Remove the hold on their record if the erasure should go ahead.', 'groundwork-common-volunteer-tracker' );

			continue;
		}

		/* Anonymized rather than deleted, and the message says so. The hours are
		 * the organization's own service record — needed for grant reporting and
		 * a Form 990 — and they identify nobody once the name and contact
		 * details are gone. Quietly destroying them would damage the
		 * organization to no benefit for the person asking. */
		$letters = gwc_vt_letters_for_volunteer( $volunteer_id );
		$totals  = gwc_vt_compute_totals( $volunteer_id );

		if ( gwc_vt_anonymize_volunteer( $volunteer_id ) ) {
			$removed  = true;
			$retained = true;

			$messages[] = sprintf(
				/* translators: %s: a duration, already formatted — "42.5" or "42h 30m" depending on the site's setting, so the sentence must not add a unit of its own. */
				__( 'The name, email address and phone number were removed. The volunteer hours themselves (%s) were kept — anonymized, they identify nobody, and the organization needs them for its own service reporting.', 'groundwork-common-volunteer-tracker' ),
				gwc_vt_format_hours( $totals->verified_minutes + $totals->pending_minutes )
			);

			/* Naming the affected letters, because silently destroying the record
			 * behind a document a court is holding is precisely the failure this
			 * plugin cannot have. The administrator needs to know a reference may
			 * now be un-checkable. */
			if ( $letters ) {
				$messages[] = sprintf(
					/* translators: %s: a comma-separated list of reference codes. */
					__( 'Verification letters were previously issued for this person. Their references remain in the issued-letter log and can no longer be matched against a named record: %s', 'groundwork-common-volunteer-tracker' ),
					implode( ', ', wp_list_pluck( $letters, 'reference' ) )
				);
			}
		}
	}

	/* ── Signups made by somebody nobody matched ─────────────────────────────
	 * The loop above reaches everything that hangs off a volunteer record. This
	 * reaches what does not: a signup made through the public form by a person
	 * who never became a volunteer. Their name and address sit on the signup,
	 * belong to no record, and would otherwise survive an erasure that reported
	 * itself as complete.
	 *
	 * A retention hold on a volunteer record protects that volunteer's signups
	 * along with everything else of theirs — checked per signup rather than
	 * once, because one address can reach both a held record and a loose claim. */
	$erased_signups = 0;
	$held_signups   = 0;

	foreach ( gwc_vt_signups_by_claim_email( (string) $email ) as $signup_id ) {
		$signup_id = (int) $signup_id;
		$owner     = (int) get_post_meta( $signup_id, GWC_VT_SIGNUP_VOLUNTEER, true );

		if ( $owner > 0 && gwc_vt_retention_held( $owner ) ) {
			++$held_signups;
			$retained = true;
			continue;
		}

		gwc_vt_clear_signup_claims( $signup_id );

		$removed  = true;
		$retained = true;
		++$erased_signups;
	}

	if ( $erased_signups > 0 ) {
		$messages[] = sprintf(
			/* translators: %d: how many shift signups were affected. */
			_n(
				'The name and email address given when signing up for %d shift were removed. The place on the shift itself was kept — it is the organization\'s record of who staffed it, and it now identifies nobody.',
				'The name and email address given when signing up for %d shifts were removed. The places on the shifts themselves were kept — they are the organization\'s record of who staffed them, and they now identify nobody.',
				$erased_signups,
				'groundwork-common-volunteer-tracker'
			),
			$erased_signups
		);
	}

	if ( $held_signups > 0 ) {
		$messages[] = sprintf(
			/* translators: %d: how many shift signups were left alone. */
			_n(
				'%d shift signup belongs to a volunteer record on a retention hold and was not erased.',
				'%d shift signups belong to a volunteer record on a retention hold and were not erased.',
				$held_signups,
				'groundwork-common-volunteer-tracker'
			),
			$held_signups
		);
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => $retained,
		'messages'       => $messages,
		'done'           => true,
	);
}

<?php
/**
 * Partners: the organizations volunteers come with.
 *
 * A company sending twenty people for a day of service, a school sending a
 * class, a church group, a court's community-service partner. The partner
 * is not a volunteer and never becomes one — it is the name attached to the
 * people who arrived under it.
 *
 * ── A term, not a string, and not a post type ────────────────────────────────
 * A partner's name here is an AGGREGATION KEY, not a label. The question
 * the feature exists to answer is "how many hours did Acme contribute", and
 * free text answers it wrongly and silently: "Acme Corp" and "ACME Corp." split
 * the total in two, forever, and nothing on any screen says so.
 *
 * That rules out the pattern this plugin uses for activities and locations —
 * newline lists in settings, offered as suggestions with free text still
 * allowed. Right for a word nobody sums; wrong for a key.
 *
 * It is not a post type either, and the line is worth stating because it moved
 * once already. #211 drew it at "a second fact about a partner" and that
 * rule fires on a contact name, which is not the distinction that matters. What
 * makes credentials a post type is that they carry BEHAVIOUR — a renewal
 * interval, a mode that blocks a signup — and CHILD RECORDS, one per grant. An
 * partner carries four scalars and no behaviour, which is what term meta
 * is for.
 *
 * It graduates when it needs HISTORY: several contacts over time, an agreement
 * on file, dated notes, a reversible decision like "do not book again". Not
 * when it needs a fifth string.
 *
 * ── Why "partner" and not "organization" ────────────────────────────────────
 * This was called gwc_vt_org for about a day, and the name was wrong for a
 * reason worth recording: "organization" in this codebase has meant the HOST
 * NONPROFIT since the first release — the one whose letterhead is on the
 * letter, whose name a court reads, and about which README.md and CLAUDE.md say
 * "the letter is a record of what the organization observed". That meaning owns
 * gwc_vt_org_name() in inc/settings.php, gwc_vt_org_contact() in inc/render.php,
 * gwc_vt_org_totals() in inc/dashboard.php, the org_name setting, the
 * gwcvt-org-* classes on the letterhead, and {org} in the letter wording.
 *
 * A second meaning under the same six letters is the "requirement" problem
 * again — the word CLAUDE.md bans from the credential feature because it
 * already meant court-ordered hours. The first draft here argued the collision
 * was survivable because the host helpers take no arguments and everything on
 * this side takes an ID. That is a real distinction and a thin one to ask a
 * reader to hold, and it would have lasted exactly until somebody wrote a fifth
 * host helper that took an ID.
 *
 * So: a PARTNER is an organization a volunteer came WITH. The organization is
 * the nonprofit running the site. Neither word does both jobs, and nothing in
 * this file touches the letterhead.
 *
 * The screens say "partner" too. Renaming the code and leaving "Organizations"
 * on the menu would have moved the collision rather than removed it — a
 * coordinator reading "Organizations" one row below a letter about "the
 * organization" is the person the confusion actually costs.
 *
 * ── Two object types, and neither may answer the other's question ────────────
 * The taxonomy is registered on the VOLUNTEER — who is affiliated with Acme —
 * and #211 adds the ENTRY, which is how many hours Acme actually contributed.
 * One vocabulary, one set of terms, one merge screen; two questions.
 *
 * The rule that keeps them apart is load-bearing:
 *
 *   HOURS BY ORGANIZATION ARE COUNTED FROM ENTRIES, NEVER FROM VOLUNTEERS.
 *
 * Somebody who came once with Acme and twice on their own has one term on their
 * record and three entries. A total built from the person attributes all three
 * to Acme and nothing says otherwise — the "a count and the screen it links to"
 * trap in CLAUDE.md, on a number that reaches a grant report. There is
 * deliberately no hours-by-partner function in this file for that reason;
 * it arrives with the entry half.
 *
 * ── Never on a letter ────────────────────────────────────────────────────────
 * gwc_vt_build_letter() does not read this and tests/integration/letter.php
 * says so. The letter is a record of what the partner observed about a
 * person; their employer's name is third-party information the volunteer did
 * not ask to have disclosed to a court, and the letter's fields are the ones
 * the reference code is computed over.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gwc_vt_register_partner_taxonomy' );

/* Anonymizing a volunteer takes their partners with their name. Hooked
 * here rather than written into gwc_vt_anonymize_volunteer() so the taxonomy
 * owns its own erasure — privacy.php fires this for both purge routes, and a
 * feature that adds personal data adds the line that removes it. */
add_action( 'gwc_vt_purged', 'gwc_vt_purge_volunteer_partners', 10, 2 );

/** Who somebody came with. */
const GWC_VT_PARTNER_TAXONOMY = 'gwc_vt_partner';

/* ── Term meta ───────────────────────────────────────────────────────────────
 * Four scalars. Each is show_in_rest => false — hard rule 2 covers the
 * taxonomy, and these would publish a named contact's telephone number.
 * ─────────────────────────────────────────────────────────────────────────── */

/** Whatever this partner is called in the CRM the site actually keeps. */
const GWC_VT_PARTNER_CRM_ID = 'gwc_vt_partner_crm_id';

/** Who to ring there. */
const GWC_VT_PARTNER_CONTACT_NAME = 'gwc_vt_partner_contact_name';

/** Their email address. */
const GWC_VT_PARTNER_CONTACT_EMAIL = 'gwc_vt_partner_contact_email';

/** Their telephone number. */
const GWC_VT_PARTNER_CONTACT_PHONE = 'gwc_vt_partner_contact_phone';

/**
 * Which partner a list is filtered to.
 *
 * ── Why this and not the taxonomy's own query var ────────────────────────────
 * A URL of the form ?gwc_vt_partner=<slug> does nothing. WP_Query::parse_tax_query()
 * only reads a taxonomy's query var when the taxonomy HAS one — `if ( $t->query_var
 * && ... )` — and this one is registered query_var => false, deliberately: a
 * public query var on a non-public taxonomy is a way to ask the front end about
 * records that are not the front end's business.
 *
 * The first version of gwc_vt_partner_volunteers_url() built exactly that URL.
 * It returned every volunteer on the site, under a count that said three. That
 * is the "a count and the screen it links to" trap for the third time in this
 * feature, so both URL helpers now build the filter the dropdown posts and the
 * one that gwc_vt_apply_partner_filter() actually reads.
 */
const GWC_VT_PARTNER_FILTER = 'gwc_vt_partner_is';

/** Longest any of the free-text fields may be. */
const GWC_VT_PARTNER_FIELD_MAX = 200;

/**
 * Every meta key this taxonomy owns.
 *
 * A function and not a const because the labels are translated, and a const is
 * evaluated at include time — the trap CLAUDE.md records for every translated
 * table in this codebase.
 *
 * One list, read by the registration below, by the term-editor fields, by the
 * merge screen's collision report and by the privacy exporter. A fifth field
 * added here appears in all four without a sweep.
 *
 * @return array<string, array{label:string, type:string, help:string}>
 */
function gwc_vt_partner_fields(): array {
	return array(
		GWC_VT_PARTNER_CRM_ID        => array(
			'label' => __( 'CRM ID', 'groundwork-common-volunteer-tracker' ),
			'type'  => 'text',
			/* Deliberately opaque. It may point at CiviCRM, at Salesforce, at a
			 * spreadsheet, or at nothing. This plugin never reads it, never
			 * validates its shape and takes no runtime dependency on any CRM —
			 * hard rule 8, and the reason #211 asked for the field at all.
			 *
			 * No help text under it, and none under the contact either: the
			 * labels are the explanation, and what the fields are for belongs in
			 * the Help tab where somebody goes once rather than under a field
			 * every coordinator reads past forever. */
			'help'  => '',
		),
		GWC_VT_PARTNER_CONTACT_NAME  => array(
			'label' => __( 'Contact', 'groundwork-common-volunteer-tracker' ),
			'type'  => 'text',
			'help'  => '',
		),
		GWC_VT_PARTNER_CONTACT_EMAIL => array(
			'label' => __( 'Contact email', 'groundwork-common-volunteer-tracker' ),
			'type'  => 'email',
			'help'  => '',
		),
		GWC_VT_PARTNER_CONTACT_PHONE => array(
			'label' => __( 'Contact telephone', 'groundwork-common-volunteer-tracker' ),
			'type'  => 'tel',
			'help'  => '',
		),
	);
}

/**
 * Register the taxonomy and its meta.
 */
function gwc_vt_register_partner_taxonomy(): void {
	$labels = array(
		'name'                  => _x( 'Partners', 'taxonomy general name', 'groundwork-common-volunteer-tracker' ),
		'singular_name'         => _x( 'Partner', 'taxonomy singular name', 'groundwork-common-volunteer-tracker' ),
		'menu_name'             => __( 'Partners', 'groundwork-common-volunteer-tracker' ),
		'all_items'             => __( 'All partners', 'groundwork-common-volunteer-tracker' ),
		'edit_item'             => __( 'Edit partner', 'groundwork-common-volunteer-tracker' ),
		'view_item'             => __( 'View partner', 'groundwork-common-volunteer-tracker' ),
		'update_item'           => __( 'Update partner', 'groundwork-common-volunteer-tracker' ),
		'add_new_item'          => __( 'Add a new partner', 'groundwork-common-volunteer-tracker' ),
		'new_item_name'         => __( 'New partner name', 'groundwork-common-volunteer-tracker' ),
		'parent_item'           => __( 'Part of', 'groundwork-common-volunteer-tracker' ),
		'parent_item_colon'     => __( 'Part of:', 'groundwork-common-volunteer-tracker' ),
		'search_items'          => __( 'Search partners', 'groundwork-common-volunteer-tracker' ),
		'not_found'             => __( 'No partners yet.', 'groundwork-common-volunteer-tracker' ),
		'no_terms'              => __( 'No partners', 'groundwork-common-volunteer-tracker' ),
		'items_list_navigation' => __( 'Partners list navigation', 'groundwork-common-volunteer-tracker' ),
		'items_list'            => __( 'Partners list', 'groundwork-common-volunteer-tracker' ),
		'back_to_items'         => __( '&larr; Back to partners', 'groundwork-common-volunteer-tracker' ),
	);

	$args = array(
		'labels'                => $labels,

		/* ── hierarchical is a UI decision, not a data-model one ──────────────
		 * This is the single line here most likely to be "tidied" by somebody
		 * who notices that partners are mostly flat, and doing so
		 * reintroduces the exact failure the taxonomy exists to prevent.
		 *
		 * A NON-hierarchical taxonomy gets the tag-style metabox: a free-text
		 * field that creates a term from whatever was typed. Autocomplete helps
		 * and does not stop anybody — one coordinator types "ACME Corp." and
		 * the total is split from that moment on, silently.
		 *
		 * Hierarchical gets the checkbox list, which offers only terms that
		 * already exist. Creating one becomes a deliberate act on the
		 * Partners screen rather than a side effect of typing a name into
		 * a person's record.
		 *
		 * Parent and child — a national body over its local chapters — is a
		 * real thing partners do and a genuine bonus. It is not the
		 * reason. If this is ever flipped to false, a custom metabox has to
		 * replace core's on the same commit. */
		'hierarchical'          => true,

		/* Hard rule 2. A taxonomy route at /wp/v2/ would publish the list of
		 * partners this site works with; the term meta would publish a
		 * named person's telephone number beside it. */
		'public'                => false,
		'publicly_queryable'    => false,
		'show_in_rest'          => false,

		'show_ui'               => true,
		'show_in_menu'          => false,
		'show_in_nav_menus'     => false,
		'show_tagcloud'         => false,
		'show_in_quick_edit'    => false,
		'show_admin_column'     => true,
		'rewrite'               => false,
		'query_var'             => false,

		/* ── The capabilities are not optional, and the defaults are wrong ────
		 * register_taxonomy() defaults assign_terms to edit_posts —
		 * CONTRIBUTOR-level (wp-includes/class-wp-taxonomy.php:434) — and the
		 * other three to manage_categories, which Editor holds
		 * (wp-admin/includes/schema.php:768, :794).
		 *
		 * Every screen in this plugin that shows somebody else's record is
		 * behind gwc_vt_records_cap(). Left at core's defaults this arrives
		 * behind a WEAKER gate than that, holding a named contact person and
		 * their telephone number. That is exactly the shape of #213, which cost
		 * six volunteers' names and court-referral status off one list table,
		 * and the lesson recorded from it was that a belief about what the
		 * framework does for you is worth a test more than a paragraph of
		 * prose. tests/integration/caps.php has the test. */
		'capabilities'          => gwc_vt_partner_taxonomy_caps(),

		/* Core's checkbox metabox MINUS its add-new field — see below. */
		'meta_box_cb'           => 'gwc_vt_partner_meta_box',

		'update_count_callback' => '_update_post_term_count',
	);

	/**
	 * Arguments for the partner taxonomy.
	 *
	 * @param array $args register_taxonomy() arguments.
	 */
	$args = (array) apply_filters( 'gwc_vt_partner_taxonomy_args', $args );

	register_taxonomy( GWC_VT_PARTNER_TAXONOMY, gwc_vt_partner_object_types(), $args );

	gwc_vt_register_partner_meta();
}

/**
 * The checkbox list on a volunteer's record, and nothing else.
 *
 * ── Core's own metabox undoes the reason this taxonomy is hierarchical ───────
 * post_categories_meta_box() draws the checkbox list, and then, at
 * wp-admin/includes/meta-boxes.php:676, it draws an "+ Add New Partner"
 * toggle over a free-text input for anybody holding the taxonomy's edit_terms —
 * which here is every coordinator, because that capability is this plugin's
 * records gate.
 *
 * So the hierarchical choice bought the safe checkbox list and core handed the
 * unsafe text field straight back, three lines below it. Somebody typing
 * "ACME Corp." into that box creates the second term just as surely as the
 * tag-style metabox would have, and the whole feature exists to prevent exactly
 * that.
 *
 * This is core's markup with that block left off. Adding a partner is a
 * deliberate act on the Partners screen, where the duplicate finder can
 * see it happen.
 *
 * The browser suite is what found this: tests/e2e/specs/partners.spec.js
 * counts the text inputs in this box and expects none. It was written to assert
 * a property this file claimed, and the claim was false.
 *
 * @param WP_Post $post The volunteer being edited.
 */
function gwc_vt_partner_meta_box( $post ): void {
	$name = 'tax_input[' . GWC_VT_PARTNER_TAXONOMY . ']';
	?>
	<div id="taxonomy-<?php echo esc_attr( GWC_VT_PARTNER_TAXONOMY ); ?>" class="categorydiv">
		<div id="<?php echo esc_attr( GWC_VT_PARTNER_TAXONOMY ); ?>-all" class="tabs-panel" role="tabpanel">
			<?php
			/* Core's own trick, and it is load-bearing rather than decorative:
			 * a form with every checkbox cleared posts NOTHING for this field,
			 * which save_post reads as "no opinion" and leaves the old terms in
			 * place. 0 is not a real term, so it says "an empty set, on
			 * purpose" and taking somebody off a partner works. */
			?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="0" />

			<ul id="<?php echo esc_attr( GWC_VT_PARTNER_TAXONOMY ); ?>checklist" class="categorychecklist form-no-clear">
				<?php
				wp_terms_checklist(
					(int) $post->ID,
					array( 'taxonomy' => GWC_VT_PARTNER_TAXONOMY )
				);
				?>
			</ul>
		</div>

		<?php
		/* function_exists() because gwc_vt_partners_url() lives in the admin-only
		 * bundle and this file loads on every request — the same guard the help
		 * sidebar uses on gwc_vt_help_page_url(). A site that has somehow
		 * loaded one without the other should lose a link, not fatal. */
		if ( ! gwc_vt_partner_terms() && function_exists( 'gwc_vt_partners_url' ) ) :
			?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: a link to the Partners screen. */
					esc_html__( 'No partners yet. %s', 'groundwork-common-volunteer-tracker' ),
					'<a href="' . esc_url( gwc_vt_partners_url() ) . '">' . esc_html__( 'Add one', 'groundwork-common-volunteer-tracker' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * What the taxonomy is attached to.
 *
 * ── Two types, two questions, and neither answers the other's ────────────────
 * THE VOLUNTEER answers "who is affiliated with Acme" — a standing fact about a
 * person, which is why anonymizing takes it away with their name.
 *
 * THE ENTRY answers "how many hours did Acme contribute" — a fact about a
 * Saturday, which is why anonymizing leaves it alone. It identifies nobody once
 * the name above it is gone, and it is the organization's own record of a day's
 * work.
 *
 * The rule that keeps them apart is load-bearing, and it is stated once, here:
 *
 *   HOURS BY PARTNER ARE COUNTED FROM ENTRIES, NEVER FROM VOLUNTEERS.
 *
 * Somebody who came once with Acme and twice on their own has one term on their
 * record and three entries. A total built from the person attributes all three
 * to Acme, and nothing on any screen would say so. gwc_vt_partner_hours() is the
 * only function that answers the question, and it reads entries.
 *
 * Everything that operates across the taxonomy reads this function rather than
 * naming a type — the merge, the counts, the purge — so a third type would be
 * one line here. That is not a convenience: a merge written against volunteers
 * alone would leave every entry relationship pointing at a deleted term,
 * silently, and tests/integration/partners.php §5 exists for exactly that.
 *
 * @return string[]
 */
function gwc_vt_partner_object_types(): array {
	/**
	 * The post types a partner can be attached to.
	 *
	 * @param string[] $types Post type slugs.
	 */
	return (array) apply_filters(
		'gwc_vt_partner_object_types',
		array( GWC_VT_VOLUNTEER_TYPE, GWC_VT_ENTRY_TYPE )
	);
}

/**
 * The four capabilities, all of them this plugin's records gate.
 *
 * Named separately from the registration so tests/integration/caps.php can
 * assert the mapping without standing up a taxonomy, and so the reason above
 * has one place to point at.
 *
 * @return array<string, string>
 */
function gwc_vt_partner_taxonomy_caps(): array {
	$capability = gwc_vt_records_cap();

	return array(
		'manage_terms' => $capability,
		'edit_terms'   => $capability,
		'delete_terms' => $capability,

		/* The one core gets most wrong for this plugin: its default here is
		 * edit_posts, which a contributor has. */
		'assign_terms' => $capability,
	);
}

/**
 * Register the four meta keys.
 */
function gwc_vt_register_partner_meta(): void {
	foreach ( gwc_vt_partner_fields() as $key => $field ) {
		register_term_meta(
			GWC_VT_PARTNER_TAXONOMY,
			$key,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',

				/* Hard rule 2 again, at the field rather than the taxonomy.
				 * A meta key registered show_in_rest => true is exposed even
				 * where its taxonomy is not, if anything ever adds a route. */
				'show_in_rest'      => false,

				'sanitize_callback' => 'email' === $field['type']
					? 'gwc_vt_sanitize_partner_email'
					: 'gwc_vt_sanitize_partner_text',

				/* Explicit rather than left to the default, which for term meta
				 * is edit_term on the term — mapped from manage_categories, the
				 * gate this file spends a paragraph declining to sit behind. */
				'auth_callback'     => 'gwc_vt_can_edit_partner_meta',
			)
		);
	}
}

/**
 * May this user write a partner's details?
 *
 * @return bool
 */
function gwc_vt_can_edit_partner_meta(): bool {
	return gwc_vt_can_see_records();
}

/**
 * One free-text field, trimmed to a sane length.
 *
 * @param mixed $value Whatever arrived.
 * @return string
 */
function gwc_vt_sanitize_partner_text( $value ): string {
	return mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, GWC_VT_PARTNER_FIELD_MAX );
}

/**
 * The contact address.
 *
 * Empty rather than invalid when it does not look like an address. A stored
 * half-address is a field that looks filled in and cannot be used, and the term
 * editor says which field it dropped rather than correcting in silence — a
 * silent correction on save is a bug even when the correction is right.
 *
 * @param mixed $value Whatever arrived.
 * @return string
 */
function gwc_vt_sanitize_partner_email( $value ): string {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return '';
	}

	$clean = sanitize_email( $value );

	return is_email( $clean ) ? mb_substr( $clean, 0, GWC_VT_PARTNER_FIELD_MAX ) : '';
}

/**
 * Take a volunteer's partners off with their name.
 *
 * ── Why this differs from what happens to an entry's term ────────────────────
 * #211 recommends that an ENTRY keeps its partner through anonymization,
 * and that is right: an entry is a fact about a Saturday, the partner's own
 * record of a day's work, and it identifies nobody once the name above it is
 * gone.
 *
 * A term on a PERSON is the other kind of fact. It is a property of them, which
 * is the category anonymizing exists to remove — and it is a quasi-identifier
 * besides: "Former volunteer #412, Acme Corp" is close to naming somebody when
 * Acme sent two people that year. The same reasoning the requirement and the
 * credential records go by, a few lines above where this is called from.
 *
 * Fires for both purge routes. Deleting a volunteer removes the relationships
 * with the post, so this is a no-op there and is left unconditional rather than
 * branching — the branch would be the thing that rots.
 *
 * @param int    $volunteer_id Volunteer post ID.
 * @param string $action       'anonymize' or 'delete'. Unused; both purge.
 */
function gwc_vt_purge_volunteer_partners( int $volunteer_id, string $action = '' ): void {
	unset( $action );

	if ( GWC_VT_VOLUNTEER_TYPE !== get_post_type( $volunteer_id ) ) {
		return;
	}

	wp_set_object_terms( $volunteer_id, array(), GWC_VT_PARTNER_TAXONOMY );
}

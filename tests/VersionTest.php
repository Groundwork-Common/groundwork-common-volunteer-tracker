<?php
/**
 * The five places a version number lives, checked against each other, and the
 * translation template that has to be regenerated beside them.
 *
 * This fails the moment any one of them is bumped alone. That happens more
 * often than it sounds: the header and the constant are two lines apart and are
 * still edited separately, and readme.txt's Stable tag is the one WordPress.org
 * actually serves — a release where it disagrees with the header ships a
 * version nobody is offered as an update, and fixing it means another release.
 *
 * The deploy workflow checks the same five against the git tag, which is the
 * one thing this file cannot see.
 *
 * The .pot is not a sixth place and hard rule 7 stays at five. Those five are
 * DECLARATIONS, edited by hand; the template's version is derived by
 * wp i18n make-pot from the header, so it cannot be brought into line by
 * editing it — only by regenerating the file, which is the thing actually being
 * asked for.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase {

	private function plugin_file(): string {
		return (string) file_get_contents( GWC_VT_DIR . 'groundwork-common-volunteer-tracker.php' );
	}

	private function readme(): string {
		return (string) file_get_contents( GWC_VT_DIR . 'readme.txt' );
	}

	public function test_the_header_and_the_constant_agree(): void {
		preg_match( '/^ \* Version:\s*(\S+)/m', $this->plugin_file(), $header );

		$this->assertNotEmpty( $header, 'The plugin header has no Version line.' );
		$this->assertSame( $header[1], GWC_VT_VERSION );
	}

	public function test_the_stable_tag_agrees(): void {
		preg_match( '/^Stable tag:\s*(\S+)/m', $this->readme(), $stable );

		$this->assertNotEmpty( $stable, 'readme.txt has no Stable tag line.' );
		$this->assertSame( GWC_VT_VERSION, $stable[1] );
	}

	public function test_the_changelog_has_an_entry_for_this_version(): void {
		$this->assertMatchesRegularExpression(
			'/^= ' . preg_quote( GWC_VT_VERSION, '/' ) . ' =$/m',
			$this->readme(),
			'readme.txt needs a == Changelog == entry for ' . GWC_VT_VERSION
		);
	}

	public function test_the_upgrade_notice_has_an_entry_for_this_version(): void {
		$parts = explode( '== Upgrade Notice ==', $this->readme() );

		$this->assertCount( 2, $parts, 'readme.txt has no == Upgrade Notice == section.' );
		$this->assertMatchesRegularExpression(
			'/^= ' . preg_quote( GWC_VT_VERSION, '/' ) . ' =$/m',
			$parts[1]
		);
	}

	public function test_the_version_is_semver(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', GWC_VT_VERSION );
	}

	public function test_the_requirements_agree_between_the_header_and_the_readme(): void {
		$plugin = $this->plugin_file();
		$readme = $this->readme();

		foreach ( array( 'Requires at least', 'Requires PHP' ) as $field ) {
			preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':\s*(\S+)/m', $plugin, $a );
			preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(\S+)/m', $readme, $b );

			$this->assertNotEmpty( $a, $field . ' is missing from the plugin header.' );
			$this->assertNotEmpty( $b, $field . ' is missing from readme.txt.' );
			$this->assertSame( $a[1], $b[1], $field . ' disagrees between the header and readme.txt.' );
		}
	}

	/**
	 * The slug, the text domain, the main file's name and the .pot's name are
	 * deliberately one string.
	 *
	 * This used to assert on basename( GWC_VT_DIR ) — the folder the checkout
	 * happens to sit in — which is the one thing in the list that is not the
	 * plugin's own business. WordPress.org installs into a folder named after
	 * the slug whatever the developer's directory was called, so the assertion
	 * proved nothing about the shipped plugin while failing in any checkout not
	 * named after the repo: a git worktree, a fork, a CI job that clones into
	 * `work/`. The main file's name is the invariant that actually matters, and
	 * it is one the repository controls.
	 *
	 * The sibling post portal reached the same conclusion first; this is its
	 * test, adapted. Keeping the two the same shape is deliberate — three
	 * plugins that answer "what is the slug" three different ways is how one of
	 * them ends up answering it wrongly.
	 */
	public function test_the_slug_is_one_string_everywhere(): void {
		$slug = 'groundwork-common-volunteer-tracker';

		preg_match( '/^ \* Text Domain:\s*(\S+)/m', $this->plugin_file(), $domain );

		$this->assertSame( $slug, $domain[1], 'The Text Domain header must be the slug.' );

		$this->assertFileExists(
			GWC_VT_DIR . $slug . '.php',
			'The main plugin file must be named after the slug.'
		);

		$this->assertFileExists(
			GWC_VT_DIR . 'languages/' . $slug . '.pot',
			'The translation template must be named after the text domain, or WordPress will not find it.'
		);

		/* ── And the slug INSIDE it, which is the half that kept going wrong ──
		 * wp i18n make-pot derives Report-Msgid-Bugs-To and X-Domain from the
		 * name of the DIRECTORY it is pointed at, not from the plugin header. In
		 * a git worktree that directory is the branch, so the template ships
		 * naming a support forum that does not exist — and it looks perfectly
		 * well-formed while doing it.
		 *
		 * That was corrected by hand at three consecutive releases before
		 * anybody wrote it down. The file name was already asserted above; the
		 * headers were not, and the headers are the part nobody looks at.
		 * ─────────────────────────────────────────────────────────────────── */

		$pot = (string) file_get_contents( GWC_VT_DIR . 'languages/' . $slug . '.pot' );

		$this->assertStringContainsString(
			'"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/' . $slug . '\\n"',
			$pot,
			'The template points translators at a support forum named after the directory it was generated from, not the plugin. Regenerating from a worktree does this — put the slug back.'
		);

		$this->assertStringContainsString(
			'"X-Domain: ' . $slug . '\\n"',
			$pot,
			'The template names a text domain that is not this plugin\'s, so nothing it holds would ever be loaded.'
		);
	}

	/**
	 * The template was regenerated for whatever version this is.
	 *
	 * Deliberately not one of hard rule 7's five places, and the distinction is
	 * worth keeping: those five DECLARE the version and are edited by hand. This
	 * is derived — wp i18n make-pot reads it out of the plugin header — so it
	 * cannot be kept in step by editing it, only by regenerating the file.
	 *
	 * Which is the point. A release that bumps the version and does not
	 * regenerate ships a template missing every string the release added, and
	 * those strings then render in English forever on every translated site.
	 * Nothing else notices: the file is present, well-formed and named
	 * correctly, and only its contents are stale.
	 *
	 * Order matters and the failure will not say so on its own — bump the
	 * version first, then regenerate, or this reads the old number back.
	 */
	public function test_the_translation_template_was_regenerated_for_this_version(): void {
		$pot = (string) file_get_contents( GWC_VT_DIR . 'languages/groundwork-common-volunteer-tracker.pot' );

		preg_match( '/"Project-Id-Version: (.+)\\\\n"/', $pot, $found );

		$this->assertNotEmpty( $found, 'The translation template has no Project-Id-Version header.' );

		$this->assertStringEndsWith(
			' ' . GWC_VT_VERSION,
			trim( $found[1] ),
			'The translation template was generated for a different version. Bump the version first, then regenerate it — see the release steps in CLAUDE.md.'
		);
	}

	/**
	 * The schema version is not the plugin version.
	 *
	 * Asserted rather than merely documented, because the two being equal at
	 * 0.1.0-and-1 is a coincidence that invites somebody to "tidy up" by
	 * deriving one from the other. They describe different things and move at
	 * different times: the schema version changes when the shape of a stored
	 * field definition changes, which should be almost never.
	 */
	public function test_the_schema_version_is_independent_of_the_plugin_version(): void {
		$this->assertIsInt( GWC_VT_SCHEMA_VERSION );
		$this->assertMatchesRegularExpression(
			"/const GWC_VT_SCHEMA_VERSION\s*=\s*\d+;/",
			$this->plugin_file(),
			'GWC_VT_SCHEMA_VERSION must be a literal integer, never derived from GWC_VT_VERSION.'
		);
	}
}

<?php
/**
 * One msgid, one explanation of what its placeholders are.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

/**
 * Two calls that share a translatable string share its POT entry, and the
 * entry carries every translator comment written against it. Give the same
 * string two different glosses and a translator gets one entry saying two
 * contradictory things about what the placeholders are.
 *
 * `wp i18n make-pot` warns about this, and nothing in CI runs make-pot — the
 * warning goes to the terminal of whoever last regenerated the template by
 * hand, which is how five of them accumulated unnoticed. This test is that
 * warning, moved somewhere that fails a build.
 *
 * The two shapes it catches are different problems with the same symptom:
 *
 * - **The same sentence glossed twice.** Harmless in itself, but the two
 *   descriptions drift and the entry stops being trustworthy. Make them agree.
 * - **Two different sentences sharing one English string.** The real one. The
 *   signup subject line substituted a date in one place and an event name in
 *   the other; a language that declines a date differently from a proper noun
 *   cannot produce both from a single entry. Those need `_x()` contexts, which
 *   is what this test keys on — same string plus different context is two
 *   entries, and correctly allowed.
 */
final class TranslatorCommentTest extends TestCase {

	/**
	 * Every shipping PHP file. Tests and tooling are not translated.
	 *
	 * @return string[]
	 */
	private function shipping_files(): array {
		$found = array();

		foreach ( array( 'inc', 'blocks' ) as $dir ) {
			$path = GWC_VT_DIR . $dir;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );

			foreach ( $walk as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$found[] = $file->getPathname();
				}
			}
		}

		$found[] = GWC_VT_DIR . 'groundwork-common-volunteer-tracker.php';
		$found[] = GWC_VT_DIR . 'uninstall.php';

		sort( $found );

		return $found;
	}

	/**
	 * Every translators comment, keyed by the POT entry it will land on.
	 *
	 * @return array<string, array<string, string[]>> key => comment => files.
	 */
	private function comments_by_entry(): array {
		/* The codebase uses no _nx() and no _ex(), so _x() is the only source of
		 * a context and it is always the second argument. If one is ever added,
		 * this pattern needs its context position taught to it — a context read
		 * from the wrong argument would merge two entries that are really
		 * separate and fail this test for a reason that is not true.
		 *
		 * The comment body is spelled as "any character that does not begin a
		 * comment terminator" rather than a lazy dot-star. A lazy match
		 * backtracks straight past the terminator it should have stopped at
		 * whenever the next thing is not a gettext call, and goes hunting for
		 * one thousands of characters away. The first run of this test reported
		 * a clash whose quoted "comment" was four hundred lines of unrelated
		 * markup.
		 *
		 * The optional run before the function name is for the calls that are
		 * not the first thing after their comment — the post types' label_count
		 * is an array value, so the comment is followed by "'label_count' => ".
		 * Written without it this test found four of the five collisions that
		 * prompted it and silently missed that one, which would have made it a
		 * weaker check than the tool it exists to replace. It is bounded to a
		 * single line so it cannot start hunting across a file again. */
		$pattern = '/\/\*\s*translators:(?P<comment>(?:(?!\*\/).)*)\*\/\s*'
			. '(?:[^\n\/]{0,120}?(?:=>|=)\s*)?'
			. '(?P<fn>esc_html__|esc_html_e|esc_attr_e|_n_noop|_x|_n|_e|__)\s*\(\s*'
			. '(?P<q1>[\'"])(?P<s1>(?:\\\\.|(?!(?P=q1)).)*)(?P=q1)'
			. '(?:\s*,\s*(?P<q2>[\'"])(?P<s2>(?:\\\\.|(?!(?P=q2)).)*)(?P=q2))?/s';

		$entries = array();

		foreach ( $this->shipping_files() as $file ) {
			$source = (string) file_get_contents( $file );

			if ( ! preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$context = '_x' === $match['fn'] ? ( $match['s2'] ?? '' ) : '';
				$key     = $match['s1'] . "\x00" . $context;

				$comment = trim( (string) preg_replace( '/\s+/', ' ', $match['comment'] ) );

				$entries[ $key ][ $comment ][] = basename( $file );
			}
		}

		return $entries;
	}

	public function test_no_string_carries_two_different_translator_comments(): void {
		$clashes = array();

		foreach ( $this->comments_by_entry() as $key => $comments ) {
			if ( count( $comments ) < 2 ) {
				continue;
			}

			list( $msgid, $context ) = explode( "\x00", $key );

			$lines = array( sprintf( '  "%s"%s', $msgid, '' !== $context ? ' [context: ' . $context . ']' : '' ) );

			foreach ( $comments as $comment => $files ) {
				$lines[] = sprintf( '    %s  (%s)', $comment, implode( ', ', array_unique( $files ) ) );
			}

			$clashes[] = implode( "\n", $lines );
		}

		$this->assertSame(
			array(),
			$clashes,
			"These strings each land on one POT entry carrying more than one translator comment.\n"
				. "Make the comments agree if the two uses mean the same thing, or give them\n"
				. "_x() contexts if they do not — same English, different substitution, is two\n"
				. 'entries and not one.'
		);
	}

	/**
	 * The scan has to actually be finding things.
	 *
	 * Without this the test above passes just as cheerfully on a pattern that
	 * matches nothing at all, which is the failure mode of every test that
	 * asserts an empty array.
	 */
	public function test_the_scan_finds_the_comments_that_are_there(): void {
		$entries = $this->comments_by_entry();

		$this->assertGreaterThan(
			100,
			count( $entries ),
			'The scan found almost no translator comments, so it is not reading the source it thinks it is.'
		);
	}
}

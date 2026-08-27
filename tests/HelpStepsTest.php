<?php
/**
 * Every control a how-to tells somebody to click is a control that exists.
 *
 * ── Why this reads the source rather than a rendered screen ──────────────────
 * tests/integration/help.php already checks the other half — that every
 * "Volunteer Tracker › X" names a row on the menu — by building the real menu.
 * This half is a different question: the guide also says "Select <strong>Add
 * them</strong>", and the only way that is wrong is if no such button is
 * written anywhere. So the check is over the source, which means it needs no
 * database and runs in the unit suite.
 *
 * What it caught when it was written: ten steps naming controls that were not
 * there. "Accept" and "Set aside" on the applications queue, whose buttons say
 * "Add as a volunteer" and "Discard". "Change them" for a repeat, which says
 * "Change these occurrences". "Add to the shift" for a roster, which says "Add
 * them". "Print" for a letter, which says "Open the letter to print". "Match to
 * a volunteer", which is two controls and neither is called that. Two bare
 * "Save"s on screens whose buttons say "Add to the schedule" and "Save this
 * shift". And four "Save changes" against core's "Save Changes".
 *
 * None of that is caught by a person reading the guide, because every one of
 * them reads perfectly. They are only wrong beside the screen.
 *
 * ── What it does not catch, said plainly ─────────────────────────────────────
 * It proves a control EXISTS, not that it is on the screen the step names. The
 * concrete case is a bare "Save": one of the ten bugs above was a step telling
 * somebody to select Save on the schedule, whose button says "Add to the
 * schedule" — and this test would pass it, because a settings sub-screen does
 * have a button that says exactly "Save". Nine of the ten it catches; that one
 * needs a person, and the sabotage sweep for this file records it failing to
 * catch it rather than pretending otherwise.
 *
 * @package VolunteerTracker
 */

use PHPUnit\Framework\TestCase;

final class HelpStepsTest extends TestCase {

	/**
	 * Controls WordPress draws, which this plugin therefore never writes.
	 *
	 * Spelled exactly as core spells them — "Save Changes" is submit_button()'s
	 * default and carries a capital C, which is the whole reason four steps
	 * were quietly wrong about it.
	 *
	 * @return string[]
	 */
	private function core_controls(): array {
		return array( 'Publish', 'Update', 'Save Changes', 'Volunteer Tracker' );
	}

	/**
	 * Every string this plugin writes into an interface.
	 *
	 * @return string
	 */
	private function ui_source(): string {
		$source = '';

		foreach ( array_merge(
			(array) glob( GWC_VT_DIR . 'inc/*.php' ),
			(array) glob( GWC_VT_DIR . 'blocks/*/*.js' ),
			(array) glob( GWC_VT_DIR . 'blocks/*/block.json' )
		) as $file ) {
			$source .= (string) file_get_contents( (string) $file ) . "\n";
		}

		return $source;
	}

	/**
	 * Every control named in a numbered step, and the how-to that names it.
	 *
	 * Read out of the file rather than out of gwc_vt_help_topics(), because the
	 * steps are the thing under test and a regex over the literal array cannot
	 * be fooled by a filter a site has added.
	 *
	 * @return array<string, string[]>
	 */
	private function controls(): array {
		$source = (string) file_get_contents( GWC_VT_DIR . 'inc/help-content.php' );

		$this->assertNotSame( '', $source, 'inc/help-content.php did not load' );

		$task  = '';
		$steps = false;
		$found = array();

		foreach ( explode( "\n", $source ) as $line ) {
			if ( preg_match( "/'title'\s*=>\s*__\( '((?:[^'\\\\]|\\\\.)*)'/", $line, $hit ) ) {
				$task = $hit[1];
			}

			if ( false !== strpos( $line, "'steps'" ) ) {
				$steps = true;
			}

			if ( false !== strpos( $line, "'note'" ) || false !== strpos( $line, "'intro'" ) ) {
				$steps = false;
			}

			if ( ! $steps ) {
				continue;
			}

			if ( preg_match_all( '#<strong>([^<]+)</strong>#', $line, $hits ) ) {
				foreach ( $hits[1] as $control ) {
					$found[ trim( $control ) ][] = $task;
				}
			}
		}

		return $found;
	}

	/**
	 * The parse works, so a green run below means something.
	 *
	 * Without this, a regex that stopped matching would report no controls and
	 * pass every assertion in the file.
	 */
	public function test_the_steps_were_actually_read(): void {
		$controls = $this->controls();

		$this->assertGreaterThan( 30, count( $controls ), 'the how-to steps stopped being parsed' );
		$this->assertArrayHasKey( 'Add them', $controls, 'a known control is missing, so the parse is wrong' );
	}

	/**
	 * Every one of them is a string this plugin writes, or one core does.
	 *
	 * A prefix counts, because some controls carry a value: the letter's second
	 * action is "Email it to %s" and a candidate on the triage screen is "Attach
	 * to %s", and a step cannot quote the whole of either.
	 */
	public function test_every_control_a_step_names_exists(): void {
		$source = $this->ui_source();
		$core   = $this->core_controls();

		foreach ( $this->controls() as $control => $tasks ) {
			if ( in_array( $control, $core, true ) ) {
				continue;
			}

			$straight = str_replace( '’', "'", $control );

			/* A prefix only counts when what follows it is a placeholder. Allowing
			 * any prefix would let a step say "Save" and be satisfied by "Save
			 * this shift", which is the failure this test exists to catch. */
			$this->assertTrue(
				false !== strpos( $source, "'" . $control . "'" )
					|| false !== strpos( $source, '"' . $control . '"' )
					|| false !== strpos( $source, "'" . $straight . "'" )
					|| false !== strpos( $source, "'" . $control . ' %' )
					|| false !== strpos( $source, "'" . $straight . ' %' ),
				sprintf(
					'"%s" is not a control this plugin writes anywhere, but %s tells somebody to select it',
					$control,
					implode( ' and ', array_unique( $tasks ) )
				)
			);
		}
	}
}

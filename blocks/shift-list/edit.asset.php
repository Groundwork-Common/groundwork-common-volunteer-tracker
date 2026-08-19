<?php
/**
 * Hand-written, because there is no build step — see README.md.
 *
 * Keep this in step with what edit.js actually reaches for. A missing
 * dependency here is a block that throws in the editor on a site whose script
 * loading order happens to differ from the one it was written on.
 *
 * @package VolunteerTracker
 */

defined( 'ABSPATH' ) || exit;

/* The guard is here for the same reason it is at the top of every other file,
 * even though this one only returns an array and discloses nothing when fetched
 * directly. The directory's own checker treats an unguarded PHP file as an
 * error rather than a warning, and "it happens to be harmless" is not a thing a
 * scanner can see. Core requires this file from inside a request that has long
 * since defined ABSPATH, so the guard costs nothing. */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version'      => defined( 'GWC_VT_VERSION' ) ? GWC_VT_VERSION : '0',
);

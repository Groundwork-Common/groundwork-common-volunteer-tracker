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

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version'      => defined( 'GWCVT_VERSION' ) ? GWCVT_VERSION : '0',
);

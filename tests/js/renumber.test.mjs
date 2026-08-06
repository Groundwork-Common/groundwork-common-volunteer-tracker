/**
 * The event grid's clone renumbering, against the ids and names the editor
 * actually renders.
 *
 * Node, no framework and no build step — the same rule the rest of the plugin
 * follows. Run it with:  node tests/js/renumber.test.mjs
 *
 * It exists because the first version of these patterns was end-anchored
 * (/-\d+-\d+$/), and an id ends in the field it names rather than in its index,
 * so the pattern matched nothing. The field NAMES renumbered correctly, so a
 * cloned row saved perfectly and looked right on screen — while every label in
 * it still pointed at the first row's input. Clicking a label focused the wrong
 * field and a screen reader announced every row as row one.
 */
import { readFileSync } from 'node:fs';

const source = readFileSync( new URL( '../../assets/js/admin-event-grid.js', import.meta.url ), 'utf8' );

/* The patterns are read out of the script itself, so this file cannot drift
 * from the thing it is checking. */
const patterns = {
	timeName: /renumber\( row, (\/.+?\/), '\[slots\]\[' \+ next/.exec( source ),
	timeId: /renumber\( row, (\/.+?\/), 'gwcvt-slot-\$1-'/.exec( source ),
	roleName: /renumber\( copy, (\/.+?\/), 'gwcvt_roles\['/.exec( source ),
	roleId: /renumber\( copy, (\/.+?\/), 'gwcvt-role-'/.exec( source ),
	roleSlot: /renumber\( copy, (\/.+?\/), 'gwcvt-slot-'/.exec( source ),
};

for ( const [ label, found ] of Object.entries( patterns ) ) {
	if ( ! found ) {
		console.error( `FAIL  could not find the ${ label } pattern in admin-event-grid.js` );
		process.exit( 1 );
	}
}

const re = ( m ) => new RegExp( m[ 1 ].slice( 1, -1 ) );

let bad = 0;

const check = ( label, got, want ) => {
	const ok = got === want;
	if ( ! ok ) {
		bad++;
	}
	console.log( `${ ok ? 'PASS ' : 'FAIL ' } ${ label }${ ok ? '' : `\n        got  ${ got }\n        want ${ want }` }` );
};

/* Exactly what gwcvt_render_event_role_block() and gwcvt_render_event_slot_row()
 * put on the page for role 0, time 0. */
const NAMES = [ 'name', 'supervisor', 'location', 'notes' ]
	.map( ( f ) => `gwcvt_roles[0][${ f }]` )
	.concat( [ 'id', 'date', 'start', 'end', 'overnight', 'min', 'max', 'remove' ].map( ( f ) => `gwcvt_roles[0][slots][0][${ f }]` ) );

const IDS = [ 'gwcvt-role-0', 'gwcvt-role-0-sup', 'gwcvt-role-0-loc', 'gwcvt-role-0-notes' ]
	.concat( [ 'date', 'start', 'end', 'min', 'max' ].map( ( f ) => `gwcvt-slot-0-0-${ f }` ) );

console.log( '── add another time, next index 1 ──' );

for ( const n of NAMES.filter( ( x ) => x.includes( '[slots]' ) ) ) {
	check( n, n.replace( re( patterns.timeName ), '[slots][1]' ), n.replace( '[slots][0]', '[slots][1]' ) );
}

for ( const i of IDS.filter( ( x ) => x.startsWith( 'gwcvt-slot' ) ) ) {
	check( i, i.replace( re( patterns.timeId ), 'gwcvt-slot-$1-1-' ), i.replace( 'gwcvt-slot-0-0-', 'gwcvt-slot-0-1-' ) );
}

for ( const n of NAMES.filter( ( x ) => ! x.includes( '[slots]' ) ) ) {
	check( `${ n } is untouched`, n.replace( re( patterns.timeName ), '[slots][1]' ), n );
}

console.log( '\n── add another role, next index 1 ──' );

for ( const n of NAMES ) {
	check( n, n.replace( re( patterns.roleName ), 'gwcvt_roles[1]' ), n.replace( 'gwcvt_roles[0]', 'gwcvt_roles[1]' ) );
}

for ( const i of IDS ) {
	const got = i.replace( re( patterns.roleId ), 'gwcvt-role-1' ).replace( re( patterns.roleSlot ), 'gwcvt-slot-1-' );
	check( i, got, i.replace( /^gwcvt-role-0/, 'gwcvt-role-1' ).replace( /^gwcvt-slot-0-/, 'gwcvt-slot-1-' ) );
}

/* The shared datalist id is not indexed and must survive every pattern. */
for ( const label of [ 'timeId', 'roleId', 'roleSlot' ] ) {
	check( `the shared datalist id survives ${ label }`, 'gwcvt-event-roles'.replace( re( patterns[ label ] ), 'CHANGED' ), 'gwcvt-event-roles' );
}

console.log( bad === 0 ? '\nALL PASS' : `\n${ bad } FAILED` );
process.exit( bad === 0 ? 0 : 1 );

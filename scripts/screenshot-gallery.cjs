/** Builds the PR gallery from the same scene index as the local HTML report. */
const MARKER = '<!-- documentate-capturas -->';

/** Escape scene text used in GitHub HTML and table cells. */
function escape( value ) {
	return String( value ).replace( /[&<>"|\n\r]/g, ( character ) => ( {
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '|': '&#124;', '\n': ' ', '\r': ' ',
	} )[ character ] );
}

/** Render captured screen sizes, with explicit failures and commit provenance. */
function gallery( shots, { base, sha, run } ) {
	const scenes = new Map();
	for ( const shot of shots ) {
		if ( ! scenes.has( shot.title ) ) scenes.set( shot.title, [] );
		scenes.get( shot.title ).push( shot );
	}
	const screens = [ 'escritorio', 'movil' ].filter( ( screen ) => shots.some( ( shot ) => shot.screenId === screen ) );
	const labels = { escritorio: 'Ordenador', movil: 'Móvil' };
	const failures = shots.filter( ( shot ) => ! shot.ok ).length;
	const lines = [
		MARKER,
		`## Capturas del ciclo completo · ${ sha.slice( 0, 7 ) }`,
		'',
		`${ shots.length } capturas; ${ failures ? `**${ failures } escenas sin completar**` : 'todas las escenas completadas' }.`,
		`Documento 0 (propuesta de gasto) y resolución administrativa, con área, gestión y administración. [Informe HTML descargable en esta ejecución](${ run }).`,
		'',
	];
	for ( const [ title, group ] of scenes ) {
		lines.push( `<details><summary>${ group.every( ( shot ) => shot.ok ) ? '✓' : '✗' } ${ escape( title ) }</summary>`, '',
			escape( group[ 0 ].text ), '', `| Perfil | ${ screens.map( ( screen ) => labels[ screen ] ).join( ' | ' ) } |`, `|---|${ screens.map( () => '---|' ).join( '' ) }` );
		const images = screens.map( ( screen ) => {
			const shot = group.find( ( item ) => item.screenId === screen );
			if ( ! shot ) return 'Sin captura';
			if ( ! /^img\/[a-z0-9-]+\.png$/.test( shot.img ) ) throw new Error( 'Invalid screenshot path' );
			const url = base + shot.img;
			return `${ shot.ok ? '' : `**✗ ${ escape( shot.error || 'Escena incompleta' ) }**<br>` }<a href="${ url }"><img src="${ url }" alt="${ escape( title ) } (${ screen })" width="${ screen === 'movil' ? 220 : 640 }"></a>`;
		} );
		lines.push( `| ${ escape( group[ 0 ].who || group[ 0 ].as ) } | ${ images.join( ' | ' ) } |`, '', '</details>', '' );
	}
	return lines.join( '\n' );
}

module.exports = { gallery, MARKER };

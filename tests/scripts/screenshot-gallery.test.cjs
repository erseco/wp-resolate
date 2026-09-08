const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { gallery, MARKER } = require( '../../scripts/screenshot-gallery.cjs' );

test( 'gallery pairs screens, identifies failures, and preserves commit provenance', () => {
	const scene = { title: 'Crear <documento> | revisar', text: 'Texto de la escena', as: 'Área', img: 'img/01-escritorio-crear.png', screenId: 'escritorio', ok: true };
	const body = gallery( [ scene, { ...scene, screenId: 'movil', img: 'img/01-movil-crear.png', ok: false, error: '<error> | detalle' } ], {
		base: 'https://raw.githubusercontent.com/owner/repo/commit/', sha: '123456789', run: 'https://github.com/owner/repo/actions/runs/1',
	} );
	assert.ok( body.includes( MARKER ) );
	assert.match( body, /1234567/ );
	assert.match( body, /2 capturas; \*\*1 escenas sin completar\*\*/ );
	assert.match( body, /&lt;documento&gt; &#124; revisar/ );
	assert.match( body, /&lt;error&gt; &#124; detalle/ );
	assert.match( body, /commit\/img\/01-escritorio-crear.png/ );
	assert.match( body, /commit\/img\/01-movil-crear.png/ );
	assert.equal( ( body.match( /<details>/g ) || [] ).length, 1 );
	assert.throws( () => gallery( [ { ...scene, img: '../secret' } ], { base: '', sha: '1234567', run: '' } ), /Invalid screenshot path/ );
} );

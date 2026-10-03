/* global wp, wpmTemplate */
( function ( $ ) {
	'use strict';

	const textarea = document.getElementById( 'wpm-template-html' );
	const frame = document.getElementById( 'wpm-preview' );
	if ( ! textarea || ! frame ) {
		return;
	}

	let cm = null;
	if ( wpmTemplate.editor && wp.codeEditor ) {
		cm = wp.codeEditor.initialize( textarea, wpmTemplate.editor ).codemirror;
	}

	const getValue = () => ( cm ? cm.getValue() : textarea.value );
	const insert = ( text ) => {
		if ( cm ) {
			cm.replaceSelection( text );
			cm.focus();
		} else {
			const start = textarea.selectionStart;
			textarea.setRangeText( text, start, textarea.selectionEnd, 'end' );
			textarea.dispatchEvent( new Event( 'input' ) );
		}
	};

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	}

	/* Mirrors Renderer::merge() so the preview matches what recipients get. */
	function render( html ) {
		const sample = wpmTemplate.sample || {};
		html = html.includes( '{{content}}' ) ? html.split( '{{content}}' ).join( wpmTemplate.content ) : html.replace( /<\/body>/i, wpmTemplate.content + '</body>' );
		html = html.split( '{{preheader}}' ).join( '' );
		html = html.replace( /\{\{\s*(unsubscribe_url|view_in_browser_url)\s*\}\}/g, '#' );
		return html.replace( /\{\{\s*([a-zA-Z0-9_]+)\s*(?:\|([^{}]*))?\}\}/g, ( m, key, fallback ) => {
			key = key.toLowerCase();
			if ( key === 'date' ) {
				return new Date().toLocaleDateString();
			}
			const value = sample[ key ] !== undefined && String( sample[ key ] ).trim() !== '' ? sample[ key ] : ( fallback || '' ).trim();
			return escapeHtml( value );
		} );
	}

	let timer = null;
	function refresh() {
		clearTimeout( timer );
		timer = setTimeout( () => {
			// sandbox="" (no allow-scripts) keeps template scripts from running in the admin.
			frame.srcdoc = render( getValue() );
		}, 250 );
	}

	if ( cm ) {
		cm.on( 'change', refresh );
		cm.setSize( null, 620 );
	} else {
		textarea.addEventListener( 'input', refresh );
	}
	refresh();

	document.querySelectorAll( '.wpm-device' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			document.querySelectorAll( '.wpm-device' ).forEach( ( b ) => b.classList.remove( 'is-active' ) );
			btn.classList.add( 'is-active' );
			frame.style.width = btn.dataset.width;
		} );
	} );

	const tagSelect = document.getElementById( 'wpm-insert-tag' );
	tagSelect.addEventListener( 'change', () => {
		if ( tagSelect.value ) {
			insert( tagSelect.value );
			tagSelect.value = '';
		}
	} );

	let media = null;
	document.getElementById( 'wpm-insert-image' ).addEventListener( 'click', () => {
		if ( ! media ) {
			media = wp.media( { title: 'Insert image', library: { type: 'image' }, multiple: false } );
			media.on( 'select', () => {
				const img = media.state().get( 'selection' ).first().toJSON();
				const width = Math.min( img.width || 600, 600 );
				insert( '<img src="' + img.url + '" alt="' + escapeHtml( img.alt || '' ) + '" width="' + width + '" style="display:block;max-width:100%;height:auto;border:0;">' );
			} );
		}
		media.open();
	} );

	// Warn before leaving with unsaved edits.
	let dirty = false;
	const form = document.getElementById( 'wpm-template-form' );
	if ( cm ) {
		cm.on( 'change', () => ( dirty = true ) );
	} else {
		textarea.addEventListener( 'input', () => ( dirty = true ) );
	}
	form.addEventListener( 'submit', () => {
		if ( cm ) {
			cm.save();
		}
		dirty = false;
	} );
	$( window ).on( 'beforeunload', () => ( dirty ? true : undefined ) );
}( jQuery ) );

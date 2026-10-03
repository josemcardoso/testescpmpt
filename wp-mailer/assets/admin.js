/* global wpmAdmin */
( function () {
	'use strict';

	const i18n = ( window.wpmAdmin && wpmAdmin.i18n ) || {};

	function post( action, data ) {
		const body = new URLSearchParams( Object.assign( { action, _ajax_nonce: wpmAdmin.nonce }, data ) );
		return fetch( wpmAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } ).then( ( r ) => r.json() );
	}

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => {
			if ( k === 'text' ) {
				node.textContent = v;
			} else {
				node.setAttribute( k, v );
			}
		} );
		( children || [] ).forEach( ( c ) => node.appendChild( c ) );
		return node;
	}

	/* Confirmation prompts on links and submit buttons with data-confirm. */
	document.addEventListener( 'click', ( e ) => {
		const target = e.target.closest( '[data-confirm]' );
		if ( target && target.tagName !== 'FORM' && ! window.confirm( target.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );
	document.addEventListener( 'submit', ( e ) => {
		const form = e.target;
		if ( form.matches( 'form[data-confirm]' ) && ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	/* Report page auto-refresh while sending. */
	const auto = document.querySelector( '[data-autorefresh]' );
	if ( auto ) {
		setTimeout( () => window.location.reload(), 1000 * parseInt( auto.dataset.autorefresh, 10 ) );
	}

	/* Settings: reload column choices when the contacts table changes. */
	const tableSelect = document.getElementById( 'wpm-source_table' );
	if ( tableSelect ) {
		tableSelect.addEventListener( 'change', () => {
			post( 'wpm_table_columns', { table: tableSelect.value } ).then( ( res ) => {
				const cols = res.success ? res.data : [];
				[ 'wpm-source_email_col', 'wpm-source_id_col' ].forEach( ( id ) => {
					const sel = document.getElementById( id );
					sel.innerHTML = '';
					sel.appendChild( el( 'option', { value: '', text: '—' } ) );
					cols.forEach( ( c ) => sel.appendChild( el( 'option', { value: c, text: c } ) ) );
					const guess = cols.find( ( c ) => ( id.includes( 'email' ) ? /e-?mail/i : /^(id|.*_id)$/i ).test( c ) );
					if ( guess ) {
						sel.value = guess;
					}
				} );
			} );
		} );
	}

	/* Campaign audience: rule builder. */
	const root = document.getElementById( 'wpm-segment' );
	if ( ! root ) {
		return;
	}
	const columns = JSON.parse( root.dataset.columns || '[]' );
	const operators = JSON.parse( root.dataset.operators || '{}' );
	const state = JSON.parse( root.dataset.segment || '{"match":"all","rules":[]}' );
	const input = document.getElementById( 'wpm-segment-input' );
	const noValue = [ 'empty', 'not_empty' ];

	function sync() {
		input.value = JSON.stringify( state );
	}

	function render() {
		root.innerHTML = '';
		if ( state.rules.length > 1 ) {
			const match = el( 'select', { 'aria-label': 'match' } );
			[ [ 'all', 'all rules' ], [ 'any', 'any rule' ] ].forEach( ( [ v, t ] ) => match.appendChild( el( 'option', { value: v, text: t } ) ) );
			match.value = state.match;
			match.addEventListener( 'change', () => {
				state.match = match.value;
				sync();
			} );
			root.appendChild( el( 'p', {}, [ document.createTextNode( 'Contacts matching ' ), match ] ) );
		}

		state.rules.forEach( ( rule, i ) => {
			const col = el( 'select', { 'aria-label': 'column' } );
			columns.forEach( ( c ) => col.appendChild( el( 'option', { value: c, text: c } ) ) );
			col.value = rule.column;

			const op = el( 'select', { 'aria-label': 'operator' } );
			Object.entries( operators ).forEach( ( [ v, t ] ) => op.appendChild( el( 'option', { value: v, text: t } ) ) );
			op.value = rule.op;

			const val = el( 'input', { type: 'text', 'aria-label': 'value', class: 'regular-text' } );
			val.value = rule.value || '';
			val.hidden = noValue.includes( rule.op );

			const remove = el( 'button', { type: 'button', class: 'button-link wpm-danger', text: '✕', 'aria-label': 'Remove rule' } );

			col.addEventListener( 'change', () => {
				rule.column = col.value;
				sync();
			} );
			op.addEventListener( 'change', () => {
				rule.op = op.value;
				val.hidden = noValue.includes( rule.op );
				sync();
			} );
			val.addEventListener( 'input', () => {
				rule.value = val.value;
				sync();
			} );
			remove.addEventListener( 'click', () => {
				state.rules.splice( i, 1 );
				sync();
				render();
			} );

			root.appendChild( el( 'div', { class: 'wpm-rule' }, [ col, op, val, remove ] ) );
		} );

		if ( ! state.rules.length ) {
			root.appendChild( el( 'p', { class: 'description', text: 'Everyone in the table.' } ) );
		}
		const add = el( 'button', { type: 'button', class: 'button', text: '+ Add rule' } );
		add.addEventListener( 'click', () => {
			state.rules.push( { column: columns[ 0 ] || '', op: 'eq', value: '' } );
			sync();
			render();
		} );
		root.appendChild( add );
	}

	render();
	sync();

	const countBtn = document.getElementById( 'wpm-segment-count' );
	const result = document.getElementById( 'wpm-segment-result' );
	const sample = document.getElementById( 'wpm-segment-sample' );
	countBtn.addEventListener( 'click', () => {
		result.textContent = i18n.loading || '…';
		sample.innerHTML = '';
		post( 'wpm_segment_preview', { segment: input.value } ).then( ( res ) => {
			if ( ! res.success ) {
				result.textContent = ( res.data && res.data.message ) || 'Error';
				return;
			}
			const d = res.data;
			result.textContent = d.deliverable.toLocaleString() + ' ' + ( i18n.recipients || 'recipients' ) +
				( d.matching !== d.deliverable ? ' (' + d.matching.toLocaleString() + ' rows match; invalid, duplicate or suppressed addresses are skipped)' : '' );
			if ( ! d.sample.length ) {
				return;
			}
			const keys = Object.keys( d.sample[ 0 ] ).slice( 0, 6 );
			const thead = el( 'thead', {}, [ el( 'tr', {}, keys.map( ( k ) => el( 'th', { text: k } ) ) ) ] );
			const tbody = el( 'tbody', {}, d.sample.map( ( row ) => el( 'tr', {}, keys.map( ( k ) => el( 'td', { text: row[ k ] } ) ) ) ) );
			sample.appendChild( el( 'table', { class: 'widefat striped wpm-sample' }, [ thead, tbody ] ) );
		} );
	} );
}() );

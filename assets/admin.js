( () => {
	'use strict';
	const editor = document.getElementById( 'easycontact-template' );
	document.querySelectorAll( '[data-easycontact-insert]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			if ( ! editor ) {
				return;
			}
			const type = button.dataset.easycontactInsert;
			let suffix = 1;
			while ( editor.value.includes( `${ type }_${ suffix } ` ) ) {
				suffix++;
			}
			const required = [ 'checkbox', 'number' ].includes( type ) ? '' : '*';
			let options = '';
			if ( type === 'select' ) {
				options = ` "${ window.easycontactAdmin.optionOne }" "${ window.easycontactAdmin.optionTwo }"`;
			}
			if ( type === 'number' ) {
				options = ' min:0 step:1';
			}
			const tag = `[${ type }${ required } ${ type }_${ suffix } "${ button.textContent.trim() }"${ options }]`;
			const end = editor.value.indexOf( '\n', editor.selectionEnd );
			const position = end < 0 ? editor.value.length : end + 1;
			editor.setRangeText( ( position && editor.value[ position - 1 ] !== '\n' ? '\n' : '' ) + tag + '\n', position, position, 'end' );
			editor.focus();
			editor.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	} );
	const rules = document.getElementById( 'easycontact-rules' );
	let index = Date.now();
	document.getElementById( 'easycontact-add-rule' )?.addEventListener( 'click', () => {
		const template = document.getElementById( 'easycontact-rule-template' );
		if ( ! template || ! rules || rules.children.length >= 80 ) {
			return;
		}
		const row = template.content.firstElementChild.cloneNode( true );
		row.querySelectorAll( '[name]' ).forEach( ( control ) => {
			control.name = control.name.replaceAll( '__INDEX__', String( index ) );
		} );
		index++;
		rules.appendChild( row );
		row.querySelector( 'select' ).focus();
	} );
	rules?.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-easycontact-remove]' );
		if ( button ) {
			button.closest( 'tr' ).remove();
		}
	} );
	document.querySelectorAll( 'input[readonly][aria-label="Shortcode"]' ).forEach( ( input ) => input.addEventListener( 'focus', () => input.select() ) );
} )();

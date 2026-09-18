/**
 * Batch import/export screens: column picker, upload step, mapping, progress and result.
 *
 * Compiled to import-export.js by `npm run build`.
 */

type AxcCountKey = 'created' | 'updated' | 'skipped' | 'failed';
type AxcPlural = [ string, string ];

interface AxcI18n {
	complete: string;
	created: AxcPlural;
	updated: AxcPlural;
	skipped: AxcPlural;
	failed: AxcPlural;
	viewLog: string;
	fileUploaded: string;
	row: string;
	reason: string;
	error: string;
}

interface AxcConfig {
	ajaxUrl: string;
	nonce: string;
	i18n: AxcI18n;
}

interface AxcLogEntry {
	row: number;
	status: string;
	message: string;
}

interface AxcImportBatch {
	created: number;
	updated: number;
	skipped: number;
	failed: number;
	log: AxcLogEntry[];
	position: number;
	row: number;
	percent: number;
}

interface AxcExportBatch {
	total: number;
	done: number;
	percent: number;
	token: string;
	page: number;
	url?: string;
}

type AxcParams = Record< string, string | number | string[] >;

interface Window {
	axellcoreIE?: AxcConfig;
}

( function (): void {
	const cfg = window.axellcoreIE;
	if ( ! cfg ) {
		return;
	}

	const countKeys: AxcCountKey[] = [ 'created', 'updated', 'skipped', 'failed' ];

	function post< T >( data: AxcParams ): Promise< T > {
		const body = new URLSearchParams();
		Object.keys( data ).forEach( ( key ) => {
			const value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) => body.append( key + '[]', item ) );
			} else {
				body.append( key, String( value ) );
			}
		} );
		body.append( 'security', cfg.nonce );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( response ) => response.json() )
			.then( ( json ) => {
				if ( ! json.success ) {
					throw new Error( json.data && json.data.message ? json.data.message : cfg.i18n.error );
				}
				return json.data as T;
			} );
	}

	function byId< T extends HTMLElement = HTMLElement >( id: string ): T {
		return document.getElementById( id ) as T;
	}

	function setBar( root: HTMLElement, percent: number ): void {
		const bar = root.querySelector( '.axc-bar' ) as HTMLElement;
		bar.setAttribute( 'aria-valuenow', String( percent ) );
		( bar.firstElementChild as HTMLElement ).style.width = percent + '%';
	}

	function el< K extends keyof HTMLElementTagNameMap >( tag: K, text?: string, className?: string ): HTMLElementTagNameMap[ K ] {
		const node = document.createElement( tag );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		if ( className ) {
			node.className = className;
		}
		return node;
	}

	/* ---------- Column picker (chips over a real <select multiple>) ---------- */
	function enhanceColumns( select: HTMLSelectElement ): void {
		const field = el( 'div', undefined, 'axc-tokens' );
		const list = el( 'ul', undefined, 'axc-tokens__chips' );
		const placeholder = el( 'span', select.dataset.placeholder, 'axc-tokens__placeholder' );
		const menu = el( 'ul', undefined, 'axc-tokens__menu' );
		menu.hidden = true;
		menu.setAttribute( 'role', 'listbox' );

		const toggle = el( 'button', undefined, 'axc-tokens__toggle' );
		toggle.type = 'button';
		toggle.setAttribute( 'aria-haspopup', 'listbox' );
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.appendChild( list );
		toggle.appendChild( placeholder );

		const label = select.id ? document.querySelector( 'label[for="' + select.id + '"]' ) : null;
		if ( label ) {
			label.id = select.id + '-label';
			toggle.setAttribute( 'aria-labelledby', label.id );
		}

		const setMenu = ( open: boolean ): void => {
			menu.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		};

		const render = (): void => {
			list.textContent = '';
			menu.textContent = '';
			let selected = 0;

			Array.from( select.options ).forEach( ( option ) => {
				if ( option.selected ) {
					selected++;
					const chip = el( 'li', undefined, 'axc-chip' );
					const remove = el( 'button', '×' );
					remove.type = 'button';
					remove.setAttribute( 'aria-label', option.text );
					remove.addEventListener( 'click', ( event ) => {
						event.stopPropagation();
						option.selected = false;
						render();
					} );
					chip.appendChild( remove );
					chip.appendChild( el( 'span', option.text ) );
					list.appendChild( chip );
					return;
				}

				const item = el( 'li', option.text );
				item.setAttribute( 'role', 'option' );
				item.tabIndex = 0;
				const pick = (): void => {
					option.selected = true;
					render();
				};
				item.addEventListener( 'click', pick );
				item.addEventListener( 'keydown', ( event ) => {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						pick();
					}
				} );
				menu.appendChild( item );
			} );

			placeholder.hidden = selected > 0;
			if ( ! menu.children.length ) {
				setMenu( false );
			}
		};

		toggle.addEventListener( 'click', () => setMenu( menu.hidden ) );
		document.addEventListener( 'click', ( event ) => {
			if ( ! field.contains( event.target as Node ) ) {
				setMenu( false );
			}
		} );
		field.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' ) {
				setMenu( false );
				toggle.focus();
			}
		} );

		field.appendChild( toggle );
		field.appendChild( menu );
		select.hidden = true;
		select.parentNode.insertBefore( field, select.nextSibling );
		render();
	}

	document.querySelectorAll< HTMLSelectElement >( 'select.axc-columns' ).forEach( enhanceColumns );

	/* ---------- Import: upload step ---------- */
	const upload = document.getElementById( 'axc-upload' );
	if ( upload ) {
		const fileInput = byId< HTMLInputElement >( 'axc-file' );
		const urlInput = byId< HTMLInputElement >( 'axc-file-url' );
		const next = upload.querySelector< HTMLButtonElement >( 'button[type="submit"]' );

		const refresh = (): void => {
			next.disabled = ! fileInput.value && ! urlInput.value.trim();
		};
		fileInput.addEventListener( 'change', refresh );
		urlInput.addEventListener( 'input', refresh );
		refresh();

		const toggle = upload.querySelector< HTMLButtonElement >( '.axc-summary-toggle' );
		const advanced = byId( 'axc-advanced' );
		toggle.addEventListener( 'click', () => {
			advanced.hidden = ! advanced.hidden;
			toggle.setAttribute( 'aria-expanded', advanced.hidden ? 'false' : 'true' );
		} );
	}

	/* ---------- Import: mapping, progress and result ---------- */
	function setStep( number: number ): void {
		document.querySelectorAll( '.axc-step' ).forEach( ( item, index ) => {
			const position = index + 1;
			item.className = 'axc-step' + ( position < number ? ' is-done' : position === number ? ' is-current' : '' );
			if ( position === number ) {
				item.setAttribute( 'aria-current', 'step' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );
	}

	function plural( count: number, forms: AxcPlural ): string {
		return ( count === 1 ? forms[ 0 ] : forms[ 1 ] ).replace( '%s', count.toLocaleString() );
	}

	const importRoot = document.getElementById( 'axc-import' );
	if ( importRoot ) {
		const form = byId< HTMLFormElement >( 'axc-import-form' );

		// Warn when both the combined and the separate version of a field are mapped.
		const note = byId( 'axc-priority-note' );
		const checkPriority = (): void => {
			const chosen = Array.from( form.querySelectorAll< HTMLSelectElement >( 'select[name^="mapping"]' ) ).map( ( select ) => select.value );
			const has = ( id: string ): boolean => chosen.indexOf( id ) !== -1;
			const combinedAndSeparate =
				( has( 'phone' ) && ( has( 'phone_1' ) || has( 'phone_2' ) ) ) ||
				( has( 'location' ) && ( has( 'country' ) || has( 'state' ) || has( 'city' ) ) );
			note.hidden = ! combinedAndSeparate;
		};
		form.addEventListener( 'change', checkPriority );
		checkPriority();

		const progress = byId( 'axc-progress' );
		const done = byId( 'axc-done' );
		const totals: Record< AxcCountKey, number > = { created: 0, updated: 0, skipped: 0, failed: 0 };
		let entries: AxcLogEntry[] = [];

		const renderDone = (): void => {
			const parts: string[] = [];
			countKeys.forEach( ( key ) => {
				if ( totals[ key ] > 0 ) {
					parts.push( plural( totals[ key ], cfg.i18n[ key ] ) );
				}
			} );

			const text = byId( 'axc-done-text' );
			const log = byId( 'axc-log' );
			text.textContent = '';
			text.appendChild( el( 'strong', cfg.i18n.complete ) );
			text.appendChild( document.createTextNode( ' ' + parts.join( '. ' ) + ( parts.length ? '.' : '' ) ) );

			if ( entries.length ) {
				const link = el( 'a', cfg.i18n.viewLog );
				link.href = '#';
				link.addEventListener( 'click', ( event ) => {
					event.preventDefault();
					log.hidden = ! log.hidden;
				} );
				text.appendChild( document.createTextNode( ' ' ) );
				text.appendChild( link );
			}

			if ( importRoot.dataset.fileName ) {
				text.appendChild( document.createTextNode( ' ' + cfg.i18n.fileUploaded.replace( '%s', importRoot.dataset.fileName ) ) );
			}

			if ( entries.length ) {
				const table = el( 'table', undefined, 'widefat axc-table' );
				const head = table.createTHead().insertRow();
				head.appendChild( el( 'th', cfg.i18n.row ) );
				head.appendChild( el( 'th', cfg.i18n.reason ) );
				const body = table.createTBody();
				entries.slice( 0, 500 ).forEach( ( entry ) => {
					const tr = body.insertRow();
					tr.insertCell().textContent = String( entry.row );
					tr.insertCell().textContent = entry.message;
				} );
				log.textContent = '';
				log.appendChild( table );
			}
		};

		const runImport = ( params: AxcParams, position: number, row: number ): Promise< void > =>
			post< AxcImportBatch >( Object.assign( {}, params, { position, row } ) ).then( ( result ) => {
				countKeys.forEach( ( key ) => {
					totals[ key ] += result[ key ];
				} );
				entries = entries.concat( result.log );
				setBar( progress, result.percent );

				if ( result.percent < 100 ) {
					return runImport( params, result.position, result.row );
				}
			} );

		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();

			const params: AxcParams = {
				action: 'axellcore_import_batch',
				post_type: importRoot.dataset.postType,
				file: importRoot.dataset.file,
				delimiter: importRoot.dataset.delimiter,
				encoding: importRoot.dataset.encoding,
				update_existing: importRoot.dataset.updateExisting === '1' ? '1' : '',
			};
			new FormData( form ).forEach( ( value, name ) => {
				if ( /^(mapping|map_from)\[\d+\]$/.test( name ) ) {
					params[ name ] = String( value );
				}
			} );

			form.hidden = true;
			progress.hidden = false;
			setStep( 3 );

			runImport( params, 0, 0 )
				.then( () => {
					progress.hidden = true;
					done.hidden = false;
					setStep( 4 );
					renderDone();
				} )
				.catch( ( error: Error ) => {
					progress.hidden = true;
					done.hidden = false;
					byId( 'axc-done-text' ).textContent = error.message;
				} );
		} );
	}

	/* ---------- Export ---------- */
	const exportForm = document.getElementById( 'axc-export' ) as HTMLFormElement | null;
	if ( exportForm ) {
		const exportProgress = byId( 'axc-export-progress' );
		const status = byId( 'axc-export-status' );

		const runExport = ( params: AxcParams, page: number, token: string ): Promise< void > =>
			post< AxcExportBatch >( Object.assign( {}, params, { page, token } ) ).then( ( result ) => {
				setBar( exportProgress, result.percent );

				if ( result.url ) {
					window.location.href = result.url;
					return new Promise< void >( ( resolve ) => {
						window.setTimeout( resolve, 1500 );
					} );
				}
				return runExport( params, page + 1, result.token );
			} );

		exportForm.addEventListener( 'submit', ( event ) => {
			event.preventDefault();

			const fields = new FormData( exportForm );
			const params: AxcParams = {
				action: 'axellcore_export_batch',
				post_type: exportForm.dataset.postType,
				columns: fields.getAll( 'columns[]' ) as string[],
				countries: fields.getAll( 'countries[]' ) as string[],
				states: fields.getAll( 'states[]' ) as string[],
				cities: fields.getAll( 'cities[]' ) as string[],
			};
			const button = exportForm.querySelector< HTMLButtonElement >( 'button[type="submit"]' );
			button.disabled = true;
			exportForm.classList.add( 'is-running' );
			exportProgress.hidden = false;
			setBar( exportProgress, 0 );

			runExport( params, 1, '' )
				.then( () => {
					exportForm.classList.remove( 'is-running' );
					exportProgress.hidden = true;
					status.textContent = '';
				} )
				.catch( ( error: Error ) => {
					// Keep the progress visible with the message; bring the fields back.
					exportForm.classList.remove( 'is-running' );
					exportProgress.hidden = false;
					setBar( exportProgress, 0 );
					status.textContent = error.message;
				} )
				.then( () => {
					button.disabled = false;
				} );
		} );
	}
} )();

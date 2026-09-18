/**
 * Adds the Import/Export links beside the "Add New" button on the list screen.
 *
 * Compiled to list-links.js by `npm run build`.
 */

interface AxcListLink {
	label: string;
	url: string;
}

interface Window {
	axellcoreLinks?: AxcListLink[];
}

( function (): void {
	const links = window.axellcoreLinks;
	const anchor = document.querySelector( '.wrap .page-title-action' );

	if ( ! links || ! links.length || ! anchor ) {
		return;
	}

	let previous: Element = anchor;
	links.forEach( ( item ) => {
		const link = document.createElement( 'a' );
		link.className = 'page-title-action';
		link.href = item.url;
		link.textContent = item.label;
		previous.insertAdjacentElement( 'afterend', link );
		previous = link;
	} );
} )();

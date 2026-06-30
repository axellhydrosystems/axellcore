/// <reference types="jquery" />
/// <reference types="jqueryui" />

declare const ajaxurl: string;

jQuery( function ( $ ) {
	const tableSelector  = 'table.wp-list-table';
	const itemSelector   = 'tbody tr:not(.inline-edit-row)';
	const postIdSelector = '.check-column input[type="checkbox"]';

	$( tableSelector ).find( 'tbody td, tbody th' ).css( 'cursor', 'move' );

	$( tableSelector ).sortable( {
		items:                itemSelector,
		cursor:               'move',
		cancel:               'a, input, button, select, option',
		axis:                 'y',
		forcePlaceholderSize: true,
		helper:               'clone',
		opacity:              0.65,
		placeholder:          'post-placeholder',
		scrollSensitivity:    40,
		start( _event: Event, ui: JQueryUI.SortableUIParams ): void {
			if ( ! ui.item.hasClass( 'alternate' ) ) {
				ui.item.css( 'background-color', '#ffffff' );
			}
			ui.item.children( 'td, th' ).css( 'border-bottom-width', '0' );
			ui.item.css( 'outline', '1px solid #aaa' );
		},
		stop( _event: Event, ui: JQueryUI.SortableUIParams ): void {
			ui.item.removeAttr( 'style' );
			ui.item.children( 'td, th' ).css( 'border-bottom-width', '1px' );
		},
		update( _event: Event, ui: JQueryUI.SortableUIParams ): void {
			const postid     = String( ui.item.find( postIdSelector ).val() );
			const termparent = ui.item.find( '.parent' ).html();

			let prevpostid: string | undefined = ui.item.prev().find( postIdSelector ).val() as string | undefined;
			let nextpostid: string | undefined = ui.item.next().find( postIdSelector ).val() as string | undefined;

			let prevtermparent: string | undefined;
			let nexttermparent: string | undefined;

			if ( prevpostid !== undefined ) {
				prevtermparent = ui.item.prev().find( '.parent' ).html();
				if ( prevtermparent !== termparent ) {
					prevpostid = undefined;
				}
			}

			if ( nextpostid !== undefined ) {
				nexttermparent = ui.item.next().find( '.parent' ).html();
				if ( nexttermparent !== termparent ) {
					nextpostid = undefined;
				}
			}

			if (
				( prevpostid === undefined && nextpostid === undefined ) ||
				( nextpostid === undefined && nexttermparent === prevpostid ) ||
				( nextpostid !== undefined && prevtermparent === postid )
			) {
				$( tableSelector ).sortable( 'cancel' );
				return;
			}

			ui.item.find( '.check-column input' ).hide();
			ui.item
				.find( '.check-column' )
				.append(
					'<img alt="processing" src="images/wpspin_light.gif" class="waiting" style="margin-left: 6px;" />'
				);

			$.post(
				ajaxurl,
				{
					action: 'axell_post_ordering',
					id:     postid,
					nextid: nextpostid,
				},
				function ( response: string ): void {
					if ( response === 'children' ) {
						window.location.reload();
					} else {
						ui.item.find( '.check-column input' ).show();
						ui.item.find( '.check-column' ).find( 'img' ).remove();
					}
				}
			);

			$( 'table.widefat tbody tr' ).each( function () {
				const i = jQuery( 'table.widefat tbody tr' ).index( this );
				if ( i % 2 === 0 ) {
					jQuery( this ).addClass( 'alternate' );
				} else {
					jQuery( this ).removeClass( 'alternate' );
				}
			} );
		},
	} );
} );

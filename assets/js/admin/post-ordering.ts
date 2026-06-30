/// <reference types="jquery" />
/// <reference types="jqueryui" />

declare const ajaxurl: string;

interface JQueryStatic {
	axellAddMissingSortHandles(): void;
}

jQuery( function ( $ ) {
	const tableSelector = 'table.wp-list-table';
	const itemSelector  = 'tbody tr:not(.inline-edit-row)';
	const columnHandle  = '<td class="column-handle"></td>';
	let postIdSelector  = '.column-handle input[name="post_id"]';

	if ( 0 === $( tableSelector ).find( '.column-handle' ).length ) {
		$( tableSelector )
			.find( 'tr:not(.inline-edit-row)' )
			.append( columnHandle );
		postIdSelector = '.check-column input';
	}

	$( tableSelector ).find( '.column-handle' ).show();

	$.axellAddMissingSortHandles = function (): void {
		const allTableRows   = $( tableSelector ).find( 'tbody > tr' );
		const rowsWithHandle = $( tableSelector )
			.find( 'tbody > tr > td.column-handle' )
			.parent();
		if ( allTableRows.length !== rowsWithHandle.length ) {
			allTableRows.each( function ( _index: number, elem: HTMLElement ) {
				if ( ! rowsWithHandle.is( elem ) ) {
					$( elem ).append( columnHandle );
				}
			} );
		}
		$( tableSelector ).find( '.column-handle' ).show();
	};

	$( document ).ajaxComplete( function (
		_event: JQuery.TriggeredEvent,
		request: JQuery.jqXHR,
		options: JQuery.AjaxSettings
	): void {
		const data = typeof options.data === 'string' ? options.data : '';
		if (
			request &&
			4 === request.readyState &&
			200 === request.status &&
			data &&
			( 0 <= data.indexOf( '_inline_edit' ) ||
				0 <= data.indexOf( 'add-tag' ) )
		) {
			$.axellAddMissingSortHandles();
			$( document.body ).trigger( 'init_tooltips' );
		}
	} );

	$( tableSelector ).sortable( {
		items:                itemSelector,
		cursor:               'move',
		handle:               '.column-handle',
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

/**
 * Append further batches to the lists on a scripture page.
 *
 * Progressive enhancement. Every button is a real link to somewhere the reader
 * can go, so nothing here is required for the page to work; this only spares
 * them a page load.
 */
( function () {
	'use strict';

	if ( typeof window.scslLoadMore === 'undefined' || ! window.scslLoadMore.endpoint ) {
		return;
	}

	/**
	 * The container a button appends into: the list immediately before it.
	 *
	 * @param {HTMLElement} button
	 * @return {HTMLElement|null}
	 */
	function listFor( button ) {
		var wrap = button.closest( '.scsl-loadmore' );

		if ( ! wrap ) {
			return null;
		}

		var previous = wrap.previousElementSibling;

		while ( previous ) {
			/*
			 * The grid itself, not the section wrapped around it. Appending to
			 * the section would drop the new cards below the grid rather than
			 * into it, so a part-filled last row stays part-filled and every
			 * batch after it starts on a fresh line.
			 */
			var grid = previous.matches( '.scsl-series-grid, .scsl-list-results, .scsl-service-sermons' )
				? previous
				: previous.querySelector( '.scsl-series-grid, .scsl-list-results, .scsl-service-sermons' );

			if ( grid ) {
				return grid;
			}

			if ( previous.children.length ) {
				return previous;
			}

			previous = previous.previousElementSibling;
		}

		return null;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-scsl-loadmore]' );

		if ( ! button || button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}

		var list = listFor( button );

		// Without somewhere to put the results, let the link do its job.
		if ( ! list ) {
			return;
		}

		event.preventDefault();

		var original = button.textContent;

		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = button.getAttribute( 'data-loading' ) || 'Loading…';

		var url = window.scslLoadMore.endpoint
			+ '?term=' + encodeURIComponent( button.getAttribute( 'data-term' ) )
			+ '&list=' + encodeURIComponent( button.getAttribute( 'data-scsl-loadmore' ) )
			+ '&offset=' + encodeURIComponent( button.getAttribute( 'data-offset' ) );

		fetch( url, { headers: { Accept: 'application/json' } } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Request failed' );
				}

				return response.json();
			} )
			.then( function ( data ) {
				if ( ! data || ! data.html ) {
					throw new Error( 'Empty batch' );
				}

				var holder = document.createElement( 'div' );
				holder.innerHTML = data.html;

				var added = Array.prototype.slice.call( holder.children );

				added.forEach( function ( node ) {
					list.appendChild( node );
				} );

				var offset = parseInt( button.getAttribute( 'data-offset' ), 10 ) || 0;

				button.setAttribute( 'data-offset', offset + added.length );
				button.setAttribute( 'aria-busy', 'false' );
				button.textContent = original;

				if ( ! data.more ) {
					button.parentNode.removeChild( button );
				}

				// Send focus to the first new row, otherwise a keyboard or
				// screen reader user is left where the button used to be with
				// no indication that anything arrived.
				if ( added.length ) {
					var target = added[ 0 ].querySelector( 'a, h2, h3' ) || added[ 0 ];

					if ( ! target.hasAttribute( 'tabindex' ) ) {
						target.setAttribute( 'tabindex', '-1' );
					}

					target.focus( { preventScroll: true } );
				}
			} )
			.catch( function () {
				// Put the link back the way it was and let the next click
				// navigate, which still gets the reader to the sermons.
				button.setAttribute( 'aria-busy', 'false' );
				button.textContent = original;
				button.removeAttribute( 'data-scsl-loadmore' );
			} );
	} );
}() );

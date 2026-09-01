/**
 * Sermon Library: copy Seedcast-hosted audio into this site's media library.
 *
 * Two passes. The first only asks how big the files are, so the size is known
 * before anything downloads and nobody fills their hosting by accident. The
 * second copies them a couple at a time, because these are whole recordings
 * and one request carrying twenty of them would time out on most hosting.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.scslReclaim === 'undefined' ) {
		return;
	}

	var cfg  = window.scslReclaim;
	var i18n = cfg.i18n || {};

	var $button, $status, $log;

	function post( action, data ) {
		return $.ajax( {
			url:  cfg.ajaxUrl,
			type: 'POST',
			data: $.extend( { action: action, nonce: cfg.nonce }, data || {} )
		} );
	}

	function chunk( list, size ) {
		var out = [];

		for ( var i = 0; i < list.length; i += size ) {
			out.push( list.slice( i, i + size ) );
		}

		return out;
	}

	function formatBytes( bytes ) {
		if ( bytes >= 1073741824 ) {
			return ( bytes / 1073741824 ).toFixed( 1 ) + ' GB';
		}
		return Math.round( bytes / 1048576 ) + ' MB';
	}

	function note( text, isError ) {
		$( '<p/>' )
			.css( isError ? { color: '#b32d2e', margin: '.2rem 0' } : { margin: '.2rem 0' } )
			.text( text )
			.appendTo( $log );

		$log.prop( 'hidden', false );
	}

	/**
	 * Ask every file how big it is, without downloading any of them.
	 */
	function measure( batches ) {
		var total   = 0;
		var unknown = 0;
		var chain   = Promise.resolve();

		$status.text( i18n.checking );

		batches.forEach( function ( batch ) {
			chain = chain.then( function () {
				return post( 'scsl_reclaim_size', { posts: batch.join( ',' ) } ).then( function ( res ) {
					if ( res.success ) {
						total   += res.data.bytes || 0;
						unknown += res.data.unknown || 0;
					}
				} );
			} );
		} );

		return chain.then( function () {
			return { total: total, unknown: unknown };
		} );
	}

	function copyAll( batches ) {
		var done   = 0;
		var failed = 0;
		var chain  = Promise.resolve();

		batches.forEach( function ( batch ) {
			chain = chain.then( function () {
				return post( 'scsl_reclaim_copy', { posts: batch.join( ',' ) } ).then( function ( res ) {
					if ( ! res.success ) {
						failed += batch.length;
						note( ( res.data && res.data.message ) || i18n.failed, true );
						return;
					}

					$.each( res.data.results || [], function ( _, row ) {
						if ( row.ok ) {
							done++;
						} else {
							failed++;
							note( row.title + ': ' + row.message, true );
						}
					} );

					$status.text( i18n.copying + ' ' + ( done + failed ) + ' / ' + cfg.posts.length );
				} ).catch( function () {
					// A batch that dies leaves its sermons untouched, so the
					// rest can still be attempted.
					failed += batch.length;
				} );
			} );
		} );

		return chain.then( function () {
			return { done: done, failed: failed };
		} );
	}

	$( function () {
		$button = $( '.scsl-reclaim-go' );

		if ( ! $button.length || ! cfg.posts || ! cfg.posts.length ) {
			return;
		}

		$status = $( '.scsl-reclaim-status' );
		$log    = $( '.scsl-reclaim-log' );

		$button.on( 'click', function () {
			$button.prop( 'disabled', true );

			var batches = chunk( cfg.posts, cfg.batch || 2 );

			measure( batches ).then( function ( size ) {
				var message = size.total
					? i18n.sizeIs.replace( '%s', formatBytes( size.total ) )
					: i18n.sizeUnknown;

				$status.text( message );

				if ( ! window.confirm( message + '\n\n' + i18n.confirm ) ) {
					$button.prop( 'disabled', false );
					$status.text( '' );
					return null;
				}

				return copyAll( batches ).then( function ( result ) {
					$status.text( result.failed ? i18n.someFailed : i18n.done );
					$button.prop( 'disabled', false );

					if ( ! result.failed ) {
						window.setTimeout( function () {
							window.location.reload();
						}, 1500 );
					}
				} );
			} ).catch( function () {
				$status.text( i18n.failed );
				$button.prop( 'disabled', false );
			} );
		} );
	} );

} )( jQuery );

/* SermonLibrary: Sermon List AJAX Filter
 *
 * SEO strategy:
 * - Initial load: server-rendered results + real ?scsl_page=N links (crawlable)
 * - After a filter is applied: AJAX results + AJAX pagination buttons
 * - Clearing filters: restores SEO pagination
 */
( function( $ ) {
    'use strict';

    $( document ).ready( function() {
        $( '.scsl-sermon-list-wrap' ).each( function() {
            initList( $( this ) );
        } );

        // On page load: scroll to list if we arrived via a SEO pagination link
        try {
            if ( sessionStorage.getItem( 'scsl_scroll_list' ) ) {
                sessionStorage.removeItem( 'scsl_scroll_list' );
                var $firstWrap = $( '.scsl-sermon-list-wrap' ).first();
                if ( $firstWrap.length ) {
                    setTimeout( function() { scrollToList( $firstWrap ); }, 120 );
                }
            }
        } catch(e) {}

        // Also scroll if ?sl_page= is in the URL (direct link / bookmark)
        if ( window.location.search.indexOf( 'scsl_page=' ) !== -1 ) {
            var $firstWrap = $( '.scsl-sermon-list-wrap' ).first();
            if ( $firstWrap.length ) {
                setTimeout( function() { scrollToList( $firstWrap ); }, 120 );
            }
        }
    } );

    function scrollToList( $wrap ) {
        var top = $wrap.offset().top - 80; // 80px offset for sticky headers
        $( 'html, body' ).animate( { scrollTop: Math.max( 0, top ) }, 300 );
    }

    function initList( $wrap ) {
        var baseParams = {
            series_id  : $wrap.data( 'series-id' )  || '',
            speaker_id : $wrap.data( 'speaker-id' ) || '',
            topic      : $wrap.data( 'topic' )       || '',
            book       : $wrap.data( 'book' )        || '',
            per_page   : $wrap.data( 'per-page' )    || 10,
            orderby    : $wrap.data( 'orderby' )     || 'recorded_date',
            order      : $wrap.data( 'order' )       || 'DESC',
            template   : $wrap.data( 'template' )    || 'sermon-list-item',
            nonce      : $wrap.data( 'nonce' ),
        };

        var activeFilters  = $.extend( {}, baseParams );
        var filtersApplied = false;

        var $seoPagination  = $wrap.find( '.scsl-list-pagination--seo' );
        var $ajaxPagination = $wrap.find( '.scsl-list-pagination--ajax' );

        // ── Filter change ─────────────────────────────────────────────────
        $wrap.on( 'change', '.scsl-filter-select', function() {
            var key = $( this ).data( 'filter' );
            activeFilters[ key ] = $( this ).val();
            activeFilters.paged  = 1;
            checkFiltersApplied();
            updateClearBtn();
            fetchSermons( $wrap, activeFilters );
        } );

        // ── Search input (debounced) ──────────────────────────────────────
        var searchTimer;
        $wrap.on( 'input', '.scsl-filter-search', function() {
            var val = $( this ).val();
            clearTimeout( searchTimer );
            searchTimer = setTimeout( function() {
                activeFilters.search = val;
                activeFilters.paged  = 1;
                checkFiltersApplied();
                updateClearBtn();
                fetchSermons( $wrap, activeFilters );
            }, 350 );
        } );

        // ── Clear button ──────────────────────────────────────────────────
        $wrap.on( 'click', '.scsl-filter-clear', function() {
            activeFilters = $.extend( {}, baseParams );
            $wrap.find( '.scsl-filter-select' ).each( function() {
                var f = $( this ).data( 'filter' );
                $( this ).val( baseParams[ f ] !== undefined ? baseParams[ f ] : '' );
            } );
            $wrap.find( '.scsl-filter-search' ).val( '' );
            updateClearBtn();
            window.location.href = window.location.pathname;
        } );

        // ── AJAX pagination ───────────────────────────────────────────────
        $wrap.on( 'click', '.scsl-page-btn', function() {
            if ( ! filtersApplied ) return;
            activeFilters.paged = parseInt( $( this ).data( 'page' ), 10 );
            fetchSermons( $wrap, activeFilters );
            scrollToList( $wrap );
        } );

        // ── SEO pagination: flag sessionStorage before navigating ─────────
        $wrap.on( 'click', '.scsl-list-pagination--seo a', function() {
            try { sessionStorage.setItem( 'scsl_scroll_list', '1' ); } catch(e) {}
        } );

        // ────────────────────────────────────────────────────────────────

        function checkFiltersApplied() {
            var wasApplied = filtersApplied;
            filtersApplied = false;
            $wrap.find( '.scsl-filter-select' ).each( function() {
                var f    = $( this ).data( 'filter' );
                var val  = $( this ).val();
                var base = baseParams[ f ] !== undefined ? String( baseParams[ f ] ) : '';
                if ( String( val ) !== base ) filtersApplied = true;
            } );
            if ( $wrap.find( '.scsl-filter-search' ).val() ) filtersApplied = true;

            // If filters just returned to base state, swap back to SEO pagination
            if ( wasApplied && ! filtersApplied ) {
                $seoPagination.show();
                $ajaxPagination.hide();
            }
        }

        function updateClearBtn() {
            var hasFilter = false;
            $wrap.find( '.scsl-filter-select' ).each( function() {
                var f    = $( this ).data( 'filter' );
                var val  = $( this ).val();
                var base = baseParams[ f ] !== undefined ? String( baseParams[ f ] ) : '';
                if ( f !== 'order' && String( val ) !== base ) hasFilter = true;
            } );
            if ( $wrap.find( '.scsl-filter-search' ).val() ) hasFilter = true;
            $wrap.find( '.scsl-filter-clear' ).toggle( hasFilter );
        }
    }

    function fetchSermons( $wrap, params ) {
        var $results        = $wrap.find( '.scsl-list-results' );
        var $seoPagination  = $wrap.find( '.scsl-list-pagination--seo' );
        var $ajaxPagination = $wrap.find( '.scsl-list-pagination--ajax' );
        var $loading        = $wrap.find( '.scsl-list-loading' );

        $results.css( 'opacity', 0.4 );

        /*
         * The words go in now rather than sitting hidden in the page.
         *
         * A hidden element is still text, and anything reading the page as
         * text rather than rendering it ignores the hiding: search engines,
         * readers, and the markdown version of the page all ended up with a
         * stray "Loading" as the last thing on a sermon list, which makes a
         * complete page look cut off.
         */
        if ( ! $loading.children().length ) {
            $loading.html(
                '<span class="scsl-loading-spinner"></span>' +
                ( scslList.loading || 'Loading…' )
            );
        }

        $loading.show();

        $seoPagination.hide();
        $ajaxPagination.show();

        $.post( scslList.ajaxUrl, $.extend( { action: 'scsl_filter_sermons' }, params ) )
            .done( function( res ) {
                if ( res.success ) {
                    $results.html( res.data.html ).css( 'opacity', 1 );
                    $ajaxPagination.html( res.data.pagination || '' );
                }
            } )
            .fail( function() {
                $results.css( 'opacity', 1 );
            } )
            .always( function() {
                $loading.hide();
            } );
    }

} )( jQuery );

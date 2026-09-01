/* Sermon Library Frontend JS */
( function() {
    'use strict';

    // Content Tabs - WCAG 2.1 compliant tab widget
    document.querySelectorAll( '.scsl-content-tabs' ).forEach( function( wrap ) {
        var buttons = Array.prototype.slice.call( wrap.querySelectorAll( '.scsl-tab-btn' ) );
        var panels  = wrap.querySelectorAll( '.sc-tab-panel' );

        function activateTab( btn ) {
            buttons.forEach( function( b ) {
                b.classList.remove( 'is-active' );
                b.setAttribute( 'aria-selected', 'false' );
                b.setAttribute( 'tabindex', '-1' );
            } );
            panels.forEach( function( p ) {
                p.classList.remove( 'is-active' );
                p.setAttribute( 'aria-hidden', 'true' );
            } );
            btn.classList.add( 'is-active' );
            btn.setAttribute( 'aria-selected', 'true' );
            btn.removeAttribute( 'tabindex' );
            var panel = wrap.querySelector( '#' + btn.getAttribute( 'aria-controls' ) );
            if ( panel ) {
                panel.classList.add( 'is-active' );
                panel.setAttribute( 'aria-hidden', 'false' );
            }
        }

        /**
         * Open the tab named in the address, for somebody arriving from a
         * listing elsewhere on the site.
         *
         * The panel is hidden until its tab is active, so it has no position
         * to scroll to until after that. Doing these the other way round
         * scrolls to nothing at all.
         */
        function openFromHash( scroll ) {
            var want = ( window.location.hash || '' ).replace( '#', '' );

            if ( ! want ) {
                return;
            }

            // Panels are named scsl-panel-article, but a link written by hand
            // or from a listing says #article. Both are accepted, since the
            // short one is what anybody would type.
            var btn = buttons.filter( function( b ) {
                var controls = b.getAttribute( 'aria-controls' ) || '';

                return controls === want || controls === 'scsl-panel-' + want;
            } )[0];

            if ( ! btn ) {
                return;
            }

            activateTab( btn );

            if ( ! scroll ) {
                return;
            }

            /*
             * Scrolling once is not enough on arrival. Images above the tabs
             * have no height until they load, so the page grows underneath
             * whatever position was just scrolled to and the target ends up
             * somewhere above the fold. So it is done again once the page has
             * settled, and once more on load for anything slow.
             */
            var settle = function() {
                wrap.scrollIntoView( { behavior: 'smooth', block: 'start' } );
            };

            window.requestAnimationFrame( settle );
            window.setTimeout( settle, 350 );

            if ( document.readyState !== 'complete' ) {
                window.addEventListener( 'load', function() {
                    window.setTimeout( settle, 50 );
                }, { once: true } );
            }
        }

        openFromHash( true );

        // A link followed from elsewhere on the same page, where nothing is
        // loading and one scroll is right.
        window.addEventListener( 'hashchange', function() { openFromHash( true ); } );

        buttons.forEach( function( btn ) {
            btn.addEventListener( 'click', function() { activateTab( btn ); } );
            btn.addEventListener( 'keydown', function( e ) {
                var idx = buttons.indexOf( btn );
                if ( e.key === 'ArrowRight' ) { activateTab( buttons[ ( idx + 1 ) % buttons.length ] ); }
                if ( e.key === 'ArrowLeft'  ) { activateTab( buttons[ ( idx - 1 + buttons.length ) % buttons.length ] ); }
                if ( e.key === 'Home' ) { activateTab( buttons[0] ); }
                if ( e.key === 'End'  ) { activateTab( buttons[ buttons.length - 1 ] ); }
            } );
        } );
    } );

    // Copy link button (title icon)
    document.querySelectorAll( '.scsl-share-btn--copy' ).forEach( function( btn ) {
        btn.addEventListener( 'click', function() {
            var url     = btn.dataset.url;
            var isTitle = btn.classList.contains( 'scsl-title-copy-btn' );

            function showFeedback() {
                if ( isTitle ) {
                    var feedback = btn.nextElementSibling;
                    if ( feedback && feedback.classList.contains( 'scsl-copy-feedback' ) ) {
                        feedback.textContent = 'Copied!';
                        feedback.classList.add( 'is-visible' );
                        setTimeout( function() { feedback.classList.remove( 'is-visible' ); }, 2000 );
                    }
                } else {
                    var label = btn.querySelector( '.scsl-copy-label' );
                    if ( label ) {
                        var orig = label.textContent;
                        label.textContent = 'Copied!';
                        setTimeout( function() { label.textContent = orig; }, 2000 );
                    }
                }
            }

            if ( navigator.clipboard ) {
                navigator.clipboard.writeText( url ).then( showFeedback );
            } else {
                var ta = document.createElement( 'textarea' );
                ta.value = url;
                document.body.appendChild( ta );
                ta.select();
                document.execCommand( 'copy' );
                document.body.removeChild( ta );
                showFeedback();
            }
        } );
    } );

} )();

// ── Series Card click: navigate via JS to bypass Elementor's click interception ──
document.addEventListener( 'click', function( e ) {
	var card = e.target.closest( '.scsl-series-card' );
	if ( ! card ) return;
	var link = card.querySelector( '.scsl-series-card__stretched-link' );
	if ( ! link || ! link.href ) return;
	if ( e.ctrlKey || e.metaKey || e.shiftKey ) return;
	e.preventDefault();
	window.location.href = link.href;
} );

// ── Series Slider ─────────────────────────────────────────────────────
( function() {
	'use strict';

	function initSlider( wrap ) {
		var slider = wrap.querySelector( '.scsl-series-slider' );
		var prev   = wrap.querySelector( '.scsl-slider-btn--prev' );
		var next   = wrap.querySelector( '.scsl-slider-btn--next' );
		if ( ! slider || wrap._slInited ) return;
		wrap._slInited = true;

		function getStep() {
			var item = slider.querySelector( '.scsl-series-slider__item' );
			if ( item ) {
				var gap = parseFloat( getComputedStyle( slider ).gap ) || 20;
				return item.offsetWidth + gap;
			}
			return Math.round( slider.offsetWidth * 0.85 );
		}

		function scrollTo( dir ) {
			slider.scrollBy( { left: dir * getStep(), behavior: 'smooth' } );
		}

		if ( prev ) prev.addEventListener( 'click', function( e ) { e.preventDefault(); scrollTo( -1 ); } );
		if ( next ) next.addEventListener( 'click', function( e ) { e.preventDefault(); scrollTo(  1 ); } );
	}

	function initAll() {
		document.querySelectorAll( '.scsl-series-slider-wrap' ).forEach( initSlider );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	// Re-init after Elementor frontend renders widgets
	window.addEventListener( 'elementor/frontend/init', function() {
		if ( window.elementorFrontend && elementorFrontend.hooks ) {
			elementorFrontend.hooks.addAction( 'frontend/element_ready/global', function( $el ) {
				$el[0].querySelectorAll( '.scsl-series-slider-wrap' ).forEach( initSlider );
			} );
		}
		initAll();
	} );

} )();


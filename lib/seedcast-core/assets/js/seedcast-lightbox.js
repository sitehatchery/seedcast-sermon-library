/* Seedcast Core - lightbox for WordPress galleries.

   GENERATED FILE. DO NOT EDIT.
   Source of truth lives in the seedcast-core repository.
   ------------------------------------------------------------------
   Loaded by Frontend\Gallery on the pages a plugin enables it for. Opens
   gallery photos in place instead of leaving the page for a bare image:
   previous and next through that gallery, the caption, arrow keys, swipe,
   and Escape or a click outside the photo to close.

   Built on <dialog>, which brings the focus trap and the top layer with it.
   Vanilla JS, no deps. */
( function () {
	'use strict';

	var strings   = window.seedcastLightbox || {};
	var IMAGE     = /\.(jpe?g|png|gif|webp|avif)(\?.*)?$/i;
	var galleries = document.querySelectorAll( '.sc-gallery .gallery' );

	if ( ! galleries.length || 'undefined' === typeof HTMLDialogElement ) {
		return;
	}

	var dialog, img, caption, count;
	var items   = [];
	var current = 0;
	var trigger = null;

	// The largest file a thumbnail offers, for a gallery that still links to
	// attachment pages rather than to the images themselves.
	function largest( thumb ) {
		var best      = thumb.currentSrc || thumb.src;
		var bestWidth = 0;

		( thumb.getAttribute( 'srcset' ) || '' ).split( ',' ).forEach( function ( candidate ) {
			var parts = candidate.trim().split( /\s+/ );
			var width = parseInt( parts[1], 10 );
			if ( parts[0] && width > bestWidth ) {
				bestWidth = width;
				best      = parts[0];
			}
		} );

		return best;
	}

	function collect( gallery ) {
		var list = [];

		gallery.querySelectorAll( '.gallery-item' ).forEach( function ( item ) {
			var thumb = item.querySelector( 'img' );
			if ( ! thumb ) {
				return;
			}
			var link = thumb.closest( 'a' );
			var cap  = item.querySelector( '.gallery-caption' );

			list.push( {
				src:     link && IMAGE.test( link.getAttribute( 'href' ) || '' ) ? link.href : largest( thumb ),
				alt:     thumb.getAttribute( 'alt' ) || '',
				// A plugin's own photo grid can keep captions off its small
				// thumbnails and hand them over on the link instead.
				caption: cap ? cap.textContent.trim() : ( ( link && link.getAttribute( 'data-caption' ) ) || '' ),
				target:  link || thumb
			} );
		} );

		return list;
	}

	function build() {
		dialog = document.createElement( 'dialog' );
		dialog.className = 'sc-lightbox';
		dialog.setAttribute( 'aria-label', strings.label || 'Image viewer' );

		// Fixed markup only; every label and caption goes in as text below.
		dialog.innerHTML =
			'<figure class="sc-lightbox__figure">' +
				'<img class="sc-lightbox__img" alt="">' +
				'<figcaption class="sc-lightbox__caption"></figcaption>' +
			'</figure>' +
			'<p class="sc-lightbox__count" aria-live="polite"></p>' +
			'<button type="button" class="sc-lightbox__prev"></button>' +
			'<button type="button" class="sc-lightbox__next"></button>' +
			'<button type="button" class="sc-lightbox__close" autofocus></button>';

		document.body.appendChild( dialog );

		img     = dialog.querySelector( '.sc-lightbox__img' );
		caption = dialog.querySelector( '.sc-lightbox__caption' );
		count   = dialog.querySelector( '.sc-lightbox__count' );

		var prev  = dialog.querySelector( '.sc-lightbox__prev' );
		var next  = dialog.querySelector( '.sc-lightbox__next' );
		var close = dialog.querySelector( '.sc-lightbox__close' );

		prev.textContent  = '‹';
		next.textContent  = '›';
		close.textContent = '×';
		prev.setAttribute( 'aria-label', strings.prev || 'Previous image' );
		next.setAttribute( 'aria-label', strings.next || 'Next image' );
		close.setAttribute( 'aria-label', strings.close || 'Close' );

		prev.addEventListener( 'click', function () {
			show( current - 1 );
		} );
		next.addEventListener( 'click', function () {
			show( current + 1 );
		} );
		close.addEventListener( 'click', closeViewer );

		// A click on the dim area around the photo closes it.
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog || e.target.classList.contains( 'sc-lightbox__figure' ) ) {
				closeViewer();
			}
		} );

		dialog.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowLeft' === e.key ) {
				e.preventDefault();
				show( current - 1 );
			} else if ( 'ArrowRight' === e.key ) {
				e.preventDefault();
				show( current + 1 );
			} else if ( 'Escape' === e.key ) {
				// A dialog closes itself on Escape only in some circumstances,
				// depending on the browser and how it was opened, so do it here.
				e.preventDefault();
				closeViewer();
			}
		} );

		var startX = null;
		dialog.addEventListener( 'touchstart', function ( e ) {
			startX = e.changedTouches[0].clientX;
		}, { passive: true } );
		dialog.addEventListener( 'touchend', function ( e ) {
			if ( null === startX ) {
				return;
			}
			var dx = e.changedTouches[0].clientX - startX;
			startX = null;
			if ( Math.abs( dx ) > 40 ) {
				show( dx < 0 ? current + 1 : current - 1 );
			}
		} );

		// The browser's own ways of closing a dialog come through here too.
		dialog.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			closeViewer();
		} );

		// A backstop only. The close event can arrive late, or not at all in
		// some embedded browsers, and one that arrives after the viewer has
		// been opened again must not tidy it away.
		dialog.addEventListener( 'close', function () {
			if ( ! dialog.open ) {
				cleanUp();
			}
		} );
	}

	// Unlock the page, drop the large image and give focus back to the photo
	// that opened the viewer. Safe to run twice.
	function cleanUp() {
		document.documentElement.classList.remove( 'sc-lightbox-open' );
		if ( img ) {
			img.removeAttribute( 'src' );
		}
		if ( trigger ) {
			trigger.focus();
			trigger = null;
		}
	}

	// Every way of closing ends here, and tidies up at once rather than
	// waiting on the dialog's close event.
	function closeViewer() {
		if ( dialog && dialog.open ) {
			dialog.close();
		}
		cleanUp();
	}

	function show( index ) {
		var total = items.length;
		current   = ( index + total ) % total;

		var item = items[ current ];
		img.src             = item.src;
		img.alt             = item.alt;
		caption.textContent = item.caption;
		caption.hidden      = '' === item.caption;
		count.textContent   = ( strings.count || '%1$s of %2$s' )
			.replace( '%1$s', String( current + 1 ) )
			.replace( '%2$s', String( total ) );

		// Fetch the neighbours now, so moving through feels immediate.
		[ current + 1, current - 1 ].forEach( function ( i ) {
			( new Image() ).src = items[ ( i + total ) % total ].src;
		} );
	}

	function open( list, index, from ) {
		if ( ! dialog ) {
			build();
		}
		items   = list;
		trigger = from;
		dialog.toggleAttribute( 'data-single', list.length < 2 );
		show( index );
		document.documentElement.classList.add( 'sc-lightbox-open' );
		dialog.showModal();
	}

	galleries.forEach( function ( gallery ) {
		var list = collect( gallery );

		list.forEach( function ( item, index ) {
			var target = item.target;

			if ( 'A' === target.tagName ) {
				// Elementor opens its own lightbox on image links, and two at
				// once is worse than none.
				target.setAttribute( 'data-elementor-open-lightbox', 'no' );
			} else {
				// An unlinked gallery photo still opens, from the keyboard too.
				target.setAttribute( 'tabindex', '0' );
				target.setAttribute( 'role', 'button' );
			}

			target.addEventListener( 'click', function ( e ) {
				// Ctrl, Cmd, Shift or a middle click still open the link the
				// usual way, in a new tab or window.
				if ( e.ctrlKey || e.metaKey || e.shiftKey || 0 !== e.button ) {
					return;
				}
				e.preventDefault();
				open( list, index, target );
			} );

			target.addEventListener( 'keydown', function ( e ) {
				if ( 'A' !== target.tagName && ( 'Enter' === e.key || ' ' === e.key ) ) {
					e.preventDefault();
					open( list, index, target );
				}
			} );
		} );
	} );
}() );

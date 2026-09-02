/* Seedcast Core - shared front-end behaviour.

   GENERATED FILE. DO NOT EDIT.
   Source of truth lives in the seedcast-core repository.
   ------------------------------------------------------------------
   Slider navigation, front-end tabs, captcha rendering, AJAX form
   submission, and the share-button copy control. Vanilla JS, no deps. */
( function () {
	'use strict';

	function initSlider( slider ) {
		var track = slider.querySelector( '.sc-slider__track' );
		var prev  = slider.querySelector( '.sc-slider__nav--prev' );
		var next  = slider.querySelector( '.sc-slider__nav--next' );
		if ( ! track ) return;

		function scrollAmount() {
			var slide = track.querySelector( '.sc-slider__slide' );
			return slide ? slide.getBoundingClientRect().width + 24 : track.clientWidth * 0.8;
		}
		function update() {
			if ( ! prev || ! next ) return;
			var maxScroll = track.scrollWidth - track.clientWidth - 1;
			prev.disabled = track.scrollLeft <= 0;
			next.disabled = track.scrollLeft >= maxScroll;
		}
		if ( prev ) prev.addEventListener( 'click', function () { track.scrollBy( { left: -scrollAmount(), behavior: 'smooth' } ); } );
		if ( next ) next.addEventListener( 'click', function () { track.scrollBy( { left:  scrollAmount(), behavior: 'smooth' } ); } );
		track.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.sc-slider' ).forEach( initSlider );
	} );
}() );

/* Shared front-end tabs: .sc-tab-wrap > .sc-tabs .sc-tab[data-target] + .sc-tab-panel */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.sc-tab' );
		if ( ! btn ) return;
		var wrap = btn.closest( '.sc-tab-wrap' );
		if ( ! wrap ) return;
		var target = btn.getAttribute( 'data-target' );
		wrap.querySelectorAll( '.sc-tab' ).forEach( function ( b ) { b.classList.remove( 'active' ); } );
		wrap.querySelectorAll( '.sc-tab-panel' ).forEach( function ( p ) { p.classList.remove( 'active' ); } );
		btn.classList.add( 'active' );
		var panel = document.getElementById( target );
		if ( panel ) panel.classList.add( 'active' );
	} );
}() );

/* Captcha: explicit render for every provider (reCAPTCHA v2, hCaptcha,
   Turnstile), instead of each provider's own implicit auto-render. Implicit
   widgets can't be reliably reset one-at-a-time - after ANY failed
   submission (wrong captcha, honeypot, a transient server error, anything)
   the token is already spent, and without a reset the person is stuck
   re-submitting a dead token until they reload the page. Explicit rendering
   lets us track each widget's ID (scoped to its own form) and reset just
   that one on failure. Seedcast\Core\Submissions\Captcha::render_widget() prints
   a plain .sc-captcha[data-provider][data-sitekey] mount point - none of the
   providers' own auto-render scanners recognize that class, so nothing
   double-renders. */
( function () {
	'use strict';
	var PROVIDER_SRC = {
		recaptcha: 'https://www.google.com/recaptcha/api.js?onload=seedcastCaptchaOnload&render=explicit',
		hcaptcha:  'https://hcaptcha.com/1/api.js?onload=seedcastCaptchaOnload&render=explicit',
		turnstile: 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=seedcastCaptchaOnload'
	};
	var loadedScripts = {};
	var widgets = new WeakMap(); // .sc-captcha element -> { provider, widgetId }

	function renderOne( el ) {
		if ( widgets.has( el ) ) return; // already rendered
		var provider = el.getAttribute( 'data-provider' );
		var sitekey  = el.getAttribute( 'data-sitekey' );
		if ( ! provider || ! sitekey ) return;
		try {
			if ( provider === 'recaptcha' && window.grecaptcha && window.grecaptcha.render ) {
				widgets.set( el, { provider: provider, widgetId: window.grecaptcha.render( el, { sitekey: sitekey } ) } );
			} else if ( provider === 'hcaptcha' && window.hcaptcha && window.hcaptcha.render ) {
				widgets.set( el, { provider: provider, widgetId: window.hcaptcha.render( el, { sitekey: sitekey } ) } );
			} else if ( provider === 'turnstile' && window.turnstile && window.turnstile.render ) {
				widgets.set( el, { provider: provider, widgetId: window.turnstile.render( el, { sitekey: sitekey } ) } );
			}
		} catch ( e ) {
			if ( window.console ) console.error( 'Seedcast captcha render failed:', e );
		}
	}

	function renderAll() {
		document.querySelectorAll( '.sc-captcha[data-provider]' ).forEach( renderOne );
	}

	// Each provider's script calls this once loaded (all three support an
	// ?onload= callback param). Widgets present before the script loads are
	// rendered here; any added to the page later (e.g. AJAX-loaded content)
	// are caught by the DOMContentLoaded pass below if the script already
	// finished loading by then.
	window.seedcastCaptchaOnload = renderAll;

	function ensureScript( provider ) {
		if ( loadedScripts[ provider ] ) return;
		loadedScripts[ provider ] = true;
		var src = PROVIDER_SRC[ provider ];
		if ( ! src ) return;
		var s = document.createElement( 'script' );
		s.src = src;
		s.async = true;
		s.defer = true;
		document.head.appendChild( s );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.sc-captcha[data-provider]' ).forEach( function ( el ) {
			ensureScript( el.getAttribute( 'data-provider' ) );
		} );
		// In case a provider script somehow already loaded and called its
		// onload before this listener ran.
		renderAll();
	} );

	/**
	 * Resets just the captcha widget inside a given form, so a failed
	 * submission doesn't leave a dead, already-used token behind. Exposed on
	 * window so the AJAX submit handler below (and any custom form JS) can
	 * call it without a page reload.
	 */
	window.seedcastResetCaptcha = function ( formEl ) {
		if ( ! formEl ) return;
		var el = formEl.querySelector( '.sc-captcha[data-provider]' );
		if ( ! el ) return;
		var info = widgets.get( el );
		if ( ! info ) return;
		try {
			if ( info.provider === 'recaptcha' && window.grecaptcha ) window.grecaptcha.reset( info.widgetId );
			else if ( info.provider === 'hcaptcha' && window.hcaptcha ) window.hcaptcha.reset( info.widgetId );
			else if ( info.provider === 'turnstile' && window.turnstile ) window.turnstile.reset( info.widgetId );
		} catch ( e ) {
			if ( window.console ) console.error( 'Seedcast captcha reset failed:', e );
		}
	};
}() );

/* AJAX form submission: keeps success/error inline in the posting form only,
   so multiple Seedcast forms on one page don't all show the same notice, and
   no full-page reload occurs. Falls back to normal POST if fetch is missing. */
( function () {
	'use strict';
	if ( typeof window.fetch !== 'function' || typeof window.seedcastCore === 'undefined' ) return;

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.matches || ! form.matches( '.sc-form[data-sc-ajax]' ) ) return;
		e.preventDefault();

		var notice = form.querySelector( '.sc-form__notice' );
		var submit = form.querySelector( '[type="submit"]' );
		if ( submit ) submit.disabled = true;
		if ( notice ) { notice.className = 'sc-form__notice'; notice.textContent = ''; }

		var data = new FormData( form );
		// Route to the AJAX action instead of admin-post.
		data.set( 'action', 'seedcast_ajax_submit' );

		// Diagnostic logging, off unless a site explicitly turns it on with
		// window.seedcastDebug = true. A failed submission is hard to diagnose
		// without it, and noisy for everyone else with it always on.
		if ( window.seedcastDebug && window.console ) {
			console.log( 'Seedcast form submit →', {
				source: data.get( 'seedcast_source' ),
				type:   data.get( 'seedcast_type' ),
				url:    seedcastCore.ajaxUrl
			} );
		}

		fetch( seedcastCore.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text().then( function ( text ) {
					if ( window.seedcastDebug && window.console ) {
						console.log( 'Seedcast form submit ← HTTP ' + r.status, text.length > 800 ? text.slice( 0, 800 ) + '…(truncated)' : text );
					}
					if ( ! r.ok ) {
						// The server responded, but with an HTTP error (500, 403, …)
						// rather than the JSON the handler normally returns.
						throw new Error( 'HTTP ' + r.status + ': ' + text.slice( 0, 200 ) );
					}
					try {
						return JSON.parse( text );
					} catch ( parseErr ) {
						// The response wasn't valid JSON - almost always stray
						// output (a PHP notice/warning from this plugin, a
						// conflicting plugin, or the theme) printed ahead of
						// the JSON. The full body was already logged above.
						throw parseErr;
					}
				} );
			} )
			.then( function ( res ) {
				var ok = res && res.success;
				var msg = ( res && res.data && res.data.message ) ? res.data.message : ( ok ? 'Thank you.' : 'Something went wrong.' );

				/*
				 * A plugin can send back markup to put in place of the form,
				 * so a submission turns into its own answer rather than a
				 * notice above a form that has just been emptied. Replaces the
				 * nearest ancestor marked data-sc-replace, falling back to the
				 * form itself.
				 */
				if ( ok && res.data && res.data.replace ) {
					var target = form.closest( '[data-sc-replace]' ) || form;
					var holder = document.createElement( 'div' );
					holder.innerHTML = res.data.replace;

					var replacement = holder.firstElementChild;
					if ( replacement && target.parentNode ) {
						target.parentNode.replaceChild( replacement, target );

						// Announce it, since nothing has moved on screen for
						// somebody using a screen reader and the form they were
						// in has just gone.
						replacement.setAttribute( 'role', 'status' );
						replacement.setAttribute( 'aria-live', 'polite' );
						replacement.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
						return;
					}
				}

				if ( notice ) {
					notice.className = 'sc-form__notice sc-notice sc-notice--' + ( ok ? 'success' : 'error' );
					notice.textContent = msg;
					// The notice sits at the top of the form; scroll it into view
					// so the message is visible even if the form was long and the
					// person had scrolled down to the submit button.
					notice.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
				// A captcha token is single-use either way: on success the
				// widget needs to be fresh for a possible next submission; on
				// failure the token that was just sent is already spent, so
				// leaving it "checked" would only let the person retry with a
				// dead token and fail again. Reset in both cases.
				if ( window.seedcastResetCaptcha ) window.seedcastResetCaptcha( form );
				if ( ok ) { form.reset(); }
			} )
			.catch( function ( err ) {
				if ( window.console ) console.error( 'Seedcast form submit failed:', err );
				if ( window.seedcastResetCaptcha ) window.seedcastResetCaptcha( form );
				if ( notice ) {
					notice.className = 'sc-form__notice sc-notice sc-notice--error';
					notice.textContent = 'Request failed. Please try again.';
					notice.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} )
			.finally( function () {
				if ( submit ) submit.disabled = false;
			} );
	} );
}() );

/* No-JS-fallback notice: scroll it into view on page load too, since the
   classic redirect path re-renders the whole page from scratch. */
( function () {
	'use strict';
	document.addEventListener( 'DOMContentLoaded', function () {
		var el = document.querySelector( '.sc-notice--scroll-target' );
		if ( el ) el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	} );
}() );

/* Share buttons: copy-link control. */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-sc-copy-url]' );
		if ( ! btn ) return;
		e.preventDefault();
		var url = btn.getAttribute( 'data-sc-copy-url' ) || '';
		if ( ! url ) return;

		var idle   = btn.querySelector( '.sc-share__label--copy' );
		var copied = btn.querySelector( '.sc-share__label--copied' );

		var status = btn.parentNode.querySelector( '[data-sc-copy-status]' );

		var swap = function () {
			if ( idle )   idle.hidden = true;
			if ( copied ) copied.hidden = false;
			btn.classList.add( 'is-copied' );
			// The visual label swap says nothing to a screen reader, so
			// announce it separately.
			if ( status ) { status.textContent = 'Link copied'; }
			setTimeout( function () {
				if ( idle )   idle.hidden = false;
				if ( copied ) copied.hidden = true;
				btn.classList.remove( 'is-copied' );
				if ( status ) { status.textContent = ''; }
			}, 1800 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( swap ).catch( fallback );
		} else {
			fallback();
		}

		function fallback() {
			var ta = document.createElement( 'textarea' );
			ta.value = url;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); swap(); } catch ( err ) { /* silent */ }
			document.body.removeChild( ta );
		}
	} );
}() );

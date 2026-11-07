/**
 * The ask page, asked in place.
 *
 * Without this file the page still works: the form submits, the page reloads
 * with the answer. With it, a question is added to the conversation at once,
 * a thinking state shows while the library is read, and the answer arrives in
 * the same markup the page itself prints, through the REST route that shares
 * its code. The address is kept in step, so a refresh or a shared link shows
 * the latest question.
 */
( function () {
	'use strict';

	var cfg = window.scslAsk;
	var main = document.querySelector( '.scsl-ask' );

	if ( ! cfg || ! main || ! window.fetch ) {
		return;
	}

	var thread = main.querySelector( '.scsl-ask__thread' );
	var form = main.querySelector( '.scsl-ask__composer' );
	var input = form ? form.querySelector( '.scsl-ask__input' ) : null;
	var send = form ? form.querySelector( '.scsl-ask__send' ) : null;
	var from = form ? form.querySelector( 'input[name="from"]' ) : null;
	var busy = false;
	var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	if ( ! thread || ! form || ! input ) {
		return;
	}

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	// The box grows with what is typed, up to the cap in the stylesheet.
	function fit() {
		input.style.height = 'auto';
		input.style.height = input.scrollHeight + 'px';
		if ( send ) {
			send.disabled = busy || '' === input.value.trim();
		}
	}

	function scrollTo( node ) {
		if ( node && node.scrollIntoView ) {
			node.scrollIntoView( { behavior: still ? 'auto' : 'smooth', block: 'start' } );
		}
	}

	/**
	 * Reveal the answer a few words at a time, the way an answer being
	 * written reads. Only the words already printed are touched, so links and
	 * markup stay as the server made them.
	 */
	function reveal( answer ) {
		var text = answer.querySelector( '.scsl-ask__text' );
		var rest = Array.prototype.slice.call( answer.querySelectorAll( '.scsl-ask__label, .scsl-ask__sources, .scsl-ask__chips, .scsl-ask__pages' ) );

		if ( still || ! text ) {
			return;
		}

		var words = [];
		var walker = document.createTreeWalker( text, NodeFilter.SHOW_TEXT, null );
		var nodes = [];
		var node;

		while ( ( node = walker.nextNode() ) ) {
			nodes.push( node );
		}

		nodes.forEach( function ( textNode ) {
			var parts = textNode.textContent.split( /(\s+)/ );
			var frag = document.createDocumentFragment();

			parts.forEach( function ( part ) {
				if ( '' === part.trim() ) {
					frag.appendChild( document.createTextNode( part ) );
					return;
				}
				var span = el( 'span', 'is-hidden', part );
				words.push( span );
				frag.appendChild( span );
			} );

			textNode.parentNode.replaceChild( frag, textNode );
		} );

		rest.forEach( function ( part ) {
			part.style.opacity = '0';
		} );

		var i = 0;
		var step = Math.max( 1, Math.ceil( words.length / 60 ) );

		( function next() {
			for ( var n = 0; n < step && i < words.length; n++, i++ ) {
				words[ i ].className = 'is-shown';
			}
			if ( i < words.length ) {
				window.setTimeout( next, 28 );
				return;
			}
			rest.forEach( function ( part ) {
				part.style.transition = 'opacity .3s ease';
				part.style.opacity = '1';
			} );
		} )();
	}

	function thinking() {
		var row = el( 'div', 'scsl-ask__a is-new' );
		var mark = el( 'span', 'scsl-ask__mark' );
		mark.setAttribute( 'aria-hidden', 'true' );
		mark.innerHTML = cfg.mark;

		var body = el( 'div', 'scsl-ask__body' );
		var box = el( 'div', 'scsl-ask__thinking' );
		var dots = el( 'span', 'scsl-ask__dots' );
		dots.setAttribute( 'aria-hidden', 'true' );
		dots.appendChild( el( 'i' ) );
		dots.appendChild( el( 'i' ) );
		dots.appendChild( el( 'i' ) );

		var status = el( 'span', 'scsl-ask__status', cfg.thinking[ 0 ] );
		box.appendChild( dots );
		box.appendChild( status );
		body.appendChild( box );
		row.appendChild( mark );
		row.appendChild( body );

		var at = 0;
		var timer = window.setInterval( function () {
			at = Math.min( at + 1, cfg.thinking.length - 1 );
			status.style.opacity = '0';
			window.setTimeout( function () {
				status.textContent = cfg.thinking[ at ];
				status.style.opacity = '1';
			}, 200 );
			if ( at === cfg.thinking.length - 1 ) {
				window.clearInterval( timer );
			}
		}, 1800 );

		row.stop = function () {
			window.clearInterval( timer );
		};

		return row;
	}

	function failed() {
		var row = el( 'div', 'scsl-ask__a is-new' );
		var mark = el( 'span', 'scsl-ask__mark' );
		mark.setAttribute( 'aria-hidden', 'true' );
		mark.innerHTML = cfg.mark;
		var body = el( 'div', 'scsl-ask__body' );
		body.appendChild( el( 'p', 'scsl-ask__text', cfg.failed ) );
		row.appendChild( mark );
		row.appendChild( body );
		return row;
	}

	function ask( question ) {
		question = ( question || '' ).trim();

		if ( '' === question || busy ) {
			return;
		}

		busy = true;
		main.classList.add( 'scsl-ask--started' );

		var turn = el( 'div', 'scsl-ask__turn is-new' );
		var bubble = el( 'div', 'scsl-ask__q' );
		bubble.appendChild( el( 'p', '', question ) );
		turn.appendChild( bubble );

		var wait = thinking();
		turn.appendChild( wait );
		thread.appendChild( turn );
		scrollTo( turn );

		input.value = '';
		fit();

		// Kept in the address, so a refresh or a shared link shows it.
		if ( window.history && window.history.replaceState ) {
			var url = new URL( cfg.url, window.location.href );
			url.searchParams.set( 'q', question );
			if ( from && from.value ) {
				url.searchParams.set( 'from', from.value );
			}
			window.history.replaceState( null, '', url.toString() );
		}

		var headers = { 'Content-Type': 'application/json' };

		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}

		window.fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( { q: question, from: from ? from.value : '' } )
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( response.status );
			} )
			.then( function ( data ) {
				wait.stop();

				var holder = document.createElement( 'div' );
				holder.innerHTML = data && data.html ? data.html : '';
				var answer = holder.firstElementChild || failed();

				answer.classList.add( 'is-new' );
				turn.replaceChild( answer, wait );
				reveal( answer );
			} )
			.catch( function () {
				wait.stop();
				turn.replaceChild( failed(), wait );
			} )
			.then( function () {
				busy = false;
				fit();
				input.focus();
			} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		ask( input.value );
	} );

	// Enter asks; Shift+Enter is a new line, as in any chat box.
	input.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key && ! event.shiftKey && ! event.isComposing ) {
			event.preventDefault();
			ask( input.value );
		}
	} );

	input.addEventListener( 'input', fit );

	// Suggested and follow-up questions are links that ask without reloading.
	main.addEventListener( 'click', function ( event ) {
		var chip = event.target.closest ? event.target.closest( '[data-ask]' ) : null;

		if ( ! chip ) {
			return;
		}

		event.preventDefault();
		ask( chip.getAttribute( 'data-ask' ) );
	} );

	fit();

	// The first answer, printed with the page, is revealed the same way as
	// the rest, and the box is ready for the next question.
	var first = thread.querySelector( '.scsl-ask__a' );
	if ( first ) {
		reveal( first );
	}
	input.focus( { preventScroll: true } );
} )();

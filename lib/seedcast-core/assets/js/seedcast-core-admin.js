/* Seedcast Core - shared admin behaviour.

   GENERATED FILE. DO NOT EDIT.
   Source of truth lives in the seedcast-core repository.
   ------------------------------------------------------------------
   Four things: the moderation queue actions, the appearance picker, the
   suite grid install/activate controls, and the shared media field. Every suite plugin gets all of
   it by declaring 'seedcast-core-admin' as a script dependency. */

/* ── Moderation queue ──────────────────────────────────────────────
   Queue screens live in each plugin, but they all post through core so
   statuses, notes and linking behave identically across the suite.
   Markup contract: [data-sc-moderate="<status>"][data-id="<id>"] inside
   a [data-submission-row], with optional [data-sc-notes]. */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '[data-sc-moderate]', function ( e ) {
		e.preventDefault();
		var $btn   = $( this );
		var id     = $btn.data( 'id' );
		var status = $btn.attr( 'data-sc-moderate' );
		var $row   = $btn.closest( '[data-submission-row]' );
		var notes  = $row.find( '[data-sc-notes]' ).val() || '';

		$btn.prop( 'disabled', true );

		$.post( seedcastAdmin.ajaxUrl, {
			action: 'sc_moderate',
			nonce:  seedcastAdmin.nonce,
			id:     id,
			status: status,
			notes:  notes
		} ).done( function ( res ) {
			if ( res && res.success ) {
				// The row leaves the current view; it is no longer pending.
				$row.css( 'transition', 'opacity .4s' ).css( 'opacity', '0' );
				setTimeout( function () { $row.remove(); }, 420 );
			} else {
				window.alert( ( res && res.data && res.data.message ) || seedcastAdmin.labels.failed );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			window.alert( seedcastAdmin.labels.failed );
			$btn.prop( 'disabled', false );
		} );
	} );
}( jQuery ) );

/* ── Appearance picker ─────────────────────────────────────────────
   Section navigation is server side now, so there is nothing here but
   the selected-state highlight on the theme swatches. */
( function ( $ ) {
	'use strict';

	$( document ).on( 'change', '.sc-appearance-radio', function () {
		$( '.sc-appearance-option' ).removeClass( 'is-selected' );
		$( this ).closest( '.sc-appearance-option' ).addClass( 'is-selected' );
	} );
}( jQuery ) );

/* ── Contacts: load an existing record into the add form ─────────── */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.sc-contact-edit', function ( e ) {
		e.preventDefault();
		var $btn  = $( this );
		var $form = $( '.sc-contact-form' );
		if ( ! $form.length ) return;

		$form.find( '[name="name"]' ).val( $btn.data( 'name' ) );
		$form.find( '[name="email"]' ).val( $btn.data( 'email' ) );
		$form.find( '[name="phone"]' ).val( $btn.data( 'phone' ) );
		$form.find( '[name="original_name"]' ).val( $btn.data( 'name' ) );

		var $submit = $form.find( 'button[type="submit"]' );
		$submit.text( $submit.data( 'update-label' ) );
		$form[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
	} );

	$( document ).on( 'click', '.sc-contact-delete', function ( e ) {
		if ( ! window.confirm( 'Delete this contact from the lookup list?' ) ) {
			e.preventDefault();
		}
	} );
}( jQuery ) );

/* ── Suite grid ───────────────────────────────────────────────────
   Two actions, matching the two card states. Activate flips an already
   installed plugin. Install pulls a free suite plugin from the
   WordPress.org repository through the standard installer and then
   activates it. Pro cards have no action here at all; they are a link,
   because a plugin distributed through the repository may not install
   code hosted anywhere else. */
( function ( $ ) {
	'use strict';

	function run( $btn, action ) {
		var $tile = $btn.closest( '.sc-tile' );
		var $grid = $tile.closest( '.sc-suite-grid' );
		var busy  = action === 'sc_suite_install' ? 'Installing…' : 'Activating…';

		$btn.prop( 'disabled', true ).text( busy );

		$.post( seedcastAdmin.ajaxUrl, {
			action: action,
			nonce:  $grid.data( 'nonce' ),
			slug:   $tile.data( 'slug' ),
			file:   $tile.data( 'file' ) || ''
		} ).done( function ( res ) {
			if ( res && res.success ) {
				// Both actions change what is in the admin menu, so a reload
				// is the honest way to show the result.
				window.location.reload();
			} else {
				window.alert( ( res && res.data && res.data.message ) || seedcastAdmin.labels.failed );
				$btn.prop( 'disabled', false ).text( busy.replace( 'ing…', 'e' ) );
			}
		} ).fail( function () {
			window.alert( seedcastAdmin.labels.failed );
			$btn.prop( 'disabled', false );
		} );
	}

	$( document ).on( 'click', '.sc-tile__activate', function () {
		run( $( this ), 'sc_suite_activate' );
	} );

	$( document ).on( 'click', '.sc-tile__install', function () {
		run( $( this ), 'sc_suite_install' );
	} );
}( jQuery ) );

/* ── Shared media field ───────────────────────────────────────────
   Powers Seedcast\Core\Admin\MediaField output (.sc-media-field), so any
   suite plugin gets Upload/Select, Clear and an inline preview without
   shipping uploader JS of its own. The rendering screen is responsible
   for calling wp_enqueue_media(). The stored value is always the URL. */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.sc-media-select', function ( e ) {
		e.preventDefault();
		var $btn   = $( this );
		var target = $btn.data( 'target' );
		var title  = $btn.data( 'title' )  || 'Select Media';
		var type   = $btn.data( 'type' )   || '';
		var button = $btn.data( 'button' ) || 'Use this file';
		var $input = $( '#' + target );
		var $field = $input.closest( '.sc-media-field' );

		var frame = wp.media( {
			title:    title,
			button:   { text: button },
			library:  type ? { type: type } : {},
			multiple: false
		} );

		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = att.url;
			$input.val( url ).trigger( 'change' );
			$field.find( '.sc-media-clear' ).prop( 'hidden', false );

			$field.nextAll( '.sc-media-preview' ).remove();
			if ( att.type === 'audio' ) {
				$( '<audio controls preload="none" class="sc-media-preview">' ).attr( 'src', url ).insertAfter( $field );
			} else if ( att.type === 'video' ) {
				$( '<video controls class="sc-media-preview sc-media-preview--video">' ).attr( 'src', url ).insertAfter( $field );
			}
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.sc-media-clear', function ( e ) {
		e.preventDefault();
		var target = $( this ).data( 'target' );
		var $field = $( this ).closest( '.sc-media-field' );
		$( '#' + target ).val( '' ).trigger( 'change' );
		$field.nextAll( '.sc-media-preview' ).remove();
		$( this ).prop( 'hidden', true );
	} );
}( jQuery ) );

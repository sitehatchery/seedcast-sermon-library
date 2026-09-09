/* SermonLibrary Admin JS */
( function( $ ) {
    'use strict';

    // ── Tab System ────────────────────────────
    $( document ).on( 'click', '.scsl-tab', function() {
        var $btn   = $( this );
        var target = $btn.data( 'target' );
        var $wrap  = $btn.closest( '.scsl-tab-wrap' );
        // Save TinyMCE content to textarea before switching panels
        if ( typeof tinymce !== 'undefined' ) { tinymce.triggerSave(); }
        $wrap.find( '.scsl-tab' ).removeClass( 'active' );
        $wrap.find( '.sc-tab-panel' ).removeClass( 'active' );
        $btn.addClass( 'active' );
        $( '#' + target ).addClass( 'active' );
    } );

    // ── AI Generate All ───────────────────────
    $( document ).on( 'click', '.scsl-generate-all', function() {
        var $btn   = $( this );
        var postId = $btn.data( 'post-id' );
        if ( ! postId ) return;
        $btn.prop( 'disabled', true ).text( scslAdmin.generating || 'Generating…' );
        $.post( scslAdmin.ajaxUrl, {
            action:  'scsl_generate_assets',
            nonce:   scslAdmin.nonce,
            post_id: postId,
        } )
        .done( function( res ) {
            if ( res.success ) {
                $btn.text( '✓ Done: reload to see results' );
                setTimeout( function() { location.reload(); }, 1500 );
            } else {
                $btn.prop( 'disabled', false ).text( 'Generate All with AI' );
                alert( res.data.message || 'Generation failed.' );
            }
        } )
        .fail( function() {
            $btn.prop( 'disabled', false ).text( 'Generate All with AI' );
            alert( 'Request failed. Check your connection.' );
        } );
    } );

    // ── Passage Picker ────────────────────────
    // Build formatted reference from selects/inputs and update hidden field + preview
    function updatePassagePicker( $picker ) {
        var book    = $picker.find( '.scsl-pp-book' ).val();
        var chapter = $picker.find( '.scsl-pp-chapter' ).val();
        var vStart  = $picker.find( '.scsl-pp-verse-start' ).val();
        var vEnd    = $picker.find( '.scsl-pp-verse-end' ).val();
        var cEnd    = $picker.find( '.scsl-pp-chapter-end' ).val();

        var ref = '';
        if ( book ) {
            ref = book;
            if ( chapter ) {
                ref += ' ' + chapter;
                if ( vStart ) {
                    ref += ':' + vStart;

                    /*
                     * A passage that runs into the next chapter needs both
                     * numbers on the far side. Written as a bare verse it
                     * would read as ending in the chapter it started in,
                     * which is a different passage.
                     */
                    if ( cEnd && cEnd !== chapter ) {
                        ref += '-' + cEnd + ':' + ( vEnd || '1' );
                    } else if ( vEnd && vEnd !== vStart ) {
                        ref += '-' + vEnd;
                    }
                }
            }
        }

        $picker.find( '.scsl-pp-value' ).val( ref );
        $picker.find( '.scsl-pp-preview' ).text( ref || '-' );
    }

    /*
     * Reveal the end chapter when it is asked for.
     *
     * The toggle goes away once it has done its job: leaving it beside a
     * filled field would offer to add something already there.
     */
    $( document ).on( 'click', '.scsl-pp-more', function() {
        var $picker = $( this ).closest( '.scsl-passage-picker' );

        $picker.find( '.scsl-pp-chapter-end, .scsl-pp-colon-end' ).prop( 'hidden', false );
        $( this ).prop( 'hidden', true );
        $picker.find( '.scsl-pp-chapter-end' ).trigger( 'focus' );
    } );


    $( document ).on( 'change input', '.scsl-passage-picker select, .scsl-passage-picker input[type="number"]', function() {
        updatePassagePicker( $( this ).closest( '.scsl-passage-picker' ) );
    } );

    // ── Add Passage Row ───────────────────────
    $( document ).on( 'click', '.scsl-add-passage', function() {
        var booksJson = $( '#scsl-books-json' ).val();
        var books = [];
        try { books = JSON.parse( booksJson ); } catch(e) {}

        var $select = $( '<select class="scsl-pp-book" aria-label="Book">' );
        $select.append( $( '<option value="">' ).text( 'Book' ) );
        $.each( books, function( i, b ) {
            $select.append( $( '<option>' ).val( b ).text( b ) );
        } );

        var $picker = $( '<div class="scsl-passage-picker">' )
            .append( $select )
            .append( '<input type="number" class="scsl-pp-chapter" min="1" max="150" placeholder="Ch" aria-label="Chapter" style="width:60px;" />' )
            .append( '<span class="scsl-pp-colon">:</span>' )
            .append( '<input type="number" class="scsl-pp-verse-start" min="1" max="176" placeholder="v" aria-label="Start verse" style="width:55px;" />' )
            .append( '<span class="scsl-pp-dash">-</span>' )
            .append( '<button type="button" class="button-link scsl-pp-more" aria-label="Add an end chapter, for a passage that crosses one">Ch &raquo;</button>' )
            .append( '<input type="number" class="scsl-pp-chapter-end" min="1" max="150" hidden placeholder="Ch" aria-label="End chapter" style="width:60px;" />' )
            .append( '<span class="scsl-pp-colon-end" hidden>:</span>' )
            .append( '<input type="number" class="scsl-pp-verse-end" min="1" max="176" placeholder="v" aria-label="End verse (optional)" style="width:55px;" />' )
            .append( '<span class="scsl-pp-preview">-</span>' )
            .append( '<input type="hidden" class="scsl-pp-value" name="scsl_other_passages[]" value="" />' );

        var $row = $( '<div class="scsl-passage-row sf-passage-row--extra">' )
            .append( $picker )
            .append( '<button type="button" class="button scsl-remove-passage">Remove</button>' );

        $( '#scsl-passages-wrap' ).append( $row );
        $row.find( '.scsl-pp-book' ).trigger( 'focus' );
    } );

    $( document ).on( 'click', '.scsl-remove-passage', function() {
        $( this ).closest( '.scsl-passage-row' ).remove();
    } );

    // ── External Platform Links ───────────────
    var platformLabels = {
        'spotify'    : 'Listen on Spotify',
        'apple'      : 'Listen on Apple Podcasts',
        'youtube'    : 'Watch on YouTube',
        'vimeo'      : 'Watch on Vimeo',
        'sermon'     : 'Listen on Sermon Audio',
        'soundcloud' : 'Listen on SoundCloud',
        'other'      : '',
    };

    // Always update label when platform changes (not just when empty)
    $( document ).on( 'change', '#scsl-ext-links-wrap select', function() {
        var $row   = $( this ).closest( '.scsl-ext-link-row' );
        var $label = $row.find( 'input[type="text"]' );
        var val    = $( this ).val();
        if ( platformLabels[ val ] !== undefined ) {
            $label.val( platformLabels[ val ] );
        }
    } );

    $( document ).on( 'click', '.scsl-add-ext-link', function() {
        var platforms = $( this ).data( 'platforms' );
        if ( ! platforms ) return;
        var idx = $( '#scsl-ext-links-wrap .scsl-ext-link-row' ).length;

        var $select = $( '<select>' ).attr( 'name', 'scsl_ext_links[' + idx + '][platform]' );
        $.each( platforms, function( val, label ) {
            $select.append( $( '<option>' ).val( val ).text( label ) );
        } );

        var $row = $( '<div class="scsl-ext-link-row">' )
            .append( $select )
            .append(
                $( '<input type="text">' )
                    .attr( 'name', 'scsl_ext_links[' + idx + '][label]' )
                    .attr( 'placeholder', 'Button label' )
                    .addClass( 'regular-text' )
            )
            .append(
                $( '<input type="url">' )
                    .attr( 'name', 'scsl_ext_links[' + idx + '][url]' )
                    .attr( 'placeholder', 'https://...' )
                    .addClass( 'regular-text' )
            )
            .append( '<button type="button" class="button scsl-remove-ext-link">Remove</button>' );

        $( '#scsl-ext-links-wrap' ).append( $row );
        // Auto-fill label for default platform
        var defaultPlatform = $select.val();
        if ( platformLabels[ defaultPlatform ] !== undefined ) {
            $row.find( 'input[type="text"]' ).val( platformLabels[ defaultPlatform ] );
        }
    } );

    $( document ).on( 'click', '.scsl-remove-ext-link', function() {
        $( this ).closest( '.scsl-ext-link-row' ).remove();
    } );

    // ── Media Upload Buttons ──────────────────
    $( document ).on( 'click', '.scsl-upload-media', function( e ) {
        e.preventDefault();
        var $btn    = $( this );
        var target  = $btn.data( 'target' );
        var title   = $btn.data( 'title' )  || 'Select Media';
        var type    = $btn.data( 'type' )   || '';   // 'video', 'audio', or ''
        var button  = $btn.data( 'button' ) || 'Use this file';
        var $input  = $( '#' + target );

        var frame = wp.media( {
            title:    title,
            button:   { text: button },
            library:  type ? { type: type } : {},
            multiple: false,
        } );

        frame.on( 'select', function() {
            var attachment = frame.state().get( 'selection' ).first().toJSON();
            var url = attachment.url;
            $input.val( url ).trigger( 'change' );

            // Show/hide clear button
            $btn.siblings( '.scsl-clear-media' ).remove();
            $btn.after(
                $( '<button type="button" class="button sf-clear-media">' )
                    .attr( 'data-target', target )
                    .text( 'Clear' )
            );

            // Inline preview
            $input.closest( 'td' ).find( 'audio, video' ).remove();
            if ( attachment.type === 'audio' ) {
                $input.closest( 'td' ).append(
                    $( '<audio controls preload="none" style="max-width:100%;margin-top:.5rem;">' ).attr( 'src', url )
                );
            } else if ( attachment.type === 'video' ) {
                $input.closest( 'td' ).append(
                    $( '<video controls style="max-width:100%;max-height:120px;margin-top:.5rem;border-radius:4px;">' ).attr( 'src', url )
                );
            }
        } );

        frame.open();
    } );

    $( document ).on( 'click', '.scsl-clear-media', function() {
        var target = $( this ).data( 'target' );
        $( '#' + target ).val( '' ).trigger( 'change' );
        $( this ).closest( 'td' ).find( 'audio, video' ).remove();
        $( this ).remove();
    } );
    $( document ).on( 'change', '.scsl-theme-radio', function() {
        $( '.scsl-theme-option' ).removeClass( 'is-selected' );
        $( this ).closest( '.scsl-theme-option' ).addClass( 'is-selected' );
    } );

    // ── Questions ──────────────────────────────────────────

    function slFaqRow( idx ) {
        return '<div class="scsl-faq-row" style="margin-bottom:10px;padding:10px 12px;background:#f9f9f9;border:1px solid #dcdcde;border-radius:4px;">'
            + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">'
            + '<span class="scsl-faq-number" style="font-weight:600;color:#646970;"></span>'
            + '<input type="text" name="scsl_faq[' + idx + '][question]" placeholder="Question, e.g. What does it mean to stand firm?" class="regular-text" style="flex:1;font-weight:600;" />'
            + '<button type="button" class="button-link scsl-remove-faq" style="color:#b32d2e;">✕ Remove</button>'
            + '</div>'
            + '<textarea name="scsl_faq[' + idx + '][answer]" rows="3" class="large-text" placeholder="Answer"></textarea>'
            + '</div>';
    }

    // The numbers are what a visitor sees on the sermon page, so they should
    // read the same here after a row is added or taken out.
    function slFaqRenumber() {
        $( '#scsl-faq-rows .scsl-faq-row' ).each( function( i ) {
            $( this ).find( '.scsl-faq-number' ).text( ( i + 1 ) + '.' );
        } );
    }

    $( document ).on( 'click', '.scsl-add-faq', function() {
        // Indexes only have to be unique: save() reindexes what it keeps.
        var idx = $( '#scsl-faq-rows .scsl-faq-row' ).length;
        $( '#scsl-faq-rows' ).append( slFaqRow( idx ) );
        slFaqRenumber();
        $( '#scsl-faq-rows .scsl-faq-row' ).last().find( 'input[type=text]' ).focus();
    } );

    $( document ).on( 'click', '.scsl-remove-faq', function() {
        $( this ).closest( '.scsl-faq-row' ).remove();
        slFaqRenumber();
    } );

    // ── Sermon Notes Attachments ──────────────────────────────────────────────

    function slNotesRow( idx ) {
        return '<div class="scsl-notes-file-row" style="margin-bottom:10px;padding:10px 12px;background:#f9f9f9;border:1px solid #dcdcde;border-radius:4px;">'
            + '<div style="margin-bottom:6px;"><input type="text" name="scsl_notes_files[' + idx + '][label]" placeholder="Label, e.g. Sermon Notes" class="regular-text" style="width:100%;max-width:400px;" /></div>'
            + '<div style="display:flex;align-items:center;gap:16px;margin-bottom:8px;">'
            + '<label style="cursor:pointer;font-weight:normal;"><input type="radio" name="scsl_notes_files[' + idx + '][type]" value="upload" class="scsl-notes-type" checked /> Upload File</label>'
            + '<label style="cursor:pointer;font-weight:normal;"><input type="radio" name="scsl_notes_files[' + idx + '][type]" value="url" class="scsl-notes-type" /> URL / Link</label>'
            + '<button type="button" class="button-link scsl-remove-notes-file" style="color:#b32d2e;margin-left:auto;">✕ Remove</button>'
            + '</div>'
            + '<input type="hidden" name="scsl_notes_files[' + idx + '][url]" value="" class="scsl-notes-url-value" />'
            + '<div class="scsl-notes-upload-area"><button type="button" class="button scsl-upload-notes-file">↑ Choose File</button></div>'
            + '<div class="scsl-notes-url-area" style="display:none;"><input type="url" class="regular-text scsl-notes-url-input" placeholder="https://..." style="width:100%;max-width:400px;" /></div>'
            + '</div>';
    }

    $( document ).on( 'click', '.scsl-add-notes-file', function() {
        var idx = $( '#scsl-notes-files-wrap .scsl-notes-file-row' ).length;
        $( '#scsl-notes-files-wrap' ).append( slNotesRow( idx ) );
    } );

    $( document ).on( 'click', '.scsl-remove-notes-file', function() {
        $( this ).closest( '.scsl-notes-file-row' ).remove();
    } );

    $( document ).on( 'change', '.scsl-notes-type', function() {
        var $row    = $( this ).closest( '.scsl-notes-file-row' );
        var type    = $row.find( '.scsl-notes-type:checked' ).val();
        var $upload = $row.find( '.scsl-notes-upload-area' );
        var $url    = $row.find( '.scsl-notes-url-area' );
        var $value  = $row.find( '.scsl-notes-url-value' );
        if ( type === 'upload' ) {
            $url.hide();
            $upload.show();
        } else {
            $upload.hide();
            $url.show();
            // Pre-fill URL input if we have a stored value
            var existing = $value.val();
            if ( existing && ! $url.find( '.scsl-notes-url-input' ).val() ) {
                $url.find( '.scsl-notes-url-input' ).val( existing );
            }
        }
    } );

    $( document ).on( 'input', '.scsl-notes-url-input', function() {
        $( this ).closest( '.scsl-notes-file-row' ).find( '.scsl-notes-url-value' ).val( $( this ).val() );
    } );

    $( document ).on( 'click', '.scsl-upload-notes-file', function() {
        var $row   = $( this ).closest( '.scsl-notes-file-row' );
        var $value = $row.find( '.scsl-notes-url-value' );
        var $label = $row.find( 'input[type="text"]' );
        var frame  = wp.media( { title: 'Select File', button: { text: 'Use this file' }, multiple: false, library: { type: '' } } );
        frame.off( 'select' ).on( 'select', function() {
            var att = frame.state().get( 'selection' ).first().toJSON();
            $value.val( att.url );
            if ( ! $label.val() ) $label.val( att.filename || att.title || '' );
            $row.find( '.scsl-notes-file-link' ).remove();
            $row.find( '.scsl-notes-upload-area' )
                .prepend( $( '<a class="scsl-notes-file-link" target="_blank" style="font-size:12px;display:block;margin-bottom:4px;color:#2271b1;">' ).attr( 'href', att.url ).text( att.filename || att.title || att.url ) );
        } );
        frame.open();
    } );

        // ── Podcast Details: image uploader ─────────────────────────────────
    $( document ).on( 'click', '.scsl-upload-podcast-image', function( e ) {
        e.preventDefault();
        var $field = $( '#scsl_podcast_image' );
        var frame  = wp.media( {
            title:   'Select Podcast Image',
            button:  { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        } );
        frame.on( 'select', function() {
            var att = frame.state().get( 'selection' ).first().toJSON();
            $field.val( att.url );
            $( '.scsl-upload-podcast-image' ).closest( 'td' ).find( 'img' ).remove();
            $( '.scsl-upload-podcast-image' ).closest( 'td' )
                .append( $( '<img>' ).attr( 'src', att.url ).css( { maxWidth: '120px', marginTop: '8px', borderRadius: '6px', display: 'block' } ) );
        } );
        frame.open();
    } );

} )( jQuery );

    // ── Speaker headshot media picker ──────────────────────────────────────────
    ( function( $ ) {
        var frame;
        var i18n = window.scslSpeakerI18n || {};
        $( '#scsl_speaker_photo_btn' ).on( 'click', function( e ) {
            e.preventDefault();
            if ( frame ) { frame.open(); return; }
            frame = wp.media( {
                title:    i18n.selectHeadshot || 'Select Headshot',
                button:   { text: i18n.useThisPhoto || 'Use this photo' },
                multiple: false,
            } );
            frame.on( 'select', function() {
                var a = frame.state().get( 'selection' ).first().toJSON();
                $( '#scsl_speaker_photo_id' ).val( a.id );
                $( '#scsl_speaker_photo_preview' ).attr( 'src', a.url ).show();
                $( '#scsl_speaker_photo_btn' ).text( i18n.changeHeadshot || 'Change Headshot' );
            } );
            frame.open();
        } );
        $( document ).on( 'click', '.scsl-remove-img', function() {
            var $btn = $( this );
            $( '#' + $btn.data( 'target' ) ).val( '' );
            $( '#' + $btn.data( 'preview' ) ).hide().attr( 'src', '' );
            $btn.hide();
        } );
    } )( jQuery );

/* Image pickers on the settings screen. Stores the attachment ID rather than
   the URL, so images survive a site URL change and the feed can ask for a full
   size version. Drives both the podcast cover art and the default sermon
   image. */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.scsl-artwork-select, .scsl-image-select', function ( e ) {
		e.preventDefault();
		var $field  = $( this ).closest( '.sc-media-field' );
		var target  = $( this ).data( 'target' ) || 'scsl_podcast_artwork';
		var title   = $( this ).data( 'title' )  || 'Select image';

		// A fresh frame per click. Reusing one meant the second field opened
		// with the first field's selection still bound to it.
		var frame = wp.media( {
				title: 'Podcast cover art',
				button: { text: 'Use this image' },
				library: { type: 'image' },
				multiple: false
		} );

		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			$( '#' + target ).val( att.id );
			$field.find( '.scsl-artwork-clear, .scsl-image-clear' ).prop( 'hidden', false );

			var url = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
			var $preview = $field.nextAll( '.scsl-artwork-preview, .scsl-image-preview' ).first();
			if ( $preview.length ) {
				$preview.attr( 'src', url );
			} else {
				$( '<img class="scsl-image-preview" alt="" style="max-width:180px;margin-top:.75rem;display:block;">' )
					.attr( 'src', url )
					.insertAfter( $field );
			}
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.scsl-artwork-clear, .scsl-image-clear', function ( e ) {
		e.preventDefault();
		var $field = $( this ).closest( '.sc-media-field' );
		var target = $( this ).data( 'target' ) || 'scsl_podcast_artwork';
		$( '#' + target ).val( '' );
		$field.nextAll( '.scsl-artwork-preview, .scsl-image-preview' ).remove();
		$( this ).prop( 'hidden', true );
	} );
}( jQuery ) );

/* Sermon Manager import: scan, review, run.

   Scanning is read-only and safe to repeat. The review step exists because the
   source plugin creates a new taxonomy term every time a name is typed
   slightly differently, so a site with a few years of sermons usually has
   several spellings of the same preacher. Merging them here means the church
   gets a tidy library instead of four archive pages for one person. */
( function ( $ ) {
	'use strict';

	var merges = {};

	function esc( s ) {
		return $( '<div>' ).text( s == null ? '' : s ).html();
	}

	function renderGroup( label, report ) {
		if ( ! report || ! report.count ) {
			return '';
		}

		var html = '<p style="margin:.35rem 0;"><strong>' + esc( report.count ) + '</strong> ' + esc( label ) + '</p>';

		if ( ! report.duplicates.length ) {
			return html;
		}

		html += '<div style="margin:.5rem 0 1rem;padding:.75rem;background:#fcf9e8;border:1px solid #dba617;border-radius:4px;">';
		html += '<p style="margin:0 0 .5rem;"><strong>' + esc( report.duplicates.length ) +
			'</strong> look like the same ' + esc( label.replace( /s$/, '' ) ) +
			' entered more than once. Choose which name to keep, or leave them separate.</p>';

		report.duplicates.forEach( function ( group ) {
			var keep = group[ 0 ].id;
			html += '<div style="margin:.4rem 0;">';
			group.forEach( function ( t, i ) {
				html += '<label style="margin-right:1rem;">' +
					'<input type="radio" name="scsl-merge-' + keep + '" value="' + t.id + '"' +
					( i === 0 ? ' checked' : '' ) +
					' data-group="' + group.map( function ( g ) { return g.id; } ).join( ',' ) + '"> ' +
					esc( t.name ) + ' <span style="color:#646970;">(' + esc( t.count ) + ')</span>' +
					( t.description ? ' <span style="color:#646970;" title="' + esc( t.description ) + '">&#9432;</span>' : '' ) +
					'</label>';
			} );
			html += '<label style="color:#646970;"><input type="radio" name="scsl-merge-' + keep + '" value="0"> keep separate</label>';
			html += '</div>';

			group.forEach( function ( t ) { merges[ t.id ] = group[ 0 ].id; } );
		} );

		html += '</div>';
		return html;
	}

	$( document ).on( 'change', '[name^="scsl-merge-"]', function () {
		var ids  = ( $( this ).data( 'group' ) || '' ).toString().split( ',' );
		var keep = parseInt( $( this ).val(), 10 );

		ids.forEach( function ( id ) {
			id = parseInt( id, 10 );
			if ( ! id ) return;
			// Zero means leave them alone, so each term keeps its own identity.
			merges[ id ] = keep ? keep : id;
		} );
	} );

	$( document ).on( 'click', '#scsl-wpfc-scan', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( 'Scanning…' );
		merges = {};

		$.post( scslAdmin.ajaxUrl, { action: 'scsl_wpfc_scan', nonce: scslAdmin.nonce } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || 'Scan failed.' );
					return;
				}
				var d = res.data;
				var html = '<h3 style="margin-top:0;">Found on this site</h3>';
				html += '<p style="margin:.35rem 0;"><strong>' + esc( d.sermons ) + '</strong> sermons';
				if ( d.already ) {
					html += ' <span style="color:#646970;">(' + esc( d.already ) + ' already imported, these will be updated rather than duplicated)</span>';
				}
				html += '</p>';

				html += renderGroup( 'preachers', d.preachers );
				html += renderGroup( 'series', d.series );
				html += renderGroup( 'topics', d.topics );
				html += renderGroup( 'books', d.books );
				html += renderGroup( 'service types', d.services );

                if ( d.unparsable_total ) {
					html += '<div style="margin:.75rem 0;padding:.75rem;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;">';
					html += '<p style="margin:0 0 .5rem;"><strong>' + esc( d.unparsable_total ) +
						'</strong> scripture references could not be read as a book, chapter and verse. They will be imported as written, and you can tidy them afterwards.</p><ul style="margin:0;">';
					d.unparsable.forEach( function ( u ) {
						html += '<li>' + esc( u.title ) + ': <code>' + esc( u.passage ) + '</code></li>';
					} );
					html += '</ul></div>';
				}

				if ( d.bulletins ) {
					html += '<p style="margin:.35rem 0;color:#646970;"><strong>' + esc( d.bulletins ) +
						'</strong> sermons have a bulletin attached. These come across as downloadable files on the sermon, alongside any notes.</p>';
				}

				$( '#scsl-wpfc-report' ).html( html ).show();
				$( '#scsl-wpfc-run-wrap' ).show();
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( 'Scan this site' );
			} );
	} );

	$( document ).on( 'click', '#scsl-wpfc-run', function () {
		var $btn = $( this ).prop( 'disabled', true );
		var $log = $( '#scsl-wpfc-log' ).show().empty();
		var totals = { imported: 0, updated: 0, skipped: 0 };

		function batch( offset ) {
			$.post( scslAdmin.ajaxUrl, {
				action: 'scsl_wpfc_import',
				nonce:  scslAdmin.nonce,
				offset: offset,
				status: $( 'input[name="scsl-wpfc-status"]:checked' ).val() || 'keep',
				merges: JSON.stringify( merges )
			} ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					$( '#scsl-wpfc-progress' ).text( 'Import failed.' );
					$btn.prop( 'disabled', false );
					return;
				}

				var d = res.data;
				totals.imported += d.results.imported;
				totals.updated  += d.results.updated;
				totals.skipped  += d.results.skipped;

				d.results.log.forEach( function ( line ) {
					$log.append( $( '<div>' ).text( line ) );
				} );
				$log.scrollTop( $log[ 0 ].scrollHeight );

				$( '#scsl-wpfc-progress' ).text(
					Math.min( d.offset, d.total ) + ' of ' + d.total + ' processed'
				);

				if ( ! d.complete ) {
					batch( d.offset );
					return;
				}

				$( '#scsl-wpfc-progress' ).text(
					'Done. ' + totals.imported + ' imported, ' + totals.updated +
					' updated, ' + totals.skipped + ' skipped.'
				);
				$btn.prop( 'disabled', false );
			} ).fail( function () {
				$( '#scsl-wpfc-progress' ).text( 'Import failed.' );
				$btn.prop( 'disabled', false );
			} );
		}

		$( '#scsl-wpfc-progress' ).text( 'Starting…' );
		batch( 0 );
	} );
}( jQuery ) );

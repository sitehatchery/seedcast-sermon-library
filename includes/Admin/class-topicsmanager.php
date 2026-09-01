<?php
namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Topics Manager: a clean admin UI for sf_topic taxonomy terms.
 * Shows each topic with sermon count linked to filtered sermon list.
 */
class TopicsManager {

	public function init(): void {
		add_action( 'admin_init', [ $this, 'maybe_delete' ] );
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Topics', 'seedcast-sermon-library' ),
			__( 'Topics',  'seedcast-sermon-library' ),
			'manage_options',
			'seedcast-sermon-library-topics',
			[ $this, 'render' ]
		);
	}

	/**
	 * Delete a topic, before anything has been printed.
	 *
	 * Runs on admin_init rather than inside render(). By render() time the page
	 * head and menu are already on the wire, so the redirect below cannot set a
	 * Location header and the exit simply truncates the page, leaving an empty
	 * content area.
	 */
	public function maybe_delete(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;

		// Read only, to decide whether this request is ours. The nonce is
		// verified immediately below, before anything is deleted.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action  = isset( $_GET['scsl_action'] ) ? sanitize_key( wp_unslash( $_GET['scsl_action'] ) ) : '';
		$term_id = isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'seedcast-sermon-library-topics' !== $page || 'delete' !== $action || ! $term_id ) {
			return;
		}

		check_admin_referer( 'scsl_delete_topic_' . $term_id );

		wp_delete_term( $term_id, 'scsl_topic' );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'seedcast-sermon-library-topics', 'deleted' => 1 ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		// Deletion is handled on admin_init; only the edit form is read here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_GET['scsl_action'] ) ? sanitize_key( wp_unslash( $_GET['scsl_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$term_id = isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0;

		$topics = get_terms( [
			'taxonomy'   => 'scsl_topic',
			'hide_empty' => false,
			'orderby'    => 'count',
			'order'      => 'DESC',
		] );

		$edit_term = null;
		if ( $term_id && in_array( $action, [ 'edit' ], true ) ) {
			$edit_term = get_term( $term_id, 'scsl_topic' );
		}

		// A flag on the redirect, only used to print a message. The action it
		// reports on was nonce checked before it ran.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Topic deleted.', 'seedcast-sermon-library' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Topics', 'seedcast-sermon-library' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Manage sermon topics. Topics are used to filter sermons on the front end.', 'seedcast-sermon-library' ); ?></p>

			<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;margin-top:1rem;">

				<!-- Topic List -->
				<div>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width:40%"><?php esc_html_e( 'Topic Name', 'seedcast-sermon-library' ); ?></th>
								<th style="width:20%"><?php esc_html_e( 'Slug', 'seedcast-sermon-library' ); ?></th>
								<th style="width:20%;text-align:center"><?php esc_html_e( 'Sermons', 'seedcast-sermon-library' ); ?></th>
								<th style="width:20%"><?php esc_html_e( 'Actions', 'seedcast-sermon-library' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( is_wp_error( $topics ) || empty( $topics ) ) : ?>
							<tr><td colspan="4" style="text-align:center;color:#646970;padding:2rem;">
								<?php esc_html_e( 'No topics yet. Add one using the form.', 'seedcast-sermon-library' ); ?>
							</td></tr>
						<?php else : ?>
							<?php foreach ( $topics as $topic ) :
								$sermon_filter_url = add_query_arg( [
									'post_type'       => 'scsl_sermon',
									'scsl_topic_filter' => $topic->term_id,
								], admin_url( 'edit.php' ) );
								$edit_url   = add_query_arg( [ 'page' => 'seedcast-sermon-library-topics', 'scsl_action' => 'edit',   'term_id' => $topic->term_id ], admin_url( 'admin.php' ) );
								$delete_url = wp_nonce_url( add_query_arg( [ 'page' => 'seedcast-sermon-library-topics', 'scsl_action' => 'delete', 'term_id' => $topic->term_id ], admin_url( 'admin.php' ) ), 'scsl_delete_topic_' . $topic->term_id );
							?>
							<tr>
								<td>
									<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $topic->name ); ?></a></strong>
									<?php if ( $topic->description ) : ?>
										<p style="margin:.2rem 0 0;font-size:12px;color:#646970;"><?php echo esc_html( wp_trim_words( $topic->description, 10 ) ); ?></p>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $topic->slug ); ?></code></td>
								<td style="text-align:center;">
									<?php if ( $topic->count > 0 ) : ?>
										<a href="<?php echo esc_url( $sermon_filter_url ); ?>" style="font-weight:700;">
											<?php echo esc_html( $topic->count ); ?>
										</a>
									<?php else : ?>
										<span style="color:#646970;">0</span>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'seedcast-sermon-library' ); ?></a>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $delete_url ); ?>"
									   style="color:#d63638;"
									   onclick="return confirm('<?php esc_attr_e( 'Delete this topic? Sermons using it will be untagged.', 'seedcast-sermon-library' ); ?>')">
										<?php esc_html_e( 'Delete', 'seedcast-sermon-library' ); ?>
									</a>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Add / Edit Form -->
				<div>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:1.25rem;">
						<?php
						$is_edit   = ( $edit_term && ! is_wp_error( $edit_term ) );
						$form_name = $is_edit ? $edit_term->name        : '';
						$form_slug = $is_edit ? $edit_term->slug        : '';
						$form_desc = $is_edit ? $edit_term->description : '';
						?>
						<h3 style="margin-top:0;">
							<?php echo $is_edit ? esc_html__( 'Edit Topic', 'seedcast-sermon-library' ) : esc_html__( 'Add New Topic', 'seedcast-sermon-library' ); ?>
						</h3>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'scsl_save_topic', 'scsl_topic_nonce' ); ?>
							<input type="hidden" name="action" value="scsl_save_topic" />
							<?php if ( $is_edit ) : ?>
								<input type="hidden" name="term_id" value="<?php echo esc_attr( $edit_term->term_id ); ?>" />
							<?php endif; ?>

							<p>
								<label style="font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e( 'Name', 'seedcast-sermon-library' ); ?></label>
								<input type="text" name="topic_name" value="<?php echo esc_attr( $form_name ); ?>"
									   class="widefat" required placeholder="<?php esc_attr_e( 'e.g. Foundations of Faith', 'seedcast-sermon-library' ); ?>" />
							</p>
							<p>
								<label style="font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e( 'Slug', 'seedcast-sermon-library' ); ?> <span style="font-weight:400;color:#646970;"><?php esc_html_e( '(optional)', 'seedcast-sermon-library' ); ?></span></label>
								<input type="text" name="topic_slug" value="<?php echo esc_attr( $form_slug ); ?>"
									   class="widefat" placeholder="<?php esc_attr_e( 'auto-generated if blank', 'seedcast-sermon-library' ); ?>" />
							</p>
							<p>
								<label style="font-weight:600;display:block;margin-bottom:4px;"><?php esc_html_e( 'Description', 'seedcast-sermon-library' ); ?> <span style="font-weight:400;color:#646970;"><?php esc_html_e( '(optional)', 'seedcast-sermon-library' ); ?></span></label>
								<textarea name="topic_description" class="widefat" rows="3"><?php echo esc_textarea( $form_desc ); ?></textarea>
							</p>

							<button type="submit" class="button button-primary">
								<?php echo $is_edit ? esc_html__( 'Update Topic', 'seedcast-sermon-library' ) : esc_html__( 'Add Topic', 'seedcast-sermon-library' ); ?>
							</button>
							<?php if ( $is_edit ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=seedcast-sermon-library-topics' ) ); ?>" class="button" style="margin-left:.4rem;">
									<?php esc_html_e( 'Cancel', 'seedcast-sermon-library' ); ?>
								</a>
							<?php endif; ?>
						</form>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! isset( $_POST['scsl_topic_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_topic_nonce'] ) ), 'scsl_save_topic' )
		) {
			wp_die( esc_html__( 'Invalid request.', 'seedcast-sermon-library' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'seedcast-sermon-library' ) );
		}

		$term_id     = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$name        = sanitize_text_field( wp_unslash( $_POST['topic_name'] ?? '' ) );
		$slug        = sanitize_title( wp_unslash( $_POST['topic_slug'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['topic_description'] ?? '' ) );

		if ( ! $name ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'seedcast-sermon-library-topics', 'error' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$args = [ 'description' => $description ];
		if ( $slug ) $args['slug'] = $slug;

		if ( $term_id ) {
			wp_update_term( $term_id, 'scsl_topic', array_merge( $args, [ 'name' => $name ] ) );
		} else {
			wp_insert_term( $name, 'scsl_topic', $args );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'seedcast-sermon-library-topics', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}

<?php
/**
 * Plugin Name: Bulk Template Kit Importer
 * Description: Copies your Elementor designs into the Envato "Template Kit - Export" library in one click, with a serial number per template so the kit keeps the order you want.
 * Version: 1.1.1
 * Author: devtanvirul
 * Author URI: https://devtanvirul.com
 * Plugin URI: https://devtanvirul.com
 * Text Domain: bulk-template-kit-importer
 * License: GPL-2.0-or-later
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bulk_Template_Kit_Importer {

	/** Post type registered by the Envato "Template Kit - Export" plugin. */
	const TK_POST_TYPE = 'envato_tk_templates';

	/** Meta on the source post holding the ID of the kit template made from it. */
	const META_LINK = '_btki_kit_template_id';

	/** Meta on the source post remembering the serial last used for it. */
	const META_ORDER = '_btki_serial';

	const PAGE_SLUG = 'btki-kit-importer';

	public function __construct() {
		// Late, so every other plugin's menu is registered and can be found.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 99 );
		add_action( 'admin_post_btki_import', array( $this, 'handle_import' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/* ---------------------------------------------------------------
	 * Admin menu
	 * ------------------------------------------------------------- */

	public function add_menu() {
		$hook = add_submenu_page(
			$this->parent_menu_slug(),
			__( 'Bulk Import to Kit', 'bulk-template-kit-importer' ),
			__( 'Bulk Import to Kit', 'bulk-template-kit-importer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * The screenshot picker runs on the media library's own JS.
	 */
	public function on_load() {
		wp_enqueue_media();
	}

	/**
	 * Page layouts an imported template can be given.
	 *
	 * @return array<string,string>
	 */
	private function layouts() {
		return array(
			'elementor_canvas'        => __( 'Elementor Canvas (no header or footer)', 'bulk-template-kit-importer' ),
			'elementor_header_footer' => __( 'Elementor Full Width (keeps header and footer)', 'bulk-template-kit-importer' ),
			'default'                 => __( 'Theme default', 'bulk-template-kit-importer' ),
			''                        => __( 'Copy whatever the source uses', 'bulk-template-kit-importer' ),
		);
	}

	/**
	 * Where to hang this screen.
	 *
	 * Template Kit Export does not leave its post type on the usual
	 * edit.php menu, it hangs it under a menu of its own. Attaching to
	 * the wrong parent registers the page but renders no link, so the
	 * post type is asked where it actually lives and each candidate is
	 * checked against the menus that exist before it is used.
	 *
	 * @return string
	 */
	private function parent_menu_slug() {
		$post_type = get_post_type_object( self::TK_POST_TYPE );

		if ( ! $post_type ) {
			return 'tools.php';
		}

		$candidates = array();

		if ( is_string( $post_type->show_in_menu ) ) {
			$candidates[] = $post_type->show_in_menu;
		}

		$candidates[] = 'edit.php?post_type=' . self::TK_POST_TYPE;

		foreach ( $candidates as $slug ) {
			if ( $this->top_level_menu_exists( $slug ) ) {
				return $slug;
			}
		}

		// Nothing matched, so fall back somewhere the link is sure to show.
		return 'tools.php';
	}

	/**
	 * @param string $slug Menu slug to look for.
	 * @return bool
	 */
	private function top_level_menu_exists( $slug ) {
		global $menu;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && $item[2] === $slug ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------- */

	/**
	 * Everything built with Elementor that could become a kit template:
	 * saved library templates plus ordinary pages and posts. The kit's own
	 * post type is left out so the list never feeds itself.
	 *
	 * @return int[]
	 */
	private function get_source_items() {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm
				   ON pm.post_id = p.ID
				  AND pm.meta_key = '_elementor_edit_mode'
				  AND pm.meta_value = 'builder'
				 WHERE p.post_type != %s
				   AND p.post_status IN ('publish','draft','private')",
				self::TK_POST_TYPE
			)
		);

		$ids = array_map( 'intval', $ids );

		if ( empty( $ids ) ) {
			return array();
		}

		// The query above bypasses WP_Query, so nothing is cached yet and the
		// sort below would hit the database once per comparison.
		_prime_post_caches( $ids, false, true );

		// Show them in the order they will be exported in.
		usort(
			$ids,
			function ( $a, $b ) {
				$order_a = $this->sort_position( $a );
				$order_b = $this->sort_position( $b );

				if ( $order_a === $order_b ) {
					return strcasecmp( get_the_title( $a ), get_the_title( $b ) );
				}

				return $order_a <=> $order_b;
			}
		);

		return $ids;
	}

	/**
	 * Serial the user chose for a post earlier, or an empty string when
	 * they have not set one. Nothing is guessed: a number nobody typed
	 * reads as a real position and only misleads.
	 *
	 * @param int $post_id Source post.
	 * @return int|string
	 */
	private function stored_serial( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_ORDER, true );

		return '' === $stored ? '' : (int) $stored;
	}

	/**
	 * Serial to sort by. Rows without one fall to the bottom of the list.
	 *
	 * @param int $post_id Source post.
	 * @return int
	 */
	private function sort_position( $post_id ) {
		$serial = $this->stored_serial( $post_id );

		return '' === $serial ? PHP_INT_MAX : $serial;
	}

	/**
	 * Serial used when a row is imported without one.
	 */
	const UNNUMBERED = 999;

	/**
	 * Normalise a serial typed into the form.
	 *
	 * @param mixed $value Raw field value.
	 * @return int|string A position, or an empty string for "not set".
	 */
	public function clean_serial( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		return max( 1, min( 998, (int) $value ) );
	}

	/**
	 * @param string $title Post title.
	 * @return bool
	 */
	private function looks_like_global_kit( $title ) {
		return false !== strpos( mb_strtolower( $title ), 'global' );
	}

	/**
	 * Size and health of a post's Elementor data, so the screen can show
	 * whether a copy actually carried everything across.
	 *
	 * @param int $post_id Post to inspect.
	 * @return array{len:int,valid:bool,count:int}
	 */
	private function data_info( $post_id ) {
		$raw   = get_post_meta( $post_id, '_elementor_data', true );
		$len   = is_string( $raw ) ? strlen( $raw ) : 0;
		$valid = false;
		$count = 0;

		if ( $len > 0 ) {
			$decoded = json_decode( $raw, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$valid = true;
				$count = count( $decoded );
			}
		}

		return compact( 'len', 'valid', 'count' );
	}

	/**
	 * Which Elementor document type kit templates use on this site.
	 * Read it off a template that already exists rather than assuming,
	 * so the copies open the same way the ones made by hand do.
	 *
	 * @return string
	 */
	private function kit_document_type() {
		$existing = get_posts(
			array(
				'post_type'      => self::TK_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_elementor_template_type', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		if ( ! empty( $existing ) ) {
			$type = get_post_meta( $existing[0], '_elementor_template_type', true );

			if ( $type ) {
				return $type;
			}
		}

		return 'wp-post';
	}

	/* ---------------------------------------------------------------
	 * Admin screen
	 * ------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Import to Template Kit', 'bulk-template-kit-importer' ); ?></h1>

			<?php
			if ( ! post_type_exists( self::TK_POST_TYPE ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div></div>',
					esc_html__( 'The "Template Kit - Export" plugin is not active, so there is nowhere to import to. Activate it and reload this page.', 'bulk-template-kit-importer' )
				);
				return;
			}

			$items = $this->get_source_items();
			?>

			<p>
				<?php esc_html_e( 'Envato skips the Elementor Library when it exports a kit, so every template has to be copied into the kit library first. This screen does that copying in bulk.', 'bulk-template-kit-importer' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Tick what belongs in the kit and type a serial number on each row. Serials start out empty because only you know the running order. Low numbers come first, so the Global Kit usually takes 1 and the pages follow it. A row left blank is imported after everything that has a number. Rows are listed in serial order, and importing a row again updates the template it made before instead of adding a duplicate.', 'bulk-template-kit-importer' ); ?>
			</p>

			<?php if ( empty( $items ) ) : ?>

				<p><em><?php esc_html_e( 'Nothing built with Elementor was found on this site.', 'bulk-template-kit-importer' ); ?></em></p>

			<?php else : ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="btki-form">
					<input type="hidden" name="action" value="btki_import">
					<?php wp_nonce_field( 'btki_import_action', 'btki_nonce' ); ?>

					<p id="btki-nothing-picked" style="display:none;color:#b32d2e;font-weight:600;">
						<?php esc_html_e( 'Nothing is ticked yet. Use the checkbox at the far left of each row, in the "Import" column, to choose what goes into the kit.', 'bulk-template-kit-importer' ); ?>
					</p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<td class="manage-column check-column" style="width:70px;text-align:center;">
									<input type="checkbox" id="btki-select-all">
									<div style="font-size:11px;line-height:1.3;"><?php esc_html_e( 'Import', 'bulk-template-kit-importer' ); ?></div>
								</td>
								<th style="width:80px;"><?php esc_html_e( 'Serial', 'bulk-template-kit-importer' ); ?></th>
								<th><?php esc_html_e( 'Title', 'bulk-template-kit-importer' ); ?></th>
								<th style="width:170px;"><?php esc_html_e( 'Screenshot', 'bulk-template-kit-importer' ); ?></th>
								<th style="width:140px;"><?php esc_html_e( 'Source data', 'bulk-template-kit-importer' ); ?></th>
								<th style="width:230px;"><?php esc_html_e( 'Kit template', 'bulk-template-kit-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $items as $id ) :
							$title    = get_the_title( $id );
							$serial   = $this->stored_serial( $id );
							$source   = $this->data_info( $id );
							$template = (int) get_post_meta( $id, self::META_LINK, true );
							$imported = $template && get_post_status( $template );
							?>
							<tr>
								<th class="check-column" style="text-align:center;">
									<input type="checkbox" name="btki_items[]" value="<?php echo esc_attr( $id ); ?>"
										<?php checked( $imported ); ?>>
								</th>
								<td>
									<input type="number" min="1" max="999" style="width:70px;"
										name="btki_serial[<?php echo esc_attr( $id ); ?>]"
										value="<?php echo esc_attr( $serial ); ?>">
								</td>
								<td>
									<strong><?php echo esc_html( $title ? $title : sprintf( '(no title) #%d', $id ) ); ?></strong>
									<?php if ( $this->looks_like_global_kit( $title ) ) : ?>
										<span style="color:#2271b1;">&mdash; <?php esc_html_e( 'looks like the Global Kit', 'bulk-template-kit-importer' ); ?></span>
									<?php endif; ?>
									<div class="row-actions">
										<span><?php echo esc_html( get_post_type( $id ) ); ?> &middot; </span>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>" target="_blank">
											<?php esc_html_e( 'Edit source', 'bulk-template-kit-importer' ); ?>
										</a>
									</div>
								</td>
								<td>
									<?php
									$shot = (int) get_post_thumbnail_id( $id );

									if ( ! $shot && $imported ) {
										$shot = (int) get_post_thumbnail_id( $template );
									}

									$shot_url = $shot ? wp_get_attachment_image_url( $shot, 'thumbnail' ) : '';
									?>
									<input type="hidden" class="btki-shot-id"
										name="btki_shot[<?php echo esc_attr( $id ); ?>]"
										value="<?php echo esc_attr( $shot ); ?>">

									<img class="btki-shot-img"
										src="<?php echo esc_url( (string) $shot_url ); ?>"
										alt=""
										style="<?php echo $shot_url ? 'display:block;' : 'display:none;'; ?>max-width:150px;height:auto;margin-bottom:4px;border:1px solid #dcdcde;">

									<span class="btki-shot-none" style="<?php echo $shot_url ? 'display:none;' : 'display:block;'; ?>color:#b32d2e;margin-bottom:4px;">
										<?php esc_html_e( 'None', 'bulk-template-kit-importer' ); ?>
									</span>

									<button type="button" class="button button-small btki-pick">
										<?php esc_html_e( 'Choose', 'bulk-template-kit-importer' ); ?>
									</button>
									<button type="button" class="button button-small btki-clear"
										style="<?php echo $shot_url ? '' : 'display:none;'; ?>">
										<?php esc_html_e( 'Clear', 'bulk-template-kit-importer' ); ?>
									</button>
								</td>
								<td>
									<?php if ( $source['len'] > 0 ) : ?>
										<?php
										printf(
											/* translators: 1: character count, 2: number of top level elements. */
											esc_html__( '%1$s chars, %2$d elements', 'bulk-template-kit-importer' ),
											esc_html( number_format_i18n( $source['len'] ) ),
											(int) $source['count']
										);
										?>
									<?php else : ?>
										<span style="color:#b32d2e;"><?php esc_html_e( 'empty', 'bulk-template-kit-importer' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! $imported ) : ?>
										&mdash;
									<?php else : $copy = $this->data_info( $template ); ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $template ) ); ?>" target="_blank">
											<?php esc_html_e( 'Open kit template', 'bulk-template-kit-importer' ); ?>
										</a>
										<br>
										<?php if ( 0 === $copy['len'] ) : ?>
											<span style="color:#b32d2e;"><?php esc_html_e( 'No data copied', 'bulk-template-kit-importer' ); ?></span>
										<?php elseif ( ! $copy['valid'] ) : ?>
											<span style="color:#b32d2e;"><?php esc_html_e( 'Broken JSON, import again', 'bulk-template-kit-importer' ); ?></span>
										<?php elseif ( $copy['len'] !== $source['len'] ) : ?>
											<span style="color:#b32d2e;">
												<?php
												printf(
													/* translators: %s: number of characters missing from the copy. */
													esc_html__( '%s chars lost, import again', 'bulk-template-kit-importer' ),
													esc_html( number_format_i18n( abs( $source['len'] - $copy['len'] ) ) )
												);
												?>
											</span>
										<?php else : ?>
											<span style="color:#1a7e1a;">
												<?php
												printf(
													/* translators: %s: character count that matched the source exactly. */
													esc_html__( '%s chars, exact match', 'bulk-template-kit-importer' ),
													esc_html( number_format_i18n( $copy['len'] ) )
												);
												?>
											</span>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:900px;">
						<h2 style="margin:0 0 8px;font-size:14px;"><?php esc_html_e( 'Options for this import', 'bulk-template-kit-importer' ); ?></h2>
						<p style="margin:0 0 12px;color:#646970;">
							<?php esc_html_e( 'These settings are not row selectors. They apply to every template you import.', 'bulk-template-kit-importer' ); ?>
						</p>

						<p style="margin:0 0 12px;">
							<label for="btki-layout"><strong><?php esc_html_e( 'Page layout', 'bulk-template-kit-importer' ); ?></strong></label><br>
							<select name="btki_layout" id="btki-layout">
								<?php foreach ( $this->layouts() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( 'elementor_canvas', $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span style="color:#646970;">
								<?php esc_html_e( 'Canvas is the usual choice for kit templates, since the theme header and footer would otherwise show around the design.', 'bulk-template-kit-importer' ); ?>
							</span>
						</p>

						<p style="margin:0;">
							<label>
								<input type="checkbox" name="btki_prefix" value="1">
								<?php esc_html_e( 'Put the serial in front of each kit template name, as "01. Home". Elementor orders the export list its own way, and a numbered name is the one thing that keeps the sequence visible wherever the list is sorted. You can rename them at export step 2.', 'bulk-template-kit-importer' ); ?>
							</label>
						</p>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary button-hero">
							<?php esc_html_e( 'Import selected into Template Kit', 'bulk-template-kit-importer' ); ?>
						</button>
						<span id="btki-count" style="margin-left:12px;color:#646970;"></span>
					</p>
				</form>

			<?php endif; ?>

			<hr>
			<p style="color:#646970;">
				<?php
				printf(
					/* translators: %s: developer name, linked to their website. */
					esc_html__( 'Developed by %s', 'bulk-template-kit-importer' ),
					'<a href="https://devtanvirul.com" target="_blank" rel="noopener">devtanvirul</a>'
				);
				?>
			</p>
		</div>

		<script>
		( function () {
			var form    = document.getElementById( 'btki-form' );
			var all     = document.getElementById( 'btki-select-all' );
			var warning = document.getElementById( 'btki-nothing-picked' );
			var counter = document.getElementById( 'btki-count' );

			if ( ! form ) {
				return;
			}

			var rows = form.querySelectorAll( 'input[name="btki_items[]"]' );

			function ticked() {
				return form.querySelectorAll( 'input[name="btki_items[]"]:checked' ).length;
			}

			function refresh() {
				var count = ticked();

				counter.textContent = count
					? count + ' <?php echo esc_js( __( 'selected', 'bulk-template-kit-importer' ) ); ?>'
					: '<?php echo esc_js( __( 'nothing selected yet', 'bulk-template-kit-importer' ) ); ?>';

				if ( count ) {
					warning.style.display = 'none';
				}
			}

			all.addEventListener( 'change', function ( event ) {
				rows.forEach( function ( box ) {
					box.checked = event.target.checked;
				} );
				refresh();
			} );

			rows.forEach( function ( box ) {
				box.addEventListener( 'change', refresh );
			} );

			// Submitting with nothing ticked reports "0 imported" and looks
			// like a failure, so say what is missing before that happens.
			form.addEventListener( 'submit', function ( event ) {
				if ( ! ticked() ) {
					event.preventDefault();
					warning.style.display = 'block';
					warning.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} );

			// Screenshot pickers, one per row, on the media library's frame.
			function paint( row, id, url ) {
				row.querySelector( '.btki-shot-id' ).value = id;

				var image = row.querySelector( '.btki-shot-img' );
				var none  = row.querySelector( '.btki-shot-none' );
				var clear = row.querySelector( '.btki-clear' );

				image.src           = url;
				image.style.display = url ? 'block' : 'none';
				none.style.display  = url ? 'none' : 'block';
				clear.style.display = url ? '' : 'none';
			}

			form.querySelectorAll( '.btki-pick' ).forEach( function ( button ) {
				var frame;

				button.addEventListener( 'click', function () {
					var row = button.closest( 'tr' );

					if ( ! frame ) {
						frame = wp.media( {
							title: '<?php echo esc_js( __( 'Choose a screenshot', 'bulk-template-kit-importer' ) ); ?>',
							button: { text: '<?php echo esc_js( __( 'Use this image', 'bulk-template-kit-importer' ) ); ?>' },
							library: { type: 'image' },
							multiple: false
						} );

						frame.on( 'select', function () {
							var picked = frame.state().get( 'selection' ).first().toJSON();
							var thumb  = picked.sizes && picked.sizes.thumbnail
								? picked.sizes.thumbnail.url
								: picked.url;

							paint( row, picked.id, thumb );
						} );
					}

					frame.open();
				} );
			} );

			form.querySelectorAll( '.btki-clear' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					paint( button.closest( 'tr' ), '', '' );
				} );
			} );

			refresh();
		} )();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Importing
	 * ------------------------------------------------------------- */

	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bulk-template-kit-importer' ) );
		}

		check_admin_referer( 'btki_import_action', 'btki_nonce' );

		if ( ! post_type_exists( self::TK_POST_TYPE ) ) {
			wp_die( esc_html__( 'The Template Kit - Export plugin is not active.', 'bulk-template-kit-importer' ) );
		}

		$ids     = isset( $_POST['btki_items'] ) ? array_map( 'intval', (array) $_POST['btki_items'] ) : array();
		// Kept as raw strings, because "" and "0" have to stay apart: one
		// means the user left the position open, the other is a typo.
		$serials = isset( $_POST['btki_serial'] ) ? (array) wp_unslash( $_POST['btki_serial'] ) : array();
		$serials = array_map( array( $this, 'clean_serial' ), $serials );
		$shots   = isset( $_POST['btki_shot'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['btki_shot'] ) ) : array();
		$prefix  = ! empty( $_POST['btki_prefix'] );

		$layout = isset( $_POST['btki_layout'] ) ? sanitize_text_field( wp_unslash( $_POST['btki_layout'] ) ) : 'elementor_canvas';

		if ( ! array_key_exists( $layout, $this->layouts() ) ) {
			$layout = 'elementor_canvas';
		}

		// Remember every serial on screen, so the order survives even for
		// rows that were not ticked this time. A cleared box is forgotten
		// rather than stored as a position.
		foreach ( $serials as $post_id => $serial ) {
			if ( '' === $serial ) {
				delete_post_meta( (int) $post_id, self::META_ORDER );
			} else {
				update_post_meta( (int) $post_id, self::META_ORDER, $serial );
			}
		}

		// Import in serial order so the kit library is built front to back.
		usort(
			$ids,
			function ( $a, $b ) use ( $serials ) {
				$order_a = ( isset( $serials[ $a ] ) && '' !== $serials[ $a ] ) ? $serials[ $a ] : self::UNNUMBERED;
				$order_b = ( isset( $serials[ $b ] ) && '' !== $serials[ $b ] ) ? $serials[ $b ] : self::UNNUMBERED;

				return $order_a <=> $order_b;
			}
		);

		$counts = array(
			'imported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		$document_type = $this->kit_document_type();

		foreach ( $ids as $source_id ) {
			$result = $this->import_one(
				$source_id,
				array(
					'serial'     => isset( $serials[ $source_id ] ) ? $serials[ $source_id ] : '',
					'screenshot' => isset( $shots[ $source_id ] ) ? $shots[ $source_id ] : 0,
					'prefix'     => $prefix,
					'layout'     => $layout,
					'document'   => $document_type,
				)
			);

			$counts[ $result ]++;
		}

		wp_safe_redirect( $this->result_url( $counts ) );
		exit;
	}

	/**
	 * Where to send the browser after importing.
	 *
	 * The screen can sit under any of several parent menus, and the menu
	 * globals are not loaded on admin-post.php to work it out from here,
	 * so the page that submitted the form is used as the destination.
	 *
	 * @param array $counts Result tallies to report.
	 * @return string
	 */
	private function result_url( array $counts ) {
		$url = wp_get_referer();

		if ( ! $url ) {
			$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		}

		$url = remove_query_arg( array( 'btki_imported', 'btki_skipped', 'btki_failed' ), $url );

		return add_query_arg(
			array(
				'btki_imported' => $counts['imported'],
				'btki_skipped'  => $counts['skipped'],
				'btki_failed'   => $counts['failed'],
			),
			$url
		);
	}

	/**
	 * Copy one Elementor design into the kit library.
	 *
	 * @param int   $source_id Post to copy from.
	 * @param array $args      serial, screenshot, prefix, layout, document.
	 * @return string One of: imported, skipped, failed.
	 */
	private function import_one( $source_id, array $args ) {
		$source = get_post( $source_id );
		$data   = get_post_meta( $source_id, '_elementor_data', true );

		if ( ! $source || empty( $data ) ) {
			return 'skipped';
		}

		$serial = $args['serial'];
		$title  = $source->post_title;

		// Only a serial the user actually typed gets put in the name.
		if ( $args['prefix'] && '' !== $serial ) {
			$title = sprintf( '%02d. %s', $serial, $title );
		}

		// Unnumbered rows still need a position, and they belong last.
		$position = '' === $serial ? self::UNNUMBERED : $serial;

		$existing = (int) get_post_meta( $source_id, self::META_LINK, true );
		$exists   = $existing && get_post_status( $existing );

		$postarr = array(
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_type'    => self::TK_POST_TYPE,
			'post_content' => '',
			'menu_order'   => $position,
		);

		if ( $exists ) {
			$postarr['ID'] = $existing;
		}

		$template_id = $exists
			? wp_update_post( $postarr, true )
			: wp_insert_post( $postarr, true );

		if ( is_wp_error( $template_id ) || ! $template_id ) {
			return 'failed';
		}

		/*
		 * update_post_meta() runs wp_unslash() on the value internally, which
		 * strips a layer of backslashes out of the JSON:
		 *   "https:\/\/site.com"  ->  "https://site.com"   (shorter, still valid)
		 *   "he said \"hi\""      ->  "he said "hi""       (JSON now broken)
		 * Elementor wraps the value in wp_slash() for the same reason. Without
		 * it, any design containing a quote mark lands in the kit unparseable
		 * and opens as a blank canvas.
		 */
		update_post_meta( $template_id, '_elementor_data', wp_slash( $data ) );
		update_post_meta( $template_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $template_id, '_elementor_template_type', $args['document'] );

		$version = get_post_meta( $source_id, '_elementor_version', true );

		if ( ! $version ) {
			$version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0';
		}

		update_post_meta( $template_id, '_elementor_version', $version );

		$settings = get_post_meta( $source_id, '_elementor_page_settings', true );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		/*
		 * Page layout lives in two places. WordPress reads _wp_page_template
		 * when it renders the post, and Elementor reads the "template" key in
		 * its own page settings when it opens the editor. Writing only one of
		 * them leaves the editor and the front end disagreeing.
		 */
		$layout = '' === $args['layout']
			? (string) get_post_meta( $source_id, '_wp_page_template', true )
			: $args['layout'];

		if ( $layout ) {
			update_post_meta( $template_id, '_wp_page_template', $layout );
			$settings['template'] = $layout;
		}

		if ( ! empty( $settings ) ) {
			update_post_meta( $template_id, '_elementor_page_settings', wp_slash( $settings ) );
		}

		// Envato requires a screenshot on every kit template. Use the one
		// picked on screen, falling back to whatever the source already had.
		$screenshot = $args['screenshot'] ? $args['screenshot'] : (int) get_post_thumbnail_id( $source_id );

		if ( $screenshot ) {
			set_post_thumbnail( $template_id, $screenshot );
		} else {
			delete_post_thumbnail( $template_id );
		}

		update_post_meta( $source_id, self::META_LINK, $template_id );

		// Drop styles generated for whatever the template held before.
		delete_post_meta( $template_id, '_elementor_css' );
		$this->clear_elementor_cache();

		// The copy is only good if it came back byte for byte.
		if ( get_post_meta( $template_id, '_elementor_data', true ) !== $data ) {
			return 'failed';
		}

		return 'imported';
	}

	private function clear_elementor_cache() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/* ---------------------------------------------------------------
	 * Result notice
	 * ------------------------------------------------------------- */

	public function notices() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE_SLUG !== $page || ! isset( $_GET['btki_imported'] ) ) {
			return;
		}

		$imported = (int) $_GET['btki_imported'];
		$skipped  = isset( $_GET['btki_skipped'] ) ? (int) $_GET['btki_skipped'] : 0;
		$failed   = isset( $_GET['btki_failed'] ) ? (int) $_GET['btki_failed'] : 0;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: templates imported, 2: rows skipped. */
					__( '%1$d template(s) imported into the kit. %2$d skipped for holding no Elementor data.', 'bulk-template-kit-importer' ),
					$imported,
					$skipped
				)
			)
		);

		if ( $failed > 0 ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of rows that could not be imported. */
						__( '%d row(s) could not be imported correctly. Try importing them again.', 'bulk-template-kit-importer' ),
						$failed
					)
				)
			);
		}
	}
}

new Bulk_Template_Kit_Importer();

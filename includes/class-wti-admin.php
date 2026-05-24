<?php

defined( 'ABSPATH' ) || exit;

class WTI_Admin {
	public static function get_default_settings() {
		return array(
			'feed_url'        => WTI_Feed_Client::DEFAULT_PROM_FEED_URL,
			'main_feed_url'   => WTI_Feed_Client::DEFAULT_MAIN_FEED_URL,
			'category_mode'   => 'auto',
			'markup_percent'  => '0',
			'sync_time'       => '17:00',
			'sync_interval'   => WTI_Scheduler::SCHEDULE_FOUR_HOURS,
			'dry_run'         => 'yes',
			'import_limit'    => 10,
			'variable_limit'  => 1,
			'import_images'   => 'no',
			'product_status'  => 'draft',
			'selected_paths'  => self::get_default_totobi_paths(),
			'category_map'    => array(),
		);
	}

	public static function get_default_totobi_paths() {
		return array(
			'/ruchki/',
			'/podorozh-ta-vdpochinok/lhtariki/',
			'/podorozh-ta-vdpochinok/plyashki-dlya-pittya/',
			'/podorozh-ta-vdpochinok/termosi-ta-termokruzhki/',
			'/odyag/reglani/',
			'/odyag/zhiletki/',
			'/ofs-uk/bloknoti/',
			'/elektronka/godinniki/',
			'/elektronka/zaryadn-pristro/',
			'/upakovka-uk/podarunkova-upakovka/',
		);
	}

	public static function get_known_totobi_categories() {
		return array(
			'187' => array( 'name' => 'Металеві ручки', 'path' => '/ruchki/' ),
			'188' => array( 'name' => 'Пластикові ручки', 'path' => '/ruchki/' ),
			'269' => array( 'name' => 'Еко ручки', 'path' => '/ruchki/' ),
			'314' => array( 'name' => 'Олівці', 'path' => '/ruchki/' ),
			'287' => array( 'name' => 'Ліхтарики', 'path' => '/podorozh-ta-vdpochinok/lhtariki/' ),
			'184' => array( 'name' => 'Пляшки для пиття', 'path' => '/podorozh-ta-vdpochinok/plyashki-dlya-pittya/' ),
			'185' => array( 'name' => 'Термоси та термокружки', 'path' => '/podorozh-ta-vdpochinok/termosi-ta-termokruzhki/' ),
			'246' => array( 'name' => 'Реглани, фліси', 'path' => '/odyag/reglani/' ),
			'215' => array( 'name' => 'Жилети', 'path' => '/odyag/zhiletki/' ),
			'205' => array( 'name' => 'Записні книжки', 'path' => '/ofs-uk/bloknoti/' ),
			'298' => array( 'name' => 'Годинники', 'path' => '/elektronka/godinniki/' ),
			'251' => array( 'name' => 'Зарядні пристрої', 'path' => '/elektronka/zaryadn-pristro/' ),
			'282' => array( 'name' => 'Подарункова коробка', 'path' => '/upakovka-uk/podarunkova-upakovka/' ),
		);
	}

	public static function get_settings() {
		$settings = get_option( WTI_OPTION_KEY, array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_default_settings() );
	}

	public static function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Totobi Integration', WTI_TEXT_DOMAIN ),
			__( 'Totobi Integration', WTI_TEXT_DOMAIN ),
			'manage_woocommerce',
			'wti-totobi-integration',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wti-totobi-integration' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wti-admin', WTI_PLUGIN_URL . 'assets/css/admin.css', array(), WTI_VERSION );
		wp_enqueue_script( 'wti-admin', WTI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), WTI_VERSION, true );
		wp_localize_script(
			'wti-admin',
			'wtiAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wti_ajax_nonce' ),
			)
		);
	}

	public static function handle_post_actions() {
		if ( empty( $_POST['wti_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', WTI_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'wti_admin_action', 'wti_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['wti_action'] ) );

		if ( 'save_settings' === $action ) {
			self::save_settings();
			WTI_Scheduler::reschedule();
			self::redirect_with_notice( 'settings_saved' );
		}

	}

	private static function save_settings() {
		$raw_paths = isset( $_POST['selected_paths'] ) ? (array) wp_unslash( $_POST['selected_paths'] ) : array();
		$paths     = array_values( array_intersect( array_map( 'sanitize_text_field', $raw_paths ), self::get_default_totobi_paths() ) );

		$settings = array(
			'feed_url'       => isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : WTI_Feed_Client::DEFAULT_PROM_FEED_URL,
			'main_feed_url'  => isset( $_POST['main_feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['main_feed_url'] ) ) : WTI_Feed_Client::DEFAULT_MAIN_FEED_URL,
			'category_mode'  => isset( $_POST['category_mode'] ) && 'manual' === $_POST['category_mode'] ? 'manual' : 'auto',
			'markup_percent' => isset( $_POST['markup_percent'] ) ? sanitize_text_field( wp_unslash( $_POST['markup_percent'] ) ) : '0',
			'sync_time'      => isset( $_POST['sync_time'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_time'] ) ) : '17:00',
			'sync_interval'  => isset( $_POST['sync_interval'] ) && in_array( $_POST['sync_interval'], array( WTI_Scheduler::SCHEDULE_FOUR_HOURS, WTI_Scheduler::SCHEDULE_SIX_HOURS, 'daily' ), true ) ? sanitize_key( wp_unslash( $_POST['sync_interval'] ) ) : WTI_Scheduler::SCHEDULE_FOUR_HOURS,
			'dry_run'        => empty( $_POST['dry_run'] ) ? 'no' : 'yes',
			'import_limit'   => isset( $_POST['import_limit'] ) ? max( 1, absint( wp_unslash( $_POST['import_limit'] ) ) ) : 10,
			'variable_limit' => isset( $_POST['variable_limit'] ) ? max( 0, absint( wp_unslash( $_POST['variable_limit'] ) ) ) : 1,
			'import_images'  => empty( $_POST['import_images'] ) ? 'no' : 'yes',
			'product_status' => isset( $_POST['product_status'] ) && in_array( $_POST['product_status'], array( 'draft', 'publish' ), true ) ? sanitize_key( wp_unslash( $_POST['product_status'] ) ) : 'draft',
			'selected_paths' => $paths ? $paths : self::get_default_totobi_paths(),
			'category_map'   => self::sanitize_category_map( isset( $_POST['category_map'] ) ? (array) wp_unslash( $_POST['category_map'] ) : array() ),
		);

		update_option( WTI_OPTION_KEY, $settings, false );
	}

	private static function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'wti-totobi-integration',
					'wti_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_settings_page() {
		$settings    = self::get_settings();
		$last_result = get_option( 'wti_last_result', array() );
		$next_run    = wp_next_scheduled( WTI_CRON_HOOK );
		$notice      = isset( $_GET['wti_notice'] ) ? sanitize_key( wp_unslash( $_GET['wti_notice'] ) ) : '';

		?>
		<div class="wrap wti-admin">
			<h1><?php esc_html_e( 'Totobi Integration', WTI_TEXT_DOMAIN ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( self::notice_text( $notice ) ); ?></p></div>
			<?php endif; ?>

			<?php self::render_manual_sync_panel(); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'wti_admin_action', 'wti_nonce' ); ?>
				<input type="hidden" name="wti_action" value="save_settings">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="feed_url"><?php esc_html_e( 'Prom YML feed URL', WTI_TEXT_DOMAIN ); ?></label></th>
						<td><input type="url" class="regular-text code" id="feed_url" name="feed_url" value="<?php echo esc_attr( $settings['feed_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="main_feed_url"><?php esc_html_e( 'Main YML fallback URL', WTI_TEXT_DOMAIN ); ?></label></th>
						<td><input type="url" class="regular-text code" id="main_feed_url" name="main_feed_url" value="<?php echo esc_attr( $settings['main_feed_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Category mode', WTI_TEXT_DOMAIN ); ?></th>
						<td>
							<label><input type="radio" name="category_mode" value="auto" <?php checked( $settings['category_mode'], 'auto' ); ?>> <?php esc_html_e( 'Automatic from client category list', WTI_TEXT_DOMAIN ); ?></label><br>
							<label><input type="radio" name="category_mode" value="manual" <?php checked( $settings['category_mode'], 'manual' ); ?>> <?php esc_html_e( 'Manual mapping', WTI_TEXT_DOMAIN ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Totobi categories', WTI_TEXT_DOMAIN ); ?></th>
						<td>
							<?php foreach ( self::get_default_totobi_paths() as $path ) : ?>
								<label class="wti-path"><input type="checkbox" name="selected_paths[]" value="<?php echo esc_attr( $path ); ?>" <?php checked( in_array( $path, (array) $settings['selected_paths'], true ) ); ?>> <code><?php echo esc_html( $path ); ?></code></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce category mapping', WTI_TEXT_DOMAIN ); ?></th>
						<td><?php self::render_category_mapping_table( $settings ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="markup_percent"><?php esc_html_e( 'Markup percent', WTI_TEXT_DOMAIN ); ?></label></th>
						<td><input type="number" step="0.01" id="markup_percent" name="markup_percent" value="<?php echo esc_attr( $settings['markup_percent'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sync_time"><?php esc_html_e( 'First sync time', WTI_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="time" id="sync_time" name="sync_time" value="<?php echo esc_attr( $settings['sync_time'] ); ?>">
							<p class="description"><?php echo esc_html( $next_run ? sprintf( __( 'Next automatic run: %s', WTI_TEXT_DOMAIN ), wp_date( 'Y-m-d H:i:s', $next_run ) ) : __( 'Automatic sync is not scheduled yet.', WTI_TEXT_DOMAIN ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sync_interval"><?php esc_html_e( 'Sync interval', WTI_TEXT_DOMAIN ); ?></label></th>
						<td>
							<select id="sync_interval" name="sync_interval">
								<option value="<?php echo esc_attr( WTI_Scheduler::SCHEDULE_FOUR_HOURS ); ?>" <?php selected( $settings['sync_interval'], WTI_Scheduler::SCHEDULE_FOUR_HOURS ); ?>><?php esc_html_e( 'Every 4 hours', WTI_TEXT_DOMAIN ); ?></option>
								<option value="<?php echo esc_attr( WTI_Scheduler::SCHEDULE_SIX_HOURS ); ?>" <?php selected( $settings['sync_interval'], WTI_Scheduler::SCHEDULE_SIX_HOURS ); ?>><?php esc_html_e( 'Every 6 hours', WTI_TEXT_DOMAIN ); ?></option>
								<option value="daily" <?php selected( $settings['sync_interval'], 'daily' ); ?>><?php esc_html_e( 'Daily', WTI_TEXT_DOMAIN ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Totobi Prom YML is checked on this interval. If the catalog date has not changed, the automatic sync is skipped.', WTI_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Write mode', WTI_TEXT_DOMAIN ); ?></th>
						<td>
							<label><input type="checkbox" name="dry_run" value="yes" <?php checked( $settings['dry_run'], 'yes' ); ?>> <?php esc_html_e( 'Preview only, do not write product changes', WTI_TEXT_DOMAIN ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="import_limit"><?php esc_html_e( 'Simple product batch size', WTI_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="number" min="1" max="100" id="import_limit" name="import_limit" value="<?php echo esc_attr( $settings['import_limit'] ); ?>">
							<p class="description"><?php esc_html_e( 'Number of simple products processed per AJAX request. This is not a total import limit.', WTI_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="variable_limit"><?php esc_html_e( 'Variable product batch size', WTI_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="number" min="0" max="20" id="variable_limit" name="variable_limit" value="<?php echo esc_attr( $settings['variable_limit'] ); ?>">
							<p class="description"><?php esc_html_e( 'Number of variable parent products processed per AJAX request. All valid variations for those parents are processed in the same batch.', WTI_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Images', WTI_TEXT_DOMAIN ); ?></th>
						<td>
							<label><input type="checkbox" name="import_images" value="yes" <?php checked( $settings['import_images'], 'yes' ); ?>> <?php esc_html_e( 'Import product images from Totobi', WTI_TEXT_DOMAIN ); ?></label>
							<p class="description"><?php esc_html_e( 'Uses Totobi picture tags: the first image becomes the featured image, the rest go to the gallery. Existing imported images are reused by source URL hash.', WTI_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="product_status"><?php esc_html_e( 'Created product status', WTI_TEXT_DOMAIN ); ?></label></th>
						<td>
							<select id="product_status" name="product_status">
								<option value="draft" <?php selected( $settings['product_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', WTI_TEXT_DOMAIN ); ?></option>
								<option value="publish" <?php selected( $settings['product_status'], 'publish' ); ?>><?php esc_html_e( 'Published', WTI_TEXT_DOMAIN ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', WTI_TEXT_DOMAIN ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Last sync', WTI_TEXT_DOMAIN ); ?></h2>
			<?php self::render_last_sync_summary( $last_result ); ?>

			<h2><?php esc_html_e( 'Log tail', WTI_TEXT_DOMAIN ); ?></h2>
			<?php self::render_log_summary(); ?>
		</div>
		<?php
	}

	private static function render_manual_sync_panel() {
		?>
		<div class="wti-import-panel">
			<div class="wti-panel-header">
				<div>
					<h2><?php esc_html_e( 'Manual sync', WTI_TEXT_DOMAIN ); ?></h2>
					<p class="description"><?php esc_html_e( 'Starts the Totobi update now. Progress is shown live while product data and images are processed in small batches.', WTI_TEXT_DOMAIN ); ?></p>
				</div>
				<div class="wti-actions">
					<button type="button" class="button button-primary" id="wti-start-import"><?php esc_html_e( 'Start sync', WTI_TEXT_DOMAIN ); ?></button>
					<button type="button" class="button" id="wti-pause-import" style="display:none;"><?php esc_html_e( 'Pause', WTI_TEXT_DOMAIN ); ?></button>
					<button type="button" class="button" id="wti-resume-import" style="display:none;"><?php esc_html_e( 'Resume', WTI_TEXT_DOMAIN ); ?></button>
				</div>
			</div>
			<div id="wti-progress-wrap" class="wti-progress-wrap" style="display:none;">
				<div class="wti-progress-status"><span id="wti-progress-status-text"><?php esc_html_e( 'Waiting to start', WTI_TEXT_DOMAIN ); ?></span><span class="wti-progress-percent">0%</span></div>
				<div class="wti-progress"><div class="wti-progress-bar"></div></div>
				<div class="wti-metric-grid">
					<div><span id="wti-stat-processed">0</span> / <span id="wti-stat-total">0</span><small><?php esc_html_e( 'processed', WTI_TEXT_DOMAIN ); ?></small></div>
					<div><span id="wti-stat-updated-simple">0</span><small><?php esc_html_e( 'simple updated', WTI_TEXT_DOMAIN ); ?></small></div>
					<div><span id="wti-stat-updated-variable">0</span><small><?php esc_html_e( 'variable updated', WTI_TEXT_DOMAIN ); ?></small></div>
					<div><span id="wti-stat-updated-variation">0</span><small><?php esc_html_e( 'variations updated', WTI_TEXT_DOMAIN ); ?></small></div>
					<div><span id="wti-stat-images">0</span><small><?php esc_html_e( 'images downloaded', WTI_TEXT_DOMAIN ); ?></small></div>
					<div><span id="wti-stat-errors">0</span><small><?php esc_html_e( 'errors', WTI_TEXT_DOMAIN ); ?></small></div>
				</div>
				<div id="wti-log-output" class="wti-log-list"></div>
			</div>
		</div>
		<?php
	}

	private static function render_last_sync_summary( $last_result ) {
		$execution = isset( $last_result['execution'] ) && is_array( $last_result['execution'] ) ? $last_result['execution'] : array();
		$status    = isset( $last_result['status'] ) ? $last_result['status'] : 'none';
		$updated   = isset( $last_result['updated'] ) ? (int) $last_result['updated'] : 0;
		$errors    = isset( $last_result['errors'] ) ? (int) $last_result['errors'] : 0;
		?>
		<div class="wti-summary">
			<div><strong><?php echo esc_html( strtoupper( $status ) ); ?></strong><small><?php esc_html_e( 'status', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( isset( $last_result['finished_at'] ) ? $last_result['finished_at'] : '-' ); ?></strong><small><?php esc_html_e( 'finished', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( isset( $last_result['catalog_date'] ) ? $last_result['catalog_date'] : '-' ); ?></strong><small><?php esc_html_e( 'catalog', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( $updated ); ?></strong><small><?php esc_html_e( 'updated records', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( isset( $execution['imported_images'] ) ? (int) $execution['imported_images'] : 0 ); ?></strong><small><?php esc_html_e( 'images downloaded', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( $errors ); ?></strong><small><?php esc_html_e( 'errors', WTI_TEXT_DOMAIN ); ?></small></div>
		</div>
		<?php if ( ! empty( $last_result['message'] ) ) : ?>
			<p class="description"><?php echo esc_html( $last_result['message'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	private static function render_log_summary() {
		$tail  = trim( WTI_Logger::read_tail( 5000 ) );
		$lines = $tail ? array_slice( preg_split( '/\r\n|\r|\n/', $tail ), -8 ) : array();

		if ( empty( $lines ) ) {
			echo '<p class="description">' . esc_html__( 'No log entries yet.', WTI_TEXT_DOMAIN ) . '</p>';
			return;
		}

		echo '<ul class="wti-log-tail">';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( wp_html_excerpt( $line, 220, '...' ) ) . '</li>';
		}
		echo '</ul>';
	}

	private static function notice_text( $notice ) {
		$messages = array(
			'settings_saved' => __( 'Settings saved.', WTI_TEXT_DOMAIN ),
			'sync_started'   => __( 'Manual sync check completed.', WTI_TEXT_DOMAIN ),
			'sync_error'     => __( 'Manual sync check failed. See log for details.', WTI_TEXT_DOMAIN ),
		);

		return isset( $messages[ $notice ] ) ? $messages[ $notice ] : '';
	}

	private static function sanitize_category_map( $raw_map ) {
		$map   = array();
		$known = self::get_known_totobi_categories();

		foreach ( $known as $totobi_id => $category ) {
			$value = isset( $raw_map[ $totobi_id ] ) ? absint( $raw_map[ $totobi_id ] ) : 0;

			if ( $value > 0 ) {
				$map[ $totobi_id ] = $value;
			}
		}

		return $map;
	}

	private static function render_category_mapping_table( $settings ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			echo '<p class="description">' . esc_html__( 'Cannot load WooCommerce categories.', WTI_TEXT_DOMAIN ) . '</p>';
			return;
		}

		$terms = self::sort_terms_for_select( $terms );
		$map   = isset( $settings['category_map'] ) && is_array( $settings['category_map'] ) ? $settings['category_map'] : array();

		echo '<table class="widefat striped wti-category-map"><thead><tr>';
		echo '<th>' . esc_html__( 'Totobi ID', WTI_TEXT_DOMAIN ) . '</th>';
		echo '<th>' . esc_html__( 'Totobi category', WTI_TEXT_DOMAIN ) . '</th>';
		echo '<th>' . esc_html__( 'Client path', WTI_TEXT_DOMAIN ) . '</th>';
		echo '<th>' . esc_html__( 'WooCommerce category', WTI_TEXT_DOMAIN ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( self::get_known_totobi_categories() as $totobi_id => $category ) {
			$selected = isset( $map[ $totobi_id ] ) ? (int) $map[ $totobi_id ] : 0;

			echo '<tr>';
			echo '<td><code>' . esc_html( $totobi_id ) . '</code></td>';
			echo '<td>' . esc_html( $category['name'] ) . '</td>';
			echo '<td><code>' . esc_html( $category['path'] ) . '</code></td>';
			echo '<td><select name="category_map[' . esc_attr( $totobi_id ) . ']">';
			echo '<option value="0">' . esc_html__( 'Do not assign', WTI_TEXT_DOMAIN ) . '</option>';

			foreach ( $terms as $term ) {
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $term->term_id,
					selected( $selected, (int) $term->term_id, false ),
					esc_html( $term->label )
				);
			}

			echo '</select></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function sort_terms_for_select( $terms ) {
		$by_parent = array();

		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$sorted = array();
		self::append_child_terms( 0, $by_parent, $sorted, 0 );

		return $sorted;
	}

	private static function append_child_terms( $parent_id, $by_parent, &$sorted, $depth ) {
		if ( empty( $by_parent[ $parent_id ] ) ) {
			return;
		}

		usort(
			$by_parent[ $parent_id ],
			function ( $left, $right ) {
				return strnatcasecmp( $left->name, $right->name );
			}
		);

		foreach ( $by_parent[ $parent_id ] as $term ) {
			$term->label = str_repeat( '- ', $depth ) . $term->name;
			$sorted[]    = $term;
			self::append_child_terms( (int) $term->term_id, $by_parent, $sorted, $depth + 1 );
		}
	}
}

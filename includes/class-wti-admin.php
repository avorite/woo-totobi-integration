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
			'dry_run'         => 'no',
			'import_limit'    => 50,
			'variable_limit'  => 8,
			'import_images'   => 'yes',
			'product_status'  => 'publish',
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
				'strings' => array(
					'preparing'       => __( 'Preparing Totobi feed...', WTI_TEXT_DOMAIN ),
					'cannotStart'     => __( 'Cannot start sync', WTI_TEXT_DOMAIN ),
					'syncStarted'     => __( 'Sync started. Processing first batch...', WTI_TEXT_DOMAIN ),
					'startedLog'      => __( 'Sync started. Products: %total%, catalog: %catalog%', WTI_TEXT_DOMAIN ),
					'ajaxStartFailed' => __( 'AJAX start request failed', WTI_TEXT_DOMAIN ),
					'processingBatch' => __( 'Processing next batch...', WTI_TEXT_DOMAIN ),
					'batchFailed'     => __( 'Batch failed', WTI_TEXT_DOMAIN ),
					'errorPrefix'     => __( 'ERROR:', WTI_TEXT_DOMAIN ),
					'syncCompleted'   => __( 'Sync completed.', WTI_TEXT_DOMAIN ),
					'retryBatch'      => __( 'Batch request failed. Retry %count% in 5 seconds...', WTI_TEXT_DOMAIN ),
					'retrying'        => __( 'Batch request failed. Retrying...', WTI_TEXT_DOMAIN ),
					'syncPaused'      => __( 'Sync paused.', WTI_TEXT_DOMAIN ),
					'syncResumed'     => __( 'Sync resumed.', WTI_TEXT_DOMAIN ),
					'resumedStatus'   => __( 'Sync resumed. Processing next batch...', WTI_TEXT_DOMAIN ),
					'simpleProducts'  => __( 'simple products', WTI_TEXT_DOMAIN ),
					'variableProducts'=> __( 'variable products', WTI_TEXT_DOMAIN ),
					'deletedProducts' => __( 'missing products', WTI_TEXT_DOMAIN ),
					'processingStage' => __( 'Processing %stage%: %processed% of %total%', WTI_TEXT_DOMAIN ),
					'resetConfirm'    => __( 'Reset the current sync session?', WTI_TEXT_DOMAIN ),
					'resetDone'       => __( 'Sync session reset.', WTI_TEXT_DOMAIN ),
					'interrupted'     => __( 'Sync was interrupted. Continue?', WTI_TEXT_DOMAIN ),
				),
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
		$category_mode = isset( $_POST['category_mode'] ) && 'manual' === $_POST['category_mode'] ? 'manual' : 'auto';

		$settings = array(
			'feed_url'       => isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : WTI_Feed_Client::DEFAULT_PROM_FEED_URL,
			'main_feed_url'  => isset( $_POST['main_feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['main_feed_url'] ) ) : WTI_Feed_Client::DEFAULT_MAIN_FEED_URL,
			'category_mode'  => $category_mode,
			'markup_percent' => isset( $_POST['markup_percent'] ) ? sanitize_text_field( wp_unslash( $_POST['markup_percent'] ) ) : '0',
			'sync_time'      => isset( $_POST['sync_time'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_time'] ) ) : '17:00',
			'sync_interval'  => isset( $_POST['sync_interval'] ) && in_array( $_POST['sync_interval'], array( WTI_Scheduler::SCHEDULE_FOUR_HOURS, WTI_Scheduler::SCHEDULE_SIX_HOURS, 'daily' ), true ) ? sanitize_key( wp_unslash( $_POST['sync_interval'] ) ) : WTI_Scheduler::SCHEDULE_FOUR_HOURS,
			'dry_run'        => 'no',
			'import_limit'   => 50,
			'variable_limit' => 8,
			'import_images'  => 'yes',
			'product_status' => 'publish',
			'selected_paths' => 'manual' === $category_mode && $paths ? $paths : self::get_default_totobi_paths(),
			'category_map'   => 'manual' === $category_mode ? self::sanitize_category_map( isset( $_POST['category_map'] ) ? (array) wp_unslash( $_POST['category_map'] ) : array() ) : array(),
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
			<div class="wti-page-header">
				<h1><?php esc_html_e( 'Totobi Integration', WTI_TEXT_DOMAIN ); ?></h1>
				<p><?php esc_html_e( 'Import Totobi products, prices, stock, variations, and images into WooCommerce.', WTI_TEXT_DOMAIN ); ?></p>
			</div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( self::notice_text( $notice ) ); ?></p></div>
			<?php endif; ?>

			<?php self::render_manual_sync_panel(); ?>

			<form class="wti-settings-panel" method="post" action="">
				<?php wp_nonce_field( 'wti_admin_action', 'wti_nonce' ); ?>
				<input type="hidden" name="wti_action" value="save_settings">
				<div class="wti-section-heading">
					<h2><?php esc_html_e( 'Settings', WTI_TEXT_DOMAIN ); ?></h2>
					<p><?php esc_html_e( 'Feed source, categories, schedule, and product update rules.', WTI_TEXT_DOMAIN ); ?></p>
				</div>

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
							<div class="wti-manual-category-controls">
								<?php foreach ( self::get_default_totobi_paths() as $path ) : ?>
									<label class="wti-path"><input type="checkbox" name="selected_paths[]" value="<?php echo esc_attr( $path ); ?>" <?php checked( in_array( $path, (array) $settings['selected_paths'], true ) ); ?>> <code><?php echo esc_html( $path ); ?></code></label>
								<?php endforeach; ?>
							</div>
							<p class="description wti-auto-category-note"><?php esc_html_e( 'Automatic mode uses the approved client category list. Switch to manual mapping to edit this list.', WTI_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce category mapping', WTI_TEXT_DOMAIN ); ?></th>
						<td>
							<div class="wti-manual-category-controls">
								<?php self::render_category_mapping_table( $settings ); ?>
							</div>
							<p class="description wti-auto-category-note"><?php esc_html_e( 'Automatic mode keeps WooCommerce mapping locked. Switch to manual mapping to edit assignments.', WTI_TEXT_DOMAIN ); ?></p>
						</td>
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
						<th scope="row"><?php esc_html_e( 'Product update mode', WTI_TEXT_DOMAIN ); ?></th>
						<td>
							<p><?php esc_html_e( 'Products are published automatically. Images are imported from Totobi and unchanged products are skipped.', WTI_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', WTI_TEXT_DOMAIN ) ); ?>
			</form>

			<div class="wti-bottom-grid">
				<section class="wti-card">
					<div class="wti-section-heading">
						<h2><?php esc_html_e( 'Last sync', WTI_TEXT_DOMAIN ); ?></h2>
						<p><?php esc_html_e( 'Short result from the most recent import run.', WTI_TEXT_DOMAIN ); ?></p>
					</div>
					<?php self::render_last_sync_summary( $last_result ); ?>
				</section>

				<section class="wti-card">
					<div class="wti-section-heading">
						<h2><?php esc_html_e( 'Recent log', WTI_TEXT_DOMAIN ); ?></h2>
					</div>
					<?php self::render_log_summary(); ?>
				</section>
			</div>
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
					<button type="button" class="button" id="wti-reset-import" style="display:none;"><?php esc_html_e( 'Reset sync', WTI_TEXT_DOMAIN ); ?></button>
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
					<div><span id="wti-stat-skipped">0</span><small><?php esc_html_e( 'products unchanged skipped', WTI_TEXT_DOMAIN ); ?></small></div>
					<div><span id="wti-stat-deleted">0</span><small><?php esc_html_e( 'missing set out of stock', WTI_TEXT_DOMAIN ); ?></small></div>
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
		$created   = isset( $last_result['created'] ) ? (int) $last_result['created'] : 0;
		$skipped   = isset( $execution['skipped_unchanged'] ) ? (int) $execution['skipped_unchanged'] : 0;
		$deleted   = isset( $execution['deleted_outofstock'] ) ? (int) $execution['deleted_outofstock'] : 0;
		?>
		<div class="wti-summary">
			<div><span class="<?php echo esc_attr( self::status_badge_class( $status ) ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span><small><?php esc_html_e( 'Status', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( isset( $last_result['finished_at'] ) ? $last_result['finished_at'] : '-' ); ?></strong><small><?php esc_html_e( 'Finished', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( isset( $last_result['catalog_date'] ) ? $last_result['catalog_date'] : '-' ); ?></strong><small><?php esc_html_e( 'Catalog date', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( $created ); ?></strong><small><?php esc_html_e( 'Created', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( $updated ); ?></strong><small><?php esc_html_e( 'Updated', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( $skipped ); ?></strong><small><?php esc_html_e( 'Unchanged', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( $deleted ); ?></strong><small><?php esc_html_e( 'Out of stock', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( isset( $execution['imported_images'] ) ? (int) $execution['imported_images'] : 0 ); ?></strong><small><?php esc_html_e( 'Images', WTI_TEXT_DOMAIN ); ?></small></div>
			<div><strong><?php echo esc_html( $errors ); ?></strong><small><?php esc_html_e( 'Errors', WTI_TEXT_DOMAIN ); ?></small></div>
		</div>
		<?php if ( ! empty( $last_result['message'] ) ) : ?>
			<p class="wti-result-message"><?php echo esc_html( self::clean_log_message( $last_result['message'] ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	private static function render_log_summary() {
		$tail  = trim( WTI_Logger::read_tail( 5000 ) );
		$lines = $tail ? preg_split( '/\r\n|\r|\n/', $tail ) : array();
		$lines = array_values(
			array_filter(
				$lines,
				function ( $line ) {
					return preg_match( '/^\[[^\]]+\]\s*/', trim( (string) $line ) );
				}
			)
		);
		$lines = array_slice( $lines, -8 );

		if ( empty( $lines ) ) {
			echo '<p class="description">' . esc_html__( 'No log entries yet.', WTI_TEXT_DOMAIN ) . '</p>';
			return;
		}

		echo '<ol class="wti-log-tail">';
		foreach ( $lines as $line ) {
			$entry = self::format_log_line( $line );
			echo '<li>';
			echo '<span class="wti-log-time">' . esc_html( $entry['time'] ) . '</span>';
			echo '<span class="wti-log-message">' . esc_html( $entry['message'] ) . '</span>';
			echo '</li>';
		}
		echo '</ol>';
	}

	private static function format_log_line( $line ) {
		$entry = array(
			'time'    => '',
			'message' => trim( (string) $line ),
		);

		if ( preg_match( '/^\[([^\]]+)\]\s*(.*)$/', $entry['message'], $matches ) ) {
			$entry['time']    = $matches[1];
			$entry['message'] = $matches[2];
		}

		$entry['message'] = self::clean_log_message( $entry['message'] );

		if ( '' === $entry['time'] ) {
			$entry['time'] = __( 'Log', WTI_TEXT_DOMAIN );
		}

		return $entry;
	}

	private static function clean_log_message( $message ) {
		$message = preg_replace( '/\s*\{.*$/', '', (string) $message );
		$message = trim( $message );
		$map     = array(
			'AJAX import started.'        => __( 'Sync started.', WTI_TEXT_DOMAIN ),
			'AJAX import completed.'      => __( 'Sync completed.', WTI_TEXT_DOMAIN ),
			'Sync scaffold started.'      => __( 'Feed check started.', WTI_TEXT_DOMAIN ),
			'Sync scaffold completed.'    => __( 'Feed check completed.', WTI_TEXT_DOMAIN ),
			'Feed fetch failed.'          => __( 'Could not download Totobi feed.', WTI_TEXT_DOMAIN ),
			'Feed parse failed.'          => __( 'Could not read Totobi feed.', WTI_TEXT_DOMAIN ),
			'Offer parse failed.'         => __( 'Could not read product list.', WTI_TEXT_DOMAIN ),
			'Scheduled sync skipped because catalog date is unchanged.' => __( 'Automatic sync skipped because Totobi data did not change.', WTI_TEXT_DOMAIN ),
		);

		if ( isset( $map[ $message ] ) ) {
			return $map[ $message ];
		}

		if ( '' === $message ) {
			return __( 'Sync event recorded.', WTI_TEXT_DOMAIN );
		}

		return wp_html_excerpt( $message, 180, '...' );
	}

	private static function status_label( $status ) {
		$labels = array(
			'ok'        => __( 'Completed', WTI_TEXT_DOMAIN ),
			'completed' => __( 'Completed', WTI_TEXT_DOMAIN ),
			'skipped'   => __( 'Skipped', WTI_TEXT_DOMAIN ),
			'error'     => __( 'Error', WTI_TEXT_DOMAIN ),
			'none'      => __( 'No sync yet', WTI_TEXT_DOMAIN ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	private static function status_badge_class( $status ) {
		$status = in_array( $status, array( 'ok', 'completed', 'skipped', 'error' ), true ) ? $status : 'none';

		return 'wti-status-badge wti-status-' . $status;
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

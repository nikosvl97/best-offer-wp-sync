<?php
/**
 * Admin Interface Class
 *
 * @package BestOfferSync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Admin interface for Best Offer Sync
 */
class EnviWeb_BestOffer_Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'cleanup_stale_syncs' ) );
		add_action( 'admin_init', array( $this, 'maybe_cleanup_old_history' ) );
		add_action( 'admin_notices', array( $this, 'display_duplicate_sku_notice' ) );
		add_action( 'wp_ajax_enviweb_bestoffer_delete_log', array( $this, 'ajax_delete_log' ) );
		add_action( 'wp_ajax_bestoffer_dismiss_duplicate_notice', array( $this, 'ajax_dismiss_duplicate_notice' ) );
		add_action( 'wp_ajax_bestoffer_scan_duplicates', array( $this, 'ajax_scan_duplicates' ) );
		add_action( 'wp_ajax_bestoffer_filter_history', array( $this, 'ajax_filter_history' ) );
	}

	/**
	 * Clean up stale "running" syncs
	 * If a sync has been "running" for more than 5 minutes, mark it as failed
	 * Rate-limited to run once every 5 minutes to prevent database overload
	 */
	public function cleanup_stale_syncs() {
		// Rate limit: Only run cleanup once every 5 minutes
		$transient_key = 'bestoffer_cleanup_ran';
		if ( get_transient( $transient_key ) ) {
			return;
		}

		// Set transient BEFORE running query to prevent race conditions
		set_transient( $transient_key, true, 5 * MINUTE_IN_SECONDS );

		global $wpdb;

		$table_name = $wpdb->prefix . 'enviweb_bestoffer_sync_logs';

		// Mark syncs as failed if they've been running for more than 5 minutes
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name}
				SET status = 'failed',
				    error_message = 'Sync interrupted - timed out or terminated unexpectedly'
				WHERE status = 'running'
				AND sync_date < %s",
				date( 'Y-m-d H:i:s', strtotime( '-5 minutes' ) )
			)
		);
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Best Offer Sync', 'best-offer-sync' ),
			__( 'Best Offer Sync', 'best-offer-sync' ),
			'manage_woocommerce',
			'bestoffer-sync',
			array( $this, 'render_admin_page' ),
			'dashicons-update',
			56
		);

		add_submenu_page(
			'bestoffer-sync',
			__( 'Sync Logs', 'best-offer-sync' ),
			__( 'Sync Logs', 'best-offer-sync' ),
			'manage_woocommerce',
			'bestoffer-sync',
			array( $this, 'render_admin_page' )
		);

		add_submenu_page(
			'bestoffer-sync',
			__( 'Run Sync', 'best-offer-sync' ),
			__( 'Run Sync', 'best-offer-sync' ),
			'manage_woocommerce',
			'bestoffer-run-sync',
			array( $this, 'render_sync_page' )
		);

		add_submenu_page(
			'bestoffer-sync',
			__( 'Settings', 'best-offer-sync' ),
			__( 'Settings', 'best-offer-sync' ),
			'manage_woocommerce',
			'bestoffer-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'bestoffer-sync',
			__( 'Product History', 'best-offer-sync' ),
			__( 'Product History', 'best-offer-sync' ),
			'manage_woocommerce',
			'bestoffer-history',
			array( $this, 'render_history_page' )
		);
	}

	/**
	 * Register plugin settings
	 */
	public function register_settings() {
		register_setting(
			'bestoffer_settings',
			'bestoffer_ignore_instock',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		// Product creation settings
		register_setting(
			'bestoffer_settings',
			'bestoffer_enable_product_creation',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_title_format',
			array(
				'type'              => 'string',
				'default'           => '{title} <span style="color: white; visibility:visible;">{ean}</span>',
				// No sanitization - allow HTML for hidden EAN trick
				'sanitize_callback' => null,
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_price_markup',
			array(
				'type'              => 'number',
				'default'           => 1.40,
				'sanitize_callback' => 'floatval',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_feed_identifier',
			array(
				'type'              => 'string',
				'default'           => 'best_offer_xml',
				'sanitize_callback' => 'sanitize_key',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_auto_draft_missing',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_default_user_id',
			array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_auto_claim_products',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		// Sync field settings - what to update during sync
		register_setting(
			'bestoffer_settings',
			'bestoffer_sync_supplier_price',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_sync_stock_status',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_sync_backorder_mode',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_sync_publish_drafts',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'bestoffer_settings',
			'bestoffer_sync_regular_price',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our admin pages
		$allowed_hooks = array(
			'toplevel_page_bestoffer-sync',
			'best-offer-sync_page_bestoffer-run-sync',
			'best-offer-sync_page_bestoffer-settings',
			'best-offer-sync_page_bestoffer-history',
		);

		if ( ! in_array( $hook, $allowed_hooks, true ) && 'product' !== get_post_type() ) {
			return;
		}

		wp_enqueue_style(
			'bestoffer-admin',
			ENVIWEB_BESTOFFER_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ENVIWEB_BESTOFFER_VERSION
		);

		wp_enqueue_script(
			'bestoffer-admin',
			ENVIWEB_BESTOFFER_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ENVIWEB_BESTOFFER_VERSION,
			true
		);

		wp_localize_script(
			'bestoffer-admin',
			'bestofferAdmin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bestoffer_admin_nonce' ),
			)
		);
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		// Pagination parameters for sync logs
		$logs_page = isset( $_GET['logs_page'] ) ? max( 1, intval( $_GET['logs_page'] ) ) : 1;
		$logs_per_page = 20;

		// Get dashboard data with caching (5 minute cache for better performance)
		// Note: We only cache stats and last_sync, not paginated logs
		$cache_key = 'bestoffer_dashboard_stats';
		$cached_data = get_transient( $cache_key );

		if ( $cached_data === false ) {
			// Cache miss - fetch fresh data
			$last_sync = EnviWeb_BestOffer_Logger::get_last_sync();
			$stats     = EnviWeb_BestOffer_Logger::get_sync_stats( 30 );

			// Store in cache for 5 minutes
			$cached_data = array(
				'last_sync' => $last_sync,
				'stats'     => $stats,
			);
			set_transient( $cache_key, $cached_data, 5 * MINUTE_IN_SECONDS );
		} else {
			// Cache hit - extract data
			$last_sync = $cached_data['last_sync'];
			$stats     = $cached_data['stats'];
		}

		// Get paginated logs (not cached to allow pagination)
		$logs_data = EnviWeb_BestOffer_Logger::get_paginated_logs( $logs_per_page, $logs_page );
		$logs = $logs_data['items'];
		$logs_total = $logs_data['total'];
		$logs_total_pages = ceil( $logs_total / $logs_per_page );

		?>
		<div class="wrap bestoffer-admin-wrap">
			<h1><?php esc_html_e( 'Best Offer Sync', 'best-offer-sync' ); ?></h1>

			<div class="bestoffer-dashboard">
				<!-- Stats Cards -->
				<div class="bestoffer-stats-grid">
					<div class="bestoffer-stat-card">
						<div class="bestoffer-stat-icon dashicons dashicons-update"></div>
						<div class="bestoffer-stat-content">
							<h3><?php echo esc_html( $stats->total_syncs ?? 0 ); ?></h3>
							<p><?php esc_html_e( 'Total Syncs (30 days)', 'best-offer-sync' ); ?></p>
						</div>
					</div>

					<div class="bestoffer-stat-card">
						<div class="bestoffer-stat-icon dashicons dashicons-yes-alt"></div>
						<div class="bestoffer-stat-content">
							<h3><?php echo esc_html( $stats->total_updated ?? 0 ); ?></h3>
							<p><?php esc_html_e( 'Products Updated', 'best-offer-sync' ); ?></p>
						</div>
					</div>

					<div class="bestoffer-stat-card">
						<div class="bestoffer-stat-icon dashicons dashicons-warning"></div>
						<div class="bestoffer-stat-content">
							<h3><?php echo esc_html( $stats->total_errors ?? 0 ); ?></h3>
							<p><?php esc_html_e( 'Errors', 'best-offer-sync' ); ?></p>
						</div>
					</div>

					<div class="bestoffer-stat-card">
						<div class="bestoffer-stat-icon dashicons dashicons-clock"></div>
						<div class="bestoffer-stat-content">
							<h3><?php echo esc_html( number_format( $stats->avg_execution_time ?? 0, 2 ) ); ?>s</h3>
							<p><?php esc_html_e( 'Avg Execution Time', 'best-offer-sync' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Last Sync Info -->
				<?php if ( $last_sync ) : ?>
				<div class="bestoffer-last-sync <?php echo esc_attr( 'status-' . $last_sync->status ); ?>">
					<h2><?php esc_html_e( 'Last Sync', 'best-offer-sync' ); ?></h2>
					<div class="bestoffer-last-sync-content">
						<div class="bestoffer-sync-status">
							<span class="status-badge status-<?php echo esc_attr( $last_sync->status ); ?>">
								<?php echo esc_html( ucfirst( $last_sync->status ) ); ?>
							</span>
						</div>
						<div class="bestoffer-sync-details">
							<p><strong><?php esc_html_e( 'Date:', 'best-offer-sync' ); ?></strong> 
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sync->sync_date ) ) ); ?>
							</p>
							<p><strong><?php esc_html_e( 'XML File:', 'best-offer-sync' ); ?></strong> 
								<?php echo esc_html( basename( $last_sync->xml_file ) ); ?>
							</p>
							<?php if ( isset( $last_sync->xml_products ) && $last_sync->xml_products > 0 ) : ?>
							<p><strong><?php esc_html_e( 'XML Products:', 'best-offer-sync' ); ?></strong> 
								<?php echo esc_html( number_format( $last_sync->xml_products ) ); ?>
							</p>
							<?php endif; ?>
							<p><strong><?php esc_html_e( 'Execution Time:', 'best-offer-sync' ); ?></strong> 
								<?php echo esc_html( number_format( $last_sync->execution_time, 2 ) ); ?>s
							</p>
						</div>
						<div class="bestoffer-sync-stats">
							<span class="stat-item">
								<span class="dashicons dashicons-yes"></span>
								<?php echo esc_html( $last_sync->products_updated ); ?> <?php esc_html_e( 'Updated', 'best-offer-sync' ); ?>
							</span>
							<?php if ( isset( $last_sync->products_created ) && $last_sync->products_created > 0 ) : ?>
							<span class="stat-item stat-created">
								<span class="dashicons dashicons-plus-alt"></span>
								<?php echo esc_html( $last_sync->products_created ); ?> <?php esc_html_e( 'Created', 'best-offer-sync' ); ?>
							</span>
							<?php endif; ?>
							<?php if ( isset( $last_sync->products_created_as_draft ) && $last_sync->products_created_as_draft > 0 ) : ?>
							<span class="stat-item stat-draft">
								<span class="dashicons dashicons-edit"></span>
								<?php echo esc_html( $last_sync->products_created_as_draft ); ?> <?php esc_html_e( 'Created (Draft)', 'best-offer-sync' ); ?>
							</span>
							<?php endif; ?>
							<?php if ( isset( $last_sync->products_auto_drafted ) && $last_sync->products_auto_drafted > 0 ) : ?>
							<span class="stat-item stat-auto-drafted">
								<span class="dashicons dashicons-hidden"></span>
								<?php echo esc_html( $last_sync->products_auto_drafted ); ?> <?php esc_html_e( 'Auto-Drafted', 'best-offer-sync' ); ?>
							</span>
							<?php endif; ?>
							<span class="stat-item stat-unchanged">
								<span class="dashicons dashicons-minus"></span>
								<?php echo esc_html( isset( $last_sync->products_unchanged ) ? $last_sync->products_unchanged : 0 ); ?> <?php esc_html_e( 'Unchanged', 'best-offer-sync' ); ?>
							</span>
							<span class="stat-item stat-locked">
								<span class="dashicons dashicons-lock"></span>
								<?php echo esc_html( $last_sync->products_locked ); ?> <?php esc_html_e( 'Locked', 'best-offer-sync' ); ?>
							</span>
							<?php 
							$skipped_empty = isset( $last_sync->products_skipped ) ? $last_sync->products_skipped : 0;
							$skipped_instock = isset( $last_sync->products_skipped_instock ) ? $last_sync->products_skipped_instock : 0;
							if ( $skipped_empty > 0 || $skipped_instock > 0 ) :
							?>
							<span class="stat-item stat-skipped">
								<span class="dashicons dashicons-editor-removeformatting"></span>
								<?php echo esc_html( $skipped_empty + $skipped_instock ); ?> <?php esc_html_e( 'Skipped', 'best-offer-sync' ); ?>
								<?php if ( $skipped_instock > 0 ) : ?>
									<small>(<?php echo esc_html( $skipped_instock ); ?> <?php esc_html_e( 'in-stock', 'best-offer-sync' ); ?>)</small>
								<?php endif; ?>
							</span>
							<?php endif; ?>
							<span class="stat-item">
								<span class="dashicons dashicons-dismiss"></span>
								<?php echo esc_html( $last_sync->products_not_found ); ?> <?php esc_html_e( 'Not Found', 'best-offer-sync' ); ?>
							</span>
							<span class="stat-item">
								<span class="dashicons dashicons-warning"></span>
								<?php echo esc_html( $last_sync->products_errors ); ?> <?php esc_html_e( 'Errors', 'best-offer-sync' ); ?>
							</span>
						</div>
						<?php if ( ! empty( $last_sync->error_message ) ) : ?>
						<div class="bestoffer-error-message">
							<strong><?php esc_html_e( 'Error:', 'best-offer-sync' ); ?></strong>
							<p><?php echo esc_html( $last_sync->error_message ); ?></p>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>

				<!-- Sync Logs Table -->
				<div class="bestoffer-logs-section">
					<h2><?php esc_html_e( 'Sync History', 'best-offer-sync' ); ?></h2>
					<?php if ( ! empty( $logs ) ) : ?>
					<table class="wp-list-table widefat fixed striped bestoffer-logs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Status', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'XML File', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'XML Products', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Updated', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Created', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Published', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Drafted', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Unchanged', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Skipped', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Locked', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Not Found', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Errors', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Time (s)', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'best-offer-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log ) : 
								$skipped_total = ( isset( $log->products_skipped ) ? $log->products_skipped : 0 ) + 
								                 ( isset( $log->products_skipped_instock ) ? $log->products_skipped_instock : 0 );
							?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->sync_date ) ) ); ?></td>
								<td>
									<span class="status-badge status-<?php echo esc_attr( $log->status ); ?>">
										<?php echo esc_html( ucfirst( $log->status ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( basename( $log->xml_file ) ); ?></td>
								<td>
									<?php 
									$xml_products = isset( $log->xml_products ) ? $log->xml_products : 0;
									if ( $xml_products > 0 ) {
										echo esc_html( number_format( $xml_products ) );
									} else {
										echo '<span style="color: #999;">—</span>';
									}
									?>
								</td>
								<td><?php echo esc_html( $log->products_updated ); ?></td>
								<td>
									<?php
									$created = ( isset( $log->products_created ) ? $log->products_created : 0 ) +
									           ( isset( $log->products_created_as_draft ) ? $log->products_created_as_draft : 0 );
									echo esc_html( $created );
									if ( isset( $log->products_created_as_draft ) && $log->products_created_as_draft > 0 ) :
									?>
										<small class="created-breakdown">(<?php echo esc_html( $log->products_created_as_draft ); ?> draft)</small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( isset( $log->products_published ) ? $log->products_published : 0 ); ?></td>
								<td><?php echo esc_html( isset( $log->products_auto_drafted ) ? $log->products_auto_drafted : 0 ); ?></td>
								<td><?php echo esc_html( isset( $log->products_unchanged ) ? $log->products_unchanged : 0 ); ?></td>
								<td>
									<?php echo esc_html( $skipped_total ); ?>
									<?php if ( isset( $log->products_skipped_instock ) && $log->products_skipped_instock > 0 ) : ?>
										<small class="skipped-breakdown">(<?php echo esc_html( $log->products_skipped_instock ); ?> stock)</small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( isset( $log->products_locked ) ? $log->products_locked : 0 ); ?></td>
								<td><?php echo esc_html( $log->products_not_found ); ?></td>
								<td><?php echo esc_html( $log->products_errors ); ?></td>
								<td><?php echo esc_html( number_format( $log->execution_time, 2 ) ); ?></td>
								<td>
									<button class="button button-small bestoffer-delete-log" data-log-id="<?php echo esc_attr( $log->id ); ?>">
										<?php esc_html_e( 'Delete', 'best-offer-sync' ); ?>
									</button>
								</td>
							</tr>
							<?php if ( ! empty( $log->error_message ) ) : ?>
							<tr class="bestoffer-error-row">
								<td colspan="15">
									<strong><?php esc_html_e( 'Error:', 'best-offer-sync' ); ?></strong>
									<?php echo esc_html( $log->error_message ); ?>
								</td>
							</tr>
							<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $logs_total_pages > 1 ) : ?>
					<div class="bestoffer-pagination">
						<span class="bestoffer-pagination-info">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages, 3: total items */
								esc_html__( 'Page %1$d of %2$d (%3$s total syncs)', 'best-offer-sync' ),
								$logs_page,
								$logs_total_pages,
								number_format( $logs_total )
							);
							?>
						</span>
						<span class="bestoffer-pagination-links">
							<?php
							$base_url = admin_url( 'admin.php?page=bestoffer-sync' );

							// First page link
							if ( $logs_page > 2 ) :
								?>
								<a href="<?php echo esc_url( add_query_arg( 'logs_page', 1, $base_url ) ); ?>" class="page-numbers first">&laquo; <?php esc_html_e( 'First', 'best-offer-sync' ); ?></a>
							<?php endif;

							// Previous page link
							if ( $logs_page > 1 ) :
								?>
								<a href="<?php echo esc_url( add_query_arg( 'logs_page', $logs_page - 1, $base_url ) ); ?>" class="page-numbers prev">&lsaquo; <?php esc_html_e( 'Previous', 'best-offer-sync' ); ?></a>
							<?php endif;

							// Page numbers
							$start_page = max( 1, $logs_page - 2 );
							$end_page = min( $logs_total_pages, $logs_page + 2 );

							if ( $start_page > 1 ) :
								?>
								<a href="<?php echo esc_url( add_query_arg( 'logs_page', 1, $base_url ) ); ?>" class="page-numbers">1</a>
								<?php if ( $start_page > 2 ) : ?>
									<span class="page-numbers dots">...</span>
								<?php endif;
							endif;

							for ( $i = $start_page; $i <= $end_page; $i++ ) :
								if ( $i === $logs_page ) :
									?>
									<span class="page-numbers current"><?php echo esc_html( $i ); ?></span>
								<?php else : ?>
									<a href="<?php echo esc_url( add_query_arg( 'logs_page', $i, $base_url ) ); ?>" class="page-numbers"><?php echo esc_html( $i ); ?></a>
								<?php endif;
							endfor;

							if ( $end_page < $logs_total_pages ) :
								if ( $end_page < $logs_total_pages - 1 ) : ?>
									<span class="page-numbers dots">...</span>
								<?php endif; ?>
								<a href="<?php echo esc_url( add_query_arg( 'logs_page', $logs_total_pages, $base_url ) ); ?>" class="page-numbers"><?php echo esc_html( $logs_total_pages ); ?></a>
							<?php endif;

							// Next page link
							if ( $logs_page < $logs_total_pages ) :
								?>
								<a href="<?php echo esc_url( add_query_arg( 'logs_page', $logs_page + 1, $base_url ) ); ?>" class="page-numbers next"><?php esc_html_e( 'Next', 'best-offer-sync' ); ?> &rsaquo;</a>
							<?php endif;

							// Last page link
							if ( $logs_page < $logs_total_pages - 1 ) :
								?>
								<a href="<?php echo esc_url( add_query_arg( 'logs_page', $logs_total_pages, $base_url ) ); ?>" class="page-numbers last"><?php esc_html_e( 'Last', 'best-offer-sync' ); ?> &raquo;</a>
							<?php endif; ?>
						</span>
					</div>
					<?php endif; ?>

					<?php else : ?>
					<p><?php esc_html_e( 'No sync logs found. Run your first sync using WP-CLI.', 'best-offer-sync' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Usage Instructions -->
				<div class="bestoffer-usage">
					<h2><?php esc_html_e( 'Usage', 'best-offer-sync' ); ?></h2>
					<p><?php esc_html_e( 'Use WP-CLI to run synchronization:', 'best-offer-sync' ); ?></p>
					<pre><code>wp bestoffer sync /path/to/best-offer.xml</code></pre>
					<p><?php esc_html_e( 'For more options:', 'best-offer-sync' ); ?></p>
					<pre><code>wp help bestoffer sync</code></pre>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Save settings if form submitted
		if ( isset( $_POST['bestoffer_settings_nonce'] ) ) {
			// Verify nonce - wp_die on failure for security
			if ( ! wp_verify_nonce( $_POST['bestoffer_settings_nonce'], 'bestoffer_save_settings' ) ) {
				wp_die( __( 'Security check failed. Please try again.', 'best-offer-sync' ), __( 'Security Error', 'best-offer-sync' ), array( 'response' => 403 ) );
			}

			// Verify user has permission to save settings
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to modify these settings.', 'best-offer-sync' ), __( 'Permission Denied', 'best-offer-sync' ), array( 'response' => 403 ) );
			}

			$ignore_instock           = isset( $_POST['bestoffer_ignore_instock'] ) ? 1 : 0;
			$enable_creation          = isset( $_POST['bestoffer_enable_product_creation'] ) ? 1 : 0;
			// No sanitization - allow HTML for title format (admin-only, protected by nonce + capability)
			// Example: {title} <span style="color: white; visibility:visible;">{ean}</span>
			$title_format = isset( $_POST['bestoffer_title_format'] ) ? wp_unslash( $_POST['bestoffer_title_format'] ) : '';
			$price_markup             = isset( $_POST['bestoffer_price_markup'] ) ? floatval( $_POST['bestoffer_price_markup'] ) : 1.40;
			$feed_identifier          = isset( $_POST['bestoffer_feed_identifier'] ) ? sanitize_key( $_POST['bestoffer_feed_identifier'] ) : 'best_offer_xml';
			$auto_draft_missing       = isset( $_POST['bestoffer_auto_draft_missing'] ) ? 1 : 0;
			$default_user_id          = isset( $_POST['bestoffer_default_user_id'] ) ? absint( $_POST['bestoffer_default_user_id'] ) : 1;
			$auto_claim_products      = isset( $_POST['bestoffer_auto_claim_products'] ) ? 1 : 0;

			// Sync field settings
			$sync_supplier_price = isset( $_POST['bestoffer_sync_supplier_price'] ) ? 1 : 0;
			$sync_stock_status   = isset( $_POST['bestoffer_sync_stock_status'] ) ? 1 : 0;
			$sync_backorder_mode = isset( $_POST['bestoffer_sync_backorder_mode'] ) ? 1 : 0;
			$sync_publish_drafts = isset( $_POST['bestoffer_sync_publish_drafts'] ) ? 1 : 0;
			$sync_regular_price  = isset( $_POST['bestoffer_sync_regular_price'] ) ? 1 : 0;

			update_option( 'bestoffer_ignore_instock', $ignore_instock );
			update_option( 'bestoffer_enable_product_creation', $enable_creation );
			update_option( 'bestoffer_title_format', $title_format );
			update_option( 'bestoffer_price_markup', $price_markup );
			update_option( 'bestoffer_feed_identifier', $feed_identifier );
			update_option( 'bestoffer_auto_draft_missing', $auto_draft_missing );
			update_option( 'bestoffer_default_user_id', $default_user_id );
			update_option( 'bestoffer_auto_claim_products', $auto_claim_products );

			// Save sync field settings
			update_option( 'bestoffer_sync_supplier_price', $sync_supplier_price );
			update_option( 'bestoffer_sync_stock_status', $sync_stock_status );
			update_option( 'bestoffer_sync_backorder_mode', $sync_backorder_mode );
			update_option( 'bestoffer_sync_publish_drafts', $sync_publish_drafts );
			update_option( 'bestoffer_sync_regular_price', $sync_regular_price );

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully!', 'best-offer-sync' ) . '</p></div>';
		}

		$ignore_instock      = get_option( 'bestoffer_ignore_instock', false );
		$enable_creation     = get_option( 'bestoffer_enable_product_creation', false );
		$title_format        = get_option( 'bestoffer_title_format', '{title} <span style="color: white; visibility:visible;">{ean}</span>' );
		$price_markup        = get_option( 'bestoffer_price_markup', 1.40 );
		$feed_identifier     = get_option( 'bestoffer_feed_identifier', 'best_offer_xml' );
		$auto_draft_missing  = get_option( 'bestoffer_auto_draft_missing', true );
		$default_user_id     = get_option( 'bestoffer_default_user_id', 1 );
		$auto_claim_products = get_option( 'bestoffer_auto_claim_products', true );

		// Sync field settings
		$sync_supplier_price = get_option( 'bestoffer_sync_supplier_price', true );
		$sync_stock_status   = get_option( 'bestoffer_sync_stock_status', true );
		$sync_backorder_mode = get_option( 'bestoffer_sync_backorder_mode', true );
		$sync_publish_drafts = get_option( 'bestoffer_sync_publish_drafts', true );
		$sync_regular_price  = get_option( 'bestoffer_sync_regular_price', false );
		?>
		<div class="wrap bestoffer-admin-wrap">
			<h1><?php esc_html_e( 'Best Offer Sync Settings', 'best-offer-sync' ); ?></h1>

			<div class="bestoffer-settings-page">
				<form method="post" action="">
					<?php wp_nonce_field( 'bestoffer_save_settings', 'bestoffer_settings_nonce' ); ?>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="bestoffer_ignore_instock">
										<?php esc_html_e( 'Ignore In-Stock Products', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_ignore_instock"
											   name="bestoffer_ignore_instock"
											   value="1"
											   <?php checked( $ignore_instock, true ); ?> />
										<?php esc_html_e( 'Skip products that are currently in stock during synchronization', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When enabled, products with stock_status = "instock" will not be updated during sync. This is useful to preserve manual stock settings for products you have in inventory.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="bestoffer_default_user_id">
										<?php esc_html_e( 'Default User for Sync', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<?php
									// Get all users with WooCommerce capabilities
									$users = get_users(
										array(
											'role__in' => array( 'administrator', 'shop_manager' ),
											'orderby'  => 'display_name',
										)
									);
									?>
									<select id="bestoffer_default_user_id" name="bestoffer_default_user_id">
										<?php foreach ( $users as $user ) : ?>
											<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $default_user_id, $user->ID ); ?>>
												<?php echo esc_html( $user->display_name ); ?> (<?php echo esc_html( $user->user_email ); ?>) - ID: <?php echo esc_html( $user->ID ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'All product updates during sync will be performed as this user. This ensures proper audit trails and permissions. The user must have WooCommerce management capabilities.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Product Creation Settings', 'best-offer-sync' ); ?></h2>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="bestoffer_enable_product_creation">
										<?php esc_html_e( 'Enable Product Creation', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_enable_product_creation"
											   name="bestoffer_enable_product_creation"
											   value="1"
											   <?php checked( $enable_creation, true ); ?> />
										<?php esc_html_e( 'Automatically create new products from XML feed', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When enabled, products not found by supplier_sku will be created. When disabled, only existing products are updated.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="bestoffer_title_format">
										<?php esc_html_e( 'Title Format Template', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<input type="text"
										   id="bestoffer_title_format"
										   name="bestoffer_title_format"
										   value="<?php echo esc_attr( $title_format ); ?>"
										   class="regular-text" />
									<p class="description">
										<?php esc_html_e( 'Placeholders: {title}, {ean}, {mpn}, {brandname}', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="bestoffer_price_markup">
										<?php esc_html_e( 'Price Markup', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<input type="number"
										   id="bestoffer_price_markup"
										   name="bestoffer_price_markup"
										   value="<?php echo esc_attr( $price_markup ); ?>"
										   step="0.01"
										   min="1.0"
										   max="5.0" />
									<p class="description">
										<?php esc_html_e( 'Multiplier for supplier price (e.g., 1.40 = 140%)', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="bestoffer_feed_identifier">
										<?php esc_html_e( 'Feed Identifier', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<input type="text"
										   id="bestoffer_feed_identifier"
										   name="bestoffer_feed_identifier"
										   value="<?php echo esc_attr( $feed_identifier ); ?>"
										   class="regular-text" />
									<p class="description">
										<?php esc_html_e( 'Unique identifier stored in _bestoffer_feed_source meta field for tracking ownership', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="bestoffer_auto_draft_missing">
										<?php esc_html_e( 'Auto-Draft Missing Products', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_auto_draft_missing"
											   name="bestoffer_auto_draft_missing"
											   value="1"
											   <?php checked( $auto_draft_missing, true ); ?> />
										<?php esc_html_e( 'Set products to draft if not in XML feed', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Only affects products managed by this feed. Respects lock settings and ignore in-stock option.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="bestoffer_auto_claim_products">
										<?php esc_html_e( 'Auto-Claim Existing Products', 'best-offer-sync' ); ?>
									</label>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_auto_claim_products"
											   name="bestoffer_auto_claim_products"
											   value="1"
											   <?php checked( $auto_claim_products, true ); ?> />
										<?php esc_html_e( 'Automatically claim existing products that match XML data', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php
										esc_html_e(
											'When enabled, the plugin will search for existing products (by SKU, EAN, or similar title) and claim them instead of creating duplicates. Claimed products are tagged with supplier_sku and managed by this feed.',
											'best-offer-sync'
										);
										?>
										<br><strong><?php esc_html_e( 'Matching criteria:', 'best-offer-sync' ); ?></strong>
										<?php esc_html_e( '1) Same SKU (exact match), 2) Same EAN + similar SKU, 3) 95%+ title similarity', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Sync Field Settings', 'best-offer-sync' ); ?></h2>
					<p class="description" style="margin-bottom: 15px;">
						<?php esc_html_e( 'Control which fields are updated during synchronization. Uncheck fields you want to manage manually.', 'best-offer-sync' ); ?>
					</p>

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<?php esc_html_e( 'Supplier Price', 'best-offer-sync' ); ?>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_sync_supplier_price"
											   name="bestoffer_sync_supplier_price"
											   value="1"
											   <?php checked( $sync_supplier_price, true ); ?> />
										<?php esc_html_e( 'Update fs_supplier_price meta field', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Stores the supplier price from XML in the fs_supplier_price custom field.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<?php esc_html_e( 'Regular Price', 'best-offer-sync' ); ?>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_sync_regular_price"
											   name="bestoffer_sync_regular_price"
											   value="1"
											   <?php checked( $sync_regular_price, true ); ?> />
										<?php esc_html_e( 'Update WooCommerce regular price (using markup)', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php
										printf(
											/* translators: %s: current markup value */
											esc_html__( 'Calculates regular price as: supplier_price × %s (markup). Updates _regular_price and _price fields.', 'best-offer-sync' ),
											esc_html( $price_markup )
										);
										?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<?php esc_html_e( 'Stock Status', 'best-offer-sync' ); ?>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_sync_stock_status"
											   name="bestoffer_sync_stock_status"
											   value="1"
											   <?php checked( $sync_stock_status, true ); ?> />
										<?php esc_html_e( 'Set stock status to "On Backorder"', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Sets _stock_status to "onbackorder" for all synced products.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<?php esc_html_e( 'Backorder Mode', 'best-offer-sync' ); ?>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_sync_backorder_mode"
											   name="bestoffer_sync_backorder_mode"
											   value="1"
											   <?php checked( $sync_backorder_mode, true ); ?> />
										<?php esc_html_e( 'Enable backorders and disable stock management', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Sets manage_stock=false and backorders=yes. Products can always be ordered.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<?php esc_html_e( 'Auto-Publish Drafts', 'best-offer-sync' ); ?>
								</th>
								<td>
									<label>
										<input type="checkbox"
											   id="bestoffer_sync_publish_drafts"
											   name="bestoffer_sync_publish_drafts"
											   value="1"
											   <?php checked( $sync_publish_drafts, true ); ?> />
										<?php esc_html_e( 'Automatically publish draft products found in XML', 'best-offer-sync' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'When a draft product matches an XML entry, it will be published automatically.', 'best-offer-sync' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<div class="bestoffer-info-box" style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'What does NOT get updated:', 'best-offer-sync' ); ?></h4>
						<ul style="margin-bottom: 0;">
							<li><?php esc_html_e( 'Product title, description, short description', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'Product SKU (_sku field)', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'Sale price (_sale_price)', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'Categories, tags, attributes', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'Images, weight, dimensions', 'best-offer-sync' ); ?></li>
						</ul>
						<p style="margin-bottom: 0; margin-top: 10px;">
							<strong><?php esc_html_e( 'Block Updates:', 'best-offer-sync' ); ?></strong>
							<?php esc_html_e( 'Use the "Block XML Updates" checkbox in the Product Block Updates plugin to completely skip a product during sync.', 'best-offer-sync' ); ?>
						</p>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Settings', 'best-offer-sync' ); ?>
						</button>
					</p>
				</form>

				<div class="bestoffer-settings-info">
					<h2><?php esc_html_e( 'Settings Information', 'best-offer-sync' ); ?></h2>
					
					<div class="bestoffer-info-box">
						<h3><?php esc_html_e( 'Ignore In-Stock Products', 'best-offer-sync' ); ?></h3>
						<p>
							<strong><?php esc_html_e( 'What it does:', 'best-offer-sync' ); ?></strong><br>
							<?php esc_html_e( 'When this option is enabled, the sync process will skip any products that have their stock status set to "In Stock". This allows you to maintain manual control over products you currently have in your inventory.', 'best-offer-sync' ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Use cases:', 'best-offer-sync' ); ?></strong>
						</p>
						<ul>
							<li><?php esc_html_e( 'You have physical inventory and want to manage those products manually', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'You want to override supplier prices for products you have in stock', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'You want to sync only out-of-stock products to backorder mode', 'best-offer-sync' ); ?></li>
						</ul>
						<p>
							<strong><?php esc_html_e( 'Statistics:', 'best-offer-sync' ); ?></strong><br>
							<?php esc_html_e( 'Ignored in-stock products will be counted in the "Skipped" statistic and logged in the sync history.', 'best-offer-sync' ); ?>
						</p>
					</div>

					<div class="bestoffer-info-box">
						<h3><?php esc_html_e( 'CLI Usage', 'best-offer-sync' ); ?></h3>
						<p><?php esc_html_e( 'The setting will be automatically applied when running sync via WP-CLI:', 'best-offer-sync' ); ?></p>
						<pre><code>wp bestoffer sync /path/to/best-offer.xml</code></pre>
						<p><?php esc_html_e( 'No additional parameters needed. The sync will respect this setting.', 'best-offer-sync' ); ?></p>
					</div>

					<div class="bestoffer-info-box">
						<h3><?php esc_html_e( '🛡️ XML Validation & Safety', 'best-offer-sync' ); ?></h3>
						<p>
							<strong><?php esc_html_e( 'What it does:', 'best-offer-sync' ); ?></strong><br>
							<?php esc_html_e( 'Before processing begins, the plugin automatically validates that the XML file contains a reasonable number of products. This prevents processing incomplete or corrupted XML files that could cause issues.', 'best-offer-sync' ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'How it works:', 'best-offer-sync' ); ?></strong>
						</p>
						<ol>
							<li><?php esc_html_e( 'Counts products in the XML file', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'Compares with your published WooCommerce products', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'If XML has significantly fewer products (< 50% of published), waits 30 seconds and retries', 'best-offer-sync' ); ?></li>
							<li><?php esc_html_e( 'After 3 failed validation attempts, stops the sync to prevent issues', 'best-offer-sync' ); ?></li>
						</ol>
						<p>
							<strong><?php esc_html_e( 'Example scenario:', 'best-offer-sync' ); ?></strong><br>
							<?php esc_html_e( 'If you have 5,000 published products but the XML only contains 1,000, the plugin will wait and retry, assuming the XML file is still being uploaded or generated. This prevents accidentally unpublishing products or processing incomplete data.', 'best-offer-sync' ); ?>
						</p>
						<p>
							<strong><?php esc_html_e( 'Note:', 'best-offer-sync' ); ?></strong><br>
							<?php esc_html_e( 'Validation is automatically skipped for resumed syncs (when using --offset) and dry-run mode. You can also manually skip it with --skip-validation flag (not recommended).', 'best-offer-sync' ); ?>
						</p>
					</div>
				</div>

				<!-- WP-CLI Commands Reference -->
				<div class="bestoffer-cli-commands">
					<h2><?php esc_html_e( 'WP-CLI Commands Reference', 'best-offer-sync' ); ?></h2>
					
					<div class="bestoffer-command-box">
						<h3><?php esc_html_e( '1. Sync Products', 'best-offer-sync' ); ?></h3>
						<p><strong><?php esc_html_e( 'Basic Usage:', 'best-offer-sync' ); ?></strong></p>
						<pre><code>wp bestoffer sync &lt;file&gt;</code></pre>
						
						<p><strong><?php esc_html_e( 'Parameters:', 'best-offer-sync' ); ?></strong></p>
						<ul>
							<li><code>&lt;file&gt;</code> - <?php esc_html_e( 'Path to the XML file (required)', 'best-offer-sync' ); ?></li>
							<li><code>--batch-size=&lt;number&gt;</code> - <?php esc_html_e( 'Products per batch (default: 100)', 'best-offer-sync' ); ?></li>
							<li><code>--offset=&lt;number&gt;</code> - <?php esc_html_e( 'Start from product N (default: 0)', 'best-offer-sync' ); ?></li>
							<li><code>--limit=&lt;number&gt;</code> - <?php esc_html_e( 'Process max N products (default: all)', 'best-offer-sync' ); ?></li>
							<li><code>--user=&lt;id&gt;</code> - <?php esc_html_e( 'Run as specific user ID (default: 390)', 'best-offer-sync' ); ?></li>
							<li><code>--dry-run</code> - <?php esc_html_e( 'Test without making changes', 'best-offer-sync' ); ?></li>
							<li><code>--skip-validation</code> - <?php esc_html_e( 'Skip XML product count validation (not recommended)', 'best-offer-sync' ); ?></li>
						</ul>

						<p><strong><?php esc_html_e( 'Examples:', 'best-offer-sync' ); ?></strong></p>
						<pre><code># Full sync (runs as user 390 by default)
wp bestoffer sync /path/to/best-offer.xml

# Run as different user
wp bestoffer sync /path/to/best-offer.xml --user=1

# Custom batch size
wp bestoffer sync /path/to/best-offer.xml --batch-size=50

# Resume from offset
wp bestoffer sync /path/to/best-offer.xml --offset=1000

# Limit products
wp bestoffer sync /path/to/best-offer.xml --limit=100

# Test mode (no changes)
wp bestoffer sync /path/to/best-offer.xml --dry-run

# Combined parameters with user
wp bestoffer sync /path/to/best-offer.xml --user=390 --batch-size=50 --limit=500</code></pre>

						<p class="bestoffer-user-note">
							<strong>👤 <?php esc_html_e( 'User Context:', 'best-offer-sync' ); ?></strong><br>
							<?php esc_html_e( 'All product updates are performed as the specified user (default: ID 390). This ensures proper audit trails and permissions. The user must have appropriate WooCommerce capabilities.', 'best-offer-sync' ); ?>
						</p>
					</div>

					<div class="bestoffer-command-box">
						<h3><?php esc_html_e( '2. Clear Cache', 'best-offer-sync' ); ?></h3>
						<p><strong><?php esc_html_e( 'Usage:', 'best-offer-sync' ); ?></strong></p>
						<pre><code>wp bestoffer clear-cache</code></pre>
						<p><?php esc_html_e( 'Clears WooCommerce product transients and WordPress object cache.', 'best-offer-sync' ); ?></p>
						
						<p><strong><?php esc_html_e( 'Example:', 'best-offer-sync' ); ?></strong></p>
						<pre><code># Clear cache after sync
wp bestoffer sync /path/to/best-offer.xml
wp bestoffer clear-cache</code></pre>
					</div>

					<div class="bestoffer-command-box">
						<h3><?php esc_html_e( '3. Help & Documentation', 'best-offer-sync' ); ?></h3>
						<p><strong><?php esc_html_e( 'View Command Help:', 'best-offer-sync' ); ?></strong></p>
						<pre><code># General help
wp bestoffer --help

# Sync command help
wp help bestoffer sync

# Clear-cache command help
wp help bestoffer clear-cache</code></pre>
					</div>

					<div class="bestoffer-command-box bestoffer-cron-box">
						<h3><?php esc_html_e( '4. Automated Sync (Cron)', 'best-offer-sync' ); ?></h3>
						<p><?php esc_html_e( 'Set up automatic synchronization:', 'best-offer-sync' ); ?></p>
						
						<p><strong><?php esc_html_e( 'Edit crontab:', 'best-offer-sync' ); ?></strong></p>
						<pre><code>crontab -e</code></pre>

						<p><strong><?php esc_html_e( 'Add cron job (every 6 hours):', 'best-offer-sync' ); ?></strong></p>
						<pre><code>0 */6 * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml >> /var/log/bestoffer-sync.log 2>&1</code></pre>

						<p><strong><?php esc_html_e( 'Other schedules:', 'best-offer-sync' ); ?></strong></p>
						<pre><code># Every hour
0 * * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml

# Daily at 2 AM
0 2 * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml

# Every 30 minutes
*/30 * * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml

# Every Monday at 3 AM
0 3 * * 1 cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml</code></pre>
					</div>

					<div class="bestoffer-command-box bestoffer-useful-commands">
						<h3><?php esc_html_e( '5. Useful WooCommerce Commands', 'best-offer-sync' ); ?></h3>
						<p><?php esc_html_e( 'Additional WP-CLI commands for product management:', 'best-offer-sync' ); ?></p>
						
						<pre><code># List products with supplier_sku
wp post list --post_type=product --meta_key=supplier_sku --fields=ID,post_title

# Count products with supplier_sku
wp post list --post_type=product --meta_key=supplier_sku --format=count

# View product details
wp wc product get &lt;PRODUCT_ID&gt;

# Check product meta
wp post meta list &lt;PRODUCT_ID&gt;

# Lock a product from updates
wp post meta update &lt;PRODUCT_ID&gt; _block_xml_update 1

# Unlock a product
wp post meta delete &lt;PRODUCT_ID&gt; _block_xml_update

# List all locked products
wp post list --post_type=product --meta_key=_block_xml_update --meta_value=1

# Count in-stock products (affected by ignore setting)
wp post list --post_type=product --meta_key=_stock_status --meta_value=instock --format=count</code></pre>
					</div>

					<div class="bestoffer-command-box bestoffer-tips-box">
						<h3><?php esc_html_e( '💡 Tips & Best Practices', 'best-offer-sync' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Test First:', 'best-offer-sync' ); ?></strong> <?php esc_html_e( 'Always use --dry-run before running actual sync', 'best-offer-sync' ); ?></li>
							<li><strong><?php esc_html_e( 'Backup:', 'best-offer-sync' ); ?></strong> <?php esc_html_e( 'Backup your database before major syncs', 'best-offer-sync' ); ?></li>
							<li><strong><?php esc_html_e( 'Monitor Logs:', 'best-offer-sync' ); ?></strong> <?php esc_html_e( 'Check sync logs in this admin dashboard', 'best-offer-sync' ); ?></li>
							<li><strong><?php esc_html_e( 'Performance:', 'best-offer-sync' ); ?></strong> <?php esc_html_e( 'Use smaller batch sizes for large files', 'best-offer-sync' ); ?></li>
							<li><strong><?php esc_html_e( 'Resume:', 'best-offer-sync' ); ?></strong> <?php esc_html_e( 'Use --offset if sync times out', 'best-offer-sync' ); ?></li>
						</ul>
					</div>
				</div>

				<!-- Duplicate SKUs Section -->
				<div id="duplicates" class="bestoffer-duplicates-section">
					<h2><?php esc_html_e( '⚠️ Duplicate SKU Check', 'best-offer-sync' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Products with duplicate supplier_sku values can cause sync issues. Only one product per SKU will be synced.', 'best-offer-sync' ); ?>
					</p>

					<p>
						<button type="button" id="bestoffer-scan-duplicates" class="button button-secondary">
							<span class="dashicons dashicons-search" style="vertical-align: middle;"></span>
							<?php esc_html_e( 'Scan for Duplicates', 'best-offer-sync' ); ?>
						</button>
						<span id="bestoffer-scan-status" style="margin-left: 10px;"></span>
					</p>

					<?php
					$duplicates = self::get_duplicate_skus();
					if ( ! empty( $duplicates ) ) :
						$total_affected = array_sum( array_column( $duplicates, 'count' ) );
					?>
					<div class="bestoffer-duplicates-found" style="margin-top: 15px;">
						<div class="notice notice-warning inline" style="margin: 0 0 15px 0;">
							<p>
								<strong>
									<?php
									printf(
										/* translators: 1: number of duplicate SKUs, 2: number of affected products */
										esc_html__( 'Found %1$d duplicate SKUs affecting %2$d products!', 'best-offer-sync' ),
										count( $duplicates ),
										$total_affected
									);
									?>
								</strong>
							</p>
						</div>

						<table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
							<thead>
								<tr>
									<th style="width: 30%;"><?php esc_html_e( 'Supplier SKU', 'best-offer-sync' ); ?></th>
									<th style="width: 15%;"><?php esc_html_e( 'Count', 'best-offer-sync' ); ?></th>
									<th style="width: 55%;"><?php esc_html_e( 'Product IDs', 'best-offer-sync' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $duplicates as $dup ) : ?>
								<tr>
									<td><code><?php echo esc_html( $dup['supplier_sku'] ); ?></code></td>
									<td><?php echo esc_html( $dup['count'] ); ?></td>
									<td>
										<?php
										$product_ids = explode( ',', $dup['product_ids'] );
										$links = array();
										foreach ( $product_ids as $pid ) {
											$links[] = sprintf(
												'<a href="%s" target="_blank">#%d</a>',
												esc_url( get_edit_post_link( $pid ) ),
												$pid
											);
										}
										echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										?>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<div class="bestoffer-info-box" style="margin-top: 15px; max-width: 800px;">
							<h4><?php esc_html_e( 'How to Fix Duplicates', 'best-offer-sync' ); ?></h4>
							<ol>
								<li><?php esc_html_e( 'Click on each product ID to open the product edit page', 'best-offer-sync' ); ?></li>
								<li><?php esc_html_e( 'Check the "Supplier SKU" field and determine which product should keep it', 'best-offer-sync' ); ?></li>
								<li><?php esc_html_e( 'Remove or change the SKU on duplicate products, or delete them if not needed', 'best-offer-sync' ); ?></li>
								<li><?php esc_html_e( 'Re-scan to verify all duplicates are resolved', 'best-offer-sync' ); ?></li>
							</ol>
							<p>
								<strong><?php esc_html_e( 'WP-CLI Command:', 'best-offer-sync' ); ?></strong>
								<code>wp bestoffer list-duplicates</code>
							</p>
						</div>
					</div>
					<?php else : ?>
					<div id="bestoffer-no-duplicates" class="notice notice-success inline" style="margin: 15px 0 0 0; max-width: 800px;">
						<p><?php esc_html_e( '✅ No duplicate SKUs found! All supplier_sku values are unique.', 'best-offer-sync' ); ?></p>
					</div>
					<?php endif; ?>
				</div>

				<script>
				jQuery(document).ready(function($) {
					$('#bestoffer-scan-duplicates').on('click', function() {
						var $btn = $(this);
						var $status = $('#bestoffer-scan-status');

						$btn.prop('disabled', true);
						$status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> <?php echo esc_js( __( 'Scanning...', 'best-offer-sync' ) ); ?>');

						$.post(ajaxurl, {
							action: 'bestoffer_scan_duplicates',
							nonce: '<?php echo esc_js( wp_create_nonce( 'bestoffer_admin_nonce' ) ); ?>'
						}, function(response) {
							$btn.prop('disabled', false);
							if (response.success) {
								$status.html('<span style="color: green;">✓</span> ' + response.data.message);
								// Reload page to show updated duplicates
								setTimeout(function() {
									location.reload();
								}, 1000);
							} else {
								$status.html('<span style="color: red;">✗</span> ' + (response.data.message || 'Error scanning'));
							}
						}).fail(function() {
							$btn.prop('disabled', false);
							$status.html('<span style="color: red;">✗</span> <?php echo esc_js( __( 'Request failed', 'best-offer-sync' ) ); ?>');
						});
					});
				});
				</script>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sync control page
	 */
	public function render_sync_page() {
		// Get previous XML files from uploads directory
		$upload_dir = wp_upload_dir();
		$xml_dir    = trailingslashit( $upload_dir['basedir'] ) . 'bestoffer-xml';
		$xml_files  = array();

		if ( file_exists( $xml_dir ) && is_dir( $xml_dir ) ) {
			$files = glob( $xml_dir . '/*.xml' );
			if ( $files ) {
				foreach ( $files as $file ) {
					$xml_files[] = array(
						'path'     => $file,
						'filename' => basename( $file ),
						'date'     => date( 'Y-m-d H:i:s', filemtime( $file ) ),
					);
				}
				// Sort by date descending
				usort(
					$xml_files,
					function ( $a, $b ) {
						return strcmp( $b['date'], $a['date'] );
					}
				);
			}
		}

		// Get recent sync history
		$logs = EnviWeb_BestOffer_Logger::get_recent_logs( 10 );

		?>
		<div class="wrap bestoffer-sync-page">
			<h1><?php esc_html_e( 'Run Sync', 'best-offer-sync' ); ?></h1>

			<!-- Notices Container -->
			<div class="bestoffer-notices"></div>

			<!-- File Upload Section -->
			<div class="bestoffer-upload-section">
				<h2>
					<span class="dashicons dashicons-upload"></span>
					<?php esc_html_e( 'Upload XML File', 'best-offer-sync' ); ?>
				</h2>

				<!-- File Upload Zone -->
				<div class="bestoffer-file-upload-zone" onclick="document.getElementById('bestoffer-xml-file').click()">
					<span class="dashicons dashicons-cloud-upload"></span>
					<p>
						<strong><?php esc_html_e( 'Click to upload XML file', 'best-offer-sync' ); ?></strong>
						<?php esc_html_e( 'or drag and drop here', 'best-offer-sync' ); ?>
					</p>
					<p><?php esc_html_e( 'Maximum file size: 50MB', 'best-offer-sync' ); ?></p>
					<input type="file" id="bestoffer-xml-file" accept=".xml" style="display: none;" />
				</div>

				<!-- Upload Progress -->
				<div id="bestoffer-upload-progress" class="bestoffer-upload-progress">
					<div class="bestoffer-progress-bar-container">
						<div class="bestoffer-progress-bar">
							<span class="progress-text">0%</span>
						</div>
					</div>
				</div>

				<!-- Previous Files Selector -->
				<?php if ( ! empty( $xml_files ) ) : ?>
				<div class="bestoffer-file-select-section">
					<label for="bestoffer-select-xml"><?php esc_html_e( 'Or select from previously uploaded files:', 'best-offer-sync' ); ?></label>
					<select id="bestoffer-select-xml">
						<option value=""><?php esc_html_e( '-- Select a file --', 'best-offer-sync' ); ?></option>
						<?php foreach ( $xml_files as $file ) : ?>
							<option value="<?php echo esc_attr( $file['path'] ); ?>">
								<?php echo esc_html( $file['filename'] ); ?> (<?php echo esc_html( $file['date'] ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<!-- Current File Info -->
				<div id="bestoffer-file-info" class="bestoffer-file-info">
					<p>
						<strong><?php esc_html_e( 'Current File:', 'best-offer-sync' ); ?></strong>
						<span id="bestoffer-current-file">-</span>
					</p>
					<p>
						<strong><?php esc_html_e( 'Products Found:', 'best-offer-sync' ); ?></strong>
						<span id="bestoffer-product-count">-</span>
					</p>
				</div>
			</div>

			<!-- Sync Controls -->
			<div class="bestoffer-sync-controls">
				<div class="bestoffer-control-row">
					<label>
						<input type="checkbox" id="bestoffer-dry-run" />
						<?php esc_html_e( 'Dry Run Mode (no changes will be made)', 'best-offer-sync' ); ?>
					</label>
				</div>

				<div class="bestoffer-button-group">
					<button id="bestoffer-start-sync" class="bestoffer-sync-button primary" disabled>
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Start Sync', 'best-offer-sync' ); ?>
					</button>

					<button id="bestoffer-cancel-sync" class="bestoffer-sync-button danger">
						<span class="dashicons dashicons-no"></span>
						<?php esc_html_e( 'Cancel Sync', 'best-offer-sync' ); ?>
					</button>
				</div>
			</div>

			<!-- Progress Section -->
			<div id="bestoffer-sync-progress" class="bestoffer-progress-section">
				<h2>
					<?php esc_html_e( 'Sync Progress', 'best-offer-sync' ); ?>
					<span id="bestoffer-sync-status" class="bestoffer-sync-status">
						<span class="dashicons dashicons-update"></span>
						<span><?php esc_html_e( 'Ready', 'best-offer-sync' ); ?></span>
					</span>
				</h2>

				<!-- Progress Bar -->
				<div class="bestoffer-progress-bar-container">
					<div id="bestoffer-progress-bar" class="bestoffer-progress-bar" style="width: 0%;">
						<span id="bestoffer-progress-text" class="bestoffer-progress-text">0%</span>
					</div>
				</div>

				<!-- Progress Info -->
				<div class="bestoffer-progress-info">
					<div class="bestoffer-progress-info-item">
						<strong><?php esc_html_e( 'Processed', 'best-offer-sync' ); ?></strong>
						<span><span id="bestoffer-processed">0</span> / <span id="bestoffer-total">0</span></span>
					</div>

					<div class="bestoffer-progress-info-item">
						<strong><?php esc_html_e( 'Current Batch', 'best-offer-sync' ); ?></strong>
						<span>#<span id="bestoffer-current-batch">0</span></span>
					</div>

					<div class="bestoffer-progress-info-item">
						<strong><?php esc_html_e( 'Elapsed Time', 'best-offer-sync' ); ?></strong>
						<span id="bestoffer-elapsed-time">0m 0s</span>
					</div>
				</div>

				<!-- Live Statistics -->
				<div class="bestoffer-live-stats">
					<div class="bestoffer-live-stat stat-updated">
						<span class="stat-label"><?php esc_html_e( 'Updated', 'best-offer-sync' ); ?></span>
						<span id="bestoffer-stat-updated" class="stat-value">0</span>
					</div>

					<div class="bestoffer-live-stat stat-created">
						<span class="stat-label"><?php esc_html_e( 'Created', 'best-offer-sync' ); ?></span>
						<span id="bestoffer-stat-created" class="stat-value">0</span>
					</div>

					<div class="bestoffer-live-stat stat-created-draft">
						<span class="stat-label"><?php esc_html_e( 'Created (Draft)', 'best-offer-sync' ); ?></span>
						<span id="bestoffer-stat-created-draft" class="stat-value">0</span>
					</div>

					<div class="bestoffer-live-stat">
						<span class="stat-label"><?php esc_html_e( 'Unchanged', 'best-offer-sync' ); ?></span>
						<span id="bestoffer-stat-unchanged" class="stat-value">0</span>
					</div>

					<div class="bestoffer-live-stat">
						<span class="stat-label"><?php esc_html_e( 'Locked', 'best-offer-sync' ); ?></span>
						<span id="bestoffer-stat-locked" class="stat-value">0</span>
					</div>

					<div class="bestoffer-live-stat stat-errors">
						<span class="stat-label"><?php esc_html_e( 'Errors', 'best-offer-sync' ); ?></span>
						<span id="bestoffer-stat-errors" class="stat-value">0</span>
					</div>

					<div class="bestoffer-live-stat">
						<span class="stat-label"><?php esc_html_e( 'Not Found', 'best-offer-sync' ); ?></span>
						<span id="bestoffer-stat-not-found" class="stat-value">0</span>
					</div>
				</div>
			</div>

			<!-- Sync History Section -->
			<div class="bestoffer-history-section">
				<h2>
					<?php esc_html_e( 'Recent Sync History', 'best-offer-sync' ); ?>
					<button id="bestoffer-refresh-history" class="bestoffer-sync-button secondary bestoffer-refresh-button">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh', 'best-offer-sync' ); ?>
					</button>
				</h2>

				<div class="bestoffer-history-table-wrapper">
					<?php if ( ! empty( $logs ) ) : ?>
						<table class="bestoffer-sync-history-table widefat">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'best-offer-sync' ); ?></th>
									<th><?php esc_html_e( 'File', 'best-offer-sync' ); ?></th>
									<th><?php esc_html_e( 'Status', 'best-offer-sync' ); ?></th>
									<th><?php esc_html_e( 'Statistics', 'best-offer-sync' ); ?></th>
									<th><?php esc_html_e( 'Duration', 'best-offer-sync' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $logs as $log ) : ?>
									<tr>
										<td><?php echo esc_html( $log->sync_date ); ?></td>
										<td class="file-name"><?php echo esc_html( basename( $log->xml_file ) ); ?></td>
										<td>
											<span class="status-badge status-<?php echo esc_attr( $log->status ); ?>">
												<?php echo esc_html( ucfirst( $log->status ) ); ?>
											</span>
										</td>
										<td>
											<div class="sync-stats">
												<?php if ( $log->products_updated > 0 ) : ?>
													<span class="stat-success">
														<?php
														/* translators: %d: number of products */
														echo esc_html( sprintf( __( 'Updated: %d', 'best-offer-sync' ), $log->products_updated ) );
														?>
													</span>
												<?php endif; ?>
												<?php if ( $log->products_created > 0 ) : ?>
													<span class="stat-success">
														<?php
														/* translators: %d: number of products */
														echo esc_html( sprintf( __( 'Created: %d', 'best-offer-sync' ), $log->products_created ) );
														?>
													</span>
												<?php endif; ?>
												<?php if ( $log->products_locked > 0 ) : ?>
													<span class="stat-warning">
														<?php
														/* translators: %d: number of products */
														echo esc_html( sprintf( __( 'Locked: %d', 'best-offer-sync' ), $log->products_locked ) );
														?>
													</span>
												<?php endif; ?>
												<?php if ( $log->products_errors > 0 ) : ?>
													<span class="stat-error">
														<?php
														/* translators: %d: number of products */
														echo esc_html( sprintf( __( 'Errors: %d', 'best-offer-sync' ), $log->products_errors ) );
														?>
													</span>
												<?php endif; ?>
											</div>
										</td>
										<td><?php echo esc_html( $log->execution_time ); ?>s</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<div class="bestoffer-empty-state">
							<span class="dashicons dashicons-info"></span>
							<h3><?php esc_html_e( 'No Sync History', 'best-offer-sync' ); ?></h3>
							<p><?php esc_html_e( 'Run your first sync to see the history here', 'best-offer-sync' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler to delete log
	 */
	public function ajax_delete_log() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$log_id = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;

		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => 'Invalid log ID' ) );
		}

		global $wpdb;
		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$deleted = $wpdb->delete( $table_name, array( 'id' => $log_id ), array( '%d' ) );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => 'Log deleted successfully' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to delete log' ) );
		}
	}

	/**
	 * Display admin notice for duplicate SKUs
	 */
	public function display_duplicate_sku_notice() {
		// Only show on Best Offer Sync pages and WooCommerce products
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array(
			'toplevel_page_bestoffer-sync',
			'best-offer-sync_page_bestoffer-settings',
			'best-offer-sync_page_bestoffer-run-sync',
			'edit-product',
			'product',
		);

		if ( ! in_array( $screen->id, $allowed_screens ) ) {
			return;
		}

		// Check if notice was dismissed
		if ( get_transient( 'bestoffer_duplicate_notice_dismissed' ) ) {
			return;
		}

		// Get duplicate SKUs
		$duplicates = get_transient( 'bestoffer_duplicate_skus' );

		if ( empty( $duplicates ) ) {
			return;
		}

		$dup_count = count( $duplicates );
		$total_products = 0;
		foreach ( $duplicates as $product_ids ) {
			$total_products += count( $product_ids );
		}

		?>
		<div class="notice notice-warning is-dismissible bestoffer-duplicate-notice" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bestoffer_admin_nonce' ) ); ?>">
			<p>
				<strong><?php esc_html_e( 'Best Offer Sync: Duplicate SKUs Detected!', 'best-offer-sync' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: number of duplicate SKUs, 2: number of affected products */
					esc_html__( 'Found %1$d duplicate supplier_sku values affecting %2$d products. Only one product per SKU will be synced.', 'best-offer-sync' ),
					$dup_count,
					$total_products
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bestoffer-settings#duplicates' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'View Duplicates', 'best-offer-sync' ); ?>
				</a>
				<button type="button" class="button bestoffer-dismiss-duplicate-notice">
					<?php esc_html_e( 'Dismiss for 24 hours', 'best-offer-sync' ); ?>
				</button>
			</p>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('.bestoffer-dismiss-duplicate-notice').on('click', function() {
				var $notice = $(this).closest('.bestoffer-duplicate-notice');
				$.post(ajaxurl, {
					action: 'bestoffer_dismiss_duplicate_notice',
					nonce: $notice.data('nonce')
				});
				$notice.fadeOut();
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler to dismiss duplicate notice
	 */
	public function ajax_dismiss_duplicate_notice() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		// Dismiss for 24 hours
		set_transient( 'bestoffer_duplicate_notice_dismissed', true, DAY_IN_SECONDS );

		wp_send_json_success();
	}

	/**
	 * AJAX handler to scan for duplicates
	 */
	public function ajax_scan_duplicates() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;

		// Find all duplicate SKUs
		$duplicates = $wpdb->get_results(
			"SELECT meta_value as supplier_sku,
					GROUP_CONCAT(post_id ORDER BY post_id ASC) as product_ids,
					COUNT(*) as count
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE pm.meta_key = 'supplier_sku'
			AND pm.meta_value != ''
			AND p.post_type = 'product'
			AND p.post_status != 'trash'
			GROUP BY pm.meta_value
			HAVING count > 1
			ORDER BY count DESC, supplier_sku ASC",
			ARRAY_A
		);

		if ( empty( $duplicates ) ) {
			delete_transient( 'bestoffer_duplicate_skus' );
			delete_transient( 'bestoffer_duplicate_notice_dismissed' );
			wp_send_json_success( array(
				'message'    => __( 'No duplicate SKUs found!', 'best-offer-sync' ),
				'duplicates' => array(),
			) );
		}

		// Store in transient
		$duplicate_data = array();
		foreach ( $duplicates as $dup ) {
			$duplicate_data[ $dup['supplier_sku'] ] = array_map( 'intval', explode( ',', $dup['product_ids'] ) );
		}
		set_transient( 'bestoffer_duplicate_skus', $duplicate_data, WEEK_IN_SECONDS );
		delete_transient( 'bestoffer_duplicate_notice_dismissed' );

		wp_send_json_success( array(
			'message'    => sprintf(
				/* translators: %d: number of duplicate SKUs */
				__( 'Found %d duplicate SKUs!', 'best-offer-sync' ),
				count( $duplicates )
			),
			'duplicates' => $duplicates,
		) );
	}

	/**
	 * Get duplicate SKUs from database
	 *
	 * @return array Array of duplicate SKUs with product IDs
	 */
	public static function get_duplicate_skus() {
		global $wpdb;

		$duplicates = $wpdb->get_results(
			"SELECT meta_value as supplier_sku,
					GROUP_CONCAT(post_id ORDER BY post_id ASC SEPARATOR ',') as product_ids,
					COUNT(*) as count
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE pm.meta_key = 'supplier_sku'
			AND pm.meta_value != ''
			AND p.post_type = 'product'
			AND p.post_status != 'trash'
			GROUP BY pm.meta_value
			HAVING count > 1
			ORDER BY count DESC, supplier_sku ASC",
			ARRAY_A
		);

		return $duplicates ? $duplicates : array();
	}

	/**
	 * Maybe cleanup old history records (rate-limited to once per day)
	 */
	public function maybe_cleanup_old_history() {
		$transient_key = 'bestoffer_history_cleanup_ran';
		if ( get_transient( $transient_key ) ) {
			return;
		}

		// Set transient before running to prevent race conditions
		set_transient( $transient_key, true, DAY_IN_SECONDS );

		// Cleanup records older than 90 days (extended from 7 to allow viewing full history)
		$deleted = EnviWeb_BestOffer_Database::cleanup_old_history( 90 );

		if ( $deleted > 0 ) {
			error_log( sprintf( 'Best Offer Sync: Cleaned up %d old history records', $deleted ) );
		}
	}

	/**
	 * Render product history page
	 */
	public function render_history_page() {
		// Get filter parameters
		$days = isset( $_GET['days'] ) ? intval( $_GET['days'] ) : 7;
		$change_type = isset( $_GET['change_type'] ) ? sanitize_text_field( $_GET['change_type'] ) : '';
		$search = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
		$page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$per_page = 50;

		// Get statistics
		$stats = EnviWeb_BestOffer_Logger::get_history_stats( $days );

		// Get change types for filter dropdown
		$change_types = EnviWeb_BestOffer_Logger::get_change_types( $days );

		// Get history data
		$history_data = EnviWeb_BestOffer_Logger::get_update_history( array(
			'per_page'    => $per_page,
			'page'        => $page,
			'days'        => $days,
			'change_type' => $change_type,
			'search'      => $search,
		) );

		$history = $history_data['items'];
		$total = $history_data['total'];
		$total_pages = ceil( $total / $per_page );

		?>
		<div class="wrap bestoffer-admin-wrap bestoffer-history-wrap">
			<h1><?php esc_html_e( 'Product Update History', 'best-offer-sync' ); ?></h1>

			<!-- Statistics Cards -->
			<div class="bestoffer-history-stats">
				<div class="bestoffer-stat-card">
					<div class="bestoffer-stat-icon dashicons dashicons-edit"></div>
					<div class="bestoffer-stat-content">
						<h3><?php echo esc_html( number_format( $stats->total_changes ?? 0 ) ); ?></h3>
						<p><?php esc_html_e( 'Total Changes', 'best-offer-sync' ); ?></p>
					</div>
				</div>

				<div class="bestoffer-stat-card">
					<div class="bestoffer-stat-icon dashicons dashicons-products"></div>
					<div class="bestoffer-stat-content">
						<h3><?php echo esc_html( number_format( $stats->products_affected ?? 0 ) ); ?></h3>
						<p><?php esc_html_e( 'Products Affected', 'best-offer-sync' ); ?></p>
					</div>
				</div>

				<div class="bestoffer-stat-card">
					<div class="bestoffer-stat-icon dashicons dashicons-money-alt"></div>
					<div class="bestoffer-stat-content">
						<h3><?php echo esc_html( number_format( $stats->price_changes ?? 0 ) ); ?></h3>
						<p><?php esc_html_e( 'Price Changes', 'best-offer-sync' ); ?></p>
					</div>
				</div>

				<div class="bestoffer-stat-card">
					<div class="bestoffer-stat-icon dashicons dashicons-visibility"></div>
					<div class="bestoffer-stat-content">
						<h3><?php echo esc_html( number_format( $stats->status_changes ?? 0 ) ); ?></h3>
						<p><?php esc_html_e( 'Status Changes', 'best-offer-sync' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Filters -->
			<div class="bestoffer-history-filters">
				<form method="get" action="">
					<input type="hidden" name="page" value="bestoffer-history" />

					<div class="bestoffer-filter-row">
						<div class="bestoffer-filter-item">
							<label for="days"><?php esc_html_e( 'Time Period', 'best-offer-sync' ); ?></label>
							<select name="days" id="days">
								<option value="1" <?php selected( $days, 1 ); ?>><?php esc_html_e( 'Last 24 hours', 'best-offer-sync' ); ?></option>
								<option value="3" <?php selected( $days, 3 ); ?>><?php esc_html_e( 'Last 3 days', 'best-offer-sync' ); ?></option>
								<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'best-offer-sync' ); ?></option>
								<option value="14" <?php selected( $days, 14 ); ?>><?php esc_html_e( 'Last 14 days', 'best-offer-sync' ); ?></option>
								<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'best-offer-sync' ); ?></option>
								<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'best-offer-sync' ); ?></option>
								<option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'best-offer-sync' ); ?></option>
							</select>
						</div>

						<div class="bestoffer-filter-item">
							<label for="change_type"><?php esc_html_e( 'Change Type', 'best-offer-sync' ); ?></label>
							<select name="change_type" id="change_type">
								<option value=""><?php esc_html_e( 'All Changes', 'best-offer-sync' ); ?></option>
								<?php foreach ( $change_types as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $change_type, $type ); ?>>
										<?php echo esc_html( EnviWeb_BestOffer_Logger::get_change_type_label( $type ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="bestoffer-filter-item bestoffer-search-item">
							<label for="search"><?php esc_html_e( 'Search', 'best-offer-sync' ); ?></label>
							<input type="text" name="search" id="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'SKU or product name...', 'best-offer-sync' ); ?>" />
						</div>

						<div class="bestoffer-filter-item bestoffer-filter-buttons">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'best-offer-sync' ); ?></button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=bestoffer-history' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'best-offer-sync' ); ?></a>
						</div>
					</div>
				</form>
			</div>

			<!-- Results count -->
			<div class="bestoffer-history-count">
				<?php
				if ( $days === 0 ) {
					printf(
						/* translators: 1: number of results */
						esc_html__( 'Showing %1$s changes (all time)', 'best-offer-sync' ),
						'<strong>' . number_format( $total ) . '</strong>'
					);
				} else {
					printf(
						/* translators: 1: number of results, 2: time period */
						esc_html__( 'Showing %1$s changes in the last %2$s', 'best-offer-sync' ),
						'<strong>' . number_format( $total ) . '</strong>',
						sprintf( _n( '%d day', '%d days', $days, 'best-offer-sync' ), $days )
					);
				}
				?>
			</div>

			<!-- History Table -->
			<?php if ( ! empty( $history ) ) : ?>
			<table class="wp-list-table widefat fixed striped bestoffer-history-table">
				<thead>
					<tr>
						<th class="column-date"><?php esc_html_e( 'Date/Time', 'best-offer-sync' ); ?></th>
						<th class="column-product"><?php esc_html_e( 'Product', 'best-offer-sync' ); ?></th>
						<th class="column-sku"><?php esc_html_e( 'SKU', 'best-offer-sync' ); ?></th>
						<th class="column-change"><?php esc_html_e( 'Change Type', 'best-offer-sync' ); ?></th>
						<th class="column-old"><?php esc_html_e( 'Old Value', 'best-offer-sync' ); ?></th>
						<th class="column-new"><?php esc_html_e( 'New Value', 'best-offer-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $item ) : ?>
					<tr class="history-row change-type-<?php echo esc_attr( $item->field_changed ); ?>">
						<td class="column-date">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->sync_date ) ) ); ?>
						</td>
						<td class="column-product">
							<?php if ( $item->product_id > 0 && $item->product_title ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $item->product_id ) ); ?>" target="_blank">
									<?php echo esc_html( $item->product_title ); ?>
								</a>
								<span class="product-id">#<?php echo esc_html( $item->product_id ); ?></span>
								<?php if ( $item->product_status === 'draft' ) : ?>
									<span class="product-status-badge draft"><?php esc_html_e( 'Draft', 'best-offer-sync' ); ?></span>
								<?php endif; ?>
							<?php elseif ( $item->product_id > 0 ) : ?>
								<span class="deleted-product"><?php esc_html_e( '(Deleted)', 'best-offer-sync' ); ?> #<?php echo esc_html( $item->product_id ); ?></span>
							<?php else : ?>
								<span class="no-product">—</span>
							<?php endif; ?>
						</td>
						<td class="column-sku">
							<code><?php echo esc_html( $item->supplier_sku ); ?></code>
						</td>
						<td class="column-change">
							<span class="change-type-badge change-<?php echo esc_attr( $item->field_changed ); ?>">
								<?php echo esc_html( EnviWeb_BestOffer_Logger::get_change_type_label( $item->field_changed ) ); ?>
							</span>
						</td>
						<td class="column-old">
							<?php echo esc_html( $this->format_history_value( $item->old_value, $item->field_changed ) ); ?>
						</td>
						<td class="column-new">
							<?php echo esc_html( $this->format_history_value( $item->new_value, $item->field_changed ) ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
			<div class="bestoffer-pagination">
				<span class="bestoffer-pagination-info">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages, 3: total items */
						esc_html__( 'Page %1$d of %2$d (%3$s total)', 'best-offer-sync' ),
						$page,
						$total_pages,
						number_format( $total )
					);
					?>
				</span>
				<span class="bestoffer-pagination-links">
					<?php
					// Build base URL preserving all current filters
					$base_url = admin_url( 'admin.php' );
					$query_args = array(
						'page' => 'bestoffer-history',
						'days' => $days,
					);
					if ( ! empty( $change_type ) ) {
						$query_args['change_type'] = $change_type;
					}
					if ( ! empty( $search ) ) {
						$query_args['search'] = $search;
					}

					// First page link
					if ( $page > 2 ) :
						$first_url = add_query_arg( array_merge( $query_args, array( 'paged' => 1 ) ), $base_url );
						?>
						<a href="<?php echo esc_url( $first_url ); ?>" class="page-numbers first" title="<?php esc_attr_e( 'First page', 'best-offer-sync' ); ?>">&laquo; <?php esc_html_e( 'First', 'best-offer-sync' ); ?></a>
					<?php endif;

					// Previous page link
					if ( $page > 1 ) :
						$prev_url = add_query_arg( array_merge( $query_args, array( 'paged' => $page - 1 ) ), $base_url );
						?>
						<a href="<?php echo esc_url( $prev_url ); ?>" class="page-numbers prev">&lsaquo; <?php esc_html_e( 'Previous', 'best-offer-sync' ); ?></a>
					<?php endif;

					// Page number links
					$start_page = max( 1, $page - 2 );
					$end_page = min( $total_pages, $page + 2 );

					// Always show first page
					if ( $start_page > 1 ) :
						$page_url = add_query_arg( array_merge( $query_args, array( 'paged' => 1 ) ), $base_url );
						?>
						<a href="<?php echo esc_url( $page_url ); ?>" class="page-numbers">1</a>
						<?php if ( $start_page > 2 ) : ?>
							<span class="page-numbers dots">...</span>
						<?php endif;
					endif;

					// Page numbers around current
					for ( $i = $start_page; $i <= $end_page; $i++ ) :
						if ( $i === $page ) :
							?>
							<span class="page-numbers current"><?php echo esc_html( $i ); ?></span>
						<?php else :
							$page_url = add_query_arg( array_merge( $query_args, array( 'paged' => $i ) ), $base_url );
							?>
							<a href="<?php echo esc_url( $page_url ); ?>" class="page-numbers"><?php echo esc_html( $i ); ?></a>
						<?php endif;
					endfor;

					// Always show last page
					if ( $end_page < $total_pages ) :
						if ( $end_page < $total_pages - 1 ) : ?>
							<span class="page-numbers dots">...</span>
						<?php endif;
						$page_url = add_query_arg( array_merge( $query_args, array( 'paged' => $total_pages ) ), $base_url );
						?>
						<a href="<?php echo esc_url( $page_url ); ?>" class="page-numbers"><?php echo esc_html( $total_pages ); ?></a>
					<?php endif;

					// Next page link
					if ( $page < $total_pages ) :
						$next_url = add_query_arg( array_merge( $query_args, array( 'paged' => $page + 1 ) ), $base_url );
						?>
						<a href="<?php echo esc_url( $next_url ); ?>" class="page-numbers next"><?php esc_html_e( 'Next', 'best-offer-sync' ); ?> &rsaquo;</a>
					<?php endif;

					// Last page link
					if ( $page < $total_pages - 1 ) :
						$last_url = add_query_arg( array_merge( $query_args, array( 'paged' => $total_pages ) ), $base_url );
						?>
						<a href="<?php echo esc_url( $last_url ); ?>" class="page-numbers last"><?php esc_html_e( 'Last', 'best-offer-sync' ); ?> &raquo;</a>
					<?php endif; ?>
				</span>
			</div>
			<?php endif; ?>

			<?php else : ?>
			<div class="bestoffer-no-history">
				<span class="dashicons dashicons-info"></span>
				<h3><?php esc_html_e( 'No Changes Found', 'best-offer-sync' ); ?></h3>
				<p><?php esc_html_e( 'No product changes have been recorded in the selected time period.', 'best-offer-sync' ); ?></p>
				<p><?php esc_html_e( 'Run a sync to see product update history here.', 'best-offer-sync' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- Info Box -->
			<div class="bestoffer-history-info">
				<h4><?php esc_html_e( 'About Product History', 'best-offer-sync' ); ?></h4>
				<p><?php esc_html_e( 'This page shows all product changes made during synchronization. History is automatically cleaned up after 90 days to prevent database bloat.', 'best-offer-sync' ); ?></p>
				<p><strong><?php esc_html_e( 'Change types:', 'best-offer-sync' ); ?></strong></p>
				<ul>
					<li><strong><?php esc_html_e( 'Supplier Price', 'best-offer-sync' ); ?>:</strong> <?php esc_html_e( 'The fs_supplier_price meta field was updated', 'best-offer-sync' ); ?></li>
					<li><strong><?php esc_html_e( 'Status Change', 'best-offer-sync' ); ?>:</strong> <?php esc_html_e( 'Product status changed (e.g., draft to publish)', 'best-offer-sync' ); ?></li>
					<li><strong><?php esc_html_e( 'Product Created', 'best-offer-sync' ); ?>:</strong> <?php esc_html_e( 'A new product was created from XML', 'best-offer-sync' ); ?></li>
					<li><strong><?php esc_html_e( 'Auto-Drafted', 'best-offer-sync' ); ?>:</strong> <?php esc_html_e( 'Product was set to draft because it was not in the XML feed', 'best-offer-sync' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Format history value for display
	 *
	 * @param string $value Raw value (possibly serialized).
	 * @param string $field_changed Field type.
	 * @return string Formatted value
	 */
	private function format_history_value( $value, $field_changed ) {
		// Try to unserialize
		$unserialized = maybe_unserialize( $value );

		if ( is_array( $unserialized ) ) {
			// Handle specific array formats
			if ( isset( $unserialized['status'] ) ) {
				return $unserialized['status'];
			}
			if ( isset( $unserialized['reason'] ) ) {
				return $unserialized['reason'];
			}
			if ( isset( $unserialized['images'] ) ) {
				return sprintf( __( '%d images', 'best-offer-sync' ), $unserialized['images'] );
			}
			return wp_json_encode( $unserialized );
		}

		// Format based on field type
		if ( in_array( $field_changed, array( 'fs_supplier_price', 'regular_price' ), true ) ) {
			if ( is_numeric( $value ) && $value !== '' ) {
				return '€' . number_format( (float) $value, 2 );
			}
		}

		// Status values
		if ( $field_changed === 'post_status' ) {
			$statuses = array(
				'publish' => __( 'Published', 'best-offer-sync' ),
				'draft'   => __( 'Draft', 'best-offer-sync' ),
				'pending' => __( 'Pending', 'best-offer-sync' ),
				'private' => __( 'Private', 'best-offer-sync' ),
			);
			return isset( $statuses[ $value ] ) ? $statuses[ $value ] : $value;
		}

		// Backorders
		if ( $field_changed === 'backorders' ) {
			$backorder_values = array(
				'no'     => __( 'Do not allow', 'best-offer-sync' ),
				'notify' => __( 'Allow, but notify', 'best-offer-sync' ),
				'yes'    => __( 'Allow', 'best-offer-sync' ),
			);
			return isset( $backorder_values[ $value ] ) ? $backorder_values[ $value ] : $value;
		}

		// Stock status
		if ( $field_changed === 'stock_status' ) {
			$stock_values = array(
				'instock'     => __( 'In stock', 'best-offer-sync' ),
				'outofstock'  => __( 'Out of stock', 'best-offer-sync' ),
				'onbackorder' => __( 'On backorder', 'best-offer-sync' ),
			);
			return isset( $stock_values[ $value ] ) ? $stock_values[ $value ] : $value;
		}

		// Empty value
		if ( $value === '' || $value === null ) {
			return '—';
		}

		return $value;
	}

	/**
	 * AJAX handler for filtering history
	 */
	public function ajax_filter_history() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$days = isset( $_POST['days'] ) ? intval( $_POST['days'] ) : 7;
		$change_type = isset( $_POST['change_type'] ) ? sanitize_text_field( $_POST['change_type'] ) : '';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		$page = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;

		$history_data = EnviWeb_BestOffer_Logger::get_update_history( array(
			'per_page'    => 50,
			'page'        => $page,
			'days'        => $days,
			'change_type' => $change_type,
			'search'      => $search,
		) );

		$stats = EnviWeb_BestOffer_Logger::get_history_stats( $days );

		wp_send_json_success( array(
			'items' => $history_data['items'],
			'total' => $history_data['total'],
			'stats' => $stats,
		) );
	}
}


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
		add_action( 'wp_ajax_enviweb_bestoffer_delete_log', array( $this, 'ajax_delete_log' ) );
	}

	/**
	 * Clean up stale "running" syncs
	 * If a sync has been "running" for more than 5 minutes, mark it as failed
	 */
	public function cleanup_stale_syncs() {
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
			__( 'Settings', 'best-offer-sync' ),
			__( 'Settings', 'best-offer-sync' ),
			'manage_woocommerce',
			'bestoffer-settings',
			array( $this, 'render_settings_page' )
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
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our admin pages
		if ( 'toplevel_page_bestoffer-sync' !== $hook && 'best-offer-sync_page_bestoffer-settings' !== $hook && 'product' !== get_post_type() ) {
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
		// Get last sync info
		$last_sync = EnviWeb_BestOffer_Logger::get_last_sync();
		$stats     = EnviWeb_BestOffer_Logger::get_sync_stats( 30 );
		$logs      = EnviWeb_BestOffer_Logger::get_recent_logs( 50 );

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
								<td colspan="12">
									<strong><?php esc_html_e( 'Error:', 'best-offer-sync' ); ?></strong>
									<?php echo esc_html( $log->error_message ); ?>
								</td>
							</tr>
							<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
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
		if ( isset( $_POST['bestoffer_settings_nonce'] ) && wp_verify_nonce( $_POST['bestoffer_settings_nonce'], 'bestoffer_save_settings' ) ) {
			$ignore_instock = isset( $_POST['bestoffer_ignore_instock'] ) ? 1 : 0;
			update_option( 'bestoffer_ignore_instock', $ignore_instock );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully!', 'best-offer-sync' ) . '</p></div>';
		}

		$ignore_instock = get_option( 'bestoffer_ignore_instock', false );
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
						</tbody>
					</table>

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
}


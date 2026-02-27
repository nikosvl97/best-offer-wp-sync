<?php
/**
 * WP-CLI Command for Best Offer Sync
 *
 * @package BestOfferSync
 * @subpackage CLI
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sync products from Best Offer XML feed
 */
class EnviWeb_BestOffer_CLI_Command {

	/**
	 * Maximum execution time per batch (in seconds)
	 * Set to 0 to disable timeout checks (recommended for LiteSpeed with no timeout issues)
	 */
	const MAX_EXECUTION_TIME = 0; // Disabled - no timeout limit

	/**
	 * Safety buffer (seconds) - Not used when MAX_EXECUTION_TIME is 0
	 */
	const SAFETY_BUFFER = 0;

	/**
	 * Check frequency - Not used when MAX_EXECUTION_TIME is 0
	 */
	const TIMEOUT_CHECK_FREQUENCY = 0;

	/**
	 * Batch size for processing
	 * Increased to 100 for better throughput (optimizations prevent lock issues)
	 */
	const BATCH_SIZE = 100;

	/**
	 * Start time of execution
	 *
	 * @var float
	 */
	private $start_time;

	/**
	 * Statistics (current batch)
	 *
	 * @var array
	 */
	private $stats = array(
		'processed'         => 0,
		'updated'           => 0,
		'created'           => 0,
		'created_as_draft'  => 0,
		'auto_drafted'      => 0,
		'published'         => 0,
		'unchanged'         => 0,
		'skipped'           => 0,
		'skipped_instock'   => 0,
		'locked'            => 0,
		'errors'            => 0,
		'not_found'         => 0,
	);

	/**
	 * Cumulative statistics (across all batches)
	 *
	 * @var array
	 */
	private $cumulative_stats = array(
		'batches'           => 0,
		'processed'         => 0,
		'updated'           => 0,
		'created'           => 0,
		'created_as_draft'  => 0,
		'auto_drafted'      => 0,
		'published'         => 0,
		'unchanged'         => 0,
		'skipped'           => 0,
		'skipped_instock'   => 0,
		'locked'            => 0,
		'errors'            => 0,
		'not_found'         => 0,
		'total_time'        => 0,
	);

	/**
	 * Logger instance
	 *
	 * @var EnviWeb_BestOffer_Logger
	 */
	private $logger;

	/**
	 * Product creator instance
	 *
	 * @var EnviWeb_BestOffer_Product_Creator
	 */
	private $product_creator;

	/**
	 * Settings instance
	 *
	 * @var EnviWeb_BestOffer_Settings
	 */
	private $settings;

	/**
	 * XML supplier SKUs in current sync (for missing detection)
	 *
	 * @var array
	 */
	private $xml_supplier_skus = array();

	/**
	 * Sync limit (for tracking whether this is a full sync)
	 *
	 * @var int|null
	 */
	private $limit = null;

	/**
	 * Product lookup cache (supplier_sku => product_id)
	 *
	 * @var array
	 */
	private $product_lookup_cache = array();

	/**
	 * Product meta cache (product_id => array of meta)
	 *
	 * @var array
	 */
	private $product_meta_cache = array();

	/**
	 * Queued product changes for batch processing
	 *
	 * @var array
	 */
	private $queued_changes = array();

	/**
	 * Products processed count (for speed calculation)
	 *
	 * @var int
	 */
	private $products_checked = 0;

	/**
	 * Average time per product (calculated dynamically)
	 *
	 * @var float
	 */
	private $avg_time_per_product = 0;

	/**
	 * Cached ignore_instock option (loaded once per sync)
	 *
	 * @var bool
	 */
	private $ignore_instock = false;

	/**
	 * Cached sync field settings (loaded once per sync)
	 *
	 * @var array
	 */
	private $sync_fields = array(
		'supplier_price' => true,
		'stock_status'   => true,
		'backorder_mode' => true,
		'publish_drafts' => true,
		'regular_price'  => false,
	);

	/**
	 * Cached price markup (loaded once per sync)
	 *
	 * @var float
	 */
	private $price_markup = 1.40;

	/**
	 * Verbose mode for debugging
	 *
	 * @var bool
	 */
	private $verbose = false;

	/**
	 * Sync products from Best Offer XML feed
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the XML file
	 *
	 * [--batch-size=<number>]
	 * : Number of products to process per batch (default: 25)
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--offset=<number>]
	 * : Start processing from this product number (default: 0)
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum number of products to process (default: all)
	 *
	 * [--dry-run]
	 * : Run without making actual changes
	 *
	 * [--user=<id>]
	 * : Run sync as specific user ID (default: from plugin settings)
	 *
	 * [--skip-validation]
	 * : Skip XML product count validation (not recommended)
	 *
	 * [--force]
	 * : Force sync even if another sync is already running (clears stale locks)
	 *
	 * [--verbose]
	 * : Show detailed logging for debugging (shows why products are skipped)
	 *
	 * ## EXAMPLES
	 *
	 *     # Sync all products from XML file
	 *     wp bestoffer sync /path/to/best-offer.xml
	 *
	 *     # Sync with custom batch size
	 *     wp bestoffer sync /path/to/best-offer.xml --batch-size=50
	 *
	 *     # Sync starting from product 1000
	 *     wp bestoffer sync /path/to/best-offer.xml --offset=1000
	 *
	 *     # Dry run to test without changes
	 *     wp bestoffer sync /path/to/best-offer.xml --dry-run
	 *
	 *     # Run as specific user (overrides plugin setting)
	 *     wp bestoffer sync /path/to/best-offer.xml --user=1
	 *
	 *     # Skip XML validation (not recommended)
	 *     wp bestoffer sync /path/to/best-offer.xml --skip-validation
	 *
	 *     # Force sync (override stale locks)
	 *     wp bestoffer sync /path/to/best-offer.xml --force
	 *
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {
		$this->start_time = microtime( true );

		// Prevent concurrent syncs - check for existing lock
		$lock_key = 'bestoffer_sync_lock';
		$existing_lock = get_transient( $lock_key );
		if ( $existing_lock && ! isset( $assoc_args['force'] ) ) {
			$lock_age = time() - (int) $existing_lock;
			WP_CLI::error( sprintf(
				'Another sync is already running (started %d minutes ago). Use --force to override.',
				floor( $lock_age / 60 )
			) );
			return;
		}

		// Set sync lock (expires after 2 hours as safety)
		set_transient( $lock_key, time(), 2 * HOUR_IN_SECONDS );

		// Initialize settings
		$this->settings = new EnviWeb_BestOffer_Settings();

		// Reset stats for this batch
		$this->stats = array(
			'processed'         => 0,
			'updated'           => 0,
			'created'           => 0,
			'created_as_draft'  => 0,
			'claimed'           => 0,
			'auto_drafted'      => 0,
			'unchanged'         => 0,
			'skipped'           => 0,
			'skipped_instock'   => 0,
			'locked'            => 0,
			'errors'            => 0,
			'not_found'         => 0,
		);

		// Reset performance tracking
		$this->products_checked = 0;
		$this->avg_time_per_product = 0;

		// Parse arguments
		$xml_file    = $args[0];
		$batch_size  = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : self::BATCH_SIZE;
		$offset      = isset( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : 0;
		$this->limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : null;
		$dry_run     = isset( $assoc_args['dry-run'] );
		$user_id     = isset( $assoc_args['user'] ) ? intval( $assoc_args['user'] ) : intval( get_option( 'bestoffer_default_user_id', 1 ) );

		// Handle cumulative stats (passed from previous batch or initialize)
		if ( isset( $assoc_args['_cumulative'] ) ) {
			$this->cumulative_stats = json_decode( base64_decode( $assoc_args['_cumulative'] ), true );
		} else {
			// First batch - reset cumulative stats
			$this->cumulative_stats = array(
				'batches'           => 0,
				'processed'         => 0,
				'updated'           => 0,
				'created'           => 0,
				'created_as_draft'  => 0,
				'claimed'           => 0,
				'auto_drafted'      => 0,
				'unchanged'         => 0,
				'skipped'           => 0,
				'skipped_instock'   => 0,
				'locked'            => 0,
				'errors'            => 0,
				'not_found'         => 0,
				'total_time'        => 0,
			);
		}

		// Initialize logger early for failure tracking (skip for dry run)
		// This ensures all failures are logged, even early ones
		if ( ! $dry_run ) {
			$this->logger = new EnviWeb_BestOffer_Logger();
			$this->logger->start_sync(
				$xml_file,
				array(
					'batch_size'   => $batch_size,
					'offset'       => $offset,
					'xml_products' => 0, // Will be updated later after validation
				)
			);
		}

		// Clear all PHP file caches for the XML file to ensure fresh content
		// This prevents stale data when the XML file is updated externally
		clearstatcache( true, $xml_file );
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $xml_file, true );
		}
		// Also clear realpath cache which can cache file paths
		clearstatcache( true );

		// Validate file exists
		if ( ! file_exists( $xml_file ) ) {
			$error_msg = sprintf( 'XML file not found: %s', $xml_file );
			$this->log_early_failure( $error_msg, $offset );
			delete_transient( $lock_key );
			WP_CLI::error( $error_msg );
			return;
		}

		// Skip XML validation for resumed syncs (offset > 0) or when explicitly skipped
		$skip_validation = ( $offset > 0 ) || ( isset( $assoc_args['skip-validation'] ) && $assoc_args['skip-validation'] );

		if ( ! $skip_validation && ! $dry_run ) {
			// Validate XML file has reasonable product count
			if ( ! $this->validate_xml_file( $xml_file ) ) {
				$error_msg = 'XML validation failed after retries';
				$this->log_early_failure( $error_msg, $offset );
				delete_transient( $lock_key );
				WP_CLI::error( $error_msg );
				return;
			}
		}

		// Set user context for sync operations
		$original_user_id = get_current_user_id();
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			$error_msg = sprintf(
				'User ID %d not found. Please set a valid default user in Best Offer Sync → Settings or use --user parameter.',
				$user_id
			);
			$this->log_early_failure( $error_msg, $offset );
			delete_transient( $lock_key );
			WP_CLI::error( $error_msg );
			return;
		}

		wp_set_current_user( $user_id );
		WP_CLI::line( sprintf( 'Running as user: %s (ID: %d)', $user->user_login, $user_id ) );

		WP_CLI::line( sprintf( 'Starting Best Offer sync from: %s', $xml_file ) );
		if ( $offset > 0 ) {
			WP_CLI::line( sprintf( '📍 Continuing from product #%d', $offset + 1 ) );
		}
		if ( $dry_run ) {
			WP_CLI::warning( 'DRY RUN MODE - No changes will be made' );
		}

		// Check if WooCommerce is using HPOS
		$hpos_enabled = $this->is_hpos_enabled();
		WP_CLI::line( sprintf( 'WooCommerce storage: %s', $hpos_enabled ? 'HPOS' : 'Legacy' ) );
		WP_CLI::line( sprintf( 'Stock mode: All products set to BACKORDER' ) );
		if ( self::MAX_EXECUTION_TIME > 0 ) {
			WP_CLI::line( sprintf( 'Smart timeout: %ds limit with %ds safety buffer', self::MAX_EXECUTION_TIME, self::SAFETY_BUFFER ) );
		} else {
			WP_CLI::line( '🚀 Timeout limit: DISABLED - Full speed mode!' );
		}

		// Check ignore instock setting (cache for use in process_product loop)
		$this->ignore_instock = (bool) get_option( 'bestoffer_ignore_instock', false );
		if ( $this->ignore_instock ) {
			WP_CLI::line( 'In-stock products: PRICE ONLY (stock/status unchanged)' );
		}

		// Set verbose mode for debugging
		$this->verbose = isset( $assoc_args['verbose'] );
		if ( $this->verbose ) {
			WP_CLI::line( '🔍 VERBOSE MODE: Detailed logging enabled' );
		}

		// Load sync field settings (cache for use in update_product)
		$this->sync_fields = array(
			'supplier_price' => (bool) get_option( 'bestoffer_sync_supplier_price', true ),
			'stock_status'   => (bool) get_option( 'bestoffer_sync_stock_status', true ),
			'backorder_mode' => (bool) get_option( 'bestoffer_sync_backorder_mode', true ),
			'publish_drafts' => (bool) get_option( 'bestoffer_sync_publish_drafts', true ),
			'regular_price'  => (bool) get_option( 'bestoffer_sync_regular_price', false ),
		);
		$this->price_markup = (float) get_option( 'bestoffer_price_markup', 1.40 );

		// Show sync field settings
		$enabled_fields = array_keys( array_filter( $this->sync_fields ) );
		WP_CLI::line( 'Sync fields: ' . ( ! empty( $enabled_fields ) ? implode( ', ', $enabled_fields ) : 'NONE' ) );

		// Clear any stale caches before starting fresh sync
		// This is critical if using persistent object cache (Redis/Memcached)
		if ( $offset === 0 ) {
			wp_cache_delete( 'bestoffer_product_lookup_v1', 'bestoffer' );
			delete_transient( 'bestoffer_dashboard_stats' );
			WP_CLI::line( '🧹 Cleared stale caches before sync' );
		}

		// Build product lookup cache (only for first batch)
		if ( $offset === 0 ) {
			$this->build_product_lookup_cache();
		}

		// Migration step: Tag existing products with feed source (first run only)
		if ( $offset === 0 && $this->settings->is_product_creation_enabled() && ! $dry_run ) {
			$this->migrate_existing_products();
		}

		// Count XML products for logging (only for first batch)
		// Update the logger's xml_products count now that validation passed
		if ( $offset === 0 && ! $dry_run ) {
			$xml_product_count = $this->count_xml_products( $xml_file );
			// Update sync log with actual product count
			if ( $this->logger ) {
				$this->update_sync_xml_count( $xml_product_count );
			}
		}

		// Note: WordPress deferrals removed to prevent site issues
		// The bulk operations and caching already provide sufficient performance

		// Process XML file
		$status        = 'completed';
		$error_message = '';
		
		try {
			$this->process_xml_file( $xml_file, $batch_size, $offset, $this->limit, $dry_run, $hpos_enabled );
		} catch ( Exception $e ) {
			$status        = 'failed';
			$error_message = $e->getMessage();

			// IMPORTANT: Log the error BEFORE WP_CLI::error() which exits the script
			if ( $this->logger ) {
				$this->stats['offset_end'] = $offset + $this->stats['processed'];
				$this->logger->end_sync( $this->stats, $status, $error_message );
			}

			// Clear sync lock on error
			delete_transient( 'bestoffer_sync_lock' );

			// Restore original user
			if ( $original_user_id ) {
				wp_set_current_user( $original_user_id );
			}

			// WP_CLI::error() exits the script, so all cleanup must be done above
			WP_CLI::error( sprintf( 'Error processing XML: %s', $error_message ) );

			return;
		}

		// After all batches complete, mark missing as draft (full sync only)
		if ( $offset === 0 && ! $this->limit && ! $dry_run ) {
			$this->mark_missing_products_as_draft( $dry_run );
		}

		// Calculate resume offset and elapsed time
		$resume_offset = $offset + $this->stats['processed'];
		$elapsed = microtime( true ) - $this->start_time;

		// Update cumulative stats
		$this->cumulative_stats['batches']++;
		$this->cumulative_stats['processed']         += $this->stats['processed'];
		$this->cumulative_stats['updated']           += $this->stats['updated'];
		$this->cumulative_stats['created']           += $this->stats['created'];
		$this->cumulative_stats['created_as_draft']  += $this->stats['created_as_draft'];
		$this->cumulative_stats['auto_drafted']      += $this->stats['auto_drafted'];
		$this->cumulative_stats['unchanged']         += $this->stats['unchanged'];
		$this->cumulative_stats['skipped']           += $this->stats['skipped'];
		$this->cumulative_stats['skipped_instock']   += $this->stats['skipped_instock'];
		$this->cumulative_stats['locked']            += $this->stats['locked'];
		$this->cumulative_stats['errors']            += $this->stats['errors'];
		$this->cumulative_stats['not_found']         += $this->stats['not_found'];
		$this->cumulative_stats['total_time']        += $elapsed;

		// End logging
		if ( $this->logger ) {
			$this->stats['offset_end'] = $resume_offset;
			$this->logger->end_sync( $this->stats, $status, $error_message );
		}

		// Clear WooCommerce caches at end
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		WP_CLI::line( '🧹 Caches cleared' );

		// Display statistics
		$this->display_stats();

		// Restore original user
		if ( $original_user_id ) {
			wp_set_current_user( $original_user_id );
		}

		// Display cumulative stats if multiple batches were processed
		if ( $this->cumulative_stats['batches'] > 1 ) {
			$this->display_cumulative_stats();
		}

		// Clear sync lock on successful completion
		delete_transient( 'bestoffer_sync_lock' );

		// Final success message
		if ( $this->cumulative_stats['batches'] === 1 ) {
			WP_CLI::success( '✅ Full sync completed!' );
		} else {
			WP_CLI::success( sprintf( '✅ Full sync completed across %d batches!', $this->cumulative_stats['batches'] ) );
		}
	}

	/**
	 * Check if HPOS is enabled
	 *
	 * @return bool
	 */
	private function is_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * Build product lookup cache
	 * Loads products with supplier_sku in paginated chunks for better memory efficiency
	 * Uses WordPress object cache for persistence across requests
	 *
	 * @return int Number of products cached
	 */
	private function build_product_lookup_cache() {
		global $wpdb;

		WP_CLI::line( '🔧 Building product lookup cache...' );
		$cache_start = microtime( true );

		// Try to get from WordPress object cache first (1-hour expiration)
		$cache_key = 'bestoffer_product_lookup_v1';
		$cached = wp_cache_get( $cache_key, 'bestoffer' );

		if ( $cached !== false && is_array( $cached ) ) {
			$this->product_lookup_cache = $cached;
			$cache_time = microtime( true ) - $cache_start;
			$count = count( $this->product_lookup_cache );
			WP_CLI::line( sprintf(
				'✅ Loaded %d products from object cache in %.3f seconds',
				$count,
				$cache_time
			) );
			return $count;
		}

		try {
			$this->product_lookup_cache = array();
			$duplicate_skus = array(); // Track duplicates
			$page_size = 10000; // Load 10k products per page
			$offset = 0;
			$total_count = 0;

			// Paginated loading to prevent memory issues with large product catalogs
			while ( true ) {
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT pm.post_id, pm.meta_value as supplier_sku
						FROM {$wpdb->postmeta} pm
						INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
						WHERE pm.meta_key = 'supplier_sku'
						AND pm.meta_value != ''
						AND p.post_type = 'product'
						AND p.post_status != 'trash'
						ORDER BY pm.post_id ASC
						LIMIT %d OFFSET %d",
						$page_size,
						$offset
					),
					OBJECT
				);

				// No more results - we're done
				if ( empty( $results ) ) {
					break;
				}

				foreach ( $results as $row ) {
					// Check for duplicate SKUs
					if ( isset( $this->product_lookup_cache[ $row->supplier_sku ] ) ) {
						$existing_id = $this->product_lookup_cache[ $row->supplier_sku ];
						if ( ! isset( $duplicate_skus[ $row->supplier_sku ] ) ) {
							$duplicate_skus[ $row->supplier_sku ] = array( $existing_id );
						}
						$duplicate_skus[ $row->supplier_sku ][] = (int) $row->post_id;
					}
					$this->product_lookup_cache[ $row->supplier_sku ] = (int) $row->post_id;
				}

				$batch_count = count( $results );
				$total_count += $batch_count;
				$offset += $page_size;

				// Show progress for large catalogs
				if ( $total_count % 50000 === 0 ) {
					WP_CLI::line( sprintf( '  ...loaded %d products', $total_count ) );
				}

				// If we got fewer results than page size, we're done
				if ( $batch_count < $page_size ) {
					break;
				}
			}

			$cache_time = microtime( true ) - $cache_start;
			$count = count( $this->product_lookup_cache );

			// Store in WordPress object cache for 1 hour
			wp_cache_set( $cache_key, $this->product_lookup_cache, 'bestoffer', HOUR_IN_SECONDS );

			WP_CLI::line( sprintf(
				'✅ Cached %d products in %.3f seconds (stored in object cache)',
				$count,
				$cache_time
			) );

			// Warn about duplicate SKUs and store for admin notice
			if ( ! empty( $duplicate_skus ) ) {
				$dup_count = count( $duplicate_skus );
				WP_CLI::warning( sprintf(
					'⚠️  Found %d duplicate supplier_sku values! Only the last product ID will be synced for each.',
					$dup_count
				) );
				// Show first 10 duplicates
				$shown = 0;
				foreach ( $duplicate_skus as $sku => $product_ids ) {
					if ( $shown >= 10 ) {
						WP_CLI::line( sprintf( '  ... and %d more duplicates', $dup_count - 10 ) );
						break;
					}
					WP_CLI::line( sprintf(
						'  - SKU "%s" found on products: #%s',
						$sku,
						implode( ', #', $product_ids )
					) );
					$shown++;
				}

				// Store duplicates in transient for admin notice
				set_transient( 'bestoffer_duplicate_skus', $duplicate_skus, WEEK_IN_SECONDS );
			} else {
				// Clear any stored duplicates if none found
				delete_transient( 'bestoffer_duplicate_skus' );
			}

			return $count;

		} catch ( Exception $e ) {
			WP_CLI::warning( sprintf(
				'Failed to build product cache: %s. Continuing without cache...',
				$e->getMessage()
			) );
			$this->product_lookup_cache = array();
			return 0;
		}
	}

	/**
	 * Bulk load product meta for a batch of product IDs
	 * Loads all needed meta in ONE query instead of individual queries per product
	 *
	 * @param array $product_ids Array of product IDs
	 */
	private function bulk_load_product_meta( $product_ids ) {
		global $wpdb;

		if ( empty( $product_ids ) ) {
			return;
		}

		// Limit batch size to prevent query overload (increased to match BATCH_SIZE)
		if ( count( $product_ids ) > 100 ) {
			$product_ids = array_slice( $product_ids, 0, 100 );
		}

		try {
			// Clear existing cache for these products
			foreach ( $product_ids as $id ) {
				unset( $this->product_meta_cache[ $id ] );
			}

			// Meta keys we need to check (including feed source to avoid per-product queries)
			$meta_keys = array(
				'fs_supplier_price',
				'_block_xml_update',
				'_stock_status',
				'_bestoffer_feed_source',
			);

			// Build placeholders for IN clause
			$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
			$meta_key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

			// Prepare query
			$query = $wpdb->prepare(
				"SELECT post_id, meta_key, meta_value
				FROM {$wpdb->postmeta}
				WHERE post_id IN ($placeholders)
				AND meta_key IN ($meta_key_placeholders)",
				array_merge( $product_ids, $meta_keys )
			);

			$results = $wpdb->get_results( $query, OBJECT );

			// Build cache structure
			foreach ( $product_ids as $id ) {
				if ( ! isset( $this->product_meta_cache[ $id ] ) ) {
					$this->product_meta_cache[ $id ] = array(
						'fs_supplier_price'      => '',
						'_block_xml_update'      => '',
						'_stock_status'          => '',
						'_bestoffer_feed_source' => '',
					);
				}
			}

			// Populate cache with actual values
			if ( $results ) {
				foreach ( $results as $row ) {
					$this->product_meta_cache[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
				}
			}

		} catch ( Exception $e ) {
			WP_CLI::warning( sprintf(
				'Failed to bulk load meta: %s. Falling back to individual queries...',
				$e->getMessage()
			) );
			// Continue without cache - will use get_post_meta fallback
		}
	}

	/**
	 * Get cached product meta value
	 * Falls back to get_post_meta if cache is empty
	 *
	 * @param int    $product_id Product ID
	 * @param string $meta_key Meta key
	 * @return mixed Meta value or empty string if not found
	 */
	private function get_cached_meta( $product_id, $meta_key ) {
		// Try cache first
		if ( isset( $this->product_meta_cache[ $product_id ][ $meta_key ] ) ) {
			return $this->product_meta_cache[ $product_id ][ $meta_key ];
		}
		
		// Fallback to direct query if cache not available
		$value = get_post_meta( $product_id, $meta_key, true );
		return $value !== false ? $value : '';
	}

	/**
	 * Process XML file
	 *
	 * @param string $xml_file Path to XML file
	 * @param int    $batch_size Batch size
	 * @param int    $offset Starting offset
	 * @param int    $limit Maximum products to process
	 * @param bool   $dry_run Dry run mode
	 * @param bool   $hpos_enabled HPOS status
	 */
	private function process_xml_file( $xml_file, $batch_size, $offset, $limit, $dry_run, $hpos_enabled ) {
		// Clear PHP's file stat cache for this file to ensure we read fresh content
		// This is important when the XML file is frequently updated externally
		clearstatcache( true, $xml_file );

		// Use XMLReader for memory-efficient parsing of large files
		$reader = new XMLReader();

		if ( ! $reader->open( $xml_file ) ) {
			throw new Exception( 'Failed to open XML file' );
		}

		$current_product = 0;
		$processed_count = 0;
		$xml_products_batch = array(); // Collect products for batch processing

		// Create progress bar
		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing products', $limit ? $limit : 1000 );

		// Read XML products one by one
		while ( $reader->read() ) {
			// Find product nodes
			if ( $reader->nodeType == XMLReader::ELEMENT && $reader->name == 'product' ) {
				// Skip until we reach the offset
				if ( $current_product < $offset ) {
					$current_product++;
					// Skip this product node completely
					$reader->next();
					continue;
				}

				// Check if we've reached the limit
				if ( $limit && $processed_count >= $limit ) {
					break;
				}

				// Expand current product to SimpleXMLElement for easier parsing
				$product_node = simplexml_load_string( $reader->readOuterXML() );
				
				if ( $product_node ) {
					// Collect products for batch processing
					$xml_products_batch[] = $product_node;
					$processed_count++;
					$this->stats['processed']++;
					$progress->tick();

					// Process batch when we reach batch_size or this is the last product
					if ( count( $xml_products_batch ) >= $batch_size ) {
						$this->process_product_batch( $xml_products_batch, $dry_run, $hpos_enabled );
						$xml_products_batch = array();
					}
				}

				$current_product++;
				
				// Note: We DON'T call $reader->next() here because the while loop's
				// $reader->read() will naturally advance to the next element
			}
		}

		// Process any remaining products in the batch
		if ( ! empty( $xml_products_batch ) ) {
			$this->process_product_batch( $xml_products_batch, $dry_run, $hpos_enabled );
		}

		$progress->finish();
		$reader->close();
	}

	/**
	 * Process a batch of products
	 * First pass: collect all product IDs and load their meta in bulk
	 * Second pass: process each product with cached data and queue changes
	 * Third pass: apply all queued changes in a transaction
	 *
	 * @param array $xml_products_batch Array of SimpleXMLElement product nodes
	 * @param bool  $dry_run Dry run mode
	 * @param bool  $hpos_enabled HPOS status
	 */
	private function process_product_batch( $xml_products_batch, $dry_run, $hpos_enabled ) {
		// Pass 1: Separate into create/update buckets
		$separated = $this->separate_products_for_processing( $xml_products_batch, $hpos_enabled );

		// Pass 2: Bulk load meta for existing products
		if ( ! empty( $separated['existing_ids'] ) ) {
			$this->bulk_load_product_meta( $separated['existing_ids'] );
		}

		// Pass 3a: Create new products (if enabled)
		if ( ! empty( $separated['create_candidates'] ) ) {
			$this->create_new_products( $separated['create_candidates'], $dry_run, $hpos_enabled );
		}

		// Pass 3b: Queue updates for existing products
		$this->queued_changes = array(); // Reset queue
		$feed_identifier = $this->settings->get_feed_identifier(); // Cache outside loop
		foreach ( $separated['existing_map'] as $product_id => $product_node ) {
			// Tag with feed source on first update (migration) - use cached meta
			if ( ! $dry_run ) {
				$feed_source = $this->get_cached_meta( $product_id, '_bestoffer_feed_source' );
				if ( empty( $feed_source ) ) {
					update_post_meta( $product_id, '_bestoffer_feed_source', $feed_identifier );
				}
			}

			// Process update (existing method)
			$this->process_product( $product_node, $dry_run, $hpos_enabled );
		}

		// Pass 4: Apply queued changes
		if ( ! $dry_run && ! empty( $this->queued_changes ) ) {
			$this->apply_queued_changes( $hpos_enabled );
		}
	}

	/**
	 * Apply all queued product changes in small batches to avoid database locks
	 * NO large transactions - each product saves independently to prevent site lockup
	 *
	 * @param bool $hpos_enabled HPOS status
	 */
	private function apply_queued_changes( $hpos_enabled ) {
		if ( empty( $this->queued_changes ) ) {
			return;
		}

		// Process changes WITHOUT a large transaction wrapper
		// This prevents holding database locks that block the entire site
		foreach ( $this->queued_changes as $change ) {
			$product_id = $change['product_id'];

			try {
				// Load product fresh - we don't store the object in queue to save memory
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					$this->stats['errors']++;
					continue;
				}

				// Check if this is an in-stock product (price-only update)
				$is_instock = isset( $change['is_instock'] ) && $change['is_instock'];

				// Apply status change if enabled and needed (skip for in-stock products)
				if ( ! $is_instock && $this->sync_fields['publish_drafts'] && isset( $change['new_status'] ) ) {
					$product->set_status( $change['new_status'] );
				}

				// Update fs_supplier_price meta if enabled (always update, even for in-stock)
				if ( $this->sync_fields['supplier_price'] && isset( $change['supplier_price'] ) ) {
					if ( $hpos_enabled ) {
						$product->update_meta_data( 'fs_supplier_price', $change['supplier_price'] );
					} else {
						update_post_meta( $product_id, 'fs_supplier_price', $change['supplier_price'] );
					}
				}

				// Update regular price if enabled (skip for in-stock products)
				if ( ! $is_instock && $this->sync_fields['regular_price'] && isset( $change['supplier_price'] ) ) {
					$regular_price = $change['supplier_price'] * $this->price_markup;
					$product->set_regular_price( $regular_price );
					$product->set_price( $regular_price );
				}

				// Set to backorder mode if enabled (skip for in-stock products)
				if ( ! $is_instock && $this->sync_fields['backorder_mode'] ) {
					$product->set_manage_stock( false );
					$product->set_backorders( 'yes' );
				}

				// Set stock status if enabled (skip for in-stock products)
				if ( ! $is_instock && $this->sync_fields['stock_status'] ) {
					$product->set_stock_status( 'onbackorder' );
				}

				// Save changes - WooCommerce handles its own transaction per product
				$product->save();

				// Queue logging
				if ( $this->logger && isset( $change['log_changes'] ) ) {
					foreach ( $change['log_changes'] as $log_change ) {
						$this->logger->log_product_change(
							$product_id,
							$change['supplier_sku'],
							$log_change['field'],
							$log_change['old_value'],
							$log_change['new_value']
						);
					}
				}

			} catch ( Exception $e ) {
				// Log error but continue with other products
				WP_CLI::warning( sprintf(
					'Error updating product #%d: %s',
					$product_id,
					$e->getMessage()
				) );
				$this->stats['errors']++;
			}
		}

		// Flush queued logs after batch
		if ( $this->logger ) {
			$this->logger->flush_queued_logs();
		}
	}

	/**
	 * Process a single product from XML
	 *
	 * @param SimpleXMLElement $product_node Product XML node
	 * @param bool             $dry_run Dry run mode
	 * @param bool             $hpos_enabled HPOS status
	 */
	private function process_product( $product_node, $dry_run, $hpos_enabled ) {
		// Extract data from XML
		$supplier_sku   = (string) $product_node->SKU;
		$supplier_price = (float) $product_node->supplier_price;

		// Validate required fields
		if ( empty( $supplier_sku ) ) {
			$this->stats['skipped']++;
			return;
		}

		// Find product by supplier_sku meta
		$product_id = $this->find_product_by_supplier_sku( $supplier_sku, $hpos_enabled );

		if ( ! $product_id ) {
			$this->stats['not_found']++;
			return;
		}

		// Get product object
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			$this->stats['errors']++;
			return;
		}

		// Update processing speed metrics for smart timeout prediction
		$this->update_processing_speed();

		// Check if product is in-stock (use cached meta for performance)
		// When ignore_instock is enabled: still update supplier price, but skip stock/backorder changes
		$stock_status = $this->get_cached_meta( $product_id, '_stock_status' );
		$is_instock = $this->ignore_instock && $stock_status === 'instock';

		// Check for update locks
		$lock_info = $this->check_product_locks( $product_id );
		if ( $lock_info['is_locked'] ) {
			$this->stats['locked']++;
			
			// Log the locked product
			if ( ! $dry_run && $this->logger ) {
				$this->logger->log_product_locked( $product_id, $supplier_sku, $lock_info['reason'], $supplier_price );
			}
			
			if ( $dry_run ) {
				WP_CLI::line( sprintf(
					'[DRY RUN] Product #%d (%s) is LOCKED - Reason: %s',
					$product_id,
					$supplier_sku,
					$lock_info['reason']
				) );
			}
			
			return;
		}

		// Check if price has changed (use cached meta for performance)
		$current_price = $this->get_cached_meta( $product_id, 'fs_supplier_price' );
		// Use tolerance-based comparison for floats to avoid floating-point precision issues
		// This prevents false positives/negatives when comparing 14.5 vs 14.50000001
		$price_changed = ( empty( $current_price ) || abs( (float) $current_price - $supplier_price ) > 0.001 );

		// Check if product is draft - draft products must be published even if price unchanged (if setting enabled)
		// Note: In-stock products don't get published (we only update their price)
		$is_draft = $product->get_status() === 'draft';
		$should_publish_draft = $is_draft && $this->sync_fields['publish_drafts'] && ! $is_instock;

		// Don't proceed if dry run
		if ( $dry_run ) {
			$post_status = $product->get_status();
			if ( $is_instock ) {
				// In-stock product: only update price
				if ( $price_changed ) {
					WP_CLI::line( sprintf(
						'[DRY RUN] Product #%d (%s) is IN STOCK - Would update price only: €%s → €%s',
						$product_id,
						$supplier_sku,
						$current_price ? number_format( (float) $current_price, 2 ) : 'N/A',
						number_format( $supplier_price, 2 )
					) );
					$this->stats['updated']++;
					$this->stats['skipped_instock']++;
				} else {
					WP_CLI::line( sprintf(
						'[DRY RUN] Product #%d (%s) is IN STOCK - No price change (€%s), would skip',
						$product_id,
						$supplier_sku,
						number_format( $supplier_price, 2 )
					) );
					$this->stats['unchanged']++;
					$this->stats['skipped_instock']++;
				}
			} elseif ( $price_changed ) {
				$message = sprintf(
					'[DRY RUN] Would update product #%d (%s) - Supplier Price: €%s → €%s',
					$product_id,
					$supplier_sku,
					$current_price ? number_format( (float) $current_price, 2 ) : 'N/A',
					number_format( $supplier_price, 2 )
				);
				if ( $should_publish_draft ) {
					$message .= ' + PUBLISH';
					$this->stats['published']++;
				}
				WP_CLI::line( $message );
				$this->stats['updated']++;
			} elseif ( $should_publish_draft ) {
				// Price unchanged but product is draft and publish_drafts enabled - still need to publish
				WP_CLI::line( sprintf(
					'[DRY RUN] Would publish draft product #%d (%s) - Price unchanged (€%s) + PUBLISH',
					$product_id,
					$supplier_sku,
					number_format( $supplier_price, 2 )
				) );
				$this->stats['updated']++;
				$this->stats['published']++;
			} else {
				WP_CLI::line( sprintf(
					'[DRY RUN] Product #%d (%s) - No price change (€%s), would skip',
					$product_id,
					$supplier_sku,
					number_format( $supplier_price, 2 )
				) );
				$this->stats['unchanged']++;
			}
			return;
		}

		// Handle in-stock products: only update if price changed (no publish/stock changes)
		if ( $is_instock ) {
			if ( ! $price_changed ) {
				$this->stats['unchanged']++;
				$this->stats['skipped_instock']++;
				// Verbose logging for debugging
				if ( $this->verbose ) {
					WP_CLI::line( sprintf(
						'  [SKIP-INSTOCK] #%d (%s): In-stock, price unchanged (€%s)',
						$product_id,
						$supplier_sku,
						number_format( $supplier_price, 2 )
					) );
				}
				return;
			}

			// Update price only for in-stock products
			try {
				$this->update_product( $product, $supplier_sku, $supplier_price, $hpos_enabled, true );
				$this->stats['updated']++;
				$this->stats['skipped_instock']++;
			} catch ( Exception $e ) {
				WP_CLI::warning( sprintf(
					'Error updating in-stock product #%d (%s): %s',
					$product_id,
					$supplier_sku,
					$e->getMessage()
				) );
				$this->stats['errors']++;
			}
			return;
		}

		// Skip update ONLY if price unchanged AND (product already published OR publish_drafts disabled)
		if ( ! $price_changed && ! $should_publish_draft ) {
			$this->stats['unchanged']++;
			// Verbose logging for debugging sync issues
			if ( $this->verbose ) {
				WP_CLI::line( sprintf(
					'  [SKIP] #%d (%s): Price unchanged (DB: €%s vs XML: €%s, diff: %.4f)',
					$product_id,
					$supplier_sku,
					number_format( (float) $current_price, 4 ),
					number_format( $supplier_price, 4 ),
					abs( (float) $current_price - $supplier_price )
				) );
			}
			return;
		}

		// Update product
		try {
			$this->update_product( $product, $supplier_sku, $supplier_price, $hpos_enabled, false );
			$this->stats['updated']++;
			if ( $should_publish_draft ) {
				$this->stats['published']++;
			}
			// Verbose logging for updated products
			if ( $this->verbose ) {
				$changes = array();
				if ( $price_changed ) {
					$changes[] = sprintf( 'price €%s→€%s', number_format( (float) $current_price, 2 ), number_format( $supplier_price, 2 ) );
				}
				if ( $should_publish_draft ) {
					$changes[] = 'publish';
				}
				WP_CLI::line( sprintf(
					'  [UPDATE] #%d (%s): %s',
					$product_id,
					$supplier_sku,
					implode( ', ', $changes )
				) );
			}
		} catch ( Exception $e ) {
			WP_CLI::warning( sprintf(
				'Error updating product #%d (%s): %s',
				$product_id,
				$supplier_sku,
				$e->getMessage()
			) );
			$this->stats['errors']++;
		}
	}

	/**
	 * Find product by supplier_sku meta
	 * Uses in-memory cache for O(1) lookups
	 *
	 * @param string $supplier_sku Supplier SKU
	 * @param bool   $hpos_enabled HPOS status (unused, kept for compatibility)
	 * @return int|false Product ID or false if not found
	 */
	private function find_product_by_supplier_sku( $supplier_sku, $hpos_enabled ) {
		// Sanitize input
		$supplier_sku = sanitize_text_field( $supplier_sku );

		// Use cache for instant O(1) lookup
		if ( isset( $this->product_lookup_cache[ $supplier_sku ] ) ) {
			return $this->product_lookup_cache[ $supplier_sku ];
		}

		return false;
	}

	/**
	 * Check if product has update locks
	 * Uses cached meta data for performance
	 *
	 * @param int $product_id Product ID
	 * @return array Lock status and reason
	 */
	private function check_product_locks( $product_id ) {
		// Only _block_xml_update fully blocks the sync
		// _block_custom_update does NOT block - we only sync supplier price, stock, and post status
		// _skroutz_block_xml_update is for Skroutz feeds, not Best Offer
		$locks = array(
			'_block_xml_update' => 'XML Update Block',
		);

		foreach ( $locks as $meta_key => $reason ) {
			// Use cached meta instead of get_post_meta
			$lock_value = $this->get_cached_meta( $product_id, $meta_key );
			
			// Check if lock is set to true, 1, or 'yes'
			if ( $lock_value === true || $lock_value === '1' || $lock_value === 1 || $lock_value === 'yes' ) {
				return array(
					'is_locked' => true,
					'reason'    => $reason,
					'meta_key'  => $meta_key,
				);
			}
		}

		return array(
			'is_locked' => false,
			'reason'    => '',
			'meta_key'  => '',
		);
	}

	/**
	 * Update product with new data
	 * Queues changes for batch processing instead of immediate save
	 *
	 * @param WC_Product $product WooCommerce product object
	 * @param string     $supplier_sku Supplier SKU
	 * @param float      $supplier_price Supplier price
	 * @param bool       $hpos_enabled HPOS status
	 * @param bool       $is_instock Whether product is in-stock (price-only update)
	 */
	private function update_product( $product, $supplier_sku, $supplier_price, $hpos_enabled, $is_instock = false ) {
		$product_id = $product->get_id();

		// Get old values for logging (use cached meta)
		$old_price      = $this->get_cached_meta( $product_id, 'fs_supplier_price' );
		$old_backorders = $product->get_backorders();
		$old_stock      = $product->get_stock_status();
		$old_status     = $product->get_status();

		// Build change queue entry
		// OPTIMIZATION: Only store product_id, not the full product object (saves ~10-20MB per batch)
		// Product will be reloaded in apply_queued_changes() - small overhead but huge memory savings
		$change = array(
			'product_id'     => $product_id,
			'supplier_sku'   => $supplier_sku,
			'supplier_price' => $supplier_price,
			'log_changes'    => array(),
			'is_instock'     => $is_instock,
		);

		// If product is draft and publish_drafts is enabled, publish it (skip for in-stock products)
		$status_changed = false;
		if ( ! $is_instock && $this->sync_fields['publish_drafts'] && $old_status === 'draft' ) {
			$change['new_status'] = 'publish';
			$status_changed = true;
		}

		// Queue log changes (only for fields that are enabled and actually changed)
		if ( $this->logger ) {
			// Log price change (if supplier_price sync enabled)
			if ( $this->sync_fields['supplier_price'] && $old_price != $supplier_price ) {
				$change['log_changes'][] = array(
					'field'     => 'fs_supplier_price',
					'old_value' => $old_price,
					'new_value' => $supplier_price,
				);
			}

			// Log regular price change (if regular_price sync enabled) - skip for in-stock products
			if ( ! $is_instock && $this->sync_fields['regular_price'] ) {
				$old_regular = $product->get_regular_price();
				$new_regular = $supplier_price * $this->price_markup;
				if ( $old_regular != $new_regular ) {
					$change['log_changes'][] = array(
						'field'     => 'regular_price',
						'old_value' => $old_regular,
						'new_value' => $new_regular,
					);
				}
			}

			// Log backorders change (if backorder_mode sync enabled) - skip for in-stock products
			if ( ! $is_instock && $this->sync_fields['backorder_mode'] && $old_backorders !== 'yes' ) {
				$change['log_changes'][] = array(
					'field'     => 'backorders',
					'old_value' => $old_backorders,
					'new_value' => 'yes',
				);
			}

			// Log stock status change (if stock_status sync enabled) - skip for in-stock products
			if ( ! $is_instock && $this->sync_fields['stock_status'] && $old_stock !== 'onbackorder' ) {
				$change['log_changes'][] = array(
					'field'     => 'stock_status',
					'old_value' => $old_stock,
					'new_value' => 'onbackorder',
				);
			}

			// Log post status change (draft to publish) - skip for in-stock products
			if ( $status_changed ) {
				$change['log_changes'][] = array(
					'field'     => 'post_status',
					'old_value' => $old_status,
					'new_value' => 'publish',
				);
			}
		}

		// Add to queue
		$this->queued_changes[] = $change;

		// Show message if product was published
		if ( $status_changed ) {
			WP_CLI::line( sprintf(
				'  → Product #%d (%s) was DRAFT - will be PUBLISHED',
				$product_id,
				$supplier_sku
			) );
		}
	}

	/**
	 * Check if we're approaching timeout (with smart prediction)
	 * Returns false when MAX_EXECUTION_TIME is 0 (no timeout limit)
	 *
	 * @return bool
	 */
	private function is_timeout_approaching() {
		// Timeout checks disabled - run until completion
		if ( self::MAX_EXECUTION_TIME === 0 ) {
			return false;
		}
		
		$elapsed = microtime( true ) - $this->start_time;
		
		// Hard limit - definitely stop
		if ( $elapsed >= self::MAX_EXECUTION_TIME ) {
			return true;
		}
		
		// Smart prediction: Stop early if we're approaching timeout
		// and probably won't finish the next batch of products in time
		$safe_limit = self::MAX_EXECUTION_TIME - self::SAFETY_BUFFER;
		
		if ( $elapsed >= $safe_limit ) {
			return true;
		}
		
		// Dynamic prediction: If we know average time per product,
		// check if we have time for at least TIMEOUT_CHECK_FREQUENCY more products
		if ( $this->products_checked > 0 && $this->avg_time_per_product > 0 ) {
			$time_remaining = $safe_limit - $elapsed;
			$estimated_time_needed = $this->avg_time_per_product * self::TIMEOUT_CHECK_FREQUENCY;
			
			if ( $time_remaining < $estimated_time_needed ) {
				WP_CLI::line( sprintf( 
					'⚡ Smart timeout: %.2fs remaining, need ~%.2fs for next %d products. Stopping early.',
					$time_remaining,
					$estimated_time_needed,
					self::TIMEOUT_CHECK_FREQUENCY
				) );
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Update processing speed metrics
	 * Call this after processing each product
	 */
	private function update_processing_speed() {
		$this->products_checked++;
		
		// Skip if timeout checks are disabled
		if ( self::TIMEOUT_CHECK_FREQUENCY === 0 ) {
			return;
		}
		
		// Recalculate average every TIMEOUT_CHECK_FREQUENCY products
		if ( $this->products_checked % self::TIMEOUT_CHECK_FREQUENCY === 0 ) {
			$elapsed = microtime( true ) - $this->start_time;
			$this->avg_time_per_product = $elapsed / $this->products_checked;
		}
	}

	/**
	 * Display statistics (current batch)
	 */
	private function display_stats() {
		$elapsed = microtime( true ) - $this->start_time;
		$rate = $this->stats['processed'] > 0 ? $this->stats['processed'] / $elapsed : 0;
		$memory_mb = memory_get_peak_usage( true ) / 1024 / 1024;

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '=== Batch #%d Statistics ===', $this->cumulative_stats['batches'] ) );
		WP_CLI::line( sprintf( 'Processed:       %d products', $this->stats['processed'] ) );
		WP_CLI::line( sprintf( 'Updated:         %d products (%.1f%%)',
			$this->stats['updated'],
			$this->stats['processed'] > 0 ? ( $this->stats['updated'] / $this->stats['processed'] * 100 ) : 0
		) );
		WP_CLI::line( sprintf( 'Created:         %d products', isset( $this->stats['created'] ) ? $this->stats['created'] : 0 ) );
		WP_CLI::line( sprintf( 'Created (draft): %d products', isset( $this->stats['created_as_draft'] ) ? $this->stats['created_as_draft'] : 0 ) );
		if ( isset( $this->stats['claimed'] ) && $this->stats['claimed'] > 0 ) {
			WP_CLI::line( sprintf( 'Claimed:         %d products (existing products matched and tagged)', $this->stats['claimed'] ) );
		}
		if ( isset( $this->stats['auto_drafted'] ) && $this->stats['auto_drafted'] > 0 ) {
			WP_CLI::line( sprintf( 'Auto-drafted:    %d products (missing from feed)', $this->stats['auto_drafted'] ) );
		}
		WP_CLI::line( sprintf( 'Unchanged:       %d products (%.1f%%)',
			$this->stats['unchanged'],
			$this->stats['processed'] > 0 ? ( $this->stats['unchanged'] / $this->stats['processed'] * 100 ) : 0
		) );
		WP_CLI::line( sprintf( 'Locked:          %d products', $this->stats['locked'] ) );
		WP_CLI::line( sprintf( 'Skipped (empty): %d products', $this->stats['skipped'] ) );
		WP_CLI::line( sprintf( 'In-stock:        %d products (price only)', $this->stats['skipped_instock'] ) );
		WP_CLI::line( sprintf( 'Not Found:       %d products', $this->stats['not_found'] ) );
		WP_CLI::line( sprintf( 'Errors:          %d products', $this->stats['errors'] ) );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '⚡ Performance:' ) );
		WP_CLI::line( sprintf( '  Time:          %.2f seconds', $elapsed ) );
		WP_CLI::line( sprintf( '  Throughput:    %.1f products/sec', $rate ) );
		WP_CLI::line( sprintf( '  Avg per item:  %.3f seconds', $this->stats['processed'] > 0 ? $elapsed / $this->stats['processed'] : 0 ) );
		WP_CLI::line( sprintf( '  Memory peak:   %.2f MB', $memory_mb ) );
		WP_CLI::line( '' );
	}

	/**
	 * Display cumulative statistics (across all batches)
	 */
	private function display_cumulative_stats() {
		$avg_rate = $this->cumulative_stats['processed'] > 0 ? 
			$this->cumulative_stats['processed'] / $this->cumulative_stats['total_time'] : 0;
		$memory_mb = memory_get_peak_usage( true ) / 1024 / 1024;
		
		WP_CLI::line( '' );
		WP_CLI::line( '╔════════════════════════════════════════════════╗' );
		WP_CLI::line( '║          CUMULATIVE SYNC TOTALS                ║' );
		WP_CLI::line( '╚════════════════════════════════════════════════╝' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '📦 Total Batches:     %d', $this->cumulative_stats['batches'] ) );
		WP_CLI::line( sprintf( '📊 Total Processed:   %d products', $this->cumulative_stats['processed'] ) );
		WP_CLI::line( sprintf( '✅ Total Updated:     %d products (%.1f%%)',
			$this->cumulative_stats['updated'],
			$this->cumulative_stats['processed'] > 0 ? ( $this->cumulative_stats['updated'] / $this->cumulative_stats['processed'] * 100 ) : 0
		) );
		WP_CLI::line( sprintf( '✨ Total Created:     %d products', isset( $this->cumulative_stats['created'] ) ? $this->cumulative_stats['created'] : 0 ) );
		WP_CLI::line( sprintf( '📝 Created (draft):   %d products', isset( $this->cumulative_stats['created_as_draft'] ) ? $this->cumulative_stats['created_as_draft'] : 0 ) );
		if ( isset( $this->cumulative_stats['claimed'] ) && $this->cumulative_stats['claimed'] > 0 ) {
			WP_CLI::line( sprintf( '🏷️  Total Claimed:      %d products (matched and tagged)', $this->cumulative_stats['claimed'] ) );
		}
		if ( isset( $this->cumulative_stats['auto_drafted'] ) && $this->cumulative_stats['auto_drafted'] > 0 ) {
			WP_CLI::line( sprintf( '📋 Auto-drafted:      %d products (missing from feed)', $this->cumulative_stats['auto_drafted'] ) );
		}
		WP_CLI::line( sprintf( '➖ Total Unchanged:   %d products (%.1f%%)',
			$this->cumulative_stats['unchanged'],
			$this->cumulative_stats['processed'] > 0 ? ( $this->cumulative_stats['unchanged'] / $this->cumulative_stats['processed'] * 100 ) : 0
		) );
		WP_CLI::line( sprintf( '🔒 Total Locked:      %d products', $this->cumulative_stats['locked'] ) );
		WP_CLI::line( sprintf( '⏭️  Total Skipped:     %d products', $this->cumulative_stats['skipped'] ) );
		WP_CLI::line( sprintf( '📦 In-stock:          %d products (price only)', $this->cumulative_stats['skipped_instock'] ) );
		WP_CLI::line( sprintf( '❌ Total Not Found:   %d products', $this->cumulative_stats['not_found'] ) );
		WP_CLI::line( sprintf( '⚠️  Total Errors:      %d products', $this->cumulative_stats['errors'] ) );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '⚡ Performance Summary:' ) );
		WP_CLI::line( sprintf( '  Total Time:        %.2f seconds', $this->cumulative_stats['total_time'] ) );
		WP_CLI::line( sprintf( '  Avg per batch:     %.2f seconds', $this->cumulative_stats['total_time'] / $this->cumulative_stats['batches'] ) );
		WP_CLI::line( sprintf( '  Overall rate:      %.1f products/sec', $avg_rate ) );
		WP_CLI::line( sprintf( '  Memory peak:       %.2f MB', $memory_mb ) );
		WP_CLI::line( '' );
	}

	/**
	 * Check a specific SKU's current state vs XML value
	 *
	 * ## OPTIONS
	 *
	 * <sku>
	 * : The supplier_sku to check
	 *
	 * <file>
	 * : Path to the XML file
	 *
	 * ## EXAMPLES
	 *
	 *     wp bestoffer check-sku ABC123 /path/to/best-offer.xml
	 *
	 * @subcommand check-sku
	 * @when after_wp_load
	 */
	public function check_sku( $args, $assoc_args ) {
		$sku = $args[0];
		$xml_file = $args[1];

		if ( ! file_exists( $xml_file ) ) {
			WP_CLI::error( 'XML file not found: ' . $xml_file );
			return;
		}

		WP_CLI::line( sprintf( '🔍 Checking SKU: %s', $sku ) );
		WP_CLI::line( '' );

		// Find product in database
		global $wpdb;
		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'supplier_sku' AND meta_value = %s LIMIT 1",
				$sku
			)
		);

		if ( ! $product_id ) {
			WP_CLI::warning( 'Product NOT FOUND in database with this supplier_sku' );
		} else {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				WP_CLI::warning( sprintf( 'Product ID #%d exists in meta but product object could not be loaded', $product_id ) );
			} else {
				WP_CLI::line( '📦 DATABASE STATE:' );
				WP_CLI::line( sprintf( '  Product ID:       #%d', $product_id ) );
				WP_CLI::line( sprintf( '  Title:            %s', $product->get_name() ) );
				WP_CLI::line( sprintf( '  Status:           %s', $product->get_status() ) );
				WP_CLI::line( sprintf( '  Stock Status:     %s', $product->get_stock_status() ) );

				// Get fs_supplier_price from database directly to show exact value
				$db_price = get_post_meta( $product_id, 'fs_supplier_price', true );
				WP_CLI::line( sprintf( '  Supplier Price:   %s (raw: "%s")',
					$db_price ? '€' . number_format( (float) $db_price, 4 ) : 'NOT SET',
					$db_price
				) );

				// Check locks
				$block_xml = get_post_meta( $product_id, '_block_xml_update', true );
				WP_CLI::line( sprintf( '  XML Lock:         %s', $block_xml ? 'YES (blocked)' : 'No' ) );
			}
		}

		WP_CLI::line( '' );

		// Find product in XML
		WP_CLI::line( '📄 XML STATE:' );
		$reader = new XMLReader();
		if ( ! $reader->open( $xml_file ) ) {
			WP_CLI::error( 'Failed to open XML file' );
			return;
		}

		$found_in_xml = false;
		while ( $reader->read() ) {
			if ( $reader->nodeType == XMLReader::ELEMENT && $reader->name == 'product' ) {
				$product_node = simplexml_load_string( $reader->readOuterXML() );
				if ( $product_node && (string) $product_node->SKU === $sku ) {
					$found_in_xml = true;
					$xml_price = (float) $product_node->supplier_price;
					WP_CLI::line( sprintf( '  SKU:              %s', (string) $product_node->SKU ) );
					WP_CLI::line( sprintf( '  Supplier Price:   €%s (raw: "%s")',
						number_format( $xml_price, 4 ),
						(string) $product_node->supplier_price
					) );

					if ( isset( $product_node->title ) ) {
						WP_CLI::line( sprintf( '  Title:            %s', (string) $product_node->title ) );
					}

					// Compare if product exists in DB
					if ( $product_id && $db_price ) {
						WP_CLI::line( '' );
						WP_CLI::line( '📊 COMPARISON:' );
						$diff = abs( (float) $db_price - $xml_price );
						$would_update = $diff > 0.001;
						WP_CLI::line( sprintf( '  Price Difference: %.6f', $diff ) );
						WP_CLI::line( sprintf( '  Threshold:        0.001' ) );
						WP_CLI::line( sprintf( '  Would Update:     %s', $would_update ? 'YES' : 'NO (within tolerance)' ) );

						if ( ! $would_update ) {
							WP_CLI::warning( 'Product would be SKIPPED as unchanged due to price tolerance' );
						}
					}

					break;
				}
			}
		}
		$reader->close();

		if ( ! $found_in_xml ) {
			WP_CLI::warning( 'Product NOT FOUND in XML file' );
		}

		WP_CLI::line( '' );
	}

	/**
	 * Clear all product caches
	 *
	 * ## EXAMPLES
	 *
	 *     wp bestoffer clear-cache
	 *
	 * @when after_wp_load
	 */
	public function clear_cache( $args, $assoc_args ) {
		WP_CLI::line( 'Clearing product caches...' );

		// Clear WooCommerce caches
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		// Clear object cache
		wp_cache_flush();

		WP_CLI::success( 'Product caches cleared!' );
	}

	/**
	 * Clear sync lock (useful when sync gets stuck)
	 *
	 * ## EXAMPLES
	 *
	 *     wp bestoffer clear-lock
	 *
	 * @when after_wp_load
	 */
	public function clear_lock( $args, $assoc_args ) {
		$lock_key = 'bestoffer_sync_lock';
		$existing_lock = get_transient( $lock_key );

		if ( $existing_lock ) {
			$lock_age = time() - (int) $existing_lock;
			delete_transient( $lock_key );
			WP_CLI::success( sprintf(
				'Cleared sync lock (was running for %d minutes)',
				floor( $lock_age / 60 )
			) );
		} else {
			WP_CLI::line( 'No sync lock found.' );
		}
	}

	/**
	 * List products with duplicate supplier_sku values
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv)
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all duplicate SKUs
	 *     wp bestoffer list-duplicates
	 *
	 *     # Output as JSON
	 *     wp bestoffer list-duplicates --format=json
	 *
	 * @when after_wp_load
	 * @subcommand list-duplicates
	 */
	public function list_duplicates( $args, $assoc_args ) {
		global $wpdb;

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		WP_CLI::line( '🔍 Scanning for duplicate supplier_sku values...' );

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
			WP_CLI::success( '✅ No duplicate supplier_sku values found!' );
			// Clear any stored duplicates
			delete_transient( 'bestoffer_duplicate_skus' );
			return;
		}

		// Store duplicates in transient for admin notice
		$duplicate_data = array();
		foreach ( $duplicates as $dup ) {
			$duplicate_data[ $dup['supplier_sku'] ] = array_map( 'intval', explode( ',', $dup['product_ids'] ) );
		}
		set_transient( 'bestoffer_duplicate_skus', $duplicate_data, WEEK_IN_SECONDS );

		$total_duplicates = count( $duplicates );
		$total_affected = array_sum( array_column( $duplicates, 'count' ) );

		WP_CLI::warning( sprintf(
			'⚠️  Found %d duplicate SKUs affecting %d products!',
			$total_duplicates,
			$total_affected
		) );
		WP_CLI::line( '' );

		if ( $format === 'table' ) {
			// Build table data with edit links
			$table_data = array();
			foreach ( $duplicates as $dup ) {
				$product_ids = explode( ',', $dup['product_ids'] );
				$product_links = array();
				foreach ( $product_ids as $pid ) {
					$product_links[] = '#' . $pid;
				}
				$table_data[] = array(
					'SKU'         => $dup['supplier_sku'],
					'Count'       => $dup['count'],
					'Product IDs' => implode( ', ', $product_links ),
				);
			}

			WP_CLI\Utils\format_items( 'table', $table_data, array( 'SKU', 'Count', 'Product IDs' ) );

			WP_CLI::line( '' );
			WP_CLI::line( '💡 To fix duplicates:' );
			WP_CLI::line( '   1. Edit the products in WooCommerce and remove/change duplicate SKUs' );
			WP_CLI::line( '   2. Or delete the duplicate products if they are not needed' );
			WP_CLI::line( '   3. Re-run sync after fixing duplicates' );
		} elseif ( $format === 'json' ) {
			WP_CLI::line( json_encode( $duplicates, JSON_PRETTY_PRINT ) );
		} elseif ( $format === 'csv' ) {
			WP_CLI::line( 'supplier_sku,count,product_ids' );
			foreach ( $duplicates as $dup ) {
				WP_CLI::line( sprintf( '"%s",%d,"%s"', $dup['supplier_sku'], $dup['count'], $dup['product_ids'] ) );
			}
		}
	}

	/**
	 * Log early failure (before main processing starts)
	 * Helper to ensure failures are logged before WP_CLI::error() exits
	 *
	 * @param string $error_message Error message to log
	 * @param int    $offset Current offset
	 */
	private function log_early_failure( $error_message, $offset = 0 ) {
		if ( $this->logger ) {
			$this->stats['offset_end'] = $offset + $this->stats['processed'];
			$this->logger->end_sync( $this->stats, 'failed', $error_message );
		}
	}

	/**
	 * Update sync log with XML product count
	 * Called after validation passes to update the initial 0 count
	 *
	 * @param int $count XML product count
	 */
	private function update_sync_xml_count( $count ) {
		global $wpdb;

		if ( ! $this->logger ) {
			return;
		}

		$sync_log_id = $this->logger->get_sync_log_id();
		if ( ! $sync_log_id ) {
			return;
		}

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$wpdb->update(
			$table_name,
			array( 'xml_products' => $count ),
			array( 'id' => $sync_log_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Count total products in XML file
	 *
	 * @param string $xml_file Path to XML file
	 * @return int Number of products
	 */
	private function count_xml_products( $xml_file ) {
		$count = 0;

		// Clear PHP's file stat cache for this file
		clearstatcache( true, $xml_file );

		try {
			$reader = new XMLReader();
			if ( ! $reader->open( $xml_file ) ) {
				throw new Exception( 'Failed to open XML file' );
			}

			// Count product elements
			while ( $reader->read() ) {
				if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'product' ) {
					$count++;
				}
			}

			$reader->close();
		} catch ( Exception $e ) {
			WP_CLI::warning( sprintf( 'Error counting XML products: %s', $e->getMessage() ) );
			return 0;
		}

		return $count;
	}

	/**
	 * Count published products in WooCommerce
	 *
	 * @return int Number of published products
	 */
	private function count_published_products() {
		$args = array(
			'status'  => 'publish',
			'limit'   => -1,
			'return'  => 'ids',
		);

		$products = wc_get_products( $args );
		return count( $products );
	}

	/**
	 * Validate XML file has reasonable product count
	 * Retries if XML appears incomplete
	 *
	 * @param string $xml_file Path to XML file
	 * @param int    $max_retries Maximum retry attempts
	 * @return bool True if valid, false if invalid after retries
	 */
	private function validate_xml_file( $xml_file, $max_retries = 3 ) {
		$retry_count = 0;
		$retry_delay = 30; // seconds

		while ( $retry_count < $max_retries ) {
			// Count products in XML
			WP_CLI::line( '🔍 Validating XML file...' );
			$xml_count = $this->count_xml_products( $xml_file );
			
			if ( $xml_count === 0 ) {
				WP_CLI::warning( 'XML file contains 0 products!' );
				$retry_count++;

				if ( $retry_count < $max_retries ) {
					WP_CLI::line( sprintf( '⏳ Waiting %d seconds before retry %d/%d...', $retry_delay, $retry_count + 1, $max_retries ) );
					sleep( $retry_delay );
					continue;
				}

				// Return false instead of WP_CLI::error() to allow caller to handle cleanup
				WP_CLI::warning( 'XML file is empty or invalid after all retries.' );
				return false;
			}

			// Count published products in WordPress
			$published_count = $this->count_published_products();

			WP_CLI::line( sprintf( '📊 XML products: %d', $xml_count ) );
			WP_CLI::line( sprintf( '📊 Published products: %d', $published_count ) );

			// Validate: XML should have at least 50% of published products
			// (allows for some products to be unpublished, but catches major issues)
			$minimum_expected = (int) ( $published_count * 0.5 );
			
			if ( $xml_count < $minimum_expected && $published_count > 100 ) {
				WP_CLI::warning( sprintf(
					'⚠️  XML appears incomplete! Expected at least %d products, found %d',
					$minimum_expected,
					$xml_count
				) );

				$retry_count++;

				if ( $retry_count < $max_retries ) {
					WP_CLI::line( sprintf( '⏳ Waiting %d seconds before retry %d/%d...', $retry_delay, $retry_count + 1, $max_retries ) );
					sleep( $retry_delay );
					continue;
				}

				// Return false instead of WP_CLI::error() to allow caller to handle cleanup
				WP_CLI::warning( sprintf(
					'XML validation failed after %d retries. XML has %d products but expected at least %d based on %d published products.',
					$max_retries,
					$xml_count,
					$minimum_expected,
					$published_count
				) );
				return false;
			}

			// Validation passed
			WP_CLI::success( sprintf( '✅ XML validation passed! Processing %d products...', $xml_count ) );
			return true;
		}

		return false;
	}

	/**
	 * Separate products for processing into create/update buckets
	 *
	 * @param array $xml_products_batch Array of SimpleXMLElement product nodes.
	 * @param bool  $hpos_enabled       HPOS status.
	 * @return array Separated products by category
	 */
	private function separate_products_for_processing( $xml_products_batch, $hpos_enabled ) {
		$separated = array(
			'existing_ids'     => array(),
			'existing_map'     => array(),
			'create_candidates' => array(),
			'skipped'          => array(),
		);

		foreach ( $xml_products_batch as $product_node ) {
			$supplier_sku = (string) $product_node->SKU;

			if ( empty( $supplier_sku ) ) {
				$this->stats['skipped']++;
				continue;
			}

			// Track all SKUs in this sync (for missing detection) - only on first batch, full sync
			if ( $this->cumulative_stats['batches'] === 0 && is_null( $this->limit ) ) {
				$this->xml_supplier_skus[] = $supplier_sku;
			}

			// Check if product exists
			$product_id = $this->find_product_by_supplier_sku( $supplier_sku, $hpos_enabled );

			if ( $product_id ) {
				// Existing product - update
				$separated['existing_ids'][] = $product_id;
				$separated['existing_map'][ $product_id ] = $product_node;
			} else {
				// New product - check if creation enabled
				if ( $this->settings->is_product_creation_enabled() ) {
					$separated['create_candidates'][] = $product_node;
				} else {
					$this->stats['not_found']++;
				}
			}
		}

		return $separated;
	}

	/**
	 * Create new products from XML nodes
	 *
	 * @param array $create_candidates Array of SimpleXMLElement product nodes to create.
	 * @param bool  $dry_run           Dry run mode.
	 * @param bool  $hpos_enabled      HPOS status.
	 */
	private function create_new_products( $create_candidates, $dry_run, $hpos_enabled ) {
		if ( empty( $create_candidates ) ) {
			return;
		}

		// Initialize product creator
		if ( ! $this->product_creator ) {
			$this->product_creator = new EnviWeb_BestOffer_Product_Creator( $this->logger );
		}

		foreach ( $create_candidates as $product_node ) {
			$supplier_sku = (string) $product_node->SKU;

			// Check if we should try to claim existing matching product
			$claimed_product_id = false;
			if ( $this->settings->is_auto_claim_products_enabled() ) {
				// Extract data for matching
				$data = array(
					'supplier_sku'   => (string) $product_node->SKU,
					'ean'            => (string) $product_node->ean,
					'mpn'            => (string) $product_node->mpn,
					'brandname'      => (string) $product_node->brandname,
					'supplier_price' => (float) $product_node->supplier_price,
					'title'          => (string) $product_node->title,
				);

				$claimed_product_id = $this->product_creator->find_matching_product( $data );
			}

			if ( $dry_run ) {
				// Show what would be created or claimed
				$title = $this->product_creator->transform_title(
					(string) $product_node->title,
					(string) $product_node->ean
				);
				$sku = $this->product_creator->transform_sku( $supplier_sku );
				$price = $this->product_creator->calculate_price( (float) $product_node->supplier_price );

				if ( $claimed_product_id ) {
					WP_CLI::line( sprintf(
						'[DRY RUN] Would claim existing product #%d: %s (SKU: %s)',
						$claimed_product_id,
						$title,
						$sku
					) );
					$this->stats['claimed']++;
				} else {
					WP_CLI::line( sprintf(
						'[DRY RUN] Would create product: %s (SKU: %s, Price: €%s)',
						$title,
						$sku,
						number_format( $price, 2 )
					) );
					$this->stats['created']++;
				}
				continue;
			}

			try {
				// If we found a matching product, claim it instead of creating
				if ( $claimed_product_id ) {
					$data = array(
						'supplier_sku'   => (string) $product_node->SKU,
						'ean'            => (string) $product_node->ean,
						'mpn'            => (string) $product_node->mpn,
						'brandname'      => (string) $product_node->brandname,
						'supplier_price' => (float) $product_node->supplier_price,
						'title'          => (string) $product_node->title,
					);

					$this->product_creator->claim_existing_product( $claimed_product_id, $data );
					$product_id = $claimed_product_id;

					$this->stats['claimed']++;
					WP_CLI::line( "Product claimed: #$product_id ($supplier_sku)" );

					// Add to lookup cache immediately
					$this->product_lookup_cache[ $supplier_sku ] = $product_id;

					// Log claiming
					if ( $this->logger ) {
						$this->logger->log_product_change(
							$product_id,
							$supplier_sku,
							'product_claimed',
							'',
							'Claimed existing product for this feed'
						);
					}
				} else {
					// Create new product
					$product_id = $this->product_creator->create_product_from_xml( $product_node, $hpos_enabled );

					// Check if created as draft (no images)
					$product = wc_get_product( $product_id );
					if ( $product->get_status() === 'draft' ) {
						$this->stats['created_as_draft']++;
						WP_CLI::warning( "Product created as DRAFT (no images): #$product_id ($supplier_sku)" );
					} else {
						$this->stats['created']++;
						WP_CLI::line( "Product created: #$product_id ($supplier_sku)" );
					}

					// Add to lookup cache immediately
					$this->product_lookup_cache[ $supplier_sku ] = $product_id;

					// Log creation
					if ( $this->logger ) {
						$this->logger->log_product_created(
							$product_id,
							$supplier_sku,
							$product->get_status(),
							count( $product->get_gallery_image_ids() ) + ( $product->get_image_id() ? 1 : 0 )
						);
					}
				}

			} catch ( Exception $e ) {
				WP_CLI::warning( "Failed to create product $supplier_sku: " . $e->getMessage() );
				$this->stats['errors']++;

				if ( $this->logger ) {
					$this->logger->log_error( $supplier_sku, 'product_creation_failed', $e->getMessage() );
				}
			}

			// Small delay to prevent overwhelming server
			usleep( 10000 ); // 10ms
		}
	}

	/**
	 * Mark products missing from XML feed as draft
	 *
	 * Only runs on full syncs (offset=0, no limit).
	 * Only affects products with our feed source tag.
	 *
	 * @param bool $dry_run Dry run mode.
	 */
	private function mark_missing_products_as_draft( $dry_run ) {
		// Only run on full syncs (offset=0, no limit)
		if ( $this->cumulative_stats['batches'] > 1 ) {
			WP_CLI::line( 'Skipping missing product detection (partial sync)' );
			return;
		}

		// Check if enabled
		if ( ! $this->settings->is_auto_draft_missing_enabled() ) {
			WP_CLI::line( 'Auto-draft missing products: DISABLED' );
			return;
		}

		WP_CLI::line( 'Checking for missing products...' );

		global $wpdb;
		$feed_id = $this->settings->get_feed_identifier();

		// Find all PUBLISHED products with our feed source (skip already-draft products)
		$our_products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm_sku.meta_value as supplier_sku
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_feed ON p.ID = pm_feed.post_id
				INNER JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id
				WHERE p.post_type = 'product'
				AND pm_feed.meta_key = '_bestoffer_feed_source'
				AND pm_feed.meta_value = %s
				AND pm_sku.meta_key = 'supplier_sku'
				AND p.post_status = 'publish'",
				$feed_id
			)
		);

		$drafted_count = 0;

		foreach ( $our_products as $row ) {
			// Skip if in current XML
			if ( in_array( $row->supplier_sku, $this->xml_supplier_skus ) ) {
				continue;
			}

			// Check locks - only _block_xml_update blocks Best Offer sync
			$block_xml = get_post_meta( $row->ID, '_block_xml_update', true );

			if ( $block_xml ) {
				WP_CLI::line( "Product #$row->ID locked (XML block), skipping auto-draft" );
				continue;
			}

			// Check ignore in-stock setting
			$ignore_instock = $this->settings->is_ignore_instock_enabled();
			if ( $ignore_instock ) {
				$stock_status = get_post_meta( $row->ID, '_stock_status', true );
				if ( $stock_status === 'instock' ) {
					continue; // Don't draft in-stock products
				}
			}

			if ( $dry_run ) {
				WP_CLI::line( "[DRY RUN] Would draft missing product #$row->ID ($row->supplier_sku)" );
			} else {
				wp_update_post(
					array(
						'ID'          => $row->ID,
						'post_status' => 'draft',
					)
				);

				if ( $this->logger ) {
					$this->logger->log_product_auto_drafted(
						$row->ID,
						$row->supplier_sku,
						'Not in current XML feed'
					);
				}

				WP_CLI::line( "Auto-drafted missing product: #$row->ID ($row->supplier_sku)" );
			}

			$drafted_count++;
		}

		$this->stats['auto_drafted'] = $drafted_count;

		if ( $drafted_count > 0 ) {
			WP_CLI::success( "Auto-drafted $drafted_count missing products" );
		} else {
			WP_CLI::line( 'No missing products to draft' );
		}
	}

	/**
	 * Migrate existing products to feed tracking
	 *
	 * Tags all existing products with supplier_sku with our feed source.
	 * Only runs once per installation.
	 */
	private function migrate_existing_products() {
		// Only run once
		if ( get_option( 'bestoffer_products_migrated', false ) ) {
			return;
		}

		WP_CLI::line( 'Migrating existing products to feed tracking...' );

		global $wpdb;
		$feed_id = $this->settings->get_feed_identifier();

		// Find products with supplier_sku but no feed source
		$results = $wpdb->get_results(
			"SELECT DISTINCT pm1.post_id
			FROM {$wpdb->postmeta} pm1
			LEFT JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
				AND pm2.meta_key = '_bestoffer_feed_source'
			WHERE pm1.meta_key = 'supplier_sku'
			AND pm1.meta_value != ''
			AND pm2.meta_id IS NULL"
		);

		foreach ( $results as $row ) {
			update_post_meta( $row->post_id, '_bestoffer_feed_source', $feed_id );
		}

		update_option( 'bestoffer_products_migrated', true );
		WP_CLI::success( sprintf( 'Migrated %d existing products', count( $results ) ) );
	}
}

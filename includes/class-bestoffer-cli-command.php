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
	 * Reduced to 25 to prevent database lock issues and site slowdown
	 */
	const BATCH_SIZE = 25;

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
		'processed'       => 0,
		'updated'         => 0,
		'unchanged'       => 0,
		'skipped'         => 0,
		'skipped_instock' => 0,
		'locked'          => 0,
		'errors'          => 0,
		'not_found'       => 0,
	);

	/**
	 * Cumulative statistics (across all batches)
	 *
	 * @var array
	 */
	private $cumulative_stats = array(
		'batches'         => 0,
		'processed'       => 0,
		'updated'         => 0,
		'unchanged'       => 0,
		'skipped'         => 0,
		'skipped_instock' => 0,
		'locked'          => 0,
		'errors'          => 0,
		'not_found'       => 0,
		'total_time'      => 0,
	);

	/**
	 * Logger instance
	 *
	 * @var EnviWeb_BestOffer_Logger
	 */
	private $logger;

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
	 * : Run sync as specific user ID (default: 390)
	 * ---
	 * default: 390
	 * ---
	 *
	 * [--skip-validation]
	 * : Skip XML product count validation (not recommended)
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
	 *     # Run as specific user
	 *     wp bestoffer sync /path/to/best-offer.xml --user=390
	 *
	 *     # Skip XML validation (not recommended)
	 *     wp bestoffer sync /path/to/best-offer.xml --skip-validation
	 *
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {
		$this->start_time = microtime( true );

		// Reset stats for this batch
		$this->stats = array(
			'processed'       => 0,
			'updated'         => 0,
			'unchanged'       => 0,
			'skipped'         => 0,
			'skipped_instock' => 0,
			'locked'          => 0,
			'errors'          => 0,
			'not_found'       => 0,
		);

		// Reset performance tracking
		$this->products_checked = 0;
		$this->avg_time_per_product = 0;

		// Parse arguments
		$xml_file   = $args[0];
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : self::BATCH_SIZE;
		$offset     = isset( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : 0;
		$limit      = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : null;
		$dry_run    = isset( $assoc_args['dry-run'] );
		$user_id    = isset( $assoc_args['user'] ) ? intval( $assoc_args['user'] ) : 390;

		// Handle cumulative stats (passed from previous batch or initialize)
		if ( isset( $assoc_args['_cumulative'] ) ) {
			$this->cumulative_stats = json_decode( base64_decode( $assoc_args['_cumulative'] ), true );
		} else {
			// First batch - reset cumulative stats
			$this->cumulative_stats = array(
				'batches'         => 0,
				'processed'       => 0,
				'updated'         => 0,
				'unchanged'       => 0,
				'skipped'         => 0,
				'skipped_instock' => 0,
				'locked'          => 0,
				'errors'          => 0,
				'not_found'       => 0,
				'total_time'      => 0,
			);
		}

		// Validate file exists
		if ( ! file_exists( $xml_file ) ) {
			WP_CLI::error( sprintf( 'XML file not found: %s', $xml_file ) );
			return;
		}

		// Skip XML validation for resumed syncs (offset > 0) or when explicitly skipped
		$skip_validation = ( $offset > 0 ) || ( isset( $assoc_args['skip-validation'] ) && $assoc_args['skip-validation'] );
		
		if ( ! $skip_validation && ! $dry_run ) {
			// Validate XML file has reasonable product count
			if ( ! $this->validate_xml_file( $xml_file ) ) {
				return; // Validation failed after retries
			}
		}

		// Set user context for sync operations
		$original_user_id = get_current_user_id();
		$user = get_user_by( 'id', $user_id );
		
		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User ID %d not found. Please specify a valid user ID with --user parameter.', $user_id ) );
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

		// Check ignore instock setting
		$ignore_instock = get_option( 'bestoffer_ignore_instock', false );
		if ( $ignore_instock ) {
			WP_CLI::line( sprintf( 'Ignore in-stock products: ENABLED' ) );
		}

		// Build product lookup cache (only for first batch)
		if ( $offset === 0 ) {
			$this->build_product_lookup_cache();
		}

		// Count XML products for logging (only for first batch)
		$xml_product_count = 0;
		if ( $offset === 0 && ! $dry_run ) {
			$xml_product_count = $this->count_xml_products( $xml_file );
		}

		// Initialize logger (skip for dry run)
		if ( ! $dry_run ) {
			$this->logger = new EnviWeb_BestOffer_Logger();
			$this->logger->start_sync(
				$xml_file,
				array(
					'batch_size'   => $batch_size,
					'offset'       => $offset,
					'xml_products' => $xml_product_count,
				)
			);
		}

		// Note: WordPress deferrals removed to prevent site issues
		// The bulk operations and caching already provide sufficient performance

		// Process XML file
		$status        = 'completed';
		$error_message = '';
		
		try {
			$this->process_xml_file( $xml_file, $batch_size, $offset, $limit, $dry_run, $hpos_enabled );
		} catch ( Exception $e ) {
			$status        = 'failed';
			$error_message = $e->getMessage();
			WP_CLI::error( sprintf( 'Error processing XML: %s', $error_message ) );
			
			// Log the error
			if ( $this->logger ) {
				$this->stats['offset_end'] = $offset + $this->stats['processed'];
				$this->logger->end_sync( $this->stats, $status, $error_message );
			}
			
			// Restore original user
			if ( $original_user_id ) {
				wp_set_current_user( $original_user_id );
			}
			
			return;
		}

		// Calculate resume offset and elapsed time
		$resume_offset = $offset + $this->stats['processed'];
		$elapsed = microtime( true ) - $this->start_time;

		// Update cumulative stats
		$this->cumulative_stats['batches']++;
		$this->cumulative_stats['processed']       += $this->stats['processed'];
		$this->cumulative_stats['updated']         += $this->stats['updated'];
		$this->cumulative_stats['unchanged']       += $this->stats['unchanged'];
		$this->cumulative_stats['skipped']         += $this->stats['skipped'];
		$this->cumulative_stats['skipped_instock'] += $this->stats['skipped_instock'];
		$this->cumulative_stats['locked']          += $this->stats['locked'];
		$this->cumulative_stats['errors']          += $this->stats['errors'];
		$this->cumulative_stats['not_found']       += $this->stats['not_found'];
		$this->cumulative_stats['total_time']      += $elapsed;

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
	 * Loads ALL products with supplier_sku into memory for O(1) lookups
	 *
	 * @return int Number of products cached
	 */
	private function build_product_lookup_cache() {
		global $wpdb;

		WP_CLI::line( '🔧 Building product lookup cache...' );
		$cache_start = microtime( true );

		try {
			// Load all products with supplier_sku meta in one query
			// Use unbuffered query to reduce memory pressure
			$results = $wpdb->get_results(
				"SELECT post_id, meta_value as supplier_sku 
				FROM {$wpdb->postmeta} 
				WHERE meta_key = 'supplier_sku' 
				AND meta_value != ''
				LIMIT 100000",  // Safety limit to prevent memory issues
				OBJECT
			);

			$this->product_lookup_cache = array();
			foreach ( $results as $row ) {
				$this->product_lookup_cache[ $row->supplier_sku ] = (int) $row->post_id;
			}

			$cache_time = microtime( true ) - $cache_start;
			$count = count( $this->product_lookup_cache );
			
			WP_CLI::line( sprintf( 
				'✅ Cached %d products in %.3f seconds',
				$count,
				$cache_time
			) );

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

		// Limit batch size to prevent query overload
		if ( count( $product_ids ) > 50 ) {
			$product_ids = array_slice( $product_ids, 0, 50 );
		}

		try {
			// Clear existing cache for these products
			foreach ( $product_ids as $id ) {
				unset( $this->product_meta_cache[ $id ] );
			}

			// Meta keys we need to check
			$meta_keys = array(
				'fs_supplier_price',
				'_block_xml_update',
				'_skroutz_block_xml_update',
				'_block_custom_update',
				'_stock_status',
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
						'fs_supplier_price'         => '',
						'_block_xml_update'         => '',
						'_skroutz_block_xml_update' => '',
						'_block_custom_update'      => '',
						'_stock_status'             => '',
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
		// First pass: collect all product IDs that exist
		$product_ids = array();
		$xml_product_map = array(); // Map product_id to XML node
		
		foreach ( $xml_products_batch as $product_node ) {
			$supplier_sku = (string) $product_node->SKU;
			
			if ( empty( $supplier_sku ) ) {
				continue;
			}
			
			$product_id = $this->find_product_by_supplier_sku( $supplier_sku, $hpos_enabled );
			
			if ( $product_id ) {
				$product_ids[] = $product_id;
				$xml_product_map[ $product_id ] = $product_node;
			}
		}

		// Bulk load meta for all products in this batch
		if ( ! empty( $product_ids ) ) {
			$this->bulk_load_product_meta( $product_ids );
		}

		// Second pass: process each product with cached data (queues changes)
		$this->queued_changes = array(); // Reset queue
		foreach ( $xml_products_batch as $product_node ) {
			$this->process_product( $product_node, $dry_run, $hpos_enabled );
		}

		// Third pass: apply all queued changes in bulk
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
		global $wpdb;

		if ( empty( $this->queued_changes ) ) {
			return;
		}

		// Process changes WITHOUT a large transaction wrapper
		// This prevents holding database locks that block the entire site
		foreach ( $this->queued_changes as $change ) {
			$product_id = $change['product_id'];
			
			try {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					continue;
				}

				// Apply status change if needed
				if ( isset( $change['new_status'] ) ) {
					$product->set_status( $change['new_status'] );
				}

				// Update fs_supplier_price meta
				if ( isset( $change['supplier_price'] ) ) {
					if ( $hpos_enabled ) {
						$product->update_meta_data( 'fs_supplier_price', $change['supplier_price'] );
					} else {
						update_post_meta( $product_id, 'fs_supplier_price', $change['supplier_price'] );
					}
				}

				// Set to backorder mode
				$product->set_manage_stock( false );
				$product->set_backorders( 'yes' );
				$product->set_stock_status( 'onbackorder' );

				// Save changes - WooCommerce handles its own transaction per product
				$product->save();

				// For legacy compatibility
				if ( ! $hpos_enabled ) {
					update_post_meta( $product_id, '_manage_stock', 'no' );
					update_post_meta( $product_id, '_backorders', 'yes' );
					update_post_meta( $product_id, '_stock_status', 'onbackorder' );
				}

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

		// Small delay to prevent database overload
		// Gives other site queries time to execute
		usleep( 100000 ); // 0.1 second delay
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

		// Check if we should ignore in-stock products (use cached meta for performance)
		$ignore_instock = get_option( 'bestoffer_ignore_instock', false );
		$stock_status = $this->get_cached_meta( $product_id, '_stock_status' );
		if ( $ignore_instock && $stock_status === 'instock' ) {
			$this->stats['skipped_instock']++;
			
			if ( $dry_run ) {
				WP_CLI::line( sprintf(
					'[DRY RUN] Product #%d (%s) is IN STOCK - Skipped per settings',
					$product_id,
					$supplier_sku
				) );
			}
			
			return;
		}

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
		$price_changed = ( empty( $current_price ) || (float) $current_price !== $supplier_price );

		// Check if product is draft - draft products must be published even if price unchanged
		$is_draft = $product->get_status() === 'draft';

		// Don't proceed if dry run
		if ( $dry_run ) {
			$post_status = $product->get_status();
			if ( $price_changed ) {
				$message = sprintf(
					'[DRY RUN] Would update product #%d (%s) - Supplier Price: €%s → €%s, Backorder: Yes',
					$product_id,
					$supplier_sku,
					$current_price ? number_format( (float) $current_price, 2 ) : 'N/A',
					number_format( $supplier_price, 2 )
				);
				if ( $post_status === 'draft' ) {
					$message .= ' + PUBLISH';
				}
				WP_CLI::line( $message );
				$this->stats['updated']++;
			} elseif ( $is_draft ) {
				// Price unchanged but product is draft - still need to publish
				WP_CLI::line( sprintf(
					'[DRY RUN] Would publish draft product #%d (%s) - Price unchanged (€%s) + PUBLISH',
					$product_id,
					$supplier_sku,
					number_format( $supplier_price, 2 )
				) );
				$this->stats['updated']++;
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

		// Skip update ONLY if price unchanged AND product already published
		if ( ! $price_changed && ! $is_draft ) {
			$this->stats['unchanged']++;
			return;
		}

		// Update product
		try {
			$this->update_product( $product, $supplier_sku, $supplier_price, $hpos_enabled );
			$this->stats['updated']++;
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
		$locks = array(
			'_block_xml_update'         => 'XML Update Block',
			'_skroutz_block_xml_update' => 'Skroutz XML Update Block',
			'_block_custom_update'      => 'Custom Update Block',
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
	 */
	private function update_product( $product, $supplier_sku, $supplier_price, $hpos_enabled ) {
		$product_id = $product->get_id();

		// Get old values for logging (use cached meta)
		$old_price      = $this->get_cached_meta( $product_id, 'fs_supplier_price' );
		$old_backorders = $product->get_backorders();
		$old_stock      = $product->get_stock_status();
		$old_status     = $product->get_status();

		// Build change queue entry
		$change = array(
			'product_id'     => $product_id,
			'supplier_sku'   => $supplier_sku,
			'supplier_price' => $supplier_price,
			'log_changes'    => array(),
		);

		// If product is draft, publish it
		$status_changed = false;
		if ( $old_status === 'draft' ) {
			$change['new_status'] = 'publish';
			$status_changed = true;
		}

		// Queue log changes (only for fields that actually changed)
		if ( $this->logger ) {
			// Log price change
			if ( $old_price != $supplier_price ) {
				$change['log_changes'][] = array(
					'field'     => 'fs_supplier_price',
					'old_value' => $old_price,
					'new_value' => $supplier_price,
				);
			}

			// Log backorders change
			if ( $old_backorders !== 'yes' ) {
				$change['log_changes'][] = array(
					'field'     => 'backorders',
					'old_value' => $old_backorders,
					'new_value' => 'yes',
				);
			}

			// Log stock status change
			if ( $old_stock !== 'onbackorder' ) {
				$change['log_changes'][] = array(
					'field'     => 'stock_status',
					'old_value' => $old_stock,
					'new_value' => 'onbackorder',
				);
			}

			// Log post status change (draft to publish)
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
		WP_CLI::line( sprintf( 'Unchanged:       %d products (%.1f%%)', 
			$this->stats['unchanged'],
			$this->stats['processed'] > 0 ? ( $this->stats['unchanged'] / $this->stats['processed'] * 100 ) : 0 
		) );
		WP_CLI::line( sprintf( 'Locked:          %d products', $this->stats['locked'] ) );
		WP_CLI::line( sprintf( 'Skipped (empty): %d products', $this->stats['skipped'] ) );
		WP_CLI::line( sprintf( 'Skipped (stock): %d products', $this->stats['skipped_instock'] ) );
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
		WP_CLI::line( sprintf( '➖ Total Unchanged:   %d products (%.1f%%)', 
			$this->cumulative_stats['unchanged'],
			$this->cumulative_stats['processed'] > 0 ? ( $this->cumulative_stats['unchanged'] / $this->cumulative_stats['processed'] * 100 ) : 0
		) );
		WP_CLI::line( sprintf( '🔒 Total Locked:      %d products', $this->cumulative_stats['locked'] ) );
		WP_CLI::line( sprintf( '⏭️  Total Skipped:     %d products', $this->cumulative_stats['skipped'] ) );
		WP_CLI::line( sprintf( '📦 Skipped (stock):   %d products', $this->cumulative_stats['skipped_instock'] ) );
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
	 * Count total products in XML file
	 *
	 * @param string $xml_file Path to XML file
	 * @return int Number of products
	 */
	private function count_xml_products( $xml_file ) {
		$count = 0;
		
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
				
				WP_CLI::error( 'XML file is empty or invalid after all retries.' );
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
				
				WP_CLI::error( sprintf(
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
}

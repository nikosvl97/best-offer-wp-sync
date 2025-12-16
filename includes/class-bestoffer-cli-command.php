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
	 */
	const MAX_EXECUTION_TIME = 110; // 110 seconds to stay under 120 limit

	/**
	 * Batch size for processing
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
	 * Sync products from Best Offer XML feed
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the XML file
	 *
	 * [--batch-size=<number>]
	 * : Number of products to process per batch (default: 100)
	 * ---
	 * default: 100
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

		// Check ignore instock setting
		$ignore_instock = get_option( 'bestoffer_ignore_instock', false );
		if ( $ignore_instock ) {
			WP_CLI::line( sprintf( 'Ignore in-stock products: ENABLED' ) );
		}

		// Initialize logger (skip for dry run)
		if ( ! $dry_run ) {
			$this->logger = new EnviWeb_BestOffer_Logger();
			$this->logger->start_sync(
				$xml_file,
				array(
					'batch_size' => $batch_size,
					'offset'     => $offset,
				)
			);
		}

		// Process XML file
		$status        = 'completed';
		$error_message = '';
		$timeout_occurred = false;
		
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

		// Check if we hit timeout
		if ( $this->is_timeout_approaching() ) {
			$status = 'timeout';
			$timeout_occurred = true;
			WP_CLI::warning( 'Sync stopped due to timeout approaching' );
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

		// Display statistics
		$this->display_stats();

		// Restore original user
		if ( $original_user_id ) {
			wp_set_current_user( $original_user_id );
		}

		// Auto-resume if timeout occurred
		if ( $timeout_occurred && ! $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
			WP_CLI::line( sprintf( '🔄 Auto-resuming from product %d...', $resume_offset + 1 ) );
			WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
			WP_CLI::line( '' );
			
			// Small delay to let things settle
			sleep( 2 );
			
			// Encode cumulative stats to pass to next batch
			$cumulative_encoded = base64_encode( json_encode( $this->cumulative_stats ) );
			
			// Recursively call sync with new offset and cumulative stats
			$this->sync( array( $xml_file ), array(
				'batch-size'   => $batch_size,
				'offset'       => $resume_offset,
				'limit'        => $limit,
				'user'         => $user_id,
				'_cumulative'  => $cumulative_encoded,
			) );
			
			return;
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

		// Create progress bar
		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing products', $limit ? $limit : 1000 );

		// Read XML products one by one
		while ( $reader->read() ) {
			// Check execution time
			if ( $this->is_timeout_approaching() ) {
				WP_CLI::warning( 'Approaching timeout limit. Stopping processing.' );
				WP_CLI::line( sprintf( 'Resume from offset: %d', $offset + $processed_count ) );
				break;
			}

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
					$this->process_product( $product_node, $dry_run, $hpos_enabled );
					$processed_count++;
					$this->stats['processed']++;
					$progress->tick();
				}

				$current_product++;
				
				// Note: We DON'T call $reader->next() here because the while loop's
				// $reader->read() will naturally advance to the next element
			}
		}

		$progress->finish();
		$reader->close();
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

		// Check if we should ignore in-stock products
		$ignore_instock = get_option( 'bestoffer_ignore_instock', false );
		if ( $ignore_instock && $product->get_stock_status() === 'instock' ) {
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

		// Check if price has changed
		$current_price = get_post_meta( $product_id, 'fs_supplier_price', true );
		$price_changed = ( empty( $current_price ) || (float) $current_price !== $supplier_price );

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

		// Skip update if price hasn't changed
		if ( ! $price_changed ) {
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
	 *
	 * @param string $supplier_sku Supplier SKU
	 * @param bool   $hpos_enabled HPOS status
	 * @return int|false Product ID or false if not found
	 */
	private function find_product_by_supplier_sku( $supplier_sku, $hpos_enabled ) {
		global $wpdb;

		// Sanitize input
		$supplier_sku = sanitize_text_field( $supplier_sku );

		if ( $hpos_enabled ) {
			// Query using HPOS-compatible method
			$args = array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => 'supplier_sku',
						'value'   => $supplier_sku,
						'compare' => '=',
					),
				),
			);
			$products = wc_get_products( $args );
			
			if ( ! empty( $products ) ) {
				return $products[0];
			}
		} else {
			// Legacy query for backward compatibility
			$product_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} 
					WHERE meta_key = %s AND meta_value = %s 
					LIMIT 1",
					'supplier_sku',
					$supplier_sku
				)
			);

			if ( $product_id ) {
				return intval( $product_id );
			}
		}

		return false;
	}

	/**
	 * Check if product has update locks
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
			$lock_value = get_post_meta( $product_id, $meta_key, true );
			
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
	 *
	 * @param WC_Product $product WooCommerce product object
	 * @param string     $supplier_sku Supplier SKU
	 * @param float      $supplier_price Supplier price
	 * @param bool       $hpos_enabled HPOS status
	 */
	private function update_product( $product, $supplier_sku, $supplier_price, $hpos_enabled ) {
		$product_id = $product->get_id();

		// Get old values for logging
		$old_price      = get_post_meta( $product_id, 'fs_supplier_price', true );
		$old_backorders = $product->get_backorders();
		$old_stock      = $product->get_stock_status();
		$old_status     = $product->get_status();

		// If product is draft, publish it
		$status_changed = false;
		if ( $old_status === 'draft' ) {
			$product->set_status( 'publish' );
			$status_changed = true;
		}

		// Update fs_supplier_price meta
		if ( $hpos_enabled ) {
			$product->update_meta_data( 'fs_supplier_price', $supplier_price );
		} else {
			update_post_meta( $product_id, 'fs_supplier_price', $supplier_price );
		}

		// Set to backorder mode (no stock management)
		$product->set_manage_stock( false );
		$product->set_backorders( 'yes' );
		$product->set_stock_status( 'onbackorder' );

		// Save changes - This triggers all WooCommerce hooks:
		// - woocommerce_update_product
		// - woocommerce_product_object_updated_props
		// - save_post_product
		// - woocommerce_after_product_object_save
		// And any custom hooks you've added to product save actions
		$product->save();

		// For legacy compatibility, ensure meta is saved in postmeta table too
		if ( ! $hpos_enabled ) {
			update_post_meta( $product_id, '_manage_stock', 'no' );
			update_post_meta( $product_id, '_backorders', 'yes' );
			update_post_meta( $product_id, '_stock_status', 'onbackorder' );
		}

		// Log changes
		if ( $this->logger ) {
			// Log price change
			if ( $old_price != $supplier_price ) {
				$this->logger->log_product_change( $product_id, $supplier_sku, 'fs_supplier_price', $old_price, $supplier_price );
			}

			// Log backorders change
			if ( $old_backorders !== 'yes' ) {
				$this->logger->log_product_change( $product_id, $supplier_sku, 'backorders', $old_backorders, 'yes' );
			}

			// Log stock status change
			if ( $old_stock !== 'onbackorder' ) {
				$this->logger->log_product_change( $product_id, $supplier_sku, 'stock_status', $old_stock, 'onbackorder' );
			}

			// Log post status change (draft to publish)
			if ( $status_changed ) {
				$this->logger->log_product_change( $product_id, $supplier_sku, 'post_status', $old_status, 'publish' );
			}
		}

		// Show message if product was published
		if ( $status_changed ) {
			WP_CLI::line( sprintf(
				'  → Product #%d (%s) was DRAFT - now PUBLISHED',
				$product_id,
				$supplier_sku
			) );
		}
	}

	/**
	 * Check if we're approaching timeout
	 *
	 * @return bool
	 */
	private function is_timeout_approaching() {
		$elapsed = microtime( true ) - $this->start_time;
		return $elapsed >= self::MAX_EXECUTION_TIME;
	}

	/**
	 * Display statistics (current batch)
	 */
	private function display_stats() {
		$elapsed = microtime( true ) - $this->start_time;

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '=== Batch #%d Statistics ===', $this->cumulative_stats['batches'] ) );
		WP_CLI::line( sprintf( 'Processed:       %d products', $this->stats['processed'] ) );
		WP_CLI::line( sprintf( 'Updated:         %d products', $this->stats['updated'] ) );
		WP_CLI::line( sprintf( 'Unchanged:       %d products', $this->stats['unchanged'] ) );
		WP_CLI::line( sprintf( 'Locked:          %d products', $this->stats['locked'] ) );
		WP_CLI::line( sprintf( 'Skipped (empty): %d products', $this->stats['skipped'] ) );
		WP_CLI::line( sprintf( 'Skipped (stock): %d products', $this->stats['skipped_instock'] ) );
		WP_CLI::line( sprintf( 'Not Found:       %d products', $this->stats['not_found'] ) );
		WP_CLI::line( sprintf( 'Errors:          %d products', $this->stats['errors'] ) );
		WP_CLI::line( sprintf( 'Time:            %.2f seconds', $elapsed ) );
		WP_CLI::line( '' );
	}

	/**
	 * Display cumulative statistics (across all batches)
	 */
	private function display_cumulative_stats() {
		WP_CLI::line( '' );
		WP_CLI::line( '╔════════════════════════════════════════════════╗' );
		WP_CLI::line( '║          CUMULATIVE SYNC TOTALS                ║' );
		WP_CLI::line( '╚════════════════════════════════════════════════╝' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '📦 Total Batches:     %d', $this->cumulative_stats['batches'] ) );
		WP_CLI::line( sprintf( '📊 Total Processed:   %d products', $this->cumulative_stats['processed'] ) );
		WP_CLI::line( sprintf( '✅ Total Updated:     %d products', $this->cumulative_stats['updated'] ) );
		WP_CLI::line( sprintf( '➖ Total Unchanged:   %d products', $this->cumulative_stats['unchanged'] ) );
		WP_CLI::line( sprintf( '🔒 Total Locked:      %d products', $this->cumulative_stats['locked'] ) );
		WP_CLI::line( sprintf( '⏭️  Total Skipped:     %d products', $this->cumulative_stats['skipped'] ) );
		WP_CLI::line( sprintf( '📦 Skipped (stock):   %d products', $this->cumulative_stats['skipped_instock'] ) );
		WP_CLI::line( sprintf( '❌ Total Not Found:   %d products', $this->cumulative_stats['not_found'] ) );
		WP_CLI::line( sprintf( '⚠️  Total Errors:      %d products', $this->cumulative_stats['errors'] ) );
		WP_CLI::line( sprintf( '⏱️  Total Time:        %.2f seconds', $this->cumulative_stats['total_time'] ) );
		WP_CLI::line( sprintf( '⚡ Avg per batch:     %.2f seconds', $this->cumulative_stats['total_time'] / $this->cumulative_stats['batches'] ) );
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
}

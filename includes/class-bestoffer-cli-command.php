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
	 * Statistics
	 *
	 * @var array
	 */
	private $stats = array(
		'updated'  => 0,
		'skipped'  => 0,
		'errors'   => 0,
		'not_found' => 0,
	);

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
	 * @when after_wp_load
	 */
	public function sync( $args, $assoc_args ) {
		$this->start_time = microtime( true );

		// Parse arguments
		$xml_file   = $args[0];
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : self::BATCH_SIZE;
		$offset     = isset( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : 0;
		$limit      = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : null;
		$dry_run    = isset( $assoc_args['dry-run'] );

		// Validate file exists
		if ( ! file_exists( $xml_file ) ) {
			WP_CLI::error( sprintf( 'XML file not found: %s', $xml_file ) );
			return;
		}

		WP_CLI::line( sprintf( 'Starting Best Offer sync from: %s', $xml_file ) );
		if ( $dry_run ) {
			WP_CLI::warning( 'DRY RUN MODE - No changes will be made' );
		}

		// Check if WooCommerce is using HPOS
		$hpos_enabled = $this->is_hpos_enabled();
		WP_CLI::line( sprintf( 'WooCommerce storage: %s', $hpos_enabled ? 'HPOS' : 'Legacy' ) );

		// Process XML file
		try {
			$this->process_xml_file( $xml_file, $batch_size, $offset, $limit, $dry_run, $hpos_enabled );
		} catch ( Exception $e ) {
			WP_CLI::error( sprintf( 'Error processing XML: %s', $e->getMessage() ) );
			return;
		}

		// Display statistics
		$this->display_stats();

		WP_CLI::success( 'Sync completed!' );
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
					$reader->next( 'product' );
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
					$progress->tick();
				}

				$current_product++;

				// Move to next product
				$reader->next( 'product' );
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
		$supplier_sku      = (string) $product_node->SKU;
		$supplier_quantity = (int) $product_node->supplier_quantity;
		$supplier_price    = (float) $product_node->supplier_price;

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

		// Don't proceed if dry run
		if ( $dry_run ) {
			WP_CLI::line( sprintf(
				'[DRY RUN] Would update product #%d (%s) - Price: %s, Stock: %d',
				$product_id,
				$supplier_sku,
				$supplier_price,
				$supplier_quantity
			) );
			$this->stats['updated']++;
			return;
		}

		// Update product
		try {
			$this->update_product( $product, $supplier_price, $supplier_quantity, $hpos_enabled );
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
	 * Update product with new data
	 *
	 * @param WC_Product $product WooCommerce product object
	 * @param float      $supplier_price Supplier price
	 * @param int        $supplier_quantity Supplier quantity
	 * @param bool       $hpos_enabled HPOS status
	 */
	private function update_product( $product, $supplier_price, $supplier_quantity, $hpos_enabled ) {
		$product_id = $product->get_id();

		// Update fs_supplier_price meta
		if ( $hpos_enabled ) {
			$product->update_meta_data( 'fs_supplier_price', $supplier_price );
		} else {
			update_post_meta( $product_id, 'fs_supplier_price', $supplier_price );
		}

		// Update stock management
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $supplier_quantity );

		// Set stock status based on quantity
		if ( $supplier_quantity > 0 ) {
			$product->set_stock_status( 'instock' );
		} else {
			$product->set_stock_status( 'outofstock' );
		}

		// Save changes
		$product->save();

		// For legacy compatibility, ensure meta is saved in postmeta table too
		if ( ! $hpos_enabled ) {
			update_post_meta( $product_id, '_stock', $supplier_quantity );
			update_post_meta( $product_id, '_manage_stock', 'yes' );
			update_post_meta( $product_id, '_stock_status', $supplier_quantity > 0 ? 'instock' : 'outofstock' );
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
	 * Display statistics
	 */
	private function display_stats() {
		$elapsed = microtime( true ) - $this->start_time;

		WP_CLI::line( '' );
		WP_CLI::line( '=== Sync Statistics ===' );
		WP_CLI::line( sprintf( 'Updated:   %d products', $this->stats['updated'] ) );
		WP_CLI::line( sprintf( 'Not Found: %d products', $this->stats['not_found'] ) );
		WP_CLI::line( sprintf( 'Skipped:   %d products', $this->stats['skipped'] ) );
		WP_CLI::line( sprintf( 'Errors:    %d products', $this->stats['errors'] ) );
		WP_CLI::line( sprintf( 'Time:      %.2f seconds', $elapsed ) );
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


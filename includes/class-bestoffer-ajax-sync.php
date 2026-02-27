<?php
/**
 * AJAX Sync Handler for Best Offer WP Sync
 *
 * Handles AJAX-based synchronization with live progress updates.
 *
 * @package Best_Offer_Sync
 * @since 1.2.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class EnviWeb_BestOffer_AJAX_Sync
 *
 * Manages AJAX sync operations with real-time progress tracking.
 */
class EnviWeb_BestOffer_AJAX_Sync {

	/**
	 * Batch size for AJAX processing (increased for better throughput)
	 */
	const AJAX_BATCH_SIZE = 50;

	/**
	 * Maximum execution time per AJAX request (seconds)
	 */
	const MAX_EXECUTION_TIME = 30;

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
	 * Duplicate SKUs found during cache building
	 *
	 * @var array
	 */
	private $duplicate_skus = array();

	/**
	 * Register AJAX handlers
	 */
	public function __construct() {
		add_action( 'wp_ajax_bestoffer_start_sync', array( $this, 'ajax_start_sync' ) );
		add_action( 'wp_ajax_bestoffer_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_bestoffer_get_progress', array( $this, 'ajax_get_progress' ) );
		add_action( 'wp_ajax_bestoffer_cancel_sync', array( $this, 'ajax_cancel_sync' ) );
		add_action( 'wp_ajax_bestoffer_upload_xml', array( $this, 'ajax_upload_xml' ) );
	}

	/**
	 * AJAX: Start new sync process
	 */
	public function ajax_start_sync() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$xml_file = isset( $_POST['xml_file'] ) ? sanitize_text_field( $_POST['xml_file'] ) : '';
		$settings = isset( $_POST['settings'] ) ? $_POST['settings'] : array();

		// Clear all PHP file caches for the XML file to ensure fresh content
		if ( ! empty( $xml_file ) ) {
			clearstatcache( true, $xml_file );
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $xml_file, true );
			}
			clearstatcache( true );
		}

		// Validate XML file
		if ( empty( $xml_file ) || ! file_exists( $xml_file ) ) {
			wp_send_json_error( array( 'message' => 'Invalid XML file path' ) );
		}

		// Check if another sync is running
		$current_sync = get_transient( 'bestoffer_ajax_sync_progress' );
		if ( $current_sync && $current_sync['status'] === 'running' ) {
			wp_send_json_error( array( 'message' => 'Another sync is already running' ) );
		}

		// Count total products in XML
		$xml_product_count = $this->count_xml_products( $xml_file );

		if ( $xml_product_count === 0 ) {
			wp_send_json_error( array( 'message' => 'No products found in XML file' ) );
		}

		// Cache options once (avoid repeated get_option calls in loops)
		$cached_options = array(
			'ignore_instock'       => (bool) get_option( 'bestoffer_ignore_instock', false ),
			'feed_identifier'      => get_option( 'bestoffer_feed_identifier', 'best_offer_xml' ),
			'auto_draft_missing'   => (bool) get_option( 'bestoffer_auto_draft_missing', true ),
			'sync_supplier_price'  => (bool) get_option( 'bestoffer_sync_supplier_price', true ),
			'sync_stock_status'    => (bool) get_option( 'bestoffer_sync_stock_status', true ),
			'sync_backorder_mode'  => (bool) get_option( 'bestoffer_sync_backorder_mode', true ),
			'sync_publish_drafts'  => (bool) get_option( 'bestoffer_sync_publish_drafts', true ),
			'sync_regular_price'   => (bool) get_option( 'bestoffer_sync_regular_price', false ),
			'price_markup'         => (float) get_option( 'bestoffer_price_markup', 1.40 ),
		);

		// Initialize progress tracking
		$progress = array(
			'status'             => 'running',
			'xml_file'           => $xml_file,
			'total_products'     => $xml_product_count,
			'processed'          => 0,
			'current_batch'      => 0,
			'offset'             => 0,
			'stats'              => array(
				'updated'           => 0,
				'created'           => 0,
				'created_as_draft'  => 0,
				'auto_drafted'      => 0,
				'unchanged'         => 0,
				'locked'            => 0,
				'skipped'           => 0,
				'errors'            => 0,
				'not_found'         => 0,
			),
			'start_time'         => time(),
			'last_update'        => time(),
			'error_message'      => '',
			'settings'           => $settings,
			'cached_options'     => $cached_options,
		);

		// Initialize sync log
		$logger = new EnviWeb_BestOffer_Logger();
		$sync_log_id = $logger->start_sync(
			$xml_file,
			array(
				'batch_size'   => self::AJAX_BATCH_SIZE,
				'offset'       => 0,
				'xml_products' => $xml_product_count,
			)
		);

		$progress['sync_log_id'] = $sync_log_id;

		// Save progress
		set_transient( 'bestoffer_ajax_sync_progress', $progress, 4 * HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'message'        => 'Sync started successfully',
				'total_products' => $xml_product_count,
				'sync_log_id'    => $sync_log_id,
			)
		);
	}

	/**
	 * AJAX: Process single batch
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// Get current progress
		$progress = get_transient( 'bestoffer_ajax_sync_progress' );

		if ( ! $progress || $progress['status'] !== 'running' ) {
			wp_send_json_error( array( 'message' => 'No active sync found' ) );
		}

		// Set execution time limit
		set_time_limit( self::MAX_EXECUTION_TIME );

		$start_time = microtime( true );

		try {
			// Load required classes
			require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-settings.php';
			require_once ENVIWEB_BESTOFFER_PLUGIN_DIR . 'includes/class-bestoffer-product-creator.php';

			// Build product lookup cache (only once per batch request, resets between AJAX calls)
			$this->build_product_lookup_cache();

			// Check if WooCommerce HPOS is enabled
			$hpos_enabled = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) &&
							\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

			// Get cached options from progress
			$cached_options = isset( $progress['cached_options'] ) ? $progress['cached_options'] : array(
				'ignore_instock'       => false,
				'feed_identifier'      => 'best_offer_xml',
				'auto_draft_missing'   => true,
				'sync_supplier_price'  => true,
				'sync_stock_status'    => true,
				'sync_backorder_mode'  => true,
				'sync_publish_drafts'  => true,
				'sync_regular_price'   => false,
				'price_markup'         => 1.40,
			);

			// Process batch with cached options
			$batch_result = $this->process_xml_batch(
				$progress['xml_file'],
				$progress['offset'],
				self::AJAX_BATCH_SIZE,
				$hpos_enabled,
				$progress['sync_log_id'],
				$cached_options
			);

			// Update progress
			$progress['processed'] += $batch_result['processed'];
			$progress['offset'] += $batch_result['processed'];
			$progress['current_batch']++;
			$progress['last_update'] = time();

			// Merge stats
			foreach ( $batch_result['stats'] as $key => $value ) {
				if ( isset( $progress['stats'][ $key ] ) ) {
					$progress['stats'][ $key ] += $value;
				}
			}

			// Check if complete
			if ( $progress['offset'] >= $progress['total_products'] || $batch_result['processed'] === 0 ) {
				$progress['status'] = 'completed';
				$progress['end_time'] = time();

				// Finalize sync log - FIX: Set sync_log_id before calling end_sync
				$logger = new EnviWeb_BestOffer_Logger();
				$logger->set_sync_log_id( $progress['sync_log_id'] );
				$logger->end_sync( $progress['stats'], 'completed', '' );

				// Mark missing products as draft if enabled (use cached option)
				if ( $cached_options['auto_draft_missing'] ) {
					$drafted = $this->mark_missing_products_as_draft( $progress['xml_file'], $progress['sync_log_id'] );
					$progress['stats']['auto_drafted'] = $drafted;
				}
			}

			set_transient( 'bestoffer_ajax_sync_progress', $progress, 4 * HOUR_IN_SECONDS );

			$elapsed = microtime( true ) - $start_time;

			wp_send_json_success(
				array(
					'status'         => $progress['status'],
					'processed'      => $progress['processed'],
					'total'          => $progress['total_products'],
					'percentage'     => round( ( $progress['processed'] / $progress['total_products'] ) * 100, 1 ),
					'current_batch'  => $progress['current_batch'],
					'stats'          => $progress['stats'],
					'elapsed'        => $elapsed,
					'complete'       => $progress['status'] === 'completed',
				)
			);

		} catch ( Exception $e ) {
			$progress['status'] = 'failed';
			$progress['error_message'] = $e->getMessage();
			set_transient( 'bestoffer_ajax_sync_progress', $progress, 4 * HOUR_IN_SECONDS );

			// Log error - FIX: Set sync_log_id before calling end_sync
			if ( isset( $progress['sync_log_id'] ) ) {
				$logger = new EnviWeb_BestOffer_Logger();
				$logger->set_sync_log_id( $progress['sync_log_id'] );
				$logger->end_sync( $progress['stats'], 'failed', $e->getMessage() );
			}

			wp_send_json_error(
				array(
					'message' => 'Batch processing failed: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX: Get current progress
	 */
	public function ajax_get_progress() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$progress = get_transient( 'bestoffer_ajax_sync_progress' );

		if ( ! $progress ) {
			wp_send_json_success(
				array(
					'status' => 'idle',
				)
			);
		}

		$response = array(
			'status'         => $progress['status'],
			'processed'      => $progress['processed'],
			'total'          => $progress['total_products'],
			'percentage'     => $progress['total_products'] > 0 ? round( ( $progress['processed'] / $progress['total_products'] ) * 100, 1 ) : 0,
			'current_batch'  => $progress['current_batch'],
			'stats'          => $progress['stats'],
			'elapsed_time'   => isset( $progress['end_time'] ) ? $progress['end_time'] - $progress['start_time'] : time() - $progress['start_time'],
			'error_message'  => $progress['error_message'],
		);

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Cancel running sync
	 */
	public function ajax_cancel_sync() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$progress = get_transient( 'bestoffer_ajax_sync_progress' );

		if ( $progress ) {
			$progress['status'] = 'cancelled';
			$progress['end_time'] = time();
			set_transient( 'bestoffer_ajax_sync_progress', $progress, HOUR_IN_SECONDS );

			// Log cancellation - FIX: Set sync_log_id before calling end_sync
			if ( isset( $progress['sync_log_id'] ) ) {
				$logger = new EnviWeb_BestOffer_Logger();
				$logger->set_sync_log_id( $progress['sync_log_id'] );
				$logger->end_sync( $progress['stats'], 'failed', 'Cancelled by user' );
			}
		}

		delete_transient( 'bestoffer_ajax_sync_progress' );

		wp_send_json_success( array( 'message' => 'Sync cancelled successfully' ) );
	}

	/**
	 * AJAX: Upload XML file
	 */
	public function ajax_upload_xml() {
		check_ajax_referer( 'bestoffer_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		if ( empty( $_FILES['xml_file'] ) ) {
			wp_send_json_error( array( 'message' => 'No file uploaded' ) );
		}

		$file = $_FILES['xml_file'];

		// Validate file type
		$allowed_types = array( 'text/xml', 'application/xml' );
		$file_type = mime_content_type( $file['tmp_name'] );

		if ( ! in_array( $file_type, $allowed_types ) && pathinfo( $file['name'], PATHINFO_EXTENSION ) !== 'xml' ) {
			wp_send_json_error( array( 'message' => 'Invalid file type. Only XML files are allowed.' ) );
		}

		// Create uploads directory if needed
		$upload_dir = wp_upload_dir();
		$bestoffer_dir = $upload_dir['basedir'] . '/bestoffer-xml/';

		if ( ! file_exists( $bestoffer_dir ) ) {
			wp_mkdir_p( $bestoffer_dir );
		}

		// Generate unique filename
		$filename = 'best-offer-' . date( 'Y-m-d-H-i-s' ) . '.xml';
		$filepath = $bestoffer_dir . $filename;

		// Move uploaded file
		if ( ! move_uploaded_file( $file['tmp_name'], $filepath ) ) {
			wp_send_json_error( array( 'message' => 'Failed to save uploaded file' ) );
		}

		// Count products
		$product_count = $this->count_xml_products( $filepath );

		wp_send_json_success(
			array(
				'message'       => 'File uploaded successfully',
				'filepath'      => $filepath,
				'filename'      => $filename,
				'product_count' => $product_count,
			)
		);
	}

	/**
	 * Process XML batch
	 *
	 * @param string $xml_file       XML file path.
	 * @param int    $offset         Starting offset.
	 * @param int    $batch_size     Batch size.
	 * @param bool   $hpos_enabled   HPOS status.
	 * @param int    $sync_log_id    Sync log ID.
	 * @param array  $cached_options Cached options array.
	 * @return array Batch results
	 */
	private function process_xml_batch( $xml_file, $offset, $batch_size, $hpos_enabled, $sync_log_id, $cached_options = array() ) {
		$stats = array(
			'processed'         => 0,
			'updated'           => 0,
			'created'           => 0,
			'created_as_draft'  => 0,
			'auto_drafted'      => 0,
			'published'         => 0,
			'unchanged'         => 0,
			'locked'            => 0,
			'skipped'           => 0,
			'errors'            => 0,
			'not_found'         => 0,
		);

		$logger = new EnviWeb_BestOffer_Logger();
		$logger->set_sync_log_id( $sync_log_id );
		$settings = new EnviWeb_BestOffer_Settings();
		$product_creator = new EnviWeb_BestOffer_Product_Creator( $logger );

		// Clear PHP's file stat cache for this file to ensure we read fresh content
		clearstatcache( true, $xml_file );

		// Open XML file
		$reader = new XMLReader();
		$reader->open( $xml_file );

		$current_position = 0;
		$batch_count = 0;

		// First pass: collect product IDs for bulk meta loading
		$batch_products = array();
		$temp_reader = new XMLReader();
		$temp_reader->open( $xml_file );
		$temp_position = 0;
		$temp_count = 0;

		while ( $temp_reader->read() && $temp_count < $batch_size ) {
			if ( $temp_reader->nodeType === XMLReader::ELEMENT && $temp_reader->name === 'product' ) {
				if ( $temp_position < $offset ) {
					$temp_position++;
					continue;
				}

				$product_node = simplexml_load_string( $temp_reader->readOuterXML() );
				if ( $product_node ) {
					$supplier_sku = (string) $product_node->SKU;
					if ( ! empty( $supplier_sku ) ) {
						$product_id = $this->find_product_by_supplier_sku_cached( $supplier_sku );
						if ( $product_id ) {
							$batch_products[] = $product_id;
						}
					}
				}
				$temp_count++;
				$temp_position++;
			}
		}
		$temp_reader->close();

		// Bulk load meta for all products in batch
		if ( ! empty( $batch_products ) ) {
			$this->bulk_load_product_meta( $batch_products );
		}

		// Second pass: process products
		while ( $reader->read() && $batch_count < $batch_size ) {
			if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'product' ) {
				if ( $current_position < $offset ) {
					$current_position++;
					continue;
				}

				$product_node = simplexml_load_string( $reader->readOuterXML() );

				if ( ! $product_node ) {
					continue;
				}

				$supplier_sku = (string) $product_node->SKU;

				if ( empty( $supplier_sku ) ) {
					$stats['skipped']++;
					$batch_count++;
					$current_position++;
					continue;
				}

				// Find existing product using cache
				$product_id = $this->find_product_by_supplier_sku_cached( $supplier_sku );

				if ( $product_id ) {
					// Update existing product with cached options
					$this->update_product( $product_id, $product_node, $hpos_enabled, $logger, $stats, $cached_options );
				} elseif ( $settings->is_product_creation_enabled() ) {
					// Create new product
					try {
						$new_product_id = $product_creator->create_product_from_xml( $product_node, $hpos_enabled );
						$product = wc_get_product( $new_product_id );

						if ( $product->get_status() === 'draft' ) {
							$stats['created_as_draft']++;
						} else {
							$stats['created']++;
						}

						$logger->log_product_created(
							$new_product_id,
							$supplier_sku,
							$product->get_status(),
							count( $product->get_gallery_image_ids() ) + ( $product->get_image_id() ? 1 : 0 )
						);
					} catch ( Exception $e ) {
						$stats['errors']++;
						$logger->log_error( $supplier_sku, 'product_creation_failed', $e->getMessage() );
					}
				} else {
					$stats['not_found']++;
				}

				$stats['processed']++;
				$batch_count++;
				$current_position++;
			}
		}

		$reader->close();

		// Flush logs
		$logger->flush_queued_logs();

		return array(
			'processed' => $stats['processed'],
			'stats'     => $stats,
		);
	}

	/**
	 * Find product by supplier SKU
	 *
	 * @param string $supplier_sku  Supplier SKU.
	 * @param bool   $hpos_enabled  HPOS status.
	 * @return int|false Product ID or false
	 */
	private function find_product_by_supplier_sku( $supplier_sku, $hpos_enabled ) {
		global $wpdb;

		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = 'supplier_sku' AND meta_value = %s LIMIT 1",
				$supplier_sku
			)
		);

		return $product_id ? (int) $product_id : false;
	}

	/**
	 * Update existing product
	 *
	 * @param int              $product_id     Product ID.
	 * @param SimpleXMLElement $product_node   XML product node.
	 * @param bool             $hpos_enabled   HPOS status.
	 * @param object           $logger         Logger instance.
	 * @param array            &$stats         Stats array (passed by reference).
	 * @param array            $cached_options Cached options array.
	 */
	private function update_product( $product_id, $product_node, $hpos_enabled, $logger, &$stats, $cached_options = array() ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			$stats['errors']++;
			return;
		}

		$supplier_price = (float) $product_node->supplier_price;
		// Use cached meta instead of get_post_meta
		$old_price = $this->get_cached_meta( $product_id, 'fs_supplier_price' );

		// Check locks using cached meta
		// Only _block_xml_update fully blocks the sync
		// _block_custom_update does NOT block - we only sync supplier price, stock, and post status
		// _skroutz_block_xml_update is for Skroutz feeds, not Best Offer
		$block_xml = $this->get_cached_meta( $product_id, '_block_xml_update' );

		if ( $block_xml ) {
			$stats['locked']++;
			return;
		}

		// Check if product is in-stock (use cached option and meta)
		$ignore_instock = isset( $cached_options['ignore_instock'] ) ? $cached_options['ignore_instock'] : false;
		$stock_status = $this->get_cached_meta( $product_id, '_stock_status' );
		$is_instock = $ignore_instock && $stock_status === 'instock';

		// Get sync field settings
		$sync_supplier_price = isset( $cached_options['sync_supplier_price'] ) ? $cached_options['sync_supplier_price'] : true;
		$sync_stock_status   = isset( $cached_options['sync_stock_status'] ) ? $cached_options['sync_stock_status'] : true;
		$sync_backorder_mode = isset( $cached_options['sync_backorder_mode'] ) ? $cached_options['sync_backorder_mode'] : true;
		$sync_publish_drafts = isset( $cached_options['sync_publish_drafts'] ) ? $cached_options['sync_publish_drafts'] : true;
		$sync_regular_price  = isset( $cached_options['sync_regular_price'] ) ? $cached_options['sync_regular_price'] : false;
		$price_markup        = isset( $cached_options['price_markup'] ) ? $cached_options['price_markup'] : 1.40;

		// Update supplier price if enabled (always update, even for in-stock)
		if ( $sync_supplier_price ) {
			if ( $hpos_enabled ) {
				$product->update_meta_data( 'fs_supplier_price', $supplier_price );
			} else {
				update_post_meta( $product_id, 'fs_supplier_price', $supplier_price );
			}
		}

		// Update regular price if enabled (skip for in-stock products)
		if ( ! $is_instock && $sync_regular_price ) {
			$regular_price = $supplier_price * $price_markup;
			$product->set_regular_price( $regular_price );
			$product->set_price( $regular_price );
		}

		// Set to backorder mode if enabled (skip for in-stock products)
		if ( ! $is_instock && $sync_backorder_mode ) {
			$product->set_manage_stock( false );
			$product->set_backorders( 'yes' );
		}

		// Set stock status if enabled (skip for in-stock products)
		if ( ! $is_instock && $sync_stock_status ) {
			$product->set_stock_status( 'onbackorder' );
		}

		// Check if product is draft before publishing (skip for in-stock products)
		$was_draft = ( $product->get_status() === 'draft' );
		$should_publish = ! $is_instock && $was_draft && $sync_publish_drafts;

		// Publish if draft and setting enabled (and not in-stock)
		if ( $should_publish ) {
			$product->set_status( 'publish' );
		}

		// Tag with feed source using cached meta and option
		$feed_source = $this->get_cached_meta( $product_id, '_bestoffer_feed_source' );
		if ( empty( $feed_source ) ) {
			$feed_identifier = isset( $cached_options['feed_identifier'] ) ? $cached_options['feed_identifier'] : 'best_offer_xml';
			update_post_meta( $product_id, '_bestoffer_feed_source', $feed_identifier );
		}

		$product->save();

		// Log change
		$price_changed = ( $old_price != $supplier_price );
		if ( $sync_supplier_price && $price_changed ) {
			$logger->log_product_change( $product_id, (string) $product_node->SKU, 'fs_supplier_price', $old_price, $supplier_price );
		}
		if ( $should_publish ) {
			$logger->log_product_change( $product_id, (string) $product_node->SKU, 'post_status', 'draft', 'publish' );
			$stats['published']++;
		}

		// Track updates
		if ( $price_changed || $should_publish ) {
			$stats['updated']++;
		} else {
			$stats['unchanged']++;
		}
	}

	/**
	 * Mark missing products as draft
	 *
	 * @param string $xml_file     XML file path.
	 * @param int    $sync_log_id  Sync log ID.
	 * @return int Number of products drafted
	 */
	private function mark_missing_products_as_draft( $xml_file, $sync_log_id ) {
		// Clear PHP's file stat cache for this file
		clearstatcache( true, $xml_file );

		// Get all SKUs from XML
		$xml_skus = array();
		$reader = new XMLReader();
		$reader->open( $xml_file );

		while ( $reader->read() ) {
			if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'product' ) {
				$product_node = simplexml_load_string( $reader->readOuterXML() );
				if ( $product_node ) {
					$xml_skus[] = (string) $product_node->SKU;
				}
			}
		}
		$reader->close();

		// Find products to draft
		global $wpdb;
		$feed_id = get_option( 'bestoffer_feed_identifier', 'best_offer_xml' );

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
		$logger = new EnviWeb_BestOffer_Logger();

		foreach ( $our_products as $row ) {
			if ( ! in_array( $row->supplier_sku, $xml_skus ) ) {
				// Check locks - only _block_xml_update blocks Best Offer sync
				$block_xml = get_post_meta( $row->ID, '_block_xml_update', true );

				if ( $block_xml ) {
					continue;
				}

				// Check ignore in-stock setting - don't draft in-stock products
				$ignore_instock = (bool) get_option( 'bestoffer_ignore_instock', false );
				if ( $ignore_instock ) {
					$stock_status = get_post_meta( $row->ID, '_stock_status', true );
					if ( $stock_status === 'instock' ) {
						continue; // Don't draft in-stock products
					}
				}

				wp_update_post(
					array(
						'ID'          => $row->ID,
						'post_status' => 'draft',
					)
				);

				$logger->log_product_auto_drafted( $row->ID, $row->supplier_sku, 'Not in current XML feed' );
				$drafted_count++;
			}
		}

		return $drafted_count;
	}

	/**
	 * Count products in XML file
	 *
	 * @param string $xml_file XML file path.
	 * @return int Product count
	 */
	private function count_xml_products( $xml_file ) {
		$count = 0;

		// Clear PHP's file stat cache for this file
		clearstatcache( true, $xml_file );

		$reader = new XMLReader();
		$reader->open( $xml_file );

		while ( $reader->read() ) {
			if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'product' ) {
				$count++;
			}
		}

		$reader->close();
		return $count;
	}

	/**
	 * Build product lookup cache
	 * Loads all supplier_sku => product_id mappings for fast lookups
	 * Uses WordPress object cache for persistence across AJAX requests
	 * Detects and logs duplicate SKUs
	 *
	 * @return int Number of products cached
	 */
	private function build_product_lookup_cache() {
		global $wpdb;

		// Try to get from WordPress object cache first (1-hour expiration)
		// This prevents rebuilding the cache on every AJAX batch request
		$cache_key = 'bestoffer_product_lookup_v1';
		$cached = wp_cache_get( $cache_key, 'bestoffer' );

		if ( $cached !== false && is_array( $cached ) ) {
			$this->product_lookup_cache = $cached;
			return count( $this->product_lookup_cache );
		}

		$this->product_lookup_cache = array();
		$this->duplicate_skus = array(); // Track duplicates
		$page_size = 10000;
		$offset = 0;

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

			if ( empty( $results ) ) {
				break;
			}

			foreach ( $results as $row ) {
				// Check for duplicate SKUs
				if ( isset( $this->product_lookup_cache[ $row->supplier_sku ] ) ) {
					$existing_id = $this->product_lookup_cache[ $row->supplier_sku ];
					if ( ! isset( $this->duplicate_skus[ $row->supplier_sku ] ) ) {
						$this->duplicate_skus[ $row->supplier_sku ] = array( $existing_id );
					}
					$this->duplicate_skus[ $row->supplier_sku ][] = (int) $row->post_id;
				}
				$this->product_lookup_cache[ $row->supplier_sku ] = (int) $row->post_id;
			}

			$offset += $page_size;

			if ( count( $results ) < $page_size ) {
				break;
			}
		}

		// Store in WordPress object cache for 1 hour
		// This speeds up subsequent AJAX batch requests significantly
		wp_cache_set( $cache_key, $this->product_lookup_cache, 'bestoffer', HOUR_IN_SECONDS );

		// Store duplicates in transient for admin notice
		if ( ! empty( $this->duplicate_skus ) ) {
			set_transient( 'bestoffer_duplicate_skus', $this->duplicate_skus, WEEK_IN_SECONDS );
		} else {
			delete_transient( 'bestoffer_duplicate_skus' );
		}

		return count( $this->product_lookup_cache );
	}

	/**
	 * Bulk load product meta for a batch of product IDs
	 *
	 * @param array $product_ids Array of product IDs.
	 */
	private function bulk_load_product_meta( $product_ids ) {
		global $wpdb;

		if ( empty( $product_ids ) ) {
			return;
		}

		// Limit batch size
		if ( count( $product_ids ) > 100 ) {
			$product_ids = array_slice( $product_ids, 0, 100 );
		}

		// Clear existing cache for these products
		foreach ( $product_ids as $id ) {
			unset( $this->product_meta_cache[ $id ] );
		}

		// Meta keys we need - only _block_xml_update for lock check
		$meta_keys = array(
			'fs_supplier_price',
			'_block_xml_update',
			'_stock_status',
			'_bestoffer_feed_source',
		);

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$meta_key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$query = $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value
			FROM {$wpdb->postmeta}
			WHERE post_id IN ($placeholders)
			AND meta_key IN ($meta_key_placeholders)",
			array_merge( $product_ids, $meta_keys )
		);

		$results = $wpdb->get_results( $query, OBJECT );

		// Initialize cache structure
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

		// Populate cache
		if ( $results ) {
			foreach ( $results as $row ) {
				$this->product_meta_cache[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}
		}
	}

	/**
	 * Get cached meta value
	 *
	 * @param int    $product_id Product ID.
	 * @param string $meta_key   Meta key.
	 * @return mixed Meta value or empty string
	 */
	private function get_cached_meta( $product_id, $meta_key ) {
		if ( isset( $this->product_meta_cache[ $product_id ][ $meta_key ] ) ) {
			return $this->product_meta_cache[ $product_id ][ $meta_key ];
		}
		return get_post_meta( $product_id, $meta_key, true );
	}

	/**
	 * Find product by supplier SKU using cache
	 *
	 * @param string $supplier_sku Supplier SKU.
	 * @return int|false Product ID or false
	 */
	private function find_product_by_supplier_sku_cached( $supplier_sku ) {
		if ( isset( $this->product_lookup_cache[ $supplier_sku ] ) ) {
			return $this->product_lookup_cache[ $supplier_sku ];
		}
		return false;
	}
}

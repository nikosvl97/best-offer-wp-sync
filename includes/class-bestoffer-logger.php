<?php
/**
 * Logger Class
 *
 * @package BestOfferSync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Logging functionality for Best Offer Sync
 */
class EnviWeb_BestOffer_Logger {

	/**
	 * Current sync log ID
	 *
	 * @var int
	 */
	private $sync_log_id;

	/**
	 * Start time
	 *
	 * @var float
	 */
	private $start_time;

	/**
	 * Statistics
	 *
	 * @var array
	 */
	private $stats = array();

	/**
	 * Queued product changes for batch insert
	 *
	 * @var array
	 */
	private $queued_logs = array();

	/**
	 * Start a new sync log
	 *
	 * @param string $xml_file XML file path.
	 * @param array  $params Sync parameters.
	 * @return int Sync log ID
	 */
	public function start_sync( $xml_file, $params = array() ) {
		global $wpdb;

		$this->start_time = microtime( true );

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$data = array(
			'sync_date'      => current_time( 'mysql' ),
			'xml_file'       => $xml_file,
			'xml_products'   => isset( $params['xml_products'] ) ? $params['xml_products'] : 0,
			'status'         => 'running',
			'batch_size'     => isset( $params['batch_size'] ) ? $params['batch_size'] : 100,
			'offset_start'   => isset( $params['offset'] ) ? $params['offset'] : 0,
			'created_at'     => current_time( 'mysql' ),
		);

		$wpdb->insert( $table_name, $data );
		$this->sync_log_id = $wpdb->insert_id;

		return $this->sync_log_id;
	}

	/**
	 * End sync log
	 *
	 * @param array  $stats Statistics array.
	 * @param string $status Status (completed, failed, timeout).
	 * @param string $error_message Optional error message.
	 */
	public function end_sync( $stats, $status = 'completed', $error_message = '' ) {
		global $wpdb;

		if ( ! $this->sync_log_id ) {
			return;
		}

		// Flush any queued logs before ending
		$this->flush_queued_logs();

		// Invalidate dashboard caches so fresh data is shown
		delete_transient( 'bestoffer_dashboard_stats' );

		// Also clear the product lookup cache so next sync gets fresh data
		wp_cache_delete( 'bestoffer_product_lookup_v1', 'bestoffer' );

		$execution_time = microtime( true ) - $this->start_time;
		$table_name     = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$data = array(
			'status'                     => $status,
			'products_processed'         => isset( $stats['processed'] ) ? $stats['processed'] : 0,
			'products_updated'           => isset( $stats['updated'] ) ? $stats['updated'] : 0,
			'products_created'           => isset( $stats['created'] ) ? $stats['created'] : 0,
			'products_created_as_draft'  => isset( $stats['created_as_draft'] ) ? $stats['created_as_draft'] : 0,
			'products_claimed'           => isset( $stats['claimed'] ) ? $stats['claimed'] : 0,
			'products_auto_drafted'      => isset( $stats['auto_drafted'] ) ? $stats['auto_drafted'] : 0,
			'products_published'         => isset( $stats['published'] ) ? $stats['published'] : 0,
			'products_unchanged'         => isset( $stats['unchanged'] ) ? $stats['unchanged'] : 0,
			'products_locked'            => isset( $stats['locked'] ) ? $stats['locked'] : 0,
			'products_not_found'         => isset( $stats['not_found'] ) ? $stats['not_found'] : 0,
			'products_skipped'           => isset( $stats['skipped'] ) ? $stats['skipped'] : 0,
			'products_skipped_instock'   => isset( $stats['skipped_instock'] ) ? $stats['skipped_instock'] : 0,
			'products_errors'            => isset( $stats['errors'] ) ? $stats['errors'] : 0,
			'execution_time'             => $execution_time,
			'offset_end'                 => isset( $stats['offset_end'] ) ? $stats['offset_end'] : 0,
			'error_message'              => $error_message,
		);

		$wpdb->update(
			$table_name,
			$data,
			array( 'id' => $this->sync_log_id ),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Log product change
	 * Queues changes for batch insert for better performance
	 *
	 * @param int    $product_id Product ID.
	 * @param string $supplier_sku Supplier SKU.
	 * @param string $field_changed Field name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 */
	public function log_product_change( $product_id, $supplier_sku, $field_changed, $old_value, $new_value ) {
		// Don't log if values are the same
		if ( $old_value === $new_value ) {
			return;
		}

		// Queue for batch insert
		$this->queued_logs[] = array(
			'product_id'    => $product_id,
			'sync_log_id'   => $this->sync_log_id,
			'supplier_sku'  => $supplier_sku,
			'field_changed' => $field_changed,
			'old_value'     => maybe_serialize( $old_value ),
			'new_value'     => maybe_serialize( $new_value ),
			'sync_date'     => current_time( 'mysql' ),
			'created_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Flush queued logs to database
	 * Performs batch insert in chunks to avoid query size limits and locks
	 */
	public function flush_queued_logs() {
		global $wpdb;

		if ( empty( $this->queued_logs ) ) {
			return;
		}

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_PRODUCT_HISTORY );

		// Process in chunks of 200 for better efficiency (increased from 50)
		$chunks = array_chunk( $this->queued_logs, 200 );

		foreach ( $chunks as $chunk ) {
			try {
				// Build batch insert query for this chunk
				$values = array();
				$placeholders = array();

				foreach ( $chunk as $log ) {
					$placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %s)';
					$values[] = $log['product_id'];
					$values[] = $log['sync_log_id'];
					$values[] = $log['supplier_sku'];
					$values[] = $log['field_changed'];
					$values[] = $log['old_value'];
					$values[] = $log['new_value'];
					$values[] = $log['sync_date'];
					$values[] = $log['created_at'];
				}

				$query = "INSERT INTO {$table_name} 
					(product_id, sync_log_id, supplier_sku, field_changed, old_value, new_value, sync_date, created_at) 
					VALUES " . implode( ', ', $placeholders );

				$wpdb->query( $wpdb->prepare( $query, $values ) );

			} catch ( Exception $e ) {
				// Log error but continue - don't fail sync because of logging
				error_log( 'BestOffer Logger Error: ' . $e->getMessage() );
			}
		}

		// Clear the queue
		$this->queued_logs = array();
	}

	/**
	 * Log locked product (product that couldn't be updated due to locks)
	 *
	 * @param int    $product_id Product ID.
	 * @param string $supplier_sku Supplier SKU.
	 * @param string $lock_reason Lock reason.
	 * @param mixed  $attempted_price Price that would have been set.
	 */
	public function log_product_locked( $product_id, $supplier_sku, $lock_reason, $attempted_price ) {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_PRODUCT_HISTORY );

		$data = array(
			'product_id'     => $product_id,
			'sync_log_id'    => $this->sync_log_id,
			'supplier_sku'   => $supplier_sku,
			'field_changed'  => 'product_locked',
			'old_value'      => maybe_serialize( $lock_reason ),
			'new_value'      => maybe_serialize( $attempted_price ),
			'sync_date'      => current_time( 'mysql' ),
			'created_at'     => current_time( 'mysql' ),
		);

		$wpdb->insert( $table_name, $data );
	}

	/**
	 * Get sync log ID
	 *
	 * @return int
	 */
	public function get_sync_log_id() {
		return $this->sync_log_id;
	}

	/**
	 * Set sync log ID (for resuming a sync session)
	 *
	 * @param int $sync_log_id Sync log ID.
	 */
	public function set_sync_log_id( $sync_log_id ) {
		$this->sync_log_id = (int) $sync_log_id;
		$this->start_time  = microtime( true ); // Reset start time for accurate duration
	}

	/**
	 * Get recent sync logs
	 *
	 * @param int $limit Number of logs to retrieve.
	 * @return array
	 */
	public static function get_recent_logs( $limit = 20 ) {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name}
				ORDER BY sync_date DESC
				LIMIT %d",
				$limit
			)
		);

		return $results;
	}

	/**
	 * Get paginated sync logs
	 *
	 * @param int $per_page Number of logs per page.
	 * @param int $page Current page number.
	 * @return array Array with 'items' and 'total' keys
	 */
	public static function get_paginated_logs( $per_page = 20, $page = 1 ) {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		// Get total count
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		// Get paginated results
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name}
				ORDER BY sync_date DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return array(
			'items' => $items,
			'total' => intval( $total ),
		);
	}

	/**
	 * Get product sync history
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit Number of records.
	 * @return array
	 */
	public static function get_product_history( $product_id, $limit = 50 ) {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_PRODUCT_HISTORY );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} 
				WHERE product_id = %d 
				ORDER BY sync_date DESC 
				LIMIT %d",
				$product_id,
				$limit
			)
		);

		return $results;
	}

	/**
	 * Get last sync info
	 *
	 * @return object|null
	 */
	public static function get_last_sync() {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$result = $wpdb->get_row(
			"SELECT * FROM {$table_name} 
			ORDER BY sync_date DESC 
			LIMIT 1"
		);

		return $result;
	}

	/**
	 * Get sync statistics summary
	 *
	 * @param int $days Number of days to include.
	 * @return object
	 */
	public static function get_sync_stats( $days = 30 ) {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_syncs,
					SUM(products_updated) as total_updated,
					SUM(products_errors) as total_errors,
					AVG(execution_time) as avg_execution_time,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_syncs
				FROM {$table_name}
				WHERE sync_date >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		return $result;
	}

	/**
	 * Log product creation
	 *
	 * @param int    $product_id      Product ID.
	 * @param string $supplier_sku    Supplier SKU.
	 * @param string $status          Product status (publish or draft).
	 * @param int    $images_attached Number of images attached.
	 */
	public function log_product_created( $product_id, $supplier_sku, $status, $images_attached = 0 ) {
		$this->queued_logs[] = array(
			'product_id'    => $product_id,
			'sync_log_id'   => $this->sync_log_id,
			'supplier_sku'  => $supplier_sku,
			'field_changed' => 'product_created',
			'old_value'     => maybe_serialize( array( 'status' => $status ) ),
			'new_value'     => maybe_serialize( array( 'images' => $images_attached ) ),
			'sync_date'     => current_time( 'mysql' ),
			'created_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Log product auto-drafted (missing from XML feed)
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $supplier_sku Supplier SKU.
	 * @param string $reason       Reason for auto-drafting.
	 */
	public function log_product_auto_drafted( $product_id, $supplier_sku, $reason ) {
		$this->queued_logs[] = array(
			'product_id'    => $product_id,
			'sync_log_id'   => $this->sync_log_id,
			'supplier_sku'  => $supplier_sku,
			'field_changed' => 'auto_drafted',
			'old_value'     => 'publish',
			'new_value'     => maybe_serialize( array( 'reason' => $reason ) ),
			'sync_date'     => current_time( 'mysql' ),
			'created_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Log image download failure
	 *
	 * @param int    $product_id   Product ID (may be 0 for creation failures).
	 * @param string $supplier_sku Supplier SKU.
	 * @param string $image_url    Image URL that failed.
	 * @param string $error        Error message.
	 */
	public function log_image_failure( $product_id, $supplier_sku, $image_url, $error ) {
		$this->queued_logs[] = array(
			'product_id'    => $product_id,
			'sync_log_id'   => $this->sync_log_id,
			'supplier_sku'  => $supplier_sku,
			'field_changed' => 'image_download_failed',
			'old_value'     => maybe_serialize( $image_url ),
			'new_value'     => maybe_serialize( $error ),
			'sync_date'     => current_time( 'mysql' ),
			'created_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Log general error
	 *
	 * @param string $supplier_sku Supplier SKU.
	 * @param string $error_type   Error type identifier.
	 * @param string $error        Error message.
	 */
	public function log_error( $supplier_sku, $error_type, $error ) {
		$this->queued_logs[] = array(
			'product_id'    => 0,
			'sync_log_id'   => $this->sync_log_id,
			'supplier_sku'  => $supplier_sku,
			'field_changed' => $error_type,
			'old_value'     => '',
			'new_value'     => maybe_serialize( $error ),
			'sync_date'     => current_time( 'mysql' ),
			'created_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Get product update history with filtering and pagination
	 *
	 * @param array $args Query arguments.
	 * @return array Array with 'items' and 'total' keys
	 */
	public static function get_update_history( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'    => 50,
			'page'        => 1,
			'days'        => 7,
			'change_type' => '',
			'search'      => '',
			'orderby'     => 'sync_date',
			'order'       => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );
		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_PRODUCT_HISTORY );

		// Build WHERE clause
		$where_clauses = array();
		$where_values = array();

		// Date filter
		if ( $args['days'] > 0 ) {
			$where_clauses[] = 'ph.sync_date >= DATE_SUB(NOW(), INTERVAL %d DAY)';
			$where_values[] = intval( $args['days'] );
		}

		// Change type filter
		if ( ! empty( $args['change_type'] ) ) {
			$where_clauses[] = 'ph.field_changed = %s';
			$where_values[] = sanitize_text_field( $args['change_type'] );
		}

		// Search filter (SKU or product title)
		if ( ! empty( $args['search'] ) ) {
			$search_term = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where_clauses[] = '(ph.supplier_sku LIKE %s OR p.post_title LIKE %s)';
			$where_values[] = $search_term;
			$where_values[] = $search_term;
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		// Validate orderby and order
		$allowed_orderby = array( 'sync_date', 'product_id', 'supplier_sku', 'field_changed' );
		$orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'sync_date';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Calculate offset
		$offset = ( max( 1, intval( $args['page'] ) ) - 1 ) * intval( $args['per_page'] );

		// Count total records
		$count_query = "SELECT COUNT(*) FROM {$table_name} ph
			LEFT JOIN {$wpdb->posts} p ON ph.product_id = p.ID
			{$where_sql}";

		if ( ! empty( $where_values ) ) {
			$count_query = $wpdb->prepare( $count_query, $where_values );
		}

		$total = $wpdb->get_var( $count_query );

		// Get records with product info
		$query = "SELECT
				ph.*,
				p.post_title as product_title,
				p.post_status as product_status
			FROM {$table_name} ph
			LEFT JOIN {$wpdb->posts} p ON ph.product_id = p.ID
			{$where_sql}
			ORDER BY ph.{$orderby} {$order}
			LIMIT %d OFFSET %d";

		$query_values = array_merge( $where_values, array( intval( $args['per_page'] ), $offset ) );
		$items = $wpdb->get_results( $wpdb->prepare( $query, $query_values ) );

		return array(
			'items' => $items,
			'total' => intval( $total ),
		);
	}

	/**
	 * Get history statistics for dashboard
	 *
	 * @param int $days Number of days to include (0 = all time).
	 * @return object Statistics object
	 */
	public static function get_history_stats( $days = 7 ) {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_PRODUCT_HISTORY );

		$query = "SELECT
				COUNT(*) as total_changes,
				COUNT(DISTINCT product_id) as products_affected,
				SUM(CASE WHEN field_changed = 'fs_supplier_price' THEN 1 ELSE 0 END) as price_changes,
				SUM(CASE WHEN field_changed = 'post_status' THEN 1 ELSE 0 END) as status_changes,
				SUM(CASE WHEN field_changed = 'product_created' THEN 1 ELSE 0 END) as products_created,
				SUM(CASE WHEN field_changed = 'auto_drafted' THEN 1 ELSE 0 END) as auto_drafted,
				SUM(CASE WHEN field_changed = 'backorders' THEN 1 ELSE 0 END) as backorder_changes,
				SUM(CASE WHEN field_changed = 'stock_status' THEN 1 ELSE 0 END) as stock_changes
			FROM {$table_name}";

		// Add date filter only if days > 0 (0 = all time)
		if ( $days > 0 ) {
			$query .= $wpdb->prepare( " WHERE sync_date >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days );
		}

		$result = $wpdb->get_row( $query );

		return $result;
	}

	/**
	 * Get distinct change types for filter dropdown
	 *
	 * @param int $days Number of days to include (0 = all time).
	 * @return array Array of change types
	 */
	public static function get_change_types( $days = 7 ) {
		global $wpdb;

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_PRODUCT_HISTORY );

		$query = "SELECT DISTINCT field_changed FROM {$table_name}";

		// Add date filter only if days > 0 (0 = all time)
		if ( $days > 0 ) {
			$query .= $wpdb->prepare( " WHERE sync_date >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days );
		}

		$query .= " ORDER BY field_changed ASC";

		$results = $wpdb->get_col( $query );

		return $results;
	}

	/**
	 * Get human-readable label for change type
	 *
	 * @param string $change_type Change type key.
	 * @return string Human-readable label
	 */
	public static function get_change_type_label( $change_type ) {
		$labels = array(
			'fs_supplier_price'       => __( 'Supplier Price', 'best-offer-sync' ),
			'post_status'             => __( 'Status Change', 'best-offer-sync' ),
			'product_created'         => __( 'Product Created', 'best-offer-sync' ),
			'auto_drafted'            => __( 'Auto-Drafted', 'best-offer-sync' ),
			'product_claimed'         => __( 'Product Claimed', 'best-offer-sync' ),
			'backorders'              => __( 'Backorders', 'best-offer-sync' ),
			'stock_status'            => __( 'Stock Status', 'best-offer-sync' ),
			'regular_price'           => __( 'Regular Price', 'best-offer-sync' ),
			'product_locked'          => __( 'Update Blocked', 'best-offer-sync' ),
			'image_download_failed'   => __( 'Image Failed', 'best-offer-sync' ),
			'product_creation_failed' => __( 'Creation Failed', 'best-offer-sync' ),
		);

		return isset( $labels[ $change_type ] ) ? $labels[ $change_type ] : ucwords( str_replace( '_', ' ', $change_type ) );
	}
}


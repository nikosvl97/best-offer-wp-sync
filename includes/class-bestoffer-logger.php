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

		$execution_time = microtime( true ) - $this->start_time;
		$table_name     = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_SYNC_LOGS );

		$data = array(
			'status'                     => $status,
			'products_processed'         => isset( $stats['processed'] ) ? $stats['processed'] : 0,
			'products_updated'           => isset( $stats['updated'] ) ? $stats['updated'] : 0,
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
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Log product change
	 *
	 * @param int    $product_id Product ID.
	 * @param string $supplier_sku Supplier SKU.
	 * @param string $field_changed Field name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $new_value New value.
	 */
	public function log_product_change( $product_id, $supplier_sku, $field_changed, $old_value, $new_value ) {
		global $wpdb;

		// Don't log if values are the same
		if ( $old_value === $new_value ) {
			return;
		}

		$table_name = EnviWeb_BestOffer_Database::get_table_name( EnviWeb_BestOffer_Database::TABLE_PRODUCT_HISTORY );

		$data = array(
			'product_id'     => $product_id,
			'sync_log_id'    => $this->sync_log_id,
			'supplier_sku'   => $supplier_sku,
			'field_changed'  => $field_changed,
			'old_value'      => maybe_serialize( $old_value ),
			'new_value'      => maybe_serialize( $new_value ),
			'sync_date'      => current_time( 'mysql' ),
			'created_at'     => current_time( 'mysql' ),
		);

		$wpdb->insert( $table_name, $data );
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
}


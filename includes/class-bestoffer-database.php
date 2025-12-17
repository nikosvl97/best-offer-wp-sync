<?php
/**
 * Database Management Class
 *
 * @package BestOfferSync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Database operations for Best Offer Sync
 */
class EnviWeb_BestOffer_Database {

	/**
	 * Sync logs table name
	 *
	 * @var string
	 */
	const TABLE_SYNC_LOGS = 'enviweb_bestoffer_sync_logs';

	/**
	 * Product sync history table name
	 *
	 * @var string
	 */
	const TABLE_PRODUCT_HISTORY = 'enviweb_bestoffer_product_history';

	/**
	 * Create database tables
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Sync logs table
		$table_sync_logs = $wpdb->prefix . self::TABLE_SYNC_LOGS;
		$sql_sync_logs   = "CREATE TABLE IF NOT EXISTS {$table_sync_logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sync_date datetime NOT NULL,
			xml_file varchar(500) NOT NULL,
			xml_products int(11) DEFAULT 0,
			status varchar(20) NOT NULL,
			products_processed int(11) DEFAULT 0,
			products_updated int(11) DEFAULT 0,
			products_unchanged int(11) DEFAULT 0,
			products_locked int(11) DEFAULT 0,
			products_not_found int(11) DEFAULT 0,
			products_skipped int(11) DEFAULT 0,
			products_skipped_instock int(11) DEFAULT 0,
			products_errors int(11) DEFAULT 0,
			execution_time float DEFAULT 0,
			error_message text,
			batch_size int(11) DEFAULT 100,
			offset_start int(11) DEFAULT 0,
			offset_end int(11) DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY sync_date (sync_date),
			KEY status (status)
		) $charset_collate;";

		// Product sync history table
		$table_product_history = $wpdb->prefix . self::TABLE_PRODUCT_HISTORY;
		$sql_product_history   = "CREATE TABLE IF NOT EXISTS {$table_product_history} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			sync_log_id bigint(20) unsigned,
			supplier_sku varchar(100) NOT NULL,
			field_changed varchar(50) NOT NULL,
			old_value text,
			new_value text,
			sync_date datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY sync_log_id (sync_log_id),
			KEY supplier_sku (supplier_sku),
			KEY sync_date (sync_date)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_sync_logs );
		dbDelta( $sql_product_history );
	}

	/**
	 * Upgrade database tables to add new columns
	 */
	public static function upgrade_tables() {
		global $wpdb;

		$table_sync_logs = $wpdb->prefix . self::TABLE_SYNC_LOGS;

		// Check if xml_products column exists
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'xml_products'" );
		
		if ( empty( $column_check ) ) {
			// Add xml_products column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN xml_products int(11) DEFAULT 0 AFTER xml_file" );
		}

		// Check if products_unchanged column exists
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'products_unchanged'" );
		
		if ( empty( $column_check ) ) {
			// Add products_unchanged column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN products_unchanged int(11) DEFAULT 0 AFTER products_updated" );
		}

		// Check if products_skipped_instock column exists
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'products_skipped_instock'" );
		
		if ( empty( $column_check ) ) {
			// Add products_skipped_instock column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN products_skipped_instock int(11) DEFAULT 0 AFTER products_skipped" );
		}
	}

	/**
	 * Drop database tables (for uninstall)
	 */
	public static function drop_tables() {
		global $wpdb;

		$table_sync_logs       = $wpdb->prefix . self::TABLE_SYNC_LOGS;
		$table_product_history = $wpdb->prefix . self::TABLE_PRODUCT_HISTORY;

		$wpdb->query( "DROP TABLE IF EXISTS {$table_product_history}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$table_sync_logs}" );
	}

	/**
	 * Get table name with prefix
	 *
	 * @param string $table Table constant name.
	 * @return string
	 */
	public static function get_table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}
}


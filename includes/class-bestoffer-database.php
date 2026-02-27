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
			KEY status (status),
			KEY status_date (status, sync_date)
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
			KEY sync_date (sync_date),
			KEY product_date (product_id, sync_date)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_sync_logs );
		dbDelta( $sql_product_history );
	}

	/**
	 * Upgrade database tables to add new columns and indexes
	 */
	public static function upgrade_tables() {
		global $wpdb;

		$table_sync_logs = $wpdb->prefix . self::TABLE_SYNC_LOGS;
		$table_product_history = $wpdb->prefix . self::TABLE_PRODUCT_HISTORY;

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

		// Check if products_created column exists (v1.2.0+)
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'products_created'" );

		if ( empty( $column_check ) ) {
			// Add products_created column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN products_created int(11) DEFAULT 0 AFTER products_updated" );
		}

		// Check if products_created_as_draft column exists (v1.2.0+)
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'products_created_as_draft'" );

		if ( empty( $column_check ) ) {
			// Add products_created_as_draft column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN products_created_as_draft int(11) DEFAULT 0 AFTER products_created" );
		}

		// Check if products_auto_drafted column exists (v1.2.0+)
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'products_auto_drafted'" );

		if ( empty( $column_check ) ) {
			// Add products_auto_drafted column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN products_auto_drafted int(11) DEFAULT 0 AFTER products_created_as_draft" );
		}

		// Check if products_claimed column exists (v1.2.1+)
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'products_claimed'" );

		if ( empty( $column_check ) ) {
			// Add products_claimed column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN products_claimed int(11) DEFAULT 0 AFTER products_created_as_draft" );
		}

		// Check if products_published column exists (v1.2.2+) - tracks draft products published during sync
		$column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sync_logs} LIKE 'products_published'" );

		if ( empty( $column_check ) ) {
			// Add products_published column
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD COLUMN products_published int(11) DEFAULT 0 AFTER products_auto_drafted" );
		}

		// Add composite indexes for performance (if not exists)
		self::add_indexes_if_not_exist();
	}

	/**
	 * Add composite indexes if they don't exist
	 * These indexes dramatically improve query performance
	 */
	public static function add_indexes_if_not_exist() {
		global $wpdb;

		$table_sync_logs = $wpdb->prefix . self::TABLE_SYNC_LOGS;
		$table_product_history = $wpdb->prefix . self::TABLE_PRODUCT_HISTORY;

		// Check and add status_date index on sync_logs
		$index_check = $wpdb->get_results( "SHOW INDEX FROM {$table_sync_logs} WHERE Key_name = 'status_date'" );
		if ( empty( $index_check ) ) {
			$wpdb->query( "ALTER TABLE {$table_sync_logs} ADD INDEX status_date (status, sync_date)" );
		}

		// Check and add product_date index on product_history
		$index_check = $wpdb->get_results( "SHOW INDEX FROM {$table_product_history} WHERE Key_name = 'product_date'" );
		if ( empty( $index_check ) ) {
			$wpdb->query( "ALTER TABLE {$table_product_history} ADD INDEX product_date (product_id, sync_date)" );
		}
	}

	/**
	 * Clean up old history records to prevent table bloat
	 *
	 * @param int $days Number of days to keep (default 90)
	 * @return int Number of rows deleted
	 */
	public static function cleanup_old_history( $days = 90 ) {
		global $wpdb;

		$table_product_history = $wpdb->prefix . self::TABLE_PRODUCT_HISTORY;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_product_history}
				WHERE sync_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		return $deleted !== false ? $deleted : 0;
	}

	/**
	 * Clean up old sync logs
	 *
	 * @param int $days Number of days to keep (default 90)
	 * @return int Number of rows deleted
	 */
	public static function cleanup_old_logs( $days = 90 ) {
		global $wpdb;

		$table_sync_logs = $wpdb->prefix . self::TABLE_SYNC_LOGS;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_sync_logs}
				WHERE sync_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		return $deleted !== false ? $deleted : 0;
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


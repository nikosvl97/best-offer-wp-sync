<?php
/**
 * Settings Manager for Best Offer WP Sync
 *
 * Centralized settings management for product creation and sync configuration.
 *
 * @package Best_Offer_Sync
 * @since 1.2.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class EnviWeb_BestOffer_Settings
 *
 * Manages plugin settings with default values and validation.
 */
class EnviWeb_BestOffer_Settings {
	/**
	 * Default title format template
	 */
	const DEFAULT_TITLE_FORMAT = '{title} <span style="color: white; visibility:visible;">{ean}</span>';

	/**
	 * Default price markup multiplier (140%)
	 */
	const DEFAULT_PRICE_MARKUP = 1.40;

	/**
	 * Default SKU prefix mapping
	 */
	const DEFAULT_SKU_PREFIX_MAP = array(
		'PX' => 'BO',
		'FS' => 'BO',
	);

	/**
	 * Default feed identifier for ownership tracking
	 */
	const DEFAULT_FEED_ID = 'best_offer_xml';

	/**
	 * Default user ID for sync operations
	 */
	const DEFAULT_USER_ID = 1;

	/**
	 * Get title format template
	 *
	 * @return string Title format with placeholders: {title}, {ean}, {mpn}, {brandname}
	 */
	public function get_title_format() {
		return get_option( 'bestoffer_title_format', self::DEFAULT_TITLE_FORMAT );
	}

	/**
	 * Get price markup multiplier
	 *
	 * @return float Multiplier (e.g., 1.40 = 140%)
	 */
	public function get_price_markup() {
		return (float) get_option( 'bestoffer_price_markup', self::DEFAULT_PRICE_MARKUP );
	}

	/**
	 * Get SKU prefix mapping for transformation
	 *
	 * @return array Associative array of old_prefix => new_prefix
	 */
	public function get_sku_prefix_mapping() {
		$map = get_option( 'bestoffer_sku_prefix_map', self::DEFAULT_SKU_PREFIX_MAP );
		return is_array( $map ) ? $map : self::DEFAULT_SKU_PREFIX_MAP;
	}

	/**
	 * Get feed identifier for ownership tracking
	 *
	 * @return string Feed identifier stored in _bestoffer_feed_source meta
	 */
	public function get_feed_identifier() {
		return get_option( 'bestoffer_feed_identifier', self::DEFAULT_FEED_ID );
	}

	/**
	 * Check if product creation is enabled
	 *
	 * @return bool True if product creation is enabled
	 */
	public function is_product_creation_enabled() {
		return (bool) get_option( 'bestoffer_enable_product_creation', false );
	}

	/**
	 * Check if auto-draft missing products is enabled
	 *
	 * @return bool True if missing products should be auto-drafted (enabled by default)
	 */
	public function is_auto_draft_missing_enabled() {
		// Enabled by default as per requirements
		return (bool) get_option( 'bestoffer_auto_draft_missing', true );
	}

	/**
	 * Check if in-stock products should be ignored
	 *
	 * @return bool True if in-stock products should be skipped
	 */
	public function is_ignore_instock_enabled() {
		return (bool) get_option( 'bestoffer_ignore_instock', false );
	}

	/**
	 * Get default user ID for sync operations
	 *
	 * @return int User ID to run sync operations as
	 */
	public function get_default_user_id() {
		return (int) get_option( 'bestoffer_default_user_id', self::DEFAULT_USER_ID );
	}

	/**
	 * Check if auto-claim existing products is enabled
	 *
	 * @return bool True if should claim existing matching products (enabled by default)
	 */
	public function is_auto_claim_products_enabled() {
		return (bool) get_option( 'bestoffer_auto_claim_products', true );
	}
}

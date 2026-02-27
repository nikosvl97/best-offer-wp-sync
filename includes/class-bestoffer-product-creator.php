<?php
/**
 * Product Creator for Best Offer WP Sync
 *
 * Handles automatic creation of WooCommerce products from XML feed data.
 * Includes image downloading, category creation, and field transformations.
 *
 * @package Best_Offer_Sync
 * @since 1.2.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class EnviWeb_BestOffer_Product_Creator
 *
 * Creates new WooCommerce products from XML feed with proper field mappings,
 * image downloads, hierarchical categories, and attributes.
 */
class EnviWeb_BestOffer_Product_Creator {
	/**
	 * Settings manager instance
	 *
	 * @var EnviWeb_BestOffer_Settings
	 */
	private $settings;

	/**
	 * Logger instance
	 *
	 * @var EnviWeb_BestOffer_Logger
	 */
	private $logger;

	/**
	 * Maximum number of images to download per product
	 */
	const MAX_IMAGES = 10;

	/**
	 * Image download timeout in seconds
	 */
	const IMAGE_TIMEOUT = 10;

	/**
	 * Constructor
	 *
	 * @param EnviWeb_BestOffer_Logger $logger Logger instance for tracking operations.
	 */
	public function __construct( $logger = null ) {
		$this->logger   = $logger;
		$this->settings = new EnviWeb_BestOffer_Settings();
	}

	/**
	 * Create new WooCommerce product from XML node
	 *
	 * @param SimpleXMLElement $product_node XML node containing product data.
	 * @param bool             $hpos_enabled Whether HPOS is enabled.
	 *
	 * @return int Product ID
	 * @throws Exception On failure.
	 */
	public function create_product_from_xml( $product_node, $hpos_enabled = false ) {
		// Extract data from XML
		$data = $this->extract_product_data( $product_node );

		// Transform fields
		$title       = $this->transform_title( $data['title'], $data['ean'] );
		$description = $this->transform_description( $data['description'] );
		$sku         = $this->transform_sku( $data['supplier_sku'] );
		$price       = $this->calculate_price( $data['supplier_price'] );

		// Check for SKU conflict
		if ( $this->check_sku_conflict( $sku ) ) {
			throw new Exception( "SKU conflict: $sku already exists" );
		}

		// Create WooCommerce product
		$product = new WC_Product_Simple();
		$product->set_name( $title );
		$product->set_description( $description );
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->set_tax_status( 'taxable' );
		$product->set_manage_stock( false );
		$product->set_backorders( 'yes' );
		$product->set_stock_status( 'onbackorder' );

		// Set weight if available
		if ( ! empty( $data['weight'] ) && $data['weight'] > 0 ) {
			$product->set_weight( $data['weight'] );
		}

		// Set status to publish initially (may change if images fail)
		$product->set_status( 'publish' );

		// Save to get product ID
		$product_id = $product->save();

		// Create categories
		$category_ids = $this->create_category_hierarchy( $data['categorypath'] );
		if ( ! empty( $category_ids ) ) {
			$product->set_category_ids( $category_ids );
		}

		// Download and attach images
		$image_ids = $this->download_and_attach_images(
			$product_id,
			$data['main_image'],
			$data['gallery_images']
		);

		// If ALL images failed, set to draft
		if ( empty( $image_ids ) ) {
			$product->set_status( 'draft' );
			if ( $this->logger ) {
				$this->logger->log_image_failure(
					$product_id,
					$data['supplier_sku'],
					'all',
					'All images failed to download'
				);
			}
		} else {
			// Set featured image
			$product->set_image_id( $image_ids[0] );
			// Set gallery images
			if ( count( $image_ids ) > 1 ) {
				$product->set_gallery_image_ids( array_slice( $image_ids, 1 ) );
			}
		}

		// Create product attributes (WooCommerce visible)
		$this->create_product_attributes( $product, $data );

		// Save again with images and attributes
		$product->save();

		// Set custom meta fields
		$this->set_custom_meta_fields( $product_id, $data, $hpos_enabled );

		// Set feed source for ownership tracking
		update_post_meta( $product_id, '_bestoffer_feed_source', $this->settings->get_feed_identifier() );

		return $product_id;
	}

	/**
	 * Download and attach images to product
	 *
	 * @param int   $product_id      Product ID to attach images to.
	 * @param string $main_image     Main image URL.
	 * @param array  $gallery_images Array of gallery image URLs.
	 *
	 * @return array Array of attachment IDs (empty if all failed)
	 */
	public function download_and_attach_images( $product_id, $main_image, $gallery_images = array() ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = array();
		$all_images     = array_merge( array( $main_image ), $gallery_images );

		// Limit total images
		$all_images = array_slice( $all_images, 0, self::MAX_IMAGES );

		foreach ( $all_images as $image_url ) {
			if ( empty( $image_url ) ) {
				continue;
			}

			try {
				$temp_file = download_url( $image_url, self::IMAGE_TIMEOUT );

				if ( is_wp_error( $temp_file ) ) {
					throw new Exception( $temp_file->get_error_message() );
				}

				// Prepare file array
				$file_array = array(
					'name'     => basename( $image_url ),
					'tmp_name' => $temp_file,
				);

				// Upload to media library
				$attachment_id = media_handle_sideload( $file_array, $product_id );

				if ( is_wp_error( $attachment_id ) ) {
					@unlink( $temp_file );
					throw new Exception( $attachment_id->get_error_message() );
				}

				$attachment_ids[] = $attachment_id;

			} catch ( Exception $e ) {
				// Log individual image failure but continue
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					WP_CLI::warning( "Image download failed: $image_url - " . $e->getMessage() );
				}

				if ( $this->logger ) {
					$this->logger->log_image_failure(
						$product_id,
						'',
						$image_url,
						$e->getMessage()
					);
				}
				continue;
			}
		}

		return $attachment_ids;
	}

	/**
	 * Create hierarchical category structure from path
	 *
	 * @param string $categorypath Category path (e.g., "Category1->Category2->Category3").
	 *
	 * @return array Array of all category IDs in the hierarchy path
	 */
	public function create_category_hierarchy( $categorypath ) {
		// Apply checkemptychar function
		$categorypath = $this->checkemptychar( $categorypath );

		// Split path
		$categories = array_map( 'trim', explode( '->', $categorypath ) );

		if ( empty( $categories ) ) {
			return array();
		}

		$parent_id    = 0;
		$category_ids = array(); // Collect ALL category IDs in the path

		foreach ( $categories as $cat_name ) {
			if ( empty( $cat_name ) ) {
				continue;
			}

			// Check if category exists under this parent
			$term = term_exists( $cat_name, 'product_cat', $parent_id );

			if ( is_array( $term ) ) {
				$term_id = $term['term_id'];
			} elseif ( $term === 0 || $term === null ) {
				// Create new category
				$new_term = wp_insert_term(
					$cat_name,
					'product_cat',
					array( 'parent' => $parent_id )
				);

				if ( is_wp_error( $new_term ) ) {
					if ( defined( 'WP_CLI' ) && WP_CLI ) {
						WP_CLI::warning( "Failed to create category: $cat_name" );
					}
					continue;
				}

				$term_id = $new_term['term_id'];
			} else {
				$term_id = $term;
			}

			if ( $term_id ) {
				$parent_id      = $term_id;
				$category_ids[] = $term_id; // Add each category to the array
			}
		}

		return $category_ids; // Return all category IDs in the hierarchy
	}

	/**
	 * Create WooCommerce product attributes (visible on frontend)
	 *
	 * @param WC_Product $product Product instance.
	 * @param array      $data    Extracted product data.
	 */
	private function create_product_attributes( $product, $data ) {
		$attributes = array();

		// ΚΑΤΑΣΚΕΥΑΣΤΗΣ attribute (Manufacturer)
		if ( ! empty( $data['brandname'] ) ) {
			$attr = new WC_Product_Attribute();
			$attr->set_name( 'ΚΑΤΑΣΚΕΥΑΣΤΗΣ' );
			$attr->set_options( array( $data['brandname'] ) );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$attributes[] = $attr;
		}

		// EAN attribute
		if ( ! empty( $data['ean'] ) ) {
			$attr = new WC_Product_Attribute();
			$attr->set_name( 'EAN' );
			$attr->set_options( array( $data['ean'] ) );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$attributes[] = $attr;
		}

		$product->set_attributes( $attributes );
	}

	/**
	 * Set custom meta fields
	 *
	 * @param int   $product_id   Product ID.
	 * @param array $data         Extracted product data.
	 * @param bool  $hpos_enabled Whether HPOS is enabled.
	 */
	private function set_custom_meta_fields( $product_id, $data, $hpos_enabled ) {
		$meta_fields = array(
			'supplier_sku'     => $data['supplier_sku'],
			'fs_supplier_price' => $data['supplier_price'],
			'EAN'              => $data['ean'],
			'MPN'              => $data['mpn'],
			'BRAND'            => $data['brandname'],
			'TITLOS'           => $data['title'],
			'_titlos'          => $data['title'],
		);

		foreach ( $meta_fields as $key => $value ) {
			if ( ! empty( $value ) ) {
				update_post_meta( $product_id, $key, sanitize_text_field( $value ) );
			}
		}
	}

	/**
	 * Extract product data from XML node
	 *
	 * @param SimpleXMLElement $product_node XML node containing product data.
	 *
	 * @return array Associative array of product data
	 */
	private function extract_product_data( $product_node ) {
		$data = array(
			'supplier_sku'   => (string) $product_node->SKU,
			'ean'            => (string) $product_node->ean,
			'mpn'            => (string) $product_node->mpn,
			'brandname'      => (string) $product_node->brandname,
			'supplier_price' => (float) $product_node->supplier_price,
			'title'          => (string) $product_node->title,
			'description'    => (string) $product_node->description,
			'main_image'     => (string) $product_node->main_image,
			'weight'         => (float) $product_node->weight,
			'categorypath'   => (string) $product_node->categories->categorypath,
			'gallery_images' => array(),
		);

		// Extract gallery images if present
		if ( isset( $product_node->images ) && isset( $product_node->images->image ) ) {
			foreach ( $product_node->images->image as $image ) {
				$data['gallery_images'][] = (string) $image;
			}
		}

		return $data;
	}

	/**
	 * Transform title using template format
	 *
	 * @param string $title Original title.
	 * @param string $ean   EAN code.
	 *
	 * @return string Transformed title
	 */
	public function transform_title( $title, $ean ) {
		$format = $this->settings->get_title_format();
		$output = str_replace( '{title}', $title, $format );
		$output = str_replace( '{ean}', $ean, $output );
		return $output;
	}

	/**
	 * Transform description by removing discount blocks
	 *
	 * @param string $description Original description.
	 *
	 * @return string Cleaned description
	 */
	public function transform_description( $description ) {
		return $this->removeDiscountBlock( $description );
	}

	/**
	 * Transform SKU using prefix mapping
	 *
	 * @param string $supplier_sku Original supplier SKU.
	 *
	 * @return string Transformed SKU
	 */
	public function transform_sku( $supplier_sku ) {
		return $this->replacePrefix( $supplier_sku );
	}

	/**
	 * Calculate regular price from supplier price
	 *
	 * @param float $supplier_price Supplier price.
	 *
	 * @return float Calculated regular price
	 */
	public function calculate_price( $supplier_price ) {
		$markup = $this->settings->get_price_markup();
		return round( $supplier_price * $markup, 2 );
	}

	/**
	 * Remove discount block from description
	 *
	 * Removes red text discount messages from product descriptions.
	 *
	 * @param string $description Original description.
	 *
	 * @return string Cleaned description
	 */
	private function removeDiscountBlock( $description ) {
		return preg_replace(
			'/<span[^>]*style="[^"]*color:\s*#FF0000[^"]*"[^>]*>.*?<\/span><br\s*\/?><br\s*\/?>/is',
			'',
			$description
		);
	}

	/**
	 * Replace SKU prefix
	 *
	 * Transforms PX or FS prefixes to BO1 (e.g., PX050006 -> BO1050006).
	 *
	 * @param string $input      Original SKU.
	 * @param string $new_prefix New prefix to use (default: 'BO').
	 *
	 * @return string Transformed SKU
	 */
	private function replacePrefix( $input, $new_prefix = 'BO' ) {
		$prefixes = array( 'PX', 'FS' );
		foreach ( $prefixes as $old_prefix ) {
			if ( strpos( $input, $old_prefix ) === 0 ) {
				return $new_prefix . '1' . substr( $input, strlen( $old_prefix ) );
			}
		}
		return $input;
	}

	/**
	 * Check if category path is empty and return default
	 *
	 * @param string $char Category path.
	 *
	 * @return string Category path or default "Χωρίς κατηγορία"
	 */
	private function checkemptychar( $char ) {
		if ( empty( $char ) ) {
			return 'Χωρίς κατηγορία';
		}
		return $char;
	}

	/**
	 * Check if SKU already exists in database
	 *
	 * @param string $sku SKU to check.
	 *
	 * @return bool True if SKU exists
	 */
	private function check_sku_conflict( $sku ) {
		global $wpdb;

		// Check in wp_posts meta (WooCommerce SKU)
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
				$sku
			)
		);

		return $exists !== null;
	}

	/**
	 * Find existing product that matches the XML data
	 *
	 * Searches for products with same SKU, EAN, or similar title
	 * Returns product ID if a strong match is found
	 *
	 * @param array $data Product data extracted from XML.
	 *
	 * @return int|false Product ID if match found, false otherwise
	 */
	public function find_matching_product( $data ) {
		global $wpdb;

		// Transform SKU using same logic as creation
		$transformed_sku = $this->transform_sku( $data['supplier_sku'] );

		// 1. Check by transformed SKU (strongest match)
		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_sku' AND meta_value = %s
				LIMIT 1",
				$transformed_sku
			)
		);

		if ( $product_id ) {
			// Verify it's not already claimed by our feed
			$existing_supplier_sku = get_post_meta( $product_id, 'supplier_sku', true );
			if ( empty( $existing_supplier_sku ) ) {
				return (int) $product_id;
			}
		}

		// 2. Check by EAN (if provided)
		if ( ! empty( $data['ean'] ) ) {
			$product_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					WHERE meta_key = 'EAN' AND meta_value = %s
					LIMIT 1",
					$data['ean']
				)
			);

			if ( $product_id ) {
				$existing_supplier_sku = get_post_meta( $product_id, 'supplier_sku', true );
				if ( empty( $existing_supplier_sku ) ) {
					// Additional verification: check if SKU is similar
					$existing_sku = get_post_meta( $product_id, '_sku', true );
					if ( $this->skus_are_similar( $existing_sku, $transformed_sku ) ) {
						return (int) $product_id;
					}
				}
			}
		}

		// 3. Check by title similarity (most lenient)
		$title = sanitize_text_field( $data['title'] );
		if ( ! empty( $title ) ) {
			// Search for products with very similar titles
			$similar_products = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts}
					WHERE post_type = 'product'
					AND post_status != 'trash'
					AND post_title LIKE %s
					LIMIT 5",
					'%' . $wpdb->esc_like( $title ) . '%'
				)
			);

			foreach ( $similar_products as $product ) {
				$existing_supplier_sku = get_post_meta( $product->ID, 'supplier_sku', true );
				if ( empty( $existing_supplier_sku ) ) {
					// Calculate similarity
					$similarity = $this->calculate_title_similarity( $title, $product->post_title );
					if ( $similarity >= 90 ) { // 90% or higher similarity
						// Additional verification: check if EAN matches (if available)
						if ( ! empty( $data['ean'] ) ) {
							$existing_ean = get_post_meta( $product->ID, 'EAN', true );
							if ( ! empty( $existing_ean ) && $existing_ean === $data['ean'] ) {
								return (int) $product->ID;
							}
						} else {
							// If no EAN to verify, use high similarity threshold
							if ( $similarity >= 95 ) {
								return (int) $product->ID;
							}
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Check if two SKUs are similar
	 *
	 * @param string $sku1 First SKU.
	 * @param string $sku2 Second SKU.
	 *
	 * @return bool True if SKUs are similar
	 */
	private function skus_are_similar( $sku1, $sku2 ) {
		// Exact match
		if ( $sku1 === $sku2 ) {
			return true;
		}

		// Remove common separators and compare
		$clean1 = preg_replace( '/[-_\s]/', '', strtolower( $sku1 ) );
		$clean2 = preg_replace( '/[-_\s]/', '', strtolower( $sku2 ) );

		if ( $clean1 === $clean2 ) {
			return true;
		}

		// Check if one contains the other (for prefix variations)
		if ( strlen( $clean1 ) > 5 && strlen( $clean2 ) > 5 ) {
			if ( strpos( $clean1, $clean2 ) !== false || strpos( $clean2, $clean1 ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate title similarity percentage
	 *
	 * @param string $title1 First title.
	 * @param string $title2 Second title.
	 *
	 * @return float Similarity percentage (0-100)
	 */
	private function calculate_title_similarity( $title1, $title2 ) {
		// Normalize titles
		$title1 = strtolower( trim( $title1 ) );
		$title2 = strtolower( trim( $title2 ) );

		// Remove common words that don't help matching
		$common_words = array( 'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for' );
		foreach ( $common_words as $word ) {
			$title1 = preg_replace( '/\b' . $word . '\b/i', '', $title1 );
			$title2 = preg_replace( '/\b' . $word . '\b/i', '', $title2 );
		}

		// Clean extra whitespace
		$title1 = preg_replace( '/\s+/', ' ', trim( $title1 ) );
		$title2 = preg_replace( '/\s+/', ' ', trim( $title2 ) );

		// Use similar_text for PHP native similarity
		similar_text( $title1, $title2, $percent );

		return $percent;
	}

	/**
	 * Claim an existing product for this feed
	 *
	 * Adds supplier_sku and feed_source meta to mark ownership
	 *
	 * @param int   $product_id Product ID to claim.
	 * @param array $data       Product data from XML.
	 *
	 * @return bool True on success
	 */
	public function claim_existing_product( $product_id, $data ) {
		// Add supplier_sku meta
		update_post_meta( $product_id, 'supplier_sku', sanitize_text_field( $data['supplier_sku'] ) );

		// Add feed source for ownership tracking
		update_post_meta( $product_id, '_bestoffer_feed_source', $this->settings->get_feed_identifier() );

		// Add other identifying meta fields
		update_post_meta( $product_id, 'fs_supplier_price', floatval( $data['supplier_price'] ) );
		update_post_meta( $product_id, 'EAN', sanitize_text_field( $data['ean'] ) );
		update_post_meta( $product_id, 'MPN', sanitize_text_field( $data['mpn'] ) );
		update_post_meta( $product_id, 'BRAND', sanitize_text_field( $data['brandname'] ) );
		update_post_meta( $product_id, 'TITLOS', sanitize_text_field( $data['title'] ) );
		update_post_meta( $product_id, '_titlos', sanitize_text_field( $data['title'] ) );

		return true;
	}
}

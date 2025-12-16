<?php
/**
 * Product Metabox Class
 *
 * @package BestOfferSync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Product metabox for sync history
 */
class EnviWeb_BestOffer_Metabox {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
	}

	/**
	 * Add metabox
	 */
	public function add_metabox() {
		add_meta_box(
			'bestoffer_sync_history',
			__( 'Best Offer Sync History', 'best-offer-sync' ),
			array( $this, 'render_metabox' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render metabox
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_metabox( $post ) {
		$product_id   = $post->ID;
		$supplier_sku = get_post_meta( $product_id, 'supplier_sku', true );
		$history      = EnviWeb_BestOffer_Logger::get_product_history( $product_id, 50 );

		?>
		<div class="bestoffer-metabox">
			<div class="bestoffer-metabox-header">
				<?php if ( $supplier_sku ) : ?>
					<p>
						<strong><?php esc_html_e( 'Supplier SKU:', 'best-offer-sync' ); ?></strong>
						<code><?php echo esc_html( $supplier_sku ); ?></code>
					</p>
				<?php else : ?>
					<p class="bestoffer-warning">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'This product does not have a supplier_sku meta field. It will not be synced.', 'best-offer-sync' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $history ) ) : ?>
				<div class="bestoffer-history-section">
					<h4><?php esc_html_e( 'Sync History', 'best-offer-sync' ); ?></h4>
					<table class="widefat bestoffer-history-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Field Changed', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'Old Value', 'best-offer-sync' ); ?></th>
								<th><?php esc_html_e( 'New Value', 'best-offer-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history as $record ) : 
								$row_class = '';
								if ( 'product_locked' === $record->field_changed ) {
									$row_class = 'bestoffer-locked-row';
								} elseif ( 'post_status' === $record->field_changed ) {
									$row_class = 'bestoffer-published-row';
								}
							?>
							<tr<?php echo $row_class ? ' class="' . esc_attr( $row_class ) . '"' : ''; ?>>
								<td>
									<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $record->sync_date ) ) ); ?>
								</td>
								<td>
									<strong><?php echo $this->format_field_name( $record->field_changed ); ?></strong>
								</td>
								<td>
									<?php if ( 'product_locked' === $record->field_changed ) : ?>
										<code><?php echo esc_html( $this->format_value( $record->old_value, $record->field_changed ) ); ?></code>
									<?php else : ?>
										<code><?php echo esc_html( $this->format_value( $record->old_value, $record->field_changed ) ); ?></code>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( 'product_locked' === $record->field_changed ) : ?>
										<code><?php esc_html_e( 'Attempted: ', 'best-offer-sync' ); ?><?php echo esc_html( $this->format_value( $record->new_value, $record->field_changed ) ); ?></code>
									<?php else : ?>
										<code class="bestoffer-new-value"><?php echo esc_html( $this->format_value( $record->new_value, $record->field_changed ) ); ?></code>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p class="bestoffer-no-history">
					<?php esc_html_e( 'No sync history found for this product.', 'best-offer-sync' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Format field name for display
	 *
	 * @param string $field_name Field name.
	 * @return string
	 */
	private function format_field_name( $field_name ) {
		$labels = array(
			'fs_supplier_price' => __( 'Supplier Price', 'best-offer-sync' ),
			'stock_status'      => __( 'Stock Status', 'best-offer-sync' ),
			'backorders'        => __( 'Backorders', 'best-offer-sync' ),
			'post_status'       => __( '✅ Post Status (Published)', 'best-offer-sync' ),
			'product_locked'    => __( '🔒 Product Locked', 'best-offer-sync' ),
		);

		return isset( $labels[ $field_name ] ) ? $labels[ $field_name ] : ucwords( str_replace( '_', ' ', $field_name ) );
	}

	/**
	 * Format value for display
	 *
	 * @param mixed  $value Value to format.
	 * @param string $field_name Field name.
	 * @return string
	 */
	private function format_value( $value, $field_name ) {
		$unserialized = maybe_unserialize( $value );

		// Special handling for locked products
		if ( 'product_locked' === $field_name ) {
			// Old value contains the lock reason, new value contains attempted price
			return (string) $unserialized;
		}

		if ( is_bool( $unserialized ) ) {
			return $unserialized ? __( 'Yes', 'best-offer-sync' ) : __( 'No', 'best-offer-sync' );
		}

		if ( is_numeric( $unserialized ) && 'fs_supplier_price' === $field_name ) {
			return number_format( (float) $unserialized, 2 ) . ' €';
		}

		// For locked products, format price in new value
		if ( is_numeric( $unserialized ) && 'product_locked' === $field_name ) {
			return number_format( (float) $unserialized, 2 ) . ' €';
		}

		if ( 'stock_status' === $field_name ) {
			$statuses = array(
				'instock'    => __( 'In Stock', 'best-offer-sync' ),
				'outofstock' => __( 'Out of Stock', 'best-offer-sync' ),
				'onbackorder' => __( 'On Backorder', 'best-offer-sync' ),
			);
			return isset( $statuses[ $unserialized ] ) ? $statuses[ $unserialized ] : $unserialized;
		}

		if ( 'backorders' === $field_name ) {
			$options = array(
				'no'     => __( 'Do not allow', 'best-offer-sync' ),
				'notify' => __( 'Allow, but notify customer', 'best-offer-sync' ),
				'yes'    => __( 'Allow', 'best-offer-sync' ),
			);
			return isset( $options[ $unserialized ] ) ? $options[ $unserialized ] : $unserialized;
		}

		if ( 'post_status' === $field_name ) {
			$statuses = array(
				'publish' => __( 'Published', 'best-offer-sync' ),
				'draft'   => __( 'Draft', 'best-offer-sync' ),
				'pending' => __( 'Pending Review', 'best-offer-sync' ),
				'private' => __( 'Private', 'best-offer-sync' ),
			);
			return isset( $statuses[ $unserialized ] ) ? $statuses[ $unserialized ] : ucfirst( $unserialized );
		}

		return (string) $unserialized;
	}
}


=== Best Offer WP Sync ===
Contributors: EnviWeb
Tags: woocommerce, sync, xml, products, wp-cli
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WP-CLI command to sync WooCommerce products from Best Offer XML feed. Updates supplier prices and stock levels efficiently.

== Description ==

Best Offer WP Sync provides a WP-CLI command and admin interface to synchronize WooCommerce product data from Best Offer XML feeds. The plugin:

* **Admin Dashboard** with sync logs, statistics, and monitoring
* **Plugin Settings** to customize sync behavior (ignore in-stock products, etc.)
* **Product Metabox** showing sync history for each product
* **Database Logging** of all sync operations and changes
* **Product Lock System** to prevent specific products from being updated
* Finds products by matching `supplier_sku` meta with XML SKU field
* Updates `fs_supplier_price` custom meta field
* Sets all products to **backorder mode** (no stock quantity management)
* Supports both HPOS (High-Performance Order Storage) and legacy WooCommerce storage
* Handles large XML files efficiently with memory-optimized processing
* Complies with LiteSpeed LSPHP 120-second timeout limits
* Production-ready with proper error handling and reporting

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/best-offer-wp-sync/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. WooCommerce must be installed and activated
4. Use WP-CLI to run sync commands

== Usage ==

= Basic Sync Command =

```bash
wp bestoffer sync /path/to/best-offer.xml
```

By default, runs as user ID 390. To use a different user:

```bash
wp bestoffer sync /path/to/best-offer.xml --user=1
```

= Sync with Custom Batch Size =

```bash
wp bestoffer sync /path/to/best-offer.xml --batch-size=50
```

= Resume from Specific Product =

```bash
wp bestoffer sync /path/to/best-offer.xml --offset=1000
```

= Limit Number of Products =

```bash
wp bestoffer sync /path/to/best-offer.xml --limit=500
```

= Dry Run (Test Without Changes) =

```bash
wp bestoffer sync /path/to/best-offer.xml --dry-run
```

= Clear Product Caches =

```bash
wp bestoffer clear-cache
```

= Setup Cron Job =

To automate the sync, add to your crontab:

```bash
# Run every 6 hours
0 */6 * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml
```

== Features ==

= Memory Efficient =
Uses XMLReader for streaming large XML files without loading entire file into memory.

= HPOS Compatible =
Fully compatible with WooCommerce's High-Performance Order Storage system.

= Legacy Support =
Also supports traditional WooCommerce postmeta storage for backward compatibility.

= Timeout Protection =
Built-in protection against LiteSpeed LSPHP timeout limits (110s runtime limit).

= Detailed Reporting =
Provides statistics on updated, skipped, not found, and error products.

= Resume Capability =
Can resume from specific offset if interrupted due to timeout.

== Requirements ==

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* WP-CLI installed
* Products must have `supplier_sku` meta field set

== Frequently Asked Questions ==

= What happens if a product is not found? =

If a product with matching `supplier_sku` is not found, it is skipped and counted in the "Not Found" statistics.

= How do I handle large XML files? =

Use the --batch-size and --limit parameters to process in smaller chunks. If timeout occurs, resume using --offset.

= Does it create new products? =

No, the plugin only updates existing products. It does not create new products.

= Can I skip products that are in stock? =

Yes! Go to Best Offer Sync → Settings and enable "Ignore In-Stock Products". This will skip any products with stock_status = 'instock' during sync, allowing you to maintain manual control over your physical inventory.

= What fields are updated? =

The plugin updates:
- `fs_supplier_price` (custom meta field)
- Stock management (disabled - set to backorder mode)
- Backorders (set to 'yes')
- Stock status (set to 'onbackorder')

= Is it safe for production? =

Yes, the plugin follows WordPress coding standards, includes proper sanitization, uses prepared SQL statements, and includes error handling.

== Changelog ==

= 1.1.0 =
* Added plugin settings page (ignore in-stock products)
* Added admin dashboard with sync logs and statistics
* Added product metabox for sync history
* Added database logging system
* Added product lock system (prevent specific products from updating)
* Changed to backorder mode (no stock quantity management)
* Added change tracking for all field updates
* Added auto-refresh for running syncs
* Added delete log functionality
* Improved UI with modern design

= 1.0.0 =
* Initial release
* WP-CLI sync command
* HPOS and legacy storage support
* Memory-efficient XML processing
* Timeout protection
* Detailed reporting

== Author ==

EnviWeb - https://enviweb.gr


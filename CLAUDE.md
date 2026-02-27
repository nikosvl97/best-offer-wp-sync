# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Best Offer WP Sync is a WordPress plugin that synchronizes WooCommerce product data from an XML feed. It updates supplier prices and stock levels via WP-CLI commands, with comprehensive logging and safety features.

**Key Technologies:**
- WordPress Plugin (PHP 7.4+)
- WooCommerce Integration
- WP-CLI Commands
- XMLReader for memory-efficient XML parsing
- Custom database tables for logging

## Core Architecture

### Main Components

1. **WP-CLI Command Handler** (`includes/class-bestoffer-cli-command.php`)
   - Primary sync logic with batch processing
   - Memory-efficient XML parsing using XMLReader
   - Three-pass batch processing: collect product IDs → bulk load meta → queue changes → apply in transaction
   - Product lookup cache for O(1) SKU matching
   - Concurrent sync prevention via transient locks
   - XML validation with retry logic before processing

2. **Database Layer** (`includes/class-bestoffer-database.php`)
   - Two custom tables: `sync_logs` and `product_history`
   - Handles table creation, upgrades, and cleanup
   - Composite indexes for performance

3. **Logger** (`includes/class-bestoffer-logger.php`)
   - Queued batch inserts (chunks of 200) for performance
   - Tracks sync runs and per-product changes
   - Statistics aggregation

4. **Admin Interface** (`includes/class-bestoffer-admin.php`)
   - Dashboard for sync logs and statistics
   - Settings page for configuration
   - Stale sync cleanup (auto-fails syncs running >5 minutes)
   - AJAX log deletion

5. **Product Metabox** (`includes/class-bestoffer-metabox.php`)
   - Shows sync history per product
   - Displays supplier SKU and change log

### Data Flow

```
XML Feed → XMLReader (streaming parse)
         → Batch collection (default 25 products)
         → Bulk meta load (single query)
         → Product matching via supplier_sku cache
         → Lock/validation checks (cached meta)
         → Queue changes
         → Apply changes (no large transactions, per-product saves)
         → Batch log insert
```

### Performance Optimizations

- **Product Lookup Cache**: Pre-loads all supplier_sku → product_id mappings in memory (paginated 10k at a time)
- **Bulk Meta Loading**: Loads all needed meta fields for batch in ONE query instead of per-product queries
- **Meta Caching**: Cached meta data used for lock checks and stock status
- **Queued Changes**: Changes are queued and applied without large database transactions to prevent site lockup
- **Queued Logging**: Log entries batched in chunks of 200 for efficient inserts
- **No WordPress Deferrals**: Term counting and cache deferrals removed to prevent site issues

### Product Sync Behavior

- **Stock Mode**: ALL products set to backorder mode (manage_stock=false, backorders=yes, stock_status=onbackorder)
- **Price Field**: Updates `fs_supplier_price` custom meta field
- **Draft Publishing**: Products in draft status are automatically published when synced
- **Update Locks**: Products can be locked from updates via meta fields:
  - `_block_xml_update`
  - `_skroutz_block_xml_update`
  - `_block_custom_update`
- **In-Stock Price Only**: When enabled, in-stock products only get supplier price updated (stock status, backorder mode, and publish status are preserved)
- **Unchanged Skip**: Products with matching price and already published are skipped for efficiency

### Safety Features

1. **XML Validation**:
   - Counts products before processing
   - Compares with published WooCommerce products
   - Retries 3x with 30s delays if XML has <50% of published products
   - Auto-skipped for resumed syncs (offset > 0)

2. **Concurrent Sync Prevention**:
   - Transient lock prevents multiple simultaneous syncs
   - Lock expires after 2 hours as safety measure
   - `--force` flag to override stale locks

3. **User Context**:
   - All operations run as specified user (default: 390)
   - Ensures proper audit trails and permissions

4. **Timeout Protection**:
   - `MAX_EXECUTION_TIME` constant (currently disabled/0)
   - Smart timeout prediction based on avg processing speed
   - Batch-based processing allows resume via `--offset`

## Common Development Commands

### Running Sync

```bash
# Full sync (default batch size 25, user 390)
wp bestoffer sync /path/to/best-offer.xml

# Custom batch size
wp bestoffer sync /path/to/best-offer.xml --batch-size=50

# Run as different user
wp bestoffer sync /path/to/best-offer.xml --user=1

# Resume from offset (skips XML validation)
wp bestoffer sync /path/to/best-offer.xml --offset=1000

# Limit products processed
wp bestoffer sync /path/to/best-offer.xml --limit=100

# Dry run (no changes)
wp bestoffer sync /path/to/best-offer.xml --dry-run

# Skip XML validation (not recommended)
wp bestoffer sync /path/to/best-offer.xml --skip-validation

# Force sync (override lock)
wp bestoffer sync /path/to/best-offer.xml --force
```

### Utility Commands

```bash
# Clear WooCommerce caches
wp bestoffer clear-cache

# Clear stale sync lock
wp bestoffer clear-lock

# View help
wp help bestoffer sync
```

### Product Management

```bash
# List products with supplier_sku
wp post list --post_type=product --meta_key=supplier_sku --fields=ID,post_title

# Count products
wp post list --post_type=product --meta_key=supplier_sku --format=count

# Lock product from updates
wp post meta update <PRODUCT_ID> _block_xml_update 1

# Unlock product
wp post meta delete <PRODUCT_ID> _block_xml_update

# List locked products
wp post list --post_type=product --meta_key=_block_xml_update --meta_value=1
```

## Database Schema

### Custom Tables

**enviweb_bestoffer_sync_logs**:
- Tracks each sync run
- Fields: sync_date, xml_file, xml_products, status, products_processed/updated/unchanged/locked/not_found/skipped/skipped_instock/errors, execution_time, batch_size, offset_start/end, error_message
- Indexes: sync_date, status, status_date (composite)

**enviweb_bestoffer_product_history**:
- Per-product change log
- Fields: product_id, sync_log_id, supplier_sku, field_changed, old_value, new_value, sync_date
- Indexes: product_id, sync_log_id, supplier_sku, sync_date, product_date (composite)

### Product Meta Fields

- `supplier_sku` - Used to match XML products to WooCommerce products (REQUIRED for sync)
- `fs_supplier_price` - Supplier price from XML feed
- `_block_xml_update` - Lock flag: prevents XML updates
- `_skroutz_block_xml_update` - Lock flag: prevents Skroutz XML updates
- `_block_custom_update` - Lock flag: prevents custom updates
- `_stock_status` - Used for in-stock ignore feature

## Plugin Settings

**Admin Page**: WordPress Admin → Best Offer Sync

**Settings**:
- `bestoffer_ignore_instock` - When enabled, in-stock products only get price updated (stock/backorder/publish changes skipped)

## Code Patterns

### Adding New Sync Logic

When modifying sync behavior:
1. Changes should be queued in `update_product()` method, not immediately saved
2. Use cached meta via `get_cached_meta()` for performance
3. Log changes via logger's `log_product_change()` method
4. Maintain statistics in `$this->stats` array
5. Avoid large database transactions to prevent site lockup

### Performance Considerations

- Batch size default is 25 (reduced from 100) to prevent database locks
- Product lookup cache is built once per sync (not per batch)
- Meta is bulk-loaded per batch (50 products max per query)
- Changes are applied individually (no transaction wrapper) to prevent blocking site
- Logs are flushed in 200-record chunks

### HPOS Compatibility

The plugin supports both legacy and High-Performance Order Storage (HPOS):
- Checks HPOS status via `OrderUtil::custom_orders_table_usage_is_enabled()`
- Uses appropriate meta update methods based on storage mode
- Meta updates use `$product->update_meta_data()` for HPOS, `update_post_meta()` for legacy

## Plugin Activation

On activation, runs:
- `EnviWeb_BestOffer_Database::create_tables()` - Creates custom tables
- `EnviWeb_BestOffer_Database::upgrade_tables()` - Adds new columns/indexes if needed

## Constants

- `ENVIWEB_BESTOFFER_VERSION` - Plugin version (1.4.0)
- `ENVIWEB_BESTOFFER_PLUGIN_DIR` - Plugin directory path
- `ENVIWEB_BESTOFFER_PLUGIN_URL` - Plugin URL
- `MAX_EXECUTION_TIME` - Timeout limit (0 = disabled)
- `BATCH_SIZE` - Default batch size (25)

## Dependencies

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 6.0+ (tested up to 8.5)
- WP-CLI (for sync commands)

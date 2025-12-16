# Best Offer WP Sync - Usage Guide

## Overview

This plugin provides a WP-CLI command to sync WooCommerce products from the Best Offer XML feed. It updates supplier prices and stock levels for existing products.

## Installation

1. Copy the plugin to your WordPress plugins directory:
   ```bash
   cp -r best-offer-wp-sync /path/to/wordpress/wp-content/plugins/
   ```

2. Activate the plugin:
   ```bash
   wp plugin activate best-offer-sync
   ```

## Basic Usage

### Sync All Products

```bash
wp bestoffer sync /path/to/best-offer.xml
```

This will:
- Read the XML file
- Find products by matching `supplier_sku` meta with XML SKU
- Update `fs_supplier_price`, stock management, and stock quantity
- Display detailed statistics

### Options

#### Batch Size
Process products in batches (default: 100):
```bash
wp bestoffer sync best-offer.xml --batch-size=50
```

#### Offset
Start from a specific product number:
```bash
wp bestoffer sync best-offer.xml --offset=1000
```

#### Limit
Process only a specific number of products:
```bash
wp bestoffer sync best-offer.xml --limit=500
```

#### Dry Run
Test without making changes:
```bash
wp bestoffer sync best-offer.xml --dry-run
```

## Production Deployment

### Cron Job Setup

For automated syncing, add to your server's crontab:

```bash
# Edit crontab
crontab -e

# Add this line to run every 6 hours
0 */6 * * * cd /var/www/html && wp bestoffer sync /path/to/best-offer.xml >> /var/log/bestoffer-sync.log 2>&1
```

### LiteSpeed Compatibility

The plugin is designed to work with LiteSpeed LSPHP:
- Maximum execution time: 110 seconds (under 120s limit)
- Memory-efficient XML streaming
- Automatic timeout detection with resume capability

### Handling Large Files

For very large XML files:

1. **Process in batches:**
   ```bash
   wp bestoffer sync best-offer.xml --batch-size=50
   ```

2. **If timeout occurs, resume from offset:**
   ```bash
   # Check the last offset from error message
   wp bestoffer sync best-offer.xml --offset=500
   ```

3. **Or split into multiple runs:**
   ```bash
   wp bestoffer sync best-offer.xml --limit=1000
   wp bestoffer sync best-offer.xml --offset=1000 --limit=1000
   wp bestoffer sync best-offer.xml --offset=2000 --limit=1000
   ```

## XML Structure Expected

The plugin expects XML in this format:

```xml
<products>
  <product>
    <SKU>PX050006</SKU>
    <supplier_quantity>47</supplier_quantity>
    <supplier_price>103.2500</supplier_price>
  </product>
  <!-- more products -->
</products>
```

## Product Matching

Products are matched using the `supplier_sku` meta field:
- XML `SKU` = WooCommerce product meta `supplier_sku`
- If not found, product is skipped
- No new products are created

## Fields Updated

For each matched product:
1. **fs_supplier_price** (custom meta) - Updated with XML `supplier_price`
2. **Manage Stock** - Set to enabled
3. **Stock Quantity** - Updated with XML `supplier_quantity`
4. **Stock Status** - Set to "instock" if quantity > 0, else "outofstock"

## Storage Compatibility

The plugin automatically detects and supports:
- ✅ **HPOS (High-Performance Order Storage)** - WooCommerce 8.0+
- ✅ **Legacy Storage** - Traditional postmeta tables

No configuration needed - it works with both!

## Monitoring

### View Statistics

After each run, you'll see:
```
=== Sync Statistics ===
Updated:   125 products
Not Found: 15 products
Skipped:   2 products
Errors:    0 products
Time:      45.23 seconds
```

### Clear Caches

After sync, clear product caches:
```bash
wp bestoffer clear-cache
```

## Troubleshooting

### "Product not found" errors
- Ensure products have `supplier_sku` meta field set
- Check that SKU values match exactly

### Timeout issues
- Reduce batch size: `--batch-size=25`
- Use offset to resume: `--offset=X`

### Memory issues
- Plugin uses streaming XML parser (XMLReader)
- Should handle files of any size

### Permission errors
- Ensure WP-CLI runs as appropriate user
- Check file permissions on XML file

## Examples

### Daily Automated Sync
```bash
#!/bin/bash
# sync-bestoffer.sh

cd /var/www/html
wp bestoffer sync /data/feeds/best-offer.xml --batch-size=100
wp bestoffer clear-cache
```

### Sync with Email Notification
```bash
#!/bin/bash
RESULT=$(wp bestoffer sync /path/to/best-offer.xml 2>&1)
echo "$RESULT" | mail -s "BestOffer Sync Complete" admin@example.com
```

### Safe Production Sync
```bash
# 1. Test first with dry run
wp bestoffer sync best-offer.xml --dry-run --limit=10

# 2. Backup database
wp db export backup-$(date +%Y%m%d).sql

# 3. Run actual sync
wp bestoffer sync best-offer.xml

# 4. Clear caches
wp bestoffer clear-cache
wp cache flush
```

## Security Notes

- ✅ All inputs are sanitized
- ✅ Uses prepared SQL statements
- ✅ Follows WordPress coding standards
- ✅ No direct database writes without WooCommerce API
- ✅ Proper nonce validation (N/A for CLI)

## Support

For issues or questions:
- Author: EnviWeb
- Website: https://enviweb.gr


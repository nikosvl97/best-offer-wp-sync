# Best Offer WP Sync - Project Summary

## 🎯 Project Overview

A production-ready WordPress plugin that provides WP-CLI commands to sync WooCommerce products from Best Offer XML feeds.

**Author:** EnviWeb (https://enviweb.gr)  
**Version:** 1.0.0  
**License:** GPL v2 or later

---

## 📁 File Structure

```
best-offer-wp-sync/
├── best-offer-sync.php              # Main plugin file
├── includes/
│   └── class-bestoffer-cli-command.php   # WP-CLI command class
├── best-offer.xml                   # XML feed (138,299 lines)
├── QUICKSTART.md                    # Quick start guide
├── USAGE.md                         # Detailed usage documentation
├── README.txt                       # WordPress.org format readme
├── PROJECT-SUMMARY.md              # This file
├── setup.sh                        # Setup script
├── install-cron.sh                 # Cron installation script
├── assets-readme.txt               # Assets folder documentation
└── .gitignore                      # Git ignore file
```

---

## ✨ Features

### Core Functionality
- ✅ **Product Matching:** Finds products by `supplier_sku` meta matching XML SKU
- ✅ **Selective Updates:** Only updates existing products (no creation)
- ✅ **Field Updates:**
  - `fs_supplier_price` custom meta
  - Stock management (enabled)
  - Stock quantity
  - Stock status (instock/outofstock)

### Technical Features
- ✅ **HPOS Compatible:** Full support for WooCommerce High-Performance Order Storage
- ✅ **Legacy Support:** Compatible with traditional postmeta storage
- ✅ **Memory Efficient:** Uses XMLReader for streaming large files
- ✅ **Timeout Protection:** Built-in 110s limit for LiteSpeed LSPHP compatibility
- ✅ **Resume Capability:** Can resume from specific offset after timeout
- ✅ **Batch Processing:** Configurable batch sizes
- ✅ **Dry Run Mode:** Test without making changes
- ✅ **Detailed Reporting:** Statistics on updated/skipped/errors

### Security & Best Practices
- ✅ WordPress coding standards
- ✅ Input sanitization
- ✅ Prepared SQL statements
- ✅ Proper error handling
- ✅ WooCommerce API usage
- ✅ Function prefix: `enviweb_bestoffer_`

---

## 🚀 Quick Start

### 1. Copy to WordPress
```bash
cp -r best-offer-wp-sync /path/to/wordpress/wp-content/plugins/
```

### 2. Activate Plugin
```bash
cd /path/to/wordpress
wp plugin activate best-offer-sync
```

### 3. Run Sync
```bash
wp bestoffer sync /path/to/best-offer.xml
```

---

## 📖 Available Commands

### Main Sync Command
```bash
wp bestoffer sync <file> [--batch-size=<number>] [--offset=<number>] [--limit=<number>] [--dry-run]
```

**Parameters:**
- `<file>` - Path to XML file (required)
- `--batch-size` - Products per batch (default: 100)
- `--offset` - Start from product number (default: 0)
- `--limit` - Max products to process (default: all)
- `--dry-run` - Test without changes

**Examples:**
```bash
# Full sync
wp bestoffer sync best-offer.xml

# Smaller batches for large files
wp bestoffer sync best-offer.xml --batch-size=50

# Test first 10 products
wp bestoffer sync best-offer.xml --dry-run --limit=10

# Resume from product 1000
wp bestoffer sync best-offer.xml --offset=1000
```

### Cache Clear Command
```bash
wp bestoffer clear-cache
```

---

## 🔄 Production Deployment

### Cron Job Setup

**Every 6 hours:**
```bash
0 */6 * * * cd /var/www/html && wp bestoffer sync /path/to/best-offer.xml >> /var/log/bestoffer-sync.log 2>&1
```

**Daily at 2 AM:**
```bash
0 2 * * * cd /var/www/html && wp bestoffer sync /path/to/best-offer.xml >> /var/log/bestoffer-sync.log 2>&1
```

### Using Install Script
```bash
# Edit configuration
nano install-cron.sh

# Run installer
chmod +x install-cron.sh
./install-cron.sh
```

---

## 📊 XML Structure

The plugin expects XML in this format:

```xml
<products>
  <product>
    <SKU>PX050006</SKU>
    <supplier_quantity>47</supplier_quantity>
    <supplier_price>103.2500</supplier_price>
    <!-- other fields are ignored -->
  </product>
  <!-- more products -->
</products>
```

**Key Fields:**
- `SKU` → Matched with product meta `supplier_sku`
- `supplier_quantity` → Updates stock quantity
- `supplier_price` → Updates `fs_supplier_price` meta

---

## 🔍 How It Works

### Process Flow

1. **Parse XML:** Uses XMLReader to stream large files efficiently
2. **Find Product:** Queries WooCommerce for product with matching `supplier_sku`
3. **Update Product:** If found, updates:
   - `fs_supplier_price` meta
   - Stock management (enabled)
   - Stock quantity
   - Stock status
4. **Skip if Not Found:** No error, just counted in statistics
5. **Report:** Display statistics at end

### Storage Compatibility

**HPOS (High-Performance Order Storage):**
- Detected automatically
- Uses `$product->update_meta_data()`
- Uses `wc_get_products()` with meta_query

**Legacy Storage:**
- Falls back automatically
- Uses `update_post_meta()`
- Uses direct database queries with prepared statements

**No configuration needed** - works with both automatically!

---

## 📈 Statistics Output

After each sync:
```
=== Sync Statistics ===
Updated:   125 products
Not Found: 15 products
Skipped:   2 products
Errors:    0 products
Time:      45.23 seconds
```

**Meanings:**
- **Updated:** Products successfully synced
- **Not Found:** SKU exists in XML but no matching product found
- **Skipped:** Invalid data in XML (empty SKU, etc.)
- **Errors:** Exceptions during update

---

## 🛡️ Security & Performance

### Security Measures
- Input sanitization (`sanitize_text_field()`)
- Prepared SQL statements (`$wpdb->prepare()`)
- WooCommerce API usage (no direct database writes)
- No user input (CLI only)
- WordPress coding standards

### Performance Optimization
- **Memory Efficient:** XMLReader streams large files
- **Timeout Protection:** Max 110s execution time
- **Batch Processing:** Process in chunks
- **Resume Support:** Can continue from offset
- **Progress Bar:** Visual feedback during processing

### LiteSpeed LSPHP Compatibility
- Maximum execution time: 110 seconds (under 120s limit)
- Automatic timeout detection
- Displays resume offset if interrupted
- Memory-efficient processing

---

## 🔧 Prerequisites

### Required
- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- WP-CLI installed

### Product Requirements
Products must have `supplier_sku` meta field set to match XML SKU values.

**Check if products have supplier_sku:**
```bash
wp post list --post_type=product --meta_key=supplier_sku --format=count
```

---

## 🐛 Troubleshooting

### Products Not Found

**Problem:** "Not Found" count is high

**Solutions:**
```bash
# Check if products have supplier_sku meta
wp post list --post_type=product --meta_key=supplier_sku --fields=ID,meta_value

# Check specific SKU from XML
wp post list --post_type=product --meta_key=supplier_sku --meta_value=PX050006

# Query database directly
wp db query "SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key = 'supplier_sku' LIMIT 10"
```

### Timeout Issues

**Problem:** Process stops before completion

**Solutions:**
```bash
# Reduce batch size
wp bestoffer sync file.xml --batch-size=25

# Resume from offset shown in error message
wp bestoffer sync file.xml --offset=500

# Process in multiple runs
wp bestoffer sync file.xml --limit=1000
wp bestoffer sync file.xml --offset=1000 --limit=1000
```

### Memory Issues

**Problem:** Out of memory errors

**Note:** Plugin uses XMLReader which streams data, so memory issues are rare.

**Solutions:**
- Reduce batch size: `--batch-size=25`
- Check PHP memory limit: `wp eval 'echo ini_get("memory_limit");'`
- Increase if needed in wp-config.php: `define('WP_MEMORY_LIMIT', '256M');`

---

## 📝 Development Notes

### Function Prefix
All functions use prefix: `enviweb_bestoffer_`

### Code Organization
- Main plugin file: `best-offer-sync.php`
- CLI command: `includes/class-bestoffer-cli-command.php`
- Assets (future): `assets/css/` and `assets/js/`

### WordPress Guidelines
- Follows WordPress coding standards
- Uses WordPress API functions
- Proper text domain: `best-offer-sync`
- Translation ready

### Testing
```bash
# Dry run (no changes)
wp bestoffer sync file.xml --dry-run --limit=10

# Check updates
wp post meta get <PRODUCT_ID> fs_supplier_price
wp wc product get <PRODUCT_ID>
```

---

## 📚 Documentation Files

1. **QUICKSTART.md** - 5-minute setup guide
2. **USAGE.md** - Detailed usage with examples
3. **README.txt** - WordPress.org format readme
4. **PROJECT-SUMMARY.md** - This comprehensive overview
5. **assets-readme.txt** - Assets folder structure

---

## 🎓 Learning Resources

### WooCommerce APIs Used
- `wc_get_product()` - Get product object
- `wc_get_products()` - Query products (HPOS compatible)
- `$product->update_meta_data()` - Update meta (HPOS)
- `$product->set_manage_stock()` - Enable stock management
- `$product->set_stock_quantity()` - Update stock
- `$product->set_stock_status()` - Set instock/outofstock
- `$product->save()` - Save changes

### WordPress Functions Used
- `update_post_meta()` - Update meta (legacy)
- `$wpdb->prepare()` - Prepared statements
- `sanitize_text_field()` - Input sanitization
- `WP_CLI::line()` - Output messages
- `WP_CLI::error()` - Error messages
- `WP_CLI::success()` - Success messages

---

## 🚦 Next Steps

1. **Test Installation:**
   ```bash
   cp -r best-offer-wp-sync /path/to/wordpress/wp-content/plugins/
   cd /path/to/wordpress
   wp plugin activate best-offer-sync
   ```

2. **Test Sync:**
   ```bash
   wp bestoffer sync best-offer.xml --dry-run --limit=10
   ```

3. **Run Full Sync:**
   ```bash
   wp bestoffer sync best-offer.xml
   ```

4. **Setup Automation:**
   ```bash
   ./install-cron.sh
   ```

5. **Monitor:**
   ```bash
   tail -f /var/log/bestoffer-sync.log
   ```

---

## 📞 Support & Contact

**Author:** EnviWeb  
**Website:** https://enviweb.gr  
**License:** GPL v2 or later

---

## ✅ Checklist for Production

- [ ] Copy plugin to WordPress plugins directory
- [ ] Activate plugin via WP-CLI
- [ ] Verify products have `supplier_sku` meta field
- [ ] Test with dry run mode first
- [ ] Run full sync
- [ ] Verify updates in database
- [ ] Setup cron job for automation
- [ ] Configure log rotation
- [ ] Setup monitoring/alerts
- [ ] Document server-specific paths
- [ ] Test timeout handling with large batches
- [ ] Clear caches after sync

---

**Last Updated:** December 16, 2025  
**Plugin Version:** 1.0.0


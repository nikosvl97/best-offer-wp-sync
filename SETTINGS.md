# Plugin Settings Documentation

## ⚙️ Settings Overview

Best Offer WP Sync includes settings to customize synchronization behavior. Access settings via:

**WordPress Admin → Best Offer Sync → Settings**

---

## 📋 Available Settings

### 1. Ignore In-Stock Products

**Setting Name:** `bestoffer_ignore_instock`  
**Type:** Checkbox (boolean)  
**Default:** Disabled (unchecked)

#### What It Does

When enabled, the sync process will skip any products that currently have their stock status set to "In Stock" (`stock_status = 'instock'`).

This allows you to:
- Maintain manual control over products you have in physical inventory
- Preserve custom pricing for in-stock items
- Only sync out-of-stock products to backorder mode

#### Use Cases

**Use Case 1: Physical Inventory**
You have some products in your warehouse and manage them manually:
```
Product A - In Stock (your inventory) → SKIPPED
Product B - Out of Stock → UPDATED to backorder
Product C - On Backorder → UPDATED
```

**Use Case 2: Custom Pricing**
You want to override supplier prices for products you stock:
```
Product with custom price marked "In Stock" → SKIPPED
Product without stock → UPDATED with supplier price
```

**Use Case 3: Hybrid Model**
Mix dropshipping with physical inventory:
```
Physical inventory products (In Stock) → Managed manually
Dropship products (Out of Stock) → Auto-synced to backorder
```

---

## 🎯 How Settings Are Applied

### During WP-CLI Sync

Settings are automatically applied:

```bash
wp bestoffer sync /path/to/best-offer.xml
```

**Output shows:**
```
Starting Best Offer sync from: /path/to/best-offer.xml
WooCommerce storage: HPOS
Stock mode: All products set to BACKORDER
Ignore in-stock products: ENABLED    ← Setting applied

Processing products  100% [===================]

=== Sync Statistics ===
Processed: 150 products
Updated:   100 products
Locked:    10 products
Not Found: 20 products
Skipped:   20 products    ← Includes in-stock products
Errors:    0 products
Time:      45.23 seconds
```

### During Dry Run

Test the setting without making changes:

```bash
wp bestoffer sync file.xml --dry-run --limit=20
```

**Shows which products would be skipped:**
```
[DRY RUN] Product #123 (SKU001) is IN STOCK - Skipped per settings
[DRY RUN] Would update product #124 (SKU002) - Price: 103.25, Backorder: Yes
[DRY RUN] Product #125 (SKU003) is IN STOCK - Skipped per settings
```

---

## 🔧 Managing Settings

### Via Admin Interface

1. Navigate to **WordPress Admin → Best Offer Sync → Settings**
2. Check/uncheck **"Ignore In-Stock Products"**
3. Click **"Save Settings"**
4. Success message confirms save

### Via WP-CLI

```bash
# Enable ignore in-stock
wp option update bestoffer_ignore_instock 1

# Disable ignore in-stock
wp option update bestoffer_ignore_instock 0

# Check current value
wp option get bestoffer_ignore_instock
```

### Via PHP Code

```php
// Enable the setting
update_option( 'bestoffer_ignore_instock', true );

// Disable the setting
update_option( 'bestoffer_ignore_instock', false );

// Get current value
$ignore_instock = get_option( 'bestoffer_ignore_instock', false );
if ( $ignore_instock ) {
    echo 'In-stock products will be skipped';
}
```

### Via Database

```sql
-- Enable
INSERT INTO wp_options (option_name, option_value) 
VALUES ('bestoffer_ignore_instock', '1')
ON DUPLICATE KEY UPDATE option_value = '1';

-- Disable
UPDATE wp_options 
SET option_value = '0' 
WHERE option_name = 'bestoffer_ignore_instock';

-- Check current value
SELECT option_value 
FROM wp_options 
WHERE option_name = 'bestoffer_ignore_instock';
```

---

## 📊 Statistics Impact

### Skipped Count

In-stock products are counted in the **"Skipped"** statistic:

```
=== Sync Statistics ===
Processed: 150 products
Updated:   100 products
Locked:    10 products
Not Found: 20 products
Skipped:   20 products    ← Includes in-stock products
Errors:    0 products
```

### Not Logged Separately

Currently, in-stock skips are not logged separately in the database. They are simply skipped and counted in the overall "Skipped" count.

To differentiate, check the product's stock status:
```bash
wp wc product get <PRODUCT_ID> --field=stock_status
```

---

## 🔍 Determining Which Products Are Affected

### Find In-Stock Products

```bash
# List all in-stock products
wp post list --post_type=product --meta_key=_stock_status --meta_value=instock --fields=ID,post_title

# Count in-stock products
wp post list --post_type=product --meta_key=_stock_status --meta_value=instock --format=count
```

### Find Products That Would Be Synced

```bash
# List out-of-stock and backorder products (would be synced)
wp post list --post_type=product --meta_key=_stock_status --meta_value=outofstock --fields=ID,post_title
wp post list --post_type=product --meta_key=_stock_status --meta_value=onbackorder --fields=ID,post_title
```

### SQL Query

```sql
-- Find all in-stock products
SELECT p.ID, p.post_title, pm.meta_value as stock_status
FROM wp_posts p
JOIN wp_postmeta pm ON p.ID = pm.post_id
WHERE p.post_type = 'product'
AND pm.meta_key = '_stock_status'
AND pm.meta_value = 'instock';

-- Count by stock status
SELECT pm.meta_value as stock_status, COUNT(*) as count
FROM wp_posts p
JOIN wp_postmeta pm ON p.ID = pm.post_id
WHERE p.post_type = 'product'
AND pm.meta_key = '_stock_status'
GROUP BY pm.meta_value;
```

---

## 🎭 Workflow Examples

### Workflow 1: Enable for Physical Inventory

**Scenario:** You have 50 products in your warehouse, rest are dropship.

1. **Mark your inventory as in-stock:**
   ```bash
   # Manually or via bulk action in WooCommerce
   wp post meta update 123 _stock_status instock
   ```

2. **Enable the setting:**
   ```bash
   wp option update bestoffer_ignore_instock 1
   ```

3. **Run sync:**
   ```bash
   wp bestoffer sync best-offer.xml
   ```

4. **Result:**
   - 50 in-stock products → Skipped (your manual control)
   - Other products → Updated to backorder

### Workflow 2: Temporarily Disable During Inventory Count

**Scenario:** During inventory count, update all products.

1. **Disable the setting:**
   ```bash
   wp option update bestoffer_ignore_instock 0
   ```

2. **Run full sync:**
   ```bash
   wp bestoffer sync best-offer.xml
   ```

3. **Re-enable after count:**
   ```bash
   wp option update bestoffer_ignore_instock 1
   ```

### Workflow 3: Test Impact First

**Scenario:** See what would change before enabling.

1. **Count in-stock products:**
   ```bash
   wp post list --post_type=product --meta_key=_stock_status --meta_value=instock --format=count
   ```

2. **Test with dry run:**
   ```bash
   wp bestoffer sync file.xml --dry-run --limit=50
   ```

3. **Review output, then enable:**
   ```bash
   wp option update bestoffer_ignore_instock 1
   ```

4. **Run actual sync:**
   ```bash
   wp bestoffer sync file.xml
   ```

---

## ⚠️ Important Notes

### Priority Order

Products are checked in this order:

1. **Product Locks** (highest priority)
   - `_block_xml_update`
   - `_skroutz_block_xml_update`
   - `_block_custom_update`
   
2. **In-Stock Check** (if setting enabled)
   - `stock_status = 'instock'`

3. **Normal Update** (if passes all checks)

### Lock vs In-Stock Setting

**Locks:**
- ✅ Product-specific control
- ✅ Logged with reason
- ✅ Visible in history
- ⚙️ Manual per product

**In-Stock Setting:**
- ✅ Global rule
- ✅ Automatic based on status
- ❌ Not logged separately
- ⚙️ One-click enable/disable

**Use locks for:** Specific products you never want synced  
**Use setting for:** Category of products (inventory items)

---

## 🔄 Combining with Other Features

### With Product Locks

Both can be active simultaneously:

```
Product A - Locked → SKIPPED (logged as locked)
Product B - In Stock (setting ON) → SKIPPED (counted in skipped)
Product C - Out of Stock → UPDATED
```

### With Backorder Mode

The setting works with backorder mode:
- In-stock products → Not touched
- Out-of-stock products → Set to backorder

### With Dry Run

Test the combination:
```bash
wp bestoffer sync file.xml --dry-run
```

Shows exactly what would happen.

---

## 📈 Monitoring Impact

### Before Enabling

Check how many products would be affected:
```bash
echo "In-stock products:"
wp post list --post_type=product --meta_key=_stock_status --meta_value=instock --format=count

echo "Would be synced:"
wp post list --post_type=product --meta_key=_stock_status --meta_value=outofstock --format=count
```

### After Sync

Compare statistics:
```
Before: Updated 150 products
After:  Updated 100 products, Skipped 50
```

### Admin Dashboard

View in **Best Offer Sync → Sync Logs**:
- Check "Skipped" column
- Higher skipped count = setting working

---

## 🐛 Troubleshooting

### Problem: Setting Not Applied

**Check if setting is enabled:**
```bash
wp option get bestoffer_ignore_instock
```

Should return `1` (enabled) or `0` (disabled).

**Solution:** Re-save setting in admin or:
```bash
wp option update bestoffer_ignore_instock 1
```

### Problem: In-Stock Products Still Updated

**Check product stock status:**
```bash
wp wc product get <PRODUCT_ID> --field=stock_status
```

**Possible causes:**
- Setting is disabled
- Product is not actually "instock"
- Product is locked (different skip reason)

### Problem: Want to Update One In-Stock Product

**Temporarily change status:**
```bash
# Before sync
wp post meta update <PRODUCT_ID> _stock_status outofstock

# Run sync
wp bestoffer sync file.xml

# After sync (if needed)
wp post meta update <PRODUCT_ID> _stock_status instock
```

---

## 📚 Related Documentation

- **LOCKS-FEATURE.md** - Product lock system
- **USAGE.md** - General usage guide
- **FEATURES.md** - All features
- **CHANGELOG.md** - Version history

---

## 💡 Best Practices

1. **Test First:** Use dry-run before enabling
2. **Document Reason:** Note why setting is enabled
3. **Regular Review:** Check if setting is still needed
4. **Monitor Statistics:** Watch skipped counts
5. **Communicate:** Inform team about the setting
6. **Backup First:** Backup before major changes

---

## 🆘 Support

**Setting Location:**  
WordPress Admin → Best Offer Sync → Settings

**Default Value:** Disabled (unchecked)

**Option Name:** `bestoffer_ignore_instock`

**Storage:** `wp_options` table

---

**Author:** EnviWeb (https://enviweb.gr)  
**Plugin Version:** 1.1.0+  
**Feature Added:** December 16, 2025


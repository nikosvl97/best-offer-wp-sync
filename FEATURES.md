# Best Offer WP Sync - Features Documentation

## 🎯 Core Features

### 1. Product Synchronization
- **XML Parsing**: Memory-efficient streaming of large XML files
- **Product Matching**: Finds products by `supplier_sku` meta field
- **Selective Updates**: Only updates existing products (no creation)
- **Backorder Mode**: All products set to backorder (no stock quantity management)

### 2. Fields Updated
- ✅ `fs_supplier_price` - Custom meta field for supplier price
- ✅ `manage_stock` - Set to false (disabled)
- ✅ `backorders` - Set to 'yes' (allow backorders)
- ✅ `stock_status` - Set to 'onbackorder'

---

## 📊 Admin Dashboard

### Statistics Cards
- **Total Syncs** - Number of syncs in last 30 days
- **Products Updated** - Total products updated
- **Errors** - Total errors encountered
- **Avg Execution Time** - Average sync duration

### Last Sync Info
- Sync status with color-coded badge
- Date and time of sync
- XML file name
- Execution time
- Detailed counts (updated, not found, errors)
- Error message display (if failed)

### Sync History Table
Shows last 50 sync executions with:
- Date and time
- Status (completed, running, failed, timeout)
- XML file name
- Update counts and statistics
- Execution time
- Delete action for cleanup

---

## 📦 Product Metabox

Each product edit page shows:

### Supplier Information
- Supplier SKU display
- Warning if SKU is missing

### Sync History Table
- Date/time of each change
- Field changed (price, stock status, backorders)
- Old value vs new value
- Last 50 changes per product

### Field Change Tracking
Logs changes to:
- Supplier Price
- Stock Status
- Backorders setting

---

## 🗄️ Database Logging

### Sync Logs Table
**Table**: `wp_enviweb_bestoffer_sync_logs`

Stores:
- Sync execution details
- Statistics (processed, updated, errors, etc.)
- Execution time
- Status (completed, running, failed, timeout)
- Error messages
- Batch parameters (size, offset)

### Product History Table
**Table**: `wp_enviweb_bestoffer_product_history`

Stores:
- Product ID
- Sync log ID (relationship)
- Supplier SKU
- Field changed
- Old value
- New value
- Change date

---

## 🎨 UI/UX Features

### Modern Design
- Clean, professional interface
- Card-based layout
- Color-coded status indicators
- Responsive design for all devices

### Status Badges
- 🟢 **Completed** - Green badge
- 🔵 **Running** - Blue badge with loading animation
- 🔴 **Failed** - Red badge
- 🟡 **Timeout** - Yellow badge

### Interactive Elements
- Delete log entries with confirmation
- Auto-refresh when sync is running
- Smooth animations
- Hover effects

### Visual Indicators
- Dashboard icons for statistics
- Loading spinner for running syncs
- Warning icons for errors
- Color-coded change values

---

## ⚙️ Technical Features

### Storage Compatibility
- **HPOS Support** - High-Performance Order Storage
- **Legacy Support** - Traditional postmeta tables
- **Auto-detection** - Automatically uses correct storage method

### Memory Management
- **XMLReader** - Streams XML files (not loaded entirely in memory)
- **Batch Processing** - Process in configurable chunks
- **Timeout Protection** - Stops before LiteSpeed 120s limit

### Security
- **Input Sanitization** - All inputs cleaned
- **Prepared Statements** - SQL injection prevention
- **Capability Checks** - Only admins can access
- **Nonce Verification** - AJAX request protection

### Performance
- **Efficient Queries** - Optimized database queries
- **Indexing** - Database tables properly indexed
- **Caching** - WooCommerce cache integration
- **Progress Tracking** - Visual progress bar

---

## 🔧 WP-CLI Features

### Sync Command
```bash
wp bestoffer sync <file> [options]
```

**Options:**
- `--batch-size=<n>` - Products per batch
- `--offset=<n>` - Start from product N
- `--limit=<n>` - Process max N products
- `--dry-run` - Test without changes

**Features:**
- Progress bar
- Real-time statistics
- Timeout detection
- Resume capability
- Error handling

### Cache Command
```bash
wp bestoffer clear-cache
```

Clears:
- WooCommerce product transients
- WordPress object cache

---

## 📈 Monitoring & Reporting

### Real-time Feedback
- Progress bar during sync
- Live statistics updates
- Error messages
- Warning notifications

### Post-sync Reports
- Detailed statistics
- Execution time
- Success/failure counts
- Resume instructions (if timeout)

### Historical Data
- 30-day statistics
- Complete sync history
- Per-product change history
- Error logs

---

## 🔐 Admin Permissions

### Required Capabilities
- `manage_woocommerce` - For admin access
- Standard WordPress capability system
- AJAX nonce verification

### Access Control
- Admin menu item
- Product metabox (all users who can edit products)
- WP-CLI (server access required)

---

## 🌐 Internationalization

### Translation Ready
- Text domain: `best-offer-sync`
- All strings translatable
- Greek translations planned

### Date/Time Formatting
- Uses WordPress date format settings
- Timezone-aware
- Localized number formatting

---

## 🚀 Performance Metrics

### Typical Performance
- **Small XML (< 1000 products)**: 10-30 seconds
- **Medium XML (1000-10000)**: 1-3 minutes
- **Large XML (> 10000)**: Multiple batches with resume

### Resource Usage
- **Memory**: < 128MB (streaming XML)
- **CPU**: Moderate during sync
- **Database**: Efficient queries with indexes

---

## 📱 Responsive Design

### Desktop
- Full-width dashboard
- Multi-column layout
- Detailed tables

### Tablet
- Adjusted grid layout
- Readable tables
- Touch-friendly buttons

### Mobile
- Single-column layout
- Scrollable tables
- Optimized font sizes

---

## 🔄 Auto-refresh

When a sync is running:
- Admin page auto-refreshes every 30 seconds
- Loading indicator displayed
- Prevents stale data display

---

## 🎯 Future Features (Planned)

- Email notifications on sync completion/failure
- Scheduled syncs via WordPress cron
- Multi-file sync support
- Advanced filtering options
- Export sync reports
- Rollback functionality
- Bulk product operations
- API endpoints for remote triggers

---

## 📞 Support Features

### Error Handling
- Detailed error messages
- Stack trace logging
- User-friendly notifications

### Debugging
- Dry-run mode
- Verbose logging
- Database query logging (optional)

### Documentation
- Inline help text
- Usage examples
- Code comments
- Multiple README files

---

## ✅ Quality Assurance

### Code Standards
- WordPress Coding Standards
- PHPDoc documentation
- Consistent naming conventions
- Security best practices

### Testing
- Dry-run mode for safe testing
- Small batch testing
- Offset/limit testing
- Error scenario handling

### Maintenance
- Regular updates
- Security patches
- Performance optimization
- Feature enhancements


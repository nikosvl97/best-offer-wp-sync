# Parallel Sync Guide

## Overview
With timeout limits removed and bulk optimizations in place, you can now run multiple sync processes in parallel to maximize throughput and dramatically reduce total sync time.

## Performance Improvement
- **Single Process:** ~100-300 products/sec
- **4 Parallel Processes:** ~400-1200 products/sec
- **8 Parallel Processes:** ~800-2400 products/sec

---

## Method 1: Parallel Sync with Offset Splitting (Recommended)

Split your XML into ranges and process them simultaneously.

### Example: 10,000 Products with 4 Parallel Processes

```bash
# Terminal 1 - Process products 0-2499
wp bestoffer sync /path/to/best-offer.xml --offset=0 --limit=2500 &

# Terminal 2 - Process products 2500-4999
wp bestoffer sync /path/to/best-offer.xml --offset=2500 --limit=2500 &

# Terminal 3 - Process products 5000-7499
wp bestoffer sync /path/to/best-offer.xml --offset=5000 --limit=2500 &

# Terminal 4 - Process products 7500-9999
wp bestoffer sync /path/to/best-offer.xml --offset=7500 --limit=2500 &

# Wait for all to complete
wait
```

### Automated Script for Parallel Processing

Create a file `parallel-sync.sh`:

```bash
#!/bin/bash

# Configuration
XML_FILE="/path/to/best-offer.xml"
TOTAL_PRODUCTS=10000  # Total products in XML
PARALLEL_JOBS=4       # Number of parallel processes
PRODUCTS_PER_JOB=$((TOTAL_PRODUCTS / PARALLEL_JOBS))

# Run parallel syncs
for i in $(seq 0 $((PARALLEL_JOBS - 1))); do
    OFFSET=$((i * PRODUCTS_PER_JOB))
    
    echo "Starting sync job $((i + 1))/$PARALLEL_JOBS - Offset: $OFFSET, Limit: $PRODUCTS_PER_JOB"
    
    wp bestoffer sync "$XML_FILE" \
        --offset=$OFFSET \
        --limit=$PRODUCTS_PER_JOB \
        --path=/path/to/wordpress \
        > /var/log/bestoffer-sync-job-$i.log 2>&1 &
done

# Wait for all jobs to complete
wait

echo "All parallel sync jobs completed!"
echo "Check logs in /var/log/bestoffer-sync-job-*.log"
```

Make it executable and run:

```bash
chmod +x parallel-sync.sh
./parallel-sync.sh
```

---

## Method 2: GNU Parallel (Most Efficient)

Install GNU Parallel for sophisticated job management:

```bash
# Install GNU Parallel
sudo apt-get install parallel
```

### Basic Parallel Sync

```bash
# Generate range commands and run in parallel
seq 0 2500 10000 | parallel -j 4 \
    "wp bestoffer sync /path/to/best-offer.xml --offset={} --limit=2500"
```

### Advanced with Progress Monitoring

```bash
# With progress bar and job slots
seq 0 2500 10000 | parallel --progress -j 4 --joblog parallel-sync.log \
    "wp bestoffer sync /path/to/best-offer.xml --offset={} --limit=2500"
```

### With Resource Limits

```bash
# Limit to 4 jobs, with load average monitoring
seq 0 2500 10000 | parallel -j 4 --load 80% \
    "wp bestoffer sync /path/to/best-offer.xml --offset={} --limit=2500"
```

---

## Method 3: Screen/Tmux Sessions

Use screen or tmux to manage multiple sync sessions:

### Using Screen

```bash
# Start first session
screen -S sync1
wp bestoffer sync /path/to/best-offer.xml --offset=0 --limit=2500
# Press Ctrl+A, then D to detach

# Start second session
screen -S sync2
wp bestoffer sync /path/to/best-offer.xml --offset=2500 --limit=2500
# Press Ctrl+A, then D to detach

# Start third session
screen -S sync3
wp bestoffer sync /path/to/best-offer.xml --offset=5000 --limit=2500
# Press Ctrl+A, then D to detach

# Start fourth session
screen -S sync4
wp bestoffer sync /path/to/best-offer.xml --offset=7500 --limit=2500
# Press Ctrl+A, then D to detach

# Monitor sessions
screen -ls

# Reattach to a session
screen -r sync1
```

### Using Tmux

```bash
# Create new tmux session with 4 panes
tmux new-session \; \
  split-window -h \; \
  split-window -v \; \
  select-pane -t 0 \; \
  split-window -v \; \

# In each pane, run:
# Pane 1: wp bestoffer sync /path/to/best-offer.xml --offset=0 --limit=2500
# Pane 2: wp bestoffer sync /path/to/best-offer.xml --offset=2500 --limit=2500
# Pane 3: wp bestoffer sync /path/to/best-offer.xml --offset=5000 --limit=2500
# Pane 4: wp bestoffer sync /path/to/best-offer.xml --offset=7500 --limit=2500
```

---

## Method 4: Cron-Based Parallel Scheduling

Schedule multiple jobs to start at the same time:

```bash
# Edit crontab
crontab -e

# Add parallel jobs (example: run every 6 hours at :00)
0 */6 * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml --offset=0 --limit=2500 >> /var/log/bestoffer-sync-1.log 2>&1
0 */6 * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml --offset=2500 --limit=2500 >> /var/log/bestoffer-sync-2.log 2>&1
0 */6 * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml --offset=5000 --limit=2500 >> /var/log/bestoffer-sync-3.log 2>&1
0 */6 * * * cd /path/to/wordpress && wp bestoffer sync /path/to/best-offer.xml --offset=7500 --limit=2500 >> /var/log/bestoffer-sync-4.log 2>&1
```

---

## Determining Optimal Number of Parallel Jobs

### CPU-Based Calculation

```bash
# Get number of CPU cores
CORES=$(nproc)

# Recommended: Use 50-75% of cores for parallel syncs
PARALLEL_JOBS=$((CORES * 3 / 4))

echo "Recommended parallel jobs: $PARALLEL_JOBS"
```

### Performance Testing

Test with different parallel job counts:

```bash
# Test with 2 parallel jobs
time ./parallel-sync.sh  # with PARALLEL_JOBS=2

# Test with 4 parallel jobs
time ./parallel-sync.sh  # with PARALLEL_JOBS=4

# Test with 8 parallel jobs
time ./parallel-sync.sh  # with PARALLEL_JOBS=8
```

### Monitoring System Resources

While running parallel syncs:

```bash
# Monitor CPU usage
htop

# Monitor MySQL connections
watch -n 1 'mysql -e "SHOW PROCESSLIST" | grep -c "Query"'

# Monitor memory usage
watch -n 1 free -h

# Monitor disk I/O
iostat -x 1
```

---

## Safety Considerations

### 1. Database Connection Limits

Check MySQL max connections:

```sql
SHOW VARIABLES LIKE 'max_connections';
```

Ensure you have enough connections:
- Each parallel job uses ~5-10 connections
- 4 parallel jobs = ~20-40 connections needed
- Recommended: Set `max_connections` to at least 150

### 2. Memory Usage

Each sync process uses ~50-150MB RAM:
- 4 parallel jobs = ~200-600MB
- 8 parallel jobs = ~400-1200MB
- Ensure adequate free memory

### 3. Avoid Overlapping Ranges

**CRITICAL:** Never have overlapping offset ranges!

❌ **BAD:**
```bash
wp bestoffer sync file.xml --offset=0 --limit=3000 &
wp bestoffer sync file.xml --offset=2500 --limit=3000 &  # Overlaps!
```

✅ **GOOD:**
```bash
wp bestoffer sync file.xml --offset=0 --limit=2500 &
wp bestoffer sync file.xml --offset=2500 --limit=2500 &  # No overlap
```

### 4. Monitor First Run

Always monitor the first parallel run:

```bash
# Run with visible output
./parallel-sync.sh

# Watch all log files
tail -f /var/log/bestoffer-sync-job-*.log
```

---

## Complete Automated Parallel Sync Script

Save as `auto-parallel-sync.sh`:

```bash
#!/bin/bash

################################################################################
# Automated Parallel Best Offer Sync
# Automatically determines optimal settings and runs parallel sync
################################################################################

set -e  # Exit on error

# Configuration
XML_FILE="${1:-/path/to/best-offer.xml}"
WP_PATH="${2:-/var/www/html}"
LOG_DIR="/var/log/bestoffer-parallel"

# Auto-detect settings
CORES=$(nproc)
PARALLEL_JOBS=$((CORES * 3 / 4))
[ $PARALLEL_JOBS -lt 1 ] && PARALLEL_JOBS=1

# Create log directory
mkdir -p "$LOG_DIR"

# Count products in XML
echo "Counting products in XML..."
TOTAL_PRODUCTS=$(grep -c "<product>" "$XML_FILE")
echo "Total products: $TOTAL_PRODUCTS"

# Calculate products per job
PRODUCTS_PER_JOB=$((TOTAL_PRODUCTS / PARALLEL_JOBS))
echo "Parallel jobs: $PARALLEL_JOBS"
echo "Products per job: $PRODUCTS_PER_JOB"
echo ""

# Start time
START_TIME=$(date +%s)

# Run parallel syncs
echo "Starting parallel sync jobs..."
for i in $(seq 0 $((PARALLEL_JOBS - 1))); do
    OFFSET=$((i * PRODUCTS_PER_JOB))
    LOG_FILE="$LOG_DIR/sync-job-$i-$(date +%Y%m%d-%H%M%S).log"
    
    echo "  Job $((i + 1))/$PARALLEL_JOBS - Offset: $OFFSET, Limit: $PRODUCTS_PER_JOB"
    echo "  Log: $LOG_FILE"
    
    wp bestoffer sync "$XML_FILE" \
        --offset=$OFFSET \
        --limit=$PRODUCTS_PER_JOB \
        --path="$WP_PATH" \
        > "$LOG_FILE" 2>&1 &
    
    # Store process ID
    eval "JOB_PID_$i=$!"
done

echo ""
echo "All jobs started! Waiting for completion..."
echo ""

# Wait for all jobs and capture exit codes
FAILED_JOBS=0
for i in $(seq 0 $((PARALLEL_JOBS - 1))); do
    eval "PID=\$JOB_PID_$i"
    
    if wait "$PID"; then
        echo "✅ Job $((i + 1))/$PARALLEL_JOBS completed successfully"
    else
        echo "❌ Job $((i + 1))/$PARALLEL_JOBS failed!"
        FAILED_JOBS=$((FAILED_JOBS + 1))
    fi
done

# Calculate total time
END_TIME=$(date +%s)
TOTAL_TIME=$((END_TIME - START_TIME))
PRODUCTS_PER_SEC=$((TOTAL_PRODUCTS / TOTAL_TIME))

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "PARALLEL SYNC COMPLETED"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Total products:     $TOTAL_PRODUCTS"
echo "Parallel jobs:      $PARALLEL_JOBS"
echo "Total time:         ${TOTAL_TIME}s"
echo "Throughput:         ${PRODUCTS_PER_SEC} products/sec"
echo "Failed jobs:        $FAILED_JOBS"
echo ""
echo "Logs available in:  $LOG_DIR"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Exit with error if any jobs failed
[ $FAILED_JOBS -gt 0 ] && exit 1

exit 0
```

Usage:

```bash
chmod +x auto-parallel-sync.sh

# Basic usage (uses defaults)
./auto-parallel-sync.sh

# Specify XML file and WordPress path
./auto-parallel-sync.sh /path/to/best-offer.xml /var/www/html
```

---

## Performance Expectations

### Single Process vs Parallel

| Products | Single Process | 4 Parallel | 8 Parallel | Speedup |
|----------|---------------|------------|------------|---------|
| 1,000    | ~5s           | ~2s        | ~1s        | 5x      |
| 5,000    | ~25s          | ~7s        | ~4s        | 6x      |
| 10,000   | ~50s          | ~13s       | ~7s        | 7x      |
| 50,000   | ~4min         | ~1min      | ~35s       | 7x      |
| 100,000  | ~8min         | ~2min      | ~1min      | 8x      |

*Based on 200 products/sec per process on modern server*

---

## Troubleshooting Parallel Syncs

### Issue: "Too many connections" Error

**Solution:** Reduce parallel jobs or increase MySQL max_connections:

```sql
SET GLOBAL max_connections = 250;
```

Make permanent in `/etc/mysql/my.cnf`:

```ini
[mysqld]
max_connections = 250
```

### Issue: High Load Average

**Solution:** Reduce parallel jobs:

```bash
# Use fewer parallel jobs
PARALLEL_JOBS=2 ./parallel-sync.sh
```

### Issue: Memory Exhaustion

**Solution:** 
1. Reduce parallel jobs
2. Reduce batch size per job:

```bash
wp bestoffer sync file.xml --offset=0 --limit=2500 --batch-size=50
```

### Issue: Logs Show Different Speeds

This is normal - some product ranges may have:
- More locked products (faster to skip)
- More price changes (slower to update)
- Different database cache hit rates

---

## Best Practices

1. **Start Small:** Test with 2 parallel jobs first
2. **Monitor Resources:** Watch CPU, memory, and database during first run
3. **Check Logs:** Verify all jobs complete successfully
4. **Avoid Peak Hours:** Run parallel syncs during low-traffic periods
5. **Use Automation:** Set up the automated script for regular runs
6. **Keep Logs:** Maintain logs for troubleshooting and performance tracking

---

## Cron Setup for Automated Parallel Sync

```bash
# Run parallel sync every 6 hours
0 */6 * * * /path/to/auto-parallel-sync.sh /path/to/best-offer.xml /var/www/html >> /var/log/bestoffer-cron.log 2>&1
```

---

## Summary

With timeouts removed and parallel processing enabled:

✅ **Single process:** 100-300 products/sec  
✅ **4 parallel processes:** 400-1200 products/sec  
✅ **Full speed mode:** No artificial limits  
✅ **Automated scripts:** Easy to set up and maintain  

Your 20,000 product catalog can now sync in **~30-120 seconds** instead of hours!


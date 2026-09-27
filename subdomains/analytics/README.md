# Analytics Snapshot System

This system allows you to save historical snapshots of your database and view analytics data from different points in time.

## Files Created

1. **`db/saveAnalyticsSnapshot.php`** - Cronjob script to create snapshots
2. **`db/analytics_snapshot_tables.sql`** - SQL schema for snapshot tables
3. **`subdomains/analytics/helpers.php`** - Helper functions for data retrieval
4. **`subdomains/analytics/manage.php`** - Web interface to manage snapshots
5. **`subdomains/analytics/index.php`** - Updated analytics dashboard with snapshot viewing

## Setup Instructions

### 1. Create Database Tables

Run the SQL script to create the necessary tables:

```bash
mysql -u your_username -p your_database < db/analytics_snapshot_tables.sql
```

Or import it via phpMyAdmin or your preferred MySQL client.

### 2. Test Manual Snapshot Creation

Navigate to: `http://yourdomain.com/subdomains/analytics/manage.php`

Click "Create Snapshot Now" to create your first snapshot manually.

### 3. Set Up Automated Snapshots (Optional)

#### For Linux/Unix Cron:

Edit your crontab:
```bash
crontab -e
```

Add one of these lines depending on your desired frequency:

**Daily at midnight:**
```
0 0 * * * cd /path/to/your/project && php db/saveAnalyticsSnapshot.php
```

**Weekly (Sunday at midnight):**
```
0 0 * * 0 cd /path/to/your/project && php db/saveAnalyticsSnapshot.php
```

**Monthly (1st of month at midnight):**
```
0 0 1 * * cd /path/to/your/project && php db/saveAnalyticsSnapshot.php
```

#### For Windows Task Scheduler:

1. Open Task Scheduler
2. Create a new task
3. Set the trigger (daily/weekly/monthly)
4. Set the action to run:
   ```
   "C:\path\to\php.exe" "D:\3_Programmierung\xampp\htdocs\db\saveAnalyticsSnapshot.php"
   ```

#### For XAMPP (using existing cronjob.php):

If you already have a cronjob system in place, add this to your existing `cronjob.php`:

```php
require_once 'db/saveAnalyticsSnapshot.php';
```

## Usage

### Viewing Analytics

1. **Current Data**: Visit `http://yourdomain.com/subdomains/analytics/`
2. **Historical Data**: Use the dropdown in the header to select a snapshot date
3. **Manage Snapshots**: Click "Manage Snapshots" button to create or delete snapshots

### Managing Snapshots

Navigate to: `http://yourdomain.com/subdomains/analytics/manage.php`

Here you can:
- Create new snapshots manually (with optional description)
- View all existing snapshots
- Delete old snapshots
- See cronjob setup instructions

## Database Schema

### Main Tables

- **`analytics_snapshots`** - Stores snapshot metadata (ID, date, description)
- **`analytics_content_items_snapshot`** - Historical content_items data
- **`analytics_inventories_snapshot`** - Historical inventories data
- **`analytics_market_snapshot`** - Historical market data

### Relationships

All snapshot data tables reference `analytics_snapshots.snapshot_id` with CASCADE DELETE, so deleting a snapshot automatically removes all associated data.

## Features

✅ Save complete database state at any point in time
✅ View historical analytics with the same interface
✅ Compare different time periods
✅ Manual or automated snapshot creation
✅ Easy snapshot management (create/delete)
✅ No impact on current data
✅ Automatic cleanup when deleting snapshots

## Storage Considerations

Each snapshot stores copies of:
- All content_items records
- All inventories records
- All market records

Consider:
- Setting up a retention policy (delete old snapshots)
- Running snapshots at appropriate intervals (daily/weekly/monthly based on needs)
- Monitoring database size

## Troubleshooting

### "Table doesn't exist" error
Run the SQL schema file to create the tables.

### Cronjob not running
- Check file paths are absolute
- Verify PHP executable path
- Check cronjob logs: `grep CRON /var/log/syslog`
- Test script manually: `php db/saveAnalyticsSnapshot.php`

### Empty snapshots
Ensure the database connection in `db/connection.php` is working correctly.

## Future Enhancements

Possible additions:
- Snapshot comparison view (side-by-side)
- Automatic retention policy (delete snapshots older than X days)
- Export snapshots to JSON/CSV
- Snapshot notes/tags
- Scheduled snapshots via web interface

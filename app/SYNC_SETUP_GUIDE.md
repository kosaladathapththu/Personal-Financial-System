# PFMS Oracle Sync Setup Guide

## Prerequisites

1. **Oracle Database** (11g or higher)
   - Oracle XE (Express Edition) recommended for development
   - Download from: https://www.oracle.com/database/technologies/xe-downloads.html

2. **SQL Developer**
   - Download from: https://www.oracle.com/tools/downloads/sqldev-downloads.html

3. **PHP OCI8 Extension**
   - Requires Oracle Instant Client

## Step-by-Step Setup

### 1. Install Oracle Database

**Windows:**
```bash
# Download and install Oracle XE
# Default credentials:
# Username: system
# Password: (set during installation)
# SID: XE
```

**Linux:**
```bash
# Download RPM
wget https://download.oracle.com/otn-pub/otn_software/db-express/oracle-database-xe-21c-1.0-1.ol7.x86_64.rpm

# Install
sudo rpm -ivh oracle-database-xe-21c-1.0-1.ol7.x86_64.rpm

# Configure
sudo /etc/init.d/oracle-xe-21c configure
```

### 2. Create Oracle User in SQL Developer
```sql
-- Connect as SYSTEM
-- Create new user for PFMS
CREATE USER pfms_user IDENTIFIED BY YourPassword123;

-- Grant privileges
GRANT CONNECT, RESOURCE TO pfms_user;
GRANT CREATE SESSION TO pfms_user;
GRANT CREATE TABLE TO pfms_user;
GRANT CREATE VIEW TO pfms_user;
GRANT CREATE SEQUENCE TO pfms_user;
GRANT UNLIMITED TABLESPACE TO pfms_user;

-- Verify
SELECT * FROM dba_users WHERE username = 'PFMS_USER';
```

### 3. Run Oracle Schema

1. Open SQL Developer
2. Create new connection:
   - Name: PFMS_Production
   - Username: pfms_user
   - Password: YourPassword123
   - Hostname: localhost
   - Port: 1521
   - SID: XE

3. Run the Oracle schema SQL provided earlier

### 4. Install PHP OCI8 Extension

**Windows (XAMPP):**
```bash
# 1. Download Oracle Instant Client
# https://www.oracle.com/database/technologies/instant-client/winx64-64-downloads.html

# 2. Extract to C:\oracle\instantclient_21_x

# 3. Add to PATH
# System Properties > Advanced > Environment Variables
# Add: C:\oracle\instantclient_21_x

# 4. Enable extension in php.ini
extension=oci8_19

# 5. Restart Apache
```

**Linux:**
```bash
# Install dependencies
sudo apt-get install libaio1

# Download Instant Client
cd /tmp
wget https://download.oracle.com/otn_software/linux/instantclient/2110000/instantclient-basic-linux.x64-21.10.0.0.0dbru.zip
sudo unzip instantclient-basic-linux.x64-21.10.0.0.0dbru.zip -d /opt/oracle

# Configure
sudo sh -c "echo /opt/oracle/instantclient_21_10 > /etc/ld.so.conf.d/oracle-instantclient.conf"
sudo ldconfig

# Install OCI8
sudo pecl install oci8

# Enable extension
echo "extension=oci8.so" | sudo tee -a /etc/php/8.x/apache2/php.ini

# Restart Apache
sudo systemctl restart apache2
```

### 5. Configure PFMS

Edit `pfms/config/env.php`:
```php
// Oracle Database Configuration
define('ORACLE_HOST', 'localhost');
define('ORACLE_PORT', '1521');
define('ORACLE_SID', 'XE');
define('ORACLE_USER', 'pfms_user');
define('ORACLE_PASS', 'YourPassword123');

// Sync Configuration
define('AUTO_SYNC_ENABLED', true);
define('SYNC_INTERVAL_MINUTES', 15);
```

### 6. Test Connection

Create `pfms/test_oracle.php`:
```php
<?php
require 'config/env.php';
require 'db/oracle.php';

if (oracle_test_connection()) {
    echo "✅ Oracle connection successful!\n";
    
    $conn = oracle_connect();
    $sql = "SELECT table_name FROM user_tables";
    $stmt = oci_parse($conn, $sql);
    oci_execute($stmt);
    
    echo "\nTables in database:\n";
    while ($row = oci_fetch_array($stmt, OCI_ASSOC)) {
        echo "- " . $row['TABLE_NAME'] . "\n";
    }
    
    oracle_close($conn);
} else {
    echo "❌ Oracle connection failed!\n";
    echo "Check your configuration in config/env.php\n";
}
```

Run: `php test_oracle.php`

### 7. First Sync

1. Login to PFMS
2. Navigate to: http://localhost/pfms/public/sync.php
3. Click "Start Sync"
4. Verify data in SQL Developer:
```sql
-- Check synced data
SELECT * FROM USERS_CLOUD;
SELECT * FROM ACCOUNTS_CLOUD;
SELECT * FROM CATEGORIES_CLOUD;
SELECT * FROM TRANSACTIONS_CLOUD;
SELECT * FROM SYNC_LOG;
```

## Troubleshooting

### Connection Errors
```bash
# Test TNS connection
tnsping XE

# Check listener
lsnrctl status
```

### OCI8 Not Found
```bash
# Verify extension loaded
php -m | grep oci8

# Check php.ini location
php --ini
```

### Permission Issues
```sql
-- Grant additional privileges if needed
GRANT ALL PRIVILEGES TO pfms_user;
```

## Auto-Sync Behavior

- **Online Mode**: Syncs every 15 minutes automatically
- **Offline Mode**: Manual sync only
- **Conflict Resolution**: Last write wins
- **Failed Transactions**: Marked as 'CONFLICT' in local DB

## Monitoring

View sync logs in SQL Developer:
```sql
SELECT 
    sync_id,
    sync_type,
    records_synced,
    sync_status,
    sync_started_at,
    sync_completed_at,
    ROUND((sync_completed_at - sync_started_at) * 24 * 60 * 60, 2) as duration_seconds
FROM SYNC_LOG
ORDER BY sync_started_at DESC;
```
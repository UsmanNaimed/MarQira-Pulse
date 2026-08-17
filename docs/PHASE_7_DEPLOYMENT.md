# Phase 7 Deployment Guide — Private Plugin Update Server

**Version:** 1.0.0  
**Date:** August 17, 2026  
**Feature:** Automatic WordPress plugin updates from private update server

---

## Overview

Phase 7 implements a private plugin update server that enables WordPress sites with the MarQira Connector to automatically check for and install plugin updates, just like WordPress.org plugins — but from your own private server.

### What's New

1. **Update Server API** (`api.marqira.com/api/v1/plugin/*`)
   - `/update-check` — WordPress sites query this to see if updates are available
   - `/info` — Plugin information for "View Details" link in WP admin
   - `/download` — Downloads the latest plugin .zip file

2. **WordPress Plugin Updater** (new `class-marqira-updater.php`)
   - Hooks into WordPress update mechanism
   - Checks update server every 12 hours
   - Shows updates in WP admin like any other plugin

3. **Release Management API** (Owner-only dashboard endpoints)
   - Create new plugin releases
   - Activate/deactivate versions
   - Delete old releases

4. **Database Schema**
   - New `plugin_releases` table tracks all versions

---

## Prerequisites

- Phase 6 already deployed and working
- Access to Coolify dashboard
- GitHub repository write access (for commits)
- Access to store plugin .zip files (S3, Cloudflare R2, CDN, etc.)

---

## Deployment Steps

### Step 1: Deploy Updated API

#### 1.1 Verify Git Commit

The Phase 7 code should already be committed and pushed. Verify on GitHub:

```
https://github.com/UsmanNaimed/MarQira-Pulse/commits/main
```

Look for commits containing:
- `plugin_releases` migration
- `PluginRelease` model
- `PluginUpdateController`
- `PluginReleaseController`

#### 1.2 Deploy in Coolify

1. **Go to Coolify** → **marqira-api** project
2. Click **Deploy** button
3. Wait for build to complete (~2-3 minutes)
4. Check deployment logs for errors

#### 1.3 Run Migration

**Via Coolify Terminal:**

1. Go to **marqira-api** → **Terminal** tab
2. Run:
   ```bash
   php artisan migrate
   ```
3. Verify output shows:
   ```
   2024_01_01_000011_create_plugin_releases_table ........... DONE
   ```

**Expected table structure:**
- `id`, `version`, `changelog`, `download_url`
- `file_hash`, `file_size`, `requires_wp`, `requires_php`, `tested_up_to`
- `is_active`, `released_at`, `released_by`

---

### Step 2: Deploy Updated WordPress Plugin

The connector now includes auto-update functionality. Deploy the new version:

#### 2.1 Build New Plugin Package

On your local machine:

```bash
cd /path/to/MarQira-Pulse/wordpress/marqira-connector

# Create a clean zip (exclude dev files)
zip -r marqira-connector-1.2.0.zip . \
  -x "*.git*" "tests/*" "*.md" "*.txt" "composer.*"

# Verify zip contains the new updater class
unzip -l marqira-connector-1.2.0.zip | grep class-marqira-updater.php
```

Expected output:
```
includes/class-marqira-updater.php
```

#### 2.2 Upload Plugin Zip to Storage

Upload `marqira-connector-1.2.0.zip` to your file storage:

**Option A: Cloudflare R2 / S3**
```bash
# Example using AWS CLI (adjust for your storage)
aws s3 cp marqira-connector-1.2.0.zip \
  s3://your-bucket/marqira-connector/releases/marqira-connector-1.2.0.zip \
  --acl public-read
```

**Option B: CDN / Static Host**
- Upload via your hosting provider's dashboard
- Make sure the URL is publicly accessible

**Copy the final download URL**, e.g.:
```
https://downloads.marqira.com/marqira-connector-1.2.0.zip
```

#### 2.3 Calculate File Hash (Optional but Recommended)

```bash
sha256sum marqira-connector-1.2.0.zip
```

Copy the hash for the next step.

---

### Step 3: Create First Plugin Release

#### 3.1 Via Laravel Tinker (Recommended)

In **Coolify** → **marqira-api** → **Terminal**:

```bash
php artisan tinker
```

Then run:

```php
use App\Models\PluginRelease;
use App\Models\User;

// Get the admin user (adjust email if needed)
$admin = User::where('email', 'your-admin@email.com')->first();

// Create the release
$release = PluginRelease::create([
    'version' => '1.2.0',
    'changelog' => "## Version 1.2.0\n\n- Added automatic plugin updates\n- Phase 6: Origin IP detection\n- Bug fixes and improvements",
    'download_url' => 'https://downloads.marqira.com/marqira-connector-1.2.0.zip',
    'file_hash' => 'YOUR_SHA256_HASH_HERE', // from step 2.3
    'file_size' => 1234567, // file size in bytes
    'requires_wp' => '5.6',
    'requires_php' => '7.4',
    'tested_up_to' => '6.4',
    'is_active' => true,
    'released_at' => now(),
    'released_by' => $admin->id,
]);

echo "Release {$release->version} created successfully!\n";
exit;
```

#### 3.2 Via API (Alternative)

Using curl or Postman:

```bash
curl -X POST https://api.marqira.com/api/dashboard/plugin-releases \
  -H "Content-Type: application/json" \
  -H "Cookie: YOUR_SESSION_COOKIE" \
  -d '{
    "version": "1.2.0",
    "changelog": "## Version 1.2.0\n\n- Added automatic plugin updates",
    "download_url": "https://downloads.marqira.com/marqira-connector-1.2.0.zip",
    "file_hash": "YOUR_SHA256_HASH_HERE",
    "file_size": 1234567,
    "requires_wp": "5.6",
    "requires_php": "7.4",
    "tested_up_to": "6.4",
    "is_active": true
  }'
```

---

### Step 4: Verify Update Server

#### 4.1 Test Update Check Endpoint

```bash
curl "https://api.marqira.com/api/v1/plugin/update-check?version=1.0.0"
```

**Expected response:**
```json
{
  "update_available": true,
  "version": "1.2.0",
  "download_url": "https://downloads.marqira.com/marqira-connector-1.2.0.zip",
  "changelog": "## Version 1.2.0...",
  "requires_wp": "5.6",
  "requires_php": "7.4",
  "tested_up_to": "6.4",
  "file_size": 1234567,
  "file_hash": "...",
  "released_at": "2026-08-17T..."
}
```

#### 4.2 Test Plugin Info Endpoint

```bash
curl "https://api.marqira.com/api/v1/plugin/info"
```

**Expected response:**
```json
{
  "name": "MarQira Connector",
  "slug": "marqira-connector",
  "version": "1.2.0",
  "author": "MarQira",
  "homepage": "https://marqira.com",
  "download_link": "https://downloads.marqira.com/marqira-connector-1.2.0.zip",
  ...
}
```

#### 4.3 Test Download Redirect

```bash
curl -I "https://api.marqira.com/api/v1/plugin/download"
```

**Expected response:**
```
HTTP/2 302
location: https://downloads.marqira.com/marqira-connector-1.2.0.zip
```

---

### Step 5: Test on a WordPress Site

#### 5.1 Install Current Plugin Version

On a test WordPress site with MarQira Connector **1.0.0** or **1.1.0** installed:

1. Go to **WP Admin** → **Plugins**
2. You should see an **Update Available** notice for MarQira Connector

#### 5.2 Check Update Details

Click **"View version 1.2.0 details"** link.

**Expected:**
- Opens a modal with plugin information
- Shows changelog
- Shows compatibility (WP 5.6+, PHP 7.4+)

#### 5.3 Perform the Update

1. Click **"Update Now"**
2. WordPress downloads from your update server
3. Installs the new version
4. Go to **MarQira** → **Settings**
5. Verify "Plugin Version" shows **1.2.0**
6. Check **Recent Activity** — heartbeats should still work

---

## Managing Plugin Releases

### Create a New Release

**Via Tinker:**

```php
php artisan tinker

use App\Models\PluginRelease;
use App\Models\User;

$admin = User::first();

$release = PluginRelease::create([
    'version' => '1.3.0',
    'changelog' => "## Version 1.3.0\n\n- New features\n- Performance improvements",
    'download_url' => 'https://downloads.marqira.com/marqira-connector-1.3.0.zip',
    'file_hash' => hash_file('sha256', '/path/to/plugin.zip'),
    'file_size' => filesize('/path/to/plugin.zip'),
    'requires_wp' => '6.0',
    'requires_php' => '7.4',
    'tested_up_to' => '6.4',
    'is_active' => false, // Don't activate yet
    'released_at' => now(),
    'released_by' => $admin->id,
]);
```

### Activate a Release

Make a version the "current" one:

```php
php artisan tinker

use App\Models\PluginRelease;

$release = PluginRelease::where('version', '1.3.0')->first();
$release->activate(); // Deactivates all others automatically

echo "Version {$release->version} is now active!\n";
```

**Or via API:**

```bash
curl -X POST https://api.marqira.com/api/dashboard/plugin-releases/{id}/activate \
  -H "Cookie: YOUR_SESSION_COOKIE"
```

### List All Releases

```php
php artisan tinker

use App\Models\PluginRelease;

PluginRelease::orderBy('released_at', 'desc')->get(['id', 'version', 'is_active', 'released_at']);
```

**Or via API:**

```bash
curl https://api.marqira.com/api/dashboard/plugin-releases \
  -H "Cookie: YOUR_SESSION_COOKIE"
```

### Delete an Old Release

**Cannot delete the active release.** Activate a different version first.

```php
php artisan tinker

use App\Models\PluginRelease;

$old = PluginRelease::where('version', '1.0.0')->first();
if (!$old->is_active) {
    $old->delete();
    echo "Deleted version {$old->version}\n";
}
```

---

## Troubleshooting

### WordPress Not Seeing Updates

**Check:**

1. **Update server reachable?**
   ```bash
   # On the WordPress server
   curl "https://api.marqira.com/api/v1/plugin/update-check?version=1.0.0"
   ```

2. **Updater class loaded?**
   - Check MarQira Recent Activity for errors
   - In WP, run: `wp eval "var_dump(class_exists('Marqira_Updater'));"`
   - Should return `bool(true)`

3. **Clear WordPress update cache:**
   ```bash
   wp transient delete marqira_update_check
   wp transient delete marqira_update_check_info
   ```

4. **Force update check:**
   - WP Admin → Dashboard → Updates → Click "Check Again"

### Download Fails

**"Package could not be installed"**

1. **Verify download URL is public:**
   ```bash
   curl -I https://downloads.marqira.com/marqira-connector-1.2.0.zip
   ```
   Should return `HTTP 200`, not `403` or `404`.

2. **Check file integrity:**
   - Download the .zip manually
   - Unzip and verify `marqira-connector.php` exists
   - Check file size matches `file_size` in database

3. **WordPress permissions:**
   - WP needs write access to `/wp-content/plugins/`
   - Check server error logs: `tail -f /var/log/apache2/error.log`

### No Active Release Error

**"No active release available"**

```bash
php artisan tinker

use App\Models\PluginRelease;

// Check if any release is active
PluginRelease::where('is_active', true)->count(); // Should be 1

// If 0, activate one:
$release = PluginRelease::first();
$release->activate();
```

---

## Rollback Procedure

If an update causes issues:

### 1. Deactivate the Bad Release

```php
php artisan tinker

use App\Models\PluginRelease;

$bad = PluginRelease::where('version', '1.3.0')->first();
$bad->update(['is_active' => false]);

$good = PluginRelease::where('version', '1.2.0')->first();
$good->activate();
```

### 2. Downgrade WordPress Sites

On each affected WordPress site:

1. **Via WP-CLI (fastest):**
   ```bash
   wp plugin install https://downloads.marqira.com/marqira-connector-1.2.0.zip \
     --activate --force
   ```

2. **Via WP Admin:**
   - Deactivate MarQira Connector
   - Delete the plugin
   - Re-upload the old .zip
   - Activate

3. **Verify:**
   - Check **MarQira** → **Settings** for correct version
   - Check **Recent Activity** for successful heartbeats

---

## Security Notes

1. **Plugin .zip files should be publicly downloadable** (WordPress needs to fetch them)
2. **But keep the download URL unpredictable** (use long random paths or signed URLs)
3. **File hash (`file_hash`) is optional** but recommended for integrity verification
4. **Only platform Owners can manage releases** (enforced by `owner` middleware)
5. **All release changes are logged** in the audit log

---

## Next Steps

After Phase 7 is deployed:

1. **Build the dashboard UI** for managing releases (currently API/Tinker only)
2. **Set up automated builds** (GitHub Actions to build .zip on every release tag)
3. **Add update notifications** (email admins when new version is available)
4. **Implement beta/staged rollouts** (release to 10% of sites first, then 100%)

---

## Summary

✅ **API Update Server:** Public endpoints for WordPress to check/download updates  
✅ **WordPress Updater:** Plugin now auto-checks for updates every 12 hours  
✅ **Release Management:** Owner can create/activate/delete versions via API  
✅ **Database Schema:** `plugin_releases` table tracks all versions  
✅ **Tests:** 15 new tests (all passing)  

**Migration:** `2024_01_01_000011_create_plugin_releases_table`  
**Endpoints:**
- `GET /api/v1/plugin/update-check?version=X.Y.Z`
- `GET /api/v1/plugin/info`
- `GET /api/v1/plugin/download`
- `GET /api/dashboard/plugin-releases` (owner)
- `POST /api/dashboard/plugin-releases` (owner)
- `POST /api/dashboard/plugin-releases/{id}/activate` (owner)
- `DELETE /api/dashboard/plugin-releases/{id}` (owner)

---

**End of Phase 7 Deployment Guide**

# Phase 6 — Origin Detection + Verification

## Deployment Guide for Coolify

This guide walks you through deploying Phase 6 (Origin Detection + Verification) to your Coolify instance.

---

## What's New in Phase 6

1. **Automatic Origin IP Detection**
   - DNS analysis (A and AAAA records)
   - Cloudflare proxy detection
   - Confidence scoring (high/medium/low/unknown)
   - Server IP classification (public/private/reserved/cloudflare)

2. **Origin IP History**
   - Tracks all origin changes
   - Records detection events
   - Logs manual verifications

3. **Manual Verification UI**
   - Dashboard interface for verifying origin IPs
   - Add notes to verifications
   - Audit log integration

4. **API Endpoints**
   - `GET /api/dashboard/sites/{uuid}/origin/history` — View origin change history
   - `POST /api/dashboard/sites/{uuid}/origin/verify` — Manually verify an origin IP
   - `PATCH /api/dashboard/sites/{uuid}/origin/confidence` — Update confidence level

---

## ⚠️ Important: GitHub Rate Limit Fix

**If your deployment fails with HTTP 429 errors during `composer install`**, see [GITHUB_RATE_LIMIT_FIX.md](./GITHUB_RATE_LIMIT_FIX.md) for the permanent solution.

**Quick fix**: Wait 30-60 minutes and retry. The rate limit resets hourly.

---

## Prerequisites

- Phase 5 successfully deployed and running
- Access to Coolify web interface at `https://vps.marqira.com`
- SSH access to VPS at `187.77.136.105` (optional, for troubleshooting)
- GitHub repo at `https://github.com/UsmanNaimed/MarQira-Pulse` with Phase 6 code pushed to `main`

---

## Step 1: Verify GitHub Repository

### 1.1 Check that Phase 6 code is on GitHub

1. Open your web browser
2. Navigate to: `https://github.com/UsmanNaimed/MarQira-Pulse`
3. Click on the **"Code"** tab
4. Verify you see the latest commit message mentioning "Phase 6" or "Origin Detection"
5. Click on `apps/api/app/Services/` and verify you see `OriginDetector.php`

**What you should see:** The file `OriginDetector.php` listed in the Services directory.

---

## Step 2: Deploy API Changes

### 2.1 Trigger API rebuild in Coolify

1. Open Coolify at `https://vps.marqira.com`
2. Log in with your credentials
3. Navigate to **Projects** → **MarQira Pulse**
4. Click on the **"marqira-api"** resource (or whatever you named your Laravel API service)
5. Look for the **"Deploy"** or **"Redeploy"** button (usually in the top right)
6. Click **"Deploy"**

**What happens:** Coolify will:
- Pull the latest code from your GitHub `main` branch
- Rebuild the Docker image
- Deploy the new container

**This will take 2-5 minutes.** You'll see build logs streaming in real-time.

### 2.2 Run database migration

After the API container is running, you need to run the new migration for `origin_ip_history` table.

**Option A: Via Coolify UI (if your Coolify supports shell access)**

1. In the marqira-api service page, look for **"Execute Command"** or **"Shell"** button
2. Click it to open a terminal into the running container
3. Run:
   ```bash
   php artisan migrate --force
   ```
4. You should see:
   ```
   Migrating: 2024_01_01_000010_create_origin_ip_history_table
   Migrated:  2024_01_01_000010_create_origin_ip_history_table
   ```

**Option B: Via SSH**

1. SSH into your VPS:
   ```bash
   ssh root@187.77.136.105
   ```
2. Find the API container name:
   ```bash
   docker ps | grep marqira-api
   ```
   Look for a container name like `marqira-api-1` or `marqira_api_1`.

3. Run the migration inside the container:
   ```bash
   docker exec -it <container_name> php artisan migrate --force
   ```
   Replace `<container_name>` with the actual name from step 2.

**What you should see:** Migration success message with the new table created.

### 2.3 Verify API health

1. In your browser, navigate to: `https://api.marqira.com/api/health`
2. You should see JSON like:
   ```json
   {
     "status": "ok",
     "service": "MarQira Pulse API",
     "timestamp": "2026-08-17T..."
   }
   ```

**If you see this:** API is running successfully.

**If you see an error page:** Check the build logs in Coolify for errors.

---

## Step 3: Deploy Dashboard Changes

### 3.1 Trigger Dashboard rebuild

1. In Coolify, go back to **Projects** → **MarQira Pulse**
2. Click on the **"marqira-dashboard"** resource
3. Click **"Deploy"** or **"Redeploy"**

**What happens:** Coolify will:
- Pull the latest React/TypeScript code
- Run `npm install` and `npm run build`
- Deploy the new static build

**This will take 1-3 minutes.**

### 3.2 Verify Dashboard

1. Open `https://app.marqira.com` in your browser
2. Log in with your account
3. Navigate to **Websites**
4. Click on `olympianwatertestingschools.com` (or any enrolled site)
5. Click the **"Network"** tab

**What you should see:**
- **Server Information** section (Server IP, hostname, software)
- **Origin IP Detection** section with:
  - Origin IP address
  - Detection source
  - Confidence badge (high/medium/low/unknown) with color coding
  - Verified status
- **Manually Verify Origin IP** form (if not already verified)

**If the verification form appears:** You can test it by:
1. Entering the origin IP (or confirming the detected one)
2. Adding optional notes
3. Clicking **"Verify Origin IP"**
4. Page should reload and show the origin as verified

---

## Step 4: Test Origin Detection

### 4.1 Wait for next heartbeat

The origin detection runs automatically on every heartbeat from the WordPress plugin. The plugin sends heartbeats every ~10 minutes.

**To verify origin detection is working:**

1. In the dashboard, go to **Websites** → Click on a site
2. Go to the **"Network"** tab
3. Check the **"Origin IP"** field — it should be populated (if `null`, wait for next heartbeat)
4. Check the **"Detection source"** — e.g., `dns_a_match`, `server_addr`, etc.
5. Check the **"Confidence"** badge — should be `high`, `medium`, `low`, or `unknown`

### 4.2 Test manual verification

1. On the **Network** tab, scroll to **"Manually Verify Origin IP"**
2. If the detected origin is correct, you can verify it by:
   - Entering the IP (or leaving it as pre-filled)
   - Adding a note like "Confirmed via hosting panel"
   - Clicking **"Verify Origin IP"**
3. The page will reload
4. The **"Verified"** pill should now show a green checkmark
5. The verification form should disappear (only unverified origins show the form)

### 4.3 Check origin history

Origin changes are logged in the `origin_ip_history` table. To view them via API:

1. Get the site UUID from the URL: `https://app.marqira.com/websites/<uuid>`
2. In a new tab, visit:
   ```
   https://api.marqira.com/api/dashboard/sites/<uuid>/origin/history
   ```
   (You may need to be logged into the dashboard for the session cookie to authenticate)

**What you should see:** JSON array of history entries showing detected, verified, and confidence_changed events.

---

## Step 5: Verify Database Tables

### 5.1 Check origin_ip_history table

Connect to PostgreSQL and verify the new table exists:

**Via SSH:**
```bash
ssh root@187.77.136.105
docker ps | grep postgres
docker exec -it <postgres_container> psql -U marqira_user -d marqira_db
```

**In psql:**
```sql
\dt origin_ip_history
SELECT * FROM origin_ip_history LIMIT 5;
```

**What you should see:**
- Table `origin_ip_history` exists
- Rows appear after sites send heartbeats (may be empty if no heartbeats yet)

**To exit psql:**
```
\q
```

---

## Step 6: Troubleshooting

### Problem: Migration fails with "table already exists"

**Solution:** The migration may have run before. Check:
```sql
SELECT * FROM migrations WHERE migration LIKE '%origin_ip_history%';
```

If it's listed, the migration already ran. If not, run:
```bash
php artisan migrate:refresh --step=1
```

### Problem: Origin IP stays null

**Possible causes:**
1. WordPress site hasn't sent a heartbeat yet (wait ~10 minutes)
2. DNS lookup failing for the domain
3. SERVER_ADDR not available from WordPress

**Check:**
- Go to **Connection History** tab and verify recent heartbeats
- Check the heartbeat payload for `server_ip` value
- Review Laravel logs for DNS errors:
  ```bash
  docker exec -it <api_container> tail -n 50 storage/logs/laravel.log
  ```

### Problem: Dashboard shows old UI without verification form

**Solutions:**
1. **Hard refresh** the browser: `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac)
2. Clear browser cache and reload
3. Check Coolify build logs for dashboard deployment errors
4. Verify `WebsiteDetail.tsx` was updated in GitHub

### Problem: "Verification failed" error when clicking Verify

**Check:**
1. Browser console for JavaScript errors (F12 → Console tab)
2. Network tab for API response (F12 → Network → filter by "verify")
3. Laravel logs for validation errors:
   ```bash
   docker logs <api_container_name> --tail=100
   ```

---

## Step 7: Rollback (If Needed)

If Phase 6 breaks something critically:

### 7.1 Revert GitHub repo

```bash
cd /path/to/MarQira-Pulse  # on your local machine
git log --oneline
git revert <phase_6_commit_hash>
git push
```

### 7.2 Redeploy in Coolify

Follow Step 2.1 and Step 3.1 again — Coolify will pull the reverted code.

### 7.3 Rollback migration

If you need to undo the `origin_ip_history` table:

```bash
docker exec -it <api_container> php artisan migrate:rollback --step=1
```

---

## Step 8: Post-Deployment Checklist

- [ ] API health check returns 200 OK
- [ ] Dashboard loads without errors
- [ ] Migration `origin_ip_history` ran successfully
- [ ] Network tab shows Origin IP Detection section
- [ ] Manual verification form appears for unverified origins
- [ ] Confidence badge displays with correct color
- [ ] Origin history API endpoint returns data
- [ ] Audit log shows `site.origin_verified` events after manual verification
- [ ] No errors in Laravel logs
- [ ] No errors in browser console

---

## Next Steps

- **Phase 7:** Private plugin updates at `updates.marqira.com`
- **Phase 8:** n8n API endpoints
- **Phase 9:** Origin bypass proxy
- **Phase 10:** Commercial SaaS (subscriptions, plans, billing)

---

## Support

If you encounter issues not covered in this guide:

1. Check Laravel logs: `docker logs <api_container> --tail=200`
2. Check PostgreSQL for data: `SELECT * FROM origin_ip_history LIMIT 10;`
3. Review Coolify build logs for deployment errors
4. Verify GitHub has the latest code: `https://github.com/UsmanNaimed/MarQira-Pulse/commits/main`

---

**Deployed:** [Date you deployed Phase 6]  
**API:** `https://api.marqira.com`  
**Dashboard:** `https://app.marqira.com`  
**VPS:** `187.77.136.105` (`vps.marqira.com`)

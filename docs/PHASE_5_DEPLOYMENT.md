# Phase 5 — Dashboard Deployment (app.marqira.com)

## Goal

Deploy the MarQira Pulse **management dashboard** (a React single-page app) to
your VPS using Coolify, and wire it to the existing API so you can:

- Sign in securely with your admin account
- See an **Overview** of all your connected websites
- Browse the **Websites** table and open each site's detail tabs
- Generate a **connection code** to enrol a new website
- Create and revoke **API tokens**
- Update your **profile / password** in **Settings**

The dashboard runs at `https://app.marqira.com` and talks to the API at
`https://api.marqira.com` (deployed in Phase 3).

**Important:** This guide assumes you are new to Coolify. Every step tells you
exactly what to click, what to type, and what you should see afterward.

---

## Prerequisites

Before starting:

- Phase 3 is complete — the API is live at `https://api.marqira.com`
- Coolify is installed and running on your VPS (187.77.136.105)
- You can access the Coolify web UI
- Your GitHub repo `UsmanNaimed/MarQira-Pulse` is connected to Coolify
- DNS: create an **A record** for `app.marqira.com` pointing to `187.77.136.105`
  (do this first — DNS can take a few minutes to propagate)

---

## Part 1: Prepare the API for the dashboard

The dashboard logs in using **cookie-based sessions** (Laravel Sanctum "SPA"
mode). For the browser to accept those cookies across `app.` and `api.`
sub-domains, the API needs a few environment variables. You set these on the
**existing API application** in Coolify (the one from Phase 3).

### Step 1.1: Open the API application
1. In Coolify, open your **MarQira** project.
2. Click the **API application** (the Laravel app from Phase 3, e.g. `marqira-api`).
3. Click the **"Environment Variables"** tab.

### Step 1.2: Add / confirm these variables
Add each of the following (if one already exists, update its value):

| Variable | Value |
|----------|-------|
| `SESSION_DOMAIN` | `.marqira.com` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | `app.marqira.com` |
| `CORS_ALLOWED_ORIGINS` | `https://app.marqira.com` |
| `MARQIRA_PLUGIN_LATEST_VERSION` | *(leave blank for now — set in Phase 7)* |

**Note the leading dot** in `SESSION_DOMAIN` (`.marqira.com`) — it lets the
cookie be shared between `app.` and `api.` sub-domains. This is required.

### Step 1.3: Redeploy the API
1. Click **"Save"**.
2. Click **"Redeploy"** (or **"Deploy"**) so the new variables take effect.
3. **What you should see:** the deployment finishes with a green/healthy status.

---

## Part 2: Create the Dashboard application in Coolify

### Step 2.1: Add a new resource
1. Open your **MarQira** project.
2. Click **"+ New Resource"** → **"Application"**.
3. Choose **"Public Repository"** or your connected **GitHub** source, and select
   the repo `UsmanNaimed/MarQira-Pulse`.
4. **Branch:** `main`.

### Step 2.2: Set the build type and base directory
1. **Build Pack:** choose **"Dockerfile"**.
2. **Base Directory:** type `/apps/dashboard`
   (this is the folder that contains the dashboard's `Dockerfile`).
3. **Dockerfile Location:** `Dockerfile` (default — it lives in that base directory).

### Step 2.3: Set the domain
1. Find the **"Domains"** (or **"FQDN"**) field.
2. Type `https://app.marqira.com`.
3. Coolify will automatically request an HTTPS certificate for it.

### Step 2.4: Set the exposed port
1. Find the **"Port"** setting.
2. Set it to **`80`** (the Nginx server inside the container listens on port 80).

---

## Part 3: Configure the build variable

The dashboard needs to know the API URL **at build time** (Vite bakes it into
the static files).

### Step 3.1: Add the build variable
1. Open the **"Environment Variables"** tab of the dashboard application.
2. Add a variable:
   - **Name:** `VITE_API_BASE_URL`
   - **Value:** `https://api.marqira.com`
3. **Important:** mark it as a **"Build Variable"** / **"Available at build time"**
   (in Coolify there is a checkbox for this). Vite only reads `VITE_*` values
   during `npm run build`, so it must be present at build time, not just runtime.

---

## Part 4: Deploy

### Step 4.1: Deploy
1. Click **"Deploy"**.
2. Watch the build logs. You should see, in order:
   - `npm ci` installing packages
   - `npm run build` producing a `dist/` folder ("build" then "✓ built in …")
   - the Nginx image being assembled
   - deployment marked **healthy**

### Step 4.2: First load
1. Open `https://app.marqira.com` in your browser.
2. **What you should see:** the MarQira Pulse **sign-in** page.

---

## Part 5: Testing (Definition of Done)

Do these checks in the browser after deployment:

1. **Login**
   - Go to `https://app.marqira.com`.
   - Sign in with your admin email + password (created in Phase 3 with
     `php artisan marqira:create-admin`).
   - You should land on the **Overview** page. ✅
2. **Overview cards** show: Total, Online, Offline, Needs Attention, Updates
   Available. ✅
3. **Websites** page lists your sites with Domain, Status, Origin IP, Origin
   Verified, Server IP, WP, PHP, Connector, Last Seen. Search / filter / sort /
   pagination all work. ✅
4. **Website detail** — click a site; the Overview, Network, WordPress,
   Connection History, Plugin Status, Updates, and Activity tabs all load. ✅
5. **Connect a website** — click the button, a connection code (enrollment
   token) is generated and shown. ✅
6. **API Tokens** — create a token (it is shown **once**), then revoke it. ✅
7. **Settings** — update your name and change your password. ✅
8. **Logout** — sign out; you are returned to the sign-in page and protected
   pages are no longer reachable. ✅
9. **Security** — refresh the page while logged in; your session persists via
   the secure cookie. Passwords are never shown or logged.

If all of the above pass, Phase 5 is complete.

---

## Troubleshooting

- **Login returns 419 / "CSRF token mismatch"**
  → `SESSION_DOMAIN` must be `.marqira.com` (leading dot) and
  `SANCTUM_STATEFUL_DOMAINS` must include `app.marqira.com`. Redeploy the API
  after changing these.
- **Login returns "CORS" error in the browser console**
  → `CORS_ALLOWED_ORIGINS` on the API must be exactly `https://app.marqira.com`
  (no trailing slash). Redeploy the API.
- **The dashboard loads but every API call fails / points at the wrong host**
  → `VITE_API_BASE_URL` was not set as a **build** variable, or was changed
  after the build. Set it and **redeploy** the dashboard (rebuild required).
- **Refreshing a deep link (e.g. `/websites/…`) shows a 404**
  → This should not happen — the bundled `nginx.conf` has an SPA history
  fallback. If it does, confirm the dashboard is using the Dockerfile build pack
  (not a static-site pack that skips the custom Nginx config).
- **Cookies not sticking / logged out on refresh**
  → Ensure the site is served over **HTTPS** and `SESSION_SECURE_COOKIE=true`.
  Secure cookies are dropped on plain HTTP.

---

## What was built in Phase 5

- **Dashboard SPA** (`apps/dashboard/`): React 18 + TypeScript + Vite + Tailwind,
  React Router, TanStack Query, Axios. Pages: Login, Overview, Websites, Website
  Detail (7 tabs), API Tokens, Settings, and a Plugin Releases placeholder
  (built out in Phase 7).
- **Dashboard API** (`apps/api/`): Sanctum SPA cookie auth (`/login`, `/logout`,
  `/user`), plus tenant-scoped `/api/dashboard/*` endpoints for overview, sites,
  site detail, heartbeats, enrollment tokens, API tokens, audit logs and
  settings. Rate-limited login, CSRF protection, secure session cookies.
- **Deploy files**: `apps/dashboard/Dockerfile` (Node build → Nginx serve) and
  `apps/dashboard/nginx.conf` (SPA fallback + security headers).

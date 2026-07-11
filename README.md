# Travel Assist

An AI-powered advisor for two journeys: **studying abroad** and **general travel/immigration**. It turns visa rules and admissions paperwork into a personalized, phased checklist — with a chat interface backed by the Anthropic API.

Zero-bloat by design: no Composer, no Node/npm, no build step. Plain PHP + SQLite on the backend, static HTML/vanilla JS/Bootstrap on the frontend.

## Features

- **Landing page** — explains the platform, with separate entry points for students vs. travelers
- **Advisor chat** (`/chat.html`) — phased guidance (Academic Selection → Admissions → Visa Docs → Pre-Departure for students; Visa Matrix → Document Stack → Arrival Compliance for travelers), session persistence via JWT cookies
- **Admin dashboard** (`/admin`) — API key and model are managed here, in the database, never in code or `.env`
- **Legal disclaimer** — surfaced on the landing page, in the chat UI, and baked into the AI's own system prompt: informational guidance only, no guaranteed outcomes

## Requirements

- PHP 8.1+ with `pdo_sqlite` and `openssl` extensions
- Python 3 (used only to orchestrate startup)

## Getting Started

```bash
python server.py
```

This will:
1. Verify PHP and the required extensions are available
2. Run idempotent database migrations (creates `database/app.sqlite`)
3. On first run, seed a random admin account and print the credentials **once** to the console — save them
4. Start the PHP dev server at `http://127.0.0.1:8010`

Then:
1. Visit `/admin/login.html`, sign in with the printed credentials, and change the password immediately
2. Go to **Settings** and add your Anthropic API key (get one at [console.anthropic.com](https://console.anthropic.com))
3. Visit `/` — the advisor chat is now live

## Project Structure

```
server.py                  Orchestrator: checks environment, runs migrations, starts PHP server
database/
  schema.sql                Table definitions (sessions, messages, admins, settings)
  migrate.php                Idempotent migration runner + first-run seeding
public/                    Web root (point your server/subdomain document root here)
  index.php                  Front controller for /api/v1/* routes
  router.php                  Dev-server router, used only by `php -S` (see Getting Started)
  .htaccess                   Apache/LiteSpeed rewrite rules, used only on real web servers (see Deployment)
  index.html                  Landing page
  chat.html                   Advisor chat UI
  admin/                       Admin dashboard (login, settings, dashboard)
  js/, css/                    Shared frontend assets
src/
  Router.php                  Minimal method+path router
  Controllers/                 ChatController, AdminAuthController, AdminSettingsController
  Support/                     Database (PDO singleton), Settings (DB-backed config), Jwt, AdminAuth
  Support/Prompts/              The advisor's system prompt
```

## Configuration

All runtime configuration (Anthropic API key, model, JWT signing secret) lives in the `settings` table in SQLite, managed through the admin UI — there is no `.env` file to edit. The JWT secret and a default admin account are generated automatically on first migration.

## Security Notes

- `database/app.sqlite` contains the admin password hash and API key — it's git-ignored and should never be committed
- Change the seeded admin password immediately after first login

## Deploying to a Subdomain on cPanel (Namecheap Shared Hosting)

`server.py` / `php -S` are for local development only — cPanel serves via Apache or LiteSpeed, which is why `public/.htaccess` exists (it does the same job `router.php` does locally: everything that isn't a real file gets routed to `index.php`).

**The most important rule:** the subdomain's document root must point at the `public/` folder specifically — never at the project root.

Ideally, clone the repo *outside* `public_html` entirely (e.g. your home directory) so `src/` and `database/` are never inside any web-served tree. In practice, cPanel's Git tool often defaults to cloning inside `public_html` (e.g. `public_html/travelassist`, with the subdomain root at `public_html/travelassist/public`) — that's fine too, **but only because `database/.htaccess` and `src/.htaccess` in this repo explicitly deny all web access to those folders.** Without them, anyone could download `database/app.sqlite` (which contains the admin password hash and your Anthropic API key) directly via your **primary domain**, e.g. `https://yourdomain.com/travelassist/database/app.sqlite` — since `public_html` is usually the primary domain's own document root, and a subfolder inside it is reachable through that domain too, regardless of what the subdomain's document root points to.

After deploying, verify this is actually blocked: visit `https://yourdomain.com/travelassist/database/app.sqlite` (adjust the path to match your setup) and confirm you get a 403, not a file download.

### 1. Create the subdomain

In cPanel → **Domains** → **Create A New Domain**, enter the subdomain (e.g. `travel.yourdomain.com`). Cpanel will offer a document root like `public_html/travel` — **change it** to `public_html/travel-assist-app/public` (or wherever you place the project, as long as it ends in `/public`).

### 2. Get the code onto the server

Preferred — cPanel's **Git™ Version Control** tool (Namecheap cPanel includes this):
1. cPanel → **Git Version Control** → **Create**
2. Repository URL: `https://github.com/CalebPrince/travel-assist-app.git`
3. Repository Path: ideally a directory *above* `public_html` (e.g. your home directory) — but cPanel's default suggestion of a path inside `public_html` (e.g. `public_html/travelassist`) is also fine, since `database/.htaccess` and `src/.htaccess` block direct web access to those folders either way
4. Set the subdomain's document root to `<repository path>/public`
5. Deploy the `main` branch

To ship updates later, `git push` to GitHub, then in cPanel go to the repo → **Manage** → **Pull or Deploy** → **Update from Remote** (or `git pull` via Terminal). It's live as soon as the pull finishes — no separate deploy step.

Fallback — no Git tool available: zip the repo locally, upload via **File Manager**, extract, and set the document root the same way.

### 3. Set the PHP version and extensions

cPanel → **MultiPHP Manager**: select the subdomain, set PHP to **8.1 or higher**.
cPanel → **Select PHP Extensions** (or **MultiPHP INI Editor**): ensure `pdo_sqlite`, `sqlite3`, and `openssl` are enabled. `curl` is used automatically if present, otherwise the app falls back to PHP streams — either works.

### 4. Run the migration once

This creates `database/app.sqlite`, generates the JWT secret, and seeds a one-time admin account.

- If cPanel gives you **Terminal** (Namecheap shared hosting usually does): `cd` into your repository path (e.g. `~/travelassist` or `~/public_html/travelassist`) and run `php database/migrate.php` — copy the printed admin username/password before closing.
- No Terminal: temporarily create `public/migrate-once.php` containing `<?php require __DIR__ . '/../database/migrate.php';`, visit `https://travel.yourdomain.com/migrate-once.php` once in a browser to see the output, then **delete that file immediately** — leaving a public migration endpoint live is a security hole.

### 5. Go live

1. cPanel → **SSL/TLS Status**, run **AutoSSL** for the subdomain (Namecheap issues a free cert), then enable **Force HTTPS Redirect**
2. Visit `https://travel.yourdomain.com/admin/login.html`, sign in with the credentials from step 4, and change the password immediately
3. In **Settings**, add your Anthropic API key
4. Visit `https://travel.yourdomain.com/` — the advisor is live

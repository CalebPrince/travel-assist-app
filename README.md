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
public/                    Web root
  index.php                  Front controller for /api/v1/* routes
  router.php                  Dev-server router (serves static files, else defers to index.php)
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

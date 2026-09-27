# C9 Intranet

A PHP intranet for the **Cinder 9** Arma 3 milsim community. Manages a roleplay economy with inventories, markets, player-to-player trading, and organizational tools.

**Live:** [cinder9.com](https://cinder9.com)

## Features

- **Inventories** — Each player has an inventory with items and a credit balance.
- **Vendor Market** — Buy items from a central shop. Items are tiered and unlock progressively. The market can be scheduled to close during operations.
- **Player Market** — Player-to-player trading with fixed-price listings and auctions.
- **Sell & Transfer** — Sell items back to the vendor, transfer items or funds between inventories.
- **Magazine Refilling** — Refill partial magazines from ammo boxes.
- **Roster / ORBAT** — Hierarchical team structure with player assignments, roles, and callsigns.
- **Player Profiles** — Profiles with notes, comments, image galleries, loadout display, and certifications.
- **Dashboard** — Mission planning with interactive SVG maps, ORBAT assignments, asset groups, resources, and a notebook.
- **Wiki** — Database-backed knowledge base with hierarchical pages, breadcrumbs, and an admin editor.
- **Forum** — phpBB 3.x integrated via iframe with seamless auto-login.
- **Messages** — Internal direct messaging between players.
- **Calendar** — Event calendar with designated editors.
- **News & Documents** — PDF/image/video uploads viewable in a sidebar+iframe viewer.
- **Financial Reports** — Retro fax-styled financial report viewer.
- **Transaction Logs** — Filterable history of all purchases, sales, and transfers.
- **Analytics** — Snapshot system and trend analysis subdomain.
- **Admin Panel** — User/inventory/market management, system parameters, map tile rendering, session monitoring.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x (no framework) |
| Database | MySQL / MariaDB (PDO) |
| Frontend | Vanilla HTML, CSS, JavaScript |
| Web Server | Apache with `.htaccess` |
| Forum | phpBB 3.x |
| Maps | Custom SVG tile renderer (`funkySvgViewer`) |

## Setup

### Prerequisites

- Apache with `mod_rewrite` and `mod_authz_core` enabled (e.g. XAMPP)
- PHP 8.x with `pdo_mysql`, `gd`, `mbstring` extensions
- MySQL or MariaDB
- FFmpeg (optional — for generating MP4 background thumbnails)

### Installation

1. **Clone** the repository into your web server document root:
   ```
   git clone <repo-url> htdocs/c9/public_html
   ```

2. **Create the database config file** `cinder9_db.php` in the project root:
   ```php
   <?php
   define('C9_DB_HOST', 'localhost');
   define('C9_DB_NAME', 'arma_inventories');
   define('C9_DB_USER', 'your_user');
   define('C9_DB_PASS', 'your_password');
   ```
   This file is blocked from web access by `.htaccess` and excluded from git by `.gitignore`.

3. **Import the database schema:**
   ```
   mysql -u your_user -p arma_inventories < .context/arma_inventories.sql
   ```

4. **Install phpBB** into `views/forum/`:
   - Download phpBB 3.x from [phpbb.com](https://www.phpbb.com/downloads/).
   - Extract into `views/forum/`.
   - Run the phpBB installer and point it at the same database (`arma_inventories`).
   - The bridge file `views/forum/phpbb_bridge.php` handles auto-login — users are created in phpBB automatically on first forum visit.

5. **Set up the cronjob** (recommended: every minute):
   ```
   * * * * * php /path/to/public_html/cronjob.php
   ```
   This handles: market scheduling (disable during Sunday ops), auction resolution, avatar syncing, blurhash generation, query log cleanup, and MP4 thumbnail generation.

6. **Disable maintenance mode** by commenting out the rewrite rules in `.htaccess` (lines 5–13), or whitelist your IP.

7. **Create the admin account** — The admin user logs in with `username = admin`. On first login, the system assigns `user_id = -1` and grants full access.

### Image Setup

Item images go in `images/` as `UPPERCASE_CLASSNAME.PNG` (e.g., `images/ARIFLE_MX_F.PNG`). The `images/` directory is not tracked by git due to its size.

## Project Structure

```
├── index.php                    Entry point → redirects to views/home.php
├── cinder9_db.php               Database credentials (not committed)
├── cronjob.php                  Scheduled tasks
├── .htaccess                    Access control & maintenance mode
│
├── views/                       User-facing pages
│   ├── home.php                 Desktop-style home with configurable shortcuts & backgrounds
│   ├── login.php / logout.php   Authentication
│   ├── inventory.php            Player inventory management
│   ├── shop.php                 Vendor market browser
│   ├── detailPage.php           Single item detail & purchase
│   ├── playerMarket.php         Player-to-player trading
│   ├── roster.php               Team roster
│   ├── profile.php              Player profiles
│   ├── dashboard.php            Mission planning dashboard
│   ├── messages.php             Direct messaging
│   ├── calendar.php             Event calendar
│   ├── docs.php                 Document viewer
│   ├── logs.php                 Transaction logs
│   ├── financials.php           Financial reports viewer
│   ├── forum.php                phpBB wrapper (iframe)
│   └── forum/                   phpBB installation directory
│
├── admin/                       Admin-only pages
│   ├── inventories.php          Inventory management
│   ├── users.php                User management
│   ├── addNewUser.php           User creation form
│   ├── parameters.php           System configuration
│   ├── markets.php              Market management
│   ├── maps.php                 Map management
│   ├── renderSvgTiles.php       SVG → PNG tile renderer
│   └── ...
│
├── db/                          Backend API endpoints (JSON)
│   ├── connection.php           Database connection
│   ├── auth_users/              Authentication endpoints
│   ├── economy/                 Fund transfers & financial reports
│   ├── inventory_assets/        Inventory CRUD, buy, sell, transfer
│   ├── market/                  Market & auction endpoints
│   ├── roster_orbat/            Team hierarchy endpoints
│   ├── profile_social/          Profile, comments, notes
│   ├── wiki/                    Wiki page endpoints
│   ├── forum_chat/              Forum & messaging endpoints
│   ├── news/                    News/document uploads
│   ├── misc/                    Settings, calendar, logs
│   └── ...
│
├── includes/                    Shared PHP includes
│   ├── auth.php                 Authentication & CSRF
│   ├── header.php               Global header & navigation
│   ├── footer.php               Footer
│   ├── error.php                Error overlay component
│   └── toast.php                Toast notifications
│
├── styles/
│   └── preview_styles.css       Main stylesheet
│
├── funkySvgViewer/              SVG map tile viewer (separate sub-project)
│
├── subdomains/
│   └── analytics/               Analytics subdomain
│
├── images/                      Static assets (not in git)
└── pdf/                         Uploaded documents (not in git)
```
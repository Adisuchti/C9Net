# Cinder9 Intranet — Project Overview

## What This Is

A web-based intranet for **Cinder 9 Contracting LTD**, an Arma 3 milsim/roleplay unit. It manages a roleplay economy (inventories, credits, buying/selling items), player profiles, organizational structure, a wiki, messaging, mission planning, and a phpBB forum.

**Live URL:** `https://cinder9.com`

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x (vanilla, no framework) |
| Database | MariaDB via PDO (+ legacy mysqli in `connection.php`) |
| Frontend | Vanilla HTML/CSS/JS (no build tools, no bundler) |
| Web Server | Apache (XAMPP for local dev) |
| Font | Google Fonts — Oxanium |
| Forum | phpBB 3.x (embedded via iframe, auto-login bridge) |
| Charting | Chart.js (analytics, financial reports) |
| Maps | Custom SVG tile renderer (`funkySvgViewer/`) |
| CSS | Single monolithic `styles/preview_styles.css` |

## User Roles

| Role | Identification | Capabilities |
|---|---|---|
| Admin | `$_SESSION['user_id'] === -1` | Full CRUD on all entities, market management, user management, system parameters, financial reports |
| Player | `user_id > 0` (authenticated) | Browse market, buy/sell items, manage own inventory, transfer items/funds, profiles, messages, forum, player market listings |
| Guest | Not logged in | Redirected to login page |

## Core Domain Concepts

### Economy

- Each player has an **inventory** with a **credit balance** (`Inventory_Money`).
- Players buy items from the **vendor market** (shop) — deducts credits, adds items.
- Players can **sell items** back to the market for credits.
- Players can **transfer items** between inventories and **transfer funds**.
- The market has **tiers** — items unlock progressively based on the `Current_Tier` condition variable.
- The market can be **disabled on schedule** (cronjob disables it during Sunday operations).
- Item quantities can be **limited** (`Available_Quantity`, -1 = unlimited).
- **Inventory type limits** restrict how many items of each category a particular inventory type can hold.

### Player Market

- Players can **list their inventory items** for sale to other players.
- Two listing types: **Fixed Price** (instant buy) and **Auction** (minimum bid + end date).
- Auctions are resolved by the cronjob (`resolvePlayerAuctions.php`).
- Players can cancel their own listings (auctions only if no bids yet).

### Items & Classification

- Items are identified by `Item_Class` (Arma 3 class name, e.g., `arifle_MX_F`).
- Categorized via: `item_types` → `custom_item_types` → display category (e.g., "Primary_Weapon", "Optic", "Magazine").
- Item images are stored as `UPPERCASE_CLASSNAME.PNG` in `/images/`.
- **Magazine refilling:** Refill partial magazines from ammo boxes in inventory.
- **Interchangeable items:** Items can be exchanged for compatible alternatives via the modification graph.

### Organization

- **Roster:** Hierarchical team tree via `team_hierarchy` table (parent-child).
- **Player Profiles:** Name, callsign, role, status, homeland, certifications, image gallery, notes, comments.
- **ORBAT:** Temporary per-event team assignments on the dashboard.
- **Asset Groups:** Vehicle/equipment grouping for operational planning.

### Communication

- **Messages:** Internal inbox system between player profiles.
- **Forum:** phpBB 3.x forum — users are auto-created on first visit, login is seamless via session bridge.
- **Notes:** Personal journal entries on player profiles.
- **Comments:** Public comments on player profiles and profile images.

### Mission Planning (Dashboard)

- SVG maps with typemap/heightmap overlays.
- Planning steps with timed map overlays for operational briefings.
- SVG tile pre-rendering for performance.
- ORBAT drag-and-drop team assignments.
- Asset group management.
- Per-dashboard notebook.
- Resource tracking.

### Other Features

- **Wiki:** Hierarchical page tree, slug-based URLs, admin-editable with toolbar.
- **Calendar:** Event management with designated editors.
- **News & Documents:** PDF/image/video uploads in a sidebar+iframe viewer.
- **Financial Reports:** Admin-created, player-viewable with retro fax overlay.
- **Transaction Logs:** Filterable history of all purchases, sales, and transfers.
- **Analytics Subdomain:** Database insights, trend analysis, market comparison tools.
- **Home Page:** Desktop-style UI with configurable shortcuts, backgrounds (images/MP4), and an inbox feed.
- **Active Sessions:** Admin view of online users with IP logging and session cleanup.

## Color Scheme

| Purpose | Color |
|---|---|
| Background | `#1a1a1a` |
| Accent text | `#FFB800` (orange) |
| Secondary text | `#2e8b57` (green) |
| Standard text | `#ffffff` |
| Success buttons | `#2e8b57` |
| Danger buttons | `#dc3545` |
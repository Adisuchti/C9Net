# Cinder9 Intranet — Architecture & Conventions

## Directory Structure

```
public_html/
├── index.php                    ← Entry point, redirects to views/home.php
├── .htaccess                    ← Directory indexing, maintenance mode, credential file protection
├── .gitignore                   ← Ignores credentials, images/, pdf/, SQL dumps, IDE files
├── cinder9_db.php               ← Database credentials (NOT committed, protected by .htaccess)
├── cronjob.php                  ← Scheduled tasks (market scheduling, auctions, blurhash, etc.)
├── saveAnalyticsSnapshot.php    ← Analytics snapshot script
│
├── views/                       ← User-facing pages
│   ├── home.php                 ← Desktop-style home (shortcuts, backgrounds, inbox)
│   ├── login.php                ← Login with remember-me support
│   ├── logout.php               ← Session teardown + cookie cleanup
│   ├── inventory.php            ← Player inventory management
│   ├── shop.php                 ← Vendor market browser with filters & sorting
│   ├── detailPage.php           ← Single market item detail + buy form
│   ├── playerMarket.php         ← Player-to-player trading (fixed price + auctions)
│   ├── roster.php               ← Hierarchical team roster
│   ├── profile.php              ← Player profiles (notes, comments, gallery, loadout)
│   ├── dashboard.php            ← Mission dashboard (map, ORBAT, assets, resources, notebook)
│   ├── messages.php             ← Internal messaging inbox
│   ├── calendar.php             ← Event calendar
│   ├── docs.php                 ← Document/news viewer
│   ├── logs.php                 ← Transaction log viewer
│   ├── webLog.php               ← Web activity log viewer
│   ├── financials.php           ← Financial reports viewer
│   ├── forum.php                ← phpBB forum wrapper (iframe + auto-login)
│   ├── settings.php             ← User settings
│   ├── forum/                   ← phpBB 3.x installation
│   │   ├── phpbb_bridge.php     ← Auth bridge (auto-creates phpBB users)
│   │   └── sync_avatars.php     ← Avatar sync script (called by cronjob)
│   └── mapDisplay/              ← Map display components
│
├── admin/                       ← Admin-only management pages
│   ├── inventories.php          ← Inventory management + money operations
│   ├── adminInventoryDetail.php ← Single inventory content editor
│   ├── users.php                ← User management
│   ├── addNewUser.php           ← User creation form
│   ├── parameters.php           ← System config (types, limits, condition variables, calendar editors)
│   ├── markets.php              ← Market entity management
│   ├── adminEditItem.php        ← Single market item editor
│   ├── shopList.php             ← Shop item list view
│   ├── interchangeableItems.php ← Item exchange rules
│   ├── maps.php                 ← Map file management
│   ├── renderSvgTiles.php       ← SVG → PNG tile pre-renderer
│   ├── financialReports.php     ← Financial report management
│   ├── editProfile.php          ← Admin profile editor
│   ├── editHierarchy.php        ← Team hierarchy editor
│   ├── manageProfiles.php       ← Profile management
│   ├── activeSessions.php       ← Active session tracking
│   ├── uploadSnapshot.php       ← SQL file upload → analytics snapshot
│   └── viewSnapshot.php         ← Analytics snapshot viewer
│
├── db/                          ← Backend API endpoints (JSON responses)
│   ├── connection.php           ← Database connection (loads cinder9_db.php, creates $pdo + $conn)
│   ├── auth_users/              ← User CRUD, password management
│   ├── economy/                 ← Fund transfers, financial reports
│   ├── inventory_assets/        ← Inventory CRUD, buy, sell, transfer, refill, modify
│   ├── market/                  ← Market CRUD, player listings, auctions, bids
│   ├── roster_orbat/            ← Team hierarchy, ORBAT assignments, donations
│   ├── profile_social/          ← Profiles, comments, notes, image uploads
│   ├── wiki/                    ← Wiki page CRUD, image uploads
│   ├── forum_chat/              ← Forum posts, threads, live chat, media uploads
│   ├── news/                    ← News/document upload & management
│   ├── notebook/                ← Dashboard notebook pages
│   ├── map/                     ← Map save/delete, SVG render management
│   ├── map_assets/              ← Map asset management
│   ├── market_assets/           ← Market asset management
│   ├── system_assets/           ← System parameter updates, image uploads
│   └── misc/                    ← Calendar events, settings, logs, backgrounds, header data
│
├── includes/                    ← Shared PHP includes
│   ├── auth.php                 ← Authentication, CSRF, session management, remember-me
│   ├── header.php               ← Global header (nav, session refresh, CSRF fetch wrapper, password change)
│   ├── footer.php               ← Footer
│   ├── error.php                ← Error overlay modal: showError(), showWarning()
│   ├── toast.php                ← Toast notifications: showToast()
│   ├── debug.php                ← Console debug logging
│   ├── imageHelper.php          ← Item image URL helper
│   └── blurhash/                ← PHP Blurhash library
│
├── styles/
│   └── preview_styles.css       ← Main stylesheet (monolithic, ~5000+ lines)
│
├── funkySvgViewer/              ← SVG map tile viewer (separate sub-project with its own git)
│
├── subdomains/
│   ├── analytics/               ← Analytics subdomain (trends, market comparison, DB insight)
│   └── preview/                 ← Preview subdomain
│
├── images/                      ← Static assets (NOT in git — several GB)
│   ├── *.PNG                    ← Item images (UPPERCASE_CLASSNAME.PNG)
│   ├── profiles/                ← Profile avatars
│   ├── profileUploads/          ← User-uploaded gallery images
│   ├── homeBackground/          ← Home page backgrounds (images + MP4)
│   ├── icons/                   ← UI icons (SVG)
│   └── ...
│
└── pdf/                         ← Uploaded documents & news (NOT in git)
    ├── docs/
    └── news/
```

## Credential Management

Database credentials are stored in `cinder9_db.php` in the project root:
- **Protected from web access** by `.htaccess` (`<Files "cinder9_db.php"> Require all denied`)
- **Excluded from git** by `.gitignore` (multiple patterns: `/cinder9_db.php`, `**/*credentials*.php`, `**/.env`)
- **Loaded by** `db/connection.php` via `require_once`

## Page Include Pattern

Every user-facing page follows this structure:

```php
<?php
require_once '../db/connection.php';   // 1. Database connection ($pdo, $conn)
require_once '../includes/auth.php';   // 2. Auth functions + CSRF token

if (!isLoggedIn()) {                   // 3. Auth check
    redirectToLogin();                 //    (preserves URL for post-login redirect)
}

// ... page-specific queries ...

include '../includes/header.php';      // 4. Header (nav, session refresh, CSRF wrapper)
?>

<!-- HTML content -->

<?php
include '../includes/error.php';       // 5. Error overlay (optional)
include '../includes/toast.php';       // 6. Toast notifications (optional)
include '../includes/footer.php';      // 7. Footer
?>

<script>
// Inline JavaScript — fetch() calls to db/ endpoints
</script>
```

## API Request/Response Pattern

### Frontend (Fetch)
```javascript
fetch("../db/category/endpoint.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ key: "value" })
})
.then(async response => {
    const json = await response.json();
    if (json.success) {
        showToast("Success!", "success");
        location.reload();
    } else {
        showError(json.error);
    }
})
.catch(err => showError("Fetch error: " + err.message));
```

CSRF tokens are automatically injected into all POST requests by a `fetch()` wrapper in `header.php`.

### Backend (Endpoint)
```php
<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();  // CSRF check for POST

$input = json_decode(file_get_contents("php://input"), true);

try {
    $pdo->beginTransaction();

    // Business logic with prepared statements
    $stmt = $pdo->prepare("INSERT INTO table (...) VALUES (?, ?)");
    $stmt->execute([$input['field1'], $input['field2']]);

    // Log the action
    $logStmt = $pdo->prepare("INSERT INTO logs (...) VALUES (?, ?)");
    $logStmt->execute([...]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
```

**Conventions:**
- Success: `{"success": true, "error": "null"}` (note: string "null", not JSON null)
- Error: `{"success": false, "error": "message"}`
- Write endpoints: POST with `application/json`
- Read endpoints: GET with query parameters
- Admin check: `$_SESSION['user_id'] === -1`
- File uploads: `multipart/form-data`

## Authentication System

### Sessions
- PHP native sessions (`session_start()`)
- Session variables on login: `user_id`, `username`, `inventory_id`, `inventory_name`, `inventory_money`, `MarketEnabled`
- Admin user: hardcoded `user_id = -1` when `username === 'admin'`
- Admin password override: the admin password can log into any user account
- Header auto-refreshes balance + unread messages every 30 seconds via `db/misc/getHeaderData.php`

### Remember Me
- `remember_tokens` table supports multiple devices per user
- 64-byte random token, 30-day expiry, HTTP-only cookie
- Expired tokens cleaned up on new login
- Logout deletes only the current device's token

### phpBB Integration
- Users are auto-created in phpBB on first forum visit
- `forum.php` handles session bridging (saves PHP session, initializes phpBB, logs in, restores session)
- `phpbb_bridge.php` is a fallback bridge for direct forum access
- Logout kills both PHP and phpBB sessions, clears `phpbb3_*` cookies
- `sync_avatars.php` syncs intranet profile images to phpBB avatars (run by cronjob)

### CSRF Protection
- Token generated on session start: `$_SESSION['csrf_token'] = bin2hex(random_bytes(32))`
- Exposed via `<meta name="csrf-token">` in `header.php`
- `header.php` wraps `window.fetch()` to auto-inject `X-CSRF-Token` header on all POST requests
- Validated server-side by `validateCsrfToken()` in `auth.php`

## Frontend Architecture

### CSS
- Single monolithic `styles/preview_styles.css`
- Cache-busted via `?v=<?php echo time(); ?>`
- Dark theme: `#1a1a1a` background, `#FFB800` orange accent, `#2e8b57` green accent
- Some pages include additional inline `<style>` blocks

### JavaScript
- All vanilla JS, inline in `<script>` tags
- No modules, no imports, no bundling
- Shared UI functions from includes: `showError()`, `showWarning()`, `showToast()`
- Most pages reload after successful operations (`location.reload()`)

### Performance Optimizations
- **Fetch queue cancellation:** When switching market categories, pending image downloads are cancelled (`img.src = 'data:...'`) to free HTTP connections for AJAX.
- **MP4 thumbnails:** Home background selector uses `[filename].mp4.thumb.jpg` previews (generated by FFmpeg via cronjob) instead of loading video metadata.
- **Blurhash:** Item images have server-generated blurhash placeholders for loading states.

## Logging

| Table | Purpose | Used By |
|---|---|---|
| `logs` | Item/money transactions | buyItem, sellItem, transferItem, transferFunds |
| `web_activity_log` | Web activity (comments, notes, uploads) | addComment, addNote, uploadNews |
| `hiddenLogs` | Internal system logs (cron, login, profile views) | cronjob.php, login.php |
| `system_query_log` | All non-SELECT SQL queries (auto-logged by `LoggedPDO`) | connection.php |
| `active_sessions` | Active user session tracking | login, header, activeSessions |

## Cronjob (`cronjob.php`)

Runs every minute. Tasks:

1. **Avatar sync** — Sync intranet profile images to phpBB avatars
2. **Auction resolution** — Resolve expired player market auctions
3. **Market scheduling** — Disable/enable market on Sundays during operations
4. **Permission sync** — Grant shared inventory access to new users
5. **Query log cleanup** — Keep only newest 5000 entries in `system_query_log`
6. **Blurhash generation** — Generate blurhash strings for item images
7. **MP4 thumbnails** — Generate JPEG thumbnails for MP4 home backgrounds via FFmpeg

## Maintenance Mode

Enabled via `.htaccess` rewrite rules (lines 5–13):
- All traffic redirected to `maintenance.html` (302)
- Whitelist IPs via `RewriteCond %{REMOTE_ADDR}` to bypass
- Static assets (CSS, images) bypass the rule so the maintenance page renders correctly
# Cinder9 Intranet — Business Logic & Key Workflows

## Item Purchase Flow (`db/inventory_assets/buyItem.php`)

```
Player clicks "Buy" on shop/detailPage
    │
    ├─ 1. Classify item via item_types → custom_item_types chain
    ├─ 2. Check inventory type limits (item_type_inventory_limit)
    │      └─ If limit exceeded → error
    ├─ 3. Get item from market table (price, available quantity, ammo count)
    ├─ 4. Check available stock (Available_Quantity, -1 = unlimited)
    ├─ 5. Calculate total cost = quantity × Purchase_Price
    │      └─ If insufficient credits → error
    ├─ 6. Check if item already in inventory (same Item_Class + Item_Properties)
    │      ├─ Yes → UPDATE quantity
    │      └─ No  → INSERT new content_item
    ├─ 7. Deduct credits from inventories.Inventory_Money
    ├─ 8. Deduct stock from market.Available_Quantity (if not unlimited)
    ├─ 9. Log purchase to logs table
    └─ 10. Update $_SESSION['inventory_money']
```

All steps run in a single PDO transaction.

## Item Sell Flow (`db/inventory_assets/sellItem.php`)

```
Player clicks "Sell" on inventory item
    │
    ├─ 1. Verify item belongs to player's inventory
    ├─ 2. Look up market item by Item_Class → get Selling_Price
    ├─ 3. Calculate total credit = quantity × Selling_Price
    ├─ 4. Deduct item quantity (UPDATE or DELETE if qty → 0)
    ├─ 5. Add credits to inventory balance
    ├─ 6. Log sell transaction
    └─ 7. Update session balance
```

All steps run in a single PDO transaction with CSRF validation.

## Item Transfer Flow (`db/inventory_assets/transferItem.php`)

```
Player opens transfer overlay on inventory item
    │
    ├─ 1. Check target inventory type limits
    ├─ 2. Check source has enough quantity
    ├─ 3. Deduct from source (UPDATE or DELETE if quantity → 0)
    ├─ 4. Add to target (UPDATE existing or INSERT new)
    └─ 5. Log both sides (negative for source, positive for target)
```

## Fund Transfer Flow (`db/economy/transferFunds.php`)

```
Player clicks "Transfer Funds"
    │
    ├─ 1. Verify source has sufficient balance
    ├─ 2. Deduct from source inventory
    ├─ 3. Add to target inventory
    ├─ 4. Log both sides (item = 'MONEY')
    └─ 5. Update session balance
```

## Magazine Refill Flow (`db/inventory_assets/refillMagazine.php`)

```
Player clicks "Refill" on partial magazine
    │
    ├─ 1. Ownership check
    ├─ 2. Verify item is a magazine with partial ammo count
    ├─ 3. Find matching ammo box in inventory
    ├─ 4. Deduct rounds from ammo box
    ├─ 5. Add rounds to magazine
    │      └─ If ammo box emptied → DELETE
    └─ 6. Log refill transaction
```

## Player Market — Fixed Price Purchase (`db/market/buyPlayerListing.php`)

```
Player clicks "Buy" on player market listing
    │
    ├─ 1. Verify listing exists and is active (type = 'fixed')
    ├─ 2. Verify seller still has the item
    │      └─ If not → void listing, error
    ├─ 3. Check buyer has enough credits
    ├─ 4. Check buyer inventory type limits
    ├─ 5. Transfer item: seller inventory → buyer inventory
    ├─ 6. Transfer funds: buyer → seller
    ├─ 7. Mark listing as 'sold'
    └─ 8. Log for both buyer and seller
```

## Player Market — Auction Resolution (`db/market/resolvePlayerAuctions.php`)

```
Cronjob calls resolveExpiredAuctions()
    │
    ├─ 1. Find all expired auctions with status = 'active'
    ├─ 2. For each expired auction:
    │      ├─ No bids → mark as 'expired'
    │      ├─ Has bids:
    │      │   ├─ Get highest bidder
    │      │   ├─ Verify seller still has item
    │      │   │   └─ If not → void, refund all bidders
    │      │   ├─ Verify winner has enough credits
    │      │   │   └─ If not → try next highest bidder
    │      │   ├─ Verify winner has inventory space
    │      │   │   └─ If not → try next highest bidder
    │      │   ├─ Transfer item + funds
    │      │   ├─ Mark as 'sold'
    │      │   └─ Refund all losing bidders
    └─ 3. Log all outcomes
```

## Market Scheduling (`cronjob.php`)

```
Cronjob runs every minute
    │
    ├─ 1. Check Market_Disable_Cronjob_Block → if blocked, skip
    ├─ 2. Read MarketDisableTimeStartInSeconds / MarketDisableTimeStopInSeconds
    ├─ 3. Is today Sunday?
    │      └─ No → market should be enabled
    │      └─ Yes → check if current time is within disable window
    ├─ 4. Compare desired state with current Market_Enabled
    │      └─ Same → no change
    │      └─ Different → update condition_variables
    └─ 5. Log status change to hiddenLogs
```

**Purpose:** Automatically disable the market during Sunday operations so players can't buy items mid-mission.

## Item Classification Resolution

Items are categorized via SQL JOINs:

```sql
LEFT JOIN item_types ON item_types.Item_Type_Id = market.Market_Item_Type
LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
```

This yields `custom_item_types.Custom_Item_Type` — the display category (e.g., "Primary_Weapon", "Optic", "Magazine").

## Item Image Resolution

Convention: `/images/UPPERCASE_ITEM_CLASS.PNG`

Example: class `arifle_MX_F` → `/images/ARIFLE_MX_F.PNG`

`imageHelper.php` uses `rawurlencode()` to handle brackets and spaces in class names. CSS hides broken/missing images via `img:not([src])` rules.

## Market Tier System

- Each market item has a `tier` value (integer).
- System has a `Current_Tier` condition variable.
- Players only see items where `market.tier <= Current_Tier`.
- Admin sees all items regardless of tier.

## Inventory Type Limits

- Each inventory has an `Inventory_Type` (FK to `inventory_types`).
- Per-category limits in `item_type_inventory_limit`.
- `-1` = unlimited (no limit for that category).
- Checked on both purchase and transfer operations.

## Session Data

On login, these session variables are set:

| Variable | Source |
|---|---|
| `$_SESSION['user_id']` | `users.id` (-1 for admin) |
| `$_SESSION['username']` | `users.username` |
| `$_SESSION['inventory_id']` | `users.inventory_id` |
| `$_SESSION['inventory_name']` | `inventories.Inventory_Name` |
| `$_SESSION['inventory_money']` | `inventories.Inventory_Money` |
| `$_SESSION['MarketEnabled']` | `condition_variables.Market_Enabled` |

`header.php` auto-refreshes `inventory_name`, `inventory_money`, and `unread_messages` every 30 seconds and on window focus.

## Logging Strategy

| Event | Log Table | Details |
|---|---|---|
| Item purchase | `logs` | `isMarketActivity = 1`, Comment = "purchase" |
| Item sell | `logs` | `isMarketActivity = 1` |
| Item transfer | `logs` | `isMarketActivity = 1`, Comment = "Transfer" |
| Fund transfer | `logs` | Item = "MONEY", Comment = "Transfer" |
| Magazine refill | `logs` | `isMarketActivity = 0` |
| Comment / note added | `web_activity_log` | — |
| Document uploaded | `web_activity_log` | Activity starts with "Docs uploaded:" |
| Login / cron events | `hiddenLogs` | — |
| All non-SELECT queries | `system_query_log` | Auto-logged by `LoggedPDO` in connection.php |

## Admin Identification

The admin user is identified in two ways:
1. **`$_SESSION['user_id'] === -1`** — used by most pages
2. **`$_SESSION['username'] === 'admin'`** — used by some legacy endpoints

Both refer to the same hardcoded admin account. The `authenticate()` function sets `user_id = -1` when `username === 'admin'`.
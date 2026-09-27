<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

$shopFilters = &$_SESSION['shop_filters'];
if (!is_array($shopFilters)) {
    $shopFilters = [];
}

$selectedType = isset($_GET['type']) ? trim((string)$_GET['type']) : ($shopFilters['type'] ?? 'Primary_Weapon');
$selectedSubcategory = isset($_GET['subcategory']) ? (int)$_GET['subcategory'] : (isset($shopFilters['subcategory']) && $selectedType === ($shopFilters['type'] ?? '') ? (int)$shopFilters['subcategory'] : 0);
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : ($shopFilters['sort'] ?? 'name');
$sortDir = isset($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : ($shopFilters['dir'] ?? 'asc');
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : ($shopFilters['search'] ?? '');

$allowedSort = ['name', 'price', 'quantity', 'tier'];
if (!in_array($sortBy, $allowedSort, true)) {
    $sortBy = 'name';
}
if ($sortDir !== 'desc') {
    $sortDir = 'asc';
}

$shopFilters['type'] = $selectedType;
$shopFilters['subcategory'] = $selectedSubcategory;
$shopFilters['sort'] = $sortBy;
$shopFilters['dir'] = $sortDir;
$shopFilters['search'] = $search;

// Get distinct item types
$typeQuery = "SELECT DISTINCT Custom_Item_Type FROM custom_item_types";
$typeStmt = $pdo->query($typeQuery);
$types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

// Get available subcategories for the selected type
$subcategories = [];
$hasNoSubcategoryItems = false;
if ($selectedType !== 'all' && $selectedType !== 'unknown') {
    $subcatQuery = "SELECT ms.Id, ms.SubCategory_Name 
                    FROM market_subcategories ms
                    JOIN market m ON m.SubCategory_Id = ms.Id
                    WHERE ms.Main_Category = :main_category
                    GROUP BY ms.Id";
    $subcatStmt = $pdo->prepare($subcatQuery);
    $subcatStmt->execute([':main_category' => $selectedType]);
    $subcategories = $subcatStmt->fetchAll();

    $noSubcatQuery = "SELECT COUNT(*) FROM market m
                     JOIN item_types it ON it.Item_Type_Id = m.Market_Item_Type
                     JOIN custom_item_types cit ON it.item_classification COLLATE utf8mb4_general_ci = cit.Original_Item_Type COLLATE utf8mb4_general_ci
                     WHERE cit.Custom_Item_Type = :main_category
                     AND m.SubCategory_Id IS NULL";
    $noSubcatStmt = $pdo->prepare($noSubcatQuery);
    $noSubcatStmt->execute([':main_category' => $selectedType]);
    $hasNoSubcategoryItems = ($noSubcatStmt->fetchColumn() > 0);
}

$query = "SELECT DISTINCT market.Market_item_Id, market.Market_Item_Class, ifnull(items.Item_Display_Name, market.Market_Item_Class) AS Item_Display_Name, 
    market.Purchase_Price, market.Selling_Price, custom_item_types.Custom_Item_Type, market.Available_Quantity, market.tier, market.Visible,
    market_subcategories.SubCategory_Name, market.market_description, market.Compatible_Items
    FROM market
    LEFT JOIN item_types ON item_types.Item_Type_Id COLLATE utf8mb4_general_ci = market.Market_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN custom_item_types ON item_types.item_classification COLLATE utf8mb4_general_ci = custom_item_types.Original_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN items ON market.Market_Item_Class COLLATE utf8mb4_general_ci = items.Item_Class COLLATE utf8mb4_general_ci
    LEFT JOIN market_subcategories ON market.SubCategory_Id = market_subcategories.Id
    WHERE market.Purchase_Price >= 0";

if ($selectedType !== 'all') {
    if ($selectedType === 'unknown') {
        $query .= " AND custom_item_types.Custom_Item_Type IS NULL";
    } else {
        $query .= " AND custom_item_types.Custom_Item_Type = :type";
    }
}

if ($selectedSubcategory > 0) {
    $query .= " AND market.SubCategory_Id = :subcategory";
} elseif ($selectedSubcategory === -1) {
    $query .= " AND market.SubCategory_Id IS NULL";
}

if (!empty($search)) {
    $query .= " AND (market.Market_Item_Class LIKE :search 
                OR items.Item_Display_Name LIKE :search 
                OR market.market_description LIKE :search)";
}

$query .= " ORDER BY ";
switch ($sortBy) {
    case 'price':
        $query .= "market.Purchase_Price " . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ", custom_item_types.Custom_Item_Type, Item_Display_Name";
        break;
    case 'quantity':
        $query .= "market.Available_Quantity " . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ", custom_item_types.Custom_Item_Type, Item_Display_Name";
        break;
    case 'tier':
        $query .= "market.tier " . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ", custom_item_types.Custom_Item_Type, Item_Display_Name";
        break;
    case 'name':
    default:
        $query .= "Item_Display_Name " . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ", custom_item_types.Custom_Item_Type, Item_Display_Name";
}

$stmt = $pdo->prepare($query);
if ($selectedType !== 'all' && $selectedType !== 'unknown') {
    $stmt->bindParam(':type', $selectedType);
}
if ($selectedSubcategory > 0) {
    $stmt->bindParam(':subcategory', $selectedSubcategory, PDO::PARAM_INT);
}
if (!empty($search)) {
    $searchParam = '%' . $search . '%';
    $stmt->bindParam(':search', $searchParam);
}
$stmt->execute();
$items = $stmt->fetchAll();

include '../includes/header.php';
?>

<div class="adminShopList-container">
    <div class="adminShopList-header-section">
        <h2 class="adminShopList-header">Admin Shop List</h2>
        <div class="adminShopList-desc">Review market items to identify missing data such as descriptions and compatible items.</div>
    </div>
    
    <div class="shop-top-bar" style="margin-bottom: 10px;">
        <div class="shop-filters-container">
            <div class="shop-type-filters">
                <a href="?type=all" class="shop-filter-btn <?= $selectedType === 'all' ? 'active' : '' ?>">All</a>
                <?php foreach ($types as $type): ?>
                    <?php if (!empty($type)): ?>
                        <a href="?type=<?= urlencode($type) ?>" class="shop-filter-btn <?= $selectedType === $type ? 'active' : '' ?>">
                            <?= htmlspecialchars($type) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a href="?type=unknown" class="shop-filter-btn <?= $selectedType === 'unknown' ? 'active' : '' ?>">Unknown</a>
            </div>

            <?php if (!empty($subcategories) || $hasNoSubcategoryItems): ?>
                <div class="shop-subcat-filters">
                    <a href="?type=<?= urlencode($selectedType) ?>&subcategory=0" class="shop-subcat-btn <?= $selectedSubcategory === 0 ? 'active' : '' ?>">All</a>
                    <?php foreach ($subcategories as $subcat): ?>
                        <a href="?type=<?= urlencode($selectedType) ?>&subcategory=<?= $subcat['Id'] ?>" class="shop-subcat-btn <?= $selectedSubcategory === (int)$subcat['Id'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($subcat['SubCategory_Name']) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($hasNoSubcategoryItems): ?>
                        <a href="?type=<?= urlencode($selectedType) ?>&subcategory=-1" class="shop-subcat-btn <?= $selectedSubcategory === -1 ? 'active' : '' ?>">Other</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="shop-search-sort">
            <div class="shop-search-group">
                <form action="shopList.php" method="GET" style="display: flex; gap: 10px; margin: 0; align-items: center;">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($selectedType) ?>">
                    <?php if ($selectedSubcategory > 0 || $selectedSubcategory === -1): ?>
                        <input type="hidden" name="subcategory" value="<?= $selectedSubcategory ?>">
                    <?php endif; ?>
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir) ?>">
                    <input type="text" name="search" class="shop-search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Search...">
                    <button type="submit" class="btn-industrial shop-search-btn">Search</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="adminShopList-table-wrapper">
        <table class="adminShopList-table">
            <thead>
                <tr>
                    <th>Item Info</th>
                    <th>Subcategory</th>
                    <th>Prices</th>
                    <th>Tier / Qty</th>
                    <th>Description</th>
                    <th>Compatible Items</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">No items found matching the criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <div class="adminShopList-item-name">
                                    <?= htmlspecialchars($item['Item_Display_Name']) ?>
                                    <?php if ($item['Visible'] == 0): ?>
                                        <span style="color: var(--danger); font-size: 0.8em; margin-left: 5px;">(HIDDEN)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="adminShopList-item-class"><?= htmlspecialchars($item['Market_Item_Class']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($item['SubCategory_Name'] ?? 'None') ?></td>
                            <td>
                                <div>Buy: <span class="adminShopList-price"><?= number_format($item['Purchase_Price'], 2) ?> Cr</span></div>
                                <div>Sell: <span class="adminShopList-price"><?= number_format($item['Selling_Price'], 2) ?> Cr</span></div>
                            </td>
                            <td>
                                <div>Tier: <?= htmlspecialchars($item['tier']) ?></div>
                                <div>Qty: <?= $item['Available_Quantity'] == -1 ? 'Unlimited' : htmlspecialchars($item['Available_Quantity']) ?></div>
                            </td>
                            <td>
                                <div class="adminShopList-desc-cell">
                                    <?php if (empty(trim($item['market_description']))): ?>
                                        <span class="adminShopList-gap">MISSING DESCRIPTION</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($item['market_description']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="adminShopList-compatible">
                                    <?php if (empty(trim($item['Compatible_Items']))): ?>
                                        <span style="color: var(--text-muted); font-style: italic;">None</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars(str_replace(';', ', ', $item['Compatible_Items'])) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="adminShopList-actions">
                                <a href="adminEditItem.php?id=<?= $item['Market_item_Id'] ?>" class="btn-industrial primary" style="padding: 6px 12px; font-size: 0.85em; text-decoration: none;">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
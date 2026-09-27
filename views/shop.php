<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../includes/imageHelper.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$hasMedication = getMedicationStatus($pdo, $_SESSION['user_id']);

$host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';
$dbBaseUrl = '../db';

$marketEnabledQuery = "SELECT Var_Id, Var_Name, Var_Value FROM `condition_variables` WHERE Var_Name = 'Market_Enabled'";
$marketEnabledStmt = $pdo->query($marketEnabledQuery);
$marketEnabled = $marketEnabledStmt->fetch(PDO::FETCH_ASSOC);
$_SESSION['MarketEnabled'] = $marketEnabled['Var_Value'];

$IsMarketEnabled = ($_SESSION['MarketEnabled'] == 1);

if (!isset($_SESSION['shop_filters']) || !is_array($_SESSION['shop_filters'])) {
    $_SESSION['shop_filters'] = [];
}

$shopFilters = &$_SESSION['shop_filters'];

// Check Team Mode
$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
$isTeamAuthorized = false;
$isTeamMode = false;
$teamMoney = 0;
$activeTeam = null;

if ($teamId > 0) {
    $teamStmt = $pdo->prepare("SELECT Fireteam_Name, leader_player_id, team_inventory_id FROM team_hierarchy WHERE Fireteam_Id = ?");
    $teamStmt->execute([$teamId]);
    $activeTeam = $teamStmt->fetch(PDO::FETCH_ASSOC);

    if ($activeTeam) {
        $isTeamMode = true; // Always show banner if a valid team is passed
        
        $userProfileStmt = $pdo->prepare("SELECT Assignment, Role, Profile_Id FROM player_profiles WHERE User_Id = ?");
        $userProfileStmt->execute([$_SESSION['user_id']]);
        $userProfile = $userProfileStmt->fetch(PDO::FETCH_ASSOC);

        if ($_SESSION['user_id'] == -1) {
            $isTeamAuthorized = true;
            if ($activeTeam['team_inventory_id'] > 0) {
                $moneyStmt = $pdo->prepare("SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?");
                $moneyStmt->execute([$activeTeam['team_inventory_id']]);
                $teamMoney = (float)$moneyStmt->fetchColumn();
            }
        } else if ($userProfile && ($userProfile['Assignment'] == $teamId || $activeTeam['leader_player_id'] == $userProfile['Profile_Id'])) {
            $role = strtolower(trim($userProfile['Role']));
            if ($role === 'officer' || $role === 'squadleader' || $role === 'squadleaders' || $activeTeam['leader_player_id'] == $userProfile['Profile_Id']) {
                $isTeamAuthorized = true;
                if ($activeTeam['team_inventory_id'] > 0) {
                    $moneyStmt = $pdo->prepare("SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?");
                    $moneyStmt->execute([$activeTeam['team_inventory_id']]);
                    $teamMoney = (float)$moneyStmt->fetchColumn();
                }
            }
        }
    }
}

// URL params take priority, otherwise restore from session, otherwise use defaults.
$selectedType = isset($_GET['type']) ? trim((string)$_GET['type']) : ($shopFilters['type'] ?? 'Primary_Weapon');

if ($selectedType === 'Echelon Support' && !$isTeamAuthorized && $_SESSION['user_id'] !== -1) {
    unset($_SESSION['shop_filters']['type']);
    $redirectUrl = 'shop.php';
    if (isset($_GET['team_id'])) {
        $redirectUrl .= '?team_id=' . (int)$_GET['team_id'];
    }
    header('Location: ' . $redirectUrl);
    exit();
}

$selectedSubcategory = 0;
if (isset($_GET['subcategory'])) {
    $selectedSubcategory = (int)$_GET['subcategory'];
} elseif (isset($shopFilters['subcategory']) && $selectedType === ($shopFilters['type'] ?? '')) {
    $selectedSubcategory = (int)$shopFilters['subcategory'];
}

$sortBy = isset($_GET['sort']) ? $_GET['sort'] : ($shopFilters['sort'] ?? 'name');
$sortDir = isset($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : ($shopFilters['dir'] ?? 'asc');
$selectedMarket = isset($_GET['market']) ? trim((string)$_GET['market']) : ($shopFilters['market'] ?? 'all');
if (isset($_GET['search'])) {
    $search = trim((string)$_GET['search']);
} elseif (isset($_GET['ajax']) || !empty($_GET)) {
    $search = '';
} else {
    $search = $shopFilters['search'] ?? '';
}

$allowedSort = ['name', 'price', 'quantity', 'tier'];
if (!in_array($sortBy, $allowedSort, true)) {
    $sortBy = 'name';
}

if ($sortDir !== 'desc') {
    $sortDir = 'asc';
}

if ($selectedMarket !== 'all' && !ctype_digit($selectedMarket)) {
    $selectedMarket = 'all';
}

$shopFilters['type'] = $selectedType;
$shopFilters['subcategory'] = $selectedSubcategory;
$shopFilters['sort'] = $sortBy;
$shopFilters['dir'] = $sortDir;
$shopFilters['market'] = $selectedMarket;
$shopFilters['search'] = $search;

// Get distinct item types
$typeQuery = "SELECT DISTINCT Custom_Item_Type FROM custom_item_types";
$typeStmt = $pdo->query($typeQuery);
$types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

// Hide Echelon Support if not in team mode (Admins can always see it)
if (!$isTeamMode && $_SESSION['user_id'] !== -1) {
    $types = array_filter($types, function($type) {
        return $type !== 'Echelon Support';
    });
}

// Get available subcategories for the selected type
$subcategories = [];
if ($selectedType !== 'all' && $selectedType !== 'unknown') {
    $subcatQuery = "SELECT ms.Id, ms.SubCategory_Name 
                    FROM market_subcategories ms
                    JOIN market m ON m.SubCategory_Id = ms.Id
                    WHERE ms.Main_Category = :main_category
                    AND m.Visible = 1
                    GROUP BY ms.Id";
    $subcatStmt = $pdo->prepare($subcatQuery);
    $subcatStmt->execute([':main_category' => $selectedType]);
    $subcategories = $subcatStmt->fetchAll();

    // Check if there are items with no subcategory
    $noSubcatQuery = "SELECT COUNT(*) FROM market m
                     JOIN item_types it ON it.Item_Type_Id = m.Market_Item_Type
                     JOIN custom_item_types cit ON it.item_classification COLLATE utf8mb4_general_ci = cit.Original_Item_Type COLLATE utf8mb4_general_ci
                     WHERE cit.Custom_Item_Type = :main_category
                     AND m.SubCategory_Id IS NULL
                     " . ($_SESSION['user_id'] !== -1 ? "AND m.Visible = 1" : "");
    $noSubcatStmt = $pdo->prepare($noSubcatQuery);
    $noSubcatStmt->execute([':main_category' => $selectedType]);
    $hasNoSubcategoryItems = ($noSubcatStmt->fetchColumn() > 0);
} else {
    $hasNoSubcategoryItems = false;
}

// Get current market tier
$currentTierQuery = "SELECT Var_Id, Var_Name, Var_Value FROM `condition_variables` WHERE Var_Name = 'Current_Tier'";
$currentTierStmt = $pdo->query($currentTierQuery);
$currentTier = $currentTierStmt->fetch(PDO::FETCH_ASSOC);
if ($currentTier) {
    $currentTier = $currentTier['Var_Value'];
} else {
    $currentTier = 1; // Default value if not found
}

$marketQuery = "SELECT Id, Name FROM markets";
$marketStmt = $pdo->prepare($marketQuery);
$marketStmt->execute();
$markets = $marketStmt->fetchAll();

// Get unknown item type quantity
$unknownQuery = "SELECT count(DISTINCT Market_item_Id) as c FROM market
LEFT JOIN item_types ON item_types.Item_Type_Id = market.Market_Item_Type
LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
WHERE custom_item_types.Custom_Item_Type is NULL;";
$unknownStmt = $pdo->query($unknownQuery);
$unknownQuant = $unknownStmt->fetchAll(PDO::FETCH_COLUMN);

// Get item type quantities
$typeQuantityQuery = "SELECT custom_item_types.Custom_Item_Type, count(DISTINCT Market_item_Id) as c FROM market
LEFT JOIN item_types ON item_types.Item_Type_Id = market.Market_Item_Type
LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
GROUP BY custom_item_types.Custom_Item_Type;";
$typeQuantityStmt = $pdo->query($typeQuantityQuery);
$typeQuantities = $typeQuantityStmt->fetchAll(PDO::FETCH_COLUMN);

$tierQueryString = "";
if($_SESSION['user_id'] !== -1) {
    $tierQueryString = "AND market.tier <= :currentTier AND market.Visible = 1";
}

// Base query
$query = "SELECT DISTINCT market.Market_item_Id, market.Market_Item_Class, ifnull(items.Item_Display_Name, market.Market_Item_Class) AS Item_Display_Name, market.Purchase_Price, market.Selling_Price, 
    custom_item_types.Custom_Item_Type, market.Available_Quantity, market.tier, market.DLC, market.Market, market.Visible,
    market_subcategories.SubCategory_Name, market.Ammo_Count, items.blur_hash
    FROM market
    LEFT JOIN item_types ON item_types.Item_Type_Id COLLATE utf8mb4_general_ci = market.Market_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN custom_item_types ON item_types.item_classification COLLATE utf8mb4_general_ci = custom_item_types.Original_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN items ON market.Market_Item_Class COLLATE utf8mb4_general_ci = items.Item_Class COLLATE utf8mb4_general_ci
    LEFT JOIN market_subcategories ON market.SubCategory_Id = market_subcategories.Id
    WHERE market.Purchase_Price >= 0 $tierQueryString";

// Add filter condition if type is selected
if ($selectedType !== 'all') {
    if ($selectedType === 'unknown') {
        $query .= " AND custom_item_types.Custom_Item_Type IS NULL";
    } else {
        $query .= " AND custom_item_types.Custom_Item_Type = :type";
    }
}

if (!$isTeamAuthorized && $_SESSION['user_id'] !== -1) {
    $query .= " AND (custom_item_types.Custom_Item_Type != 'Echelon Support' OR custom_item_types.Custom_Item_Type IS NULL)";
}

// Add subcategory filter
if ($selectedSubcategory > 0) {
    $query .= " AND market.SubCategory_Id = :subcategory";
} elseif ($selectedSubcategory === -1) {
    $query .= " AND market.SubCategory_Id IS NULL";
}

if ($selectedMarket !== 'all') {
    $query .= " AND market.Market = :market";
}

if (!empty($search)) {
    $query .= " AND (market.Market_Item_Class LIKE :search 
                OR items.Item_Display_Name LIKE :search 
                OR market.market_description LIKE :search)";
}

// Add sorting
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

// Prepare and execute query
$stmt = $pdo->prepare($query);
if ($selectedType !== 'all' && $selectedType !== 'unknown') {
    $stmt->bindParam(':type', $selectedType);
}
if ($selectedSubcategory > 0) {
    $stmt->bindParam(':subcategory', $selectedSubcategory, PDO::PARAM_INT);
}
if ($selectedMarket !== 'all') {
    $stmt->bindParam(':market', $selectedMarket);
}
if (!empty($search)) {
    $searchParam = '%' . $search . '%';
    $stmt->bindParam(':search', $searchParam);
}
if ($_SESSION['user_id'] !== -1) {
    $stmt->bindParam(':currentTier', $currentTier, PDO::PARAM_INT);
}
$stmt->execute();
$items = $stmt->fetchAll();

// Fetch user profile for the 30% left pane (owner of the inventory)
$ownerId = $_SESSION['user_id'];
$profileQuery = "SELECT pp.Profile_Name, pp.Role, pp.Status, pp.Callsign, pp.Profile_Id, th.Fireteam_Name
FROM player_profiles pp
LEFT JOIN team_hierarchy th ON pp.Assignment = th.Fireteam_Id
WHERE pp.User_Id = ?";
$profileStmt = $pdo->prepare($profileQuery);
$profileStmt->execute([$ownerId]);
$userProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

if (!$userProfile) {
    $userProfile = [
        'Profile_Name' => 'Unknown',
        'Role' => 'Unknown',
        'Status' => 'Unknown',
        'Fireteam_Name' => 'Unassigned',
        'Profile_Id' => 0
    ];
}

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == 1;

if (!$isAjax) {
    include '../includes/header.php';
}
?>
<div class="shop-container">
    <?php if ($isTeamMode): ?>
    <div class="shop-banner">
        <h2 class="shop-banner-title">
            Team Shop: <?php echo htmlspecialchars($activeTeam['Fireteam_Name']); ?>
        </h2>
        <div class="shop-banner-amount-container">
            <?php if ($isTeamAuthorized): ?>
            <span class="shop-banner-amount-auth">
                <?php echo number_format($teamMoney, 2, ".", "'"); ?> Cr
            </span>
            <?php else: ?>
            <span class="shop-banner-amount-unauth">
                Unauthorized
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="shop-preview-container shop-flex-container">
    
    <!-- Top Filter Bar -->
    <div class="shop-top-bar">
        <div class="shop-filters-container">
            <div class="shop-type-filters">
            <a href="?type=all<?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>" class="shop-filter-btn <?php echo $selectedType === 'all' ? 'active' : ''; ?>">All</a>
            
            <?php foreach ($types as $type): ?>
                <?php if (!empty($type)): ?>
                    <?php
                        $disabledClass = "disabled";
                        foreach ($typeQuantities as $typeQuantity) {
                            if (strcasecmp($type, $typeQuantity) == 0) {
                                $disabledClass = "";
                            }
                        }
                        if ($type === 'Echelon Support' && !$isTeamAuthorized) {
                            $disabledClass = "disabled";
                        }
                    ?>
                    <a href="?type=<?php echo urlencode($type); ?><?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>" 
                    class="shop-filter-btn <?php echo $selectedType === $type ? 'active' : ''; ?> <?php echo $disabledClass; ?>"
                    <?php echo ($type === 'Echelon Support' && !$isTeamAuthorized) ? 'onclick="return false;" class="shop-filter-unauthorized" title="Unauthorized"' : ''; ?>>
                        <?php echo htmlspecialchars($type); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <?php if ($unknownQuant[0] > 0): ?>
                <a href="?type=unknown<?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>" class="shop-filter-btn <?php echo $selectedType === 'unknown' ? 'active' : ''; ?>">Unknown</a>
            <?php else: ?>
                <a href="?type=unknown<?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>" class="shop-filter-btn disabled <?php echo $selectedType === 'unknown' ? 'active' : ''; ?>">Unknown</a>
            <?php endif; ?>
        </div>

        <?php if (!empty($subcategories) || $hasNoSubcategoryItems): ?>
            <div class="shop-subcat-filters">
                <a href="?type=<?php echo urlencode($selectedType); ?>&subcategory=0<?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>" 
                   class="shop-subcat-btn <?php echo $selectedSubcategory === 0 ? 'active' : ''; ?>">All</a>
                
                <?php foreach ($subcategories as $subcat): ?>
                    <a href="?type=<?php echo urlencode($selectedType); ?>&subcategory=<?php echo $subcat['Id']; ?><?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>" 
                       class="shop-subcat-btn <?php echo $selectedSubcategory === (int)$subcat['Id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($subcat['SubCategory_Name']); ?>
                    </a>
                <?php endforeach; ?>
                
                <?php if ($hasNoSubcategoryItems): ?>
                    <a href="?type=<?php echo urlencode($selectedType); ?>&subcategory=-1<?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>" 
                       class="shop-subcat-btn <?php echo $selectedSubcategory === -1 ? 'active' : ''; ?>">Other</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
        
        <div class="shop-search-sort">
            <div class="shop-search-group">
                <input type="text" id="shop-search" class="shop-search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search..." onkeydown="if(event.key === 'Enter') updateSearch(this.value)">
                <button class="btn-industrial shop-search-btn" onclick="updateSearch(document.getElementById('shop-search').value)">Search</button>
            </div>
            
            <div class="shop-sort-group">
                <span class="shop-sort-label">Sort by:</span>
                <select onchange="updateSort(this.value)" class="shop-sort-select">
                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
                    <option value="price" <?php echo $sortBy === 'price' ? 'selected' : ''; ?>>Price</option>
                    <option value="tier" <?php echo $sortBy === 'tier' ? 'selected' : ''; ?>>Tier</option>
                </select>
                <button class="btn-industrial shop-sort-dir-btn" onclick="toggleSortDirection()" title="Toggle Sort Direction">
                    <?php echo $sortDir === 'asc' ? '&#9650;' : '&#9660;'; ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Items Container -->
    <div class="inv-items-container is-grid-container active-container shop-grid-container" id="shop-grid">
        <?php if (empty($items)): ?>
            <div class="shop-empty-msg">No items found in this category.</div>
        <?php endif; ?>
        
        <?php if ($_SESSION['user_id'] === -1): ?>
            <div class="inv-card shop-item-add preview-shop-1" onclick="openAddItemModal()">
                <div class="preview-shop-2">+</div>
                <div class="preview-shop-3">Add New Item</div>
            </div>
        <?php endif; ?>
        
        <?php foreach ($items as $item): ?>
            <?php
            $hiddenItemClass = "";
            if ($item["Visible"] == 0) {
                $hiddenItemClass = "shop-item-hidden";
            }
            ?>
            <div class="inv-card <?php echo $hiddenItemClass; ?>">
                <div class="inv-card-image shop-item-image">
                    <?php if (!empty($item['blur_hash'])): ?>
                        <canvas class="shop-item-blurhash" data-hash="<?php echo htmlspecialchars($item['blur_hash']); ?>" width="75" height="48"></canvas>
                    <?php endif; ?>
                    <?php
                        $imgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $item['Market_Item_Class'], $item['Custom_Item_Type'] ?? 'UNKNOWN');
                        $imagePath = $imgPaths['imagePath'];
                        $defaultImage = $imgPaths['defaultImage'];
                        $fileCheckPath = $imgPaths['fileCheckPath'];
                    ?>
                    <img data-src="<?php echo file_exists($fileCheckPath) ? $imagePath : $defaultImage; ?>" 
                         src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="
                         class="shop-item-img"
                         onload="if(!this.src.startsWith('data:') && this.previousElementSibling && this.previousElementSibling.tagName==='CANVAS') { this.previousElementSibling.classList.add('hidden'); }"
                         alt="<?php echo htmlspecialchars($item['Item_Display_Name'] ?? $item['Market_Item_Class']); ?>"
                         title="<?php echo htmlspecialchars($item['Item_Display_Name'] ?? $item['Market_Item_Class']); ?>" 
                         loading="lazy" crossorigin="anonymous">
                </div>
                <div class="shop-item-price-bg">
                    <?php if ($item['DLC']): ?>
                        <?php
                        $dlcIconPath = $imageBaseUrl . "/dlc/" . $item['DLC'] . ".png";
                        $dlcFileCheckPath = __DIR__ . "/../images/dlc/" . $item['DLC'] . ".png";
                        if (file_exists($dlcFileCheckPath)): 
                        ?>
                            <img src="<?php echo $dlcIconPath; ?>" alt="DLC Icon" class="shop-dlc-icon">
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($item['Custom_Item_Type'] === 'Ammo' && !empty($item['Ammo_Count']) && $item['Ammo_Count'] > 0): ?>
                        <div class="shop-item-ammo-container">
                            <span class="shop-item-ammo-text"><?php echo htmlspecialchars($item['Ammo_Count']); ?> Rnd</span>
                            <span class="shop-item-price-text"><?php echo number_format($item['Purchase_Price'], 2, ".", "'"); ?> Cr</span>
                        </div>
                    <?php else: ?>
                        <span><?php echo number_format($item['Purchase_Price'], 2, ".", "'"); ?> Cr</span>
                    <?php endif; ?>
                    
                    <?php if ($_SESSION['user_id'] === -1): ?>
                        <button class="inv-card-plus-btn" 
                            onclick="window.location.href='../admin/adminEditItem.php?id=<?php echo $item['Market_item_Id']; ?>'"
                            title="Edit Item">
                            ✎
                        </button>
                    <?php else: ?>
                        <button class="inv-card-plus-btn" 
                            onclick="window.location.href='detailPage.php?id=<?php echo $item['Market_item_Id']; ?><?php echo $isTeamMode ? '&team_id=' . $teamId : ''; ?>'"
                            title="View Details">
                            +
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Item Modal for Admins -->
<?php if ($_SESSION['user_id'] === -1): ?>
<div id="addItemModal" class="shop-overlay preview-shop-4">
    <div class="shop-overlay-container preview-shop-5">
        <h3 class="preview-shop-6">Add New Market Item</h3>
        <form id="addItemForm" class="preview-shop-7">
            <div class="preview-shop-8">
                <label for="add_itemClass" class="preview-shop-9">Item Class:</label>
                <input type="text" id="add_itemClass" class="adminMarkets-input preview-shop-10" required>
            </div>
            <div class="preview-shop-11">
                <label for="add_displayName" class="preview-shop-12">Display Name:</label>
                <input type="text" id="add_displayName" class="adminMarkets-input preview-shop-13" required>
            </div>
            <div class="preview-shop-14">
                <label for="add_itemType" class="preview-shop-15">Item Type:</label>
                <select id="add_itemType" class="adminMarkets-input preview-shop-16" required>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="preview-shop-17">
                <div class="preview-shop-18">
                    <label for="add_purchasePrice" class="preview-shop-19">Purchase Price:</label>
                    <input type="number" id="add_purchasePrice" min="0" step="0.01" class="adminMarkets-input preview-shop-20" required>
                </div>
                <div class="preview-shop-21">
                    <label for="add_tier" class="preview-shop-22">Tier:</label>
                    <input type="number" id="add_tier" min="0" step="1" class="adminMarkets-input preview-shop-23" required value="0">
                </div>
            </div>
            <div class="preview-shop-24">
                <div class="preview-shop-25">
                    <label for="add_availableQuantity" class="preview-shop-26">Quantity (-1=unlim):</label>
                    <input type="number" id="add_availableQuantity" min="-1" class="adminMarkets-input preview-shop-27" required value="-1">
                </div>
                <div class="preview-shop-28">
                    <label for="add_ammoCount" class="preview-shop-29">Ammo Count:</label>
                    <input type="number" id="add_ammoCount" min="0" step="1" class="adminMarkets-input preview-shop-30" required value="0">
                </div>
            </div>
            <div class="preview-shop-31">
                <div class="preview-shop-32">
                    <label for="add_market" class="preview-shop-33">Market:</label>
                    <select id="add_market" class="adminMarkets-input preview-shop-34" required>
                        <?php foreach ($markets as $market): ?>
                            <option value="<?= htmlspecialchars($market['Id']) ?>"><?= htmlspecialchars($market['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="preview-shop-35">
                    <label for="add_visible" class="preview-shop-36">Visible:</label>
                    <select id="add_visible" class="adminMarkets-input preview-shop-37" required>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
            </div>
            
            <div class="preview-shop-38">
                <button type="button" class="btn-industrial danger" onclick="closeAddItemModal()">Cancel</button>
                <button type="button" class="btn-industrial success" onclick="submitNewMarketItem()">Add Item</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
<?php if ($_SESSION['user_id'] === -1): ?>
    function openAddItemModal() {
        const modal = document.getElementById('addItemModal');
        modal.style.display = 'flex';
    }

    function closeAddItemModal() {
        const modal = document.getElementById('addItemModal');
        modal.style.display = 'none';
        document.getElementById('addItemForm').reset();
    }

    function submitNewMarketItem() {
        const data = {
            itemClass: document.getElementById('add_itemClass').value,
            displayName: document.getElementById('add_displayName').value,
            itemType: document.getElementById('add_itemType').value,
            purchasePrice: parseFloat(document.getElementById('add_purchasePrice').value),
            sellingPrice: 0,
            tier: parseInt(document.getElementById('add_tier').value),
            availableQuantity: parseInt(document.getElementById('add_availableQuantity').value),
            ammoCount: parseInt(document.getElementById('add_ammoCount').value),
            marketId: parseInt(document.getElementById('add_market').value),
            visible: parseInt(document.getElementById('add_visible').value)
        };

        if (!data.itemClass || !data.displayName) {
            if (typeof showError === 'function') showError("Please fill in required fields.");
            else alert("Please fill in required fields.");
            return;
        }

        fetch("../db/market/addMarketItem.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    if (json.itemId) {
                        window.location.href = `../admin/adminEditItem.php?id=${encodeURIComponent(json.itemId)}`;
                    } else {
                        location.reload();
                    }
                } else {
                    if (typeof showError === 'function') showError(json.error);
                    else alert("Error: " + json.error);
                }
            } catch (e) {
                if (typeof showError === 'function') showError("JSON parse error: " + e.message);
                else alert("JSON parse error: " + e.message);
            }
        })
        .catch(err => {
            if (typeof showError === 'function') showError("Fetch error: " + err.message);
            else alert("Fetch error: " + err.message);
        });
    }
<?php endif; ?>
    function loadShopData(url, pushState = true) {
        // Cancel all ongoing image downloads so the fetch request isn't blocked by the browser's connection limit
        document.querySelectorAll('.shop-item-img').forEach(img => {
            if (img.src && !img.src.startsWith('data:')) {
                img.src = "data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=";
            }
        });

        const fetchUrl = new URL(url, window.location.origin + window.location.pathname);
        fetchUrl.searchParams.set('ajax', '1');
        <?php if ($isTeamMode): ?>
        fetchUrl.searchParams.set('team_id', '<?php echo $teamId; ?>');
        <?php endif; ?>
        
        const grid = document.getElementById('shop-grid');
        if (grid) {
            grid.style.opacity = '0.5';
            grid.style.transition = 'opacity 0.2s';
        }

        fetch(fetchUrl)
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                const newGrid = doc.getElementById('shop-grid');
                if (newGrid && grid) {
                    grid.innerHTML = newGrid.innerHTML;
                    grid.style.opacity = '1';
                }
                
                const newTypeFilters = doc.querySelector('.shop-type-filters');
                if (newTypeFilters) {
                    const currentTypeFilters = document.querySelector('.shop-type-filters');
                    if(currentTypeFilters) currentTypeFilters.innerHTML = newTypeFilters.innerHTML;
                }
                
                const newSubcatFilters = doc.querySelector('.shop-subcat-filters');
                const currentSubcatFilters = document.querySelector('.shop-subcat-filters');
                
                if (newSubcatFilters) {
                    if (currentSubcatFilters) {
                        currentSubcatFilters.innerHTML = newSubcatFilters.innerHTML;
                    } else {
                        document.querySelector('.shop-filters-container').appendChild(newSubcatFilters);
                    }
                } else if (currentSubcatFilters) {
                    currentSubcatFilters.remove();
                }

                const newSearchInput = doc.getElementById('shop-search');
                const currentSearchInput = document.getElementById('shop-search');
                if (newSearchInput && currentSearchInput && document.activeElement !== currentSearchInput) {
                    currentSearchInput.value = newSearchInput.value;
                }

                if (pushState) {
                    const stateUrl = new URL(url, window.location.origin + window.location.pathname);
                    <?php if ($isTeamMode): ?>
                    stateUrl.searchParams.set('team_id', '<?php echo $teamId; ?>');
                    <?php endif; ?>
                    window.history.pushState({}, '', stateUrl.href);
                }

                processImagesForColor();
                if (typeof renderBlurHashes === 'function') renderBlurHashes();
            })
            .catch(err => {
                console.error("Error loading shop data:", err);
                if (grid) grid.style.opacity = '1';
            });
    }

    function toggleSortDirection() {
        const urlParams = new URLSearchParams(window.location.search);
        const currentDir = urlParams.get('dir') || 'asc';
        urlParams.set('dir', currentDir === 'asc' ? 'desc' : 'asc');
        <?php if ($isTeamMode): ?>
        urlParams.set('team_id', '<?php echo $teamId; ?>');
        <?php endif; ?>
        loadShopData(`?${urlParams.toString()}`);
    }

    function updateSort(value) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('sort', value);
        if (!urlParams.has('dir')) {
            urlParams.set('dir', 'asc');
        }
        <?php if ($isTeamMode): ?>
        urlParams.set('team_id', '<?php echo $teamId; ?>');
        <?php endif; ?>
        loadShopData(`?${urlParams.toString()}`);
    }

    function updateSearch(value) {
        const urlParams = new URLSearchParams(window.location.search);
        if (value.trim() === '') {
            urlParams.delete('search');
        } else {
            urlParams.set('search', value);
        }
        <?php if ($isTeamMode): ?>
        urlParams.set('team_id', '<?php echo $teamId; ?>');
        <?php endif; ?>
        loadShopData(`?${urlParams.toString()}`);
    }

    function updateMarket(value) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('market', value);
        <?php if ($isTeamMode): ?>
        urlParams.set('team_id', '<?php echo $teamId; ?>');
        <?php endif; ?>
        loadShopData(`?${urlParams.toString()}`);
    }

    window.addEventListener('popstate', () => {
        loadShopData(window.location.href, false);
    });

    function processImagesForColor() {
        document.querySelectorAll('.inv-card').forEach(card => {
            if (card.dataset.processedColor) return;

            const img = card.querySelector('.shop-item-img');
            const priceDiv = card.querySelector('.shop-item-price-bg');
            if (!img || !priceDiv) return;
            
            if (img.src.startsWith('data:')) return;

            card.dataset.processedColor = "true";

            const processColor = () => {
                const canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth;
                canvas.height = img.naturalHeight;
                if (canvas.width === 0 || canvas.height === 0) return;
                
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(img, 0, 0);
                
                const x = Math.floor(canvas.width / 2);
                const y = canvas.height - 1;
                
                let pixel;
                try {
                    pixel = ctx.getImageData(x, y, 1, 1).data;
                } catch (e) {
                    return; // Ignore cross-origin errors if any
                }
                
                const r = pixel[0];
                const g = pixel[1];
                const b = pixel[2];
                const a = pixel[3] / 255;
                
                const outAlpha = a + 0.4 * (1 - a);
                let outR = 0, outG = 0, outB = 0;
                
                if (outAlpha > 0) {
                    outR = Math.round((r * a) / outAlpha);
                    outG = Math.round((g * a) / outAlpha);
                    outB = Math.round((b * a) / outAlpha);
                }
                
                priceDiv.style.backgroundColor = `rgba(${outR}, ${outG}, ${outB}, ${outAlpha})`;
            };

            if (img.complete && img.naturalHeight !== 0) {
                processColor();
            } else {
                img.addEventListener('load', processColor);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        processImagesForColor();

        const filtersContainer = document.querySelector('.shop-filters-container');
        if (filtersContainer) {
            filtersContainer.addEventListener('click', (e) => {
                const btn = e.target.closest('.shop-filter-btn, .shop-subcat-btn');
                if (btn && btn.tagName === 'A') {
                    e.preventDefault();
                    if (btn.classList.contains('disabled')) return;
                    loadShopData(btn.getAttribute('href'));
                }
            });
        }

        // Momentum Drag Scrolling
        const shopContainer = document.querySelector('.shop-preview-container');
        if (shopContainer) {
            let isDown = false;
            let startY;
            let velocity = 0;
            let lastY;
            let lastTimestamp;
            let momentumID;
            let isDragging = false;

            shopContainer.addEventListener('mousedown', (e) => {
                isDown = true;
                isDragging = false;
                shopContainer.classList.add('is-grabbing');
                startY = e.clientY;
                lastY = e.clientY;
                lastTimestamp = Date.now();
                cancelAnimationFrame(momentumID);
            });

            shopContainer.addEventListener('mouseleave', () => {
                if (!isDown) return;
                isDown = false;
                shopContainer.classList.remove('is-grabbing');
                beginMomentumTracking();
            });

            shopContainer.addEventListener('mouseup', () => {
                if (!isDown) return;
                isDown = false;
                shopContainer.classList.remove('is-grabbing');
                beginMomentumTracking();
            });

            shopContainer.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                
                const dy = e.clientY - lastY;
                
                // If we've moved more than 5px from start, mark as dragging to prevent accidental clicks
                if (Math.abs(e.clientY - startY) > 5) {
                    isDragging = true;
                }

                shopContainer.scrollTop -= dy;

                const now = Date.now();
                const elapsed = now - lastTimestamp;
                if (elapsed > 0) {
                    velocity = dy / elapsed;
                }
                
                lastY = e.clientY;
                lastTimestamp = now;
            });

            // Prevent accidental clicks on items if the user was just dragging
            shopContainer.addEventListener('click', (e) => {
                if (isDragging) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }, { capture: true });

            function beginMomentumTracking() {
                let amplitude = velocity * 15; // Speed multiplier
                
                function updateMomentum() {
                    if (Math.abs(amplitude) < 0.5) return; // Stop threshold
                    
                    shopContainer.scrollTop -= amplitude;
                    amplitude *= 0.95; // Friction
                    
                    momentumID = requestAnimationFrame(updateMomentum);
                }
                
                momentumID = requestAnimationFrame(updateMomentum);
            }
        }
    });
</script>

<script src="../includes/fast-blurhash.js"></script>
<script>
function renderBlurHashes() {
    document.querySelectorAll('.shop-item-blurhash').forEach(canvas => {
        if (canvas.dataset.rendered) return;
        const hash = canvas.dataset.hash;
        if (hash && typeof decodeBlurHash === 'function') {
            try {
                const pixels = decodeBlurHash(hash, 75, 48);
                const ctx = canvas.getContext('2d');
                const imageData = ctx.createImageData(75, 48);
                imageData.data.set(pixels);
                ctx.putImageData(imageData, 0, 0);
                canvas.dataset.rendered = "true";
            } catch (e) {
                console.error("Error decoding blurhash", e);
            }
        }
    });

    setTimeout(() => {
        document.querySelectorAll('.shop-item-img').forEach(img => {
            if (img.dataset.src && img.src !== img.dataset.src) {
                img.src = img.dataset.src;
                img.addEventListener('load', () => {
                    if (typeof processImagesForColor === 'function') {
                        processImagesForColor();
                    }
                }, { once: true });
            }
        });
    }, 10);
}
document.addEventListener('DOMContentLoaded', renderBlurHashes);
</script>

</div>



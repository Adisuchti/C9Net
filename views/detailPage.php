<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';
$dbBaseUrl = '../db';

require_once '../includes/imageHelper.php';
$hasMedication = getMedicationStatus($pdo, $_SESSION['user_id']);

// Get the item ID from URL parameter
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$item = null;
$IsMarketEnabled = false;
$market = null;
$imagePath = "";
$defaultImage = "";
$compatibleItemList = [];
$interchangeableOptions = [];
$currentTier = 1;
$buyQuantity = 1;

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

        if ($userProfile && ($userProfile['Assignment'] == $teamId || $activeTeam['leader_player_id'] == $userProfile['Profile_Id'])) {
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

$currentMoney = $isTeamAuthorized ? $teamMoney : ($_SESSION['inventory_money'] ?? 0);
$currentInventoryId = $isTeamAuthorized ? $activeTeam['team_inventory_id'] : ($_SESSION['inventory_id'] ?? 0);

// Fetch basic item info
$query = "SELECT market.Market_item_Id, market.Market_Item_Class, ifnull(items.Item_Display_Name, market.Market_Item_Class) AS Item_Display_Name, market.Purchase_Price, market.Selling_Price, 
    custom_item_types.Custom_Item_Type, market.Available_Quantity, market.market_description, market.tier, market.Compatible_Items, market.DLC, market.Market, market.Visible,
    market_subcategories.SubCategory_Name, market.Ammo_Count
    FROM market
    LEFT JOIN item_types ON item_types.Item_Type_Id COLLATE utf8mb4_general_ci = market.Market_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN custom_item_types ON item_types.item_classification COLLATE utf8mb4_general_ci = custom_item_types.Original_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN items ON market.Market_Item_Class COLLATE utf8mb4_general_ci = items.Item_Class COLLATE utf8mb4_general_ci
    LEFT JOIN market_subcategories ON market.SubCategory_Id = market_subcategories.Id
    WHERE market.Market_item_Id = ?";

$stmt = $pdo->prepare($query);
$stmt->execute([$itemId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header('Location: shop.php');
    exit();
}

if ($item['Custom_Item_Type'] === 'Echelon Support' && !$isTeamAuthorized) {
    $redirectUrl = 'shop.php';
    if (isset($_GET['team_id'])) {
        $redirectUrl .= '?team_id=' . (int)$_GET['team_id'];
    }
    header('Location: ' . $redirectUrl);
    exit();
}

$imgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $item['Market_Item_Class'], $item['Custom_Item_Type'] ?? 'UNKNOWN');
$imagePath = $imgPaths['imagePath'];
$defaultImage = $imgPaths['defaultImage'];
$fileCheckPath = $imgPaths['fileCheckPath'];

// Check market enabled status
$marketEnabledQuery = "SELECT Var_Id, Var_Name, Var_Value FROM `condition_variables` WHERE Var_Name = 'Market_Enabled'";
$marketEnabledStmt = $pdo->query($marketEnabledQuery);
$marketEnabled = $marketEnabledStmt->fetch(PDO::FETCH_ASSOC);
$_SESSION['MarketEnabled'] = $marketEnabled['Var_Value'];
$IsMarketEnabled = ($_SESSION['MarketEnabled'] == 1);

// Check visibility permissions
if($item["Visible"] == 0 && $_SESSION['user_id'] != -1) {
    header('Location: shop.php');
    exit();
}

// Get market info
$marketQuery = "SELECT Id, Name FROM markets WHERE Id = ?";
$marketStmt = $pdo->prepare($marketQuery);
$marketStmt->execute([$item['Market']]);
$market = $marketStmt->fetch(PDO::FETCH_ASSOC);

// Get DLC info
$dlcs = [];
if ($item['DLC']) {
    $dlcQuery = "SELECT Id, Name FROM dlcs WHERE Id = ?;";
    $dlcStmt = $pdo->prepare($dlcQuery);
    $dlcStmt->execute([$item['DLC']]);
    $dlcs = $dlcStmt->fetchAll(PDO::FETCH_ASSOC);
}

$tier = $item['tier'] ?? 0;

// Get compatible items
if (!empty($item['Compatible_Items'])) {
    $compatibleItems = explode(';', $item['Compatible_Items']);
    foreach ($compatibleItems as $compatibleItem) {
        $compatibleItemsQuery = "SELECT market.Market_item_Id, market.Market_Item_Class, ifnull(items.Item_Display_Name, market.Market_Item_Class) AS Item_Display_Name, market.Purchase_Price, market.Selling_Price,
        custom_item_types.Custom_Item_Type, market.Available_Quantity, market.market_description, market.tier, market.Ammo_Count
        FROM market
        LEFT JOIN item_types ON item_types.Item_Type_Id COLLATE utf8mb4_general_ci = market.Market_Item_Type COLLATE utf8mb4_general_ci
        LEFT JOIN custom_item_types ON item_types.item_classification COLLATE utf8mb4_general_ci = custom_item_types.Original_Item_Type COLLATE utf8mb4_general_ci
        LEFT JOIN items ON market.Market_Item_Class COLLATE utf8mb4_general_ci = items.Item_Class COLLATE utf8mb4_general_ci
        WHERE market.Market_Item_Class = ?";
        $compatibleItemStmt = $pdo->prepare($compatibleItemsQuery);
        $compatibleItemStmt->execute([trim($compatibleItem)]);
        $compatibleItemFetch = $compatibleItemStmt->fetch(PDO::FETCH_ASSOC);
        if ($compatibleItemFetch) {
            array_push($compatibleItemList, [$compatibleItemFetch['Market_item_Id'], $compatibleItemFetch['Market_Item_Class'], $compatibleItemFetch['Item_Display_Name'], $compatibleItemFetch['Custom_Item_Type'], $compatibleItemFetch['Ammo_Count']]);
        }
    }
}

$compatibleWithList = [];
$compatibleWithQuery = "SELECT market.Market_item_Id, market.Market_Item_Class, ifnull(items.Item_Display_Name, market.Market_Item_Class) AS Item_Display_Name, market.Purchase_Price, market.Selling_Price,
custom_item_types.Custom_Item_Type, market.Available_Quantity, market.market_description, market.tier, market.Ammo_Count
FROM market
LEFT JOIN item_types ON item_types.Item_Type_Id COLLATE utf8mb4_general_ci = market.Market_Item_Type COLLATE utf8mb4_general_ci
LEFT JOIN custom_item_types ON item_types.item_classification COLLATE utf8mb4_general_ci = custom_item_types.Original_Item_Type COLLATE utf8mb4_general_ci
LEFT JOIN items ON market.Market_Item_Class COLLATE utf8mb4_general_ci = items.Item_Class COLLATE utf8mb4_general_ci
WHERE market.Compatible_Items LIKE ? OR market.Compatible_Items LIKE ? OR market.Compatible_Items LIKE ? OR market.Compatible_Items = ?";
$compatibleWithStmt = $pdo->prepare($compatibleWithQuery);
$searchClass = $item['Market_Item_Class'];
$compatibleWithStmt->execute([
    "%;$searchClass;%",
    "$searchClass;%",
    "%;$searchClass",
    $searchClass
]);
$compatibleWithFetch = $compatibleWithStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($compatibleWithFetch as $cw) {
    array_push($compatibleWithList, [$cw['Market_item_Id'], $cw['Market_Item_Class'], $cw['Item_Display_Name'], $cw['Custom_Item_Type'], $cw['Ammo_Count']]);
}

// Interchangable options (similar to original logic)
try {
    $normalizeClass = static function (?string $value): string {
        return strtoupper(trim((string)$value));
    };
    $sourceClass = trim((string)$item['Market_Item_Class']);
    $sourceKey = $normalizeClass($sourceClass);
    $edges = [];

    $allLegacyQuery = "SELECT Base_Item_Class as source, Interchangable_Item_Class as target, Change_Cost as cost
                       FROM interchangable_items
                       WHERE Base_Item_Class != Interchangable_Item_Class
                       UNION
                       SELECT Interchangable_Item_Class as source, Base_Item_Class as target, Change_Cost as cost
                       FROM interchangable_items
                       WHERE Base_Item_Class != Interchangable_Item_Class";
    $allLegacyStmt = $pdo->prepare($allLegacyQuery);
    $allLegacyStmt->execute();
    $allLegacyEdges = $allLegacyStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allLegacyEdges as $edge) {
        $source = $normalizeClass($edge['source']);
        $target = $normalizeClass($edge['target']);
        $cost = (float)$edge['cost'];
        if ($source === '' || $target === '') continue;
        if (!isset($edges[$source])) $edges[$source] = [];
        if (!isset($edges[$source][$target]) || $edges[$source][$target] > $cost) {
            $edges[$source][$target] = $cost;
        }
    }

    try {
        $directedQuery = "SELECT Source_Item_Class as source, Target_Item_Class as target, Change_Cost as cost
                          FROM interchangeable_item_routes";
        $directedStmt = $pdo->prepare($directedQuery);
        $directedStmt->execute();
        $directedEdges = $directedStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($directedEdges as $edge) {
            $source = $normalizeClass($edge['source']);
            $target = $normalizeClass($edge['target']);
            $cost = (float)$edge['cost'];
            if ($source === '' || $target === '') continue;
            if (!isset($edges[$source])) $edges[$source] = [];
            if (!isset($edges[$source][$target]) || $edges[$source][$target] > $cost) {
                $edges[$source][$target] = $cost;
            }
        }
    } catch (Exception $ignored) {}

    $itemsStmt = $pdo->prepare("SELECT item_class, IFNULL(Item_Display_Name, item_class) as display_name FROM items");
    $itemsStmt->execute();
    $itemsList = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $itemDisplayMap = [];
    $itemClassMap = [];
    foreach ($itemsList as $listItem) {
        $key = $normalizeClass($listItem['item_class']);
        $itemDisplayMap[$key] = $listItem['display_name'];
        $itemClassMap[$key] = $listItem['item_class'];
    }

    if (!isset($edges[$sourceKey]) && isset($itemClassMap[$sourceKey])) {
        $sourceKey = $normalizeClass($itemClassMap[$sourceKey]);
    }

    $distances = [$sourceKey => 0];
    $visited = [];
    $pq = [[$sourceKey, 0]];

    while (!empty($pq)) {
        usort($pq, fn($a, $b) => $a[1] <=> $b[1]);
        [$current, $currentDist] = array_shift($pq);

        if (isset($visited[$current])) continue;
        $visited[$current] = true;

        if (isset($edges[$current])) {
            foreach ($edges[$current] as $neighbor => $edgeCost) {
                $newDist = $currentDist + $edgeCost;
                if (!isset($distances[$neighbor]) || $newDist < $distances[$neighbor]) {
                    $distances[$neighbor] = $newDist;
                    $pq[] = [$neighbor, $newDist];
                }
            }
        }
    }

    foreach ($distances as $targetKey => $cost) {
        if ($targetKey === $sourceKey) continue;
        $interchangeableOptions[] = [
            'item_class' => $itemClassMap[$targetKey] ?? $targetKey,
            'display_name' => $itemDisplayMap[$targetKey] ?? $targetKey,
            'cost' => (int)$cost
        ];
    }
} catch (Exception $ignored) {
    $interchangeableOptions = [];
}

// Get current market tier
$currentTierQuery = "SELECT Var_Id, Var_Name, Var_Value FROM `condition_variables` WHERE Var_Name = 'Current_Tier'";
$currentTierStmt = $pdo->query($currentTierQuery);
$currentTierData = $currentTierStmt->fetch(PDO::FETCH_ASSOC);
if ($currentTierData) {
    $currentTier = $currentTierData['Var_Value'];
}

if ($tier > $currentTier) {
    header('Location: shop.php');
    exit();
}

// Include header
include '../includes/header.php';
?>

<div class="detailPage-main-wrapper">
    <?php if ($isTeamMode): ?>
    <div class="detailPage-team-banner">
        <h2 class="detailPage-team-banner-title">
            Team Shop: <?php echo htmlspecialchars($activeTeam['Fireteam_Name']); ?>
        </h2>
        <div class="detailPage-team-banner-amount-container">
            <?php if ($isTeamAuthorized): ?>
            <span class="detailPage-team-banner-amount-auth">
                <?php echo number_format($teamMoney, 2, ".", "'"); ?> Cr
            </span>
            <?php else: ?>
            <span class="detailPage-team-banner-amount-unauth">
                Unauthorized
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="detailPage-scroll-container">
        <div class="detailPage-main-container">
    <!-- Left Pane: Dynamic -->
    <div id="left-pane" class="detailPage-left-pane">
        <div class="detailPage-back-wrapper">
            <a id="back-btn" href="shop.php<?php echo $isTeamMode ? '?team_id=' . $teamId : ''; ?>" class="btn-industrial detailPage-back-btn">← Back to Market</a>
        </div>
        <div class="detailPage-image-wrapper">
            <img id="preview-image" src="<?php echo file_exists($fileCheckPath) ? $imagePath : $defaultImage; ?>" alt="<?php echo htmlspecialchars($item['Item_Display_Name']); ?>" class="detailPage-image" onload="if(typeof adjustLeftPane === 'function') adjustLeftPane();">
        </div>
    </div>
    
    <!-- Right Pane: Flex Fill -->
    <div class="detailPage-right-pane">
        <div class="detailPage-content-wrapper">
            <h2 class="detailPage-title"><?php echo htmlspecialchars($item['Item_Display_Name']); ?></h2>
            
            <div class="detailPage-info-grid">
                <div class="detail-info-row">
                    <span class="detailPage-info-label">Class</span>
                    <span class="detailPage-info-value"><?php echo htmlspecialchars($item['Market_Item_Class']); ?></span>
                </div>
                <div class="detail-info-row">
                    <span class="detailPage-info-label">Type</span>
                    <span class="detailPage-info-value"><?php echo htmlspecialchars($item['Custom_Item_Type'] ?? 'Unknown'); ?></span>
                </div>
                <?php if (!empty($item['SubCategory_Name'])): ?>
                <div class="detail-info-row">
                    <span class="detailPage-info-label">Subcategory</span>
                    <span class="detailPage-info-value"><?php echo htmlspecialchars($item['SubCategory_Name']); ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-info-row">
                    <span class="detailPage-info-label">Purchase Price</span>
                    <span class="detailPage-info-price"><?php echo number_format($item['Purchase_Price'], 2, '.', "'"); ?> Cr</span>
                </div>
                <div class="detail-info-row">
                    <span class="detailPage-info-label">Tier</span>
                    <span class="detailPage-info-value"><?php echo number_format($item['tier']); ?></span>
                </div>
                <?php if (!empty($item['Ammo_Count']) && $item['Ammo_Count'] > 0): ?>
                <div class="detail-info-row">
                    <span class="detailPage-info-label">Capacity</span>
                    <span class="detailPage-info-value"><?php echo (int)$item['Ammo_Count']; ?> Rnd</span>
                </div>
                <?php endif; ?>
                <div class="detail-info-row">
                    <span class="detailPage-info-label">Available</span>
                    <span class="detailPage-info-value"><?php echo $item['Available_Quantity'] == -1 ? 'Unlimited' : number_format($item['Available_Quantity']); ?></span>
                </div>
            </div>
            
            <!-- Purchase Form -->
            <div class="detailPage-form-wrapper">
                <form id="buyForm" class="detailPage-form">
                    <div class="detailPage-quantity-wrapper">
                        <label for="quantity" class="detailPage-quantity-label">Quantity</label>
                        <div class="detailPage-quantity-controls">
                            <button type="button" class="quantity-btn minus-btn" onclick="changeQuantity(-1)">&minus;</button>
                            <input type="number" id="quantity" name="quantity" min="1" 
                                max="<?php echo $item['Available_Quantity'] == -1 ? 1000 : $item['Available_Quantity']; ?>"
                                value="1"
                                onchange="updateBuyForm()"
                                oninput="updateBuyForm()"
                                class="detailPage-quantity-input">
                            <button type="button" class="quantity-btn plus-btn" onclick="changeQuantity(1)">&plus;</button>
                        </div>
                    </div>
                    
                    <div class="detailPage-cost-wrapper">
                        <span class="detailPage-cost-label">Total Cost</span>
                        <div id="totalCost" class="detailPage-cost-value"><?php echo number_format($item['Purchase_Price'], 2, '.', "'"); ?> Cr</div>
                    </div>
                    
                    <?php
                    $maxAffordableQuantity = floor($currentMoney / $item['Purchase_Price']);
                    $maxQuantity = $item['Available_Quantity'] == -1 ? 1000 : $item['Available_Quantity'];
                    $actualMax = min($maxAffordableQuantity, $maxQuantity);
                    
                    $isDisabled = "";
                    if ($item['Available_Quantity'] == 0 || 
                        ($item['Available_Quantity'] != -1 && $buyQuantity > $item['Available_Quantity']) || 
                        ($buyQuantity * $item['Purchase_Price'] > $currentMoney) || 
                        ($item['tier'] > $currentTier) || 
                        !$IsMarketEnabled || 
                        $item['Market'] != 0 || 
                        !$isTeamAuthorized) {
                        $isDisabled = "disabled";
                    }
                    ?>
                    
                    <div class="detailPage-buy-wrapper">
                        <button type="button" id="buyButton" class="btn-industrial success detailPage-buy-btn <?php echo $isDisabled ? 'disabled' : ''; ?>" 
                            data-price="<?php echo $item['Purchase_Price']; ?>"
                            data-money="<?php echo $currentMoney; ?>"
                            data-available="<?php echo $item['Available_Quantity']; ?>"
                            <?php echo $isDisabled; ?>
                            onclick="buyItem()">
                            Buy
                        </button>
                    </div>
                </form>
            </div>
            
            <?php if (!empty($item['market_description'])): ?>
                <div class="detailPage-section">
                    <h3 class="detailPage-section-title">Description</h3>
                    <p class="detailPage-section-desc"><?php echo nl2br(htmlspecialchars($item['market_description'])); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($item['DLC'] && !empty($dlcs)): ?>
                <?php
                $dlcIconPath = $imageBaseUrl . "/dlc/" . $item['DLC'] . ".png";
                $dlcFileCheckPath = __DIR__ . "/../images/dlc/" . $item['DLC'] . ".png";
                ?>
                <div class="detailPage-section detailPage-dlc-section">
                    <h3 class="detailPage-section-title-nomargin">Requires DLC</h3>
                    <div class="detailPage-dlc-content">
                        <?php if (file_exists($dlcFileCheckPath)): ?>
                            <img src="<?php echo $dlcIconPath; ?>" alt="DLC Icon" class="detailPage-dlc-icon">
                        <?php endif; ?>
                        <span class="detailPage-dlc-name"><?php echo htmlspecialchars($dlcs[0]['Name']); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($compatibleItemList)): ?>
                <?php
                $groupedCompatItems = [];
                foreach ($compatibleItemList as $compat) {
                    $type = $compat[3] ?? 'Unknown';
                    if (!isset($groupedCompatItems[$type])) {
                        $groupedCompatItems[$type] = [];
                    }
                    $groupedCompatItems[$type][] = $compat;
                }
                
                $orderedCompatGroups = [];
                foreach ($groupedCompatItems as $cat => $items) {
                    if ($cat !== 'Unknown') {
                        $orderedCompatGroups[$cat] = $items;
                    }
                }
                if (isset($groupedCompatItems['Unknown'])) {
                    $orderedCompatGroups['Unknown'] = $groupedCompatItems['Unknown'];
                }
                $groupedCompatItems = $orderedCompatGroups;
                ?>
                <div class="detailPage-section">
                    <h3 class="detailPage-section-title detailPage-section-title-flex">
                        Compatible Items
                        <small class="detailPage-tooltip">ⓘ Not all compatible items are listed</small>
                    </h3>
                    
                    <div class="inv-categories-wrapper detailPage-categories-wrapper-extra">
                        <div class="inv-categories detailPage-categories-extra">
                            <?php 
                            $firstCategory = true;
                            foreach ($groupedCompatItems as $type => $typeItems): 
                                $iconName = strtolower(str_replace(' ', '_', $type));
                                $iconPath = __DIR__ . '/../images/icons/categories/' . $iconName . '.svg';
                            ?>
                                <button type="button" class="inv-category-btn detail-compat-cat-btn <?php echo $firstCategory ? 'active' : ''; ?>" data-target="compat-cat-<?php echo htmlspecialchars($type); ?>" onclick="switchCompatCategory(this)" title="<?php echo htmlspecialchars($type); ?>">
                                    <?php 
                                    if (file_exists($iconPath)) {
                                        include $iconPath;
                                    } else {
                                        include __DIR__ . '/../images/icons/categories/unknown.svg';
                                    }
                                    ?>
                                </button>
                            <?php 
                            $firstCategory = false;
                            endforeach; 
                            ?>
                        </div>
                    </div>
                    
                    <div class="detailPage-compat-containers-wrapper detailPage-compat-containers-extra">
                        <?php 
                        $firstCategory = true;
                        foreach ($groupedCompatItems as $type => $typeItems): 
                        ?>
                            <div class="inv-items-container is-weapon-container detail-compat-container <?php echo $firstCategory ? 'active-container' : ''; ?> detailPage-compat-horizontal" id="compat-cat-<?php echo htmlspecialchars($type); ?>">
                                <?php foreach ($typeItems as $compat): ?>
                                    <a href="detailPage.php?id=<?php echo $compat[0]; ?>" class="detailPage-compat-card detailPage-compat-card-horizontal">
                                        <div class="detailPage-compat-icon-wrap">
                                            <?php 
                                                $cImgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $compat[1], $compat[3]);
                                                $cImg = $cImgPaths['imagePath'];
                                                $cDef = $cImgPaths['defaultImage'];
                                                $cCheck = $cImgPaths['fileCheckPath'];
                                            ?>
                                            <img src="<?php echo file_exists($cCheck) ? $cImg : $cDef; ?>" class="detailPage-compat-img" title="<?php echo htmlspecialchars($compat[2]); ?>">
                                            <?php if (!empty($compat[4]) && $compat[4] > 0): ?>
                                                <div class="inv-card-ammo-badge-sm" style="font-size: 10px; bottom: 4px; left: 4px; right: auto;">Ammo: <?php echo (int)$compat[4]; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php 
                        $firstCategory = false;
                        endforeach; 
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($compatibleWithList)): ?>
                <?php
                $groupedCompatWithItems = [];
                foreach ($compatibleWithList as $compat) {
                    $type = $compat[3] ?? 'Unknown';
                    if (!isset($groupedCompatWithItems[$type])) {
                        $groupedCompatWithItems[$type] = [];
                    }
                    $groupedCompatWithItems[$type][] = $compat;
                }
                
                $orderedCompatWithGroups = [];
                foreach ($groupedCompatWithItems as $cat => $items) {
                    if ($cat !== 'Unknown') {
                        $orderedCompatWithGroups[$cat] = $items;
                    }
                }
                if (isset($groupedCompatWithItems['Unknown'])) {
                    $orderedCompatWithGroups['Unknown'] = $groupedCompatWithItems['Unknown'];
                }
                $groupedCompatWithItems = $orderedCompatWithGroups;
                ?>
                <div class="detailPage-section">
                    <h3 class="detailPage-section-title detailPage-section-title-flex">
                        Compatible With
                        <small class="detailPage-tooltip">ⓘ Items this item can be used with</small>
                    </h3>
                    
                    <div class="inv-categories-wrapper detailPage-categories-wrapper-extra">
                        <div class="inv-categories detailPage-categories-extra">
                            <?php 
                            $firstCategory = true;
                            foreach ($groupedCompatWithItems as $type => $typeItems): 
                                $iconName = strtolower(str_replace(' ', '_', $type));
                                $iconPath = __DIR__ . '/../images/icons/categories/' . $iconName . '.svg';
                            ?>
                                <button type="button" class="inv-category-btn detail-compat-cat-btn <?php echo $firstCategory ? 'active' : ''; ?>" data-target="compat-with-cat-<?php echo htmlspecialchars($type); ?>" onclick="switchCompatCategory(this)" title="<?php echo htmlspecialchars($type); ?>">
                                    <?php 
                                    if (file_exists($iconPath)) {
                                        include $iconPath;
                                    } else {
                                        include __DIR__ . '/../images/icons/categories/unknown.svg';
                                    }
                                    ?>
                                </button>
                            <?php 
                            $firstCategory = false;
                            endforeach; 
                            ?>
                        </div>
                    </div>
                    
                    <div class="detailPage-compat-containers-wrapper detailPage-compat-containers-extra">
                        <?php 
                        $firstCategory = true;
                        foreach ($groupedCompatWithItems as $type => $typeItems): 
                        ?>
                            <div class="inv-items-container is-weapon-container detail-compat-container <?php echo $firstCategory ? 'active-container' : ''; ?> detailPage-compat-horizontal" id="compat-with-cat-<?php echo htmlspecialchars($type); ?>">
                                <?php foreach ($typeItems as $compat): ?>
                                    <a href="detailPage.php?id=<?php echo $compat[0]; ?>" class="detailPage-compat-card detailPage-compat-card-horizontal">
                                        <div class="detailPage-compat-icon-wrap">
                                            <?php 
                                                $cImgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $compat[1], $compat[3]);
                                                $cImg = $cImgPaths['imagePath'];
                                                $cDef = $cImgPaths['defaultImage'];
                                                $cCheck = $cImgPaths['fileCheckPath'];
                                            ?>
                                            <img src="<?php echo file_exists($cCheck) ? $cImg : $cDef; ?>" class="detailPage-compat-img" title="<?php echo htmlspecialchars($compat[2]); ?>" loading="lazy">
                                            <?php if (!empty($compat[4]) && $compat[4] > 0): ?>
                                                <div class="inv-card-ammo-badge-sm" style="font-size: 10px; bottom: 4px; left: 4px; right: auto;">Ammo: <?php echo (int)$compat[4]; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php 
                        $firstCategory = false;
                        endforeach; 
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($interchangeableOptions)): ?>
                <div class="detailPage-section">
                    <h3 class="detailPage-section-title detailPage-section-title-flex">
                        Modifiable Into
                        <small class="detailPage-tooltip">ⓘ Items you can convert this weapon into</small>
                    </h3>
                    <div class="inv-items-container is-weapon-container active-container detail-compat-container detailPage-mod-options-container">
                        <?php foreach ($interchangeableOptions as $option): ?>
                            <div class="detailPage-compat-card detailPage-mod-option-card">
                                <div class="detailPage-compat-icon-wrap" title="<?php echo htmlspecialchars($option['display_name']); ?>">
                                    <?php 
                                        $mImgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $option['item_class']);
                                        $mImg = $mImgPaths['imagePath'];
                                        $mCheck = $mImgPaths['fileCheckPath'];
                                        $mDef = $imageBaseUrl . "/items/UNKNOWN.PNG";
                                    ?>
                                    <img src="<?php echo file_exists($mCheck) ? $mImg : $mDef; ?>" class="detailPage-compat-img">
                                </div>
                                <div class="detailPage-mod-option-info">
                                    <span class="detailPage-mod-option-cost-label">Cost: <span class="detailPage-mod-option-cost-val"><?php echo number_format((int)$option['cost'], 2, '.', "'"); ?> Cr</span></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Not implementing itemModificationGraph.php in preview yet, so we'll omit the link or keep it pointed to the old one -->
                    <p class="detailPage-link-wrap">
                        <a href="../views/itemModificationGraph.php" class="detailPage-full-mod-link">View Full Modification Graph →</a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>



<script>
    function changeQuantity(amount) {
        const input = document.getElementById('quantity');
        let currentVal = parseInt(input.value) || 1;
        let max = parseInt(input.getAttribute('max')) || 1000;
        let min = parseInt(input.getAttribute('min')) || 1;
        
        let newVal = currentVal + amount;
        if (newVal > max) newVal = max;
        if (newVal < min) newVal = min;
        
        input.value = newVal;
        updateBuyForm();
    }

    function updateBuyForm() {
        const quantityInput = document.getElementById('quantity');
        const quantity = parseInt(quantityInput.value) || 1;
        
        const buyButton = document.getElementById('buyButton');
        const price = parseFloat(buyButton.getAttribute('data-price'));
        const money = parseFloat(buyButton.getAttribute('data-money'));
        const available = parseInt(buyButton.getAttribute('data-available'));
        
        // Calculate total cost
        const totalCost = quantity * price;
        const totalCostEl = document.getElementById('totalCost');
        
        // Format with commas
        let formattedCost = totalCost.toFixed(2).split('.');
        formattedCost[0] = formattedCost[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
        totalCostEl.textContent = formattedCost.join('.') + " Cr";
        
        // Check if purchase is possible
        const isMarketEnabled = <?php echo $IsMarketEnabled ? 'true' : 'false'; ?>;
        const isMarketZero = <?php echo $item['Market'] == 0 ? 'true' : 'false'; ?>;
        const tierAllowed = <?php echo $item['tier'] <= $currentTier ? 'true' : 'false'; ?>;
        
        const isDisabled = 
            (!isMarketEnabled) || 
            (!isMarketZero) ||
            (!tierAllowed) ||
            (available === 0) || 
            (available !== -1 && quantity > available) || 
            (totalCost > money) ||
            (quantity < 1);
        
        // Update button state and cost display
        buyButton.disabled = isDisabled;
        
        if (isDisabled) {
            buyButton.style.opacity = '0.5';
            buyButton.style.filter = 'grayscale(1)';
            buyButton.style.cursor = 'not-allowed';
        } else {
            buyButton.style.opacity = '1';
            buyButton.style.filter = 'none';
            buyButton.style.cursor = 'pointer';
        }
        
        if (totalCost > money) {
            totalCostEl.classList.add('cost-exceed');
        } else {
            totalCostEl.classList.remove('cost-exceed');
        }
    }

    function buyItem() {
        const quantity = document.getElementById('quantity').value;
        const data = {
            quantity: quantity,
            itemId: <?php echo json_encode($itemId); ?>,
            inventoryId: <?php echo json_encode($currentInventoryId); ?>
        };
        <?php if ($isTeamMode): ?>
        data.teamId = <?php echo json_encode($teamId); ?>;
        <?php endif; ?>

        const buyButton = document.getElementById('buyButton');
        const originalText = buyButton.textContent;
        buyButton.disabled = true;
        buyButton.textContent = 'Processing...';

        fetch("<?php echo $dbBaseUrl; ?>/inventory_assets/buyItem.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    alert("Purchase successful!");
                    window.location.reload();
                } else {
                    alert("Error: " + json.error);
                    buyButton.disabled = false;
                    buyButton.textContent = originalText;
                }
            } catch (e) {
                alert("JSON parse error: " + e.message + "\nResponse: " + text);
                buyButton.disabled = false;
                buyButton.textContent = originalText;
            }
        })
        .catch(err => {
            alert("Fetch error: " + err.message);
            buyButton.disabled = false;
            buyButton.textContent = originalText;
        });
    }

    // Initialize display on load
    updateBuyForm();

    // Adjust left pane width to hug the image perfectly
    function adjustLeftPane() {
        const img = document.getElementById('preview-image');
        const btn = document.getElementById('back-btn');
        const pane = document.getElementById('left-pane');
        if (img && btn && pane) {
            // Temporarily clear width to let CSS recalculate its natural fit
            pane.style.width = 'fit-content';
            
            // Read dimensions (this forces synchronous layout, giving us the correct size)
            const imgWidth = img.clientWidth;
            const btnWidth = btn.clientWidth;
            const targetWidth = Math.max(imgWidth, btnWidth) + 40; // 40px padding
            
            // Re-apply the hard width
            pane.style.width = targetWidth + 'px';
        }
    }

    window.addEventListener('load', adjustLeftPane);
    window.addEventListener('resize', adjustLeftPane);
    if (document.getElementById('preview-image') && document.getElementById('preview-image').complete) {
        setTimeout(adjustLeftPane, 50);
    }

    function switchCompatCategory(btn) {
        document.querySelectorAll('.detail-compat-cat-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        document.querySelectorAll('.detail-compat-container').forEach(c => c.classList.remove('active-container'));
        
        const targetId = btn.getAttribute('data-target');
        const targetContainer = document.getElementById(targetId);
        if (targetContainer) {
            targetContainer.classList.add('active-container');
        }
    }
</script>

    </div>
</div>

<?php include '../includes/footer.php'; ?>

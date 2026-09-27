<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../db/market_assets/interchangeableGraphSchema.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: ../views/login.php');
    exit();
}

ensureInterchangeableGraphTables($pdo);
require_once '../includes/imageHelper.php';

if ($_SESSION['user_id'] == -1) {
    header('Location: shop.php');
    exit();
}

$hasMedication = getMedicationStatus($pdo, $_SESSION['user_id']);

// Initialize default values for missing keys to prevent warnings
if (!isset($_SESSION['inventory_money'])) $_SESSION['inventory_money'] = 0;
if (!isset($_SESSION['Inventory_market_saturation'])) $_SESSION['Inventory_market_saturation'] = 0;
if (!isset($_SESSION['inventory_name'])) $_SESSION['inventory_name'] = 'Unknown';

$host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';
$dbBaseUrl = '../db';

// Get inventory ID from URL parameter or fall back to user's own inventory
$inventoryId = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['inventory_id'];

// Check if viewing own inventory or someone else's
$isOwnInventory = ($inventoryId === $_SESSION['inventory_id']);
$_SESSION['is_own_inventory'] = $isOwnInventory;

$inventoryName = "";
$inventoryBalance = 0;
$inventoryType = 1;

$inventoryQuery = "SELECT Inventory_Name, Inventory_Money, Inventory_Type FROM inventories WHERE Inventory_Id = ?";
$inventoryStmt = $pdo->prepare($inventoryQuery);
$inventoryStmt->execute([$inventoryId]);
$inventoryData = $inventoryStmt->fetch(PDO::FETCH_ASSOC);
if ($inventoryData) {
    $inventoryName = $inventoryData['Inventory_Name'];
    $inventoryBalance = $inventoryData['Inventory_Money'];
    $inventoryType = $inventoryData['Inventory_Type'];
}

// Fetch user profile for the 30% left pane (owner of the inventory)
$ownerQuery = "SELECT id FROM users WHERE inventory_id = ?";
$ownerStmt = $pdo->prepare($ownerQuery);
$ownerStmt->execute([$inventoryId]);
$ownerId = $ownerStmt->fetchColumn();

if ($ownerId) {
    $profileQuery = "SELECT pp.Profile_Name, pp.Role, pp.Status, pp.Callsign, pp.Profile_Id, th.Fireteam_Name
    FROM player_profiles pp
    LEFT JOIN team_hierarchy th ON pp.Assignment = th.Fireteam_Id
    WHERE pp.User_Id = ?";
    $profileStmt = $pdo->prepare($profileQuery);
    $profileStmt->execute([$ownerId]);
    $userProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
} else {
    $userProfile = null;
}

if (!$userProfile) {
    $userProfile = [
        'Profile_Name' => 'no inventory owner found',
        'Role' => 'Unknown',
        'Status' => 'Unknown',
        'Fireteam_Name' => 'Unassigned',
        'Profile_Id' => 0
    ];
}

// Get current item count per type
$currentCountQuery = "SELECT Custom_Item_Type, SUM(Item_Quantity) AS quantity
FROM (
    SELECT DISTINCT
        content_items.Content_Item_Id,
        custom_item_types.Custom_Item_Type,
        content_items.Item_Quantity
    FROM content_items
    LEFT JOIN items ON items.item_class = content_items.Item_Class
    LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
    LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
    WHERE Inventory_Id = ?
) AS subquery
GROUP BY Custom_Item_Type;";
$currentCountStmt = $pdo->prepare($currentCountQuery);
$currentCountStmt->execute([$inventoryId]);
$currentCounts = $currentCountStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch category limits for this inventory type
$limitsQuery = "SELECT Item_Type, Item_Limit FROM item_type_inventory_limit WHERE Inventory_Type = ?";
$limitsStmt = $pdo->prepare($limitsQuery);
$limitsStmt->execute([$inventoryType]);
$categoryLimits = [];
while ($row = $limitsStmt->fetch(PDO::FETCH_ASSOC)) {
    $categoryLimits[$row['Item_Type']] = (int)$row['Item_Limit'];
}

// Fetch items from the database
$query = "SELECT DISTINCT content_items.Content_Item_Id, content_items.Inventory_Id,
    content_items.Item_Class, content_items.Item_Quantity, content_items.Item_Properties,
    custom_item_types.Custom_Item_Type, IFNULL(items.Item_Display_Name, content_items.Item_Class) as Item_Display_Name
    FROM content_items
    LEFT JOIN items ON items.item_class = content_items.Item_Class
    LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
    LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
    LEFT JOIN item_sorting ON item_sorting.Item_Sorting_Type = item_types.item_classification
    WHERE Inventory_Id = :inventory_id
    ORDER BY item_sorting.Item_Sorting_Number, content_items.Item_Class, content_items.Item_Properties";

$stmt = $pdo->prepare($query);
$stmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

// Group items by Custom_Item_Type
$groupedItems = [];
foreach ($items as $item) {
    $type = $item['Custom_Item_Type'] ?? 'Unknown';
    if (!isset($groupedItems[$type])) {
        $groupedItems[$type] = [];
    }
    
    // Unroll quantities for weapons
    $isWeapon = in_array($type, ['Primary_Weapon', 'Sidearm', 'Launcher', 'Melee', 'T-Doll']);
    
    if ($isWeapon) {
        for ($i = 0; $i < $item['Item_Quantity']; $i++) {
            $clonedItem = $item;
            $clonedItem['Item_Quantity'] = 1;
            $groupedItems[$type][] = $clonedItem;
        }
    } else {
        $groupedItems[$type][] = $item;
    }
}

// Reorder categories to ensure weapons come first, followed by other known categories, and Unknown last
$categoryOrder = ['Primary_Weapon', 'Sidearm', 'Launcher', 'Melee', 'T-Doll', 'Ammo', 'Attachments', 'Equipment', 'Throwables'];
$orderedGroups = [];
foreach ($categoryOrder as $cat) {
    if (isset($groupedItems[$cat])) {
        $orderedGroups[$cat] = $groupedItems[$cat];
        unset($groupedItems[$cat]);
    }
}
// Add any remaining categories except Unknown
foreach ($groupedItems as $cat => $items) {
    if ($cat !== 'Unknown') {
        $orderedGroups[$cat] = $items;
    }
}
// Add Unknown at the very end
if (isset($groupedItems['Unknown'])) {
    $orderedGroups['Unknown'] = $groupedItems['Unknown'];
}
$groupedItems = $orderedGroups;

// Find ammo for weapons
$ammoItems = $groupedItems['Ammo'] ?? [];

// Fetch compatible items mapping from market
$compatQuery = $pdo->query("SELECT Market_Item_Class, Compatible_Items FROM market WHERE Compatible_Items IS NOT NULL AND Compatible_Items != ''");
$compatibleMap = [];
while ($row = $compatQuery->fetch(PDO::FETCH_ASSOC)) {
    $compatibleMap[strtoupper($row['Market_Item_Class'])] = array_map('strtoupper', explode(';', $row['Compatible_Items']));
}

function findCompatibleAmmo($weaponClass, $ammoItems, $compatibleMap) {
    $weaponClass = strtoupper($weaponClass);
    if (!isset($compatibleMap[$weaponClass])) {
        return [];
    }
    
    $compatClasses = $compatibleMap[$weaponClass];
    $foundAmmo = [];
    foreach ($ammoItems as $ammo) {
        if (in_array(strtoupper($ammo['Item_Class']), $compatClasses)) {
            $foundAmmo[] = $ammo;
        }
    }
    return $foundAmmo;
}

// Get inventories for transfer dropdown
$inventoriesQuery = "SELECT Inventory_Id, Inventory_Name from inventories WHERE Inventory_Id != :inventory_id";
$inventoriesStmt = $pdo->prepare($inventoriesQuery);
$inventoriesStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
$inventoriesStmt->execute();
$inventories = $inventoriesStmt->fetchAll();

// Build market link lookup & selling prices
$marketQuery = "SELECT Market_item_Id, Market_Item_Class, Selling_Price, Ammo_Count FROM market WHERE Market = 0";
$marketStmt = $pdo->prepare($marketQuery);
$marketStmt->execute();
$marketItems = $marketStmt->fetchAll();

$marketDataByClass = [];
foreach ($marketItems as $marketItem) {
    $classKey = strtoupper(trim((string)$marketItem['Market_Item_Class']));
    if (!isset($marketDataByClass[$classKey])) {
        $marketDataByClass[$classKey] = [
            'Selling_Price' => $marketItem['Selling_Price'],
            'Market_item_Id' => $marketItem['Market_item_Id'],
            'Ammo_Count' => $marketItem['Ammo_Count']
        ];
    }
}

// Build interchangeable set
$interchangeQuery = "SELECT DISTINCT Base_Item_Class AS Item_Class FROM interchangable_items
                     UNION
                     SELECT DISTINCT Interchangable_Item_Class AS Item_Class FROM interchangable_items
                     UNION
                     SELECT DISTINCT Source_Item_Class AS Item_Class FROM interchangeable_item_routes";
$interchangeStmt = $pdo->prepare($interchangeQuery);
$interchangeStmt->execute();
$interchangeableItems = $interchangeStmt->fetchAll(PDO::FETCH_COLUMN);

$interchangeableSet = [];
foreach ($interchangeableItems as $itemClass) {
    $interchangeableSet[strtoupper(trim((string)$itemClass))] = true;
}

include '../includes/header.php';
// We won't include toast and error for now to keep it clean, unless we need them for functionality.
// If needed, they should be copied to preview/includes too, but we just want the styling overhaul for now.
?>
<div class="inventory-preview-container">
    
    <!-- Left Pane: 30% -->
    <div class="inv-left-pane">
        <!-- User Info Widget -->
        <div class="user-info-widget">
            <?php 
            $profileImage = $imageBaseUrl . "/profiles/profile-" . str_pad($userProfile['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png";
            if (!file_exists(__DIR__ . "/../images/profiles/profile-" . str_pad($userProfile['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png")) {
                $profileImage = $imageBaseUrl . "/profiles/default.png"; // Fallback if no image
            }
            ?>
            <img src="<?php echo $profileImage; ?>" alt="Profile Picture" class="user-info-avatar" onerror="this.onerror=null; this.src='<?php echo $imageBaseUrl; ?>/profiles/default.png';">
            <div class="user-info-details">
                <h2><?php echo htmlspecialchars($userProfile['Profile_Name']); ?></h2>
                <span class="user-info-role"><?php echo htmlspecialchars($userProfile['Role']); ?></span>
                <span class="user-info-team">Team: <?php echo htmlspecialchars($userProfile['Fireteam_Name'] ?: 'Unassigned'); ?></span>
                <span class="user-info-status <?php echo strtolower($userProfile['Status']) === 'active' ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($userProfile['Status']); ?>
                </span>
            </div>
        </div>

        <!-- Financial History Widget -->
        <div class="financial-history-widget">
            <h4 class="history-title">Financial Activity</h4>
            <div class="history-list">
                <?php
                // Fetch financial history logs
                $historyStmt = $pdo->prepare("SELECT Log_Id, Transaction_Date, Transaction_Quantity, Comment FROM logs WHERE Transaction_Inventory_Id = ? AND Transaction_Item = 'MONEY' ORDER BY Log_Id DESC LIMIT 15");
                $historyStmt->execute([$inventoryId]);
                $moneyLogs = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

                $financialHistory = [];
                $pairStmt = $pdo->prepare("
                    SELECT l.*, i.Inventory_Name, IFNULL(it.Item_Display_Name, l.Transaction_Item) as Display_Name
                    FROM logs l 
                    LEFT JOIN inventories i ON l.Transaction_Inventory_Id = i.Inventory_Id
                    LEFT JOIN items it ON l.Transaction_Item = it.item_class
                    WHERE (l.Transaction_Item != 'MONEY' OR l.Transaction_Inventory_Id != ?)
                    AND ABS(TIMESTAMPDIFF(SECOND, l.Transaction_Date, ?)) <= 1
                    AND l.Log_Id != ?
                ");

                foreach ($moneyLogs as $ml) {
                    $date = new DateTime($ml['Transaction_Date']);
                    $date->modify('+50 years');
                    $qty = (int)$ml['Transaction_Quantity'];
                    $comment = $ml['Comment'];
                    
                    $pairStmt->execute([$inventoryId, $ml['Transaction_Date'], $ml['Log_Id']]);
                    $pairs = $pairStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $desc = "Unknown transaction ($qty Cr)";
                    
                    if (strtolower($comment) === 'purchase' || strtolower($comment) === 'refill') {
                        if (!empty($pairs)) {
                            $desc = "Purchased " . $pairs[0]['Display_Name'] . " for " . abs($qty) . " Cr";
                        }
                    } elseif (strtolower($comment) === 'item sale') {
                        if (!empty($pairs)) {
                            $desc = "Sold " . $pairs[0]['Display_Name'] . " for " . $qty . " Cr";
                        }
                    } elseif (strtolower($comment) === 'transfer') {
                        if ($qty > 0) {
                            $from = !empty($pairs) ? $pairs[0]['Inventory_Name'] : 'Unknown';
                            $desc = "Received " . $qty . " Cr from " . $from;
                        } else {
                            $to = !empty($pairs) ? $pairs[0]['Inventory_Name'] : 'Unknown';
                            $desc = "Sent " . abs($qty) . " Cr to " . $to;
                        }
                    } else {
                        if ($qty > 0) {
                            $desc = "Received " . $qty . " Cr (" . $comment . ")";
                        } else {
                            $desc = "Lost " . abs($qty) . " Cr (" . $comment . ")";
                        }
                    }
                    
                    $financialHistory[] = [
                        'date' => $date->format('d.m.Y H:i'),
                        'desc' => $desc,
                        'amount' => $qty
                    ];
                }
                ?>
                
                <?php if (empty($financialHistory)): ?>
                    <div class="history-item empty">No recent activity</div>
                <?php else: ?>
                    <?php foreach ($financialHistory as $activity): ?>
                        <div class="history-item">
                            <div class="history-details">
                                <span class="history-desc" title="<?php echo htmlspecialchars($activity['desc']); ?>"><?php echo htmlspecialchars($activity['desc']); ?></span>
                                <span class="history-date"><?php echo $activity['date']; ?></span>
                            </div>
                            <span class="history-amount <?php echo $activity['amount'] > 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $activity['amount'] > 0 ? '+' : ''; ?><?php echo number_format($activity['amount']); ?> Cr
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Current Balance -->
        <div class="user-balance-widget">
            <div class="balance-info">
                <span class="balance-label">Current Balance</span>
                <span class="balance-amount">
                    <?php 
                        $displayBalance = $isOwnInventory ? (isset($_SESSION['inventory_money']) ? $_SESSION['inventory_money'] : 0) : $inventoryBalance;
                        echo number_format($displayBalance, 2, ".", "'"); 
                    ?> Cr
                </span>
            </div>
            <?php if ($isOwnInventory): ?>
            <button class="btn-industrial success btn-inv-transfer" title="Transfer Funds" onclick="overlayOn(-1, 'Money', <?php echo isset($_SESSION['inventory_money']) ? $_SESSION['inventory_money'] : 0; ?>)">
                <?php if(file_exists('../images/icons/transfer.svg')) include '../images/icons/transfer.svg'; else echo '⇄'; ?> Transfer
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Pane: 70% -->
    <div class="inv-right-pane">
        
        <!-- Category Navigation & Global Actions -->
        <div class="inv-categories-wrapper preview-inventory-1">
            <div class="inv-categories preview-inventory-2">
                <?php 
                $firstCategory = true;
                foreach ($groupedItems as $type => $typeItems): 
                    $iconName = strtolower(str_replace(' ', '_', $type));
                    $iconPath = __DIR__ . '/../images/icons/categories/' . $iconName . '.svg';
                ?>
                    <button class="inv-category-btn <?php echo $firstCategory ? 'active' : ''; ?>" data-target="cat-<?php echo htmlspecialchars($type); ?>" onclick="switchCategory(this)" title="<?php echo htmlspecialchars($type); ?>">
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
                
                if (empty($groupedItems)) {
                    echo "<button class='inv-category-btn active'>?</button>";
                }
                ?>
            </div>
            
            <?php if ($isOwnInventory): ?>
            <div class="inv-global-actions preview-inventory-3">
                <button class="btn-industrial" onclick="showRepackAllOverlay()" title="Repack All Magazines">
                    Repack Mags
                </button>
                <button class="btn-industrial" onclick="showRefillAllOverlay()" title="Refill All Magazines">
                    Refill Mags
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Items Container -->
        <?php 
        $firstCategory = true;
        foreach ($groupedItems as $type => $typeItems): 
            $isWeapon = in_array($type, ['Primary_Weapon', 'Sidearm', 'Launcher', 'Melee', 'T-Doll']);
        ?>
            <div class="inv-items-container <?php echo $isWeapon ? 'is-weapon-container' : 'is-grid-container'; ?> <?php echo $firstCategory ? 'active-container' : ''; ?>" id="cat-<?php echo htmlspecialchars($type); ?>">
                <?php foreach ($typeItems as $item): ?>
                    <div class="inv-card">
                        <?php if (!$isWeapon && $item['Item_Quantity'] > 1): ?>
                            <div class="inv-card-qty-badge"><?php echo $item['Item_Quantity']; ?>x</div>
                        <?php endif; ?>
                        
                        <div class="inv-card-image <?php if (!$isWeapon) echo 'inv-card-img-nonweapon'; ?>">
                            <?php
                                $imgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $item['Item_Class'], $item['Custom_Item_Type']);
                                $imagePath = $imgPaths['imagePath'];
                                $defaultImage = $imgPaths['defaultImage'];
                                $fileCheckPath = $imgPaths['fileCheckPath'];
                            ?>
                            <img src="<?php echo file_exists($fileCheckPath) ? $imagePath : $defaultImage; ?>" 
                                 alt="<?php echo htmlspecialchars($item['Item_Display_Name'] ?? $item['Item_Class']); ?>"
                                 title="<?php echo htmlspecialchars($item['Item_Display_Name'] ?? $item['Item_Class']); ?>" 
                                 loading="lazy">
                            <?php if (!$isWeapon && !empty($item['Item_Properties'])): ?>
                                <div class="inv-card-ammo-badge">
                                    Ammo: <?php 
                                        $displayAmmo = htmlspecialchars($item['Item_Properties']);
                                        $classKey = strtoupper(trim((string)$item['Item_Class']));
                                        if (isset($marketDataByClass[$classKey]) && isset($marketDataByClass[$classKey]['Ammo_Count']) && $marketDataByClass[$classKey]['Ammo_Count'] > 0) {
                                            $displayAmmo .= '/' . $marketDataByClass[$classKey]['Ammo_Count'];
                                        }
                                        echo $displayAmmo;
                                    ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($isOwnInventory): ?>
                                <?php
                                    $itemClassKey = strtoupper(trim((string)$item['Item_Class']));
                                    $isInterchangeable = isset($interchangeableSet[$itemClassKey]);
                                    $sellPrice = isset($marketDataByClass[$itemClassKey]) ? (float)$marketDataByClass[$itemClassKey]['Selling_Price'] : 0;
                                    $inMarket = isset($marketDataByClass[$itemClassKey]) ? (int)$marketDataByClass[$itemClassKey]['Market_item_Id'] : 0;
                                    
                                    $ammosOverlay = [];
                                    if ($isWeapon) {
                                        $ammosOverlay = findCompatibleAmmo($item['Item_Class'], $ammoItems, $compatibleMap);
                                        foreach ($ammosOverlay as &$ammoOverlayItem) {
                                            $oImgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $ammoOverlayItem['Item_Class'], $ammoOverlayItem['Custom_Item_Type']);
                                            $aPath = $oImgPaths['imagePath'];
                                            $dImage = $oImgPaths['defaultImage'];
                                            $fCheck = $oImgPaths['fileCheckPath'];
                                            $ammoOverlayItem['imageUrl'] = file_exists($fCheck) ? $aPath : $dImage;
                                        }
                                        unset($ammoOverlayItem);
                                    }
                                ?>
                                <button class="inv-card-plus-btn" 
                                    onclick="showItemDetailsOverlay(
                                        <?php echo $item['Content_Item_Id']; ?>, 
                                        '<?php echo addslashes(htmlspecialchars($item['Item_Class'])); ?>', 
                                        '<?php echo addslashes(htmlspecialchars($item['Item_Display_Name'] ?? $item['Item_Class'])); ?>',
                                        <?php echo $item['Item_Quantity']; ?>, 
                                        '<?php echo addslashes(htmlspecialchars($item['Item_Properties'])); ?>',
                                        <?php echo $sellPrice; ?>, 
                                        <?php echo $isInterchangeable ? 'true' : 'false'; ?>,
                                        <?php echo $inMarket; ?>,
                                        '<?php echo file_exists($fileCheckPath) ? $imagePath : $defaultImage; ?>',
                                        <?php echo htmlspecialchars(json_encode($ammosOverlay), ENT_QUOTES, 'UTF-8'); ?>
                                    )">
                                    +
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($isWeapon): ?>
                        <div class="inv-card-content">
                            <?php $ammos = findCompatibleAmmo($item['Item_Class'], $ammoItems, $compatibleMap); ?>
                            <?php if (!empty($ammos)): ?>
                                <div class="inv-card-ammo-container">
                                    <?php foreach ($ammos as $ammo): ?>
                                        <div class="inv-card-ammo inv-card-ammo-rel" title="<?php echo htmlspecialchars($ammo['Item_Display_Name']); ?>">
                                            <span class="inv-card-ammo-qty"><?php echo $ammo['Item_Quantity']; ?></span>
                                            <?php
                                                $aImgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $ammo['Item_Class'], $ammo['Custom_Item_Type']);
                                                $ammoImagePath = $aImgPaths['imagePath'];
                                                $ammoDefaultImage = $aImgPaths['defaultImage'];
                                                $ammoFileCheckPath = $aImgPaths['fileCheckPath'];
                                            ?>
                                            <img src="<?php echo file_exists($ammoFileCheckPath) ? $ammoImagePath : $ammoDefaultImage; ?>" 
                                                 class="inv-card-ammo-img" alt="Ammo">
                                            <?php if (!empty($ammo['Item_Properties'])): ?>
                                                <div class="inv-card-ammo-badge-sm">
                                                    Ammo: <?php 
                                                        $displayAmmoSm = htmlspecialchars($ammo['Item_Properties']);
                                                        $ammoClassKey = strtoupper(trim((string)$ammo['Item_Class']));
                                                        if (isset($marketDataByClass[$ammoClassKey]) && isset($marketDataByClass[$ammoClassKey]['Ammo_Count']) && $marketDataByClass[$ammoClassKey]['Ammo_Count'] > 0) {
                                                            $displayAmmoSm .= '/' . $marketDataByClass[$ammoClassKey]['Ammo_Count'];
                                                        }
                                                        echo $displayAmmoSm;
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="inv-card-ammo-container">
                                    <div class="inv-card-ammo inv-card-ammo-empty">
                                        <span class="inv-card-ammo-empty-text">No Ammo</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php 
                // Add free slot cards if applicable
                if (isset($categoryLimits[$type])) {
                    $currentQty = 0;
                    foreach ($currentCounts as $cc) {
                        if ($cc['Custom_Item_Type'] === $type) {
                            $currentQty = (int)$cc['quantity'];
                            break;
                        }
                    }
                    $freeSlots = max(0, $categoryLimits[$type] - $currentQty);
                    for ($i = 0; $i < $freeSlots; $i++):
                ?>
                    <div class="inv-card free-slot">
                        <span>Free Slot</span>
                    </div>
                <?php 
                    endfor;
                }
                ?>
            </div>
        <?php 
        $firstCategory = false;
        endforeach; 
        
        if (empty($groupedItems)) {
            echo "<div class='inv-items-container inv-empty-msg'>No items found.</div>";
        }
        ?>
    </div>
</div>

<div id="inventory-item-details-overlay" class="overlay-backdrop">
    <div class="overlay-container inv-overlay-large">
        <h3>Item Details</h3>
        
        <div class="inv-details-row">
            <!-- Left side: Image -->
            <div class="inv-details-img-container">
                <img id="detailsItemImage" src="" alt="Item Image" class="inv-details-img">
            </div>
            
            <!-- Right side: Info and Buttons -->
            <div class="inv-details-info">
                <p id="detailsItemDisplayName" class="overlay-item-name inv-details-title">Display Name</p>
                <p id="detailsItemClass" class="inv-details-class">Class Name</p>
                
                <div id="detailsAmmoContainer" class="inv-details-ammo-section">
                    <h4 class="inv-details-ammo-title">Compatible Ammo</h4>
                    <div id="detailsAmmoList" class="inv-details-ammo-list">
                        <!-- Ammos injected here -->
                    </div>
                </div>
                
                <div class="overlay-form-actions inv-details-actions">
                    <button type="button" class="btn-industrial btn-inv-action" id="detailsBtnTransfer" title="Transfer" onclick="triggerTransfer()">
                        <?php include '../images/icons/transfer.svg'; ?>
                    </button>
                    <button type="button" class="btn-industrial success btn-inv-action" id="detailsBtnModify" title="Modify" onclick="triggerModify()">
                        <?php include '../images/icons/modify.svg'; ?>
                    </button>
                    <button type="button" class="btn-industrial danger btn-inv-action" id="detailsBtnSell" title="Sell" onclick="triggerSell()">
                        <?php include '../images/icons/sell.svg'; ?>
                    </button>
                    <button type="button" class="btn-industrial btn-inv-action" id="detailsBtnMarket" title="Market" onclick="triggerMarket()">
                        <?php include '../images/icons/market.svg'; ?>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="inv-details-footer">
            <button type="button" class="btn-industrial btn-inv-close" onclick="hideItemDetailsOverlay()">
                Close
            </button>
        </div>
    </div>
</div>

<div id="inventory-transfer-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Transfer</h3>
        <p id="transferItemName" class="overlay-item-name">Item</p>
        <form id="transferForm" class="overlay-form">
            <input type="hidden" id="transferItemId" name="item_id" value="">
            <input type="hidden" id="transferItemClass" name="item_class" value="">
            <input type="hidden" id="transferItemState" name="item_state" value="">
            
            <div class="overlay-form-row">
                <label for="transferUser">Send to:</label>
                <select id="transferUser" name="target_user" required>
                    <?php foreach ($inventories as $inventory): ?>
                        <option value="<?php echo htmlspecialchars($inventory['Inventory_Id']); ?>">
                            <?php echo htmlspecialchars($inventory['Inventory_Name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="overlay-form-row">
                <label for="transferQuantity">Quantity:</label>
                <input type="number" id="transferQuantity" name="quantity" min="1" required>
            </div>

            <div class="overlay-form-actions">
                <button type="button" class="btn-industrial" onclick="overlayOff()">Cancel</button>
                <button type="button" class="btn-industrial primary" onclick="transferItem()">Transfer</button>
            </div>
        </form>
    </div>
</div>

<div id="inventory-modify-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Modify</h3>
        <p id="modifyItemName" class="overlay-item-name">Item</p>
        <form id="modifyForm" class="overlay-form">
            <input type="hidden" id="modifyItemId" name="item_id" value="">
            <input type="hidden" id="modifyItemClass" name="item_class" value="">
            
            <div class="overlay-form-row">
                <label>Change to:</label>
                <div id="modifyTarget" class="inv-modify-target"></div>
            </div>

            <div class="overlay-form-row">
                <label for="modifyQuantity">Quantity:</label>
                <input type="number" id="modifyQuantity" name="quantity" min="1" required>
            </div>

            <div class="overlay-info-row inv-overlay-mt">
                <span>Total Cost:</span>
                <span><span id="modifyCost">0</span> Cr</span>
            </div>

            <div class="overlay-form-actions">
                <button type="button" class="btn-industrial" onclick="hideModifyOverlay()">Cancel</button>
                <button type="button" class="btn-industrial success" onclick="modifyItem()">Modify</button>
            </div>
        </form>
    </div>
</div>

<div id="inventory-sell-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Sell</h3>
        <p id="sellItemName" class="overlay-item-name">Item</p>
        <form id="sellForm" class="overlay-form">
            <input type="hidden" id="sellItemId" name="item_id" value="">
            <input type="hidden" id="sellItemClass" name="item_class" value="">
            <input type="hidden" id="sellItemState" name="item_state" value="">
            <input type="hidden" id="sellUnitPrice" name="unit_price" value="0">

            <div class="overlay-form-row">
                <label for="sellQuantity">Quantity:</label>
                <input type="number" id="sellQuantity" name="quantity" min="1" oninput="updateSellTotal()" required>
            </div>

            <div class="overlay-info-row inv-overlay-mt">
                <span>Price per unit:</span>
                <span><span id="sellPricePerUnit">0</span> Cr</span>
            </div>
            <div class="overlay-info-row">
                <span>Total Earnings:</span>
                <span class="inv-sell-total"><span id="sellTotalPrice">0</span> Cr</span>
            </div>

            <div class="overlay-form-actions">
                <button type="button" class="btn-industrial" onclick="hideSellOverlay()">Cancel</button>
                <button type="button" class="btn-industrial danger" onclick="sellItem()">Sell</button>
            </div>
        </form>
    </div>
</div>

<div id="inventory-repack-all-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Repack All Magazines</h3>
        <p>Are you sure you want to repack all magazines in your inventory?</p>
        <p class="overlay-item-name">This will consolidate all loose ammo into full magazines where possible.</p>
        
        <div class="overlay-form-actions preview-inventory-4">
            <button type="button" class="btn-industrial" onclick="hideRepackAllOverlay()">Cancel</button>
            <button type="button" class="btn-industrial primary" onclick="confirmRepackAll()">Repack All</button>
        </div>
    </div>
</div>

<div id="inventory-refill-all-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Refill All Magazines</h3>
        <p>This will purchase missing ammo to refill all magazines in your inventory to maximum capacity.</p>
        
        <div class="overlay-info-row inv-overlay-mt">
            <span>Total Cost:</span>
            <span class="inv-sell-total"><span id="refillAllTotalCost">...</span> Cr</span>
        </div>

        <div class="overlay-form-actions preview-inventory-5">
            <button type="button" class="btn-industrial" onclick="hideRefillAllOverlay()">Cancel</button>
            <button type="button" class="btn-industrial success" id="btnConfirmRefillAll" onclick="confirmRefillAll()" disabled>Refill All</button>
        </div>
    </div>
</div>

<script>
    const dbBaseUrl = "<?php echo $dbBaseUrl; ?>";
    const marketDataMap = <?php echo json_encode($marketDataByClass); ?>;

    let currentDetailsItem = {};

    function showItemDetailsOverlay(id, itemClass, displayName, maxQuantity, properties, sellPrice, isInterchangeable, inMarket, imageUrl, ammos) {
        currentDetailsItem = { id, itemClass, displayName, maxQuantity, properties, sellPrice, isInterchangeable, inMarket, imageUrl };
        
        document.getElementById('detailsItemDisplayName').textContent = displayName || itemClass;
        document.getElementById('detailsItemClass').textContent = itemClass;
        
        const imgEl = document.getElementById('detailsItemImage');
        if (imageUrl) {
            imgEl.src = imageUrl;
            imgEl.style.display = 'block';
        } else {
            imgEl.style.display = 'none';
        }
        
        const ammoContainer = document.getElementById('detailsAmmoContainer');
        const ammoList = document.getElementById('detailsAmmoList');
        if (ammos && ammos.length > 0) {
            ammoContainer.style.display = 'block';
            ammoList.innerHTML = '';
            ammos.forEach(ammo => {
                let propBadge = '';
                if (ammo.Item_Properties && ammo.Item_Properties !== '') {
                    let dispAmmo = ammo.Item_Properties;
                    const cKey = String(ammo.Item_Class).trim().toUpperCase();
                    if (marketDataMap[cKey] && marketDataMap[cKey].Ammo_Count && marketDataMap[cKey].Ammo_Count > 0) {
                        dispAmmo += '/' + marketDataMap[cKey].Ammo_Count;
                    }
                    propBadge = `<div class="inv-card-ammo-badge-sm">Ammo: ${dispAmmo}</div>`;
                }
                
                ammoList.innerHTML += `
                    <div class="inv-card-ammo inv-card-ammo-wrapper" title="${ammo.Item_Display_Name}">
                        <span class="inv-card-ammo-qty">${ammo.Item_Quantity}</span>
                        <img src="${ammo.imageUrl}" class="inv-card-ammo-img inv-card-ammo-img-large" alt="Ammo">
                        ${propBadge}
                    </div>
                `;
            });
        } else {
            ammoContainer.style.display = 'none';
        }
        
        const btnModify = document.getElementById('detailsBtnModify');
        if (isInterchangeable) {
            btnModify.disabled = false;
            btnModify.style.opacity = '1';
            btnModify.style.cursor = 'pointer';
            btnModify.style.filter = 'none';
        } else {
            btnModify.disabled = true;
            btnModify.style.opacity = '0.4';
            btnModify.style.cursor = 'not-allowed';
            btnModify.style.filter = 'grayscale(1)';
        }

        const btnMarket = document.getElementById('detailsBtnMarket');
        if (inMarket) {
            btnMarket.disabled = false;
            btnMarket.style.opacity = '1';
            btnMarket.style.cursor = 'pointer';
            btnMarket.style.filter = 'none';
        } else {
            btnMarket.disabled = true;
            btnMarket.style.opacity = '0.4';
            btnMarket.style.cursor = 'not-allowed';
            btnMarket.style.filter = 'grayscale(1)';
        }
        
        document.getElementById('inventory-item-details-overlay').style.display = 'flex';
    }

    function hideItemDetailsOverlay() {
        document.getElementById('inventory-item-details-overlay').style.display = 'none';
    }

    function triggerTransfer() {
        hideItemDetailsOverlay();
        overlayOn(currentDetailsItem.id, currentDetailsItem.itemClass, currentDetailsItem.maxQuantity, currentDetailsItem.properties);
    }

    function triggerModify() {
        if (!currentDetailsItem.isInterchangeable) return;
        hideItemDetailsOverlay();
        showModifyOverlay(currentDetailsItem.id, currentDetailsItem.itemClass, currentDetailsItem.maxQuantity);
    }

    function triggerSell() {
        hideItemDetailsOverlay();
        showSellOverlay(currentDetailsItem.id, currentDetailsItem.itemClass, currentDetailsItem.maxQuantity, currentDetailsItem.properties, currentDetailsItem.sellPrice);
    }

    function triggerMarket() {
        if (!currentDetailsItem.inMarket) return;
        window.location.href = '../views/detailPage.php?id=' + currentDetailsItem.inMarket;
    }

    function switchCategory(btn) {
        // Remove active class from all buttons
        document.querySelectorAll('.inv-category-btn').forEach(b => b.classList.remove('active'));
        // Add active class to clicked button
        btn.classList.add('active');
        
        // Hide all item containers
        document.querySelectorAll('.inv-items-container').forEach(c => c.classList.remove('active-container'));
        
        // Show target container
        const targetId = btn.getAttribute('data-target');
        const targetContainer = document.getElementById(targetId);
        if (targetContainer) {
            targetContainer.classList.add('active-container');
        }
    }

    // Overlay close handlers
    document.querySelectorAll('.overlay-backdrop').forEach(overlay => {
        overlay.addEventListener('click', function(event) {
            if (event.target === this) {
                this.style.display = 'none';
            }
        });
    });

    // Basic toast/error wrappers for the old functions
    function showToast(msg, type) {
        alert((type ? type.toUpperCase() + ": " : "") + msg);
    }
    function showError(msg) {
        alert("ERROR: " + msg);
    }

    function overlayOn(itemId, itemClass, maxQuantity, itemState = '') {
        document.getElementById('transferItemId').value = itemId;
        document.getElementById('transferItemClass').value = itemClass;
        document.getElementById('transferQuantity').max = maxQuantity;
        document.getElementById('transferQuantity').value = 1;
        document.getElementById('inventory-transfer-overlay').style.display = "flex";
        document.getElementById('transferItemName').textContent = itemClass;
        document.getElementById('transferItemState').value = itemState;
    }

    function overlayOff() {
        document.getElementById('inventory-transfer-overlay').style.display = "none";
    }

    function transferItem() {
        const itemId = document.getElementById('transferItemId').value;
        const itemClass = document.getElementById('transferItemClass').value;
        const targetInventoryId = document.getElementById('transferUser').value;
        const quantity = document.getElementById('transferQuantity').value;
        const itemState = document.getElementById('transferItemState').value;

        const isMoney = (itemClass === "Money");
        const endpoint = isMoney ? `${dbBaseUrl}/economy/transferFunds.php` : `${dbBaseUrl}/inventory_assets/transferItem.php`;
        
        const data = isMoney ? {
            targetInventoryId: targetInventoryId,
            amount: quantity
        } : {
            itemId: itemId,
            itemClass: itemClass,
            targetInventoryId: targetInventoryId,
            quantity: quantity,
            itemState: itemState
        };

        fetch(endpoint, {
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
                    location.reload();
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    const modifyOptionsMap = {};
    
    document.addEventListener('DOMContentLoaded', () => {
        const classesToFetch = new Set();
        document.querySelectorAll('.inv-card-plus-btn').forEach(btn => {
            const onclick = btn.getAttribute('onclick') || '';
            const match = onclick.match(/showItemDetailsOverlay\([^,]+,\s*'([^']+)'/);
            if (match && match[1]) {
                if (onclick.includes(', true, true)') || onclick.includes(', true, false)')) {
                    classesToFetch.add(match[1]);
                }
            }
        });

        const fetchClasses = Array.from(classesToFetch);
        let currentIndex = 0;

        function fetchNext() {
            if (currentIndex >= fetchClasses.length) return;
            const itemClass = fetchClasses[currentIndex];
            currentIndex++;

            fetch(`${dbBaseUrl}/inventory_assets/getInterchangeableItems.php?itemClass=${encodeURIComponent(itemClass)}`, { credentials: 'include' })
                .then(res => {
                    if (!res.ok) throw new Error("Network response was not ok");
                    return res.json();
                })
                .then(data => {
                    modifyOptionsMap[itemClass] = data;
                    fetchNext();
                })
                .catch(() => {
                    fetchNext();
                });
        }
        
        // Start 3 concurrent fetch chains
        fetchNext();
        fetchNext();
        fetchNext();
    });

    function renderModifyOptions(data) {
        const select = document.getElementById('modifyTarget');
        select.innerHTML = '';
        if (!data || data.length === 0) {
            select.innerHTML = '<div class="inv-modify-msg">No modifications available for this item.</div>';
            document.getElementById('modifyCost').textContent = "0";
            return;
        }
        data.forEach(item => {
            const option = document.createElement('div');
            option.className = 'modify-item-option';
            
            const imagePath = `../images/items/${item.item_class.toUpperCase()}.PNG`;
            const defaultImage = `../images/items/${item.item_type.toUpperCase()}.PNG`;
            
            option.innerHTML = `
                <label class="modify-item-label">
                    <input type="radio" name="targetClass" value="${item.item_class}" data-cost="${item.cost}">
                    <div class="modify-item-content">
                        <img src="${imagePath}" 
                            onerror="this.onerror=null;this.src='${defaultImage}';" 
                            alt="${item.display_name}"
                            class="modify-item-image">
                        <div class="modify-item-info">
                            <div class="modify-item-name">${item.display_name}</div>
                            <div class="modify-item-class">${item.item_class}</div>
                            <div class="modify-item-cost">${item.cost} Cr</div>
                        </div>
                    </div>
                </label>
            `;
            select.appendChild(option);
        });
        
        const firstInput = select.querySelector('input[type="radio"]');
        if (firstInput) {
            firstInput.checked = true;
            updateModifyCost();
        }
    }

    function showModifyOverlay(itemId, itemClass, maxQuantity) {
        document.getElementById('modifyItemId').value = itemId;
        document.getElementById('modifyItemClass').value = itemClass;
        document.getElementById('modifyQuantity').max = maxQuantity;
        document.getElementById('modifyQuantity').value = 1;
        document.getElementById('inventory-modify-overlay').style.display = "flex";
        document.getElementById('modifyItemName').textContent = itemClass;

        const select = document.getElementById('modifyTarget');

        if (modifyOptionsMap[itemClass]) {
            renderModifyOptions(modifyOptionsMap[itemClass]);
        } else {
            select.innerHTML = '<div class="inv-modify-msg">Loading options...</div>';
            fetch(`${dbBaseUrl}/inventory_assets/getInterchangeableItems.php?itemClass=${encodeURIComponent(itemClass)}`, { credentials: 'include' })
                .then(response => {
                    if (!response.ok) throw new Error("Network response was not ok");
                    return response.json();
                })
                .then(data => {
                    modifyOptionsMap[itemClass] = data;
                    renderModifyOptions(data);
                })
                .catch(err => {
                    select.innerHTML = '<div class="inv-modify-msg-err">Failed to load options.</div>';
                });
        }
    }

    function updateModifyCost() {
        const quantity = document.getElementById('modifyQuantity').value;
        const selectedOption = document.querySelector('input[name="targetClass"]:checked');
        const costPerItem = selectedOption ? parseInt(selectedOption.dataset.cost) : 0;
        document.getElementById('modifyCost').textContent = costPerItem * quantity;
    }

    function modifyItem() {
        const itemId = document.getElementById('modifyItemId').value;
        const itemClass = document.getElementById('modifyItemClass').value;
        const selectedOption = document.querySelector('input[name="targetClass"]:checked');
        const targetClass = selectedOption ? selectedOption.value : null;
        const quantity = document.getElementById('modifyQuantity').value;

        if (!targetClass) {
            showError('Please select a target item');
            return;
        }

        fetch(`${dbBaseUrl}/inventory_assets/modifyItem.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({
                itemId: itemId,
                sourceClass: itemClass,
                targetClass: targetClass,
                quantity: quantity
            })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    location.reload();
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function hideModifyOverlay() {
        document.getElementById('inventory-modify-overlay').style.display = "none";
    }

    document.getElementById('modifyTarget').addEventListener('change', updateModifyCost);
    document.getElementById('modifyQuantity').addEventListener('input', updateModifyCost);

    function showSellOverlay(itemId, itemClass, maxQuantity, itemState, unitPrice) {
        document.getElementById('sellItemId').value = itemId;
        document.getElementById('sellItemClass').value = itemClass;
        document.getElementById('sellQuantity').max = maxQuantity;
        document.getElementById('sellQuantity').value = 1;
        document.getElementById('sellItemName').textContent = itemClass;
        document.getElementById('sellItemState').value = itemState;
        document.getElementById('sellUnitPrice').value = unitPrice;
        document.getElementById('sellPricePerUnit').textContent = unitPrice;
        document.getElementById('inventory-sell-overlay').style.display = 'flex';
        updateSellTotal();
    }

    function hideSellOverlay() {
        document.getElementById('inventory-sell-overlay').style.display = 'none';
    }

    function updateSellTotal() {
        const quantity = parseInt(document.getElementById('sellQuantity').value) || 0;
        const unitPrice = parseFloat(document.getElementById('sellUnitPrice').value) || 0;
        document.getElementById('sellTotalPrice').textContent = Math.floor(quantity * unitPrice);
    }

    function sellItem() {
        const itemId = document.getElementById('sellItemId').value;
        const itemClass = document.getElementById('sellItemClass').value;
        const quantity = document.getElementById('sellQuantity').value;
        const itemState = document.getElementById('sellItemState').value;

        fetch(`${dbBaseUrl}/inventory_assets/sellItem.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({
                itemId: itemId,
                itemClass: itemClass,
                quantity: quantity,
                itemState: itemState
            })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast(`Sold ${quantity}x ${itemClass} for ${json.earnings} Cr`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }
    // Repack All
    function showRepackAllOverlay() {
        document.getElementById('inventory-repack-all-overlay').style.display = 'flex';
    }

    function hideRepackAllOverlay() {
        document.getElementById('inventory-repack-all-overlay').style.display = 'none';
    }

    function confirmRepackAll() {
        hideRepackAllOverlay();
        fetch(`../db/inventory_assets/repackAllMagazines.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({})
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast(json.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    // Refill All
    function showRefillAllOverlay() {
        document.getElementById('inventory-refill-all-overlay').style.display = 'flex';
        const costSpan = document.getElementById('refillAllTotalCost');
        const confirmBtn = document.getElementById('btnConfirmRefillAll');
        
        costSpan.textContent = "Loading...";
        confirmBtn.disabled = true;

        fetch(`../db/inventory_assets/getRefillAllCost.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({})
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    costSpan.textContent = json.totalCost;
                    if (json.totalCost > 0) {
                        confirmBtn.disabled = false;
                    } else {
                        costSpan.textContent = "0 (No magazines need refilling)";
                    }
                } else {
                    costSpan.textContent = "Error";
                    showError(json.error);
                }
            } catch (e) {
                costSpan.textContent = "Error";
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => {
            costSpan.textContent = "Error";
            showError("Fetch error: " + err.message);
        });
    }

    function hideRefillAllOverlay() {
        document.getElementById('inventory-refill-all-overlay').style.display = 'none';
    }

    function confirmRefillAll() {
        hideRefillAllOverlay();
        fetch(`../db/inventory_assets/refillAllMagazines.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({})
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast("All magazines refilled successfully!", 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }
</script>

<?php include '../includes/footer.php'; ?>



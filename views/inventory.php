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
    } elseif (in_array($cat, ['Primary_Weapon', 'Sidearm'])) {
        $orderedGroups[$cat] = []; // Always show Primary and Sidearm
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

<?php include '../includes/inventory_modals.php'; ?>

<?php include '../includes/footer.php'; ?>




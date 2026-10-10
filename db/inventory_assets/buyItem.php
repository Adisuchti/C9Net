<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

validateCsrfToken();

$marketStmt = $pdo->prepare("SELECT Var_Value FROM condition_variables WHERE Var_Name = 'Market_Enabled'");
$marketStmt->execute();
if ($marketStmt->fetchColumn() != 1 && $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Market is currently disabled']);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

$quantity = $input['quantity'];
$itemId = $input['itemId'];

$isTeamMode = false;
$teamId = isset($input['teamId']) ? (int)$input['teamId'] : 0;
$inventoryId = $_SESSION['inventory_id'];

if ($teamId > 0 && isset($input['inventoryId'])) {
    $teamStmt = $pdo->prepare("SELECT Fireteam_Name, leader_player_id, team_inventory_id FROM team_hierarchy WHERE Fireteam_Id = ?");
    $teamStmt->execute([$teamId]);
    $activeTeam = $teamStmt->fetch(PDO::FETCH_ASSOC);

    $userProfileStmt = $pdo->prepare("SELECT Assignment, Role, Profile_Id FROM player_profiles WHERE User_Id = ?");
    $userProfileStmt->execute([$_SESSION['user_id']]);
    $userProfile = $userProfileStmt->fetch(PDO::FETCH_ASSOC);

    if ($activeTeam && $userProfile && ($userProfile['Assignment'] == $teamId || $activeTeam['leader_player_id'] == $userProfile['Profile_Id'])) {
        $role = strtolower(trim($userProfile['Role']));
        if ($role === 'officer' || $role === 'squadleader' || $role === 'squadleaders' || $activeTeam['leader_player_id'] == $userProfile['Profile_Id']) {
            if ($activeTeam['team_inventory_id'] == $input['inventoryId']) {
                $isTeamMode = true;
                $inventoryId = $input['inventoryId'];
            }
        }
    }
    if (!$isTeamMode) {
        echo json_encode(['success' => false, 'error' => "Unauthorized team purchase"]);
        exit();
    }
}

$quantity = (int)$quantity;

if ($quantity <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid quantity']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    $moneyStmt = $pdo->prepare("SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ? FOR UPDATE");
    $moneyStmt->execute([$inventoryId]);
    $inventoryMoney = (float)$moneyStmt->fetchColumn();

    // get item type from market
    $typeQuery = "SELECT custom_item_types.Custom_Item_Type FROM market
    LEFT JOIN item_types ON item_types.Item_Type_Id = market.Market_Item_Type
    LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
    WHERE market.Market_item_Id = ?;";
    $typeStmt = $pdo->prepare($typeQuery);
    $typeStmt->execute([$itemId]);
    $itemType = $typeStmt->fetch();

    // Get item limits
    $limitQuery = "SELECT inventories.Inventory_Type, item_type_inventory_limit.Item_Type, item_type_inventory_limit.Item_Limit FROM `inventories`
    LEFT JOIN inventory_types ON inventory_types.Inventory_Type_Id = inventories.Inventory_Type
    LEFT JOIN item_type_inventory_limit ON item_type_inventory_limit.Inventory_Type = inventory_types.Inventory_Type_Id
    WHERE Inventory_Id = ? AND item_type_inventory_limit.Item_Type = ? LIMIT 1;";
    $limitStmt = $pdo->prepare($limitQuery);
    $limitStmt->execute([$inventoryId, $itemType['Custom_Item_Type']]);
    $limits = $limitStmt->fetch(PDO::FETCH_ASSOC);

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
        LEFT JOIN item_sorting ON item_sorting.Item_Sorting_Type = item_types.item_classification
        LEFT JOIN market ON market.Market_Item_Class = content_items.Item_Class
        WHERE Inventory_Id = ?
    ) AS subquery
	WHERE Custom_Item_Type = ?
    GROUP BY Custom_Item_Type;";
    $currentCountStmt = $pdo->prepare($currentCountQuery);
    $currentCountStmt->execute([$inventoryId, $itemType['Custom_Item_Type']]);
    $currentCounts = $currentCountStmt->fetch(PDO::FETCH_ASSOC);

    // Check if limits are exceeded
    if ($limits && $currentCounts) {
        $currentCount = (int)$currentCounts['quantity'];
        $limit = (int)$limits['Item_Limit'];

        if ($limit != -1 && ($currentCount + $quantity) > $limit) {
            echo json_encode(['success' => false, 'error' => "Item limit exceeded"]);
            exit();
        }
    }

    // Get item details from market
    $marketQuery = "SELECT Market_Item_Class, Purchase_Price, Available_Quantity, Ammo_Count, tier 
                FROM market WHERE Market_item_Id = ?";
    $marketStmt = $pdo->prepare($marketQuery);
    $marketStmt->execute([$itemId]);
    $item = $marketStmt->fetch();

    if ($item) {
        $tierQuery = "SELECT Var_Value FROM condition_variables WHERE Var_Name = 'current_tier'";
        $currentTier = $pdo->query($tierQuery)->fetchColumn() ?: 1;

        if ($item['tier'] > $currentTier && $_SESSION['user_id'] !== -1) {
            echo json_encode(['success' => false, 'error' => 'This item is not yet available']);
            exit();
        }
    }

    if (!$item) {
        echo json_encode(['success' => false, 'error' => "Item not found"]);
        exit();
    }

    if($item['Ammo_Count'] == null) {
        $item['Ammo_Count'] = '';
    }

    // Check if quantity is available
    if ($item['Available_Quantity'] != -1 && $quantity > $item['Available_Quantity']) {
        echo json_encode(['success' => false, 'error' => "Not enough items available"]);
        exit();
    }

    // Calculate total cost
    $totalCost = $quantity * $item['Purchase_Price'];

    // Check if user has enough money
    if ($totalCost > $inventoryMoney) {
        echo json_encode(['success' => false, 'error' => "Not enough money"]);
        exit();
    }

    // Check if item already exists in inventory
    $checkQuery = "SELECT Content_Item_Id, Item_Quantity FROM content_items 
                WHERE Inventory_Id = ? AND Item_Class = ? AND Item_Properties = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$inventoryId, $item['Market_Item_Class'], $item['Ammo_Count']]);
    $existingItem = $checkStmt->fetch();

    if ($existingItem && $existingItem['Item_Quantity'] > 0) {
        // Update existing item quantity
        $updateQuery = "UPDATE content_items 
                    SET Item_Quantity = Item_Quantity + ? 
                    WHERE Content_Item_Id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$quantity, $existingItem['Content_Item_Id']]);
    } else {
        // Insert new item
        $insertQuery = "INSERT INTO content_items 
                    (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) 
                    VALUES (?, ?, ?, ?)";
        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->execute([$inventoryId, $item['Market_Item_Class'], $quantity, $item['Ammo_Count']]);
    }

    // Update inventory money
    $updateMoneyQuery = "UPDATE inventories 
                        SET Inventory_Money = Inventory_Money - ? 
                        WHERE Inventory_Id = ?";
    $updateMoneyStmt = $pdo->prepare($updateMoneyQuery);
    $updateMoneyStmt->execute([$totalCost, $inventoryId]);

    // Update market quantity if not unlimited (-1)
    if ($item['Available_Quantity'] != -1) {
        $updateMarketQuery = "UPDATE market 
                            SET Available_Quantity = Available_Quantity - ? 
                            WHERE Market_item_Id = ?";
        $updateMarketStmt = $pdo->prepare($updateMarketQuery);
        $updateMarketStmt->execute([$quantity, $itemId]);
    }

    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $inventoryId,
        $item['Market_Item_Class'],
        $quantity,
        "purchase"
    ]);

    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $inventoryId,
        'MONEY',
        -$totalCost,
        "purchase"
    ]);



    // Update session money if personal inventory
    if (!$isTeamMode) {
        $_SESSION['inventory_money'] -= $totalCost;
    }

    // Commit transaction
    $pdo->commit();

} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit();
}

echo json_encode(['success' => true, 'error' => 'null']);
?>
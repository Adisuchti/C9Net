<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

$quantity = isset($input['quantity']) ? (int)$input['quantity'] : 0;
if ($quantity <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid quantity']);
    exit();
}
$input['quantity'] = $quantity;

try {
    $pdo->beginTransaction();

    // Get item details - FIXED: Added Item_Class to SELECT
    $itemQuery = "SELECT Content_Item_Id, Inventory_Id, Item_Quantity, Item_Properties, Item_Class 
                  FROM content_items WHERE Content_Item_Id = ?";
    $itemStmt = $pdo->prepare($itemQuery);
    $itemStmt->execute([$input['itemId']]);
    $item = $itemStmt->fetch();

    if (!$item) {
        throw new Exception("Item not found");
    }

    // Verify the item belongs to an authorized inventory
    if (!isUserAuthorizedForInventory($pdo, $_SESSION['user_id'], $item['Inventory_Id'])) {
        throw new Exception("Unauthorized for this inventory");
    }

    // Compute shortest path cost using Dijkstra's algorithm
    // Build complete graph of all edges (legacy + directed)
    $edges = [];

    // Fetch all legacy edges - bidirectional
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
        $source = $edge['source'];
        $target = $edge['target'];
        $cost = (float)$edge['cost'];
        if (!isset($edges[$source])) {
            $edges[$source] = [];
        }
        if (!isset($edges[$source][$target]) || $edges[$source][$target] > $cost) {
            $edges[$source][$target] = $cost;
        }
    }

    // Fetch all directed edges
    try {
        $directedQuery = "SELECT
                Source_Item_Class as source,
                Target_Item_Class as target,
                Change_Cost as cost
            FROM interchangeable_item_routes";
        $directedStmt = $pdo->prepare($directedQuery);
        $directedStmt->execute();
        $directedEdges = $directedStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($directedEdges as $edge) {
            $source = $edge['source'];
            $target = $edge['target'];
            $cost = (float)$edge['cost'];
            if (!isset($edges[$source])) {
                $edges[$source] = [];
            }
            if (!isset($edges[$source][$target]) || $edges[$source][$target] > $cost) {
                $edges[$source][$target] = $cost;
            }
        }
    } catch (Exception $ignored) {
        // Directed routes optional
    }

    // Run Dijkstra from source
    $distances = [$input['sourceClass'] => 0];
    $visited = [];
    $pq = [[$input['sourceClass'], 0]];

    while (!empty($pq)) {
        usort($pq, fn($a, $b) => $a[1] <=> $b[1]);
        [$current, $currentDist] = array_shift($pq);

        if (isset($visited[$current])) {
            continue;
        }
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

    // Check if target is reachable
    if (!isset($distances[$input['targetClass']])) {
        throw new Exception("Invalid modification - target not reachable");
    }

    $totalCost = $distances[$input['targetClass']] * $input['quantity'];

    // Check if user has enough money
    $moneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
    $moneyStmt = $pdo->prepare($moneyQuery);
    $moneyStmt->execute([$item['Inventory_Id']]);
    $currentMoney = $moneyStmt->fetchColumn();

    if ($currentMoney < $totalCost) {
        throw new Exception("Insufficient funds");
    }

    // Update item
    if ($input['quantity'] == $item['Item_Quantity']) {
        // Update entire stack
        $updateQuery = "UPDATE content_items 
                       SET Item_Class = ? 
                       WHERE Content_Item_Id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$input['targetClass'], $input['itemId']]);
    } else {
        // Split stack
        $updateQuery = "UPDATE content_items 
                       SET Item_Quantity = Item_Quantity - ? 
                       WHERE Content_Item_Id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$input['quantity'], $input['itemId']]);

        // Create new stack
        $insertQuery = "INSERT INTO content_items 
                       (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) 
                       VALUES (?, ?, ?, ?)";
        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->execute([
            $item['Inventory_Id'],
            $input['targetClass'],
            $input['quantity'],
            $item['Item_Properties']
        ]);
    }

    // Deduct money
    $updateMoneyQuery = "UPDATE inventories 
                        SET Inventory_Money = Inventory_Money - ? 
                        WHERE Inventory_Id = ?";
    $updateMoneyStmt = $pdo->prepare($updateMoneyQuery);
    $updateMoneyStmt->execute([$totalCost, $item['Inventory_Id']]);

    // FIXED: Log the modification - removed quotes around placeholders
    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, 
                 isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?), (?, ?, ?, 1, ?), (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $item['Inventory_Id'],
        'MONEY',
        -$totalCost,
        "Item modification cost",

        $item['Inventory_Id'],
        $item['Item_Class'],              // FIXED: Now available from SELECT
        -$input['quantity'],
        "Item modification - removed",

        $item['Inventory_Id'],
        $input['targetClass'],
        $input['quantity'],
        "Item modification - added"
    ]);

    $pdo->commit();
    if ($item['Inventory_Id'] == $_SESSION['inventory_id']) {
        $_SESSION['inventory_money'] -= $totalCost;
    }
    
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

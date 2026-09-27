<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_id'] === -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

$contentItemId = (int)$input['contentItemId'];
$listingType = $input['listingType']; // 'fixed' or 'auction'
$quantity = (int)$input['quantity'];
$fixedPrice = isset($input['fixedPrice']) ? (float)$input['fixedPrice'] : null;
$minBid = isset($input['minBid']) ? (float)$input['minBid'] : null;
$endDate = isset($input['endDate']) ? $input['endDate'] : null;

// Validate listing type
if (!in_array($listingType, ['fixed', 'auction'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid listing type']);
    exit();
}

// Validate fixed price
if ($listingType === 'fixed' && ($fixedPrice === null || $fixedPrice <= 0)) {
    echo json_encode(['success' => false, 'error' => 'Fixed price must be greater than 0']);
    exit();
}

// Validate auction fields
if ($listingType === 'auction') {
    if ($minBid === null || $minBid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Minimum bid must be greater than 0']);
        exit();
    }
    if (empty($endDate)) {
        echo json_encode(['success' => false, 'error' => 'End date is required for auctions']);
        exit();
    }
    // Ensure end date is in the future
    $endDateTime = new DateTime($endDate);
    $now = new DateTime();
    if ($endDateTime <= $now) {
        echo json_encode(['success' => false, 'error' => 'End date must be in the future']);
        exit();
    }
}

if ($quantity < 1) {
    echo json_encode(['success' => false, 'error' => 'Quantity must be at least 1']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Verify the item belongs to the logged-in user's inventory
    $itemQuery = "SELECT ci.Content_Item_Id, ci.Inventory_Id, ci.Item_Class, ci.Item_Quantity, ci.Item_Properties,
                    IFNULL(i.Item_Display_Name, ci.Item_Class) AS Item_Display_Name
                FROM content_items ci
                LEFT JOIN items i ON i.Item_Class = ci.Item_Class
                WHERE ci.Content_Item_Id = ? AND ci.Inventory_Id = ?";
    $itemStmt = $pdo->prepare($itemQuery);
    $itemStmt->execute([$contentItemId, $_SESSION['inventory_id']]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Item not found in your inventory']);
        exit();
    }

    if ($item['Item_Quantity'] < $quantity) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'You do not have enough of this item (have: ' . $item['Item_Quantity'] . ', requested: ' . $quantity . ')']);
        exit();
    }

    // Check if this exact content item already has an active listing
    $existingQuery = "SELECT Listing_Id FROM player_market_listings 
                    WHERE Content_Item_Id = ? AND Status = 'active'";
    $existingStmt = $pdo->prepare($existingQuery);
    $existingStmt->execute([$contentItemId]);
    if ($existingStmt->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'This item already has an active listing']);
        exit();
    }

    // Create the listing
    $insertQuery = "INSERT INTO player_market_listings 
                    (Seller_Inventory_Id, Content_Item_Id, Item_Class, Item_Display_Name, Item_Properties, Quantity, Listing_Type, Fixed_Price, Min_Bid, End_Date, Status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute([
        $_SESSION['inventory_id'],
        $contentItemId,
        $item['Item_Class'],
        $item['Item_Display_Name'],
        $item['Item_Properties'],
        $quantity,
        $listingType,
        $listingType === 'fixed' ? $fixedPrice : null,
        $listingType === 'auction' ? $minBid : null,
        $listingType === 'auction' ? $endDate : null
    ]);

    // Log the listing creation
    $logQuery = "INSERT INTO logs 
                (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) 
                VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([
        $_SESSION['inventory_id'],
        $item['Item_Class'],
        0,
        "Listed on player market (" . $listingType . ")" . ($listingType === 'fixed' ? " for " . $fixedPrice . " Cr" : " min bid " . $minBid . " Cr")
    ]);

    $pdo->commit();

    // Send Discord webhook notification
    $sellerName = $_SESSION['inventory_name'] ?? 'Unknown';
    $itemName = $item['Item_Display_Name'];

    if ($listingType === 'fixed') {
        $description = "**Item:** {$itemName}\n**Quantity:** {$quantity}\n**Price:** " . number_format($fixedPrice) . " Cr\n**Seller:** {$sellerName}";
    } else {
        $description = "**Item:** {$itemName}\n**Quantity:** {$quantity}\n**Min Bid:** " . number_format($minBid) . " Cr\n**Ends:** {$endDate}\n**Seller:** {$sellerName}";
    }

    $embedTitle = $listingType === 'fixed' ? 'New Market Listing (Fixed Price)' : 'New Market Listing (Auction)';
    $embedColor = $listingType === 'fixed' ? 3447003 : 15105570; // Blue for fixed, orange for auction

    $webhookUrl = "https://discord.com/api/webhooks/1434170569439318121/PZ8gTm_kK27XMeY1n-veb4_XYrqapiLjVVU4Hpzi-jCz-XMifya_3NMFS5MM4-BBVk80";
    $message = [
        "embeds" => [
            [
                "title" => $embedTitle,
                "description" => $description,
                "color" => $embedColor,
                "timestamp" => date('c')
            ]
        ]
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Discord webhook error (player market): ' . curl_error($ch));
    }
    curl_close($ch);

    echo json_encode(['success' => true, 'error' => 'null']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

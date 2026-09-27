<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn() || $_SESSION['user_id'] === -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

$listingId = (int)$input['listingId'];

try {
    $pdo->beginTransaction();

    // Get the listing - must belong to current user
    $listingQuery = "SELECT * FROM player_market_listings WHERE Listing_Id = ? AND Status = 'active' AND Seller_Inventory_Id = ?";
    $listingStmt = $pdo->prepare($listingQuery);
    $listingStmt->execute([$listingId, $_SESSION['inventory_id']]);
    $listing = $listingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$listing) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Listing not found or you are not the seller']);
        exit();
    }

    // For auctions with existing bids, do not allow cancellation
    if ($listing['Listing_Type'] === 'auction' && $listing['Current_Bid'] !== null) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Cannot cancel an auction that already has bids']);
        exit();
    }

    // Mark as cancelled and release FK reference
    $cancelQuery = "UPDATE player_market_listings SET Status = 'cancelled', Content_Item_Id = NULL WHERE Listing_Id = ?";
    $cancelStmt = $pdo->prepare($cancelQuery);
    $cancelStmt->execute([$listingId]);

    // Log
    $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) VALUES (?, ?, ?, 1, ?)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([$_SESSION['inventory_id'], $listing['Item_Class'], 0, "Cancelled player market listing"]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

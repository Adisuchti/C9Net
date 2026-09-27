<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['inventoryId'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

$inventoryId = (int)$input['inventoryId'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if inventory exists
    $checkQuery = "SELECT Inventory_Id FROM inventories WHERE Inventory_Id = ?";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([$inventoryId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception("Inventory not found");
    }

    // Delete bids placed by this inventory on other listings
    $deleteBidsByInventoryQuery = "DELETE FROM player_market_bids WHERE Bidder_Inventory_Id = ?";
    $deleteBidsByInventoryStmt = $pdo->prepare($deleteBidsByInventoryQuery);
    $deleteBidsByInventoryStmt->execute([$inventoryId]);

    // Nullify current_bidder on listings where this inventory was the highest bidder
    $nullifyBidderQuery = "UPDATE player_market_listings SET Current_Bidder_Inventory_Id = NULL, Current_Bid = NULL WHERE Current_Bidder_Inventory_Id = ?";
    $nullifyBidderStmt = $pdo->prepare($nullifyBidderQuery);
    $nullifyBidderStmt->execute([$inventoryId]);

    // Delete all bids on this inventory's own listings
    $deleteBidsOnListingsQuery = "DELETE pmb FROM player_market_bids pmb
        INNER JOIN player_market_listings pml ON pmb.Listing_Id = pml.Listing_Id
        WHERE pml.Seller_Inventory_Id = ?";
    $deleteBidsOnListingsStmt = $pdo->prepare($deleteBidsOnListingsQuery);
    $deleteBidsOnListingsStmt->execute([$inventoryId]);

    // Delete this inventory's listings
    $deleteListingsQuery = "DELETE FROM player_market_listings WHERE Seller_Inventory_Id = ?";
    $deleteListingsStmt = $pdo->prepare($deleteListingsQuery);
    $deleteListingsStmt->execute([$inventoryId]);

    // Delete inventory permissions
    $deletePermissionsQuery = "DELETE FROM permissions WHERE Inventory_Id = ?";
    $deletePermissionsStmt = $pdo->prepare($deletePermissionsQuery);
    $deletePermissionsStmt->execute([$inventoryId]);

    // Delete inventory contents
    $deleteContentsQuery = "DELETE FROM content_items WHERE Inventory_Id = ?";
    $deleteContentsStmt = $pdo->prepare($deleteContentsQuery);
    $deleteContentsStmt->execute([$inventoryId]);

    // Delete the inventory
    $deleteInventoryQuery = "DELETE FROM inventories WHERE Inventory_Id = ?";
    $deleteInventoryStmt = $pdo->prepare($deleteInventoryQuery);
    $deleteInventoryStmt->execute([$inventoryId]);

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => "null"]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

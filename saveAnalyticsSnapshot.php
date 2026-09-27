<?php
/**
 * Analytics Snapshot Cronjob
 * 
 * This script saves a snapshot of the current state of:
 * - content_items
 * - inventories
 * - market
 * 
 * Schedule this to run daily/weekly/monthly via cronjob
 */

require_once 'db/connection.php';

try {
    $pdo->beginTransaction();
    
    // Get current timestamp
    $snapshotDate = date('Y-m-d H:i:s');
    
    // Create snapshot record
    $snapshotStmt = $pdo->prepare("
        INSERT INTO analytics_snapshots (snapshot_date, snapshot_description)
        VALUES (:snapshot_date, :description)
    ");
    
    $description = "Automated snapshot - " . date('Y-m-d H:i:s');
    $snapshotStmt->execute([
        ':snapshot_date' => $snapshotDate,
        ':description' => $description
    ]);
    
    $snapshotId = $pdo->lastInsertId();
    
    // Save content_items snapshot
    $contentItemsStmt = $pdo->prepare("
        INSERT INTO analytics_content_items_snapshot 
        (snapshot_id, Content_Item_Id, Inventory_Id, Item_Class, Item_Quantity, Item_Properties)
        SELECT 
            :snapshot_id,
            Content_Item_Id,
            Inventory_Id,
            Item_Class,
            Item_Quantity,
            Item_Properties
        FROM content_items
    ");
    $contentItemsStmt->execute([':snapshot_id' => $snapshotId]);
    $contentItemsCount = $contentItemsStmt->rowCount();
    
    // Save inventories snapshot
    $inventoriesStmt = $pdo->prepare("
        INSERT INTO analytics_inventories_snapshot 
        (snapshot_id, Inventory_Id, Inventory_Name, Inventory_Money, Inventory_Type)
        SELECT 
            :snapshot_id,
            Inventory_Id,
            Inventory_Name,
            Inventory_Money,
            Inventory_Type
        FROM inventories
    ");
    $inventoriesStmt->execute([':snapshot_id' => $snapshotId]);
    $inventoriesCount = $inventoriesStmt->rowCount();
    
    // Save market snapshot
    $marketStmt = $pdo->prepare("
        INSERT INTO analytics_market_snapshot 
        (
            snapshot_id,
            Market_item_Id,
            Market_Item_Class,
            Purchase_Price,
            Selling_Price,
            Market_Item_Type,
            Available_Quantity,
            market_description,
            tier,
            Ammo_Count,
            Compatible_Items,
            Market,
            DLC,
            Visible
        )
        SELECT 
            :snapshot_id,
            Market_item_Id,
            Market_Item_Class,
            Purchase_Price,
            Selling_Price,
            Market_Item_Type,
            Available_Quantity,
            market_description,
            tier,
            Ammo_Count,
            Compatible_Items,
            Market
            ,DLC
            ,Visible
        FROM market
    ");
    $marketStmt->execute([':snapshot_id' => $snapshotId]);
    $marketCount = $marketStmt->rowCount();
    
    $pdo->commit();
    
    echo "Snapshot created successfully!\n";
    echo "Snapshot ID: $snapshotId\n";
    echo "Date: $snapshotDate\n";
    echo "Content Items: $contentItemsCount\n";
    echo "Inventories: $inventoriesCount\n";
    echo "Market Items: $marketCount\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error creating snapshot: " . $e->getMessage() . "\n";
    error_log("Analytics Snapshot Error: " . $e->getMessage());
}

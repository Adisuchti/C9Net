<?php
require_once __DIR__ . '/../connection.php';

/**
 * Send a Discord webhook notification for player market events.
 */
function sendPlayerMarketDiscordNotification($title, $description, $color = 2899536) {
    $webhookUrl = "https://discord.com/api/webhooks/1434170569439318121/PZ8gTm_kK27XMeY1n-veb4_XYrqapiLjVVU4Hpzi-jCz-XMifya_3NMFS5MM4-BBVk80";
    $message = [
        "embeds" => [
            [
                "title" => $title,
                "description" => $description,
                "color" => $color,
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
}

/**
 * Resolves all expired player market auctions.
 * - If seller still has the item: execute the trade to the highest bidder.
 * - If seller no longer has the item: void the auction.
 * - If no bids: mark as expired.
 * 
 * This should be called on page load of the player market page.
 */
function resolveExpiredAuctions($pdo) {
    $resolved = 0;

    // Find all active auctions that have passed their end date
    $expiredQuery = "SELECT * FROM player_market_listings 
                     WHERE Status = 'active' AND Listing_Type = 'auction' AND End_Date <= NOW()";
    $expiredStmt = $pdo->query($expiredQuery);
    $expiredListings = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredListings as $listing) {
        try {
            $pdo->beginTransaction();

            // No bids - mark as expired
            if ($listing['Current_Bid'] === null || $listing['Current_Bidder_Inventory_Id'] === null) {
                $expireQuery = "UPDATE player_market_listings SET Status = 'expired', Content_Item_Id = NULL WHERE Listing_Id = ?";
                $expireStmt = $pdo->prepare($expireQuery);
                $expireStmt->execute([$listing['Listing_Id']]);
                $pdo->commit();

                $itemName = $listing['Item_Display_Name'] ?? $listing['Item_Class'];
                sendPlayerMarketDiscordNotification(
                    'Auction Ended - No Bids',
                    "**Item:** {$itemName}\n**Quantity:** {$listing['Quantity']}\nThe auction expired with no bids.",
                    9807270 // Grey
                );

                $resolved++;
                continue;
            }

            // Check if seller still has the item
            $sellerItemQuery = "SELECT Content_Item_Id, Item_Quantity FROM content_items 
                                WHERE Content_Item_Id = ? AND Inventory_Id = ?";
            $sellerItemStmt = $pdo->prepare($sellerItemQuery);
            $sellerItemStmt->execute([$listing['Content_Item_Id'], $listing['Seller_Inventory_Id']]);
            $sellerItem = $sellerItemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sellerItem || $sellerItem['Item_Quantity'] < $listing['Quantity']) {
                // Void - seller doesn't have the item
                $voidQuery = "UPDATE player_market_listings SET Status = 'voided', Content_Item_Id = NULL WHERE Listing_Id = ?";
                $voidStmt = $pdo->prepare($voidQuery);
                $voidStmt->execute([$listing['Listing_Id']]);

                // Log
                $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) VALUES (?, ?, ?, 1, ?)";
                $logStmt = $pdo->prepare($logQuery);
                $logStmt->execute([$listing['Seller_Inventory_Id'], $listing['Item_Class'], 0, "Player market auction voided - item no longer in inventory"]);

                $pdo->commit();
                $resolved++;
                continue;
            }

            // Check if buyer still has inventory space
            $buyerInventoryId = $listing['Current_Bidder_Inventory_Id'];
            $typeQuery = "SELECT custom_item_types.Custom_Item_Type FROM items
                LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
                LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
                WHERE items.Item_Class = ?";
            $typeStmt = $pdo->prepare($typeQuery);
            $typeStmt->execute([$listing['Item_Class']]);
            $itemType = $typeStmt->fetch(PDO::FETCH_ASSOC);

            $canReceive = true;
            if ($itemType && $itemType['Custom_Item_Type']) {
                $limitQuery = "SELECT item_type_inventory_limit.Item_Limit FROM inventories
                    LEFT JOIN inventory_types ON inventory_types.Inventory_Type_Id = inventories.Inventory_Type
                    LEFT JOIN item_type_inventory_limit ON item_type_inventory_limit.Inventory_Type = inventory_types.Inventory_Type_Id
                    WHERE Inventory_Id = ? AND item_type_inventory_limit.Item_Type = ? LIMIT 1";
                $limitStmt = $pdo->prepare($limitQuery);
                $limitStmt->execute([$buyerInventoryId, $itemType['Custom_Item_Type']]);
                $limit = $limitStmt->fetch(PDO::FETCH_ASSOC);

                if ($limit && $limit['Item_Limit'] != -1) {
                    $countQuery = "SELECT SUM(Item_Quantity) AS quantity FROM (
                        SELECT DISTINCT content_items.Content_Item_Id, custom_item_types.Custom_Item_Type, content_items.Item_Quantity
                        FROM content_items
                        LEFT JOIN items ON items.item_class = content_items.Item_Class
                        LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
                        LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
                        WHERE Inventory_Id = ? AND custom_item_types.Custom_Item_Type = ?
                    ) AS subquery";
                    $countStmt = $pdo->prepare($countQuery);
                    $countStmt->execute([$buyerInventoryId, $itemType['Custom_Item_Type']]);
                    $currentCount = $countStmt->fetch(PDO::FETCH_ASSOC);

                    $count = $currentCount['quantity'] ?? 0;
                    if (($count + $listing['Quantity']) > $limit['Item_Limit']) {
                        $canReceive = false;
                    }
                }
            }

            // Check if buyer has enough money
            $buyerMoneyQuery = "SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?";
            $buyerMoneyStmt = $pdo->prepare($buyerMoneyQuery);
            $buyerMoneyStmt->execute([$buyerInventoryId]);
            $buyerMoney = $buyerMoneyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$canReceive || $buyerMoney['Inventory_Money'] < $listing['Current_Bid']) {
                // Void - buyer can't receive the item or doesn't have enough money anymore
                $voidQuery = "UPDATE player_market_listings SET Status = 'voided', Content_Item_Id = NULL WHERE Listing_Id = ?";
                $voidStmt = $pdo->prepare($voidQuery);
                $voidStmt->execute([$listing['Listing_Id']]);

                $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) VALUES (?, ?, ?, 1, ?)";
                $logStmt = $pdo->prepare($logQuery);
                $reason = !$canReceive ? "has no inventory space" : "has insufficient funds";
                $logStmt->execute([$buyerInventoryId, $listing['Item_Class'], 0, 
                    "Player market auction voided - buyer " . $reason]);

                $pdo->commit();

                $itemName = $listing['Item_Display_Name'] ?? $listing['Item_Class'];
                sendPlayerMarketDiscordNotification(
                    'Auction Voided',
                    "**Item:** {$itemName}\n**Quantity:** {$listing['Quantity']}\n**Reason:** Buyer {$reason}.",
                    15158332 // Red
                );

                $resolved++;
                continue;
            }

            // === Execute the trade ===

            // 1. Deduct money from buyer
            $deductQuery = "UPDATE inventories SET Inventory_Money = Inventory_Money - ? WHERE Inventory_Id = ?";
            $deductStmt = $pdo->prepare($deductQuery);
            $deductStmt->execute([$listing['Current_Bid'], $buyerInventoryId]);

            // 2. Add money to seller
            $addMoneyQuery = "UPDATE inventories SET Inventory_Money = Inventory_Money + ? WHERE Inventory_Id = ?";
            $addMoneyStmt = $pdo->prepare($addMoneyQuery);
            $addMoneyStmt->execute([$listing['Current_Bid'], $listing['Seller_Inventory_Id']]);

            // 3. Remove item from seller inventory
            // Always UPDATE (never DELETE) because the FK on player_market_listings.Content_Item_Id
            // prevents deletion of content_items rows that are still referenced by a listing
            $updateQuery = "UPDATE content_items SET Item_Quantity = Item_Quantity - ? WHERE Content_Item_Id = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([$listing['Quantity'], $listing['Content_Item_Id']]);

            // 5. Add item to buyer inventory
            $existingBuyerItemQuery = "SELECT Content_Item_Id, Item_Quantity FROM content_items 
                                        WHERE Inventory_Id = ? AND Item_Class = ? AND (Item_Properties = ? OR (Item_Properties IS NULL AND ? IS NULL))";
            $existingBuyerItemStmt = $pdo->prepare($existingBuyerItemQuery);
            $existingBuyerItemStmt->execute([$buyerInventoryId, $listing['Item_Class'], $listing['Item_Properties'], $listing['Item_Properties']]);
            $existingBuyerItem = $existingBuyerItemStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingBuyerItem) {
                $updateBuyerQuery = "UPDATE content_items SET Item_Quantity = Item_Quantity + ? WHERE Content_Item_Id = ?";
                $updateBuyerStmt = $pdo->prepare($updateBuyerQuery);
                $updateBuyerStmt->execute([$listing['Quantity'], $existingBuyerItem['Content_Item_Id']]);
            } else {
                $insertBuyerQuery = "INSERT INTO content_items (Inventory_Id, Item_Class, Item_Quantity, Item_Properties) VALUES (?, ?, ?, ?)";
                $insertBuyerStmt = $pdo->prepare($insertBuyerQuery);
                $insertBuyerStmt->execute([$buyerInventoryId, $listing['Item_Class'], $listing['Quantity'], $listing['Item_Properties']]);
            }

            // 6. Mark listing as sold and release FK reference
            $soldQuery = "UPDATE player_market_listings SET Status = 'sold', Content_Item_Id = NULL WHERE Listing_Id = ?";
            $soldStmt = $pdo->prepare($soldQuery);
            $soldStmt->execute([$listing['Listing_Id']]);

            // 7. Clean up: delete the content_items row if it's now at 0 quantity
            //    (only safe after NULLing the FK reference above)
            $cleanupQuery = "DELETE FROM content_items WHERE Content_Item_Id = ? AND Item_Quantity <= 0";
            $cleanupStmt = $pdo->prepare($cleanupQuery);
            $cleanupStmt->execute([$listing['Content_Item_Id']]);

            // 8. Log for both parties
            $logQuery = "INSERT INTO logs (Transaction_Inventory_Id, Transaction_Item, Transaction_Quantity, isMarketActivity, Comment) VALUES (?, ?, ?, 1, ?)";
            $logStmt = $pdo->prepare($logQuery);
            // Buyer item log
            $logStmt->execute([$buyerInventoryId, $listing['Item_Class'], $listing['Quantity'], 
                "Won player market auction for " . $listing['Current_Bid'] . " Cr"]);
            // Buyer money log
            $logStmt->execute([$buyerInventoryId, 'MONEY', -$listing['Current_Bid'], 
                "Won player market auction for " . $listing['Current_Bid'] . " Cr"]);
            // Seller item log
            $logStmt->execute([$listing['Seller_Inventory_Id'], $listing['Item_Class'], -$listing['Quantity'], 
                "Sold via player market auction for " . $listing['Current_Bid'] . " Cr"]);
            // Seller money log
            $logStmt->execute([$listing['Seller_Inventory_Id'], 'MONEY', $listing['Current_Bid'], 
                "Sold via player market auction for " . $listing['Current_Bid'] . " Cr"]);

            $pdo->commit();

            // Send Discord notification for completed auction
            $sellerNameQuery = "SELECT Inventory_Name FROM inventories WHERE Inventory_Id = ?";
            $sellerNameStmt = $pdo->prepare($sellerNameQuery);
            $sellerNameStmt->execute([$listing['Seller_Inventory_Id']]);
            $sellerNameRow = $sellerNameStmt->fetch(PDO::FETCH_ASSOC);
            $sellerName = $sellerNameRow ? $sellerNameRow['Inventory_Name'] : 'Unknown';

            $buyerNameQuery = "SELECT Inventory_Name FROM inventories WHERE Inventory_Id = ?";
            $buyerNameStmt = $pdo->prepare($buyerNameQuery);
            $buyerNameStmt->execute([$buyerInventoryId]);
            $buyerNameRow = $buyerNameStmt->fetch(PDO::FETCH_ASSOC);
            $buyerName = $buyerNameRow ? $buyerNameRow['Inventory_Name'] : 'Unknown';

            $itemName = $listing['Item_Display_Name'] ?? $listing['Item_Class'];
            $auctionDesc = "**Item:** {$itemName}\n**Quantity:** {$listing['Quantity']}\n**Final Price:** " . number_format($listing['Current_Bid']) . " Cr\n**Seller:** {$sellerName}\n**Winner:** {$buyerName}";

            sendPlayerMarketDiscordNotification('Auction Ended - Item Sold', $auctionDesc, 2899536); // Green

            $resolved++;
        } catch (Exception $e) {
            $pdo->rollBack();
            // Log error to PHP error log and hiddenLogs so it's visible in admin
            error_log("Failed to resolve auction " . $listing['Listing_Id'] . ": " . $e->getMessage());
            try {
                $errLogStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
                $errLogStmt->execute(["Auction resolution error for Listing_Id " . $listing['Listing_Id'] . ": " . $e->getMessage()]);
            } catch (Exception $logEx) {
                error_log("Failed to log auction error: " . $logEx->getMessage());
            }
        }
    }

    return $resolved;
}
?>

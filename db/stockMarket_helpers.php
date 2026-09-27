<?php
/**
 * Stock Market Trading Helper Functions
 */

const COMMISSION_RATE = 0.05; // 5% commission
const MAX_INVESTMENT_PER_TRADE = 10000; // Maximum investment per transaction

/**
 * Get current stock price
 */
function getCurrentStockPrice($pdo, $stockId) {
    $stmt = $pdo->prepare("
        SELECT price FROM stockEntry 
        WHERE stockId = ? 
        ORDER BY time DESC 
        LIMIT 1
    ");
    $stmt->execute([$stockId]);
    $result = $stmt->fetch();
    return $result ? $result['price'] : null;
}

/**
 * Get latest stock entry (price + trends)
 */
function getLatestStockEntry($pdo, $stockId) {
    $stmt = $pdo->prepare("
        SELECT * FROM stockEntry 
        WHERE stockId = ? 
        ORDER BY time DESC 
        LIMIT 1
    ");
    $stmt->execute([$stockId]);
    return $stmt->fetch();
}

/**
 * Get user's stock holdings
 */
function getUserStockHolding($pdo, $userId, $stockId) {
    $stmt = $pdo->prepare("
        SELECT * FROM userStockHoldings 
        WHERE userId = ? AND stockId = ?
    ");
    $stmt->execute([$userId, $stockId]);
    return $stmt->fetch();
}

/**
 * Get all user holdings with current prices
 */
function getUserPortfolio($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT 
            h.*,
            s.stockName,
            (SELECT price FROM stockEntry WHERE stockId = s.stockId ORDER BY time DESC LIMIT 1) as currentPrice
        FROM userStockHoldings h
        JOIN stocks s ON h.stockId = s.stockId
        WHERE h.userId = ? AND h.shares > 0
        ORDER BY s.stockName
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Calculate portfolio value
 */
function getPortfolioValue($pdo, $userId) {
    $portfolio = getUserPortfolio($pdo, $userId);
    $totalValue = 0;
    
    foreach ($portfolio as $holding) {
        $totalValue += $holding['shares'] * $holding['currentPrice'];
    }
    
    return $totalValue;
}

/**
 * Process buy transaction
 */
function buyStock($pdo, $userId, $stockId, $shares, $inventoryId, $userMoney) {
    try {
        $currentPrice = getCurrentStockPrice($pdo, $stockId);
        
        if (!$currentPrice) {
            return ['success' => false, 'error' => 'Stock not found'];
        }
        
        if ($shares <= 0) {
            return ['success' => false, 'error' => 'Shares must be positive'];
        }
        
        // Calculate costs
        $totalValue = $shares * $currentPrice;
        $commission = $totalValue * COMMISSION_RATE;
        $finalAmount = $totalValue + $commission;
        
        // Check investment limit
        if ($totalValue > MAX_INVESTMENT_PER_TRADE) {
            return ['success' => false, 'error' => 'Investment exceeds maximum limit per trade'];
        }
        
        // Check user has enough money
        if ($userMoney < $finalAmount) {
            return ['success' => false, 'error' => 'Insufficient funds. Need ' . number_format($finalAmount, 2) . ' Credits'];
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Log the transaction
        $stmt = $pdo->prepare("
            INSERT INTO stockTransactions 
            (userId, stockId, type, shares, pricePerShare, totalValue, commission, finalAmount, status)
            VALUES (?, ?, 'BUY', ?, ?, ?, ?, ?, 'COMPLETED')
        ");
        $stmt->execute([$userId, $stockId, $shares, $currentPrice, $totalValue, $commission, $finalAmount]);
        
        // Update user's money (from inventories table using Inventory_Id)
        $stmt = $pdo->prepare("UPDATE inventories SET Inventory_Money = Inventory_Money - ? WHERE Inventory_Id = ?");
        $stmt->execute([$finalAmount, $inventoryId]);
        
        // Update or create holding
        $holding = getUserStockHolding($pdo, $userId, $stockId);
        
        if ($holding) {
            // Update existing holding - calculate new average buy price
            $newShares = $holding['shares'] + $shares;
            $newAvgPrice = (($holding['shares'] * $holding['avgBuyPrice']) + ($shares * $currentPrice)) / $newShares;
            
            $stmt = $pdo->prepare("
                UPDATE userStockHoldings 
                SET shares = ?, avgBuyPrice = ? 
                WHERE userId = ? AND stockId = ?
            ");
            $stmt->execute([$newShares, $newAvgPrice, $userId, $stockId]);
        } else {
            // Create new holding
            $stmt = $pdo->prepare("
                INSERT INTO userStockHoldings (userId, stockId, shares, avgBuyPrice)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $stockId, $shares, $currentPrice]);
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Bought $shares shares at " . number_format($currentPrice, 2) . " Credits per share",
            'transactionDetails' => [
                'shares' => $shares,
                'pricePerShare' => $currentPrice,
                'totalValue' => $totalValue,
                'commission' => $commission,
                'finalAmount' => $finalAmount
            ]
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()];
    }
}

/**
 * Process sell transaction
 */
function sellStock($pdo, $userId, $stockId, $shares, $inventoryId) {
    try {
        $holding = getUserStockHolding($pdo, $userId, $stockId);
        
        if (!$holding || $holding['shares'] < $shares) {
            return ['success' => false, 'error' => 'Insufficient shares to sell'];
        }
        
        if ($shares <= 0) {
            return ['success' => false, 'error' => 'Shares must be positive'];
        }
        
        $currentPrice = getCurrentStockPrice($pdo, $stockId);
        
        if (!$currentPrice) {
            return ['success' => false, 'error' => 'Stock not found'];
        }
        
        // Calculate proceeds
        $totalValue = $shares * $currentPrice;
        $commission = $totalValue * COMMISSION_RATE;
        $finalAmount = $totalValue - $commission;
        
        // Check investment limit (based on total value)
        if ($totalValue > MAX_INVESTMENT_PER_TRADE) {
            return ['success' => false, 'error' => 'Sale exceeds maximum limit per trade'];
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Log the transaction
        $stmt = $pdo->prepare("
            INSERT INTO stockTransactions 
            (userId, stockId, type, shares, pricePerShare, totalValue, commission, finalAmount, status)
            VALUES (?, ?, 'SELL', ?, ?, ?, ?, ?, 'COMPLETED')
        ");
        $stmt->execute([$userId, $stockId, $shares, $currentPrice, $totalValue, $commission, $finalAmount]);
        
        // Update user's money (add proceeds to inventories table using Inventory_Id)
        $stmt = $pdo->prepare("UPDATE inventories SET Inventory_Money = Inventory_Money + ? WHERE Inventory_Id = ?");
        $stmt->execute([$finalAmount, $inventoryId]);
        
        // Update holding
        $newShares = $holding['shares'] - $shares;
        
        if ($newShares <= 0) {
            // Delete holding if no shares left
            $stmt = $pdo->prepare("DELETE FROM userStockHoldings WHERE userId = ? AND stockId = ?");
            $stmt->execute([$userId, $stockId]);
        } else {
            // Update holding
            $stmt = $pdo->prepare("UPDATE userStockHoldings SET shares = ? WHERE userId = ? AND stockId = ?");
            $stmt->execute([$newShares, $userId, $stockId]);
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Sold $shares shares at " . number_format($currentPrice, 2) . " Credits per share",
            'transactionDetails' => [
                'shares' => $shares,
                'pricePerShare' => $currentPrice,
                'totalValue' => $totalValue,
                'commission' => $commission,
                'finalAmount' => $finalAmount
            ]
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()];
    }
}

/**
 * Get stock history data for charting
 */
function getStockHistory($pdo, $stockId, $hours = 168) {
    $hours = (int)$hours; // Ensure it's an integer
    $stmt = $pdo->prepare("
        SELECT 
            price, 
            longTermTrend, 
            shortTermTrend,
            volatility,
            floor,
            ceiling,
            time
        FROM stockEntry 
        WHERE stockId = ? 
        ORDER BY time DESC 
        LIMIT $hours
    ");
    $stmt->execute([$stockId]);
    $data = array_reverse($stmt->fetchAll());
    return $data;
}

/**
 * Get transaction history for user
 */
function getUserTransactionHistory($pdo, $userId, $limit = 50) {
    $limit = (int)$limit; // Ensure it's an integer
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            s.stockName
        FROM stockTransactions t
        JOIN stocks s ON t.stockId = s.stockId
        WHERE t.userId = ?
        ORDER BY t.created_at DESC
        LIMIT $limit
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Get all stocks with current prices
 */
function getAllStocks($pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            COALESCE(latest.price, 0) as currentPrice,
            COALESCE(latest.longTermTrend, 0) as longTermTrend,
            COALESCE(latest.shortTermTrend, 0) as shortTermTrend,
            COALESCE(latest.volatility, 0) as volatility,
            COALESCE(prev.price, 0) as previousPrice
        FROM stocks s
        LEFT JOIN (
            SELECT stockId, price, longTermTrend, shortTermTrend, volatility
            FROM stockEntry 
            WHERE (stockId, time) IN (
                SELECT stockId, MAX(time) 
                FROM stockEntry 
                GROUP BY stockId
            )
        ) latest ON s.stockId = latest.stockId
        LEFT JOIN (
            SELECT stockId, price
            FROM stockEntry
            WHERE (stockId, time) IN (
                SELECT stockId, MAX(time)
                FROM stockEntry
                WHERE time < (
                    SELECT MAX(time) FROM stockEntry e2 WHERE e2.stockId = stockEntry.stockId
                )
                GROUP BY stockId
            )
        ) prev ON s.stockId = prev.stockId
        ORDER BY s.stockName
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Calculate price change percentage
 */
function getPriceChangePercent($currentPrice, $previousPrice) {
    if (!$previousPrice || $previousPrice == 0) return 0;
    return (($currentPrice - $previousPrice) / $previousPrice) * 100;
}

/**
 * Get price change over N periods
 */
function getPriceChangeOverPeriods($pdo, $stockId, $periods) {
    $limit = (int)$periods; // Ensure it's an integer
    $stmt = $pdo->prepare("
        SELECT price 
        FROM stockEntry 
        WHERE stockId = ? 
        ORDER BY time DESC 
        LIMIT $limit
    ");
    $stmt->execute([$stockId]);
    $results = $stmt->fetchAll();
    
    if (count($results) < 2) {
        return 0;
    }
    
    // Results are in DESC order, so newest is first, oldest is last
    $currentPrice = $results[0]['price'];
    $oldPrice = $results[count($results) - 1]['price'];
    
    return getPriceChangePercent($currentPrice, $oldPrice);
}

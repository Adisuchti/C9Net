<?php
/**
 * Analytics Data Helper
 * 
 * Functions to fetch data either from current tables or from historical snapshots
 */

/**
 * Get list of available snapshots
 */
function getAvailableSnapshots($pdo) {
    $stmt = $pdo->query("
        SELECT 
            snapshot_id, 
            snapshot_date, 
            snapshot_description,
            created_at
        FROM analytics_snapshots 
        ORDER BY snapshot_date DESC
    ");
    return $stmt->fetchAll();
}

/**
 * Get net worth data (either current or from snapshot)
 */
function getNetWorthData($pdo, $snapshotId = null) {
    if ($snapshotId) {
        // Query from snapshot tables
        // Use COALESCE to fall back to current market prices if snapshot market data doesn't exist
        $query = "
            SELECT 
                i.Inventory_Name,
                i.Inventory_Money,
                COALESCE(SUM(COALESCE(ms.Purchase_Price, mc.Purchase_Price) * ci.Item_Quantity), 0) AS Material_Value,
                COALESCE(SUM(ms.Purchase_Price * ci.Item_Quantity), 0) AS Material_Value_Snapshot,
                COALESCE(SUM(mc.Purchase_Price * ci.Item_Quantity), 0) AS Material_Value_Current,
                (i.Inventory_Money + COALESCE(SUM(COALESCE(ms.Purchase_Price, mc.Purchase_Price) * ci.Item_Quantity), 0) - 6500) AS Total_Net_Worth
            FROM analytics_inventories_snapshot i
            LEFT JOIN analytics_content_items_snapshot ci 
                ON ci.Inventory_Id = i.Inventory_Id AND ci.snapshot_id = :snapshot_id
            LEFT JOIN analytics_market_snapshot ms 
                ON ms.Market_Item_Class = ci.Item_Class AND ms.snapshot_id = :snapshot_id
            LEFT JOIN market mc 
                ON mc.Market_Item_Class = ci.Item_Class
            WHERE i.snapshot_id = :snapshot_id 
                AND i.Inventory_Id NOT IN (1,2,3)
                AND (COALESCE(ms.Market, mc.Market, 0) = 0)
            GROUP BY i.Inventory_Name, i.Inventory_Money
            ORDER BY Total_Net_Worth DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':snapshot_id' => $snapshotId]);
    } else {
        // Query from current tables
        $query = "
            SELECT 
                i.Inventory_Name,
                i.Inventory_Money,
                COALESCE(SUM(m.Purchase_Price * ci.Item_Quantity), 0) AS Material_Value,
                COALESCE(SUM(m.Purchase_Price * ci.Item_Quantity), 0) AS Material_Value_Snapshot,
                0 AS Material_Value_Current,
                (i.Inventory_Money + COALESCE(SUM(m.Purchase_Price * ci.Item_Quantity), 0) - 6500) AS Total_Net_Worth
            FROM inventories i
            LEFT JOIN content_items ci ON ci.Inventory_Id = i.Inventory_Id
            LEFT JOIN market m ON m.Market_Item_Class = ci.Item_Class
            WHERE (m.Market = 0 OR m.Market IS NULL) AND i.Inventory_Id NOT IN (1,2,3)
            GROUP BY i.Inventory_Name, i.Inventory_Money
            ORDER BY Total_Net_Worth DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
    }
    
    return $stmt->fetchAll();
}

/**
 * Get ammo data (either current or from snapshot)
 */
function getAmmoData($pdo, $snapshotId = null) {
    if ($snapshotId) {
        // Query from snapshot tables
        $query = "
            SELECT 
                i.Inventory_Name,
                CASE
                    WHEN ci.Item_Class LIKE '%9x21%' THEN '9x21'
                    WHEN ci.Item_Class LIKE '%9x19%' THEN '9x19'
                    WHEN ci.Item_Class LIKE '%45ACP%' THEN '45ACP'
                    WHEN ci.Item_Class LIKE '%45HP%' THEN '45HP'
                    WHEN ci.Item_Class LIKE '%556x45%' THEN '556x45'
                    WHEN ci.Item_Class LIKE '%570x28%' THEN '570x28'
                    WHEN ci.Item_Class LIKE '%65x39%' THEN '65x39'
                    WHEN ci.Item_Class LIKE '%762x39%' THEN '762x39'
                    WHEN ci.Item_Class LIKE '%12Gauge%' THEN '12Gauge'
                    WHEN ci.Item_Class LIKE '%58x42%' THEN '58x42'
                    WHEN ci.Item_Class LIKE '%762x51%' THEN '762x51'
                    WHEN ci.Item_Class LIKE '%762x54%' THEN '762x54'
                    WHEN ci.Item_Class LIKE '%127x54%' THEN '127x54'
                    WHEN ci.Item_Class LIKE '%127x55%' THEN '127x55'
                    WHEN ci.Item_Class LIKE '%127x99%' THEN '127x99'
                    WHEN ci.Item_Class LIKE '%127x108%' THEN '127x108'
                    WHEN ci.Item_Class LIKE '%338%' THEN '338'
                    WHEN ci.Item_Class LIKE '%408%' THEN '408'
                    WHEN ci.Item_Class LIKE '%93x64%' THEN '93x64'
                    WHEN ci.Item_Class LIKE '%650x39%' THEN '650x39'
                    WHEN ci.Item_Class LIKE '%HE%' THEN 'HE'
                    ELSE NULL
                END AS Ammo_Type,
                SUM(CAST(ci.Item_Quantity AS UNSIGNED) * CAST(COALESCE(ci.Item_Properties, 0) AS UNSIGNED)) AS Total_Quantity
            FROM 
                analytics_inventories_snapshot i
            LEFT JOIN analytics_content_items_snapshot ci 
                ON ci.Inventory_Id = i.Inventory_Id AND ci.snapshot_id = :snapshot_id
            WHERE
                i.snapshot_id = :snapshot_id
                AND i.Inventory_Id NOT IN (1, 2, 3)
                AND CASE
                    WHEN ci.Item_Class LIKE '%9x21%' THEN '9x21'
                    WHEN ci.Item_Class LIKE '%9x19%' THEN '9x19'
                    WHEN ci.Item_Class LIKE '%45ACP%' THEN '45ACP'
                    WHEN ci.Item_Class LIKE '%45HP%' THEN '45HP'
                    WHEN ci.Item_Class LIKE '%556x45%' THEN '556x45'
                    WHEN ci.Item_Class LIKE '%570x28%' THEN '570x28'
                    WHEN ci.Item_Class LIKE '%65x39%' THEN '65x39'
                    WHEN ci.Item_Class LIKE '%762x39%' THEN '762x39'
                    WHEN ci.Item_Class LIKE '%12Gauge%' THEN '12Gauge'
                    WHEN ci.Item_Class LIKE '%58x42%' THEN '58x42'
                    WHEN ci.Item_Class LIKE '%762x51%' THEN '762x51'
                    WHEN ci.Item_Class LIKE '%762x54%' THEN '762x54'
                    WHEN ci.Item_Class LIKE '%127x54%' THEN '127x54'
                    WHEN ci.Item_Class LIKE '%127x55%' THEN '127x55'
                    WHEN ci.Item_Class LIKE '%127x99%' THEN '127x99'
                    WHEN ci.Item_Class LIKE '%127x108%' THEN '127x108'
                    WHEN ci.Item_Class LIKE '%338%' THEN '338'
                    WHEN ci.Item_Class LIKE '%408%' THEN '408'
                    WHEN ci.Item_Class LIKE '%93x64%' THEN '93x64'
                    WHEN ci.Item_Class LIKE '%650x39%' THEN '650x39'
                    WHEN ci.Item_Class LIKE '%HE%' THEN 'HE'
                    ELSE NULL
                END IS NOT NULL
            GROUP BY 
                i.Inventory_Name, Ammo_Type
            ORDER BY 
                i.Inventory_Name ASC, Total_Quantity DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':snapshot_id' => $snapshotId]);
    } else {
        // Query from current tables
        $query = "
            SELECT 
                i.Inventory_Name,
                CASE
                    WHEN ci.Item_Class LIKE '%9x21%' THEN '9x21'
                    WHEN ci.Item_Class LIKE '%9x19%' THEN '9x19'
                    WHEN ci.Item_Class LIKE '%45ACP%' THEN '45ACP'
                    WHEN ci.Item_Class LIKE '%45HP%' THEN '45HP'
                    WHEN ci.Item_Class LIKE '%556x45%' THEN '556x45'
                    WHEN ci.Item_Class LIKE '%570x28%' THEN '570x28'
                    WHEN ci.Item_Class LIKE '%65x39%' THEN '65x39'
                    WHEN ci.Item_Class LIKE '%762x39%' THEN '762x39'
                    WHEN ci.Item_Class LIKE '%12Gauge%' THEN '12Gauge'
                    WHEN ci.Item_Class LIKE '%58x42%' THEN '58x42'
                    WHEN ci.Item_Class LIKE '%762x51%' THEN '762x51'
                    WHEN ci.Item_Class LIKE '%762x54%' THEN '762x54'
                    WHEN ci.Item_Class LIKE '%127x54%' THEN '127x54'
                    WHEN ci.Item_Class LIKE '%127x55%' THEN '127x55'
                    WHEN ci.Item_Class LIKE '%127x99%' THEN '127x99'
                    WHEN ci.Item_Class LIKE '%127x108%' THEN '127x108'
                    WHEN ci.Item_Class LIKE '%338%' THEN '338'
                    WHEN ci.Item_Class LIKE '%408%' THEN '408'
                    WHEN ci.Item_Class LIKE '%93x64%' THEN '93x64'
                    WHEN ci.Item_Class LIKE '%650x39%' THEN '650x39'
                    WHEN ci.Item_Class LIKE '%HE%' THEN 'HE'
                    ELSE NULL
                END AS Ammo_Type,
                SUM(CAST(ci.Item_Quantity AS UNSIGNED) * CAST(COALESCE(ci.Item_Properties, 0) AS UNSIGNED)) AS Total_Quantity
            FROM 
                inventories i
            LEFT JOIN content_items ci ON ci.Inventory_Id = i.Inventory_Id
            WHERE
                i.Inventory_Id NOT IN (1, 2, 3)
                AND CASE
                    WHEN ci.Item_Class LIKE '%9x21%' THEN '9x21'
                    WHEN ci.Item_Class LIKE '%9x19%' THEN '9x19'
                    WHEN ci.Item_Class LIKE '%45ACP%' THEN '45ACP'
                    WHEN ci.Item_Class LIKE '%45HP%' THEN '45HP'
                    WHEN ci.Item_Class LIKE '%556x45%' THEN '556x45'
                    WHEN ci.Item_Class LIKE '%570x28%' THEN '570x28'
                    WHEN ci.Item_Class LIKE '%65x39%' THEN '65x39'
                    WHEN ci.Item_Class LIKE '%762x39%' THEN '762x39'
                    WHEN ci.Item_Class LIKE '%12Gauge%' THEN '12Gauge'
                    WHEN ci.Item_Class LIKE '%58x42%' THEN '58x42'
                    WHEN ci.Item_Class LIKE '%762x51%' THEN '762x51'
                    WHEN ci.Item_Class LIKE '%762x54%' THEN '762x54'
                    WHEN ci.Item_Class LIKE '%127x54%' THEN '127x54'
                    WHEN ci.Item_Class LIKE '%127x55%' THEN '127x55'
                    WHEN ci.Item_Class LIKE '%127x99%' THEN '127x99'
                    WHEN ci.Item_Class LIKE '%127x108%' THEN '127x108'
                    WHEN ci.Item_Class LIKE '%338%' THEN '338'
                    WHEN ci.Item_Class LIKE '%408%' THEN '408'
                    WHEN ci.Item_Class LIKE '%93x64%' THEN '93x64'
                    WHEN ci.Item_Class LIKE '%650x39%' THEN '650x39'
                    WHEN ci.Item_Class LIKE '%HE%' THEN 'HE'
                    ELSE NULL
                END IS NOT NULL
            GROUP BY 
                i.Inventory_Name, Ammo_Type
            ORDER BY 
                i.Inventory_Name ASC, Total_Quantity DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
    }
    
    return $stmt->fetchAll();
}

/**
 * Process ammo data into structured arrays
 */
function processAmmoData($ammoData) {
    $ammoByType = [];
    $ammoByTypeAndInventory = [];
    
    foreach ($ammoData as $row) {
        $ammo_type = $row['Ammo_Type'];
        $inventory_name = $row['Inventory_Name'];
        $quantity = $row['Total_Quantity'];
        
        if (!isset($ammoByType[$ammo_type])) {
            $ammoByType[$ammo_type] = 0;
        }
        $ammoByType[$ammo_type] += $quantity;
        
        if (!isset($ammoByTypeAndInventory[$ammo_type])) {
            $ammoByTypeAndInventory[$ammo_type] = [];
        }
        $ammoByTypeAndInventory[$ammo_type][$inventory_name] = $quantity;
    }
    
    // Filter out ammo types with 0 quantity
    $ammoByType = array_filter($ammoByType, fn($v) => $v > 0);
    arsort($ammoByType);
    
    return [
        'byType' => $ammoByType,
        'byTypeAndInventory' => $ammoByTypeAndInventory
    ];
}

/**
 * Get weapon ownership data by type (either current or from snapshot)
 */
function getWeaponData($pdo, $snapshotId = null) {
    if ($snapshotId) {
        // Query from snapshot tables
        $query = "
            SELECT 
                i.Inventory_Name,
                custom_item_types.Custom_Item_Type,
                SUM(ci.Item_Quantity) AS Total_Quantity
            FROM analytics_inventories_snapshot i
            LEFT JOIN analytics_content_items_snapshot ci 
                ON ci.Inventory_Id = i.Inventory_Id AND ci.snapshot_id = :snapshot_id
            LEFT JOIN items ON items.item_class = ci.Item_Class
            LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
            LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
            WHERE i.snapshot_id = :snapshot_id
                AND i.Inventory_Id != 1 AND i.Inventory_Id != 2 AND i.Inventory_Id != 3
                AND custom_item_types.Custom_Item_Type IS NOT NULL
            GROUP BY i.Inventory_Name, custom_item_types.Custom_Item_Type
            ORDER BY i.Inventory_Name ASC, Total_Quantity DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':snapshot_id' => $snapshotId]);
    } else {
        // Query from current tables
        $query = "
            SELECT 
                i.Inventory_Name,
                custom_item_types.Custom_Item_Type,
                SUM(ci.Item_Quantity) AS Total_Quantity
            FROM inventories i
            LEFT JOIN content_items ci ON ci.Inventory_Id = i.Inventory_Id
            LEFT JOIN items ON items.item_class = ci.Item_Class
            LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
            LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
            WHERE i.Inventory_Id != 1 AND i.Inventory_Id != 2 AND i.Inventory_Id != 3
                AND custom_item_types.Custom_Item_Type IS NOT NULL
            GROUP BY i.Inventory_Name, custom_item_types.Custom_Item_Type
            ORDER BY i.Inventory_Name ASC, Total_Quantity DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
    }
    
    return $stmt->fetchAll();
}

/**
 * Process weapon data into structured arrays
 */
function processWeaponData($weaponData) {
    $weaponByType = [];
    $weaponByTypeAndInventory = [];
    
    foreach ($weaponData as $row) {
        $weapon_type = $row['Custom_Item_Type'];
        $inventory_name = $row['Inventory_Name'];
        $quantity = $row['Total_Quantity'];
        
        if (!isset($weaponByType[$weapon_type])) {
            $weaponByType[$weapon_type] = 0;
        }
        $weaponByType[$weapon_type] += $quantity;
        
        if (!isset($weaponByTypeAndInventory[$weapon_type])) {
            $weaponByTypeAndInventory[$weapon_type] = [];
        }
        $weaponByTypeAndInventory[$weapon_type][$inventory_name] = $quantity;
    }
    
    // Filter out weapon types with 0 quantity
    $weaponByType = array_filter($weaponByType, fn($v) => $v > 0);
    arsort($weaponByType);
    
    return [
        'byType' => $weaponByType,
        'byTypeAndInventory' => $weaponByTypeAndInventory
    ];
}

/**
 * Get weapon ownership data by class name (either current or from snapshot)
 */
function getWeaponByClassName($pdo, $snapshotId = null) {
    if ($snapshotId) {
        // Query from snapshot tables
        $query = "
            SELECT 
            base.Item_Class,
            COALESCE(items.Item_Display_Name, base.Item_Class) AS Item_Display_Name,
            cit.Custom_Item_Type,
            base.Total_Quantity
            FROM (
                SELECT Item_Class, SUM(Item_Quantity) AS Total_Quantity
                FROM analytics_content_items_snapshot
                WHERE snapshot_id = :snapshot_id
                    AND Inventory_Id NOT IN (1,2,3)
                GROUP BY Item_Class
            ) AS base
            LEFT JOIN items ON items.item_class = base.Item_Class
            LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
            LEFT JOIN custom_item_types cit ON cit.Original_Item_Type = item_types.item_classification
            WHERE cit.Custom_Item_Type IS NOT NULL
            ORDER BY cit.Custom_Item_Type, base.Total_Quantity DESC;
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':snapshot_id' => $snapshotId]);
    } else {
        // Query from current tables
        $query = "
            WITH base AS (
            SELECT Item_Class, SUM(Item_Quantity) AS Total_Quantity
            FROM content_items
            WHERE Inventory_Id NOT IN (1,2,3)
            GROUP BY Item_Class
            )
            SELECT 
            base.Item_Class,
            COALESCE(items.Item_Display_Name, base.Item_Class) AS Item_Display_Name,
            cit.Custom_Item_Type,
            base.Total_Quantity
            FROM base
            LEFT JOIN items ON items.item_class = base.Item_Class
            LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
            LEFT JOIN custom_item_types cit ON cit.Original_Item_Type = item_types.item_classification
            WHERE cit.Custom_Item_Type IS NOT NULL
            ORDER BY cit.Custom_Item_Type, base.Total_Quantity DESC;
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
    }
    
    return $stmt->fetchAll();
}

/**
 * Process weapon by class name data into structured arrays
 */
function processWeaponByClassName($weaponClassData) {
    $weaponByClass = [];
    $weaponByTypeAndClass = [];
    
    foreach ($weaponClassData as $row) {
        $weapon_type = $row['Custom_Item_Type'];
        $item_class = $row['Item_Class'];
        $display_name = $row['Item_Display_Name'];
        $quantity = $row['Total_Quantity'];
        
        // Store by class
        $weaponByClass[$item_class] = [
            'display_name' => $display_name,
            'type' => $weapon_type,
            'quantity' => $quantity
        ];
        
        // Store by type and class
        if (!isset($weaponByTypeAndClass[$weapon_type])) {
            $weaponByTypeAndClass[$weapon_type] = [];
        }
        $weaponByTypeAndClass[$weapon_type][$item_class] = [
            'display_name' => $display_name,
            'quantity' => $quantity
        ];
    }
    
    return [
        'byClass' => $weaponByClass,
        'byTypeAndClass' => $weaponByTypeAndClass
    ];
}

/**
 * Get weapon purchases from logs, grouped by week
 * Filters for items where Comment = 'purchase' and the item is a weapon type
 */
function getWeaponPurchasesByWeek($pdo) {
    $query = "
    SELECT 
        YEARWEEK(l.Transaction_Date, 1) AS week_key,
        DATE(DATE_SUB(l.Transaction_Date, INTERVAL WEEKDAY(l.Transaction_Date) DAY)) AS week_start,
        l.Transaction_Item AS Item_Class,
        COALESCE(items.Item_Display_Name, l.Transaction_Item) AS Item_Display_Name,
        cit.Custom_Item_Type,
        SUM(l.Transaction_Quantity) AS Total_Purchased
    FROM logs l
    LEFT JOIN (
        SELECT item_class, MAX(Item_Display_Name) AS Item_Display_Name, MAX(Item_Type) AS Item_Type
        FROM items
        GROUP BY item_class
    ) AS items ON items.item_class = l.Transaction_Item
    LEFT JOIN (
        SELECT Item_Type_Id, MAX(item_classification) AS item_classification
        FROM item_types
        GROUP BY Item_Type_Id
    ) AS item_types ON item_types.Item_Type_Id = items.Item_Type
    LEFT JOIN (
        SELECT Original_Item_Type, MAX(Custom_Item_Type) AS Custom_Item_Type
        FROM custom_item_types
        GROUP BY Original_Item_Type
    ) AS cit ON cit.Original_Item_Type = item_types.item_classification
    WHERE l.Comment = 'purchase'
      AND cit.Custom_Item_Type IN ('Primary_Weapon', 'Sidearm', 'Launcher')
    GROUP BY week_key, week_start, l.Transaction_Item, items.Item_Display_Name, cit.Custom_Item_Type
    ORDER BY week_start ASC, l.Transaction_Item ASC;
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get ammo purchases from logs, grouped by week and caliber
 */
function getAmmoPurchasesByWeek($pdo) {
    $query = "
    SELECT 
        YEARWEEK(l.Transaction_Date, 1) AS week_key,
        DATE(DATE_SUB(l.Transaction_Date, INTERVAL WEEKDAY(l.Transaction_Date) DAY)) AS week_start,
        CASE
            WHEN l.Transaction_Item LIKE '%9x21%' THEN '9x21'
            WHEN l.Transaction_Item LIKE '%9x19%' THEN '9x19'
            WHEN l.Transaction_Item LIKE '%45ACP%' THEN '45ACP'
            WHEN l.Transaction_Item LIKE '%45HP%' THEN '45HP'
            WHEN l.Transaction_Item LIKE '%556x45%' THEN '556x45'
            WHEN l.Transaction_Item LIKE '%570x28%' THEN '570x28'
            WHEN l.Transaction_Item LIKE '%65x39%' THEN '65x39'
            WHEN l.Transaction_Item LIKE '%762x39%' THEN '762x39'
            WHEN l.Transaction_Item LIKE '%12Gauge%' THEN '12Gauge'
            WHEN l.Transaction_Item LIKE '%58x42%' THEN '58x42'
            WHEN l.Transaction_Item LIKE '%762x51%' THEN '762x51'
            WHEN l.Transaction_Item LIKE '%762x54%' THEN '762x54'
            WHEN l.Transaction_Item LIKE '%127x54%' THEN '127x54'
            WHEN l.Transaction_Item LIKE '%127x55%' THEN '127x55'
            WHEN l.Transaction_Item LIKE '%127x99%' THEN '127x99'
            WHEN l.Transaction_Item LIKE '%127x108%' THEN '127x108'
            WHEN l.Transaction_Item LIKE '%338%' THEN '338'
            WHEN l.Transaction_Item LIKE '%408%' THEN '408'
            WHEN l.Transaction_Item LIKE '%93x64%' THEN '93x64'
            WHEN l.Transaction_Item LIKE '%650x39%' THEN '650x39'
            WHEN l.Transaction_Item LIKE '%HE%' THEN 'HE'
            ELSE NULL
        END AS Ammo_Type,
        SUM(l.Transaction_Quantity * CAST(COALESCE(m.Ammo_Count, 1) AS UNSIGNED)) AS Total_Purchased
    FROM logs l
    LEFT JOIN market m ON m.Market_Item_Class = l.Transaction_Item
    WHERE l.Comment = 'purchase'
      AND CASE
            WHEN l.Transaction_Item LIKE '%9x21%' THEN '9x21'
            WHEN l.Transaction_Item LIKE '%9x19%' THEN '9x19'
            WHEN l.Transaction_Item LIKE '%45ACP%' THEN '45ACP'
            WHEN l.Transaction_Item LIKE '%45HP%' THEN '45HP'
            WHEN l.Transaction_Item LIKE '%556x45%' THEN '556x45'
            WHEN l.Transaction_Item LIKE '%570x28%' THEN '570x28'
            WHEN l.Transaction_Item LIKE '%65x39%' THEN '65x39'
            WHEN l.Transaction_Item LIKE '%762x39%' THEN '762x39'
            WHEN l.Transaction_Item LIKE '%12Gauge%' THEN '12Gauge'
            WHEN l.Transaction_Item LIKE '%58x42%' THEN '58x42'
            WHEN l.Transaction_Item LIKE '%762x51%' THEN '762x51'
            WHEN l.Transaction_Item LIKE '%762x54%' THEN '762x54'
            WHEN l.Transaction_Item LIKE '%127x54%' THEN '127x54'
            WHEN l.Transaction_Item LIKE '%127x55%' THEN '127x55'
            WHEN l.Transaction_Item LIKE '%127x99%' THEN '127x99'
            WHEN l.Transaction_Item LIKE '%127x108%' THEN '127x108'
            WHEN l.Transaction_Item LIKE '%338%' THEN '338'
            WHEN l.Transaction_Item LIKE '%408%' THEN '408'
            WHEN l.Transaction_Item LIKE '%93x64%' THEN '93x64'
            WHEN l.Transaction_Item LIKE '%650x39%' THEN '650x39'
            WHEN l.Transaction_Item LIKE '%HE%' THEN 'HE'
            ELSE NULL
          END IS NOT NULL
    GROUP BY week_key, week_start, Ammo_Type
    ORDER BY week_start ASC, Ammo_Type ASC;
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Process ammo purchase data into structured arrays for charting
 */
function processAmmoPurchasesByWeek($purchaseData) {
    $weekLabels = [];
    $ammoTypes = [];
    $purchasesByWeekAndType = [];
    
    foreach ($purchaseData as $row) {
        $weekKey = $row['week_key'];
        $weekStart = $row['week_start'];
        $ammoType = $row['Ammo_Type'];
        $quantity = (int)$row['Total_Purchased'];
        
        if (!isset($weekLabels[$weekKey])) {
            $weekLabels[$weekKey] = date('M j, Y', strtotime($weekStart));
        }
        
        if (!isset($ammoTypes[$ammoType])) {
            $ammoTypes[$ammoType] = true;
        }
        
        if (!isset($purchasesByWeekAndType[$weekKey])) {
            $purchasesByWeekAndType[$weekKey] = [];
        }
        $purchasesByWeekAndType[$weekKey][$ammoType] = $quantity;
    }
    
    // Sort ammo types alphabetically
    ksort($ammoTypes);
    
    $datasets = [];
    foreach (array_keys($ammoTypes) as $ammoType) {
        $data = [];
        foreach (array_keys($weekLabels) as $weekKey) {
            $data[] = isset($purchasesByWeekAndType[$weekKey][$ammoType]) 
                ? $purchasesByWeekAndType[$weekKey][$ammoType] 
                : 0;
        }
        $datasets[$ammoType] = $data;
    }
    
    return [
        'labels' => array_values($weekLabels),
        'datasets' => $datasets
    ];
}

/**
 * Process weapon purchase data into structured arrays for charting
 */
function processWeaponPurchasesByWeek($purchaseData) {
    $weekLabels = [];
    $weaponClasses = [];
    $purchasesByWeekAndClass = [];
    
    // First pass: collect all unique weeks and weapon classes
    foreach ($purchaseData as $row) {
        $weekKey = $row['week_key'];
        $weekStart = $row['week_start'];
        $itemClass = $row['Item_Class'];
        $displayName = $row['Item_Display_Name'];
        $quantity = (int)$row['Total_Purchased'];
        
        // Track week labels
        if (!isset($weekLabels[$weekKey])) {
            $weekLabels[$weekKey] = date('M j, Y', strtotime($weekStart));
        }
        
        // Track weapon classes with their display names
        if (!isset($weaponClasses[$itemClass])) {
            $weaponClasses[$itemClass] = $displayName;
        }
        
        // Store purchase data
        if (!isset($purchasesByWeekAndClass[$weekKey])) {
            $purchasesByWeekAndClass[$weekKey] = [];
        }
        $purchasesByWeekAndClass[$weekKey][$itemClass] = $quantity;
    }
    
    // Build datasets for each weapon class
    $datasets = [];
    foreach ($weaponClasses as $itemClass => $displayName) {
        $data = [];
        foreach (array_keys($weekLabels) as $weekKey) {
            $data[] = isset($purchasesByWeekAndClass[$weekKey][$itemClass]) 
                ? $purchasesByWeekAndClass[$weekKey][$itemClass] 
                : 0;
        }
        $datasets[$displayName] = $data;
    }
    
    return [
        'labels' => array_values($weekLabels),
        'datasets' => $datasets
    ];
}


/**
 * Get ammunition usage from logs (removed/retrieved ammo), grouped by session (noon-to-noon GMT)
 * Only counts ammo that was removed from inventory (Transaction_Quantity < 0)
 * Multiplies by Ammo_Count from market table to get actual rounds
 */
function getAmmoUsageBySession($pdo) {
    $query = "
        SELECT
            TIMESTAMP(
                DATE(DATE_SUB(l.Transaction_Date, INTERVAL 12 HOUR)),
                '12:00:00'
            ) AS Session_Start,
            CASE
                WHEN l.Transaction_Item LIKE '%9x21%' THEN '9x21'
                WHEN l.Transaction_Item LIKE '%9x19%' THEN '9x19'
                WHEN l.Transaction_Item LIKE '%45ACP%' THEN '45ACP'
                WHEN l.Transaction_Item LIKE '%45HP%' THEN '45HP'
                WHEN l.Transaction_Item LIKE '%556x45%' THEN '556x45'
                WHEN l.Transaction_Item LIKE '%570x28%' THEN '570x28'
                WHEN l.Transaction_Item LIKE '%65x39%' THEN '65x39'
                WHEN l.Transaction_Item LIKE '%762x39%' THEN '762x39'
                WHEN l.Transaction_Item LIKE '%12Gauge%' THEN '12Gauge'
                WHEN l.Transaction_Item LIKE '%58x42%' THEN '58x42'
                WHEN l.Transaction_Item LIKE '%762x51%' THEN '762x51'
                WHEN l.Transaction_Item LIKE '%762x54%' THEN '762x54'
                WHEN l.Transaction_Item LIKE '%127x54%' THEN '127x54'
                WHEN l.Transaction_Item LIKE '%127x55%' THEN '127x55'
                WHEN l.Transaction_Item LIKE '%127x99%' THEN '127x99'
                WHEN l.Transaction_Item LIKE '%127x108%' THEN '127x108'
                WHEN l.Transaction_Item LIKE '%338%' THEN '338'
                WHEN l.Transaction_Item LIKE '%408%' THEN '408'
                WHEN l.Transaction_Item LIKE '%93x64%' THEN '93x64'
                WHEN l.Transaction_Item LIKE '%650x39%' THEN '650x39'
                WHEN l.Transaction_Item LIKE '%HE%' THEN 'HE'
                ELSE NULL
            END AS Ammo_Type,
            SUM(
                ABS(l.Transaction_Quantity) * CAST(COALESCE(m.Ammo_Count, 1) AS UNSIGNED)
            ) AS Rounds_Used
        FROM logs l
        LEFT JOIN market m ON m.Market_Item_Class = l.Transaction_Item
        WHERE l.isMarketActivity = 0
          AND l.Transaction_Quantity < 0
          AND l.Transaction_Date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
          AND CASE
                WHEN l.Transaction_Item LIKE '%9x21%' THEN '9x21'
                WHEN l.Transaction_Item LIKE '%9x19%' THEN '9x19'
                WHEN l.Transaction_Item LIKE '%45ACP%' THEN '45ACP'
                WHEN l.Transaction_Item LIKE '%45HP%' THEN '45HP'
                WHEN l.Transaction_Item LIKE '%556x45%' THEN '556x45'
                WHEN l.Transaction_Item LIKE '%570x28%' THEN '570x28'
                WHEN l.Transaction_Item LIKE '%65x39%' THEN '65x39'
                WHEN l.Transaction_Item LIKE '%762x39%' THEN '762x39'
                WHEN l.Transaction_Item LIKE '%12Gauge%' THEN '12Gauge'
                WHEN l.Transaction_Item LIKE '%58x42%' THEN '58x42'
                WHEN l.Transaction_Item LIKE '%762x51%' THEN '762x51'
                WHEN l.Transaction_Item LIKE '%762x54%' THEN '762x54'
                WHEN l.Transaction_Item LIKE '%127x54%' THEN '127x54'
                WHEN l.Transaction_Item LIKE '%127x55%' THEN '127x55'
                WHEN l.Transaction_Item LIKE '%127x99%' THEN '127x99'
                WHEN l.Transaction_Item LIKE '%127x108%' THEN '127x108'
                WHEN l.Transaction_Item LIKE '%338%' THEN '338'
                WHEN l.Transaction_Item LIKE '%408%' THEN '408'
                WHEN l.Transaction_Item LIKE '%93x64%' THEN '93x64'
                WHEN l.Transaction_Item LIKE '%650x39%' THEN '650x39'
                WHEN l.Transaction_Item LIKE '%HE%' THEN 'HE'
                ELSE NULL
              END IS NOT NULL
        GROUP BY Session_Start, Ammo_Type
        ORDER BY Session_Start ASC, Ammo_Type ASC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Process ammo usage by session data into structured arrays for charting
 */
function processAmmoUsageBySession($usageData) {
    $sessionLabels = [];
    $ammoTypes = [];
    $usageBySessionAndType = [];
    
    foreach ($usageData as $row) {
        $sessionStart = $row['Session_Start'];
        $ammoType = $row['Ammo_Type'];
        $roundsUsed = (int)$row['Rounds_Used'];
        
        if (!isset($sessionLabels[$sessionStart])) {
            $sessionLabels[$sessionStart] = date('M j, Y H:i', strtotime($sessionStart));
        }
        
        if (!isset($ammoTypes[$ammoType])) {
            $ammoTypes[$ammoType] = true;
        }
        
        if (!isset($usageBySessionAndType[$sessionStart])) {
            $usageBySessionAndType[$sessionStart] = [];
        }
        $usageBySessionAndType[$sessionStart][$ammoType] = $roundsUsed;
    }
    
    ksort($ammoTypes);
    
    $datasets = [];
    foreach (array_keys($ammoTypes) as $ammoType) {
        $data = [];
        foreach (array_keys($sessionLabels) as $sessionKey) {
            $data[] = isset($usageBySessionAndType[$sessionKey][$ammoType])
                ? $usageBySessionAndType[$sessionKey][$ammoType]
                : 0;
        }
        $datasets[$ammoType] = $data;
    }
    
    return [
        'labels' => array_values($sessionLabels),
        'datasets' => $datasets
    ];
}

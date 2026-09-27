<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$itemClass = trim((string)($_GET['itemClass'] ?? ''));

if (empty($itemClass)) {
    echo json_encode([]);
    exit();
}

try {
    $normalizeClass = static function (?string $value): string {
        return strtoupper(trim((string)$value));
    };

    // Resolve to canonical item_class to avoid case/whitespace mismatches between market and graph data.
    $canonicalStmt = $pdo->prepare("SELECT item_class FROM items WHERE LOWER(TRIM(item_class)) = LOWER(TRIM(?)) LIMIT 1");
    $canonicalStmt->execute([$itemClass]);
    $canonicalClass = $canonicalStmt->fetchColumn();
    if ($canonicalClass) {
        $itemClass = $canonicalClass;
    }

    $sourceKey = $normalizeClass($itemClass);

    // Build complete graph of all edges (legacy + directed)
    $edges = [];
    $allItems = [];

    // Legacy paint-style rules (bidirectional and base-group transitive behavior)
    $legacyQuery = "SELECT DISTINCT 
                CASE 
                    WHEN i.Base_Item_Class = ? THEN i.Interchangable_Item_Class 
                    ELSE i.Base_Item_Class 
                END as item_class,
                i.Change_Cost as cost,
                IFNULL(items.Item_Display_Name, 
                    CASE 
                        WHEN i.Base_Item_Class = ? THEN i.Interchangable_Item_Class 
                        ELSE i.Base_Item_Class 
                    END
                ) as display_name,
                item_types.item_classification as item_type
              FROM interchangable_items i
              LEFT JOIN items ON items.item_class = (
                  CASE 
                      WHEN i.Base_Item_Class = ? THEN i.Interchangable_Item_Class 
                      ELSE i.Base_Item_Class 
                  END
              )
              LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
              WHERE i.Base_Item_Class = ? OR i.Interchangable_Item_Class = ?
              UNION
              SELECT DISTINCT 
                i2.Interchangable_Item_Class as item_class,
                i2.Change_Cost as cost,
                IFNULL(items2.Item_Display_Name, i2.Interchangable_Item_Class) as display_name,
                item_types2.item_classification as item_type
              FROM interchangable_items i1
              JOIN interchangable_items i2 ON i1.Base_Item_Class = i2.Base_Item_Class
              LEFT JOIN items items2 ON items2.item_class = i2.Interchangable_Item_Class
              LEFT JOIN item_types item_types2 ON item_types2.Item_Type_Id = items2.Item_Type
              WHERE i1.Interchangable_Item_Class = ? AND i2.Interchangable_Item_Class != ?";

    $legacyStmt = $pdo->prepare($legacyQuery);
    $legacyStmt->execute([
        $itemClass, $itemClass, $itemClass, $itemClass, $itemClass, $itemClass, $itemClass
    ]);
    $legacyItems = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);

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
        $source = $normalizeClass($edge['source']);
        $target = $normalizeClass($edge['target']);
        $cost = (float)$edge['cost'];
        if ($source === '' || $target === '') {
            continue;
        }
        if (!isset($edges[$source])) {
            $edges[$source] = [];
        }
        if (!isset($edges[$source][$target]) || $edges[$source][$target] > $cost) {
            $edges[$source][$target] = $cost;
        }
    }

    // New directed non-transitive rules
    try {
        $directedQuery = "SELECT
                r.Source_Item_Class as source,
                r.Target_Item_Class as target,
                r.Change_Cost as cost
            FROM interchangeable_item_routes r";
        $directedStmt = $pdo->prepare($directedQuery);
        $directedStmt->execute();
        $directedEdges = $directedStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($directedEdges as $edge) {
            $source = $normalizeClass($edge['source']);
            $target = $normalizeClass($edge['target']);
            $cost = (float)$edge['cost'];
            if ($source === '' || $target === '') {
                continue;
            }
            if (!isset($edges[$source])) {
                $edges[$source] = [];
            }
            if (!isset($edges[$source][$target]) || $edges[$source][$target] > $cost) {
                $edges[$source][$target] = $cost;
            }
        }
    } catch (Exception $ignored) {
        // Directed rules are optional; keep legacy behavior if the new tables are unavailable.
    }

    // Fetch all items for mapping
    $itemsQuery = "
        SELECT items.item_class, 
               IFNULL(items.Item_Display_Name, items.item_class) as display_name, 
               item_types.item_classification 
        FROM items
        LEFT JOIN item_types ON items.Item_Type = item_types.Item_Type_Id
    ";
    $itemsStmt = $pdo->prepare($itemsQuery);
    $itemsStmt->execute();
    $itemsList = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $itemDisplayMap = [];
    $itemTypeMap = [];
    $itemClassMap = [];
    foreach ($itemsList as $item) {
        $classKey = $normalizeClass($item['item_class']);
        $itemDisplayMap[$classKey] = $item['display_name'];
        $itemClassMap[$classKey] = $item['item_class'];
        if (!empty($item['item_classification'])) {
            $itemTypeMap[$classKey] = $item['item_classification'];
        }
    }

    // Dijkstra's algorithm to find shortest paths from itemClass to all reachable items
    $distances = [$sourceKey => 0];
    $visited = [];
    $pq = [[$sourceKey, 0]]; // [item, distance]

    while (!empty($pq)) {
        usort($pq, fn($a, $b) => $a[1] <=> $b[1]);
        [$current, $currentDist] = array_shift($pq);

        if (isset($visited[$current])) {
            continue;
        }
        $visited[$current] = true;

        // Explore neighbors
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

    // Build result: exclude source item itself, include all reachable items with cheapest costs
    $result = [];
    foreach ($distances as $item => $cost) {
        if ($item === $sourceKey) {
            continue; // Skip the source item itself
        }
        $result[] = [
            'item_class' => $itemClassMap[$item] ?? $item,
            'cost' => (int)$cost,
            'display_name' => $itemDisplayMap[$item] ?? $item,
            'item_type' => $itemTypeMap[$item] ?? null
        ];
    }

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>

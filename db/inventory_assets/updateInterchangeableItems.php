<?php
require_once '../connection.php';
require_once '../../includes/auth.php';
require_once '../interchangeableGraphSchema.php';

header('Content-Type: application/json');

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

ensureInterchangeableGraphTables($pdo);

validateCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'add':
            $baseItem = $input['baseItem'] ?? '';
            $targetItem = $input['targetItem'] ?? '';
            $cost = isset($input['cost']) ? (float)$input['cost'] : 0;
            
            if (empty($baseItem) || empty($targetItem)) {
                throw new Exception('Base item and target item are required');
            }
            
            if ($baseItem === $targetItem) {
                throw new Exception('Base and target items cannot be the same');
            }
            
            $stmt = $pdo->prepare("INSERT INTO interchangable_items (Base_Item_Class, Interchangable_Item_Class, Change_Cost) VALUES (?, ?, ?)");
            $stmt->execute([$baseItem, $targetItem, $cost]);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'update':
            $id = $input['id'] ?? 0;
            $field = $input['field'] ?? '';
            $value = $input['value'] ?? '';
            
            if (!$id) {
                throw new Exception('ID is required');
            }
            
            switch ($field) {
                case 'base':
                    $stmt = $pdo->prepare("UPDATE interchangable_items SET Base_Item_Class = ? WHERE Interchangable_Id = ?");
                    $stmt->execute([$value, $id]);
                    break;
                    
                case 'target':
                    $stmt = $pdo->prepare("UPDATE interchangable_items SET Interchangable_Item_Class = ? WHERE Interchangable_Id = ?");
                    $stmt->execute([$value, $id]);
                    break;
                    
                case 'cost':
                    $stmt = $pdo->prepare("UPDATE interchangable_items SET Change_Cost = ? WHERE Interchangable_Id = ?");
                    $stmt->execute([(float)$value, $id]);
                    break;
                    
                default:
                    throw new Exception('Invalid field');
            }
            
            echo json_encode(['success' => true]);
            break;
            
        case 'delete':
            $id = $input['id'] ?? 0;
            
            if (!$id) {
                throw new Exception('ID is required');
            }
            
            $stmt = $pdo->prepare("DELETE FROM interchangable_items WHERE Interchangable_Id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true]);
            break;

        case 'addDirected':
            $sourceItem = $input['sourceItem'] ?? '';
            $targetItem = $input['targetItem'] ?? '';
            $cost = isset($input['cost']) ? (float)$input['cost'] : 0;

            if (empty($sourceItem) || empty($targetItem)) {
                throw new Exception('Source item and target item are required');
            }

            if ($sourceItem === $targetItem) {
                throw new Exception('Source and target items cannot be the same');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO interchangeable_item_routes (Source_Item_Class, Target_Item_Class, Change_Cost)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE Change_Cost = VALUES(Change_Cost)"
            );
            $stmt->execute([$sourceItem, $targetItem, $cost]);

            echo json_encode(['success' => true]);
            break;

        case 'updateDirected':
            $id = $input['id'] ?? 0;
            $field = $input['field'] ?? '';
            $value = $input['value'] ?? '';

            if (!$id) {
                throw new Exception('ID is required');
            }

            switch ($field) {
                case 'source':
                    if (empty($value)) {
                        throw new Exception('Source item is required');
                    }
                    $stmt = $pdo->prepare("UPDATE interchangeable_item_routes SET Source_Item_Class = ? WHERE Route_Id = ?");
                    $stmt->execute([$value, $id]);
                    break;

                case 'target':
                    if (empty($value)) {
                        throw new Exception('Target item is required');
                    }
                    $stmt = $pdo->prepare("UPDATE interchangeable_item_routes SET Target_Item_Class = ? WHERE Route_Id = ?");
                    $stmt->execute([$value, $id]);
                    break;

                case 'cost':
                    $stmt = $pdo->prepare("UPDATE interchangeable_item_routes SET Change_Cost = ? WHERE Route_Id = ?");
                    $stmt->execute([(float)$value, $id]);
                    break;

                default:
                    throw new Exception('Invalid field');
            }

            echo json_encode(['success' => true]);
            break;

        case 'deleteDirected':
            $id = $input['id'] ?? 0;

            if (!$id) {
                throw new Exception('ID is required');
            }

            $stmt = $pdo->prepare("DELETE FROM interchangeable_item_routes WHERE Route_Id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true]);
            break;

        case 'saveLayout':
            $positions = $input['positions'] ?? [];

            if (!is_array($positions)) {
                throw new Exception('Positions must be an array');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO interchangeable_item_layout (Item_Class, Grid_X, Grid_Y)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE Grid_X = VALUES(Grid_X), Grid_Y = VALUES(Grid_Y)"
            );

            foreach ($positions as $position) {
                if (!is_array($position)) {
                    continue;
                }

                $itemClass = $position['itemClass'] ?? '';
                if ($itemClass === '') {
                    continue;
                }

                $x = isset($position['x']) ? (int)round((float)$position['x']) : 0;
                $y = isset($position['y']) ? (int)round((float)$position['y']) : 0;
                $stmt->execute([$itemClass, $x, $y]);
            }

            echo json_encode(['success' => true]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

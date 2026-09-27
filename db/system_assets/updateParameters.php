<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get JSON input
validateCsrfToken();

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing action parameter']);
    exit();
}

try {
    $pdo->beginTransaction();

    switch ($input['action']) {
        // Inventory Types
        case 'addInventoryType':
            if (!isset($input['name'])) throw new Exception('Missing name parameter');
            $stmt = $pdo->prepare("INSERT INTO inventory_types (Inventory_Type_Name) VALUES (?)");
            $stmt->execute([$input['name']]);
            break;

        case 'updateInventoryType':
            if (!isset($input['id']) || !isset($input['name'])) throw new Exception('Missing parameters');
            $stmt = $pdo->prepare("UPDATE inventory_types SET Inventory_Type_Name = ? WHERE Inventory_Type_Id = ?");
            $stmt->execute([$input['name'], $input['id']]);
            break;

        case 'deleteInventoryType':
            if (!isset($input['id'])) throw new Exception('Missing id parameter');
            $stmt = $pdo->prepare("DELETE FROM inventory_types WHERE Inventory_Type_Id = ?");
            $stmt->execute([$input['id']]);
            break;

        // Item Type Limits
        case 'addLimit':
            if (!isset($input['type']) || !isset($input['itemType']) || !isset($input['limit'])) 
                throw new Exception('Missing parameters');
            $stmt = $pdo->prepare("INSERT INTO item_type_inventory_limit (Inventory_Type, Item_Type, Item_Limit) VALUES (?, ?, ?)");
            $stmt->execute([$input['type'], $input['itemType'], $input['limit']]);
            break;

        case 'updateLimit':
            if (!isset($input['id']) || !isset($input['field']) || !isset($input['value'])) 
                throw new Exception('Missing parameters');
            $field = match($input['field']) {
                'type' => 'Inventory_Type',
                'itemType' => 'Item_Type',
                'limit' => 'Item_Limit',
                default => throw new Exception('Invalid field')
            };
            $stmt = $pdo->prepare("UPDATE item_type_inventory_limit SET $field = ? WHERE Item_Type_Limit_Id = ?");
            $stmt->execute([$input['value'], $input['id']]);
            break;

        case 'deleteLimit':
            if (!isset($input['id'])) throw new Exception('Missing id parameter');
            $stmt = $pdo->prepare("DELETE FROM item_type_inventory_limit WHERE Item_Type_Limit_Id = ?");
            $stmt->execute([$input['id']]);
            break;

        // Condition Variables
        case 'addVariable':
            if (!isset($input['name']) || !isset($input['value'])) throw new Exception('Missing parameters');
            $stmt = $pdo->prepare("INSERT INTO condition_variables (Var_Name, Var_Value) VALUES (?, ?)");
            $stmt->execute([$input['name'], $input['value']]);
            break;

        case 'updateVariable':
            if (!isset($input['id']) || !isset($input['field']) || !isset($input['value'])) 
                throw new Exception('Missing parameters');
            $field = match($input['field']) {
                'name' => 'Var_Name',
                'value' => 'Var_Value',
                default => throw new Exception('Invalid field')
            };
            $stmt = $pdo->prepare("UPDATE condition_variables SET $field = ? WHERE Var_Id = ?");
            $stmt->execute([$input['value'], $input['id']]);
            break;

        case 'deleteVariable':
            if (!isset($input['id'])) throw new Exception('Missing id parameter');
            $stmt = $pdo->prepare("DELETE FROM condition_variables WHERE Var_Id = ?");
            $stmt->execute([$input['id']]);
            break;

        // Admins
        case 'addAdmin':
            if (!isset($input['playerId'])) throw new Exception('Missing playerId parameter');
            $stmt = $pdo->prepare("INSERT INTO admins (PlayerId) VALUES (?)");
            $stmt->execute([$input['playerId']]);
            break;

        case 'updateAdmin':
            if (!isset($input['id']) || !isset($input['playerId'])) throw new Exception('Missing parameters');
            $stmt = $pdo->prepare("UPDATE admins SET PlayerId = ? WHERE AdminId = ?");
            $stmt->execute([$input['playerId'], $input['id']]);
            break;

        case 'deleteAdmin':
            if (!isset($input['id'])) throw new Exception('Missing id parameter');
            $stmt = $pdo->prepare("DELETE FROM admins WHERE AdminId = ?");
            $stmt->execute([$input['id']]);
            break;

        // Calendar Editors
        case 'addCalendarEditor':
            if (!isset($input['userId'])) throw new Exception('Missing userId parameter');
            $stmt = $pdo->prepare("INSERT IGNORE INTO calendar_editors (User_Id) VALUES (?)");
            $stmt->execute([$input['userId']]);
            break;

        case 'deleteCalendarEditor':
            if (!isset($input['id'])) throw new Exception('Missing id parameter');
            $stmt = $pdo->prepare("DELETE FROM calendar_editors WHERE Editor_Id = ?");
            $stmt->execute([$input['id']]);
            break;

        // Planning Permissions
        case 'addPlanningPermission':
            if (!isset($input['userId'])) throw new Exception('Missing userId parameter');
            $stmt = $pdo->prepare("INSERT IGNORE INTO planning_permissions (user_id) VALUES (?)");
            $stmt->execute([$input['userId']]);
            break;

        case 'deletePlanningPermission':
            if (!isset($input['id'])) throw new Exception('Missing id parameter');
            $stmt = $pdo->prepare("DELETE FROM planning_permissions WHERE id = ?");
            $stmt->execute([$input['id']]);
            break;

        // Permissions
        case 'addPermission':
            if (!isset($input['inventoryId']) || !isset($input['playerId'])) throw new Exception('Missing parameters');
            $stmt = $pdo->prepare("INSERT INTO permissions (Inventory_Id, Player_Id) VALUES (?, ?)");
            $stmt->execute([$input['inventoryId'], $input['playerId']]);
            break;

        case 'updatePermission':
            if (!isset($input['id']) || !isset($input['field']) || !isset($input['value'])) 
                throw new Exception('Missing parameters');
            $field = match($input['field']) {
                'inventory' => 'Inventory_Id',
                'player' => 'Player_Id',
                default => throw new Exception('Invalid field')
            };
            $stmt = $pdo->prepare("UPDATE permissions SET $field = ? WHERE Permission_Id = ?");
            $stmt->execute([$input['value'], $input['id']]);
            break;

        case 'deletePermission':
            if (!isset($input['id'])) throw new Exception('Missing id parameter');
            $stmt = $pdo->prepare("DELETE FROM permissions WHERE Permission_Id = ?");
            $stmt->execute([$input['id']]);
            break;

        default:
            throw new Exception('Invalid action');
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'error' => 'null']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

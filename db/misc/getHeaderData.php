<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

try {
    $response = ['success' => true];
    
    // Get inventory data
    if (isset($_SESSION['inventory_id']) && $_SESSION['user_id'] !== -1) {
        $invQuery = "SELECT Inventory_Name, Inventory_Money FROM inventories WHERE Inventory_Id = ?";
        $invStmt = $pdo->prepare($invQuery);
        $invStmt->execute([$_SESSION['inventory_id']]);
        $invData = $invStmt->fetch();
        
        if ($invData) {
            $response['inventory_name'] = $invData['Inventory_Name'];
            $response['inventory_money'] = $invData['Inventory_Money'];
            
            // Update session data
            $_SESSION['inventory_name'] = $invData['Inventory_Name'];
            $_SESSION['inventory_money'] = $invData['Inventory_Money'];
        }
    }
    
    // Get unread messages count
    if ($_SESSION['user_id'] !== -1) {
        $unreadQuery = "SELECT COUNT(*) as count 
                       FROM messages m
                       JOIN player_profiles p ON m.Message_Receiver = p.Profile_Id
                       WHERE p.User_Id = ? AND m.Message_Read = 0";
        $unreadStmt = $pdo->prepare($unreadQuery);
        $unreadStmt->execute([$_SESSION['user_id']]);
        $unreadCount = $unreadStmt->fetch()['count'];
        
        $response['unread_messages'] = $unreadCount;
    } else {
        $response['unread_messages'] = 0;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

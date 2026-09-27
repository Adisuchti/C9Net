<?php
require_once '../connection.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Return all ORBAT teams with their assigned players
    $teamsQuery = "SELECT * FROM orbat_teams ORDER BY Sorting ASC";
    $teams = $pdo->query($teamsQuery)->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($teams as &$team) {
        $assignQuery = "SELECT oa.Id as Assignment_Id, oa.Role as Orbat_Role, oa.Sorting,
                               pp.Profile_Id, pp.Profile_Name, pp.Callsign, pp.Role as Roster_Role, pp.Status
                        FROM orbat_assignments oa
                        JOIN player_profiles pp ON oa.Profile_Id = pp.Profile_Id
                        WHERE oa.Team_Id = ? AND pp.Status != 'Inactive'
                        ORDER BY oa.Sorting ASC";
        $assignStmt = $pdo->prepare($assignQuery);
        $assignStmt->execute([$team['Id']]);
        $team['members'] = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get all active players (for unassigned pool)
    $allPlayersQuery = "SELECT pp.Profile_Id, pp.Profile_Name, pp.Callsign, pp.Role, pp.Status
                        FROM player_profiles pp
                        WHERE pp.Status != 'Inactive'
                        ORDER BY pp.Profile_Name ASC";
    $allPlayers = $pdo->query($allPlayersQuery)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get assigned profile IDs
    $assignedQuery = "SELECT DISTINCT Profile_Id FROM orbat_assignments";
    $assignedIds = $pdo->query($assignedQuery)->fetchAll(PDO::FETCH_COLUMN);
    
    // Filter unassigned
    $unassigned = array_values(array_filter($allPlayers, function($p) use ($assignedIds) {
        return !in_array($p['Profile_Id'], $assignedIds);
    }));
    
    // Get asset groups with their assigned assets
    $assetGroupsQuery = "SELECT * FROM asset_groups ORDER BY Sorting ASC";
    $assetGroups = $pdo->query($assetGroupsQuery)->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($assetGroups as &$group) {
        $assetQuery = "SELECT aa.Id as Assignment_Id, aa.Sorting,
                              wa.Id as Asset_Id, wa.Name, wa.ClassName, wa.Health, wa.Fuel, wa.Ammo, wa.Quantity
                       FROM asset_assignments aa
                       JOIN website_assets wa ON aa.Asset_Id = wa.Id
                       WHERE aa.Group_Id = ?
                       ORDER BY aa.Sorting ASC";
        $assetStmt = $pdo->prepare($assetQuery);
        $assetStmt->execute([$group['Id']]);
        $group['assets'] = $assetStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get all assets
    $allAssetsQuery = "SELECT * FROM website_assets ORDER BY Name ASC";
    $allAssets = $pdo->query($allAssetsQuery)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get assigned asset IDs
    $assignedAssetsQuery = "SELECT DISTINCT Asset_Id FROM asset_assignments";
    $assignedAssetIds = $pdo->query($assignedAssetsQuery)->fetchAll(PDO::FETCH_COLUMN);
    
    // Filter unassigned assets
    $unassignedAssets = array_values(array_filter($allAssets, function($a) use ($assignedAssetIds) {
        return !in_array($a['Id'], $assignedAssetIds);
    }));
    
    echo json_encode([
        'success' => true,
        'teams' => $teams,
        'unassigned' => $unassigned,
        'assetGroups' => $assetGroups,
        'unassignedAssets' => $unassignedAssets
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

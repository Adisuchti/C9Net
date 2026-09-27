<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../db/market_assets/interchangeableGraphSchema.php';

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Location: ../views/login.php');
    exit();
}

ensureInterchangeableGraphTables($pdo);

$host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';
$dbBaseUrl = '../db';

require_once '../includes/imageHelper.php';
$hasMedication = getMedicationStatus($pdo, $_SESSION['user_id']);

// Fetch all teams for the left navigation
$teamsQuery = "SELECT Fireteam_Id, Fireteam_Name, short_designation FROM team_hierarchy ORDER BY Sorting ASC, Fireteam_Name ASC";
$teamsStmt = $pdo->prepare($teamsQuery);
$teamsStmt->execute();
$allTeams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine active team
$activeTeamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : (count($allTeams) > 0 ? $allTeams[0]['Fireteam_Id'] : 0);

// Fetch active team details
$activeTeam = null;
$leaderProfile = null;
$teamMembers = [];
$inventoryId = 0;
$teamMoney = 0;
$teamNotebook = '';

if ($activeTeamId > 0) {
    // We expect the columns leader_player_id and team_inventory_id to exist in team_hierarchy
    // as per our recent SQL alterations.
    // However, we will try to fetch them, and if they don't exist yet, we'll handle gracefully.
    try {
        $teamQuery = "SELECT Fireteam_Id, Fireteam_Name, leader_player_id, team_inventory_id 
                      FROM team_hierarchy WHERE Fireteam_Id = ?";
        $teamStmt = $pdo->prepare($teamQuery);
        $teamStmt->execute([$activeTeamId]);
        $activeTeam = $teamStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($activeTeam) {
            $inventoryId = (int)$activeTeam['team_inventory_id'];
            
            if ($inventoryId > 0) {
                $moneyStmt = $pdo->prepare("SELECT Inventory_Money FROM inventories WHERE Inventory_Id = ?");
                $moneyStmt->execute([$inventoryId]);
                $teamMoney = (float)$moneyStmt->fetchColumn();
            }

            $noteStmt = $pdo->prepare("SELECT content FROM team_notebooks WHERE team_id = ?");
            $noteStmt->execute([$activeTeamId]);
            $teamNotebook = $noteStmt->fetchColumn() ?: '';
            
            $isTeamMember = false;
            if (isset($_SESSION['user_id'])) {
                $userProfileStmt = $pdo->prepare("SELECT Profile_Id FROM player_profiles WHERE User_Id = ? AND Assignment = ?");
                $userProfileStmt->execute([$_SESSION['user_id'], $activeTeamId]);
                if ($userProfileStmt->fetch()) {
                    $isTeamMember = true;
                }
            }
            
            // Fetch leader profile
            if ($activeTeam['leader_player_id']) {
                $leaderQuery = "SELECT Profile_Name, Profile_Id, Role FROM player_profiles WHERE Profile_Id = ?";
                $leaderStmt = $pdo->prepare($leaderQuery);
                $leaderStmt->execute([$activeTeam['leader_player_id']]);
                $leaderProfile = $leaderStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            $membersOrderBy = "ORDER BY
                UPPER(Role) = 'COMMANDER' DESC, UPPER(Role) = 'SUB COMMANDER' DESC, UPPER(Role) = 'TACTICAL LIAISON OFFICER' DESC,
                UPPER(Role) = 'OFFICER' DESC, UPPER(Role) = 'PLATOON LEADER' DESC, UPPER(Role) = 'PLATOON LEAD' DESC,
                UPPER(Role) = 'PLATOON COMMANDER' DESC, UPPER(Role) = 'PLATOON COMMAND' DESC, UPPER(Role) = 'LEADER' DESC,
                UPPER(Role) = 'LEAD' DESC, UPPER(Role) = 'TAC LEADER' DESC, UPPER(Role) = 'TAC LEAD' DESC,
                UPPER(Role) = 'SQUAD LEADER' DESC, UPPER(Role) = 'SQUAD LEAD' DESC, UPPER(Role) = 'TAC ALPHA LEAD' DESC,
                UPPER(Role) = 'TAC ALPHA LEADER' DESC, UPPER(Role) = '1IC' DESC, UPPER(Role) = 'CO' DESC,
                UPPER(Role) = '2IC' DESC, UPPER(Role) = 'NCO' DESC, UPPER(Role) = 'FIRETEAM LEADER' DESC,
                UPPER(Role) = 'FIRETEAM LEAD' DESC, UPPER(Role) = 'TEAM LEADER' DESC, UPPER(Role) = 'TEAM LEAD' DESC,
                UPPER(Role) = 'SUPPORT' DESC, UPPER(Role) = 'MEDIC' DESC, UPPER(Role) = 'CORPSMAN' DESC,
                UPPER(Role) = 'RECON' DESC, UPPER(Role) = 'RTO' DESC, UPPER(Role) = 'RIFLEMAN' DESC,
                UPPER(Role) = 'RIFLEMAN (AT)' DESC, UPPER(Role) = 'RIFLEMAN (AA)' DESC, UPPER(Role) = 'RIFLEMAN (LAT)' DESC,
                UPPER(Role) = 'RIFLEMAN (DM)' DESC, UPPER(Role) = 'RIFLEMAN (GRENADIER)' DESC, UPPER(Role) = 'RIFLEMAN (AR)' DESC,
                UPPER(Role) = 'RIFLEMAN (SAW)' DESC, UPPER(Role) = 'RIFLEMAN (MG)' DESC, UPPER(Role) = 'RIFLEMAN (HMG)' DESC,
                UPPER(Role) = 'RIFLEMAN (GL)' DESC, Profile_Name ASC";
            
            // Fetch team members
            $membersQuery = "SELECT Profile_Name, Profile_Id, Role FROM player_profiles WHERE Assignment = ? " . $membersOrderBy;
            $membersStmt = $pdo->prepare($membersQuery);
            $membersStmt->execute([$activeTeamId]);
            $teamMembers = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Fallback if the new columns haven't been added yet
        $teamQuery = "SELECT Fireteam_Id, Fireteam_Name FROM team_hierarchy WHERE Fireteam_Id = ?";
        $teamStmt = $pdo->prepare($teamQuery);
        $teamStmt->execute([$activeTeamId]);
        $activeTeam = $teamStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($activeTeam) {
            $membersOrderBy = "ORDER BY
                UPPER(Role) = 'COMMANDER' DESC, UPPER(Role) = 'SUB COMMANDER' DESC, UPPER(Role) = 'TACTICAL LIAISON OFFICER' DESC,
                UPPER(Role) = 'OFFICER' DESC, UPPER(Role) = 'PLATOON LEADER' DESC, UPPER(Role) = 'PLATOON LEAD' DESC,
                UPPER(Role) = 'PLATOON COMMANDER' DESC, UPPER(Role) = 'PLATOON COMMAND' DESC, UPPER(Role) = 'LEADER' DESC,
                UPPER(Role) = 'LEAD' DESC, UPPER(Role) = 'TAC LEADER' DESC, UPPER(Role) = 'TAC LEAD' DESC,
                UPPER(Role) = 'SQUAD LEADER' DESC, UPPER(Role) = 'SQUAD LEAD' DESC, UPPER(Role) = 'TAC ALPHA LEAD' DESC,
                UPPER(Role) = 'TAC ALPHA LEADER' DESC, UPPER(Role) = '1IC' DESC, UPPER(Role) = 'CO' DESC,
                UPPER(Role) = '2IC' DESC, UPPER(Role) = 'NCO' DESC, UPPER(Role) = 'FIRETEAM LEADER' DESC,
                UPPER(Role) = 'FIRETEAM LEAD' DESC, UPPER(Role) = 'TEAM LEADER' DESC, UPPER(Role) = 'TEAM LEAD' DESC,
                UPPER(Role) = 'SUPPORT' DESC, UPPER(Role) = 'MEDIC' DESC, UPPER(Role) = 'CORPSMAN' DESC,
                UPPER(Role) = 'RECON' DESC, UPPER(Role) = 'RTO' DESC, UPPER(Role) = 'RIFLEMAN' DESC,
                UPPER(Role) = 'RIFLEMAN (AT)' DESC, UPPER(Role) = 'RIFLEMAN (AA)' DESC, UPPER(Role) = 'RIFLEMAN (LAT)' DESC,
                UPPER(Role) = 'RIFLEMAN (DM)' DESC, UPPER(Role) = 'RIFLEMAN (GRENADIER)' DESC, UPPER(Role) = 'RIFLEMAN (AR)' DESC,
                UPPER(Role) = 'RIFLEMAN (SAW)' DESC, UPPER(Role) = 'RIFLEMAN (MG)' DESC, UPPER(Role) = 'RIFLEMAN (HMG)' DESC,
                UPPER(Role) = 'RIFLEMAN (GL)' DESC, Profile_Name ASC";
            
            $membersQuery = "SELECT Profile_Name, Profile_Id, Role FROM player_profiles WHERE Assignment = ? " . $membersOrderBy;
            $membersStmt = $pdo->prepare($membersQuery);
            $membersStmt->execute([$activeTeamId]);
            $teamMembers = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Fetch Inventory Items (similar to inventory.php)
$groupedItems = [];
$ammoItems = [];
$compatibleMap = [];
$marketDataByClass = [];
$interchangeableSet = [];

if ($inventoryId > 0) {
    // Fetch items from the database
    $query = "SELECT DISTINCT content_items.Content_Item_Id, content_items.Inventory_Id,
        content_items.Item_Class, content_items.Item_Quantity, content_items.Item_Properties,
        custom_item_types.Custom_Item_Type, IFNULL(items.Item_Display_Name, content_items.Item_Class) as Item_Display_Name
        FROM content_items
        LEFT JOIN items ON items.item_class = content_items.Item_Class
        LEFT JOIN item_types ON item_types.Item_Type_Id = items.Item_Type
        LEFT JOIN custom_item_types ON custom_item_types.Original_Item_Type = item_types.item_classification
        LEFT JOIN item_sorting ON item_sorting.Item_Sorting_Type = item_types.item_classification
        WHERE Inventory_Id = :inventory_id
        ORDER BY item_sorting.Item_Sorting_Number, content_items.Item_Class, content_items.Item_Properties";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    // Group items by Custom_Item_Type
    foreach ($items as $item) {
        $type = $item['Custom_Item_Type'] ?? 'Unknown';
        if (!isset($groupedItems[$type])) {
            $groupedItems[$type] = [];
        }
        
        // Unroll quantities for weapons
        $isWeapon = in_array($type, ['Primary_Weapon', 'Sidearm', 'Launcher', 'Melee', 'T-Doll']);
        
        if ($isWeapon) {
            for ($i = 0; $i < $item['Item_Quantity']; $i++) {
                $clonedItem = $item;
                $clonedItem['Item_Quantity'] = 1;
                $groupedItems[$type][] = $clonedItem;
            }
        } else {
            $groupedItems[$type][] = $item;
        }
    }

    // Find ammo for weapons
    $ammoItems = $groupedItems['Ammo'] ?? [];

    // Fetch compatible items mapping from market
    $compatQuery = $pdo->query("SELECT Market_Item_Class, Compatible_Items FROM market WHERE Compatible_Items IS NOT NULL AND Compatible_Items != ''");
    while ($row = $compatQuery->fetch(PDO::FETCH_ASSOC)) {
        $compatibleMap[strtoupper($row['Market_Item_Class'])] = array_map('strtoupper', explode(';', $row['Compatible_Items']));
    }

    // Build market link lookup & selling prices
    $marketQuery = "SELECT Market_Item_Class, Selling_Price FROM market WHERE Market = 0";
    $marketStmt = $pdo->prepare($marketQuery);
    $marketStmt->execute();
    $marketItems = $marketStmt->fetchAll();

    foreach ($marketItems as $marketItem) {
        $classKey = strtoupper(trim((string)$marketItem['Market_Item_Class']));
        if (!isset($marketDataByClass[$classKey])) {
            $marketDataByClass[$classKey] = $marketItem['Selling_Price'];
        }
    }

    // Build interchangeable set
    $interchangeQuery = "SELECT DISTINCT Base_Item_Class AS Item_Class FROM interchangable_items
                         UNION
                         SELECT DISTINCT Interchangable_Item_Class AS Item_Class FROM interchangable_items
                         UNION
                         SELECT DISTINCT Source_Item_Class AS Item_Class FROM interchangeable_item_routes";
    $interchangeStmt = $pdo->prepare($interchangeQuery);
    $interchangeStmt->execute();
    $interchangeableItems = $interchangeStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($interchangeableItems as $itemClass) {
        $interchangeableSet[strtoupper(trim((string)$itemClass))] = true;
    }
}

function findCompatibleAmmo($weaponClass, $ammoItems, $compatibleMap) {
    $weaponClass = strtoupper($weaponClass);
    if (!isset($compatibleMap[$weaponClass])) {
        return [];
    }
    
    $compatClasses = $compatibleMap[$weaponClass];
    $foundAmmo = [];
    foreach ($ammoItems as $ammo) {
        if (in_array(strtoupper($ammo['Item_Class']), $compatClasses)) {
            $foundAmmo[] = $ammo;
        }
    }
    return $foundAmmo;
}

function getRoleIcon($role) {
    $role = strtoupper(trim($role));
    $squadLeaders = ['PLATOON LEADER', 'PLATOON LEAD', 'PLATOON COMMANDER', 'PLATOON COMMAND', 'LEADER', 'LEAD', 'TAC LEADER', 'TAC LEAD', 'SQUAD LEADER', 'SQUAD LEAD', 'TAC ALPHA LEAD', 'TAC ALPHA LEADER', 'FIRETEAM LEADER', 'FIRETEAM LEAD', 'TEAM LEADER', 'TEAM LEAD'];
    $officers = ['COMMANDER', 'SUB COMMANDER', 'TACTICAL LIAISON OFFICER', 'OFFICER', '1IC', 'CO', '2IC', 'NCO'];
    $machineGunners = ['SUPPORT', 'RIFLEMAN (AR)', 'RIFLEMAN (SAW)', 'RIFLEMAN (MG)', 'RIFLEMAN (HMG)'];
    $medics = ['MEDIC', 'CORPSMAN'];
    $marksmen = ['RECON', 'RIFLEMAN (DM)', 'MARKSMAN', 'SNIPER'];
    $crews = ['CREW', 'PILOT', 'DRIVER'];
    
    if (in_array($role, $officers)) return 'officer.svg';
    if (in_array($role, $squadLeaders)) return 'squadleader.svg';
    if (in_array($role, $machineGunners)) return 'machinegunner.svg';
    if (in_array($role, $medics)) return 'medic.svg';
    if (in_array($role, $marksmen)) return 'marksman.svg';
    if (in_array($role, $crews)) return 'crew.svg';
    if ($role === 'T-DOLL') return 't-doll.svg';
    
    return 'rifle.svg';
}

// Get inventories for transfer dropdown (required for the inventory copy-paste)
$inventoriesQuery = "SELECT Inventory_Id, Inventory_Name from inventories WHERE Inventory_Id != :inventory_id";
$inventoriesStmt = $pdo->prepare($inventoriesQuery);
$inventoriesStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
$inventoriesStmt->execute();
$inventories = $inventoriesStmt->fetchAll();

include '../includes/header.php';
?>
<div class="roster-preview-container">
    
    <!-- Left Pane: 10% (Team List) -->
    <div class="roster-teams-pane">
        <?php foreach ($allTeams as $team): ?>
            <a href="roster.php?team_id=<?php echo $team['Fireteam_Id']; ?>" title="<?php echo htmlspecialchars($team['Fireteam_Name']); ?>" class="preview-roster-node">
                <div class="roster-team-designation <?php echo ($team['Fireteam_Id'] == $activeTeamId) ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($team['short_designation'] ?? ''); ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Right Main Pane: 90% -->
    <div class="roster-main-pane">
        
        <?php if ($activeTeam): ?>
            <!-- Leader Pane: Left 25% of Main Pane -->
            <div class="roster-leader-pane">
                <?php 
                    $leaderProfileId = $leaderProfile ? $leaderProfile['Profile_Id'] : 0;
                    $leaderImage = $imageBaseUrl . "/profiles/profile-" . str_pad($leaderProfileId, 3, '0', STR_PAD_LEFT) . ".png";
                    if (!file_exists(__DIR__ . "/../images/profiles/profile-" . str_pad($leaderProfileId, 3, '0', STR_PAD_LEFT) . ".png")) {
                        $leaderImage = $imageBaseUrl . "/profiles/default.png"; // Fallback
                    }
                    
                    // Use team ID for the logo path (e.g. 124.png)
                    $localActiveTeamLogoPath = __DIR__ . "/../images/teams/" . $activeTeamId . ".png";
                    $activeTeamLogoPath = "../images/teams/" . $activeTeamId . ".png";
                    
                    if (!file_exists($localActiveTeamLogoPath)) {
                        $activeTeamLogoPath = "../favicon.png"; // Fallback
                    }
                ?>
                <?php if ($leaderProfile): ?>
                    <img src="<?php echo $leaderImage; ?>" alt="Leader Profile" class="roster-leader-avatar" onerror="this.onerror=null; this.src='<?php echo $imageBaseUrl; ?>/profiles/default.png';">
                    <p class="auto-fit-name"><?php echo htmlspecialchars($leaderProfile['Profile_Name']); ?></p>
                <?php else: ?>
                    <div class="roster-no-leader">
                        No Leader
                    </div>
                <?php endif; ?>
                
                <?php if ($inventoryId > 0): ?>
                <div class="roster-team-finance">
                    <p class="roster-team-finance-label">Echelon Balance:</p>
                    <p class="team-balance"><?php echo number_format($teamMoney, 2, ".", "'"); ?> Cr</p>
                    <div class="team-action-buttons">
                        <button class="btn-industrial" onclick="openDonateOverlay()">Donate</button>
                        <a href="shop.php?team_id=<?php echo $activeTeamId; ?>" class="btn-industrial roster-team-shop-btn">Team Shop</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="flex-grow-1"></div>
                
                <img src="<?php echo $activeTeamLogoPath; ?>" alt="Team Logo" class="roster-leader-team-logo">
                <h2 class="roster-leader-team-name"><?php echo htmlspecialchars($activeTeam['Fireteam_Name']); ?></h2>
            </div>

            <!-- Details Pane: Right 75% of Main Pane -->
            <div class="roster-details-pane">
                
                <!-- Inventory Section: Top 70% -->
                <div class="roster-inventory-section">
                    <div class="roster-inventory-left-pane">
                    <?php if ($inventoryId > 0): ?>
                        <div class="inv-right-pane w-100 h-100">
                            <!-- Category Navigation -->
                            <div class="inv-categories">
                                <?php 
                                $firstCategory = true;
                                foreach ($groupedItems as $type => $typeItems): 
                                    $iconName = strtolower(str_replace(' ', '_', $type));
                                    $iconPath = __DIR__ . '/../images/icons/categories/' . $iconName . '.svg';
                                ?>
                                    <button class="inv-category-btn <?php echo $firstCategory ? 'active' : ''; ?>" data-target="cat-<?php echo htmlspecialchars($type); ?>" onclick="switchCategory(this)" title="<?php echo htmlspecialchars($type); ?>">
                                        <?php 
                                        if (file_exists($iconPath)) {
                                            include $iconPath;
                                        } else {
                                            include __DIR__ . '/../images/icons/categories/unknown.svg';
                                        }
                                        ?>
                                    </button>
                                <?php 
                                $firstCategory = false;
                                endforeach; 
                                
                                if (empty($groupedItems)) {
                                    echo "<button class='inv-category-btn active'>?</button>";
                                }
                                ?>
                            </div>

                            <!-- Items Container -->
                            <?php 
                            $firstCategory = true;
                            foreach ($groupedItems as $type => $typeItems): 
                                $isWeapon = in_array($type, ['Primary_Weapon', 'Sidearm', 'Launcher', 'Melee', 'T-Doll']);
                            ?>
                                <div class="inv-items-container <?php echo $isWeapon ? 'is-weapon-container' : 'is-grid-container'; ?> <?php echo $firstCategory ? 'active-container' : ''; ?>" id="cat-<?php echo htmlspecialchars($type); ?>">
                                    <?php foreach ($typeItems as $item): ?>
                                        <div class="inv-card">
                                            <div class="inv-card-image">
                                                <?php
                                                    $imgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $item['Item_Class'], $item['Custom_Item_Type']);
                                                    $imagePath = $imgPaths['imagePath'];
                                                    $defaultImage = $imgPaths['defaultImage'];
                                                    $fileCheckPath = $imgPaths['fileCheckPath'];
                                                ?>
                                                <img src="<?php echo file_exists($fileCheckPath) ? $imagePath : $defaultImage; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['Item_Class']); ?>" loading="lazy">
                                            </div>
                                            <div class="inv-card-content">
                                                <div>
                                                    <h3 class="inv-card-title" title="<?php echo htmlspecialchars($item['Item_Display_Name']); ?>">
                                                        <?php echo htmlspecialchars($item['Item_Display_Name']); ?>
                                                    </h3>
                                                </div>
                                                
                                                <?php if (!$isWeapon && $item['Item_Quantity'] > 1): ?>
                                                    <div class="inv-card-qty-badge"><?php echo $item['Item_Quantity']; ?>x</div>
                                                <?php endif; ?>

                                                <?php if ($isWeapon): ?>
                                                    <?php $ammos = findCompatibleAmmo($item['Item_Class'], $ammoItems, $compatibleMap); ?>
                                                    <?php if (!empty($ammos)): ?>
                                                        <div class="inv-card-ammo-container">
                                                            <?php foreach ($ammos as $ammo): ?>
                                                                <div class="inv-card-ammo" title="<?php echo htmlspecialchars($ammo['Item_Display_Name']); ?>">
                                                                    <span class="inv-card-ammo-qty"><?php echo $ammo['Item_Quantity']; ?></span>
                                                                    <?php
                                                                        $aImgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $ammo['Item_Class'], $ammo['Custom_Item_Type']);
                                                                        $ammoImagePath = $aImgPaths['imagePath'];
                                                                        $ammoDefaultImage = $aImgPaths['defaultImage'];
                                                                        $ammoFileCheckPath = $aImgPaths['fileCheckPath'];
                                                                    ?>
                                                                    <img src="<?php echo file_exists($ammoFileCheckPath) ? $ammoImagePath : $ammoDefaultImage; ?>" 
                                                                         class="inv-card-ammo-img" alt="Ammo">
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="inv-card-ammo-container">
                                                            <div class="inv-card-ammo no-ammo">
                                                                <span class="roster-no-ammo-text">No Ammo</span>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if (!empty($item['Item_Properties'])): ?>
                                                        <div class="roster-item-prop">
                                                            Prop: <?php echo htmlspecialchars($item['Item_Properties']); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="mt-auto"></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <!-- No actions for Team Inventory in roster preview (read-only for now unless specified) -->
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php 
                            $firstCategory = false;
                            endforeach; 
                            
                            if (empty($groupedItems)) {
                                echo "<div class='inv-items-container active-container roster-empty-inventory'>No items in team inventory.</div>";
                            }
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="roster-no-shared-inventory">
                            No Shared Inventory Assigned
                        </div>
                    <?php endif; ?>
                    </div>
                    
                    <!-- Right Notebook 30% -->
                    <div class="roster-notebook-section">
                        <h3 class="roster-notebook-title">Squad Notebook</h3>
                        <?php if (isset($isTeamMember) && $isTeamMember): ?>
                            <textarea id="teamNotebookContent" class="roster-notebook-textarea"><?php echo htmlspecialchars($teamNotebook); ?></textarea>
                            <button class="btn-industrial roster-notebook-save-btn" onclick="saveTeamNotebook(<?php echo $activeTeamId; ?>)">Save Notes</button>
                        <?php else: ?>
                            <div class="roster-notebook-readonly"><?php echo htmlspecialchars($teamNotebook) ?: 'No notes available.'; ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Members Section: Bottom 30% -->
                <div class="roster-members-section">
                    <?php foreach ($teamMembers as $member): ?>
                        <?php 
                            $memberImage = $imageBaseUrl . "/profiles/profile-" . str_pad($member['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png";
                            if (!file_exists(__DIR__ . "/../images/profiles/profile-" . str_pad($member['Profile_Id'], 3, '0', STR_PAD_LEFT) . ".png")) {
                                $memberImage = $imageBaseUrl . "/profiles/default.png"; // Fallback
                            }
                        ?>
                        <a href="profile.php?id=<?php echo $member['Profile_Id']; ?>" class="roster-member-card">
                            <img src="<?php echo $memberImage; ?>" alt="<?php echo htmlspecialchars($member['Profile_Name']); ?>" class="roster-member-avatar" onerror="this.onerror=null; this.src='<?php echo $imageBaseUrl; ?>/profiles/default.png';">
                            <div class="roster-member-info">
                                <p class="roster-member-name" title="<?php echo htmlspecialchars($member['Profile_Name']); ?>">
                                    <?php echo htmlspecialchars($member['Profile_Name']); ?>
                                </p>
                                <div class="roster-role-container">
                                    <?php 
                                        $roleIcon = getRoleIcon($member['Role']);
                                        $iconUrl = $imageBaseUrl . "/roles/" . $roleIcon;
                                    ?>
                                    <div class="roster-role-icon preview-roster-mask" style="--mask-url: url('<?php echo $iconUrl; ?>');"></div>
                                    <p class="roster-member-role"><?php echo htmlspecialchars($member['Role']); ?></p>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    
                    <?php if (empty($teamMembers)): ?>
                        <div class="roster-no-members">No members found for this team.</div>
                    <?php endif; ?>
                </div>

            </div>
        <?php else: ?>
            <div class="roster-no-team-selected">
                Select a team to view its roster
            </div>
        <?php endif; ?>
        
    </div>
</div>

<script>
    function switchCategory(btn) {
        // Remove active class from all buttons
        document.querySelectorAll('.inv-category-btn').forEach(b => b.classList.remove('active'));
        // Add active class to clicked button
        btn.classList.add('active');
        
        // Hide all item containers
        document.querySelectorAll('.inv-items-container').forEach(c => c.classList.remove('active-container'));
        
        // Show target container
        const targetId = btn.getAttribute('data-target');
        const targetContainer = document.getElementById(targetId);
        if (targetContainer) {
            targetContainer.classList.add('active-container');
        }
    }

    function formatNumber(num) {
        let formatted = Number(num).toFixed(2).split('.');
        formatted[0] = formatted[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
        return formatted.join('.') + ' Cr';
    }

    // Auto-fit leader name
    function autoFitLeaderName() {
        const nameEl = document.querySelector('.auto-fit-name');
        if (nameEl) {
            // Reset to max size to accurately recalculate
            nameEl.style.fontSize = '3rem';
            let fontSize = 3.0;
            
            // Loop until it fits (with a 2px buffer for rendering differences)
            while (nameEl.scrollWidth > nameEl.clientWidth + 2 && fontSize > 0.5) {
                fontSize -= 0.1;
                nameEl.style.fontSize = fontSize + 'rem';
            }
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        autoFitLeaderName();
        
        // Horizontal scroll for members section
        const membersSection = document.querySelector('.roster-members-section');
        if (membersSection) {
            membersSection.addEventListener('wheel', function(e) {
                if (e.deltaY !== 0) {
                    e.preventDefault();
                    membersSection.scrollLeft += e.deltaY;
                }
            });
        }
    });
    
    window.addEventListener("resize", autoFitLeaderName);
    
    if (document.fonts) {
        document.fonts.ready.then(autoFitLeaderName);
    } else {
        window.addEventListener("load", autoFitLeaderName);
    }
</script>

<div id="donateOverlay" class="roster-donate-overlay">
    <div class="overlay-content roster-donate-content">
        <div class="overlay-header roster-donate-header">
            <h3 class="roster-donate-title">Donate to Team</h3>
            <button class="close-overlay roster-donate-close-btn" onclick="closeDonateOverlay()">&times;</button>
        </div>
        <div class="overlay-body">
            <p class="roster-donate-balance">Your Balance: <?php echo number_format($_SESSION['inventory_money'] ?? 0, 2, ".", "'"); ?> Cr</p>
            <input type="number" id="donateAmount" placeholder="Amount to donate" class="detailPage-quantity-input roster-donate-input" min="1">
            <button class="btn-industrial roster-donate-confirm-btn" onclick="submitDonation(<?php echo $activeTeamId; ?>)">Confirm Donation</button>
        </div>
    </div>
</div>

<script>
    if (typeof showError !== 'function') {
        window.showError = function(msg) { alert("Error: " + msg); };
    }
    if (typeof showToast !== 'function') {
        window.showToast = function(msg) { alert(msg); };
    }

    function openDonateOverlay() {
        document.getElementById('donateOverlay').style.display = 'block';
    }

    function closeDonateOverlay() {
        document.getElementById('donateOverlay').style.display = 'none';
        document.getElementById('donateAmount').value = '';
    }

    function submitDonation(teamId) {
        const amount = parseFloat(document.getElementById('donateAmount').value);
        if (!amount || amount <= 0) {
            showError("Please enter a valid amount.");
            return;
        }

        fetch('../db/roster_orbat/donateToTeam.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ team_id: teamId, amount: amount })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast("Donation successful!");
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showError("Failed to donate: " + (data.error || "Unknown error"));
            }
        })
        .catch(err => showError("Error: " + err.message));
    }

    function saveTeamNotebook(teamId) {
        const content = document.getElementById('teamNotebookContent').value;
        fetch('../db/roster_orbat/updateTeamNote.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ team_id: teamId, content: content })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast("Notebook saved!");
            } else {
                showError("Failed to save notebook: " + (data.error || "Unknown error"));
            }
        })
        .catch(err => showError("Error: " + err.message));
    }
</script>

<?php include '../includes/footer.php'; ?>



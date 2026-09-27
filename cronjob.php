<?php
require_once 'db/connection.php';
require_once 'db/market/resolvePlayerAuctions.php';

$cronSummary = [
    'avatarSync' => 'pending',
    'auctions' => 'pending',
    'market' => 'pending',
    'permissions' => 'pending',
    'queryLogCleanup' => 'pending',
    'blurhashes' => 'pending',
    'mp4Thumbnails' => 'pending',
];

// Sync intranet profile images to phpBB avatars
try {
    $scriptPath = __DIR__ . '/views/forum/sync_avatars.php';
    $forumRoot = __DIR__ . '/views/forum';

    if (!is_file($scriptPath)) {
        throw new RuntimeException('sync_avatars.php not found');
    }

    $previousCwd = getcwd();
    if ($previousCwd === false) {
        throw new RuntimeException('Unable to read current working directory');
    }

    if (!@chdir($forumRoot)) {
        throw new RuntimeException('Unable to change directory to forum root for avatar sync');
    }

    try {
        ob_start();
        include $scriptPath;
        $avatarOutput = (string) ob_get_clean();
    } finally {
        chdir($previousCwd);
    }

    $avatarSync = [];
    if (preg_match('/Copied:\s*(\d+)\s*\|\s*Synced:\s*(\d+)/i', $avatarOutput, $matches)) {
        $avatarSync['copied'] = (int) $matches[1];
        $avatarSync['synced'] = (int) $matches[2];
    }

    $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
    if (isset($avatarSync['copied'], $avatarSync['synced'])) {
        $cronSummary['avatarSync'] = 'success (copied=' . $avatarSync['copied'] . ', synced=' . $avatarSync['synced'] . ')';
        $logStmt->execute([
            'Cronjob avatar sync: copied=' . $avatarSync['copied'] .
            ', synced=' . $avatarSync['synced'] .
            ' at ' . date('Y-m-d H:i:s')
        ]);
    } else {
        $cronSummary['avatarSync'] = 'success (ran, counters not parsed)';
        $logStmt->execute([
            'Cronjob avatar sync: completed without parsed counters at ' . date('Y-m-d H:i:s')
        ]);
    }
} catch (Exception $e) {
    $cronSummary['avatarSync'] = 'failed (' . $e->getMessage() . ')';
    error_log('Cronjob avatar sync error: ' . $e->getMessage());
}

// Resolve expired player market auctions
try {
    $auctionsResolved = resolveExpiredAuctions($pdo);
    if ($auctionsResolved > 0) {
        $cronSummary['auctions'] = 'success (resolved=' . $auctionsResolved . ')';
        $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
        $logStmt->execute(["Cronjob resolved {$auctionsResolved} expired player market auction(s) at " . date('Y-m-d H:i:s')]);
    } else {
        $cronSummary['auctions'] = 'success (no expired auctions)';
    }
} catch (Exception $e) {
    $cronSummary['auctions'] = 'failed (' . $e->getMessage() . ')';
    error_log('Cronjob auction resolution error: ' . $e->getMessage());
}

try {
    // Get the current time in seconds since midnight
    $currentTime = (int)date('H') * 3600 + (int)date('i') * 60 + (int)date('s');
    
    // Check if it's Sunday (0 = Sunday, 1 = Monday, etc.)
    $isSunday = (date('w') == 0);

    $currentStatement = $pdo->prepare("
        SELECT Var_Value 
        FROM condition_variables 
        WHERE Var_Name = 'Market_Enabled'");
    $currentStatement->execute();
    $currentMarketStatus = (int)$currentStatement->fetchColumn();

    $cronjobBlockQuery = $pdo->prepare("
        SELECT Var_Value 
        FROM condition_variables 
        WHERE Var_Name = 'Market_Disable_Cronjob_Block'");
    $cronjobBlockQuery->execute();
    $cronjobBlockStatus = (int)$cronjobBlockQuery->fetchColumn();

    $marketProcessingBlocked = ($cronjobBlockStatus === 1);

    if (!$marketProcessingBlocked) {
        // Fetch the time settings from condition_variables
        $stmt = $pdo->prepare(" 
            SELECT Var_Name, Var_Value 
            FROM condition_variables 
            WHERE Var_Name IN ('MarketDisableTimeStartInSeconds', 'MarketDisableTimeStopInSeconds')
        ");
        $stmt->execute();
        $times = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (!isset($times['MarketDisableTimeStartInSeconds']) || !isset($times['MarketDisableTimeStopInSeconds'])) {
            throw new Exception('Market disable time settings not found in database');
        }
        
        $startTime = (int)$times['MarketDisableTimeStartInSeconds'];
        $stopTime = (int)$times['MarketDisableTimeStopInSeconds'];
        
        // Determine if market should be enabled or disabled
        $shouldBeDisabled = false;
        
        if ($isSunday) {
            if ($startTime < $stopTime) {
                // Simple case: market disabled between start and stop time
                $shouldBeDisabled = ($currentTime >= $startTime && $currentTime <= $stopTime);
            } else {
                // Complex case: market disabled from start time until midnight and from midnight until stop time
                $shouldBeDisabled = ($currentTime >= $startTime || $currentTime <= $stopTime);
            }
        }
        
        // Update Market_Enabled status
        $newStatus = $shouldBeDisabled ? 0 : 1;

        if ($newStatus === $currentMarketStatus) {
            $cronSummary['market'] = 'success (unchanged=' . ($newStatus ? 'enabled' : 'disabled') . ')';
        } else {
            $updateStmt = $pdo->prepare(" 
                UPDATE condition_variables 
                SET Var_Value = ? 
                WHERE Var_Name = 'Market_Enabled'
            ");
            $updateStmt->execute([$newStatus]);
            
            // Create log message
            $logMessage = "Market status updated. Time: " . date('Y-m-d H:i:s') . 
                         " ($currentTime seconds). Market is now " . ($newStatus ? "enabled" : "disabled") .
                         ". Day: " . date('l') . 
                         ". Start: " . gmdate("H:i:s", $startTime) . 
                         ", Stop: " . gmdate("H:i:s", $stopTime);
            
            // Log to hiddenLogs table
            $logStmt = $pdo->prepare(" 
                INSERT INTO hiddenLogs (Comment) 
                VALUES (?)
            ");
            $logStmt->execute([$logMessage]);
            
            echo $logMessage;
            $cronSummary['market'] = 'success (changed=' . ($newStatus ? 'enabled' : 'disabled') . ')';
        }
    } else {
        $cronSummary['market'] = 'skipped (Market_Disable_Cronjob_Block=1)';
    }

} catch (Exception $e) {
    $cronSummary['market'] = 'failed (' . $e->getMessage() . ')';
    // Log error to hiddenLogs
    $errorMessage = "Cronjob Error: " . $e->getMessage() . " at " . date('Y-m-d H:i:s');
    $logStmt = $pdo->prepare("
        INSERT INTO hiddenLogs (Comment) 
        VALUES (?)
    ");
    $logStmt->execute([$errorMessage]);
    
    echo "Error: " . $e->getMessage();
}

// Grant all users with existing permissions access to inventory 2
try {
    $stmt = $pdo->prepare("
        INSERT INTO permissions (Inventory_Id, Player_Id)
        SELECT DISTINCT 2, p.Player_Id
        FROM permissions p
        WHERE NOT EXISTS (
            SELECT 1 
            FROM permissions p2 
            WHERE p2.Player_Id = p.Player_Id 
            AND p2.Inventory_Id = 2
        )
    ");
    $stmt->execute();
    $rowsAdded = $stmt->rowCount();
    
    if ($rowsAdded > 0) {
        $cronSummary['permissions'] = 'success (added=' . $rowsAdded . ')';
        $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
        $logStmt->execute(["Cronjob granted {$rowsAdded} user(s) access to inventory 2 at " . date('Y-m-d H:i:s')]);
    } else {
        $cronSummary['permissions'] = 'success (no new permissions added)';
    }
} catch (Exception $e) {
    $cronSummary['permissions'] = 'failed (' . $e->getMessage() . ')';
    error_log('Cronjob permission setup error: ' . $e->getMessage());
}

// Clean up system_query_log to keep only the newest 5000 entries
try {
    // We use a subquery in DELETE which is tricky in MySQL, so we use a sub-subquery to avoid 'You can't specify target table for update in FROM clause'
    $stmt = $pdo->prepare("
        DELETE FROM system_query_log 
        WHERE id NOT IN (
            SELECT id FROM (
                SELECT id FROM system_query_log 
                ORDER BY id DESC 
                LIMIT 5000
            ) AS tmp
        )
    ");
    $stmt->execute();
    $rowsDeleted = $stmt->rowCount();
    
    if ($rowsDeleted > 0) {
        $cronSummary['queryLogCleanup'] = 'success (deleted=' . $rowsDeleted . ')';
        $logStmt = $pdo->prepare("INSERT INTO hiddenLogs (Comment) VALUES (?)");
        $logStmt->execute(["Cronjob cleaned up {$rowsDeleted} query log entry(s) at " . date('Y-m-d H:i:s')]);
    } else {
        $cronSummary['queryLogCleanup'] = 'success (no cleanup needed)';
    }
} catch (Exception $e) {
    $cronSummary['queryLogCleanup'] = 'failed (' . $e->getMessage() . ')';
    error_log('Cronjob query log cleanup error: ' . $e->getMessage());
}

// Generate Blurhashes for shop items
try {
    require_once __DIR__ . '/includes/blurhash/Color.php';
    require_once __DIR__ . '/includes/blurhash/Base83.php';
    require_once __DIR__ . '/includes/blurhash/AC.php';
    require_once __DIR__ . '/includes/blurhash/DC.php';
    require_once __DIR__ . '/includes/blurhash/Blurhash.php';

    $itemsQuery = $pdo->query("SELECT Item_Class, blur_hash FROM items");
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

    $itemsDir = __DIR__ . '/images/items/';
    if (is_dir(__DIR__ . '/images/items/')) {
        $itemsDir = __DIR__ . '/images/items/';
    }

    $processed = 0;
    foreach ($items as $item) {
        $itemClass = $item['Item_Class'];
        $imagePath = $itemsDir . strtoupper($itemClass) . '.PNG';

        if (file_exists($imagePath)) {
            $img = @imagecreatefrompng($imagePath);
            if ($img !== false) {
                $width = imagesx($img);
                $height = imagesy($img);
                if ($width > 0 && $height > 0) {
                    $smallW = 32;
                    $smallH = max(1, (int)($height * ($smallW / $width)));
                    $small = imagecreatetruecolor($smallW, $smallH);

                    // Preserve transparency logic (though blurhash ignores alpha)
                    imagealphablending($small, false);
                    imagesavealpha($small, true);
                    $transparent = imagecolorallocatealpha($small, 255, 255, 255, 127);
                    imagefilledrectangle($small, 0, 0, $smallW, $smallH, $transparent);

                    imagecopyresampled($small, $img, 0, 0, 0, 0, $smallW, $smallH, $width, $height);

                    $pixels = [];
                    for ($y = 0; $y < $smallH; $y++) {
                        $row = [];
                        for ($x = 0; $x < $smallW; $x++) {
                            $colorIndex = imagecolorat($small, $x, $y);
                            $colors = imagecolorsforindex($small, $colorIndex);
                            
                            // If fully transparent, we can just treat it as white or dark, but we just use RGB.
                            // If alpha is high, mix with white background.
                            $alpha = $colors['alpha'] / 127;
                            $r = (int)($colors['red'] * (1 - $alpha) + 255 * $alpha);
                            $g = (int)($colors['green'] * (1 - $alpha) + 255 * $alpha);
                            $b = (int)($colors['blue'] * (1 - $alpha) + 255 * $alpha);

                            $row[] = [$r, $g, $b];
                        }
                        $pixels[] = $row;
                    }

                    imagedestroy($small);
                    imagedestroy($img);

                    try {
                        // 4x3 components is standard for a 16:9 or similar ratio. 4x4 is also fine.
                        $blurhashStr = \kornrunner\Blurhash\Blurhash::encode($pixels, 4, 3);
                        
                        $updateStmt = $pdo->prepare("UPDATE items SET blur_hash = ? WHERE Item_Class = ?");
                        $updateStmt->execute([$blurhashStr, $itemClass]);
                        $processed++;
                    } catch (Exception $be) {
                        error_log('Blurhash generation error for ' . $itemClass . ': ' . $be->getMessage());
                    }
                }
            }
        }
    }
    
    $cronSummary['blurhashes'] = 'success (processed=' . $processed . ')';

} catch (Exception $e) {
    $cronSummary['blurhashes'] = 'failed (' . $e->getMessage() . ')';
    error_log('Cronjob blurhash error: ' . $e->getMessage());
}

// Generate MP4 thumbnails for homeBackgrounds
try {
    $bgDir = __DIR__ . '/images/homeBackground/';
    $processedThumbs = 0;
    if (is_dir($bgDir)) {
        $files = glob($bgDir . '*.mp4');
        foreach ($files as $mp4File) {
            $thumbFile = $mp4File . '.thumb.jpg';
            if (!file_exists($thumbFile)) {
                $cmd = sprintf('ffmpeg -i %s -ss 00:00:01.000 -vframes 1 %s -y 2>&1', escapeshellarg($mp4File), escapeshellarg($thumbFile));
                exec($cmd);
                $processedThumbs++;
            }
        }
    }
    $cronSummary['mp4Thumbnails'] = 'success (generated=' . $processedThumbs . ')';
} catch (Exception $e) {
    $cronSummary['mp4Thumbnails'] = 'failed (' . $e->getMessage() . ')';
    error_log('Cronjob mp4 thumbnail error: ' . $e->getMessage());
}

echo "\nCronjob summary: avatarSync=" . $cronSummary['avatarSync']
    . "; auctions=" . $cronSummary['auctions']
    . "; market=" . $cronSummary['market']
    . "; permissions=" . $cronSummary['permissions']
    . "; blurhashes=" . $cronSummary['blurhashes']
    . "; mp4Thumbnails=" . $cronSummary['mp4Thumbnails']
    . "; queryLogCleanup=" . $cronSummary['queryLogCleanup'] . "\n";
?>
<p>This page is intended to be run as a cronjob and does not have a user interface.</p>
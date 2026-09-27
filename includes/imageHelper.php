<?php
function getMedicationStatus($pdo, $userId) {
    if (!isset($_SESSION['has_medication'])) {
        $stmt = $pdo->prepare("SELECT medication FROM user_home_config WHERE user_id = ?");
        $stmt->execute([$userId]);
        $_SESSION['has_medication'] = $stmt->fetchColumn() ? true : false;
    }
    return $_SESSION['has_medication'];
}

function resolveItemImagePaths($hasMedication, $imageBaseUrl, $itemClass, $defaultClass = 'UNKNOWN') {
    // Strip leading dot to prevent web server 404/403 blocks on "hidden" files
    $cleanItemClass = ltrim($itemClass, '.');
    $cleanDefaultClass = ltrim($defaultClass, '.');

    $itemClassSafe = strtoupper(htmlspecialchars($cleanItemClass));
    $defaultClassSafe = strtoupper(htmlspecialchars($cleanDefaultClass));
    
    $itemClassUrl = rawurlencode(strtoupper($cleanItemClass));
    $defaultClassUrl = rawurlencode(strtoupper($cleanDefaultClass));

    $imagePath = $imageBaseUrl . "/items/" . $itemClassUrl . ".PNG";
    $defaultImage = $imageBaseUrl . "/items/" . $defaultClassUrl . ".PNG";
    $fileCheckPath = __DIR__ . "/../images/items/" . $itemClassSafe . ".PNG";
    
    if ($hasMedication) {
        $altCheckPath = __DIR__ . "/../images/items_no_women/" . $itemClassSafe . ".PNG";
        if (file_exists($altCheckPath)) {
            $imagePath = $imageBaseUrl . "/items_no_women/" . $itemClassUrl . ".PNG";
            $fileCheckPath = $altCheckPath;
        }
    }
    
    return [
        'imagePath' => $imagePath,
        'defaultImage' => $defaultImage,
        'fileCheckPath' => $fileCheckPath
    ];
}

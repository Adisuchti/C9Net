<?php

if (!function_exists('ensureInterchangeableGraphTables')) {
    function interchangeableGraphTableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    }

    function ensureInterchangeableGraphTables(PDO $pdo): void
    {
        if (!interchangeableGraphTableExists($pdo, 'interchangeable_item_routes')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS interchangeable_item_routes (
                Route_Id INT AUTO_INCREMENT PRIMARY KEY,
                Source_Item_Class VARCHAR(512) NOT NULL,
                Target_Item_Class VARCHAR(512) NOT NULL,
                Change_Cost DECIMAL(15,2) NOT NULL DEFAULT 0,
                Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_source_target (Source_Item_Class, Target_Item_Class)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }

        if (!interchangeableGraphTableExists($pdo, 'interchangeable_item_layout')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS interchangeable_item_layout (
                Item_Class VARCHAR(512) NOT NULL PRIMARY KEY,
                Grid_X INT NOT NULL DEFAULT 0,
                Grid_Y INT NOT NULL DEFAULT 0,
                Updated_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        }
    }
}

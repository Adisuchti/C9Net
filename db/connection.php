<?php
$origin = '';
$requestMethod = '';

if (isset($GLOBALS['request']) && is_object($GLOBALS['request']) && method_exists($GLOBALS['request'], 'server')) {
    $origin = $GLOBALS['request']->server('HTTP_ORIGIN', '');
    $requestMethod = $GLOBALS['request']->server('REQUEST_METHOD', '');
} elseif (is_array($_SERVER)) {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
}

if ($origin) {
    if (preg_match('/^https?:\/\/(.*\.cinder9\.com|cinder9\.com|localhost(:[0-9]+)?|127\.0\.0\.1(:[0-9]+)?)$/', $origin)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    }
}
if ($requestMethod === 'OPTIONS') {
    exit(0);
}
// Load database credentials from config file in website root
// Protected from direct web access via .htaccess (Deny from all)
$configPath = __DIR__ . '/../cinder9_db.php';
if (file_exists($configPath)) {
    require_once $configPath;
} else {
    die("Database configuration file not found. Please create cinder9_db.php in the website root.");
}

$servername = C9_DB_HOST;
$dbname = C9_DB_NAME;
$username = C9_DB_USER;
$password = C9_DB_PASS;

// Create connection
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

class LoggedPDOStatement extends PDOStatement {
    private $pdo;
    private $boundParams = [];

    protected function __construct($pdo, $queryString = null) {
        $this->pdo = $pdo;
    }

    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool {
        $this->boundParams[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool {
        $this->boundParams[$param] = "[REFERENCE]"; 
        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function execute($params = null): bool {
        $allParams = $params ? array_merge($this->boundParams, $params) : $this->boundParams;
        $this->pdo->logQuery($this->queryString, $allParams);
        return parent::execute($params);
    }
}

class LoggedPDO extends PDO {
    public function __construct($dsn, $username = null, $password = null, $options = null) {
        parent::__construct($dsn, $username, $password, $options);
    }

    public function logQuery($sql, $params = null) {
        $trimmed = ltrim($sql);
        // Exclude common non-modifying queries
        if (stripos($trimmed, 'SELECT') === 0 || 
            stripos($trimmed, 'SET') === 0 || 
            stripos($trimmed, 'SHOW') === 0 || 
            stripos($trimmed, 'DESCRIBE') === 0 ||
            stripos($trimmed, 'DESC ') === 0) {
            return;
        }
        
        if (strpos($sql, 'system_query_log') !== false) return;
        
        try {
            $userId = (session_status() === PHP_SESSION_ACTIVE && is_array($_SESSION) && isset($_SESSION['user_id'])) ? $_SESSION['user_id'] : null;
            if ($userId === null && isset($GLOBALS['user']) && is_object($GLOBALS['user']) && isset($GLOBALS['user']->data['user_id'])) {
                $userId = $GLOBALS['user']->data['user_id'];
            }

            $ip = 'Unknown';
            if (is_array($_SERVER)) {
                $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (isset($_SERVER['argv']) ? 'CLI' : 'Unknown');
            } elseif (isset($GLOBALS['request']) && is_object($GLOBALS['request']) && method_exists($GLOBALS['request'], 'server')) {
                $ip = $GLOBALS['request']->server('REMOTE_ADDR', 'Unknown');
                if ($ip === 'Unknown') {
                    $argv = $GLOBALS['request']->server('argv');
                    if (!empty($argv)) $ip = 'CLI';
                }
            }
            
            $displayText = $sql;
            if ($params && count($params) > 0) {
                $displayText .= " | PARAMS: " . json_encode($params);
            }

            $logStmt = parent::prepare("INSERT INTO system_query_log (query_text, user_id, ip_address) VALUES (?, ?, ?)");
            $logStmt->execute([$displayText, $userId, $ip]);
        } catch (Exception $e) {
            // Silently fail if logging fails
        }
    }

    public function exec($statement): int|false {
        $this->logQuery($statement);
        return parent::exec($statement);
    }

    public function query($statement, $mode = null, ...$args): PDOStatement|false {
        $this->logQuery($statement);
        if ($mode === null) {
            return parent::query($statement);
        }
        return parent::query($statement, $mode, ...$args);
    }
}

try {
    $pdo = new LoggedPDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggedPDOStatement::class, [$pdo]]); 
    
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Make $conn available to other files
global $conn;
<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    redirectToLogin();
}

if ($_SESSION['user_id'] !== -1) {
    http_response_code(403);
    die('Unauthorized - admin only');
}

$tableError = false;

// Clean up stale sessions (older than 2 hours with no activity)
try {
    $pdo->exec("DELETE FROM active_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
} catch (Exception $e) {
    // Table might not exist yet
    $tableError = true;
}

// Fetch active sessions grouped by username
try {
    $sessionsQuery = "SELECT username, user_id, COUNT(*) as session_count, 
                      GROUP_CONCAT(DISTINCT ip_address ORDER BY ip_address SEPARATOR ', ') as ips,
                      MAX(login_time) as latest_login,
                      MAX(last_activity) as latest_activity
                      FROM active_sessions 
                      GROUP BY username, user_id 
                      ORDER BY latest_activity DESC";
    $sessionsStmt = $pdo->query($sessionsQuery);
    $sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalQuery = "SELECT COUNT(*) as total FROM active_sessions";
    $totalStmt = $pdo->query($totalQuery);
    $totalSessions = $totalStmt->fetch()['total'];
} catch (Exception $e) {
    $tableError = true;
}

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="activeSessions-container">
    <div class="activeSessions-header">
        <h2 class="activeSessions-title">Active Sessions</h2>
        <?php if (!$tableError && isset($totalSessions)): ?>
            <span class="activeSessions-total"><?php echo $totalSessions; ?> active</span>
        <?php endif; ?>
    </div>

    <?php if ($tableError): ?>
        <div class="activeSessions-error">
            The <code>active_sessions</code> table does not exist yet. Please run the SQL in <code>db/session_tracking_tables.sql</code> to create it.
        </div>
    <?php elseif (empty($sessions)): ?>
        <div class="activeSessions-panel preview-activeSessions-1">
            No active sessions at this time.
        </div>
    <?php else: ?>
        <div class="activeSessions-refresh-bar">
            <span class="activeSessions-auto-refresh">Auto-refreshes every 30 seconds</span>
            <button class="btn-industrial" onclick="location.reload()">
                <svg class="btn-icon" viewBox="0 0 24 24"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
                Refresh Now
            </button>
        </div>

        <div class="activeSessions-panel">
            <table class="activeSessions-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>User ID</th>
                        <th>Sessions</th>
                        <th>IP Addresses</th>
                        <th>Last Login</th>
                        <th>Last Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td class="preview-activeSessions-2">
                                <?php echo $session['user_id'] === -1 ? 'ðŸ›¡ï¸ ' : ''; ?>
                                <?php echo htmlspecialchars($session['username']); ?>
                            </td>
                            <td><?php echo $session['user_id']; ?></td>
                            <td>
                                <span class="activeSessions-count <?php echo $session['session_count'] == 1 ? 'count-1' : ($session['session_count'] > 1 ? 'count-multi' : ''); ?>">
                                    <?php echo $session['session_count']; ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    $ips = explode(', ', $session['ips']);
                                    foreach($ips as $ip) {
                                        echo '<span class="activeSessions-ip">' . htmlspecialchars($ip) . '</span> ';
                                    }
                                ?>
                            </td>
                            <td class="activeSessions-time"><?php echo date('Y-m-d H:i:s', strtotime($session['latest_login'])); ?></td>
                            <td class="activeSessions-time"><?php echo date('Y-m-d H:i:s', strtotime($session['latest_activity'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
// Auto-refresh every 30 seconds
setTimeout(function() {
    location.reload();
}, 30000);
</script>

<?php include '../includes/footer.php'; ?>

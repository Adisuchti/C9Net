<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

// Get profile ID for current user
$query = "SELECT Profile_Id FROM player_profiles WHERE User_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch();

if (!$profile) {
    header('Location: roster.php');
    exit();
}

$profileId = $profile['Profile_Id'];

// Get all messages for current user
$messagesQuery = "SELECT m.*, 
                        sender.Profile_Name as Sender_Name,
                        receiver.Profile_Name as Receiver_Name
                 FROM messages m
                 LEFT JOIN player_profiles sender ON m.Message_Sender = sender.Profile_Id
                 LEFT JOIN player_profiles receiver ON m.Message_Receiver = receiver.Profile_Id
                 WHERE m.Message_Receiver = ? OR m.Message_Sender = ?
                 ORDER BY m.Message_Date DESC";
$messagesStmt = $pdo->prepare($messagesQuery);
$messagesStmt->execute([$profileId, $profileId]);
$messages = $messagesStmt->fetchAll();

// Get selected message
$selectedMessage = isset($_GET['id']) ? $_GET['id'] : (count($messages) > 0 ? $messages[0]['Message_Id'] : null);

// Mark selected message as read
if ($selectedMessage) {
    $updateQuery = "UPDATE messages 
                   SET Message_Read = 1 
                   WHERE Message_Id = ? 
                   AND Message_Receiver = ?
                   AND Message_Read = 0";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$selectedMessage, $profileId]);
}

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="messages-preview-container preview-container">
    <div class="messages-layout">
        
        <!-- Left Pane: Message List (30%) -->
        <div class="messages-left-pane">
            <div class="messages-header">
                <h2 class="messages-title">Inbox</h2>
                <button class="btn-industrial btn-messages-new" onclick="showNewMessageOverlay()">+ New</button>
            </div>

            <div class="messages-list">
                <?php foreach ($messages as $message): ?>
                    <div class="messages-item <?php 
                        echo $message['Message_Id'] == $selectedMessage ? 'active' : ''; 
                        echo ($message['Message_Read'] == 0 && $message['Message_Receiver'] == $profileId) ? ' unread' : '';
                    ?>" onclick="window.location.href='?id=<?php echo $message['Message_Id']; ?>'">
                        <div class="messages-item-header">
                            <?php if ($message['Message_Sender'] === $profileId): ?>
                                <span class="messages-item-sender"><small>To:</small> <span class="messages-item-sender-name"><?php echo htmlspecialchars($message['Receiver_Name']); ?></span></span>
                            <?php else: ?>
                                <span class="messages-item-sender"><small>From:</small> <span class="messages-item-sender-name"><?php echo htmlspecialchars($message['Sender_Name']); ?></span></span>
                            <?php endif; ?>
                            <span class="messages-item-date"><?php echo date('M j, Y', strtotime($message['Message_Date'])); ?></span>
                        </div>
                        <div class="messages-item-title"><?php echo htmlspecialchars($message['Message_Title']); ?></div>
                        <?php if ($message['Message_Read'] == 0 && $message['Message_Receiver'] == $profileId): ?>
                            <span class="messages-unread-indicator"></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (count($messages) === 0): ?>
                    <div class="messages-not-found">No messages found</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Pane: Selected Message (70%) -->
        <div class="messages-right-pane">
            <?php if ($selectedMessage): 
                $currentMessage = array_filter($messages, function($m) use ($selectedMessage) {
                    return $m['Message_Id'] == $selectedMessage;
                });
                $currentMessage = reset($currentMessage);
                if ($currentMessage):
            ?>
                <?php
                // Mark message as read when viewed
                if ($currentMessage && $currentMessage['Message_Receiver'] == $profileId && $currentMessage['Message_Read'] == 0) {
                    $updateQuery = "UPDATE messages SET Message_Read = 1 WHERE Message_Id = ?";
                    $updateStmt = $pdo->prepare($updateQuery);
                    $updateStmt->execute([$currentMessage['Message_Id']]);
                }
                ?>
                <div class="messages-message-view">
                    <div class="messages-message-header">
                        <h2 class="messages-message-title"><?php echo htmlspecialchars($currentMessage['Message_Title']); ?></h2>
                        <div class="messages-message-info">
                            <span>From: <strong class="messages-sender-name"><?php echo htmlspecialchars($currentMessage['Sender_Name']); ?></strong></span>
                            <span>Date: <strong class="messages-date"><?php echo date('F j, Y', strtotime($currentMessage['Message_Date'])); ?></strong></span>
                        </div>
                    </div>
                    <div class="messages-message-content"><?php echo (htmlspecialchars($currentMessage['Message_Content'])); ?></div>
                </div>
            <?php endif; else: ?>
                <div class="messages-no-selection">
                    Select a message to view details
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Message Overlay -->
<div class="overlay-backdrop" id="newMessageOverlay">
    <div class="overlay-content messages-overlay-content">
        <h3 class="messages-new-title">New Message</h3>
        <form id="newMessageForm" class="messages-new-form">
            <div class="messages-form-group">
                <label for="receiver" class="messages-form-label">To:</label>
                <select id="receiver" required class="messages-form-input">
                    <?php
                    $receiversQuery = "SELECT Profile_Id, Profile_Name FROM player_profiles WHERE Profile_Id != ? ORDER BY Profile_Name";
                    $receiversStmt = $pdo->prepare($receiversQuery);
                    $receiversStmt->execute([$profileId]);
                    $receivers = $receiversStmt->fetchAll();
                    foreach ($receivers as $receiver):
                    ?>
                        <option value="<?php echo $receiver['Profile_Id']; ?>">
                            <?php echo htmlspecialchars($receiver['Profile_Name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="messages-form-group">
                <label for="title" class="messages-form-label">Subject:</label>
                <input type="text" id="title" required maxlength="64" oninput="updateCharCount(this)" class="messages-form-input">
                <small class="messages-char-count">64 characters remaining</small>
            </div>
            <div class="messages-form-group">
                <label for="content" class="messages-form-label">Message:</label>
                <textarea id="content" required rows="6" class="messages-form-textarea"></textarea>
            </div>
            <div class="messages-form-actions">
                <button type="button" onclick="closeNewMessageOverlay()" class="btn-industrial btn-messages-cancel">Cancel</button>
                <button type="button" onclick="sendMessage()" class="btn-industrial primary">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showNewMessageOverlay() {
        document.getElementById('newMessageOverlay').classList.add('active');
    }

    function closeNewMessageOverlay() {
        document.getElementById('newMessageOverlay').classList.remove('active');
    }

    function sendMessage() {
        const receiver = document.getElementById('receiver').value;
        const title = document.getElementById('title').value;
        const content = document.getElementById('content').value;

        if (!title.trim() || !content.trim()) {
            if (typeof showToast === "function") {
                showToast('Please fill in all fields', 'error');
            } else {
                alert('Please fill in all fields');
            }
            return;
        }

        fetch('../db/misc/sendMessage.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                receiver: receiver,
                title: title,
                content: content
            })
        })
        .then(async response => {
            const json = await response.json();
            if (json.success) {
                location.reload();
            } else {
                if (typeof showToast === "function") showToast(json.error, 'error');
            }
        })
        .catch(err => {
            if (typeof showToast === "function") showToast('Error sending message', 'error');
        });
    }

    function updateCharCount(input) {
        const maxLength = input.maxLength;
        const currentLength = input.value.length;
        const remaining = maxLength - currentLength;
        const counter = input.parentElement.querySelector('.messages-char-count');
        if (counter) counter.textContent = `${remaining} characters remaining`;
    }
</script>

<?php include '../includes/footer.php'; ?>

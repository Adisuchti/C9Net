<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectToLogin();
}

// Get mode from URL parameter (default to "news")
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'news';

$pdfDirectory = "";
$pdfBaseDirectory = "";

if ($mode === 'docs') {
    // Get list of PDFs
    $pdfDirectory = "../pdf/docs/";
    $pdfBaseDirectory = "../pdf/docs/";
    if (!file_exists($pdfDirectory)) {
        mkdir($pdfDirectory, 0777, true);
    }
} else {
    // Default to news mode
    $pdfDirectory = "../pdf/news/";
    $pdfBaseDirectory = "../pdf/news/";
    if (!file_exists($pdfDirectory)) {
        mkdir($pdfDirectory, 0777, true);
    }
}

// Get both PDFs and PNGs
$files = array_merge(
    glob($pdfDirectory . "*.pdf"),
    glob($pdfDirectory . "*.png"),
    glob($pdfDirectory . "*.PNG"),
    glob($pdfDirectory . "*.mp4") 
);
rsort($files);

// Get selected file
$selectedFile = isset($_GET['file']) ? $_GET['file'] : (count($files) > 0 ? basename($files[0]) : '');

$logStmt = $pdo->prepare("
    INSERT INTO hiddenLogs (Comment) 
    VALUES (?)
");
$logMessage = "Docs page viewed by user '". $_SESSION['username'] ."' from ". $_SERVER['REMOTE_ADDR'] ." at ". date('Y-m-d H:i:s');
$logStmt->execute([$logMessage]);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="docs-preview-container preview-container">
    <div class="docs-layout">
        
        <!-- Left Pane: File List (30%) -->
        <div class="docs-left-pane">
            
            <div class="docs-toggle-container">
                <a href="?mode=news" class="docs-toggle-btn <?php echo $mode === 'news' ? 'active' : ''; ?>">News</a>
                <a href="?mode=docs" class="docs-toggle-btn <?php echo $mode === 'docs' ? 'active' : ''; ?>">Docs</a>
            </div>

            <?php if ($_SESSION['user_id'] === -1): ?>
            <form id="uploadForm" class="docs-upload-form" enctype="multipart/form-data">
                <input type="file" id="pdfUpload" name="pdf" accept=".pdf,.png,.mp4" class="docs-upload-input">
                <button type="button" onclick="uploadPdf()" class="btn-industrial btn-docs-upload">Upload File</button>
                <p class="docs-upload-note">Note: Only PDF, PNG, and MP4. Max 200MB. No spaces in filename.</p>
            </form>
            <?php endif; ?>
            
            <div class="docs-list">
                <?php foreach ($files as $file): ?>
                    <?php
                    $filename = basename($file); 
                    $fileNameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

                    $displayName = htmlspecialchars($fileNameWithoutExt);
                    if (preg_match('/^\d{4}\.\d{2}\.\d{2}_/', $fileNameWithoutExt)) {
                        $displayName = preg_replace('/_/', '    ', $fileNameWithoutExt, 1);
                    }

                    $displayName = str_replace('_', ' ', $displayName);
                    $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $isActive = $filename === $selectedFile;
                    ?>
                    <div class="docs-item <?php echo $isActive ? 'active' : ''; ?>">
                        <a href="?file=<?php echo urlencode($filename); ?>&mode=<?php echo $mode; ?>" class="docs-item-link">
                            <?php echo $displayName; ?> <small class="docs-item-ext">(<?php echo strtoupper($fileExtension); ?>)</small>
                        </a>
                        <?php if ($_SESSION['user_id'] === -1): ?>
                            <div class="docs-item-actions">
                                <button onclick="renamePdf('<?php echo $filename; ?>')" class="btn-industrial primary btn-docs-rename">Rename</button>
                                <button onclick="deletePdf('<?php echo $filename; ?>')" class="btn-industrial danger btn-docs-delete">&times;</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (count($files) === 0): ?>
                    <div class="docs-no-files">No files found</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Pane: Viewer (70%) -->
        <div class="docs-right-pane">
            <?php if ($selectedFile && file_exists($pdfDirectory . $selectedFile)): ?>
                <?php 
                    $fileExt = strtolower(pathinfo($selectedFile, PATHINFO_EXTENSION));
                    if ($fileExt === 'pdf'): 
                ?>
                <iframe src="<?php echo $pdfBaseDirectory . urlencode($selectedFile); ?>" class="docs-iframe"></iframe>
                <?php elseif ($fileExt === 'mp4'): ?>
                    <div class="docs-video-viewer">
                        <video class="docs-video" controls>
                            <source src="<?php echo $pdfBaseDirectory . urlencode($selectedFile); ?>" type="video/mp4; codecs=avc1.42E01E,mp4a.40.2">
                            <source src="<?php echo $pdfBaseDirectory . urlencode($selectedFile); ?>" type="video/mp4">
                            <p>Your browser does not support the video tag or the video format.</p>
                        </video>
                    </div>
                <?php else: ?>
                    <div class="docs-image-viewer">
                        <img src="<?php echo $pdfBaseDirectory . urlencode($selectedFile); ?>" alt="News Image" class="docs-image">
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="docs-no-selection">
                    Select a file to view
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>



<script>
    function uploadPdf() {
        const input = document.getElementById('pdfUpload');
        if (!input.files.length) {
            if (typeof showToast === "function") showToast('Please select a file', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('pdf', input.files[0]);
        formData.append('directory', '<?php echo $pdfDirectory; ?>');

        fetch('../db/news/uploadNews.php', {
            method: 'POST',
            body: formData
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
            if (typeof showToast === "function") showToast('Error uploading file', 'error');
        });
    }

    function renamePdf(oldName) {
        var oldExtension = oldName.split('.').pop().toLowerCase();
        var oldNameWithoutExt = oldName.slice(0, -(oldExtension.length + 1));
        var newName = prompt('Enter new name (including .pdf extension):', oldNameWithoutExt);
        if (!newName) return;

        newName = newName + '.' + oldExtension;
        newName = newName.trim();
        if (!/^[a-zA-Z0-9._-]+$/.test(newName.replace(/ /g, '_'))) {
            if (typeof showToast === "function") showToast('Invalid filename. Only letters, digits, dots, underscores, and minus signs are allowed. Spaces will be replaced by underscores.', 'error');
            return;
        }
        newName = newName.replace(/ /g, '_').replace(/[^a-zA-Z0-9._-]/g, '');

        fetch('../db/news/renameNews.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                oldName: oldName,
                newName: newName,
                directory: '<?php echo $pdfDirectory; ?>'
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
            if (typeof showToast === "function") showToast('Error renaming file', 'error');
        });
    }

    function deletePdf(filename) {
        if (!confirm('Are you sure you want to delete this file?')) return;

        fetch('../db/news/deleteNews.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                filename: filename,
                directory: '<?php echo $pdfDirectory; ?>'
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
            if (typeof showToast === "function") showToast('Error deleting file', 'error');
        });
    }
</script>

<?php include '../includes/footer.php'; ?>

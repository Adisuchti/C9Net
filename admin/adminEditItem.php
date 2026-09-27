<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isLoggedIn() || $_SESSION['user_id'] !== -1) {
    redirectToLogin();
}

// Get the item ID from URL parameter
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$itemId) {
    header('Location: ../views/shop.php');
    exit();
}

// Fetch the specific item details
$query = "SELECT market.Market_item_Id, market.Market_Item_Class, ifnull(items.Item_Display_Name, market.Market_Item_Class) AS Item_Display_Name, market.Purchase_Price, market.Selling_Price, 
    custom_item_types.Custom_Item_Type, market.Available_Quantity, market.market_description, market.tier, market.Ammo_Count, market.Compatible_Items, market.DLC, market.Market, market.Visible,
    market.SubCategory_Id
    FROM market
    LEFT JOIN item_types ON item_types.Item_Type_Id COLLATE utf8mb4_general_ci = market.Market_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN custom_item_types ON item_types.item_classification COLLATE utf8mb4_general_ci = custom_item_types.Original_Item_Type COLLATE utf8mb4_general_ci
    LEFT JOIN items ON market.Market_Item_Class COLLATE utf8mb4_general_ci = items.Item_Class COLLATE utf8mb4_general_ci
    WHERE market.Market_item_Id = ?";

$stmt = $pdo->prepare($query);
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    header('Location: ../views/shop.php');
    exit();
}

// Fetch all available subcategories for this item's main category
$allSubcategories = [];
if (!empty($item['Custom_Item_Type'])) {
    $subcatQuery = "SELECT Id, SubCategory_Name FROM market_subcategories WHERE Main_Category = ?";
    $subcatStmt = $pdo->prepare($subcatQuery);
    $subcatStmt->execute([$item['Custom_Item_Type']]);
    $allSubcategories = $subcatStmt->fetchAll();
}

// Fetch all available market items for compatible selection
$allItemsQuery = "SELECT market.Market_Item_Class, ifnull(items.Item_Display_Name, market.Market_Item_Class) AS DisplayName 
                  FROM market 
                  LEFT JOIN items ON market.Market_Item_Class COLLATE utf8mb4_general_ci = items.Item_Class COLLATE utf8mb4_general_ci 
                  ORDER BY DisplayName ASC";
$allItems = $pdo->query($allItemsQuery)->fetchAll();

$marketQuery = "SELECT Id, Name FROM markets";
$marketStmt = $pdo->prepare($marketQuery);
$marketStmt->execute();
$markets = $marketStmt->fetchAll();

$dlcQuery = "SELECT Id, Name FROM dlcs;";
$dlcStmt = $pdo->prepare($dlcQuery);
$dlcStmt->execute();
$dlcs = $dlcStmt->fetchAll();

$itemImagePath = "/images/items/" . strtoupper($item['Market_Item_Class']) . ".PNG";
$itemTypeImagePath = "/images/items/" . strtoupper((string)($item['Custom_Item_Type'] ?? '')) . ".PNG";
$fileCheckPath = __DIR__ . "/../images/items/" . strtoupper($item['Market_Item_Class']) . ".PNG";
$resolvedPreviewImage = file_exists($fileCheckPath)
    ? $itemImagePath
    : $itemTypeImagePath;

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

<div class="detailPage-main-container">
    <!-- Left Pane -->
    <div id="left-pane" class="detailPage-left-pane">
        <div class="detailPage-back-wrapper">
            <a id="back-btn" href="../views/shop.php" class="btn-industrial detailPage-back-btn">← Back to Market</a>
        </div>
        <div class="detailPage-image-wrapper">
            <img id="adminItemPreviewImage" src="<?php echo htmlspecialchars($resolvedPreviewImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Preview" class="detailPage-image" onload="if(typeof adjustLeftPane === 'function') adjustLeftPane();" onerror="this.src='/images/placeholder.PNG'">
        </div>
        
        <div class="adminEditItem-upload-wrapper preview-adminEditItem-1">
            <h3 class="preview-adminEditItem-2">Upload Item Image</h3>
            
            <div class="preview-adminEditItem-3">
                <label class="preview-adminEditItem-4"><input type="radio" name="uploadTarget" value="live" checked> Live Site</label>
                <label class="preview-adminEditItem-5"><input type="radio" name="uploadTarget" value="preview"> Preview Site</label>
            </div>
            
            <input type="file" id="imageFile" accept="image/png,image/jpg,image/jpeg" class="adminEditItem-input preview-adminEditItem-6" onchange="previewImageUpload()">
            
            <div id="imageUploadPreview" class="preview-adminEditItem-7">
                <img id="uploadPreviewImg" src="" alt="Upload Preview" class="preview-adminEditItem-8">
                <p id="uploadPreviewFilename" class="preview-adminEditItem-9"></p>
            </div>
            
            <button type="button" onclick="uploadNewImage()" class="btn-industrial primary preview-adminEditItem-10">Upload Image</button>
        </div>
    </div>

    <!-- Right Pane -->
    <div class="detailPage-right-pane">
        <div class="adminEditItem-form-wrapper">
        <h2 class="adminEditItem-form-header">Edit Item Attributes</h2>
        
        <form id="itemEditForm">
            <input type="hidden" name="itemId" value="<?php echo $item['Market_item_Id']; ?>">
            
            <div class="preview-adminEditItem-11">
                <div class="adminEditItem-row">
                    <label for="itemClass">Item Class:</label>
                    <input type="text" id="itemClass" name="itemClass" class="adminEditItem-input" value="<?php echo htmlspecialchars($item['Market_Item_Class']); ?>">
                </div>

                <div class="adminEditItem-row">
                    <label for="displayName">Display Name:</label>
                    <input type="text" id="displayName" name="displayName" class="adminEditItem-input" value="<?php echo htmlspecialchars($item['Item_Display_Name']); ?>">
                </div>

                <div class="adminEditItem-row">
                    <label for="purchasePrice">Purchase Price (Cr):</label>
                    <input type="number" id="purchasePrice" name="purchasePrice" step="0.01" class="adminEditItem-input" value="<?php echo htmlspecialchars($item['Purchase_Price']); ?>">
                </div>

                <div class="adminEditItem-row">
                    <label for="sellingPrice">Selling Price (Cr):</label>
                    <input type="number" id="sellingPrice" name="sellingPrice" step="0.01" class="adminEditItem-input" value="<?php echo htmlspecialchars($item['Selling_Price']); ?>">
                </div>

                <div class="adminEditItem-row">
                    <label for="availableQuantity">Available Quantity: <small>(-1 for unlimited)</small></label>
                    <input type="number" id="availableQuantity" name="availableQuantity" class="adminEditItem-input" value="<?php echo htmlspecialchars($item['Available_Quantity']); ?>">
                </div>

                <div class="adminEditItem-row">
                    <label for="tier">Item Tier: <small>(1-127)</small></label>
                    <input type="number" id="tier" name="tier" min="1" max="127" class="adminEditItem-input" value="<?php echo htmlspecialchars($item['tier']); ?>">
                </div>

                <div class="adminEditItem-row">
                    <label for="state">Ammo Count: <small>(Only for Ammo and Grenades)</small></label>
                    <input type="number" id="state" name="state" min="0" max="127" class="adminEditItem-input" value="<?php echo htmlspecialchars($item['Ammo_Count']); ?>">
                </div>

                <div class="adminEditItem-row">
                    <label for="visible">Visibility in Market:</label>
                    <select id="visible" name="visible" class="adminEditItem-select">
                        <option value="1" <?php echo ($item['Visible'] == 1) ? 'selected' : ''; ?>>Visible</option>
                        <option value="0" <?php echo ($item['Visible'] == 0) ? 'selected' : ''; ?>>Hidden</option>
                    </select>
                </div>
            </div>

            <div class="adminEditItem-row preview-adminEditItem-12">
                <label for="description">Item Description:</label>
                <textarea id="description" name="description" class="adminEditItem-textarea"><?php echo htmlspecialchars($item['market_description']); ?></textarea>
            </div>

            <div class="preview-adminEditItem-13">
                <div class="adminEditItem-row">
                    <label for="market">Assigned Market: <small>(0 for website market)</small></label>
                    <select id="market" name="market" class="adminEditItem-select">
                        <option value="">No Market Selected</option>
                        <?php foreach ($markets as $market): ?>
                            <option value="<?php echo $market['Id']; ?>" <?php echo ($item['Market'] == $market['Id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($market['Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="adminEditItem-row">
                    <label for="subcategory">Subcategory: <small>(For filtering in market)</small></label>
                    <select id="subcategory" name="subcategory" class="adminEditItem-select">
                        <option value="">None / NULL</option>
                        <?php foreach ($allSubcategories as $subcat): ?>
                            <option value="<?php echo $subcat['Id']; ?>" <?php echo ($item['SubCategory_Id'] == $subcat['Id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subcat['SubCategory_Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="adminEditItem-row">
                    <label for="dlc">Required DLC:</label>
                    <select id="dlc" name="dlc" class="adminEditItem-select">
                        <option value="">No DLC Required</option>
                        <?php foreach ($dlcs as $dlc): ?>
                            <option value="<?php echo $dlc['Id']; ?>" <?php echo ($item['DLC'] == $dlc['Id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dlc['Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="adminEditItem-row preview-adminEditItem-14">
                <label>Compatible Items: <small>Select items that are compatible with this item (e.g. magazines for a weapon).</small></label>
                <div class="adminEditItem-compatible-wrapper">
                    <div class="adminEditItem-compatible-search">
                        <input type="text" id="compatibleSearch" class="adminEditItem-input" placeholder="Search items (e.g. 7.62)..." oninput="filterCompatibleItems()">
                    </div>
                    <div class="adminEditItem-compatible-list" id="compatibleItemsList">
                        <?php 
                        $currentCompatible = explode(';', $item['Compatible_Items'] ?? '');
                        $currentCompatible = array_map('trim', $currentCompatible);
                        foreach ($allItems as $marketItem): 
                            $isChecked = in_array($marketItem['Market_Item_Class'], $currentCompatible);
                        ?>
                            <label class="adminEditItem-compatible-row" data-class="<?php echo htmlspecialchars($marketItem['Market_Item_Class']); ?>" data-name="<?php echo htmlspecialchars($marketItem['DisplayName']); ?>">
                                <input type="checkbox" name="compatible_items_check[]" value="<?php echo htmlspecialchars($marketItem['Market_Item_Class']); ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                <div class="adminEditItem-compatible-label">
                                    <strong><?php echo htmlspecialchars($marketItem['DisplayName']); ?></strong>
                                    <small><?php echo htmlspecialchars($marketItem['Market_Item_Class']); ?></small>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="adminEditItem-actions">
                <button type="button" class="btn-industrial danger" onclick="deleteItem()">Delete Item</button>
                <button type="button" class="btn-industrial primary" onclick="saveItemChanges()">Save Changes</button>
            </div>
        </form>
    </div>
    </div>
</div>

<script>
    const ADMIN_IMAGE_BASE = '/images/items/';
    const ADMIN_IMAGE_FALLBACK = '/images/items/<?php echo strtoupper((string)($item['Custom_Item_Type'] ?? '')); ?>.PNG';

    // Update preview image automatically when item class changes
    const displayClassInput = document.getElementById('itemClass');
    const previewImg = document.getElementById('adminItemPreviewImage');

    displayClassInput.addEventListener('input', function() {
        const val = this.value.trim().toUpperCase();
        if(val) {
            previewImg.src = "/images/items/" + val + ".PNG";
        } else {
            previewImg.src = "/images/placeholder.PNG";
        }
    });
    
    // Adjust left pane width to hug the image perfectly, like in detailPage
    function adjustLeftPane() {
        const img = document.getElementById('adminItemPreviewImage');
        const btn = document.getElementById('back-btn');
        const pane = document.getElementById('left-pane');
        if (img && btn && pane) {
            pane.style.width = 'fit-content';
            const imgWidth = img.clientWidth;
            const btnWidth = btn.clientWidth;
            const targetWidth = Math.max(imgWidth, btnWidth, 260) + 40; 
            pane.style.width = targetWidth + 'px';
        }
    }

    window.addEventListener('load', adjustLeftPane);
    window.addEventListener('resize', adjustLeftPane);
    if (previewImg && previewImg.complete) {
        setTimeout(adjustLeftPane, 50);
    }

    document.getElementById('adminItemPreviewImage').addEventListener('error', function() {
        this.src = ADMIN_IMAGE_FALLBACK;
    });

    function filterCompatibleItems() {
        const query = document.getElementById('compatibleSearch').value.toLowerCase();
        const rows = document.querySelectorAll('.adminEditItem-compatible-row');
        
        rows.forEach(row => {
            const className = row.getAttribute('data-class').toLowerCase();
            const displayName = row.getAttribute('data-name').toLowerCase();
            
            if (className.includes(query) || displayName.includes(query)) {
                row.style.display = 'flex';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function saveItemChanges() {
        const form = document.getElementById('itemEditForm');
        const checkedItems = Array.from(document.querySelectorAll('input[name="compatible_items_check[]"]:checked'))
            .map(cb => cb.value)
            .join(';');

        const data = {
            itemId: form.itemId.value,
            itemClass: form.itemClass.value,
            displayName: form.displayName.value,
            purchasePrice: parseFloat(form.purchasePrice.value),
            sellingPrice: parseFloat(form.sellingPrice.value),
            availableQuantity: parseInt(form.availableQuantity.value),
            description: form.description.value,
            tier: parseInt(form.tier.value),
            state: parseInt(form.state.value),
            compatibleItems: checkedItems,
            marketId: parseInt(form.market.value),
            subcategoryId: form.subcategory.value ? parseInt(form.subcategory.value) : null,
            dlc: form.dlc.value || null,
            visible: parseInt(form.visible.value)
        };

        fetch('../db/market_assets/alterItem.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast("Item saved successfully!", "success");
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function deleteItem() {
        if (!confirm("Are you sure you want to permanently delete this item? This action cannot be undone.")) {
            return;
        }

        const itemId = document.getElementById('itemEditForm').itemId.value;

        fetch('../db/market/removeMarketItem.php', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ itemId: itemId })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    window.location.href = '../views/shop.php';
                } else {
                    showError(json.error || "Failed to delete item.");
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function previewImageUpload() {
        const fileInput = document.getElementById('imageFile');
        const preview = document.getElementById('imageUploadPreview');
        const previewImg = document.getElementById('uploadPreviewImg');
        const previewFilename = document.getElementById('uploadPreviewFilename');
        const itemClassInput = document.getElementById('itemClass');

        if (fileInput.files && fileInput.files[0]) {
            const file = fileInput.files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
                previewImg.src = e.target.result;
                const finalName = (itemClassInput.value || 'UNKNOWN').toUpperCase() + '.PNG';
                previewFilename.textContent = 'Will be saved as: ' + finalName;
                preview.style.display = 'block';
                adjustLeftPane();
            }
            reader.readAsDataURL(file);
        } else {
            preview.style.display = 'none';
            adjustLeftPane();
        }
    }

    // Update upload preview filename if item class changes
    document.getElementById('itemClass').addEventListener('input', function() {
        const previewFilename = document.getElementById('uploadPreviewFilename');
        const preview = document.getElementById('imageUploadPreview');
        if (preview.style.display !== 'none') {
            const finalName = (this.value || 'UNKNOWN').toUpperCase() + '.PNG';
            previewFilename.textContent = 'Will be saved as: ' + finalName;
        }
    });

    async function uploadNewImage(force = false) {
        const fileInput = document.getElementById('imageFile');
        const itemClassInput = document.getElementById('itemClass');

        if (!fileInput.files || !fileInput.files[0]) {
            showError('Please select an image file first.');
            return;
        }
        
        const filename = itemClassInput.value;
        if (!filename) {
            showError('Item Class cannot be empty for image upload.');
            return;
        }

        const target = document.querySelector('input[name="uploadTarget"]:checked').value;

        const formData = new FormData();
        formData.append('image', fileInput.files[0]);
        formData.append('filename', filename);
        formData.append('force', force ? '1' : '0');
        formData.append('target', target);

        try {
            const response = await fetch('../db/system_assets/uploadImage.php', {
                method: 'POST',
                headers: { 
                    'X-CSRF-Token': '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                },
                credentials: 'include',
                body: formData
            });

            const text = await response.text();
            try {
                const result = JSON.parse(text);
                if (result.success) {
                    showToast('Image uploaded successfully!', 'success');
                    
                    // Update the main preview image
                    const mainPreview = document.getElementById('adminItemPreviewImage');
                    if (mainPreview) {
                        // Add a cache-busting parameter to force reload
                        mainPreview.src = "/images/items/" + result.savedAs + "?t=" + new Date().getTime();
                    }
                    
                    // Clear the upload selection
                    fileInput.value = '';
                    document.getElementById('imageUploadPreview').style.display = 'none';
                    adjustLeftPane();
                } else if (result.fileExists) {
                    if (confirm('An image with the name "' + result.existingFile + '" already exists. Do you want to replace it?')) {
                        await uploadNewImage(true);
                    }
                } else {
                    showError('Error: ' + result.error);
                }
            } catch (e) {
                showError("Parse Error: " + text);
            }
        } catch (error) {
            showError('Upload failed: ' + error.message);
        }
    }
</script>

<?php include '../includes/footer.php'; ?>

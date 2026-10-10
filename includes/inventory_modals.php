<div id="inventory-item-details-overlay" class="overlay-backdrop">
    <div class="overlay-container inv-overlay-large">
        <h3>Item Details</h3>
        
        <div class="inv-details-row">
            <!-- Left side: Image -->
            <div class="inv-details-img-container">
                <img id="detailsItemImage" src="" alt="Item Image" class="inv-details-img">
            </div>
            
            <!-- Right side: Info and Buttons -->
            <div class="inv-details-info">
                <p id="detailsItemDisplayName" class="overlay-item-name inv-details-title">Display Name</p>
                <p id="detailsItemClass" class="inv-details-class">Class Name</p>
                
                <div id="detailsAmmoContainer" class="inv-details-ammo-section">
                    <h4 class="inv-details-ammo-title">Compatible Ammo</h4>
                    <div id="detailsAmmoList" class="inv-details-ammo-list">
                        <!-- Ammos injected here -->
                    </div>
                </div>
                
                <div class="overlay-form-actions inv-details-actions">
                    <button type="button" class="btn-industrial btn-inv-action" id="detailsBtnTransfer" title="Transfer" onclick="triggerTransfer()">
                        <?php include '../images/icons/transfer.svg'; ?>
                    </button>
                    <button type="button" class="btn-industrial success btn-inv-action" id="detailsBtnModify" title="Modify" onclick="triggerModify()">
                        <?php include '../images/icons/modify.svg'; ?>
                    </button>
                    <button type="button" class="btn-industrial danger btn-inv-action" id="detailsBtnSell" title="Sell" onclick="triggerSell()">
                        <?php include '../images/icons/sell.svg'; ?>
                    </button>
                    <button type="button" class="btn-industrial btn-inv-action" id="detailsBtnMarket" title="Market" onclick="triggerMarket()">
                        <?php include '../images/icons/market.svg'; ?>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="inv-details-footer">
            <button type="button" class="btn-industrial btn-inv-close" onclick="hideItemDetailsOverlay()">
                Close
            </button>
        </div>
    </div>
</div>

<div id="inventory-transfer-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Transfer</h3>
        <p id="transferItemName" class="overlay-item-name">Item</p>
        <form id="transferForm" class="overlay-form">
            <input type="hidden" id="transferItemId" name="item_id" value="">
            <input type="hidden" id="transferItemClass" name="item_class" value="">
            <input type="hidden" id="transferItemState" name="item_state" value="">
            
            <div class="overlay-form-row">
                <label for="transferUser">Send to:</label>
                <select id="transferUser" name="target_user" required>
                    <?php foreach ($inventories as $inventory): ?>
                        <option value="<?php echo htmlspecialchars($inventory['Inventory_Id']); ?>">
                            <?php echo htmlspecialchars($inventory['Inventory_Name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="overlay-form-row">
                <label for="transferQuantity">Quantity:</label>
                <input type="number" id="transferQuantity" name="quantity" min="1" required>
            </div>

            <div class="overlay-form-actions">
                <button type="button" class="btn-industrial" onclick="overlayOff()">Cancel</button>
                <button type="button" class="btn-industrial primary" onclick="transferItem()">Transfer</button>
            </div>
        </form>
    </div>
</div>

<div id="inventory-modify-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Modify</h3>
        <p id="modifyItemName" class="overlay-item-name">Item</p>
        <form id="modifyForm" class="overlay-form">
            <input type="hidden" id="modifyItemId" name="item_id" value="">
            <input type="hidden" id="modifyItemClass" name="item_class" value="">
            
            <div class="overlay-form-row">
                <label>Change to:</label>
                <div id="modifyTarget" class="inv-modify-target"></div>
            </div>

            <div class="overlay-form-row">
                <label for="modifyQuantity">Quantity:</label>
                <input type="number" id="modifyQuantity" name="quantity" min="1" required>
            </div>

            <div class="overlay-info-row inv-overlay-mt">
                <span>Total Cost:</span>
                <span><span id="modifyCost">0</span> Cr</span>
            </div>

            <div class="overlay-form-actions">
                <button type="button" class="btn-industrial" onclick="hideModifyOverlay()">Cancel</button>
                <button type="button" class="btn-industrial success" onclick="modifyItem()">Modify</button>
            </div>
        </form>
    </div>
</div>

<div id="inventory-sell-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Sell</h3>
        <p id="sellItemName" class="overlay-item-name">Item</p>
        <form id="sellForm" class="overlay-form">
            <input type="hidden" id="sellItemId" name="item_id" value="">
            <input type="hidden" id="sellItemClass" name="item_class" value="">
            <input type="hidden" id="sellItemState" name="item_state" value="">
            <input type="hidden" id="sellUnitPrice" name="unit_price" value="0">

            <div class="overlay-form-row">
                <label for="sellQuantity">Quantity:</label>
                <input type="number" id="sellQuantity" name="quantity" min="1" oninput="updateSellTotal()" required>
            </div>

            <div class="overlay-info-row inv-overlay-mt">
                <span>Price per unit:</span>
                <span><span id="sellPricePerUnit">0</span> Cr</span>
            </div>
            <div class="overlay-info-row">
                <span>Total Earnings:</span>
                <span class="inv-sell-total"><span id="sellTotalPrice">0</span> Cr</span>
            </div>

            <div class="overlay-form-actions">
                <button type="button" class="btn-industrial" onclick="hideSellOverlay()">Cancel</button>
                <button type="button" class="btn-industrial danger" onclick="sellItem()">Sell</button>
            </div>
        </form>
    </div>
</div>

<div id="inventory-repack-all-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Repack All Magazines</h3>
        <p>Are you sure you want to repack all magazines in your inventory?</p>
        <p class="overlay-item-name">This will consolidate all loose ammo into full magazines where possible.</p>
        
        <div class="overlay-form-actions preview-inventory-4">
            <button type="button" class="btn-industrial" onclick="hideRepackAllOverlay()">Cancel</button>
            <button type="button" class="btn-industrial primary" onclick="confirmRepackAll()">Repack All</button>
        </div>
    </div>
</div>

<div id="inventory-refill-all-overlay" class="overlay-backdrop">
    <div class="overlay-container">
        <h3>Refill All Magazines</h3>
        <p>This will purchase missing ammo to refill all magazines in your inventory to maximum capacity.</p>
        
        <div class="overlay-info-row inv-overlay-mt">
            <span>Total Cost:</span>
            <span class="inv-sell-total"><span id="refillAllTotalCost">...</span> Cr</span>
        </div>

        <div class="overlay-form-actions preview-inventory-5">
            <button type="button" class="btn-industrial" onclick="hideRefillAllOverlay()">Cancel</button>
            <button type="button" class="btn-industrial success" id="btnConfirmRefillAll" onclick="confirmRefillAll()" disabled>Refill All</button>
        </div>
    </div>
</div>

<script>
    const CURRENT_INVENTORY_ID = <?php echo isset($inventoryId) ? (int)$inventoryId : (isset($_SESSION['inventory_id']) ? $_SESSION['inventory_id'] : 0); ?>;
    const dbBaseUrl = "<?php echo $dbBaseUrl; ?>";
    const marketDataMap = <?php echo json_encode($marketDataByClass); ?>;

    let currentDetailsItem = {};

    function showItemDetailsOverlay(id, itemClass, displayName, maxQuantity, properties, sellPrice, isInterchangeable, inMarket, imageUrl, ammos) {
        currentDetailsItem = { id, itemClass, displayName, maxQuantity, properties, sellPrice, isInterchangeable, inMarket, imageUrl };
        
        document.getElementById('detailsItemDisplayName').textContent = displayName || itemClass;
        document.getElementById('detailsItemClass').textContent = itemClass;
        
        const imgEl = document.getElementById('detailsItemImage');
        if (imageUrl) {
            imgEl.src = imageUrl;
            imgEl.style.display = 'block';
        } else {
            imgEl.style.display = 'none';
        }
        
        const ammoContainer = document.getElementById('detailsAmmoContainer');
        const ammoList = document.getElementById('detailsAmmoList');
        if (ammos && ammos.length > 0) {
            ammoContainer.style.display = 'block';
            ammoList.innerHTML = '';
            ammos.forEach(ammo => {
                let propBadge = '';
                if (ammo.Item_Properties && ammo.Item_Properties !== '') {
                    let dispAmmo = ammo.Item_Properties;
                    const cKey = String(ammo.Item_Class).trim().toUpperCase();
                    if (marketDataMap[cKey] && marketDataMap[cKey].Ammo_Count && marketDataMap[cKey].Ammo_Count > 0) {
                        dispAmmo += '/' + marketDataMap[cKey].Ammo_Count;
                    }
                    propBadge = `<div class="inv-card-ammo-badge-sm">Ammo: ${dispAmmo}</div>`;
                }
                
                ammoList.innerHTML += `
                    <div class="inv-card-ammo inv-card-ammo-wrapper" title="${ammo.Item_Display_Name}">
                        <span class="inv-card-ammo-qty">${ammo.Item_Quantity}</span>
                        <img src="${ammo.imageUrl}" class="inv-card-ammo-img inv-card-ammo-img-large" alt="Ammo">
                        ${propBadge}
                    </div>
                `;
            });
        } else {
            ammoContainer.style.display = 'none';
        }
        
        const btnModify = document.getElementById('detailsBtnModify');
        if (isInterchangeable) {
            btnModify.disabled = false;
            btnModify.style.opacity = '1';
            btnModify.style.cursor = 'pointer';
            btnModify.style.filter = 'none';
        } else {
            btnModify.disabled = true;
            btnModify.style.opacity = '0.4';
            btnModify.style.cursor = 'not-allowed';
            btnModify.style.filter = 'grayscale(1)';
        }

        const btnMarket = document.getElementById('detailsBtnMarket');
        if (inMarket) {
            btnMarket.disabled = false;
            btnMarket.style.opacity = '1';
            btnMarket.style.cursor = 'pointer';
            btnMarket.style.filter = 'none';
        } else {
            btnMarket.disabled = true;
            btnMarket.style.opacity = '0.4';
            btnMarket.style.cursor = 'not-allowed';
            btnMarket.style.filter = 'grayscale(1)';
        }
        
        document.getElementById('inventory-item-details-overlay').style.display = 'flex';
    }

    function hideItemDetailsOverlay() {
        document.getElementById('inventory-item-details-overlay').style.display = 'none';
    }

    function triggerTransfer() {
        hideItemDetailsOverlay();
        overlayOn(currentDetailsItem.id, currentDetailsItem.itemClass, currentDetailsItem.maxQuantity, currentDetailsItem.properties);
    }

    function triggerModify() {
        if (!currentDetailsItem.isInterchangeable) return;
        hideItemDetailsOverlay();
        showModifyOverlay(currentDetailsItem.id, currentDetailsItem.itemClass, currentDetailsItem.maxQuantity);
    }

    function triggerSell() {
        hideItemDetailsOverlay();
        showSellOverlay(currentDetailsItem.id, currentDetailsItem.itemClass, currentDetailsItem.maxQuantity, currentDetailsItem.properties, currentDetailsItem.sellPrice);
    }

    function triggerMarket() {
        if (!currentDetailsItem.inMarket) return;
        window.location.href = '../views/detailPage.php?id=' + currentDetailsItem.inMarket;
    }

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

    // Overlay close handlers
    document.querySelectorAll('.overlay-backdrop').forEach(overlay => {
        overlay.addEventListener('click', function(event) {
            if (event.target === this) {
                this.style.display = 'none';
            }
        });
    });

    // Basic toast/error wrappers for the old functions
    function showToast(msg, type) {
        alert((type ? type.toUpperCase() + ": " : "") + msg);
    }
    function showError(msg) {
        alert("ERROR: " + msg);
    }

    function overlayOn(itemId, itemClass, maxQuantity, itemState = '') {
        document.getElementById('transferItemId').value = itemId;
        document.getElementById('transferItemClass').value = itemClass;
        document.getElementById('transferQuantity').max = maxQuantity;
        document.getElementById('transferQuantity').value = 1;
        document.getElementById('inventory-transfer-overlay').style.display = "flex";
        document.getElementById('transferItemName').textContent = itemClass;
        document.getElementById('transferItemState').value = itemState;
    }

    function overlayOff() {
        document.getElementById('inventory-transfer-overlay').style.display = "none";
    }

    function transferItem() {
        const itemId = document.getElementById('transferItemId').value;
        const itemClass = document.getElementById('transferItemClass').value;
        const targetInventoryId = document.getElementById('transferUser').value;
        const quantity = document.getElementById('transferQuantity').value;
        const itemState = document.getElementById('transferItemState').value;

        const isMoney = (itemClass === "Money");
        const endpoint = isMoney ? `${dbBaseUrl}/economy/transferFunds.php` : `${dbBaseUrl}/inventory_assets/transferItem.php`;
        
        const data = isMoney ? {
            targetInventoryId: targetInventoryId,
            amount: quantity,
            sourceInventoryId: CURRENT_INVENTORY_ID
        } : {
            itemId: itemId,
            itemClass: itemClass,
            targetInventoryId: targetInventoryId,
            quantity: quantity,
            itemState: itemState,
            sourceInventoryId: CURRENT_INVENTORY_ID
        };

        fetch(endpoint, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify(data)
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    location.reload();
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    const modifyOptionsMap = {};
    
    document.addEventListener('DOMContentLoaded', () => {
        const classesToFetch = new Set();
        document.querySelectorAll('.inv-card-plus-btn').forEach(btn => {
            const onclick = btn.getAttribute('onclick') || '';
            const match = onclick.match(/showItemDetailsOverlay\([^,]+,\s*'([^']+)'/);
            if (match && match[1]) {
                if (onclick.includes(', true, true)') || onclick.includes(', true, false)')) {
                    classesToFetch.add(match[1]);
                }
            }
        });

        const fetchClasses = Array.from(classesToFetch);
        let currentIndex = 0;

        function fetchNext() {
            if (currentIndex >= fetchClasses.length) return;
            const itemClass = fetchClasses[currentIndex];
            currentIndex++;

            fetch(`${dbBaseUrl}/inventory_assets/getInterchangeableItems.php?itemClass=${encodeURIComponent(itemClass)}`, { credentials: 'include' })
                .then(res => {
                    if (!res.ok) throw new Error("Network response was not ok");
                    return res.json();
                })
                .then(data => {
                    modifyOptionsMap[itemClass] = data;
                    fetchNext();
                })
                .catch(() => {
                    fetchNext();
                });
        }
        
        // Start 3 concurrent fetch chains
        fetchNext();
        fetchNext();
        fetchNext();
    });

    function renderModifyOptions(data) {
        const select = document.getElementById('modifyTarget');
        select.innerHTML = '';
        if (!data || data.length === 0) {
            select.innerHTML = '<div class="inv-modify-msg">No modifications available for this item.</div>';
            document.getElementById('modifyCost').textContent = "0";
            return;
        }
        data.forEach(item => {
            const option = document.createElement('div');
            option.className = 'modify-item-option';
            
            const imagePath = `../images/items/${item.item_class.toUpperCase()}.PNG`;
            const defaultImage = `../images/items/${item.item_type.toUpperCase()}.PNG`;
            
            option.innerHTML = `
                <label class="modify-item-label">
                    <input type="radio" name="targetClass" value="${item.item_class}" data-cost="${item.cost}">
                    <div class="modify-item-content">
                        <img src="${imagePath}" 
                            onerror="this.onerror=null;this.src='${defaultImage}';" 
                            alt="${item.display_name}"
                            class="modify-item-image">
                        <div class="modify-item-info">
                            <div class="modify-item-name">${item.display_name}</div>
                            <div class="modify-item-class">${item.item_class}</div>
                            <div class="modify-item-cost">${item.cost} Cr</div>
                        </div>
                    </div>
                </label>
            `;
            select.appendChild(option);
        });
        
        const firstInput = select.querySelector('input[type="radio"]');
        if (firstInput) {
            firstInput.checked = true;
            updateModifyCost();
        }
    }

    function showModifyOverlay(itemId, itemClass, maxQuantity) {
        document.getElementById('modifyItemId').value = itemId;
        document.getElementById('modifyItemClass').value = itemClass;
        document.getElementById('modifyQuantity').max = maxQuantity;
        document.getElementById('modifyQuantity').value = 1;
        document.getElementById('inventory-modify-overlay').style.display = "flex";
        document.getElementById('modifyItemName').textContent = itemClass;

        const select = document.getElementById('modifyTarget');

        if (modifyOptionsMap[itemClass]) {
            renderModifyOptions(modifyOptionsMap[itemClass]);
        } else {
            select.innerHTML = '<div class="inv-modify-msg">Loading options...</div>';
            fetch(`${dbBaseUrl}/inventory_assets/getInterchangeableItems.php?itemClass=${encodeURIComponent(itemClass)}`, { credentials: 'include' })
                .then(response => {
                    if (!response.ok) throw new Error("Network response was not ok");
                    return response.json();
                })
                .then(data => {
                    modifyOptionsMap[itemClass] = data;
                    renderModifyOptions(data);
                })
                .catch(err => {
                    select.innerHTML = '<div class="inv-modify-msg-err">Failed to load options.</div>';
                });
        }
    }

    function updateModifyCost() {
        const quantity = document.getElementById('modifyQuantity').value;
        const selectedOption = document.querySelector('input[name="targetClass"]:checked');
        const costPerItem = selectedOption ? parseInt(selectedOption.dataset.cost) : 0;
        document.getElementById('modifyCost').textContent = costPerItem * quantity;
    }

    function modifyItem() {
        const itemId = document.getElementById('modifyItemId').value;
        const itemClass = document.getElementById('modifyItemClass').value;
        const selectedOption = document.querySelector('input[name="targetClass"]:checked');
        const targetClass = selectedOption ? selectedOption.value : null;
        const quantity = document.getElementById('modifyQuantity').value;

        if (!targetClass) {
            showError('Please select a target item');
            return;
        }

        fetch(`${dbBaseUrl}/inventory_assets/modifyItem.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({
                itemId: itemId,
                sourceClass: itemClass,
                targetClass: targetClass,
                quantity: quantity,
                sourceInventoryId: CURRENT_INVENTORY_ID
            })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    location.reload();
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    function hideModifyOverlay() {
        document.getElementById('inventory-modify-overlay').style.display = "none";
    }

    document.getElementById('modifyTarget').addEventListener('change', updateModifyCost);
    document.getElementById('modifyQuantity').addEventListener('input', updateModifyCost);

    function showSellOverlay(itemId, itemClass, maxQuantity, itemState, unitPrice) {
        document.getElementById('sellItemId').value = itemId;
        document.getElementById('sellItemClass').value = itemClass;
        document.getElementById('sellQuantity').max = maxQuantity;
        document.getElementById('sellQuantity').value = 1;
        document.getElementById('sellItemName').textContent = itemClass;
        document.getElementById('sellItemState').value = itemState;
        document.getElementById('sellUnitPrice').value = unitPrice;
        document.getElementById('sellPricePerUnit').textContent = unitPrice;
        document.getElementById('inventory-sell-overlay').style.display = 'flex';
        updateSellTotal();
    }

    function hideSellOverlay() {
        document.getElementById('inventory-sell-overlay').style.display = 'none';
    }

    function updateSellTotal() {
        const quantity = parseInt(document.getElementById('sellQuantity').value) || 0;
        const unitPrice = parseFloat(document.getElementById('sellUnitPrice').value) || 0;
        document.getElementById('sellTotalPrice').textContent = Math.floor(quantity * unitPrice);
    }

    function sellItem() {
        const itemId = document.getElementById('sellItemId').value;
        const itemClass = document.getElementById('sellItemClass').value;
        const quantity = document.getElementById('sellQuantity').value;
        const itemState = document.getElementById('sellItemState').value;

        fetch(`${dbBaseUrl}/inventory_assets/sellItem.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({
                itemId: itemId,
                itemClass: itemClass,
                quantity: quantity,
                itemState: itemState,
                sourceInventoryId: CURRENT_INVENTORY_ID
            })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast(`Sold ${quantity}x ${itemClass} for ${json.earnings} Cr`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }
    // Repack All
    function showRepackAllOverlay() {
        document.getElementById('inventory-repack-all-overlay').style.display = 'flex';
    }

    function hideRepackAllOverlay() {
        document.getElementById('inventory-repack-all-overlay').style.display = 'none';
    }

    function confirmRepackAll() {
        hideRepackAllOverlay();
        fetch(`../db/inventory_assets/repackAllMagazines.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({ sourceInventoryId: CURRENT_INVENTORY_ID })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast(json.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }

    // Refill All
    function showRefillAllOverlay() {
        document.getElementById('inventory-refill-all-overlay').style.display = 'flex';
        const costSpan = document.getElementById('refillAllTotalCost');
        const confirmBtn = document.getElementById('btnConfirmRefillAll');
        
        costSpan.textContent = "Loading...";
        confirmBtn.disabled = true;

        fetch(`../db/inventory_assets/getRefillAllCost.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({ sourceInventoryId: CURRENT_INVENTORY_ID })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    costSpan.textContent = json.totalCost;
                    if (json.totalCost > 0) {
                        confirmBtn.disabled = false;
                    } else {
                        costSpan.textContent = "0 (No magazines need refilling)";
                    }
                } else {
                    costSpan.textContent = "Error";
                    showError(json.error);
                }
            } catch (e) {
                costSpan.textContent = "Error";
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => {
            costSpan.textContent = "Error";
            showError("Fetch error: " + err.message);
        });
    }

    function hideRefillAllOverlay() {
        document.getElementById('inventory-refill-all-overlay').style.display = 'none';
    }

    function confirmRefillAll() {
        hideRefillAllOverlay();
        fetch(`../db/inventory_assets/refillAllMagazines.php`, {
            method: "POST",
            headers: { 
                "Content-Type": "application/json",
                "X-CSRF-Token": "<?php echo $_SESSION['csrf_token'] ?? ''; ?>"
            },
            credentials: "include",
            body: JSON.stringify({ sourceInventoryId: CURRENT_INVENTORY_ID })
        })
        .then(async response => {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                if (json.success) {
                    showToast("All magazines refilled successfully!", 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError(json.error);
                }
            } catch (e) {
                showError("JSON parse error: " + e.message);
            }
        })
        .catch(err => showError("Fetch error: " + err.message));
    }
</script>



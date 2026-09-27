<?php
require_once '../db/connection.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    redirectToLogin();
}

if ($_SESSION['user_id'] === -1) {
    header('Location: shop.php');
    exit();
}

// Resolve expired auctions on every page load
require_once '../db/market/resolvePlayerAuctions.php';
resolveExpiredAuctions($pdo);

$host = $_SERVER['HTTP_HOST'] ?? '';
$imageBaseUrl = '../images';

require_once '../includes/imageHelper.php';
$hasMedication = getMedicationStatus($pdo, $_SESSION['user_id']);

// Get filter parameters
$selectedType = isset($_GET['type']) ? $_GET['type'] : 'all';
$listingFilter = isset($_GET['listing']) ? $_GET['listing'] : 'all'; // all, fixed, auction
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$sortDir = isset($_GET['dir']) ? $_GET['dir'] : 'desc';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$showOwn = isset($_GET['own']) ? $_GET['own'] : '0'; // 0 = all, 1 = my listings only

// Get distinct item types from active listings
$typeQuery = "SELECT DISTINCT cit.Custom_Item_Type 
              FROM player_market_listings pml
              LEFT JOIN items i ON i.Item_Class = pml.Item_Class
              LEFT JOIN item_types it ON it.Item_Type_Id = i.Item_Type
              LEFT JOIN custom_item_types cit ON cit.Original_Item_Type = it.item_classification
              WHERE pml.Status = 'active'
              ORDER BY cit.Custom_Item_Type";
$typeStmt = $pdo->query($typeQuery);
$types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

// Build listings query
$query = "SELECT pml.*, 
            inv.Inventory_Name AS Seller_Name,
            IFNULL(i.Item_Display_Name, pml.Item_Class) AS Display_Name,
            cit.Custom_Item_Type,
            bidder_inv.Inventory_Name AS Bidder_Name
          FROM player_market_listings pml
          LEFT JOIN inventories inv ON inv.Inventory_Id = pml.Seller_Inventory_Id
          LEFT JOIN items i ON i.Item_Class = pml.Item_Class
          LEFT JOIN item_types it ON it.Item_Type_Id = i.Item_Type
          LEFT JOIN custom_item_types cit ON cit.Original_Item_Type = it.item_classification
          LEFT JOIN inventories bidder_inv ON bidder_inv.Inventory_Id = pml.Current_Bidder_Inventory_Id
          WHERE pml.Status = 'active'";

$params = [];

// Type filter
if ($selectedType !== 'all') {
    $query .= " AND cit.Custom_Item_Type = :type";
    $params[':type'] = $selectedType;
}

// Listing type filter
if ($listingFilter !== 'all') {
    $query .= " AND pml.Listing_Type = :listingType";
    $params[':listingType'] = $listingFilter;
}

// Own listings filter
if ($showOwn === '1') {
    $query .= " AND pml.Seller_Inventory_Id = :myInventory";
    $params[':myInventory'] = $_SESSION['inventory_id'];
}

// Search
if (!empty($search)) {
    $query .= " AND (pml.Item_Class LIKE :search OR pml.Item_Display_Name LIKE :search OR inv.Inventory_Name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Prevent duplicates from 1-to-many JOINs (e.g. duplicate custom_item_types rows)
$query .= " GROUP BY pml.Listing_Id";

// Sorting
$query .= " ORDER BY ";
switch ($sortBy) {
    case 'price':
        $query .= "COALESCE(pml.Fixed_Price, pml.Current_Bid, pml.Min_Bid) " . ($sortDir === 'desc' ? 'DESC' : 'ASC');
        break;
    case 'ending':
        $query .= "pml.End_Date IS NULL, pml.End_Date " . ($sortDir === 'desc' ? 'DESC' : 'ASC');
        break;
    case 'name':
        $query .= "Display_Name " . ($sortDir === 'desc' ? 'DESC' : 'ASC');
        break;
    case 'newest':
    default:
        $query .= "pml.Created_At " . ($sortDir === 'desc' ? 'DESC' : 'ASC');
}

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's inventory items for the "Create Listing" form
$myItemsQuery = "SELECT DISTINCT ci.Content_Item_Id, ci.Item_Class, ci.Item_Quantity, ci.Item_Properties,
                    IFNULL(i.Item_Display_Name, ci.Item_Class) AS Item_Display_Name,
                    cit.Custom_Item_Type
                FROM content_items ci
                LEFT JOIN items i ON i.Item_Class = ci.Item_Class 
                LEFT JOIN item_types it ON it.Item_Type_Id = i.Item_Type
                LEFT JOIN custom_item_types cit ON cit.Original_Item_Type = it.item_classification
                WHERE ci.Inventory_Id = :inventory_id
                AND ci.Content_Item_Id NOT IN (
                    SELECT Content_Item_Id FROM player_market_listings WHERE Status = 'active'
                )
                ORDER BY cit.Custom_Item_Type, ci.Item_Class";
$myItemsStmt = $pdo->prepare($myItemsQuery);
$myItemsStmt->execute([':inventory_id' => $_SESSION['inventory_id']]);
$myItems = $myItemsStmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/error.php';
include '../includes/toast.php';
?>

    <!-- Buy Fixed Price Overlay -->
    <div id="pmarket-buy-overlay" class="preview-modal-overlay d-none">
        <div id="pmarket-buy-overlay-container" class="preview-modal-content">
            <h3>Buy Item</h3>
            <p id="buyItemName" class="pmarket-overlay-item-name"></p>
            <form id="buyForm" class="pmarket-overlay-form">
                <input type="hidden" id="buyListingId" value="">
                <div class="pmarket-overlay-info">
                    <strong>Price:</strong> <span id="buyPrice"></span> Cr
                </div>
                <div class="pmarket-overlay-info">
                    <strong>Quantity:</strong> <span id="buyQuantity"></span>
                </div>
                <div class="pmarket-overlay-info">
                    <strong>Seller:</strong> <span id="buySeller"></span>
                </div>
                <div class="pmarket-overlay-form-actions">
                    <button type="button" class="btn-industrial success" onclick="buyListing()">Confirm Purchase</button>
                    <button type="button" class="btn-industrial danger" onclick="closeBuyOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bid Overlay -->
    <div id="pmarket-bid-overlay" class="preview-modal-overlay d-none">
        <div id="pmarket-bid-overlay-container" class="preview-modal-content">
            <h3>Place Bid</h3>
            <p id="bidItemName" class="pmarket-overlay-item-name"></p>
            <form id="bidForm" class="pmarket-overlay-form">
                <input type="hidden" id="bidListingId" value="">
                <div class="pmarket-overlay-info">
                    <strong>Minimum Bid:</strong> <span id="bidMinBid"></span> Cr
                </div>
                <div class="pmarket-overlay-info">
                    <strong>Current Bid:</strong> <span id="bidCurrentBid"></span> Cr
                </div>
                <div class="pmarket-overlay-info">
                    <strong>Ends:</strong> <span id="bidEndDate"></span>
                </div>
                <div class="pmarket-overlay-form-row">
                    <label for="bidAmount">Your Bid (Cr):</label>
                    <input type="number" id="bidAmount" name="bidAmount" min="1" step="1" required>
                </div>
                <div class="pmarket-overlay-form-actions">
                    <button type="button" class="btn-industrial success" onclick="placeBid()">Place Bid</button>
                    <button type="button" class="btn-industrial danger" onclick="closeBidOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Listing Overlay -->
    <div id="pmarket-create-overlay" class="preview-modal-overlay d-none">
        <div id="pmarket-create-overlay-container" class="preview-modal-content">
            <h3>Create Listing</h3>
            <form id="createListingForm" class="pmarket-overlay-form">
                <div class="pmarket-overlay-form-row">
                    <label for="createItemSelect">Item:</label>
                    <select id="createItemSelect" onchange="updateCreateForm()" required>
                        <option value="">-- Select an item --</option>
                        <?php foreach ($myItems as $myItem): ?>
                            <option value="<?php echo $myItem['Content_Item_Id']; ?>" 
                                    data-max-qty="<?php echo $myItem['Item_Quantity']; ?>"
                                    data-class="<?php echo htmlspecialchars($myItem['Item_Class']); ?>"
                                    data-name="<?php echo htmlspecialchars($myItem['Item_Display_Name']); ?>">
                                <?php echo htmlspecialchars($myItem['Item_Display_Name']); ?> 
                                (<?php echo htmlspecialchars($myItem['Item_Class']); ?>) 
                                x<?php echo $myItem['Item_Quantity']; ?>
                                <?php if (!empty($myItem['Item_Properties'])): ?> 
                                    [<?php echo htmlspecialchars($myItem['Item_Properties']); ?>]
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pmarket-overlay-form-row">
                    <label for="createQuantity">Quantity:</label>
                    <input type="number" id="createQuantity" min="1" value="1" required>
                </div>
                <div class="pmarket-overlay-form-row">
                    <label for="createListingType">Listing Type:</label>
                    <select id="createListingType" onchange="toggleListingTypeFields()" required>
                        <option value="fixed">Fixed Price</option>
                        <option value="auction">Auction</option>
                    </select>
                </div>
                <div id="fixedPriceFields">
                    <div class="pmarket-overlay-form-row">
                        <label for="createFixedPrice">Price (Cr):</label>
                        <input type="number" id="createFixedPrice" min="1" step="1">
                    </div>
                </div>
                <div id="auctionFields" class="d-none">
                    <div class="pmarket-overlay-form-row">
                        <label for="createMinBid">Minimum Bid (Cr):</label>
                        <input type="number" id="createMinBid" min="1" step="1">
                    </div>
                    <div class="pmarket-overlay-form-row">
                        <label for="createEndDate">End Date:</label>
                        <input type="datetime-local" id="createEndDate">
                    </div>
                </div>
                <div class="pmarket-overlay-form-actions">
                    <button type="button" class="btn-industrial success" onclick="createListing()">Create Listing</button>
                    <button type="button" class="btn-industrial danger" onclick="closeCreateOverlay()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="shop-preview-container">
        <!-- Top Filter Bar -->
        <div class="shop-top-bar">
            <div class="shop-filters-container">
                <div class="shop-type-filters">
                    <a href="?type=all<?php echo '&listing='.htmlspecialchars($listingFilter, ENT_QUOTES).'&sort='.htmlspecialchars($sortBy, ENT_QUOTES).'&dir='.htmlspecialchars($sortDir, ENT_QUOTES).'&own='.htmlspecialchars($showOwn, ENT_QUOTES); ?>"
                       class="shop-filter-btn <?php echo $selectedType === 'all' ? 'active' : ''; ?>">All</a>
                    <?php foreach ($types as $type): ?>
                        <?php if (!empty($type)): ?>
                            <a href="?type=<?php echo urlencode($type); ?><?php echo '&listing='.htmlspecialchars($listingFilter, ENT_QUOTES).'&sort='.htmlspecialchars($sortBy, ENT_QUOTES).'&dir='.htmlspecialchars($sortDir, ENT_QUOTES).'&own='.htmlspecialchars($showOwn, ENT_QUOTES); ?>"
                               class="shop-filter-btn <?php echo $selectedType === $type ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($type); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a href="?own=<?php echo $showOwn === '1' ? '0' : '1'; ?><?php echo '&type='.htmlspecialchars($selectedType, ENT_QUOTES).'&listing='.htmlspecialchars($listingFilter, ENT_QUOTES).'&sort='.htmlspecialchars($sortBy, ENT_QUOTES).'&dir='.htmlspecialchars($sortDir, ENT_QUOTES); ?>"
                       class="shop-filter-btn <?php echo $showOwn === '1' ? 'active' : ''; ?>">My Listings</a>
                </div>
            </div>
            <button class="btn-industrial success" onclick="openCreateOverlay()">+ Create Listing</button>
        </div>

        <!-- Listings grid -->
        <div class="inv-items-container is-grid-container active-container shop-grid-container" id="shop-grid">
                <?php if (empty($listings)): ?>
                    <p class="pmarket-no-listings">No active listings found.</p>
                <?php endif; ?>
                <?php foreach ($listings as $listing): ?>
                    <?php
                        $isOwn = (isset($_SESSION['inventory_id']) && $listing['Seller_Inventory_Id'] == $_SESSION['inventory_id']);
                        $isAuction = ($listing['Listing_Type'] === 'auction');
                        $isHighestBidder = ($listing['Current_Bidder_Inventory_Id'] == $_SESSION['inventory_id']);
                        $imgPaths = resolveItemImagePaths($hasMedication, $imageBaseUrl, $listing['Item_Class'], $listing['Custom_Item_Type'] ?? 'UNKNOWN');
                        $imagePath = $imgPaths['imagePath'];
                        $defaultImage = $imgPaths['defaultImage'];
                        $fileCheckPath = $imgPaths['fileCheckPath'];
                        $timeLeft = '';
                        if ($isAuction && $listing['End_Date']) {
                            $endDate = new DateTime($listing['End_Date']);
                            $now = new DateTime();
                            if ($endDate > $now) {
                                $diff = $now->diff($endDate);
                                if ($diff->days > 0) {
                                    $timeLeft = $diff->days . 'd ' . $diff->h . 'h';
                                } elseif ($diff->h > 0) {
                                    $timeLeft = $diff->h . 'h ' . $diff->i . 'm';
                                } else {
                                    $timeLeft = $diff->i . 'm ' . $diff->s . 's';
                                }
                            } else {
                                $timeLeft = 'Ended';
                            }
                        }
                    ?>
                    <div class="inv-card <?php echo $isOwn ? 'pmarket-own-listing' : ''; ?>">
                        <div class="inv-card-image shop-item-image">
                            <img src="<?php echo file_exists($fileCheckPath) ? $imagePath : $defaultImage; ?>" 
                                 alt="<?php echo htmlspecialchars($listing['Item_Class']); ?>"
                                 class="shop-item-img"
                                 loading="lazy">
                            <span class="pmarket-listing-type-badge <?php echo $isAuction ? 'pmarket-badge-auction' : 'pmarket-badge-fixed'; ?>">
                                <?php echo $isAuction ? 'AUCTION' : 'FIXED'; ?>
                            </span>
                        </div>
                        <div class="shop-item-price-bg">
                            <?php if (!$isAuction): ?>
                                <div class="shop-item-price"><?php echo number_format($listing['Fixed_Price'], 2, '.', "'"); ?> Cr</div>
                                <div class="pmarket-time-left">Fixed Price</div>
                            <?php else: ?>
                                <div class="shop-item-price">
                                    <?php if ($listing['Current_Bid']): ?>
                                        <?php echo number_format($listing['Current_Bid'], 2, '.', "'"); ?> Cr <?php if ($isHighestBidder): ?><span class="pmarket-yours-badge">(Yours)</span><?php endif; ?>
                                    <?php else: ?>
                                        <?php echo number_format($listing['Min_Bid'], 2, '.', "'"); ?> Cr (Min)
                                    <?php endif; ?>
                                </div>
                                <div class="pmarket-time-left <?php echo $timeLeft === 'Ended' ? 'pmarket-ended' : ''; ?>">
                                    <?php echo $timeLeft; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="pmarket-item-info">
                            <div class="shop-item-title"><?php echo htmlspecialchars($listing['Display_Name']); ?></div>
                            <div class="pmarket-item-meta">
                                Seller: <?php echo htmlspecialchars($listing['Seller_Name']); ?><br>
                                Qty: <?php echo htmlspecialchars($listing['Quantity']); ?>
                                <?php if (!empty($listing['Item_Properties'])): ?><br>Props: <?php echo htmlspecialchars($listing['Item_Properties']); ?><?php endif; ?>
                            </div>
                        </div>

                        <div class="pmarket-item-actions">
                            <?php if ($isOwn): ?>
                                <?php if ($listing['Listing_Type'] === 'auction' && $listing['Current_Bid'] !== null): ?>
                                    <button class="btn-industrial disabled" title="Cannot cancel - auction has bids">Cancel</button>
                                <?php else: ?>
                                    <button onclick="cancelListing(<?php echo $listing['Listing_Id']; ?>)" class="btn-industrial danger">Cancel</button>
                                <?php endif; ?>
                            <?php elseif (!$isAuction): ?>
                                <?php 
                                $canBuy = ($_SESSION['inventory_money'] >= $listing['Fixed_Price']);
                                ?>
                                <?php if ($canBuy): ?>
                                    <button onclick="openBuyOverlay(<?php echo $listing['Listing_Id']; ?>, <?php echo htmlspecialchars(json_encode($listing['Display_Name']), ENT_QUOTES); ?>, <?php echo $listing['Fixed_Price']; ?>, <?php echo $listing['Quantity']; ?>, <?php echo htmlspecialchars(json_encode($listing['Seller_Name']), ENT_QUOTES); ?>)" class="btn-industrial success">Buy</button>
                                <?php else: ?>
                                    <button class="btn-industrial disabled" title="Not enough credits">Buy</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($timeLeft !== 'Ended'): ?>
                                    <button onclick="openBidOverlay(<?php echo $listing['Listing_Id']; ?>, <?php echo htmlspecialchars(json_encode($listing['Display_Name']), ENT_QUOTES); ?>, <?php echo $listing['Min_Bid']; ?>, <?php echo $listing['Current_Bid'] ?? 0; ?>, <?php echo htmlspecialchars(json_encode($listing['End_Date']), ENT_QUOTES); ?>)" class="btn-industrial">Bid</button>
                                <?php else: ?>
                                    <button class="btn-industrial disabled">Ended</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Color Extraction
            document.querySelectorAll('.inv-card').forEach(card => {
                const img = card.querySelector('.shop-item-img');
                const priceDiv = card.querySelector('.shop-item-price-bg');
                if (!img || !priceDiv) return;

                const processColor = () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = img.naturalWidth;
                    canvas.height = img.naturalHeight;
                    if (canvas.width === 0 || canvas.height === 0) return;
                    
                    const ctx = canvas.getContext('2d', { willReadFrequently: true });
                    ctx.drawImage(img, 0, 0);
                    
                    const x = Math.floor(canvas.width / 2);
                    const y = canvas.height - 1;
                    
                    let pixel;
                    try {
                        pixel = ctx.getImageData(x, y, 1, 1).data;
                    } catch (e) {
                        return; // Ignore cross-origin errors if any
                    }
                    
                    const r = pixel[0];
                    const g = pixel[1];
                    const b = pixel[2];
                    const a = pixel[3] / 255;
                    
                    const outAlpha = a + 0.4 * (1 - a);
                    let outR = 0, outG = 0, outB = 0;
                    
                    if (outAlpha > 0) {
                        outR = Math.round((r * a) / outAlpha);
                        outG = Math.round((g * a) / outAlpha);
                        outB = Math.round((b * a) / outAlpha);
                    }
                    
                    priceDiv.style.backgroundColor = `rgba(${outR}, ${outG}, ${outB}, ${outAlpha})`;
                };

                if (img.complete && img.naturalHeight !== 0) {
                    processColor();
                } else {
                    img.addEventListener('load', processColor);
                }
            });

            // Momentum Drag Scrolling
            const shopContainer = document.querySelector('.shop-preview-container');
            if (shopContainer) {
                let isDown = false;
                let startY;
                let velocity = 0;
                let lastY;
                let lastTimestamp;
                let momentumID;
                let isDragging = false;

                shopContainer.addEventListener('mousedown', (e) => {
                    isDown = true;
                    isDragging = false;
                    shopContainer.classList.add('is-grabbing');
                    startY = e.clientY;
                    lastY = e.clientY;
                    lastTimestamp = Date.now();
                    cancelAnimationFrame(momentumID);
                });

                shopContainer.addEventListener('mouseleave', () => {
                    if (!isDown) return;
                    isDown = false;
                    shopContainer.classList.remove('is-grabbing');
                    beginMomentumTracking();
                });

                shopContainer.addEventListener('mouseup', () => {
                    if (!isDown) return;
                    isDown = false;
                    shopContainer.classList.remove('is-grabbing');
                    beginMomentumTracking();
                });

                shopContainer.addEventListener('mousemove', (e) => {
                    if (!isDown) return;
                    e.preventDefault();
                    
                    const dy = e.clientY - lastY;
                    
                    if (Math.abs(e.clientY - startY) > 5) {
                        isDragging = true;
                    }

                    shopContainer.scrollTop -= dy;

                    const now = Date.now();
                    const elapsed = now - lastTimestamp;
                    if (elapsed > 0) {
                        velocity = dy / elapsed;
                    }
                    
                    lastY = e.clientY;
                    lastTimestamp = now;
                });

                shopContainer.addEventListener('click', (e) => {
                    if (isDragging) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }, { capture: true });

                function beginMomentumTracking() {
                    let amplitude = velocity * 15;
                    
                    function updateMomentum() {
                        if (Math.abs(amplitude) < 0.5) return;
                        
                        shopContainer.scrollTop -= amplitude;
                        amplitude *= 0.95;
                        
                        momentumID = requestAnimationFrame(updateMomentum);
                    }
                    
                    momentumID = requestAnimationFrame(updateMomentum);
                }
            }
        });
        
        // Overlay handlers
        function openBuyOverlay(listingId, itemName, price, quantity, seller) {
            document.getElementById('buyListingId').value = listingId;
            document.getElementById('buyItemName').textContent = itemName;
            let formattedPrice = price.toFixed(2).split('.');
            formattedPrice[0] = formattedPrice[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
            document.getElementById('buyPrice').textContent = formattedPrice.join('.');
            document.getElementById('buyQuantity').textContent = quantity;
            document.getElementById('buySeller').textContent = seller;
            const overlay = document.getElementById('pmarket-buy-overlay');
            overlay.classList.remove('d-none');
            overlay.classList.add('active');
        }

        function closeBuyOverlay() {
            const overlay = document.getElementById('pmarket-buy-overlay');
            overlay.classList.remove('active');
            setTimeout(() => overlay.classList.add('d-none'), 300);
        }

        function openBidOverlay(listingId, itemName, minBid, currentBid, endDate) {
            document.getElementById('bidListingId').value = listingId;
            document.getElementById('bidItemName').textContent = itemName;
            let formattedMin = minBid.toFixed(2).split('.');
            formattedMin[0] = formattedMin[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
            document.getElementById('bidMinBid').textContent = formattedMin.join('.');
            let formattedCur = currentBid.toFixed(2).split('.');
            formattedCur[0] = formattedCur[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
            document.getElementById('bidCurrentBid').textContent = currentBid > 0 ? formattedCur.join('.') : 'None';
            document.getElementById('bidEndDate').textContent = new Date(endDate).toLocaleString();
            const minBidValue = currentBid > 0 ? currentBid + 1 : minBid;
            document.getElementById('bidAmount').min = minBidValue;
            document.getElementById('bidAmount').value = minBidValue;
            const overlay = document.getElementById('pmarket-bid-overlay');
            overlay.classList.remove('d-none');
            overlay.classList.add('active');
        }

        function closeBidOverlay() {
            const overlay = document.getElementById('pmarket-bid-overlay');
            overlay.classList.remove('active');
            setTimeout(() => overlay.classList.add('d-none'), 300);
        }

        function openCreateOverlay() {
            const overlay = document.getElementById('pmarket-create-overlay');
            overlay.classList.remove('d-none');
            overlay.classList.add('active');
        }

        function closeCreateOverlay() {
            const overlay = document.getElementById('pmarket-create-overlay');
            overlay.classList.remove('active');
            setTimeout(() => overlay.classList.add('d-none'), 300);
        }

        function toggleListingTypeFields() {
            const type = document.getElementById('createListingType').value;
            document.getElementById('fixedPriceFields').style.display = type === 'fixed' ? 'block' : 'none';
            document.getElementById('auctionFields').style.display = type === 'auction' ? 'block' : 'none';
        }

        function updateCreateForm() {
            const select = document.getElementById('createItemSelect');
            const option = select.options[select.selectedIndex];
            if (option && option.value) {
                const maxQty = parseInt(option.dataset.maxQty);
                document.getElementById('createQuantity').max = maxQty;
                document.getElementById('createQuantity').value = 1;
            }
        }

        // API calls
        function buyListing() {
            const listingId = document.getElementById('buyListingId').value;
            const data = { listingId: parseInt(listingId) };

            fetch("../db/market/buyPlayerListing.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(data)
            })
            .then(async response => {
                const text = await response.text();
                try {
                    const json = JSON.parse(text);
                    if (json.success) {
                        showToast('Item purchased successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError(json.error);
                    }
                } catch (e) {
                    showError("JSON parse error: " + e.message);
                }
            })
            .catch(err => showError("Fetch error: " + err.message));
        }

        function placeBid() {
            const listingId = document.getElementById('bidListingId').value;
            const bidAmount = document.getElementById('bidAmount').value;
            const data = { listingId: parseInt(listingId), bidAmount: parseFloat(bidAmount) };

            fetch("../db/market/placeBid.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(data)
            })
            .then(async response => {
                const text = await response.text();
                try {
                    const json = JSON.parse(text);
                    if (json.success) {
                        showToast('Bid placed successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError(json.error);
                    }
                } catch (e) {
                    showError("JSON parse error: " + e.message);
                }
            })
            .catch(err => showError("Fetch error: " + err.message));
        }

        function createListing() {
            const contentItemId = document.getElementById('createItemSelect').value;
            const quantity = parseInt(document.getElementById('createQuantity').value);
            const listingType = document.getElementById('createListingType').value;

            if (!contentItemId) {
                showError('Please select an item');
                return;
            }

            const data = {
                contentItemId: parseInt(contentItemId),
                quantity: quantity,
                listingType: listingType
            };

            if (listingType === 'fixed') {
                const price = parseFloat(document.getElementById('createFixedPrice').value);
                if (!price || price <= 0) {
                    showError('Please enter a valid price');
                    return;
                }
                data.fixedPrice = price;
            } else {
                const minBid = parseFloat(document.getElementById('createMinBid').value);
                const endDate = document.getElementById('createEndDate').value;
                if (!minBid || minBid <= 0) {
                    showError('Please enter a valid minimum bid');
                    return;
                }
                if (!endDate) {
                    showError('Please select an end date');
                    return;
                }
                data.minBid = minBid;
                data.endDate = endDate;
            }

            fetch("../db/market/createPlayerListing.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(data)
            })
            .then(async response => {
                const text = await response.text();
                try {
                    const json = JSON.parse(text);
                    if (json.success) {
                        showToast('Listing created successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError(json.error);
                    }
                } catch (e) {
                    showError("JSON parse error: " + e.message);
                }
            })
            .catch(err => showError("Fetch error: " + err.message));
        }

        function cancelListing(listingId) {
            if (!confirm('Are you sure you want to cancel this listing?')) return;

            const data = { listingId: listingId };

            fetch("../db/market/cancelPlayerListing.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(data)
            })
            .then(async response => {
                const text = await response.text();
                try {
                    const json = JSON.parse(text);
                    if (json.success) {
                        showToast('Listing cancelled.', 'info');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError(json.error);
                    }
                } catch (e) {
                    showError("JSON parse error: " + e.message);
                }
            })
            .catch(err => showError("Fetch error: " + err.message));
        }

        // Filter/sort helpers
        function toggleSortDirection() {
            const urlParams = new URLSearchParams(window.location.search);
            const currentDir = urlParams.get('dir') || 'desc';
            urlParams.set('dir', currentDir === 'asc' ? 'desc' : 'asc');
            window.location.href = `?${urlParams.toString()}`;
        }

        function updateSort(value) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort', value);
            window.location.href = `?${urlParams.toString()}`;
        }

        function updateListingFilter(value) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('listing', value);
            window.location.href = `?${urlParams.toString()}`;
        }

        function updateSearch(value) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('search', value);
            window.location.href = `?${urlParams.toString()}`;
        }

        // Click outside overlay to close
        document.getElementById('pmarket-buy-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeBuyOverlay();
        });
        document.getElementById('pmarket-bid-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeBidOverlay();
        });
        document.getElementById('pmarket-create-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeCreateOverlay();
        });
    </script>
</body>
</html>

<?php include '../includes/footer.php'; ?>



# Cinder9 Intranet — API Endpoints

All endpoints are in `db/` and return JSON `{success, error}`. POST endpoints accept `application/json` unless noted.

## `db/auth_users/` — Authentication & User Management

| File | Method | Auth | Purpose |
|---|---|---|---|
| `addUser.php` | POST | Admin | Add a new user account |
| `addNewUserComplete.php` | POST | Admin | Create user with inventory assignment |
| `changePassword.php` | POST | User | Change own password |
| `adminResetPassword.php` | POST | Admin | Reset any user's password |
| `deleteUser.php` | POST | Admin | Delete a user account |

## `db/economy/` — Financial Operations

| File | Method | Auth | Purpose |
|---|---|---|---|
| `addMoney.php` | POST | Admin | Add credits to an inventory |
| `bulkUpdateMoney.php` | POST | Admin | Update multiple inventory balances at once |
| `transferFunds.php` | POST | User | Transfer credits between inventories |
| `saveFinancialReport.php` | POST | Admin | Save a financial report |
| `deleteFinancialReport.php` | POST | Admin | Delete a financial report |

## `db/inventory_assets/` — Inventory Operations

| File | Method | Auth | Purpose |
|---|---|---|---|
| `addInventory.php` | POST | Admin | Create a new inventory |
| `deleteInventory.php` | POST | Admin | Delete an inventory |
| `renameInventory.php` | POST | Admin | Rename an inventory |
| `updateInventoryType.php` | POST | Admin | Change inventory type |
| `addItemToInventory.php` | POST | Admin | Add item to an inventory |
| `changeQuantity.php` | POST | Admin | Change item quantity |
| `buyItem.php` | POST | User | Purchase item from vendor market |
| `sellItem.php` | POST | User | Sell item back to vendor market |
| `transferItem.php` | POST | User | Transfer item between inventories |
| `modifyItem.php` | POST | User | Exchange item for interchangeable alternative |
| `alterItem.php` | POST | Admin | Modify item properties |
| `refillMagazine.php` | POST | User | Refill partial magazine from ammo box |
| `refillAllMagazines.php` | POST | User | Refill all partial magazines |
| `repackMagazines.php` | POST | User | Repack magazines |
| `repackAllMagazines.php` | POST | User | Repack all magazines |
| `getRefillAllCost.php` | GET | User | Get cost to refill all magazines |
| `getInterchangeableItems.php` | GET | User | Get interchangeable items for an item |
| `updateInterchangeableItems.php` | POST | Admin | Update interchangeable item rules |
| `changeWebUserInventory.php` | POST | Admin | Change which inventory a user is assigned to |
| `addAsset.php` | POST | Admin | Add a company asset |
| `updateAsset.php` | POST | Admin | Update a company asset |
| `deleteAsset.php` | POST | Admin | Delete a company asset |
| `addAssetGroup.php` | POST | Admin | Create asset group |
| `deleteAssetGroup.php` | POST | Admin | Delete asset group |
| `renameAssetGroup.php` | POST | Admin | Rename asset group |
| `assignAssetToGroup.php` | POST | Admin | Assign asset to group |
| `unassignAssetFromGroup.php` | POST | Admin | Unassign asset from group |
| `addResource.php` | POST | Admin | Add a resource |
| `updateResource.php` | POST | Admin | Update a resource |
| `deleteResource.php` | POST | Admin | Delete a resource |
| `uploadVehicleImage.php` | POST | Admin | Upload vehicle image (multipart) |

## `db/market/` — Market & Player Market

| File | Method | Auth | Purpose |
|---|---|---|---|
| `addMarket.php` | POST | Admin | Create a new market |
| `updateMarket.php` | POST | Admin | Update market properties |
| `deleteMarket.php` | POST | Admin | Delete a market |
| `addMarketItem.php` | POST | Admin | Add item to market |
| `removeMarketItem.php` | POST | Admin | Remove item from market |
| `createPlayerListing.php` | POST | User | List an inventory item for player sale |
| `cancelPlayerListing.php` | POST | User | Cancel own player market listing |
| `buyPlayerListing.php` | POST | User | Buy a fixed-price player listing |
| `placeBid.php` | POST | User | Place bid on an auction listing |
| `resolvePlayerAuctions.php` | — | Internal | Resolve expired auctions (called by cronjob) |

## `db/roster_orbat/` — Team Hierarchy & ORBAT

| File | Method | Auth | Purpose |
|---|---|---|---|
| `addTeam.php` | POST | Admin | Create a team |
| `updateTeam.php` | POST | Admin | Update team properties |
| `deleteTeam.php` | POST | Admin | Delete a team |
| `addPlayer.php` | POST | Admin | Add player to team |
| `uploadTeamIcon.php` | POST | Admin | Upload team icon (multipart) |
| `updateTeamNote.php` | POST | Admin | Update team note |
| `addOrbatTeam.php` | POST | User | Create ORBAT team for dashboard |
| `deleteOrbatTeam.php` | POST | User | Delete ORBAT team |
| `renameOrbatTeam.php` | POST | User | Rename ORBAT team |
| `assignOrbat.php` | POST | User | Assign player to ORBAT team |
| `unassignOrbat.php` | POST | User | Unassign player from ORBAT team |
| `getOrbatData.php` | GET | User | Get ORBAT data for dashboard |
| `donateToTeam.php` | POST | User | Donate credits to a team |

## `db/profile_social/` — Profiles & Social

| File | Method | Auth | Purpose |
|---|---|---|---|
| `updateProfile.php` | POST | User | Update own profile fields |
| `updateProfileDescription.php` | POST | User | Update profile description |
| `updateProfileRole.php` | POST | Admin | Update a player's role |
| `deleteProfile.php` | POST | Admin | Delete a profile |
| `uploadProfileImage.php` | POST | User | Upload profile avatar (multipart) |
| `uploadProfileImages.php` | POST | User | Upload gallery images (multipart) |
| `deleteProfileImage.php` | POST | User | Delete a gallery image |
| `getImageData.php` | GET | User | Get image metadata |
| `updateImageDescription.php` | POST | User | Update image description |
| `addComment.php` | POST | User | Add comment to a profile |
| `deleteComment.php` | POST | User | Delete a profile comment |
| `addImageComment.php` | POST | User | Add comment to a gallery image |
| `deleteImageComment.php` | POST | User | Delete an image comment |
| `addNote.php` | POST | User | Add a note to a profile |
| `deleteNote.php` | POST | User | Delete a profile note |

## `db/wiki/` — Wiki

| File | Method | Auth | Purpose |
|---|---|---|---|
| `saveWikiPage.php` | POST | Admin | Create or update a wiki page |
| `deleteWikiPage.php` | POST | Admin | Delete a wiki page |
| `uploadWikiImage.php` | POST | Admin | Upload wiki image (multipart) |
| `getWikiImages.php` | GET | Admin | Get available wiki images |
| `deleteWikiImage.php` | POST | Admin | Delete a wiki image |

## `db/forum_chat/` — Forum & Chat

| File | Method | Auth | Purpose |
|---|---|---|---|
| `addForumThread.php` | POST | User | Create a forum thread |
| `addForumPost.php` | POST | User | Add a post to a thread |
| `uploadForumMedia.php` | POST | User | Upload forum media (multipart) |
| `liveChatSend.php` | POST | User | Send a live chat message |
| `liveChatPoll.php` | GET | User | Poll for new chat messages |
| `updateForumLastOnline.php` | POST | User | Update forum last-online timestamp |

## `db/news/` — News & Documents

| File | Method | Auth | Purpose |
|---|---|---|---|
| `uploadNews.php` | POST | Admin | Upload a news/document file (multipart) |
| `renameNews.php` | POST | Admin | Rename a news/document file |
| `deleteNews.php` | POST | Admin | Delete a news/document file |

## `db/notebook/` — Dashboard Notebook

| File | Method | Auth | Purpose |
|---|---|---|---|
| `getNotebook.php` | GET | User | Get notebook for a dashboard |
| `saveNotebook.php` | POST | User | Save notebook content |
| `getNotebookPages.php` | GET | User | Get notebook pages |
| `addNotebookPage.php` | POST | User | Add a notebook page |
| `saveNotebookPage.php` | POST | User | Save a notebook page |
| `deleteNotebookPage.php` | POST | User | Delete a notebook page |

## `db/map/` — Maps

| File | Method | Auth | Purpose |
|---|---|---|---|
| `saveMap.php` | POST | Admin | Save/update a map |
| `deleteMap.php` | POST | Admin | Delete a map |
| `getSvgRenders.php` | GET | Admin | Get available SVG renders |
| `deleteSvgRender.php` | POST | Admin | Delete an SVG render |

## `db/misc/` — Settings & Utilities

| File | Method | Auth | Purpose |
|---|---|---|---|
| `calendarEvents.php` | POST | User | CRUD for calendar events |
| `sendMessage.php` | POST | User | Send a direct message |
| `getHeaderData.php` | GET | User | Get header data (balance, unread count) |
| `getBackgrounds.php` | GET | User | Get available home backgrounds |
| `getAvailableIcons.php` | GET | User | Get available desktop icons |
| `updateHomeSettings.php` | POST | User | Update home page notification settings |
| `updateInboxViewTime.php` | POST | User | Mark inbox as viewed |
| `saveDesktopConfig.php` | POST | User | Save home desktop configuration |
| `saveDashboardSetting.php` | POST | User | Save dashboard settings |
| `changeState.php` | POST | Admin | Change system state variables |
| `deleteLog.php` | POST | Admin | Delete a transaction log entry |

## `db/system_assets/` — System Configuration

| File | Method | Auth | Purpose |
|---|---|---|---|
| `updateParameters.php` | POST | Admin | Update system parameters and condition variables |
| `uploadImage.php` | POST | Admin | Upload a system image (multipart) |

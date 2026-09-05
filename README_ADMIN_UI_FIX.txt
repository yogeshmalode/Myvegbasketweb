MyVegBasket Admin UI - fixed and redesigned

Important deployment note:
1. Upload/extract the project so the `admin` folder and `assets` folder are at the same website root.
2. The admin header now loads its admin stylesheet from /admin/admin.css and the shared stylesheet from ../assets/css/style.css. This avoids the blank/un-styled admin screen caused by an incorrect absolute BASE_URL path.
3. If the browser still shows the old unstyled page, use Ctrl+F5 or clear the site's cached CSS.
4. Inventory, Billing, Wastage and Reports have been redesigned to use the same dashboard visual system while preserving the PDO/transaction logic.

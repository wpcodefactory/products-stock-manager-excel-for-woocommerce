=== Products Stock Manager with Excel for WooCommerce Inventory ===
Contributors: wpcodefactory, anbinder, karzin, omardabbas
Tags: stock, inventory, excel, csv, woocommerce
Requires at least: 6.2
Requires PHP: 8.1
Tested up to: 7.0
Stable tag: 3.0.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Update WooCommerce stock and prices from Excel or CSV: preview every change, undo any update, export with variations, see what is low.
== Description ==

Update the stock and prices of your WooCommerce products from an Excel or CSV file. You see every change before it is saved, and you can undo any update with one click.

= How it works =

1. Download your products as a spreadsheet (variations come as their own rows under their product).
2. Change stock or prices in Excel, Google Sheets, Numbers or LibreOffice. Any column order works.
3. Upload the file. Check the preview: what changes, what needs a look, and which rows were skipped and why.
4. Confirm. Products are saved through WooCommerce, so stock status, sale prices, variable product prices and the shop filters stay right.

= Features =

* .xlsx, .xls and .csv files, columns found by their names in any order (ID, SKU, GTIN, Stock, Qty, Regular price, Sale price, Stock status and common supplier words). Sheets from earlier versions keep working.
* Simple products, variations and external products, found by ID, SKU or GTIN.
* Preview of every change before anything is saved: ready rows, rows to check (big price changes, a sale price above the regular price, stock jumps) and skipped rows with the reason.
* Replace the stock, or add received quantities to it.
* A backup of the old values before every update, a Backups screen and one-click restore.
* A report of every update to download: what changed and why rows were skipped.
* Large files are processed in small batches, so thousands of rows do not hit time limits.
* Export to .xlsx with variations, filters (category, stock, type, name or SKU), low stock rows in amber and out of stock rows in red.
* Low stock list using WooCommerce's low stock levels (store wide or per product), with units sold, days of stock left and a suggested reorder quantity. Works with WooCommerce's order storage (HPOS).
* Shop managers can use every screen.
* AI on WPFactory AI credits (optional, nothing is sent until you connect): match the columns of a supplier file, suggest matches for rows whose codes differ from your SKUs (remembered for the next files), and suggest what to reorder with a reason per product.

= Pro =

[Stock Manager Pro](https://wpfactory.com/item/products-stock-manager-with-excel-for-woocommerce/) adds supplier feeds: pull stock and prices from a supplier's Google Sheet or file link, check them first or update on a schedule, with price markup and rounding, units kept back, rules for products that are not in the file, failure emails, sale dates, backorders, weight, cost and custom field columns, a change history on each product, stock value and a weekly low stock email.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Go to WPFactory > Stock Manager.
3. Download your products on the Export tab, change the file, and upload it on Update stock.

== Frequently Asked Questions ==

= Which columns does my file need? =

One column that finds the product (ID, SKU or GTIN) and one or more columns to update (Stock, Stock status, Regular price, Sale price, Low stock level, Track stock). Other columns are ignored. The plugin shows the columns it found before it checks the file, and you can change any of them.

= What happens to products that are not in my file? =

Nothing. Only the products in the file change.

= Can I undo an update? =

Yes. Every update keeps a backup of the old values (30 days by default). Open Backups and click "Undo this update".

= Why was a stock number not saved? =

WooCommerce only keeps stock numbers for products that track stock. The preview tells you which products do not, and Settings can turn tracking on for them.

= Does it work with variable products? =

Yes. Each variation is its own row, found by its ID or SKU. The variable product's price range and stock status are updated after its variations.

== Screenshots ==

1. Upload a file and see the columns it has.
2. Preview: what changes, what needs a check and what is skipped.
3. Results with the report and one-click undo.
4. Backups of every update.
5. Export with variations and low stock colours.
6. Low stock with sales and reorder quantities.

== External services ==

This plugin can use WPFactory AI (https://ai.wpfactory.com), a service by WPFactory, for its AI features, paid with WPFactory credits. Nothing is sent until a site administrator connects the site on the AI Credits screen with an email address and a 6-digit code sent to it. When connecting, the site address and that email address are sent. After that, the text of each AI request the plugin makes is sent when a user starts it; WPFactory AI passes it to Anthropic, its AI provider, to produce the answer. WPFactory AI keeps only numbers about each request (tokens, credits, time), never its content. Terms: https://wpfactory.com/terms-conditions/#wpfactory-ai Privacy: https://wpfactory.com/privacy-policy/#wpfactory-ai

What Stock Manager sends, only when you click an AI button: for "Match columns with AI", the column names and the first 3 rows of your file; for "Suggest matches with AI", the codes and names of rows that did not match and up to 15 similar products from your store (ID, SKU, name) per row; for "Suggest reorder with AI", product names, stock, low stock levels and weekly units sold. No customer or order details are sent.

== Changelog ==

= Unreleased =
* New: AI on WPFactory AI credits: match columns, suggest matches for rows that did not match, suggest what to reorder.
* New: preview of every change before it is saved, with the reason for every skipped row.
* New: backup before every update, Backups screen and one-click undo.
* New: .xls and .csv files; columns found by name in any order.
* New: variations, external products and GTIN in the free plugin.
* New: add received quantities instead of replacing the stock.
* New: export with variations, filters and low stock colours, built in batches.
* New: low stock list with sales, days left and reorder quantities; works with HPOS.
* Fix: stock status, sale prices, variable product prices and shop filters now follow every update (saved through WooCommerce).
* Fix: a row without an ID or SKU no longer changes the product of the row above.
* Fix: shop managers can use every screen.
* Removed: the email signup box, the deactivation survey, the GO PRO tab and every call to outside servers.
* Text domain is now products-stock-manager-with-excel.

= 3.0.4 - 24/05/2026 =
* Tested up to: 7.0.
* WC tested up to: 10.7.

= 3.0.3 - 16/09/2025 =
* WC tested up to: 10.1.

= 3.0.2 - 10/06/2025 =
* Fix - Fix plugin icon.

= 3.0.1 - 06/06/2025 =
* Dev - Updated plugin icon and banner.

= 3.0.0 - 28/05/2025 =
* Fix - Translation loading fixed.
* Dev - Plugin settings moved to the "WPFactory" menu.
* Dev - "Recommendations" added.
* Dev - Code refactoring and cleanup.
* WC tested up to: 9.8.
* Tested up to: 6.8.

= 2.1 =
* PhpSpreadsheet updated to 3.4.0, requires PHP 8.1, checked woo 9.5 and 6.7.1 WP compatibility.

= 1.8 =
* Fix compatibility with PHP 8.0.6.

= 1.7 =
* Fix sale price update. Add update for modified on post for each change.

= 1.6 =
* Fixed bug updating _price when sale or regular price is set to zero in the excel.

= 1.5 =
* Fixed bug updating sale price.

= 1.4 =
* Remove Sale price by adding 0 to excel cell.
* Fix Setting stock to zero by adding 0 to excel cell.
* Fix updating prices and stock number with values less than zero ( 0 ).

= 1.3 =
* Check compatibility.

= 1.2 =
* Added functionality for replacing stock number with the sum of values from multiple excel rows for the same product.

= 1.1 =
* Fix check whether product exists based on ID or SKU from excel for thrown error.

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 2.1 =
PhpSpreadsheet updated to 3.4.0, requires PHP 8.1, checked woo 9.5 and 6.7.1 WP compatibility.

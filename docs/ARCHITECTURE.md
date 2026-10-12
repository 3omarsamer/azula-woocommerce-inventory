# Architecture

## Plugin bootstrap
`azula-stock-history/azula-stock-history.php` declares version 1.4.2, loads four classes, registers `AZSH_DB::activate` for table creation and initializes the logger/admin integrations after `plugins_loaded` when WooCommerce exists.

## Modules
- `class-azsh-db.php`: indexed audit table, insert and annotation functions.
- `class-azsh-logger.php`: captures prior persisted `_stock`, handles WooCommerce stock-set hooks, inserts changed values and enriches matched events for order reductions/restorations.
- `class-azsh-context.php`: conservative source labels based on REST/admin context; Khazenly detection uses an authenticated user's WooCommerce API-key description.
- `class-azsh-admin.php`: routing and rendering of three screens; inventory queries, filters, export, category suggestions, and explicit write handlers.
- `assets/`: admin CSS and JavaScript interactions.

## Read-only logging versus explicit management edits
The original audit requirement constrained the **logger**, not every future admin capability. Stock and taxonomy edits were later added as separate, manager-initiated operations. They are not automatic inventory reconciliation.

## Write paths
Audit: `AZSH_DB::insert`, `AZSH_DB::annotate` affect only the plugin's history table. Inventory UI: `AZSH_Admin::ajax_update_stock` calls `wc_update_product_stock`. Category UI: `AZSH_Admin::ajax_category_change` calls `wp_set_object_terms` and `wp_remove_object_terms`. WooCommerce product or taxonomy data can therefore be changed **only by those explicit manager UI actions** within the reviewed source.

## Logging lifecycle
1. Before stock persistence, capture previous `_stock` for the product/variation ID.
2. After WooCommerce stock-set action, compare to new quantity.
3. Skip unchanged/null cases; deduplicate identical request-local event keys.
4. Insert SKU, IDs, quantities, delta, timestamp and inferred source/reference.
5. When matching order movement hooks run, update the inserted record's source/reference.

## Limitations
- A before/after hook observes supported WooCommerce stock update paths, not arbitrary direct database writes.
- Request-local deduplication does not imply global exactly-once delivery.
- A Khazenly source label is inferred, not cryptographically asserted.
- Errors in logger code are intentionally swallowed so they do not interrupt order/stock processing; this can also hide gaps in audit coverage.
- The category mapping contains store-specific slugs and numeric category IDs and may require adaptation outside Azula.
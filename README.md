# Azula Stock History & Inventory

**WooCommerce inventory operations and stock auditing plugin — v1.4.2**

A custom WordPress/WooCommerce plugin developed around a real operational problem: the storefront inventory showed quantities for some SKUs while the fulfillment warehouse reported those items unavailable. The first goal was a traceable stock-movement log. As operational needs grew, the project became a consolidated inventory-management workspace for SKU availability, direct quantity updates, movement investigation and category corrections.

> **Portfolio release:** Source code from the supplied v1.4.2 archive. This is a reference implementation, **not** independently certified production-ready. Use a staging store for evaluation.

## The business problem
When WooCommerce stock and warehouse availability disagreed, a current stock number alone could not explain *when* a quantity changed, *from what*, or through *which channel*. An administrator needed searchable historical evidence for SKU-level reconciliation. Staff also wanted to avoid opening WooCommerce product editors and variation settings through multiple clicks just to find stock, check availability, or correct a quantity.

## Why one inventory workspace?

The project addresses **two connected problems**: reconciling stock discrepancies and making daily inventory work faster. In standard WooCommerce, staff repeatedly navigate the Products list and product/variation edit views to see or change stock. The custom **WooCommerce → Inventory** screen combines key information and actions into one admin workspace with three tabs. Staff can quickly search a product or SKU; inspect its photo, price, current quantity, stock/active status; make an **explicit authorized stock update**; open that SKU's movement history; or audit product categorization.

**Design evolution:** The first stock-history logger was observation-only. Later versions deliberately added authorized inventory updates and category edits. The plugin is **not** an automatic stock synchronizer for the warehouse.

## Main features

| Screen | Implemented functionality |
| --- | --- |
| **Stock Lookup** | Search by SKU/product name, browse simple products and variation SKUs, stock/category/active–draft filters, sortable stock/price/value/status/product columns, current stock editing, CSV export, and links to all SKUs/history. |
| **Stock History** | Indexed audit table of old/new quantity and delta, timestamps, product and variation identifiers, SKU, attributed source and reference; filtering by SKU, product name, dates, source, and order/reference. |
| **Category Audit** | Product category suggestions derived from existing categories, names and tags, with explicit manual Add/Remove actions. Suggestions alone do not modify taxonomy. |

### Important read/write boundary
The **history logger** observes stock changes from supported WooCommerce pathways, potentially including authenticated warehouse API calls, orders, admin operations and imports; it does not itself set stock. The full **plugin is not read-only**: an authorized manager can manually change quantities via Stock Lookup (`wc_update_product_stock`) and can manually add/remove categories through Category Audit (`wp_set_object_terms` / `wp_remove_object_terms`). These are deliberate UI actions, not background synchronization.

## Implementation overview
- Dedicated `$wpdb->prefix . 'azula_stock_history'` table with indexes for SKU, product/variation, time, source, and reference.
- WooCommerce before/after stock hooks capture quantity changes; order-related hooks enrich matching rows with an order reference.
- In-request deduplication prevents identical observed events being logged twice in the same request; this is **not** a universal cross-request exactly-once guarantee.
- REST attribution reports Khazenly only when the authenticated WordPress user's API-key description matches Khazenly. This is a heuristic, not independently verified webhook provenance.
- Inventory listing queries the WooCommerce product meta lookup table and paginates at 50 rows.
- Admin actions require `manage_woocommerce` permission and use nonce checks for writes.

## Repository layout
```
azula-stock-history/   # Original v1.4.2 plugin source, preserved
  azula-stock-history.php
  includes/
  assets/
  uninstall.php
docs/                  # Architecture, history, testing, case study, and security notes
```

## Local evaluation
1. Back up a **staging** WordPress installation with WooCommerce enabled.
2. Copy `azula-stock-history/` to `wp-content/plugins/` or ZIP that folder for plugin upload.
3. Activate **Azula Stock History** from WordPress Plugins.
4. Open **WooCommerce → Inventory** to explore Stock Lookup, Stock History, and Category Audit.
5. Test stock updates and category edits only with dummy products and reversible sample data.

**Minimum declared requirements:** PHP 7.4 and WooCommerce 7.0 (in the supplied plugin header). Compatibility across all versions has not been independently verified.

## Validation status
Static source inspection and PHP syntax checks are included in [Testing](docs/TESTING.md). No live store, external warehouse, or full WordPress/WooCommerce integration tests were executed for this public release. The attached screenshots supplied by the project owner show a running UI but do not independently establish backend correctness.

## Screenshots
Store screenshots were **deliberately not included**, because the originals show real SKUs, stock quantities and business catalog information. Create anonymized staging examples before adding visuals publicly. See [Portfolio Presentation](docs/PORTFOLIO.md).

## More detail
[Business Case](docs/BUSINESS_CASE.md) · [Architecture](docs/ARCHITECTURE.md) · [Features](docs/FEATURES.md) · [Development Timeline](docs/DEVELOPMENT_HISTORY.md) · [Challenges](docs/CHALLENGES.md) · [Testing](docs/TESTING.md) · [Security](docs/SECURITY.md) · [Roadmap](docs/ROADMAP.md) · [Portfolio](docs/PORTFOLIO.md)

**Author/organization:** Azula Sportswear. This project is shared for professional portfolio and educational review. No open-source license is granted unless a license file is subsequently added.
# Feature Reference (v1.4.2)

## Stock Lookup — quick inventory management
Designed to reduce repetitive navigation through WooCommerce product/variation editors for routine inventory work.
- SKU and product-name search, 50-row pages.
- Category filter including descendants, category-level stock totals.
- In-stock, out-of-stock, low-stock (1–2), Active/Draft filters.
- Product, current stock, status, price and estimated stock value sorting.
- Variation-level inventory rows; action links to associated SKUs and history.
- Authenticated stock adjustment via a quantity input and Update button.
- CSV export of the filtered inventory selection.

## Stock History
- Past/new stock levels and signed delta.
- Timestamp, product/variation IDs, SKU, source, and order/reference.
- SKU, product name, date or date interval, movement direction, source and reference filters.
- Dedicated indexed audit table preserving records on plugin uninstall.
- History button within WooCommerce variation inventory options.

## Category Audit — guided classification review
Staff can inspect current categories and potential missing or incorrect assignments together, then explicitly choose a category and click Add/Remove.
- Scans existing product taxonomies, titles and tags for classification suggestions.
- Distinguishes gender, type and brand classification using a combination of labels and configured category IDs/slugs.
- Displays current and missing/suggested categories.
- Suggestions only; actual adds/removals require explicit authorized manager actions.

**Note:** Heuristic suggestions are imperfect. Review suggested taxonomy changes before applying them.
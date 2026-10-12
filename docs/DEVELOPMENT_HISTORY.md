# Project Evolution and Provenance

This is a **development narrative**, not a release-by-release verified changelog. The project owner reports releases v1.0.0 through v1.4.2; **only the supplied v1.4.2 source was inspected for this public package**. Earlier archives and commit histories are needed to attribute features/fixes to exact versions.

## Phase 1 — Real warehouse discrepancy

WooCommerce showed stock for products that the Khazenly warehouse reportedly could not fulfill. The requirement was to understand changes for **each SKU**, including those made by warehouse APIs, WooCommerce orders, back-office edits or other supported systems. Before/after quantity evidence was preferred over silent reconciliation.

## Phase 2 — Audit-first engineering

The original design avoided modifying stock at all. Development focused on an indexed history table, variation-specific stock changes, a date/SKU/source search, and avoiding duplicate events across multiple WooCommerce hooks. Attribution was designed to be conservative rather than labeling every unknown operation as the warehouse.

## Phase 3 — Faster Inventory Lookup

The scope grew when the team noticed how many steps the regular WooCommerce Products screen required to check or edit stock, particularly variations. The new **Stock Lookup** screen put product image, name, SKU, quantity, stock status, price and draft/active status in a searchable, sortable, filterable table with SKU history links and CSV export.

The requirement later expanded from viewing stock to **editing quantities directly in that table**. These edits use a distinct, explicit and permission-protected WooCommerce stock-update action. This does **not** contradict the passive logger requirement: it adds an intentional management tool alongside it.

## Phase 4 — Guided Category Audit

A separate category audit view helped locate products possibly missing a category or placed under inconsistent categories. It shows actual category assignments and inferred classification and supports manual Add/Remove decisions. Category guesses require review.

## Phase 5 — Iteration to v1.4.2

The supplied release implements all three views, explicit inventory/category action handlers and the audit table. Development discussions reported ongoing work with category mappings, stock source labels, variation handling and other WooCommerce edge cases. These reports should not be treated as independently validated fixes.

## Follow-up required for a complete historical CHANGELOG

Compare the earlier v1.0.0–v1.4.1 ZIPs and/or tagged Git commits to enumerate introduction dates and exact debugging changes. Avoid assigning a release number to an unverified fix.
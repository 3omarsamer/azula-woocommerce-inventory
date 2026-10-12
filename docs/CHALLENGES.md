# Challenges and Solutions

## Warehouse inventory discrepancies
**Challenge:** WooCommerce's current stock quantity did not explain conflicting fulfillment availability. **Design response:** Indexed movement history by SKU, time and source, with old/new values and deltas. **Limitation:** Physical reconciliation still requires warehouse evidence.

## Duplicate event risk
**Challenge:** Multiple WooCommerce hooks can fire during stock changes. **Implementation:** Capture before/after events, use request-local event keys and annotate matching rows from order hooks instead of inserting a second order row. **Limitation:** Cross-request duplicate protection has not been established.

## Variation versus parent inventory
**Challenge:** A variable product's parent is not the same as an inventory-bearing variant SKU. **Implementation:** Record parent ID and variation ID separately; display variation SKU rows in lookup.

## Source attribution
**Challenge:** A stock change may originate from admin, Woo REST integration, fulfillment, or orders. **Implementation:** Context labels, authenticated user checks, description-based Khazenly classification, order-reference enrichment. **Limitation:** Do not treat inferred source as authoritative integration proof.

## Category misclassification
**Challenge:** Early suggestions could misread pants as shorts or conflate related collections. **Implementation in supplied release:** explicit category name/slug precedence and type-specific suggestion mapping, followed by fallback keywords. **Limitation:** Requires tests with representative products and store-specific category IDs; no full regression suite is supplied.

## Operational safety
**Challenge:** Logging must not prevent purchase/stock updates. **Implementation:** logger catches exceptions around observation and database writes. **Tradeoff:** silent audit failures require separate monitoring to detect missing records.
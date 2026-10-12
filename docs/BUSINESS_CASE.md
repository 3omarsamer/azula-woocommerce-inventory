# Business Case — Inventory Audit to Inventory Management

## 1. Original trigger: mismatched warehouse availability

Azula Sportswear found a discrepancy: WooCommerce reported available quantities for certain SKUs while the fulfillment warehouse (Khazenly) said they were unavailable. Stock values by themselves did not reveal **when**, **by how much** or **through what recorded source** a quantity had changed.

The initial request was a low-interference audit trail for **future** stock events from supported WooCommerce stock paths, including order changes, manual adjustments and API integrations. Each event should capture a timestamp, SKU/product/variation, before/after quantity and delta, with defensible source attribution and any order/reference.

This audit is a diagnostic tool, not an independent physical warehouse stock counter.

## 2. Next problem: stock checks took too many clicks

The standard WooCommerce product editing workflow required repeatedly finding and opening products, and sometimes their variations, just to see availability, stock quantity or SKU details, then editing fields and returning to the product list. This was too slow for repeated inventory support tasks.

The requested solution was **one consolidated admin workspace** where the team could:
- Search by product or SKU and view the photo, name, SKU, quantity, price, availability and active/draft status together.
- Sort/filter products and export selected inventory to CSV.
- Change stock quantities intentionally **in the lookup table** through an explicit Update action.
- Go straight to the movement history for a particular SKU.

The business goal is fewer navigation steps, not a claimed measured time-saving percentage.

## 3. Additional problem: product category accuracy

Catalog taxonomy errors or missing classifications were tedious to inspect across many products. Category Audit was added to display current categories, detect possible gaps through heuristics, and let managers select a category then explicitly click **Add** or **Remove**. It does not automatically repair classifications.

## 4. Evolution and boundaries

1. **Trace** future movements to investigate WooCommerce/warehouse disagreements.
2. **Inspect** inventory and availability quickly in one SKU-focused interface.
3. **Update** quantities deliberately, with access controls and WooCommerce stock APIs.
4. **Audit categories** and apply reviewed category corrections without opening the standard product editor for every item.

The logger stays passive; management controls write only when the manager requests a change. External systems remain responsible for their own synchronization logic. No claim is made that every direct database write is logged or that mismatches are automatically resolved.
# Testing and Validation

## What was checked in preparing this portfolio package
- The uploaded v1.4.2 ZIP was opened and inventoried.
- PHP files were linted for syntax errors using PHP CLI (see packaging report).
- Source inspection identified the three tabs, hooks, audit table, filtering, write handlers, capability and nonce checks.
- Text scanning checked for obvious embedded API credentials/secret strings. This is not a comprehensive security audit.

## What was **not** run
- A full WordPress/WooCommerce integration or regression test suite.
- The plugin installed in a new staging store.
- Real checkout, order refund/restoration, Khazenly integration or inventory updates.
- Independent confirmation of screenshot data or store-specific taxonomy classifications.

## Recommended staging acceptance checklist
- [ ] Activate on a WooCommerce staging copy; verify audit table/indexes exist.
- [ ] Change a simple product stock value and inspect exact old/new/delta.
- [ ] Change a variation's stock and verify parent/variation IDs and SKU.
- [ ] Exercise Woo order reduction and restoration; verify correct references and no duplicate history entries.
- [ ] Update stock through Woo REST and an integration test user; inspect inferred source accurately.
- [ ] Search/filter/paginate/sort stock lookup and history at realistic catalog scale.
- [ ] Verify CSV contents, filters, headers and exported data sanitization.
- [ ] Test manager versus unauthorized account permissions and nonce rejection.
- [ ] Exercise an explicit stock edit; confirm stock changes exactly once and audit catches it.
- [ ] Test Add/Remove category on a dummy product and verify no unintended categories changed.
- [ ] Test men's pants, men's shorts, women's tops, leggings and ambiguous unisex products for correct suggestions.
- [ ] Test errors such as absent API-key table, failed audit insert, disabled WooCommerce, and large catalogs.
- [ ] Validate compatibility with target PHP/WooCommerce versions and HPOS if relevant to environment.
# Roadmap and Possible Improvements

These are **proposals, not claims of completed functionality**.

1. Build deterministic PHPUnit/WordPress/WooCommerce integration tests for order reduction, restoration, variations, API writes and retry behavior.
2. Add explicit test fixtures and a sanitized screenshot gallery.
3. Offer configurable category mapping instead of store-specific numeric IDs and slugs.
4. Introduce optional diagnostic logging/health checks for silent audit write failures.
5. Assess cross-request idempotency and transactional boundaries where feasible.
6. Add export field hardening, including handling spreadsheet formula injection.
7. Benchmark lookup/category-audit performance on large catalogs; consider caching counts.
8. Confirm compatibility against current WooCommerce and PHP supported versions.
9. Include verifiable version-by-version changelog after original archives are provided.
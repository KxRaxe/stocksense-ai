# Future development: multiple branches

StockSense AI manages one location today. The proposal puts multi-branch and
multi-warehouse inventory out of scope, but the data model and code were built
so branches can be added later without migrating data or rewriting the stock
logic. This note says what is already in place and what is left.

## Already in place

| Piece | Where | What it does |
|---|---|---|
| `locations` table | `database/migrations/*_create_locations_table.php` | One row, "Main store", marked `is_default`. A database rule allows only one default. |
| Stock per location | `inventory_levels` (unique on product + location) | Current stock lives here, not on the product row. |
| Location on every movement | `stock_movements.location_id` | Each ledger row says where the stock changed. |
| One place to ask "which location?" | `App\Services\Inventory\LocationContext` | All stock reads and writes call this. Today it always returns the default. |
| One place that changes stock | `App\Services\Inventory\StockService` | Takes an optional location; defaults to `LocationContext`. |
| Feature flag | `config/features.php` (`FEATURE_MULTI_LOCATION`) | Reserved for the location picker and management screens. Off, and currently has no effect. |
| Forecast series keys | ML contract `series_key` (Phase 4) | A series is identified by a key, so "product at branch" can be added later without changing the contract. |

Tests that protect this: `StockServiceTest` (movements are tagged with the default
location; stock at another location stays separate), `LocationContextTest`, and
the product page and inventory tests (they only show the current location's stock).

## Still to build

1. **Manage locations.** Screens to create, rename and deactivate locations, behind
   the feature flag. Choose who may do it (probably Owner only) and add a permission.
2. **Choose the current location.** Teach `LocationContext::current()` to return the
   location the user picked (kept in the session or a user setting) when the flag is
   on, falling back to the default. Add a location picker to the app header.
3. **Restrict users to locations.** A `location_user` table and a check so staff only
   see and change stock at their own branch. Owners see all.
4. **Transfers between branches.** Two linked ledger rows (out of one, into the
   other) in a single transaction, with a `transfer` movement type and a screen to
   record it. `StockMovementType::allows()` needs the new type.
5. **Sales per location.** Add `location_id` to `sales` (Phase 3 creates the table
   with it) and let the import choose a location.
6. **Forecasts per location.** Build one series per product and location, using the
   `series_key`. Decide whether to forecast each branch separately, or the total and
   then split it, since small branches have thin history.
7. **Replenishment per location.** Recommendations are computed per product and
   location (`replenishment_recommendations.location_id` already exists in the plan),
   plus a view across branches.
8. **Reports and dashboard.** Add a location filter and a "all locations" total.

## Things to decide first

- Do branches share a catalog (same SKUs, prices) or keep their own?
- Is there a central warehouse that supplies the branches? That makes lead time depend
  on the source, and changes the replenishment rules.
- Are reorder points and lead times per product, or per product per location?
  Today they are per product.

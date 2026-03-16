# KT Product Category Slugs

**Contributors:** Kontiki Digital  
**Requires WordPress:** 6.4  
**Requires PHP:** 8.1  
**Requires WooCommerce:** 7.0  
**License:** GPL-2.0-or-later  

Removes the `/product-category/` base from WooCommerce product category URLs and exposes the full hierarchy directly. Supports up to three levels of nesting. Fixes permalink generation and resolution for uncategorised products.

---

## How it works

WooCommerce registers the `product_cat` taxonomy with a `/product-category/` base by default, producing URLs like:

```
/product-category/fertilizers/biostimulants/hormones/
```

This plugin removes that base so the full category hierarchy is exposed directly:

```
/fertilizers/biostimulants/hormones/
```

Product URLs with the `/%product_cat%/%product%/` permalink structure are also handled correctly at all category depths:

```
/fertilizers/product-slug/
/fertilizers/biostimulants/product-slug/
/fertilizers/biostimulants/hormones/product-slug/
```

Products with no category assigned are served under a configurable fallback slug (default: `uncategorized`):

```
/uncategorized/product-slug/
```

All other content — pages, posts, custom post types, WooCommerce special pages (shop, cart, checkout, my account) — is left entirely unaffected.

---

## Installation

1. Upload the `kt-product-cat-slugs` folder to `/wp-content/plugins/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

> **Important:** the permalink flush is required after every activation, deactivation, or manual update. The plugin triggers it automatically on activation; for manual updates, visit the Permalinks screen once.

---

## Configuration

### Product permalink structure

For product URLs to work correctly, the WooCommerce product permalink base must include `%product_cat%`. In **WooCommerce → Settings → Products → Permalinks**, set the product permalink base to:

```
/%product_cat%/%product%/
```

### Fallback slug for uncategorised products

The fallback slug used when a product has no category assigned is translatable via the `_x()` context `'product permalink fallback slug'` in the `kt-product-cat-slugs` text domain. The default value is `uncategorized`.

---

## URL resolution logic

For every request the plugin intercepts, resolution follows this order:

1. **WooCommerce special pages** (shop, cart, checkout, my account, terms) — detected immediately from WooCommerce page options and passed directly to WordPress, never touched.
2. **Single-segment URLs** (`/slug/`) — resolved as a root-level `product_cat` term. On no match, passed to WordPress.
3. **Multi-segment URLs** — resolved in this order:
   - Full slug chain as a `product_cat` hierarchy (handles deep category URLs).
   - All-but-last slugs as a category + last slug as a product (handles product URLs).
   - All-but-last as the fallback slug + last as a product (handles uncategorised products).
   - On no match, passed to WordPress (handles pages, posts, CPTs, and 404s).

All category lookups are memoized per request, so each unique slug chain hits the database at most once regardless of how many times it is resolved (menus, breadcrumbs, widgets, etc.).

---

## Compatibility

| Environment | Status |
|---|---|
| WordPress 6.4+ | ✅ Supported |
| WooCommerce 7.0+ | ✅ Supported |
| PHP 8.1+ | ✅ Required |
| WordPress Multisite (subdirectory) | ✅ Supported |
| WordPress Multisite (subdomain / mapped domain) | ✅ Supported |
| WPML | ✅ Compatible |
| Polylang | ✅ Compatible |

---

## Technical notes

### Rewrite rules

The plugin registers rules at `top` priority (evaluated before WordPress defaults) using a single rule per URL segment count, from deepest (4 segments) to shallowest (1 segment). Using one rule per segment count avoids silent pattern collisions that would occur if separate category-only and category+product rules were registered for the same segment depth.

### Permalink generation

Two `post_type_link` hooks handle permalink generation:

- **Priority 9** (before WooCommerce at 10): replaces `%product_cat%` with the fallback slug for products with no category, preventing WooCommerce from doubling the product slug.
- **Priority 20** (after WooCommerce at 10): collapses any `/{slug}/{slug}/` duplication that WooCommerce may still produce for categorised products.

### Caches

All per-request caches are instance properties, reset naturally on each new PHP process. No transients or persistent storage is used.

| Cache | Keyed by | Stores |
|---|---|---|
| `$term_slug_cache` | Slug path string | Resolved term slug or `false` (negative) |
| `$child_term_cache` | `{parent_id}:{slug}` | `WP_Term` or `null` |
| `$ancestor_cache` | Term ID | Ordered slug array root→leaf |
| `$wc_page_paths` | — | Set of WooCommerce page URI paths |
| `$public_post_types` | — | List of queryable post types |

---

## Changelog

### 1.1.0
- Added support for product URLs with 1, 2 and 3 category levels.
- Fixed permalink generation for products with no assigned category.
- Fixed URL resolution for pages, posts, CPTs and WooCommerce special pages intercepted by plugin rewrite rules.
- Consolidated rewrite rules to one per segment count, eliminating silent pattern collisions.
- All per-request caches converted to instance properties.

### 1.0.0
- Initial release. Removes `product_cat` base slug and rewrites term links.

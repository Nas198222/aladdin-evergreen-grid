# Aladdin Evergreen Grid — Technical Documentation

**Version:** 0.2.0
**Plugin slug:** `aladdin-evergreen-grid`
**Block name:** `aladdin-evergreen/content-grid`
**REST namespace:** `aladdin-evergreen/v1`
**License:** GPL-2.0-or-later

---

## Table of Contents

1. [What it is](#what-it-is)
2. [Why it exists](#why-it-exists)
3. [Architecture](#architecture)
4. [Installation](#installation)
5. [Block attributes](#block-attributes)
6. [REST API reference](#rest-api-reference)
7. [PHP filter hooks](#php-filter-hooks)
8. [How items are rendered](#how-items-are-rendered)
9. [Performance characteristics](#performance-characteristics)
10. [Security model](#security-model)
11. [Caching](#caching)
12. [Accessibility](#accessibility)
13. [Internationalization](#internationalization)
14. [Build pipeline](#build-pipeline)
15. [Development workflow](#development-workflow)
16. [Deployment](#deployment)
17. [Compatibility](#compatibility)
18. [Versioning + roadmap](#versioning--roadmap)
19. [Troubleshooting](#troubleshooting)
20. [Coding conventions](#coding-conventions)
21. [Audit history](#audit-history)

---

## What it is

A standalone WordPress plugin that adds a single, configurable Gutenberg block. The block displays **any WordPress post type** as a responsive grid with optional filters, search, and pagination.

It replaces the need for a dozen single-purpose archive plugins (recipe grid, blog grid, locations grid, etc.) with one consistent block.

## Why it exists

Aladdin Mediterranean Cuisine needed a recipe archive page (`/recipe/`) that:
- Looked on-brand (currently uses the bare Hello Elementor theme)
- Had filters by recipe category / diet / time
- Was good for SEO (server-rendered initial content)
- Could be reused for blog posts, locations, FAQs, anything

Rather than build single-use blocks per post type, this plugin abstracts the pattern into one universal block.

It was built as a **standalone plugin** — completely independent of the existing `aladdins-customizations` plugin by Brett Shumaker. Brett's code is not modified.

## Architecture

```
aladdin-evergreen-grid/
├── aladdin-evergreen-grid.php       # Plugin bootstrap (defines constants, registers hooks)
├── uninstall.php                    # Cleanup on plugin deletion
│
├── includes/                        # PHP backend (autoloaded)
│   ├── class-aeg-helpers.php        # Stateless utilities (sanitize, format, query args)
│   ├── class-aeg-rest-endpoint.php  # REST controller (/grid-items, /taxonomies, /terms)
│   └── class-aeg-block-registration.php  # Block registration + SSR + asset enqueue
│
├── blocks/
│   ├── src/                         # Source (committed)
│   │   └── content-grid/
│   │       ├── block.json           # Block manifest (used by register_block_type)
│   │       ├── index.js             # Block entry (registerBlockType)
│   │       ├── edit.js              # Gutenberg editor UI (Inspector controls)
│   │       ├── view.js              # Frontend hydration (vanilla JS, no jQuery)
│   │       ├── style.scss           # Frontend CSS (Aladdin brand tokens)
│   │       └── editor.scss          # Editor-only CSS
│   └── dist/                        # Built output (gitignored — run `npm run build`)
│       └── content-grid/
│           ├── block.json
│           ├── index.js             # Compiled editor JS
│           ├── view.js              # Compiled frontend JS
│           ├── style-index.css      # Compiled frontend CSS
│           └── *.asset.php          # WP dependency manifests
│
├── scripts/
│   └── copy-block-json.js           # Cross-platform asset copy (replaces shell `cp`)
│
├── package.json                     # @wordpress/scripts build chain
├── README.md                        # ADHD-friendly overview
└── DOCS.md                          # This file
```

### Request flow

```
┌────────────────────────────────────────────────────────────────────┐
│ User requests /recipe/                                              │
│                                                                     │
│ 1. WP loads the page that contains the block                       │
│ 2. AEG_Block_Registration::render() fires                          │
│    - Calls AEG_Helpers::build_query_args()                         │
│    - Runs WP_Query, primes thumbnail cache                         │
│    - Outputs HTML: wrapper + search/filters + first page of items  │
│    - Sets data-* attributes (postType, taxonomy, perPage, etc.)    │
│ 3. WP outputs the page with real recipe titles + thumbnails        │
│    (Google + AI crawlers see real content)                         │
│                                                                     │
│ 4. Browser parses HTML, loads view.js                              │
│ 5. view.js sees .aeg-grid elements, instantiates AEG_Grid class    │
│    - Reads data-* attributes for config                            │
│    - Does NOT refetch — PHP already SSR'd the first page           │
│    - Wires search/filter/load-more event handlers                  │
│                                                                     │
│ 6. User types in search → debounced 300ms                          │
│ 7. view.js calls /wp-json/aladdin-evergreen/v1/grid-items?search=… │
│ 8. AbortController cancels any in-flight previous fetch            │
│ 9. PHP returns JSON, view.js replaces the grid items               │
└────────────────────────────────────────────────────────────────────┘
```

## Installation

### Option A — Source clone

```bash
cd wp-content/plugins/
git clone https://github.com/Nas198222/aladdin-evergreen-grid.git
cd aladdin-evergreen-grid
npm install
npm run build
```

Then activate via WP Admin → Plugins.

### Option B — Pre-built zip

If a release zip is available (with `blocks/dist/` included):

1. WP Admin → Plugins → Add New → Upload Plugin
2. Choose the zip → Install Now → Activate

### Option C — SFTP

1. Build locally (`npm install && npm run build`)
2. Exclude `node_modules/`, `blocks/src/`, `scripts/`, `package*.json`, `.git/`
3. SFTP the folder to `/wp-content/plugins/aladdin-evergreen-grid/`
4. Activate via WP-CLI: `wp plugin activate aladdin-evergreen-grid`

## Block attributes

| Attribute | Type | Default | Description |
|---|---|---|---|
| `postType` | `string` | `'wprm_recipe'` | Any registered post type with `public: true` and `show_in_rest: true` |
| `taxonomy` | `string` | `''` | Optional. When set, enables filter buttons. |
| `termIds` | `number[]` | `[]` | Optional. Pre-limit displayed items to specific terms. Capped at 100. |
| `columns` | `number` | `3` | 1–4 |
| `showSearch` | `boolean` | `true` | Show search input |
| `showFilters` | `boolean` | `true` | Show filter buttons (requires `taxonomy`) |
| `perPage` | `number` | `12` | 1–50 |
| `heading` | `string` | `''` | Optional heading above the grid |
| `showLoadMore` | `boolean` | `true` | Show "Load more" pagination button |

## REST API reference

### `GET /wp-json/aladdin-evergreen/v1/grid-items`

**Public — no auth required.**

| Query param | Type | Default | Notes |
|---|---|---|---|
| `post_type` | string | `wprm_recipe` | Must be in the allow-list (public + show_in_rest) |
| `taxonomy` | string | `''` | Optional. Must be public + show_in_rest. |
| `term_ids` | csv int / array | `[]` | Capped at 100 IDs |
| `search` | string | `''` | Capped at 100 chars |
| `orderby` | enum | `date` | One of: `date`, `title`, `menu_order`, `modified` |
| `order` | enum | `DESC` | `ASC` or `DESC` |
| `per_page` | int | `12` | 1–50 |
| `page` | int | `1` | 1–100 |

**Response:**

```json
{
  "items": [
    {
      "id": 36300,
      "title": "Lentil & Butternut Squash Bowl",
      "link": "https://aladdinshouston.com/recipe/lentil-butternut-squash/",
      "thumbnail": {
        "url": "https://…/image-500x500.webp",
        "alt": "Mediterranean lentil bowl",
        "w": 500,
        "h": 500
      },
      "excerpt": "A cozy Mediterranean-style bowl with lentils, quinoa…",
      "meta": {
        "rating": 4.8,
        "time": "1 hr",
        "diet": ["Vegan"],
        "course": "Main"
      }
    }
  ],
  "pagination": {
    "total": 6,
    "total_pages": 1,
    "current": 1,
    "per_page": 12
  }
}
```

**Headers:**
- `X-AEG-Cache: HIT|MISS` — whether the response came from transient cache
- `429 Too Many Requests` — when rate limit is hit (120/IP/minute for anonymous users)

**Errors:**
- `400 aeg_invalid_post_type` — Post type not on allow-list
- `400 aeg_invalid_taxonomy` — Taxonomy not on allow-list for the post type
- `429 aeg_rate_limited` — Rate limit hit

### `GET /wp-json/aladdin-evergreen/v1/taxonomies?post_type=…`

**Editor-only — requires `edit_posts` capability.**

Returns the public + show_in_rest taxonomies registered against the given post type.

### `GET /wp-json/aladdin-evergreen/v1/terms?taxonomy=…`

**Editor-only — requires `edit_posts` capability.**

Returns up to 100 terms in the given taxonomy.

## PHP filter hooks

### `aeg_grid_item` — mutate a single returned item

```php
add_filter( 'aeg_grid_item', function( $item, $post, $post_type ) {
    $item['custom_badge'] = get_post_meta( $post->ID, 'is_new', true ) ? 'NEW' : '';
    return $item;
}, 10, 3 );
```

### `aeg_grid_item_meta` — add custom meta per post type

```php
add_filter( 'aeg_grid_item_meta', function( $meta, $post_id, $post_type ) {
    if ( 'event' === $post_type ) {
        $meta['date_starts'] = get_post_meta( $post_id, 'event_start', true );
        $meta['venue']       = get_post_meta( $post_id, 'event_venue', true );
    }
    return $meta;
}, 10, 3 );
```

### `aeg_grid_query_args` — tune the WP_Query before it runs

```php
add_filter( 'aeg_grid_query_args', function( $args, $params ) {
    if ( 'product' === $params['post_type'] ) {
        $args['meta_query'] = array(
            array( 'key' => '_visibility', 'value' => 'visible' )
        );
    }
    return $args;
}, 10, 2 );
```

### `aeg_allowed_post_types` — extend the allow-list

```php
add_filter( 'aeg_allowed_post_types', function( $allowed ) {
    $allowed[] = 'event';
    return $allowed;
} );
```

### `aeg_allowed_taxonomies` — per-post-type taxonomy override

```php
add_filter( 'aeg_allowed_taxonomies', function( $allowed, $post_type ) {
    if ( 'event' === $post_type ) {
        $allowed[] = 'event_category';
    }
    return $allowed;
}, 10, 2 );
```

### `aeg_excluded_post_types` — block specific types from ever appearing

```php
add_filter( 'aeg_excluded_post_types', function( $excluded ) {
    $excluded[] = 'private_cpt';
    return $excluded;
} );
```

## How items are rendered

### Server-side (PHP)

`AEG_Block_Registration::render()` outputs the wrapper + the first page of real items as static HTML:

```html
<div class="aeg-grid aeg-grid--cols-3"
     data-post-type="wprm_recipe"
     data-taxonomy="wprm_course"
     data-per-page="12">
  <div class="aeg-grid__controls">…search + filter buttons…</div>
  <div class="aeg-grid__items" aria-live="polite">
    <a class="aeg-card" href="/recipe/lentil/">
      <div class="aeg-card__image"><img src="…" loading="eager" fetchpriority="high" /></div>
      <div class="aeg-card__body">…title, excerpt, meta…</div>
    </a>
    <!-- … 11 more cards … -->
  </div>
  <button class="aeg-grid__load-more">Load more</button>
</div>
```

The first two cards have `loading="eager"` + `fetchpriority="high"` for LCP optimization.

### Client-side (JS)

`view.js` instantiates an `AEG_Grid` class per `.aeg-grid` element. It wires:
- Debounced search (300ms)
- Filter buttons with `aria-pressed` updates
- Debounced load-more pagination
- AbortController to cancel stale requests
- Skeleton loaders during in-flight fetches
- Error state with retry button

The frontend does **not** refetch on mount — the server already rendered the first page.

### Per-post-type meta adapters

```js
const renderMeta = ( postType, meta ) => {
  if ( postType === 'wprm_recipe' ) return renderRecipeMeta( meta );  // time, rating, diet
  if ( postType === 'product' )      return renderProductMeta( meta ); // price
  return '';
};
```

Add adapters by editing `view.js` `renderMeta()` or via the `aeg_grid_item_meta` PHP filter.

## Performance characteristics

| Metric | Value |
|---|---|
| Shipped JS (gzipped) | ~3 KB |
| Shipped CSS (gzipped) | ~2 KB |
| Built dist folder | 52 KB |
| Initial fetch on mount | 0 (PHP SSR'd) |
| Refetch on filter/search | 1 (debounced, cancellable) |
| First-page DB queries | 1 WP_Query + 1 thumbnail cache prime |
| Cached response queries | 0 (transient hit) |
| Cache TTL | 5 minutes |
| Cache invalidation | save_post / term changes for allow-listed types |
| Rate limit (anonymous) | 120 req/IP/minute |

## Security model

- **Public REST endpoint** uses an allow-list of `public + show_in_rest` post types only
- **Editor endpoints** require `edit_posts` capability + nonce
- **All inputs sanitized** at endpoint boundary (`sanitize_key`, `sanitize_text_field`, `absint`, custom term ID sanitizer)
- **All outputs escaped** at render boundary (`esc_attr`, `esc_html`, `esc_url`)
- **URL scheme whitelist** on frontend (`^(https?:|mailto:|tel:|\/|#)`)
- **mbstring fallback** so the plugin doesn't fatal on hosts without the extension
- **Rate limiting** on anonymous requests (logged-in users exempt)
- **Page count capped at 100** to prevent deep-pagination DoS
- **Search length capped at 100 chars**
- **Term IDs capped at 100**

## Caching

### Transient cache

Each `/grid-items` response is stored in a transient with key `aeg_grid_{md5(params)}` for 5 minutes.

Invalidated on:
- `save_post` for any allow-listed post type
- `deleted_post` for any allow-listed post type
- `edited_term` / `created_term` / `delete_term` on any public+REST taxonomy

The flush is deferred to the `shutdown` hook so save requests don't block.

### Object cache

When a persistent object cache is available (Redis/Memcached on Kinsta), `wp_cache_flush_group('aeg_grid')` is also called.

### CDN cache (Cloudflare)

Not auto-purged. After plugin updates or block config changes, manually purge:

```bash
curl -X POST "https://api.cloudflare.com/client/v4/zones/{ZONE_ID}/purge_cache" \
  -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"files":["https://aladdinshouston.com/recipe/"]}'
```

## Accessibility

- `aria-live="polite"` on items region — screen readers announce updates
- `aria-busy` toggled during fetches
- `aria-pressed` on filter buttons
- `aria-controls` linking search/filters to items region
- `:focus-visible` styles on cards, filters, load-more button
- `<noscript>` fallback when JS is disabled
- `prefers-reduced-motion` disables shimmer + hover lifts
- Skip-link-friendly markup (does not interfere with theme skip links)

## Internationalization

- All PHP user-facing strings wrapped in `__()` with text domain `aladdin-evergreen-grid`
- JS strings localized via `wp_localize_script` (`window.aegConfig.i18n`)
- WPRM time formatter uses `_n()` for plural forms

To translate, create `languages/aladdin-evergreen-grid-{locale}.po`.

## Build pipeline

```bash
npm install         # Install @wordpress/scripts and deps
npm run build       # Production build — minified, with source maps
npm start           # Watch mode — rebuilds on save
npm run lint:js     # ESLint
npm run lint:css    # Stylelint
npm run format      # Prettier
```

Built output goes to `blocks/dist/content-grid/`. The PHP side reads from there.

## Development workflow

### Adding a new post-type renderer

Edit `blocks/src/content-grid/view.js`:

```js
const renderEventMeta = ( meta ) => {
  const parts = [];
  if ( meta?.date_starts ) parts.push( `<span class="aeg-card__date">${ escapeHtml( meta.date_starts ) }</span>` );
  if ( meta?.venue ) parts.push( `<span class="aeg-card__venue">${ escapeHtml( meta.venue ) }</span>` );
  return parts.join( '' );
};

const renderMeta = ( postType, meta ) => {
  if ( postType === 'event' ) return renderEventMeta( meta );  // ← add this
  if ( postType === 'wprm_recipe' ) return renderRecipeMeta( meta );
  // …
};
```

Then on the PHP side, hook `aeg_grid_item_meta` to include `date_starts` and `venue` for the `event` post type.

Rebuild with `npm run build`.

### Changing brand colors

Edit `blocks/src/content-grid/style.scss`:

```scss
:root {
  --aeg-orange: #E85D20;        // ← your primary
  --aeg-orange-dark: #D03D00;   // ← AA-contrast variant for buttons
  --aeg-peach: #FBE2C5;         // ← warm section bg
  // …
}
```

Rebuild.

## Deployment

### To staging

```bash
# From the plugin folder, after `npm run build`:
rsync -avz --delete \
  --exclude='node_modules/' \
  --exclude='blocks/src/' \
  --exclude='scripts/' \
  --exclude='package*.json' \
  --exclude='.git/' \
  ./ aladdinshoustoncom@34.174.186.154:/www/aladdinshoustoncom_274/public/wp-content/plugins/aladdin-evergreen-grid/

ssh kinsta-aladdin-staging "wp plugin activate aladdin-evergreen-grid"
```

### To production

**Requires explicit YES from Boss.**

Same as staging, but to the prod environment + must purge Cloudflare cache:

```bash
curl -X POST "https://api.cloudflare.com/client/v4/zones/c28d0470731f74590236c4da214fcd0f/purge_cache" \
  -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"purge_everything":false,"files":["https://aladdinshouston.com/recipe/"]}'
```

## Compatibility

| | Tested | Notes |
|---|---|---|
| WordPress | 6.4+ | Uses block.json API v3 |
| PHP | 8.0+ | Type hints, null coalescing |
| WooCommerce | 10.x | Uses `wc_price()` when available |
| WP Recipe Maker | Pro / Free | Reads `wprm_total_time`, `wprm_rating_average`, etc. |
| Elementor | 4.0+ | Can be embedded in Elementor Theme Builder templates |
| Kinsta object cache | Redis | Uses `wp_cache_flush_group()` if available |
| Cloudflare | Pro | Manual purge required after activation |

## Versioning + roadmap

### v0.1.0
- Initial scaffold
- 14 audit findings from Claude

### v0.2.0 (current)
- 21 audit findings applied (Claude + Gemini + GPT-5.5 three-way review)
- SSR first page of items
- Rate limiting, mbstring fallback, AbortController in editor effects
- Object cache flush, scoped invalidation
- WCAG AA contrast, focus-visible, prefers-reduced-motion
- WC price + WPRM time formatters

### Planned v0.3
- PHPUnit + Jest test suites
- Translation .pot file
- Editor `<ServerSideRender>` for live preview
- "Featured term" pinning
- Filter-by-multiple-terms (AND/OR)
- CLI release script

## Troubleshooting

### Block doesn't appear in the editor

Check that `blocks/dist/content-grid/block.json` exists. If missing, run `npm run build`. The plugin shows an admin notice when this is the case.

### Frontend grid shows skeleton forever

Open browser DevTools → Network. Check the `/wp-json/aladdin-evergreen/v1/grid-items` request:
- `400` → invalid post_type or taxonomy (check editor config)
- `429` → rate limit hit (anonymous user only)
- `500` → check PHP error log on Kinsta
- Times out → check if Cloudflare is buffering

### "No items found" but I have published posts

Verify the post type is in the allow-list:

```php
add_filter( 'aeg_allowed_post_types', function( $allowed ) {
    error_log( print_r( $allowed, true ) );
    return $allowed;
} );
```

If your post type isn't listed, it's missing `public: true` or `show_in_rest: true` when registered.

### Cache won't clear

The transient cache flushes automatically on save_post. If you're seeing stale data:

1. WP-CLI: `wp transient delete --all`
2. Object cache: `wp cache flush`
3. Cloudflare: manual purge (see [Caching](#caching))

### Stripe / WPRM data missing from cards

The per-post-type meta adapters in `AEG_Helpers::get_item_meta()` only handle `wprm_recipe` and `product` by default. For anything else, use the `aeg_grid_item_meta` filter.

## Coding conventions

This plugin follows **WordPress Coding Standards (WPCS)**, not PSR-12:

- Tabs (not spaces) for indentation in PHP
- `snake_case` for functions, `PascalCase_Underscore` for classes
- All hooks via static methods on controllers
- Documented with PHPDoc on every function
- No `declare(strict_types=1)` (WP core doesn't use it)

JS follows the WordPress JS style (via `@wordpress/scripts`):
- Tabs
- Single quotes
- Trailing commas
- No semicolons in some contexts (matching @wordpress/scripts ESLint config)

## Audit history

Every release is audited by three AI reviewers independently:

| Version | Reviewer | Findings |
|---|---|---|
| v0.1.0 | Claude (Opus 4.7) | 14 |
| v0.1.0 | Gemini 2.5 Pro | 7 |
| v0.1.0 | GPT-5.5 | 38 |
| **v0.2.0** | **All 21 unique issues from v0.1 applied** | **0 open** |

Audit reports are stored at `~/aladdin-sandbox/audits/`.

---

## Maintainer

Built for [Aladdin Mediterranean Cuisine](https://aladdinshouston.com), Houston, TX.

Issues + PRs: https://github.com/Nas198222/aladdin-evergreen-grid

License: GPL-2.0-or-later

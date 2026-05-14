# Aladdin Evergreen Grid

Universal Gutenberg content grid block for `aladdinshouston.com`. Displays **any post type** — recipes, blog posts, locations, products, FAQs, anything — with filters, search, pagination, and the Aladdin brand look.

Built as a **separate plugin** from Brett Shumaker's `aladdins-customizations` to keep Brett's code 100% untouched.

## Block

`aladdin-evergreen/content-grid`

### Attributes

| Attribute | Default | Notes |
|---|---|---|
| `postType` | `wprm_recipe` | Any registered, viewable post type |
| `taxonomy` | `''` | Optional — enables filter buttons |
| `termIds` | `[]` | Optional — pre-limit to specific terms |
| `columns` | `3` | 1-4 |
| `showSearch` | `true` | Show search input |
| `showFilters` | `true` | Show filter buttons (requires taxonomy) |
| `perPage` | `12` | 1-50 |
| `heading` | `''` | Optional heading above the grid |
| `showLoadMore` | `true` | Show pagination button |

## REST API

Public endpoint (no auth):
```
GET /wp-json/aladdin-evergreen/v1/grid-items
  ?post_type=wprm_recipe
  &taxonomy=wprm_course
  &term_ids=12,34
  &search=hummus
  &orderby=date  (date|title|menu_order|rand|modified)
  &order=DESC    (ASC|DESC)
  &per_page=12   (1-50)
  &page=1
```

Editor-only endpoints (require `edit_posts`):
- `GET /wp-json/aladdin-evergreen/v1/taxonomies?post_type={slug}`
- `GET /wp-json/aladdin-evergreen/v1/terms?taxonomy={slug}`

Responses are cached as transients for 5 minutes (key prefix `aeg_grid_`).

## PHP filters (extensibility)

```php
// Mutate a single item before it's returned.
add_filter( 'aeg_grid_item', function( $item, $post, $post_type ) {
    return $item;
}, 10, 3 );

// Add custom meta for any post type.
add_filter( 'aeg_grid_item_meta', function( $meta, $post_id, $post_type ) {
    if ( 'event' === $post_type ) {
        $meta['date_starts'] = get_post_meta( $post_id, 'event_start', true );
    }
    return $meta;
}, 10, 3 );

// Tune WP_Query args before the grid query.
add_filter( 'aeg_grid_query_args', function( $args, $params ) {
    return $args;
}, 10, 2 );
```

## Build

```bash
npm install
npm run build       # production
npm start           # watch mode
```

## Install

1. `npm run build`
2. Zip the plugin directory (excluding `node_modules/`)
3. Upload via WP Admin → Plugins → Add New → Upload
4. Or SFTP the folder to `/wp-content/plugins/`

## License

GPL-2.0-or-later

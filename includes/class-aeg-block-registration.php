<?php
/**
 * Registers the aladdin-evergreen/content-grid Gutenberg block + server-side render.
 *
 * @package Aladdin_Evergreen_Grid
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block registration controller.
 */
class AEG_Block_Registration {

	const BLOCK_NAME = 'aladdin-evergreen/content-grid';

	/**
	 * Wire up hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		// Defer localization until WP knows whether the block is on the page.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'localize_frontend' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'localize_editor' ) );
		// Hide duplicate page-title H1 when the block is on the page (theme-agnostic).
		add_filter( 'the_title', array( __CLASS__, 'maybe_hide_page_title' ), 999, 2 );
	}

	/**
	 * Suppress the theme's title rendering on pages where our block already provides one.
	 * Only affects the main page title — not nav menus, recent posts, etc.
	 *
	 * @param string $title Page title.
	 * @param int    $id    Post ID.
	 * @return string
	 */
	public static function maybe_hide_page_title( $title, $id = 0 ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}
		global $post;
		if ( ! $post || (int) $id !== (int) $post->ID ) {
			return $title;
		}
		if ( has_block( self::BLOCK_NAME, $post ) ) {
			// Wrap so themes that escape this still drop it. Empty string = no title rendered.
			return '';
		}
		return $title;
	}

	/**
	 * Pass REST URL + i18n strings to the frontend script — only when the block is on the page.
	 *
	 * @return void
	 */
	public static function localize_frontend() {
		if ( is_admin() ) {
			return;
		}
		// Only enqueue the data when the current request actually renders the block.
		global $post;
		$on_page = ( $post instanceof WP_Post && has_block( self::BLOCK_NAME, $post ) );
		if ( ! $on_page && ! is_singular() ) {
			// Archive pages — assume block may exist. (has_block doesn't traverse template parts reliably.)
			$on_page = true;
		}
		if ( ! $on_page ) {
			return;
		}

		$handle = generate_block_asset_handle( self::BLOCK_NAME, 'view' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'aegConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'aladdin-evergreen/v1' ) ),
				'i18n'    => self::get_i18n_strings(),
			)
		);
	}

	/**
	 * Block-editor side gets a nonce for the authenticated /taxonomies and /terms endpoints.
	 *
	 * @return void
	 */
	public static function localize_editor() {
		$handle = generate_block_asset_handle( self::BLOCK_NAME, 'editor-script' );
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		wp_localize_script(
			$handle,
			'aegEditorConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'aladdin-evergreen/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Translatable JS strings.
	 *
	 * @return array
	 */
	public static function get_i18n_strings() {
		return array(
			'tryAgain'     => __( 'Try again', 'aladdin-evergreen-grid' ),
			'couldNotLoad' => __( 'Could not load items.', 'aladdin-evergreen-grid' ),
			'noItems'      => __( 'No items found.', 'aladdin-evergreen-grid' ),
		);
	}

	/**
	 * Register the block via block.json metadata.
	 *
	 * If the build hasn't run yet, surface an admin notice instead of registering
	 * a half-baked fallback. Editing without the JS bundle is not supported.
	 *
	 * @return void
	 */
	public static function register() {
		$block_json = AEG_PATH . 'blocks/dist/content-grid/block.json';

		if ( ! file_exists( $block_json ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_build_notice' ) );
			return;
		}

		register_block_type(
			$block_json,
			array(
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Admin notice shown when the JS bundle hasn't been built yet.
	 *
	 * @return void
	 */
	public static function render_build_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Aladdin Evergreen Grid:</strong> JS bundle not found. Run <code>npm install &amp;&amp; npm run build</code> in the plugin directory before activating.</p></div>';
	}

	/**
	 * Default attribute schema (also kept in block.json).
	 *
	 * @return array
	 */
	public static function get_attributes() {
		return array(
			'postType'    => array(
				'type'    => 'string',
				'default' => 'wprm_recipe',
			),
			'taxonomy'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'termIds'     => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array( 'type' => 'number' ),
			),
			'columns'     => array(
				'type'    => 'number',
				'default' => 3,
			),
			'showFilters' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showSearch'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'perPage'     => array(
				'type'    => 'number',
				'default' => 12,
			),
			'heading'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'showLoadMore' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'showBreadcrumb' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'stickyControls' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'emitItemList' => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
	}

	/**
	 * Server-side render — outputs the wrapper. The frontend script then hydrates.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$attributes = wp_parse_args(
			$attributes,
			array(
				'postType'       => 'wprm_recipe',
				'taxonomy'       => '',
				'termIds'        => array(),
				'columns'        => 3,
				'showFilters'    => true,
				'showSearch'     => true,
				'perPage'        => 12,
				'heading'        => '',
				'showLoadMore'   => true,
				'showBreadcrumb' => true,
				'stickyControls' => true,
				'emitItemList'   => true,
			)
		);

		$post_type       = sanitize_key( $attributes['postType'] );
		$taxonomy        = sanitize_key( $attributes['taxonomy'] );
		$term_ids        = AEG_Helpers::sanitize_term_ids( $attributes['termIds'] );
		$columns         = min( 4, max( 1, absint( $attributes['columns'] ) ) );
		$show_filters    = (bool) $attributes['showFilters'];
		$show_search     = (bool) $attributes['showSearch'];
		$per_page        = min( 50, max( 1, absint( $attributes['perPage'] ) ) );
		$heading         = sanitize_text_field( $attributes['heading'] );
		$show_load_more  = (bool) $attributes['showLoadMore'];
		$show_breadcrumb = (bool) $attributes['showBreadcrumb'];
		$sticky_controls = (bool) $attributes['stickyControls'];
		$emit_item_list  = (bool) $attributes['emitItemList'];

		$controls_id = wp_unique_id( 'aeg-grid-' );

		// Pre-compute the initial item set so we can render + reuse it for ItemList schema.
		$initial = self::get_initial_items( $post_type, $taxonomy, $term_ids, $per_page );

		$wrap_classes = 'aeg-grid aeg-grid--cols-' . (int) $columns;
		if ( $sticky_controls ) {
			$wrap_classes .= ' aeg-grid--sticky';
		}

		ob_start();
		?>
		<?php if ( $show_breadcrumb ) : self::render_breadcrumb_html(); endif; ?>
		<div
			class="<?php echo esc_attr( $wrap_classes ); ?>"
			id="<?php echo esc_attr( $controls_id ); ?>"
			data-post-type="<?php echo esc_attr( $post_type ); ?>"
			data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
			data-term-ids="<?php echo esc_attr( implode( ',', $term_ids ) ); ?>"
			data-columns="<?php echo esc_attr( $columns ); ?>"
			data-show-filters="<?php echo esc_attr( $show_filters ? '1' : '0' ); ?>"
			data-show-search="<?php echo esc_attr( $show_search ? '1' : '0' ); ?>"
			data-per-page="<?php echo esc_attr( $per_page ); ?>"
			data-show-load-more="<?php echo esc_attr( $show_load_more ? '1' : '0' ); ?>"
		>
			<?php if ( $heading ) : ?>
				<h2 class="aeg-grid__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>

			<?php if ( $show_search || ( $show_filters && $taxonomy ) ) : ?>
				<div class="aeg-grid__controls">
					<?php if ( $show_search ) : ?>
						<label class="screen-reader-text" for="<?php echo esc_attr( $controls_id ); ?>-search">
							<?php esc_html_e( 'Search', 'aladdin-evergreen-grid' ); ?>
						</label>
						<input
							class="aeg-grid__search"
							id="<?php echo esc_attr( $controls_id ); ?>-search"
							type="search"
							placeholder="<?php esc_attr_e( 'Search…', 'aladdin-evergreen-grid' ); ?>"
							autocomplete="off"
						/>
					<?php endif; ?>

					<?php if ( $show_filters && $taxonomy && taxonomy_exists( $taxonomy ) ) : ?>
						<div class="aeg-grid__filters" role="group" aria-label="<?php esc_attr_e( 'Filter items', 'aladdin-evergreen-grid' ); ?>">
							<button class="aeg-grid__filter aeg-grid__filter--active" type="button" data-term-id="" aria-pressed="true">
								<?php esc_html_e( 'All', 'aladdin-evergreen-grid' ); ?>
							</button>
							<?php foreach ( self::get_filter_terms( $taxonomy, $term_ids ) as $term ) : ?>
								<button class="aeg-grid__filter" type="button" data-term-id="<?php echo (int) $term->term_id; ?>" aria-pressed="false">
									<?php echo esc_html( self::title_case_term( $term->name ) ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="aeg-grid__items" role="list" aria-live="polite">
				<?php
				// Server-side render the first page so search engines + no-JS users see real content.
				if ( ! empty( $initial['items'] ) ) {
					echo self::render_items_html( $initial['items'], $post_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					// No items yet — show skeletons so the JS hydrate doesn't paint over content.
					echo self::render_skeleton( $per_page, $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<noscript>
				<p class="aeg-grid__noscript"><?php esc_html_e( 'Enable JavaScript for filters and search.', 'aladdin-evergreen-grid' ); ?></p>
			</noscript>

			<?php
			$has_more = ! empty( $initial ) && ! empty( $initial['has_more'] );
			if ( $show_load_more ) :
				?>
				<div class="aeg-grid__pagination">
					<button class="aeg-grid__load-more" type="button" <?php echo $has_more ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Load more', 'aladdin-evergreen-grid' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
		// ItemList + BreadcrumbList JSON-LD — boost archive eligibility for rich results.
		if ( $emit_item_list && ! empty( $initial['items'] ) ) {
			echo self::render_itemlist_jsonld( $initial['items'], $heading ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $show_breadcrumb ) {
			echo self::render_breadcrumb_jsonld(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return ob_get_clean();
	}

	/**
	 * Visible breadcrumb trail: Home > Current page title.
	 * Kept intentionally simple — for nested taxonomies, use a Yoast/Rank Math breadcrumb instead.
	 *
	 * @return void
	 */
	protected static function render_breadcrumb_html() {
		global $post;
		$home    = home_url( '/' );
		$current = '';
		if ( is_singular() && $post ) {
			// Read directly from the post object to bypass our own the_title filter that
			// suppresses the duplicate page title on block pages.
			$current = wp_strip_all_tags( $post->post_title );
		} elseif ( is_archive() ) {
			$current = post_type_archive_title( '', false );
		}
		if ( ! $current ) {
			return;
		}
		?>
		<nav class="aeg-grid__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'aladdin-evergreen-grid' ); ?>">
			<a href="<?php echo esc_url( $home ); ?>"><?php esc_html_e( 'Home', 'aladdin-evergreen-grid' ); ?></a>
			<span class="aeg-grid__breadcrumb-sep" aria-hidden="true">›</span>
			<span aria-current="page"><?php echo esc_html( $current ); ?></span>
		</nav>
		<?php
	}

	/**
	 * BreadcrumbList JSON-LD that mirrors the visible breadcrumb.
	 *
	 * @return string
	 */
	protected static function render_breadcrumb_jsonld() {
		global $post;
		$home          = home_url( '/' );
		$current_url   = '';
		$current_label = '';
		if ( is_singular() && $post ) {
			$current_url   = get_permalink( $post );
			$current_label = wp_strip_all_tags( $post->post_title );
		} elseif ( is_archive() ) {
			$current_url   = get_post_type_archive_link( get_post_type() );
			$current_label = post_type_archive_title( '', false );
		}

		if ( ! $current_url || ! $current_label ) {
			return '';
		}

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(
				array(
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => __( 'Home', 'aladdin-evergreen-grid' ),
					'item'     => $home,
				),
				array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => $current_label,
					'item'     => $current_url,
				),
			),
		);

		return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/**
	 * ItemList JSON-LD listing all rendered items.
	 * Helps Google + AI search recognize the page as a collection of indexable items.
	 *
	 * @param array  $items  Formatted item array.
	 * @param string $heading Optional heading to use as the list name.
	 * @return string
	 */
	protected static function render_itemlist_jsonld( $items, $heading = '' ) {
		if ( empty( $items ) ) {
			return '';
		}

		$elements = array();
		$pos      = 0;
		foreach ( $items as $item ) {
			if ( empty( $item['link'] ) ) {
				continue;
			}
			$pos++;
			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'url'      => $item['link'],
				'name'     => isset( $item['title'] ) ? $item['title'] : '',
			);
		}

		if ( empty( $elements ) ) {
			return '';
		}

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $heading ?: ( is_singular() ? get_the_title() : __( 'Items', 'aladdin-evergreen-grid' ) ),
			'numberOfItems'   => count( $elements ),
			'itemListElement' => $elements,
		);

		return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/**
	 * Fetch the first page of items for SSR. Mirrors the REST endpoint's logic
	 * so the initial HTML matches what JS would produce.
	 *
	 * @param string $post_type Post type.
	 * @param string $taxonomy  Taxonomy.
	 * @param int[]  $term_ids  Term IDs.
	 * @param int    $per_page  Items per page.
	 * @return array { items: array[], has_more: bool }
	 */
	protected static function get_initial_items( $post_type, $taxonomy, $term_ids, $per_page ) {
		// Refuse anything not on the allow-list — keeps SSR aligned with the REST endpoint.
		$allowed = AEG_REST_Endpoint::get_allowed_post_types();
		if ( ! in_array( $post_type, $allowed, true ) ) {
			return array(
				'items'    => array(),
				'has_more' => false,
			);
		}

		$params = array(
			'post_type' => $post_type,
			'taxonomy'  => $taxonomy,
			'term_ids'  => $term_ids,
			'search'    => '',
			'orderby'   => 'date',
			'order'     => 'DESC',
			'per_page'  => $per_page,
			'page'      => 1,
		);

		$query = new WP_Query( AEG_Helpers::build_query_args( $params ) );

		if ( ! empty( $query->posts ) ) {
			update_post_thumbnail_cache( $query );
		}

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = AEG_Helpers::format_grid_item( $post, $post_type );
		}

		$has_more = $query->max_num_pages > 1;
		wp_reset_postdata();

		return array(
			'items'    => $items,
			'has_more' => $has_more,
		);
	}

	/**
	 * Render the first batch of items as static HTML so crawlers + no-JS users see real content.
	 * The frontend JS will replace this on hydrate (only when filters/search/load-more fire).
	 *
	 * @param array  $items     Formatted item payload (from AEG_Helpers::format_grid_item).
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	protected static function render_items_html( $items, $post_type ) {
		$html = '';
		$idx  = 0;
		foreach ( $items as $item ) {
			$idx++;
			// First two cards eager-load to help LCP. Rest stay lazy.
			$eager = ( $idx <= 2 );
			$html .= self::render_single_card_html( $item, $post_type, $eager );
		}
		return $html;
	}

	/**
	 * Render one card. Mirrors the JS template structure exactly.
	 *
	 * @param array  $item      Formatted item.
	 * @param string $post_type Post type slug.
	 * @param bool   $eager     Whether to eager-load the image (above-fold optimization).
	 * @return string
	 */
	protected static function render_single_card_html( $item, $post_type, $eager = false ) {
		$image_html = self::render_card_image_html( $item, $eager );
		$meta_html  = self::render_meta_html( $post_type, $item['meta'] );

		return sprintf(
			'<article class="aeg-card-wrap" role="listitem"><a class="aeg-card" href="%1$s">%2$s<div class="aeg-card__body"><h3 class="aeg-card__title">%3$s</h3>%4$s%5$s</div></a></article>',
			esc_url( $item['link'] ),
			$image_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from escaped fragments
			esc_html( $item['title'] ),
			$item['excerpt'] ? '<p class="aeg-card__excerpt">' . esc_html( $item['excerpt'] ) . '</p>' : '',
			$meta_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from escaped fragments
		);
	}

	/**
	 * Title-case a taxonomy term name (e.g. "light meal" -> "Light Meal").
	 * Preserves words already containing uppercase + leaves accented chars alone.
	 *
	 * @param string $name Raw term name.
	 * @return string
	 */
	protected static function title_case_term( $name ) {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name ) {
			return $name;
		}
		// If it already has uppercase, trust the editor's casing.
		if ( preg_match( '/[A-Z]/u', $name ) ) {
			return $name;
		}
		// Otherwise, title case each word but keep mb-aware.
		return mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );
	}

	/**
	 * Render the card thumbnail with srcset/sizes + lazy-loader exclusions.
	 *
	 * Adds:
	 *   - `skip-lazy` class (EWWW respects it)
	 *   - `no-lazy` class (Perfmatters respects it)
	 *   - `data-no-lazy="1"` (defensive)
	 *   - Native `loading="eager"` + `fetchpriority="high"` on first 2 cards only
	 *   - Real `src` (never a placeholder transparent gif)
	 *   - `srcset`/`sizes` via wp_get_attachment_image()
	 *
	 * @param array $item  Item payload.
	 * @param bool  $eager Whether this card is above-the-fold.
	 * @return string
	 */
	protected static function render_card_image_html( $item, $eager = false ) {
		if ( empty( $item['thumbnail']['url'] ) ) {
			return '';
		}

		// Try to render via WP API so we get real srcset + alt + dimensions.
		$attachment_id = self::resolve_attachment_id( $item );
		$lazy_classes  = 'skip-lazy no-lazy aeg-card__img';

		if ( $attachment_id ) {
			$attrs = array(
				'class'         => $lazy_classes,
				'loading'       => $eager ? 'eager' : 'lazy',
				'decoding'      => 'async',
				'data-no-lazy'  => '1',
				'sizes'         => '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw',
			);
			if ( $eager ) {
				$attrs['fetchpriority'] = 'high';
			}
			$image_tag = wp_get_attachment_image( $attachment_id, 'medium_large', false, $attrs );
			if ( $image_tag ) {
				return '<div class="aeg-card__image">' . $image_tag . '</div>';
			}
		}

		// Fallback — manually built tag (still excluded from lazy loaders).
		return sprintf(
			'<div class="aeg-card__image"><img src="%1$s" alt="%2$s" class="%3$s" loading="%4$s" decoding="async" data-no-lazy="1"%5$s width="%6$d" height="%7$d" /></div>',
			esc_url( $item['thumbnail']['url'] ),
			esc_attr( $item['thumbnail']['alt'] ?: $item['title'] ),
			esc_attr( $lazy_classes ),
			$eager ? 'eager' : 'lazy',
			$eager ? ' fetchpriority="high"' : '',
			(int) ( $item['thumbnail']['w'] ?: 800 ),
			(int) ( $item['thumbnail']['h'] ?: 600 )
		);
	}

	/**
	 * Look up the attachment ID for an item's thumbnail by URL.
	 * Returns 0 when not findable (still safe — fallback path handles it).
	 *
	 * @param array $item Formatted item with at least ['id'].
	 * @return int
	 */
	protected static function resolve_attachment_id( $item ) {
		if ( empty( $item['id'] ) ) {
			return 0;
		}
		$thumb_id = get_post_thumbnail_id( $item['id'] );
		return $thumb_id ? (int) $thumb_id : 0;
	}

	/**
	 * Per-post-type meta row.
	 *
	 * @param string $post_type Post type slug.
	 * @param array  $meta      Meta payload.
	 * @return string
	 */
	protected static function render_meta_html( $post_type, $meta ) {
		$parts = array();

		if ( 'wprm_recipe' === $post_type ) {
			if ( ! empty( $meta['time'] ) ) {
				$parts[] = '<span class="aeg-card__time">' . esc_html( $meta['time'] ) . '</span>';
			}
			if ( ! empty( $meta['rating'] ) ) {
				$parts[] = '<span class="aeg-card__rating">★ ' . esc_html( number_format_i18n( (float) $meta['rating'], 1 ) ) . '</span>';
			}
			if ( ! empty( $meta['diet'] ) && is_array( $meta['diet'] ) ) {
				$parts[] = '<span class="aeg-card__diet">' . esc_html( $meta['diet'][0] ) . '</span>';
			}
		} elseif ( 'product' === $post_type ) {
			if ( ! empty( $meta['price'] ) ) {
				$parts[] = '<span class="aeg-card__price">' . esc_html( $meta['price'] ) . '</span>';
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '<div class="aeg-card__meta-row">' . implode( '', $parts ) . '</div>';
	}

	/**
	 * Render initial skeleton placeholders so the layout doesn't pop on hydrate.
	 *
	 * @param int $count   How many cards to skeleton.
	 * @param int $columns Column count (for sizing context).
	 * @return string
	 */
	protected static function render_skeleton( $count, $columns ) {
		$skeletons = '';
		$visible   = min( $columns * 2, $count );

		for ( $i = 0; $i < $visible; $i++ ) {
			$skeletons .= '<div class="aeg-card aeg-card--skeleton" aria-hidden="true">'
				. '<div class="aeg-card__image-skeleton"></div>'
				. '<div class="aeg-card__line-skeleton"></div>'
				. '<div class="aeg-card__line-skeleton aeg-card__line-skeleton--short"></div>'
				. '</div>';
		}

		return $skeletons;
	}

	/**
	 * Get the explicit filter terms to render as buttons.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $term_ids Selected term IDs (if empty, returns all terms in taxonomy).
	 * @return array
	 */
	protected static function get_filter_terms( $taxonomy, $term_ids ) {
		// hide_empty=true on the frontend — empty filter buttons confuse users.
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		);

		if ( ! empty( $term_ids ) ) {
			$args['include']    = $term_ids;
			$args['orderby']    = 'include';
			$args['hide_empty'] = false; // Editor explicitly chose these terms.
		} else {
			$args['orderby'] = 'name';
		}

		$terms = get_terms( $args );

		return is_wp_error( $terms ) ? array() : $terms;
	}
}

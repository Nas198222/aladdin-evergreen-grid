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
		// G12: Enqueue Playfair Display via fonts.bunny.net (CSP-allowed) + preconnect.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_fonts' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_fonts' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'resource_hints' ), 10, 2 );
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

		// G3: WP expects block.json field names — 'viewScript' / 'editorScript', not 'view' / 'editor-script'.
		$handle = generate_block_asset_handle( self::BLOCK_NAME, 'viewScript' );
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
	 * G12: Enqueue Playfair Display from fonts.bunny.net (privacy-friendly + CSP-allowed by Aladdin).
	 *
	 * @return void
	 */
	public static function enqueue_fonts() {
		wp_enqueue_style(
			'aeg-playfair',
			'https://fonts.bunny.net/css?family=playfair-display:700,700i&display=swap',
			array(),
			null
		);
	}

	/**
	 * G12: Preconnect hints for the font CDN.
	 *
	 * @param array  $urls          Resource hints array.
	 * @param string $relation_type Hint type.
	 * @return array
	 */
	public static function resource_hints( $urls, $relation_type ) {
		if ( 'preconnect' === $relation_type ) {
			$urls[] = array(
				'href'        => 'https://fonts.bunny.net',
				'crossorigin' => 'anonymous',
			);
		}
		return $urls;
	}

	/**
	 * Block-editor side gets a nonce for the authenticated /taxonomies and /terms endpoints.
	 *
	 * @return void
	 */
	public static function localize_editor() {
		$handle = generate_block_asset_handle( self::BLOCK_NAME, 'editorScript' );
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
			'eyebrow'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'tagline'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'featuredFirst' => array(
				'type'    => 'boolean',
				'default' => true,
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
				'eyebrow'        => '',
				'tagline'        => '',
				'featuredFirst'  => true,
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
		$eyebrow         = sanitize_text_field( $attributes['eyebrow'] );
		$tagline         = sanitize_text_field( $attributes['tagline'] );
		$featured_first  = (bool) $attributes['featuredFirst'];

		$controls_id = wp_unique_id( 'aeg-grid-' );

		// Pre-compute the initial item set so we can render + reuse it for ItemList schema.
		$initial = self::get_initial_items( $post_type, $taxonomy, $term_ids, $per_page );

		$wrap_classes = 'aeg-grid aeg-grid--cols-' . (int) $columns;
		if ( $sticky_controls ) {
			$wrap_classes .= ' aeg-grid--sticky';
		}
		if ( $featured_first ) {
			$wrap_classes .= ' aeg-grid--featured';
		}

		// G5: Merge in block supports (align, spacing, color, custom class) via get_block_wrapper_attributes.
		$wrapper_attrs = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes(
				array(
					'class'           => $wrap_classes,
					'data-post-type'  => $post_type,
					'data-taxonomy'   => $taxonomy,
					'data-term-ids'   => implode( ',', $term_ids ),
					'data-columns'    => $columns,
					'data-show-filters' => $show_filters ? '1' : '0',
					'data-show-search'  => $show_search ? '1' : '0',
					'data-per-page'   => $per_page,
					'data-show-load-more' => $show_load_more ? '1' : '0',
					'data-aeg-version' => AEG_VERSION, // C-L4
				)
			)
			: sprintf(
				'class="%s" data-post-type="%s" data-taxonomy="%s" data-term-ids="%s" data-columns="%d" data-show-filters="%s" data-show-search="%s" data-per-page="%d" data-show-load-more="%s" data-aeg-version="%s"',
				esc_attr( $wrap_classes ),
				esc_attr( $post_type ),
				esc_attr( $taxonomy ),
				esc_attr( implode( ',', $term_ids ) ),
				(int) $columns,
				esc_attr( $show_filters ? '1' : '0' ),
				esc_attr( $show_search ? '1' : '0' ),
				(int) $per_page,
				esc_attr( $show_load_more ? '1' : '0' ),
				esc_attr( AEG_VERSION )
			);

		ob_start();
		?>
		<?php if ( $show_breadcrumb ) : self::render_breadcrumb_html(); endif; ?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped above ?> id="<?php echo esc_attr( $controls_id ); ?>">
			<?php
			// #6: Only render dingbat when ≥2 hero elements present (no noise on minimal hero).
			$hero_count = (int) ! empty( $eyebrow ) + (int) ! empty( $heading ) + (int) ! empty( $tagline );
			if ( $hero_count > 0 ) :
				?>
				<header class="aeg-grid__hero">
					<?php if ( $eyebrow ) : ?>
						<div class="aeg-grid__eyebrow"><span class="aeg-grid__eyebrow-dot" aria-hidden="true">●</span> <?php echo esc_html( $eyebrow ); ?></div>
					<?php endif; ?>
					<?php if ( $heading ) : ?>
						<h2 class="aeg-grid__heading"><?php echo esc_html( $heading ); ?></h2>
					<?php endif; ?>
					<?php if ( $tagline ) : ?>
						<p class="aeg-grid__tagline"><?php echo esc_html( $tagline ); ?></p>
					<?php endif; ?>
					<?php if ( $hero_count >= 2 ) : ?>
						<svg class="aeg-grid__dingbat" aria-hidden="true" viewBox="0 0 40 8" width="40" height="8">
							<circle cx="4" cy="4" r="2" fill="currentColor"/>
							<line x1="10" y1="4" x2="30" y2="4" stroke="currentColor" stroke-width="1.5"/>
							<circle cx="36" cy="4" r="2" fill="currentColor"/>
						</svg>
					<?php endif; ?>
				</header>
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
				// G7: Server-side render. If empty, show a real empty state — NOT permanent skeletons,
				// because the JS doesn't auto-fetch when SSR already shipped content.
				if ( ! empty( $initial['items'] ) ) {
					echo self::render_items_html( $initial['items'], $post_type, $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					?>
					<p class="aeg-grid__empty"><?php esc_html_e( 'No items found.', 'aladdin-evergreen-grid' ); ?></p>
					<?php
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
		// C-L3: Skip BreadcrumbList JSON-LD when Rank Math or Yoast is active — they already emit one.
		if ( $show_breadcrumb && ! self::seo_plugin_handles_breadcrumb() ) {
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

		// G2: JSON_HEX_* flags escape </script>, ampersands, quotes — prevents JSON-LD breakout.
		return '<script type="application/ld+json">'
			. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
			. '</script>';
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

		// C-L1: Fall back to raw post_title to bypass our own the_title filter that returns ''.
		global $post;
		$fallback_name = $heading;
		if ( ! $fallback_name && is_singular() && $post instanceof WP_Post ) {
			$fallback_name = wp_strip_all_tags( $post->post_title );
		}
		if ( ! $fallback_name && is_archive() ) {
			$fallback_name = post_type_archive_title( '', false );
		}
		if ( ! $fallback_name ) {
			$fallback_name = __( 'Items', 'aladdin-evergreen-grid' );
		}

		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $fallback_name,
			'numberOfItems'   => count( $elements ),
			'itemListElement' => $elements,
		);

		// G2: hardened encoding for ItemList JSON-LD.
		return '<script type="application/ld+json">'
			. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
			. '</script>';
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

		// G18: Validate taxonomy is allowed for this post type before querying.
		if ( $taxonomy ) {
			$allowed_tax = AEG_REST_Endpoint::get_allowed_taxonomies_for( $post_type );
			if ( ! in_array( $taxonomy, $allowed_tax, true ) ) {
				$taxonomy = '';
				$term_ids = array();
			}
		}

		// G21: Cap term_ids in SSR same as REST.
		if ( count( $term_ids ) > 100 ) {
			$term_ids = array_slice( $term_ids, 0, 100 );
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
	protected static function render_items_html( $items, $post_type, $columns = 3 ) {
		$html = '';
		$idx  = 0;
		foreach ( $items as $item ) {
			$idx++;
			// First two cards eager-load to help LCP. Rest stay lazy.
			$eager = ( $idx <= 2 );
			$html .= self::render_single_card_html( $item, $post_type, $eager, $columns );
		}
		return $html;
	}

	/**
	 * Compute responsive sizes attribute from column count.
	 *
	 * @param int $columns Column count.
	 * @return string
	 */
	protected static function sizes_for_columns( $columns ) {
		switch ( max( 1, min( 4, (int) $columns ) ) ) {
			case 1:
				return '(max-width: 640px) 100vw, 100vw';
			case 2:
				return '(max-width: 640px) 100vw, 50vw';
			case 4:
				return '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw';
			case 3:
			default:
				return '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw';
		}
	}

	/**
	 * Render one card. Mirrors the JS template structure exactly.
	 *
	 * @param array  $item      Formatted item.
	 * @param string $post_type Post type slug.
	 * @param bool   $eager     Whether to eager-load the image (above-fold optimization).
	 * @return string
	 */
	protected static function render_single_card_html( $item, $post_type, $eager = false, $columns = 3 ) {
		$cta_label  = self::get_cta_label( $post_type ); // G9: per-post-type CTA
		$image_html = self::render_card_image_html( $item, $eager, $columns, $cta_label );
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
	 * C-L3: Detect Rank Math / Yoast handling site-wide breadcrumb schema.
	 *
	 * @return bool
	 */
	protected static function seo_plugin_handles_breadcrumb() {
		$handles = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath\\Helper' )
			|| defined( 'WPSEO_VERSION' ) || class_exists( 'Yoast\\WP\\SEO\\Main' );
		/**
		 * Filter whether an SEO plugin is handling breadcrumb schema (so we should skip ours).
		 *
		 * @param bool $handles True if Rank Math / Yoast detected.
		 */
		return apply_filters( 'aeg_seo_plugin_handles_breadcrumb', $handles );
	}

	/**
	 * G9: Per-post-type CTA label so the hover overlay says the right thing.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	protected static function get_cta_label( $post_type ) {
		$map = array(
			'wprm_recipe' => __( 'View Recipe →', 'aladdin-evergreen-grid' ),
			'product'     => __( 'View Product →', 'aladdin-evergreen-grid' ),
			'post'        => __( 'Read More →', 'aladdin-evergreen-grid' ),
			'page'        => __( 'View Page →', 'aladdin-evergreen-grid' ),
			'event'       => __( 'View Event →', 'aladdin-evergreen-grid' ),
		);
		$label = isset( $map[ $post_type ] ) ? $map[ $post_type ] : __( 'View →', 'aladdin-evergreen-grid' );
		return apply_filters( 'aeg_cta_label', $label, $post_type );
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
		// G16: Guard mb_convert_case — falls back to ucwords on hosts without mbstring.
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $name );
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
	protected static function render_card_image_html( $item, $eager = false, $columns = 3, $cta_label = '' ) {
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
				'sizes'         => self::sizes_for_columns( $columns ), // G11: per-column responsive sizes
			);
			if ( $eager ) {
				$attrs['fetchpriority'] = 'high';
			}
			$image_tag = wp_get_attachment_image( $attachment_id, 'medium_large', false, $attrs );
			if ( $image_tag ) {
				// G9: per-post-type CTA label rendered inside the overlay span.
				$overlay = '<span class="aeg-card__overlay" aria-hidden="true">' . esc_html( $cta_label ) . '</span>';
				return '<div class="aeg-card__image">' . $image_tag . $overlay . '</div>';
			}
		}

		// Fallback — manually built tag (still excluded from lazy loaders).
		$overlay = '<span class="aeg-card__overlay" aria-hidden="true">' . esc_html( $cta_label ) . '</span>';
		return sprintf(
			'<div class="aeg-card__image"><img src="%1$s" alt="%2$s" class="%3$s" loading="%4$s" decoding="async" data-no-lazy="1"%5$s width="%6$d" height="%7$d" />%8$s</div>',
			esc_url( $item['thumbnail']['url'] ),
			esc_attr( $item['thumbnail']['alt'] ?: $item['title'] ),
			esc_attr( $lazy_classes ),
			$eager ? 'eager' : 'lazy',
			$eager ? ' fetchpriority="high"' : '',
			(int) ( $item['thumbnail']['w'] ?: 800 ),
			(int) ( $item['thumbnail']['h'] ?: 600 ),
			$overlay // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
			// G15: Hide rating <= 0 so PHP + JS agree (was inconsistent in v0.5).
			if ( isset( $meta['rating'] ) && (float) $meta['rating'] > 0 ) {
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

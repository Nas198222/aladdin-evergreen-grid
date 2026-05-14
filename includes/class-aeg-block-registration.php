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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'localize_frontend' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'localize_frontend' ) );
	}

	/**
	 * Pass REST URL + nonce to the frontend script.
	 * Uses rest_url() so this works on subdir installs, multisite, custom REST prefixes, reverse proxies.
	 *
	 * @return void
	 */
	public static function localize_frontend() {
		$handle = generate_block_asset_handle( self::BLOCK_NAME, 'view' );

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'aegConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'aladdin-evergreen/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
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
				'postType'     => 'wprm_recipe',
				'taxonomy'     => '',
				'termIds'      => array(),
				'columns'      => 3,
				'showFilters'  => true,
				'showSearch'   => true,
				'perPage'      => 12,
				'heading'      => '',
				'showLoadMore' => true,
			)
		);

		$post_type     = sanitize_key( $attributes['postType'] );
		$taxonomy      = sanitize_key( $attributes['taxonomy'] );
		$term_ids      = AEG_Helpers::sanitize_term_ids( $attributes['termIds'] );
		$columns       = min( 4, max( 1, absint( $attributes['columns'] ) ) );
		$show_filters  = (bool) $attributes['showFilters'];
		$show_search   = (bool) $attributes['showSearch'];
		$per_page      = min( 50, max( 1, absint( $attributes['perPage'] ) ) );
		$heading       = sanitize_text_field( $attributes['heading'] );
		$show_load_more = (bool) $attributes['showLoadMore'];

		$controls_id = wp_unique_id( 'aeg-grid-' );

		ob_start();
		?>
		<div
			class="aeg-grid aeg-grid--cols-<?php echo (int) $columns; ?>"
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
							<button class="aeg-grid__filter aeg-grid__filter--active" type="button" data-term-id="">
								<?php esc_html_e( 'All', 'aladdin-evergreen-grid' ); ?>
							</button>
							<?php foreach ( self::get_filter_terms( $taxonomy, $term_ids ) as $term ) : ?>
								<button class="aeg-grid__filter" type="button" data-term-id="<?php echo (int) $term->term_id; ?>">
									<?php echo esc_html( $term->name ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="aeg-grid__items" aria-live="polite" aria-busy="true">
				<?php echo self::render_skeleton( $per_page, $columns ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<?php if ( $show_load_more ) : ?>
				<div class="aeg-grid__pagination">
					<button class="aeg-grid__load-more" type="button" hidden>
						<?php esc_html_e( 'Load more', 'aladdin-evergreen-grid' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
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
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);

		if ( ! empty( $term_ids ) ) {
			$args['include'] = $term_ids;
			$args['orderby'] = 'include';
		} else {
			$args['orderby'] = 'name';
		}

		$terms = get_terms( $args );

		return is_wp_error( $terms ) ? array() : $terms;
	}
}

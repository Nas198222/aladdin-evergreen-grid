<?php
/**
 * Public REST endpoint for the universal grid.
 *
 * GET /wp-json/aladdin-evergreen/v1/grid-items
 *
 * @package Aladdin_Evergreen_Grid
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoint controller.
 */
class AEG_REST_Endpoint {

	const NAMESPACE_PREFIX  = 'aladdin-evergreen/v1';
	const CACHE_PREFIX      = 'aeg_grid_';
	const CACHE_GROUP       = 'aeg_grid';
	const CACHE_TTL         = 5 * MINUTE_IN_SECONDS;
	const MAX_PAGE          = 100;
	const MAX_SEARCH_LENGTH = 100;
	const MAX_TERMS         = 100;
	const RATE_LIMIT_WINDOW = 60;   // seconds
	const RATE_LIMIT_HITS   = 120;  // requests per IP per window

	/**
	 * Wire up REST routes + cache invalidation.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Invalidate the grid cache only when relevant content changes.
		add_action( 'save_post', array( __CLASS__, 'maybe_flush_on_post_save' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'maybe_flush_on_post_delete' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'maybe_flush_on_term_change' ), 10, 3 );
		add_action( 'created_term', array( __CLASS__, 'maybe_flush_on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'maybe_flush_on_term_change' ), 10, 3 );

		// Override WP's rest_send_nocache_headers at the actual point it runs — rest_pre_serve_request.
		// WP registers rest_send_nocache_headers at priority 0 on rest_pre_serve_request; we run at 99 after it.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'force_public_cache_headers' ), 99, 4 );

		// Belt-and-suspenders: filter nocache_headers() itself to return our cache headers for our route.
		add_filter( 'nocache_headers', array( __CLASS__, 'filter_nocache_headers' ), 99, 1 );
	}

	/**
	 * If the current request is our REST endpoint + user is anon, replace the WP nocache header set.
	 *
	 * @param array $headers Default nocache headers.
	 * @return array
	 */
	public static function filter_nocache_headers( $headers ) {
		// Only override on REST requests to our route.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $headers;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( false === strpos( $uri, '/' . self::NAMESPACE_PREFIX . '/grid-items' ) ) {
			return $headers;
		}
		if ( is_user_logged_in() ) {
			return $headers;
		}
		return array(
			'Cache-Control' => sprintf( 'public, max-age=60, s-maxage=%d, stale-while-revalidate=120', self::CACHE_TTL ),
			'Expires'       => '',
		);
	}

	/**
	 * After WP's nocache filter runs, replace headers on the wire for our endpoint when anon.
	 *
	 * @param bool             $served  Whether the response has already been served.
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public static function force_public_cache_headers( $served, $result, $request, $server ) {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::NAMESPACE_PREFIX . '/grid-items' ) ) {
			return $served;
		}
		if ( is_user_logged_in() ) {
			return $served;
		}
		if ( headers_sent() ) {
			return $served;
		}
		$cache_value = sprintf( 'public, max-age=60, s-maxage=%d, stale-while-revalidate=120', self::CACHE_TTL );
		// header() with replace=true overrides whatever rest_send_nocache_headers set.
		header( 'Cache-Control: ' . $cache_value, true );
		header_remove( 'Expires' );
		return $served;
	}

	/**
	 * Flush only if the saved post is in the allow-list.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function maybe_flush_on_post_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::get_allowed_post_types(), true ) ) {
			return;
		}
		self::schedule_flush();
	}

	/**
	 * Flush only if the deleted post is in the allow-list.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object (may be null on older WP).
	 * @return void
	 */
	public static function maybe_flush_on_post_delete( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && ! in_array( $post->post_type, self::get_allowed_post_types(), true ) ) {
			return;
		}
		self::schedule_flush();
	}

	/**
	 * Flush only if the changed term belongs to a taxonomy our endpoint can serve.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term-taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function maybe_flush_on_term_change( $term_id, $tt_id, $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		// G19: Match the grid-items filter rule (public OR show_in_rest), not the stricter AND.
		if ( ! $tax || ( ! $tax->public && ! $tax->show_in_rest ) ) {
			return;
		}
		self::schedule_flush();
	}

	/**
	 * Defer the actual flush to shutdown so we don't block the save request.
	 *
	 * @return void
	 */
	protected static function schedule_flush() {
		static $scheduled = false;
		if ( $scheduled ) {
			return;
		}
		$scheduled = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Allow-listed post types this endpoint will serve.
	 * A post type must be public AND show_in_rest to qualify.
	 * Allow customizing via the `aeg_allowed_post_types` filter.
	 *
	 * @return string[]
	 */
	public static function get_allowed_post_types() {
		$all     = get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'names' );
		$blocked = AEG_Helpers::get_excluded_post_types();

		foreach ( $blocked as $b ) {
			unset( $all[ $b ] );
		}

		/**
		 * Filter the post types this REST endpoint exposes.
		 *
		 * @param string[] $allowed Allowed post type slugs.
		 */
		return apply_filters( 'aeg_allowed_post_types', array_values( $all ) );
	}

	/**
	 * Allow-listed taxonomies for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string[]
	 */
	public static function get_allowed_taxonomies_for( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$allowed    = array();

		foreach ( $taxonomies as $tax ) {
			// WPRM registers wprm_course / wprm_cuisine / wprm_diet as public=true but show_in_rest=false.
			// Treat any public taxonomy on an allow-listed post type as allowed so the block can filter recipes.
			if ( $tax->public || $tax->show_in_rest ) {
				$allowed[] = $tax->name;
			}
		}

		/**
		 * Filter the taxonomies exposed for a post type.
		 *
		 * @param string[] $allowed   Allowed taxonomy slugs.
		 * @param string   $post_type Post type.
		 */
		return apply_filters( 'aeg_allowed_taxonomies', $allowed, $post_type );
	}

	/**
	 * Flush all grid transients (DB-backed) and the object-cache group.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		// G8: With persistent object cache (Redis on Kinsta), transients live in the object cache,
		// NOT in wp_options. wp_cache_flush_group() handles that case. Bump a generation key as
		// the universal fallback — every cache_key incorporates it, so increment = instant invalidation.
		$gen = (int) get_option( 'aeg_cache_gen', 0 );
		update_option( 'aeg_cache_gen', $gen + 1, false );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}

		// Also clean up DB-backed transients for sites without object cache.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
			)
		);
	}

	/**
	 * Build a cache key that includes the current generation so flush_cache() invalidates instantly.
	 *
	 * @param array $params Sanitized request params.
	 * @return string
	 */
	protected static function build_cache_key( $params ) {
		$gen = (int) get_option( 'aeg_cache_gen', 0 );
		return self::CACHE_PREFIX . $gen . '_' . md5( maybe_serialize( $params ) );
	}

	/**
	 * Lightweight per-IP rate limit using transients.
	 *
	 * @return bool True when the request is allowed, false when over the limit.
	 */
	protected static function rate_limit_ok() {
		// Logged-in users (editors etc.) are not rate-limited.
		if ( is_user_logged_in() ) {
			return true;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		// Hash so we never store raw IPs in wp_options.
		$key = 'aeg_rl_' . substr( md5( $ip ), 0, 16 );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::RATE_LIMIT_HITS ) {
			return false;
		}
		set_transient( $key, $hits + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_PREFIX,
			'/grid-items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_items' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_PREFIX,
			'/taxonomies',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_taxonomies_for_post_type' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'post_type' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_PREFIX,
			'/terms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_terms_for_taxonomy' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'taxonomy' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Main /grid-items handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_items( WP_REST_Request $request ) {
		// Anti-abuse throttle. Editor requests via apiFetch use logged-in users → exempt.
		if ( ! self::rate_limit_ok() ) {
			return new WP_Error(
				'aeg_rate_limited',
				__( 'Too many requests. Please slow down.', 'aladdin-evergreen-grid' ),
				array( 'status' => 429 )
			);
		}

		$params = self::sanitize_params( $request );

		// G17: 400 when raw page exceeds MAX_PAGE (rather than silently capping).
		if ( $params['page_raw'] > self::MAX_PAGE ) {
			return new WP_Error(
				'aeg_page_out_of_range',
				sprintf(
					/* translators: %d: max page */
					__( 'Page out of range. Max is %d.', 'aladdin-evergreen-grid' ),
					self::MAX_PAGE
				),
				array( 'status' => 400 )
			);
		}

		// Validate against the allow-list, not just post_type_exists.
		$allowed_post_types = self::get_allowed_post_types();
		if ( ! in_array( $params['post_type'], $allowed_post_types, true ) ) {
			return new WP_Error(
				'aeg_invalid_post_type',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Post type "%s" is not allowed by this endpoint.', 'aladdin-evergreen-grid' ),
					$params['post_type']
				),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $params['taxonomy'] ) ) {
			$allowed_tax = self::get_allowed_taxonomies_for( $params['post_type'] );
			if ( ! in_array( $params['taxonomy'], $allowed_tax, true ) ) {
				return new WP_Error(
					'aeg_invalid_taxonomy',
					sprintf(
						/* translators: %s: taxonomy slug */
						__( 'Taxonomy "%s" is not allowed for this post type.', 'aladdin-evergreen-grid' ),
						$params['taxonomy']
					),
					array( 'status' => 400 )
				);
			}
		}

		$cache_key = self::build_cache_key( $params );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$response = rest_ensure_response( $cached );
			$response->header( 'X-AEG-Cache', 'HIT' );
			self::add_public_cache_headers( $response );
			return $response;
		}

		$query_args = AEG_Helpers::build_query_args( $params );
		$query      = new WP_Query( $query_args );

		// Prime thumbnail caches in one go to avoid N+1.
		if ( ! empty( $query->posts ) ) {
			update_post_thumbnail_cache( $query );
		}

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = AEG_Helpers::format_grid_item( $post, $params['post_type'] );
		}

		$payload = array(
			'items'      => $items,
			'pagination' => array(
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'current'     => (int) $params['page'],
				'per_page'    => (int) $params['per_page'],
			),
		);

		wp_reset_postdata();
		set_transient( $cache_key, $payload, self::CACHE_TTL );

		$response = rest_ensure_response( $payload );
		$response->header( 'X-AEG-Cache', 'MISS' );
		self::add_public_cache_headers( $response );

		return $response;
	}

	/**
	 * Send edge-friendly cache headers for anonymous GETs.
	 * Public CDN cache + short browser cache + stale-while-revalidate.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	protected static function add_public_cache_headers( $response ) {
		if ( is_user_logged_in() ) {
			return; // Logged-in users get the WP default no-store.
		}
		$response->header(
			'Cache-Control',
			sprintf( 'public, max-age=60, s-maxage=%d, stale-while-revalidate=120', self::CACHE_TTL ),
			true
		);
	}

	/**
	 * Sanitize incoming request params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected static function sanitize_params( WP_REST_Request $request ) {
		// `rand` removed intentionally — breaks pagination + costly + sticky under cache.
		$orderby_allowed = array( 'date', 'title', 'menu_order', 'modified' );
		$order_allowed   = array( 'ASC', 'DESC' );

		// Coerce arrays to strings before sanitizing (PHP 8.2 warns on Array→String).
		$as_string = static function ( $value ) {
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			return (string) wp_unslash( $value ?? '' );
		};

		$post_type = sanitize_key( $as_string( $request->get_param( 'post_type' ) ?: 'wprm_recipe' ) );
		$taxonomy  = sanitize_key( $as_string( $request->get_param( 'taxonomy' ) ) );
		$search    = sanitize_text_field( $as_string( $request->get_param( 'search' ) ) );
		$orderby   = sanitize_key( $as_string( $request->get_param( 'orderby' ) ?: 'date' ) );
		$order     = strtoupper( sanitize_key( $as_string( $request->get_param( 'order' ) ?: 'DESC' ) ) );
		// G20: Coerce arrays before absint to avoid PHP 8.2 array-to-string warnings.
		$per_page = min( 50, max( 1, absint( $as_string( $request->get_param( 'per_page' ) ?: 12 ) ) ) );
		// G17: Keep the raw page so caller can detect out-of-range and 400.
		$page_raw  = absint( $as_string( $request->get_param( 'page' ) ?: 1 ) );
		$page      = max( 1, $page_raw );
		$term_ids  = AEG_Helpers::sanitize_term_ids( $request->get_param( 'term_ids' ) );

		// Enforce term ID cap to prevent oversized IN (...) queries.
		if ( count( $term_ids ) > self::MAX_TERMS ) {
			$term_ids = array_slice( $term_ids, 0, self::MAX_TERMS );
		}

		// mbstring-safe length cap on search.
		if ( AEG_Helpers::safe_strlen( $search ) > self::MAX_SEARCH_LENGTH ) {
			$search = AEG_Helpers::safe_substr( $search, self::MAX_SEARCH_LENGTH );
		}

		if ( ! in_array( $orderby, $orderby_allowed, true ) ) {
			$orderby = 'date';
		}

		if ( ! in_array( $order, $order_allowed, true ) ) {
			$order = 'DESC';
		}

		return array(
			'post_type' => $post_type,
			'taxonomy'  => $taxonomy,
			'term_ids'  => $term_ids,
			'search'    => $search,
			'orderby'   => $orderby,
			'order'     => $order,
			'per_page'  => $per_page,
			'page'      => $page,
			'page_raw'  => $page_raw,
		);
	}

	/**
	 * /taxonomies — list taxonomies bound to a post type. Editor-only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_taxonomies_for_post_type( WP_REST_Request $request ) {
		$post_type = sanitize_key( $request->get_param( 'post_type' ) );

		$allowed_post_types = self::get_allowed_post_types();
		if ( ! in_array( $post_type, $allowed_post_types, true ) ) {
			return new WP_Error(
				'aeg_invalid_post_type',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Post type "%s" is not allowed.', 'aladdin-evergreen-grid' ),
					$post_type
				),
				array( 'status' => 400 )
			);
		}

		$allowed_tax = self::get_allowed_taxonomies_for( $post_type );
		$taxonomies  = get_object_taxonomies( $post_type, 'objects' );
		$out         = array();

		foreach ( $taxonomies as $tax ) {
			if ( ! in_array( $tax->name, $allowed_tax, true ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => $tax->name,
				'label' => $tax->labels->singular_name,
			);
		}

		return rest_ensure_response( $out );
	}

	/**
	 * /terms — list terms for a taxonomy. Editor-only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_terms_for_taxonomy( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'aeg_invalid_taxonomy',
				sprintf(
					/* translators: %s: taxonomy slug */
					__( 'Taxonomy "%s" does not exist.', 'aladdin-evergreen-grid' ),
					$taxonomy
				),
				array( 'status' => 400 )
			);
		}

		// G6: Match the grid-items filter rule (public OR show_in_rest), not AND, so WPRM taxonomies work here too.
		$tax_object = get_taxonomy( $taxonomy );
		if ( ! $tax_object || ( ! $tax_object->public && ! $tax_object->show_in_rest ) ) {
			return new WP_Error(
				'aeg_taxonomy_not_public',
				__( 'Taxonomy is not publicly available.', 'aladdin-evergreen-grid' ),
				array( 'status' => 400 )
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::MAX_TERMS,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return new WP_Error(
				'aeg_terms_query_failed',
				$terms->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => sanitize_text_field( $term->name ),
				'count' => (int) $term->count,
			);
		}

		return rest_ensure_response( $out );
	}
}

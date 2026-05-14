<?php
/**
 * Static helper utilities for the Aladdin Evergreen Grid.
 *
 * @package Aladdin_Evergreen_Grid
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers — kept stateless to be hook-safe.
 */
class AEG_Helpers {

	/**
	 * Sanitize a CSV string or array of term IDs into a unique list of positive ints.
	 *
	 * @param mixed $term_ids Raw input (CSV string, array, or scalar).
	 * @return int[]
	 */
	public static function sanitize_term_ids( $term_ids ) {
		if ( is_string( $term_ids ) ) {
			$term_ids = explode( ',', sanitize_text_field( wp_unslash( $term_ids ) ) );
		}

		if ( ! is_array( $term_ids ) ) {
			$term_ids = array();
		}

		$out = array();

		foreach ( $term_ids as $term_id ) {
			$id = absint( $term_id );
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Get sanitized term names for a post on a taxonomy.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[]
	 */
	public static function get_term_names( $post_id, $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$names = array();

		foreach ( $terms as $term ) {
			$names[] = sanitize_text_field( $term->name );
		}

		return $names;
	}

	/**
	 * Get the formatted card payload for a single post.
	 *
	 * @param WP_Post $post      Post object.
	 * @param string  $post_type Post type slug.
	 * @return array
	 */
	public static function format_grid_item( WP_Post $post, $post_type ) {
		$thumbnail_id = get_post_thumbnail_id( $post );
		$thumbnail    = array(
			'url' => '',
			'alt' => '',
			'w'   => 0,
			'h'   => 0,
		);

		if ( $thumbnail_id ) {
			$image = wp_get_attachment_image_src( $thumbnail_id, 'medium_large' );
			if ( $image ) {
				$thumbnail = array(
					'url' => esc_url_raw( $image[0] ),
					'alt' => sanitize_text_field( get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) ),
					'w'   => (int) $image[1],
					'h'   => (int) $image[2],
				);
			}
		}

		$item = array(
			'id'        => (int) $post->ID,
			'title'     => wp_strip_all_tags( get_the_title( $post ) ),
			'link'      => esc_url_raw( get_permalink( $post ) ),
			'thumbnail' => $thumbnail,
			'excerpt'   => wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), 24, '…' ),
			'meta'      => self::get_item_meta( $post->ID, $post_type ),
		);

		/**
		 * Filter the formatted grid item before returning.
		 *
		 * @param array   $item      Formatted item.
		 * @param WP_Post $post      Source post object.
		 * @param string  $post_type Post type slug.
		 */
		return apply_filters( 'aeg_grid_item', $item, $post, $post_type );
	}

	/**
	 * Resolve meta payload per post type — pluggable via filter.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	public static function get_item_meta( $post_id, $post_type ) {
		$meta = array(
			'rating' => null,
			'time'   => '',
			'diet'   => array(),
			'course' => '',
		);

		if ( 'wprm_recipe' === $post_type ) {
			$course = self::get_term_names( $post_id, 'wprm_course' );
			$meta   = array_merge(
				$meta,
				array(
					'calories' => sanitize_text_field( get_post_meta( $post_id, 'wprm_nutrition_calories', true ) ),
					'rating'   => (float) get_post_meta( $post_id, 'wprm_rating_average', true ),
					'time'     => sanitize_text_field( get_post_meta( $post_id, 'wprm_total_time', true ) ),
					'diet'     => self::get_term_names( $post_id, 'wprm_diet' ),
					'course'   => ! empty( $course ) ? $course[0] : '',
				)
			);
		}

		if ( 'product' === $post_type ) {
			$meta = array_merge(
				$meta,
				array(
					'price' => sanitize_text_field( get_post_meta( $post_id, '_price', true ) ),
					'sku'   => sanitize_text_field( get_post_meta( $post_id, '_sku', true ) ),
				)
			);
		}

		/**
		 * Filter per-post-type meta returned for grid cards.
		 * Use this to add custom fields for any post type.
		 *
		 * @param array  $meta      Meta payload.
		 * @param int    $post_id   Post ID.
		 * @param string $post_type Post type slug.
		 */
		return apply_filters( 'aeg_grid_item_meta', $meta, $post_id, $post_type );
	}

	/**
	 * Build WP_Query args from a sanitized request param set.
	 *
	 * @param array $params Sanitized params.
	 * @return array
	 */
	public static function build_query_args( $params ) {
		$args = array(
			'post_type'              => $params['post_type'],
			'post_status'            => 'publish',
			'posts_per_page'         => $params['per_page'],
			'paged'                  => $params['page'],
			'orderby'                => $params['orderby'],
			'order'                  => $params['order'],
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
		);

		if ( ! empty( $params['search'] ) ) {
			$args['s'] = $params['search'];
		}

		if ( ! empty( $params['taxonomy'] ) && ! empty( $params['term_ids'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => $params['taxonomy'],
					'field'    => 'term_id',
					'terms'    => $params['term_ids'],
				),
			);
		}

		/**
		 * Filter the WP_Query args before the grid query runs.
		 *
		 * @param array $args   WP_Query args.
		 * @param array $params Sanitized request params.
		 */
		return apply_filters( 'aeg_grid_query_args', $args, $params );
	}
}

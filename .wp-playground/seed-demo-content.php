<?php
/**
 * Plugin Name: Seed Demo Content
 * Description: Creates sample posts (with picsum images) + a demo page with the Evergreen Grid block.
 */

add_action( 'init', function () {
	if ( get_option( 'aeg_demo_seeded' ) ) {
		return;
	}

	$samples = array(
		array( 'Creamy Curry Yogurt Dressing',     'A velvety Mediterranean-style sauce with whole milk yogurt, curry, garlic, and lemon.',     'Sauces & Dips' ),
		array( 'Easy Muhammara Recipe',            'Smoky roasted red pepper and walnut dip you can make in 25 minutes.',                       'Sauces & Dips' ),
		array( 'Lentil & Butternut Squash Bowl',   'A cozy bowl with lentils, quinoa, roasted butternut squash, kale, and orange vinaigrette.', 'Salads & Bowls' ),
		array( 'Sweet Chili Beef Stew',            'Lamb meatballs in a rich tomato-pepper sauce with carrots and potatoes.',                    'Mains' ),
		array( 'Turkish Green Beans (Zeytinyağlı)','Slow-braised green beans in olive oil with tomato. Serve warm or room temp.',                'Sides' ),
		array( 'Basic Homemade Hummus',            'Creamy chickpea and tahini dip topped with olive oil and paprika.',                          'Sauces & Dips' ),
		array( 'Roasted Cauliflower Tahini Bowl',  'Char-edged cauliflower over warm bulgur with tahini drizzle and herbs.',                     'Salads & Bowls' ),
		array( 'Mediterranean Chicken Kebabs',     'Marinated chicken thigh skewers with garlic-lemon yogurt and warm pita.',                    'Mains' ),
	);

	// Create categories first.
	$cat_ids = array();
	foreach ( array( 'Salads & Bowls', 'Sauces & Dips', 'Mains', 'Sides' ) as $cat_name ) {
		$term = term_exists( $cat_name, 'category' );
		if ( ! $term ) {
			$term = wp_insert_term( $cat_name, 'category' );
		}
		$cat_ids[ $cat_name ] = is_array( $term ) ? (int) $term['term_id'] : 0;
	}

	// Need media_sideload_image for featured images.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	foreach ( $samples as $i => $row ) {
		list( $title, $excerpt, $cat ) = $row;

		$content = '<p>' . esc_html( $excerpt ) . '</p>'
			. '<p>This is a demo post seeded for the Aladdin Evergreen Grid Playground. Try clicking the filters and the search box on the demo page.</p>';

		$post_id = wp_insert_post( array(
			'post_title'    => $title,
			'post_excerpt'  => $excerpt,
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_author'   => 1,
			'post_content'  => $content,
			'post_category' => array( $cat_ids[ $cat ] ),
		) );

		// Pull a free placeholder image via picsum.photos (networking is enabled in the blueprint).
		if ( $post_id && function_exists( 'media_sideload_image' ) ) {
			$image_url = 'https://picsum.photos/seed/aeg-' . $i . '/800/600.webp';
			$attach_id = media_sideload_image( $image_url, $post_id, $title, 'id' );
			if ( ! is_wp_error( $attach_id ) ) {
				set_post_thumbnail( $post_id, $attach_id );
			}
		}
	}

	// Build the demo page WITH the block embedded. Use double quotes so the heredoc-style content keeps newlines.
	$block_attrs = wp_json_encode( array(
		'postType'     => 'post',
		'taxonomy'     => 'category',
		'columns'      => 3,
		'perPage'      => 6,
		'heading'      => 'All Demo Posts',
		'showSearch'   => true,
		'showFilters'  => true,
		'showLoadMore' => true,
	) );

	$demo_content = "<!-- wp:heading -->\n<h2>Evergreen Grid — Live Demo</h2>\n<!-- /wp:heading -->\n\n"
		. "<!-- wp:paragraph -->\n<p>Below is the <code>aladdin-evergreen/content-grid</code> block configured to show <strong>posts</strong> filtered by <strong>category</strong>. Try the search, click the filters, hit \"Load more\".</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:aladdin-evergreen/content-grid {$block_attrs} /-->\n\n"
		. "<!-- wp:paragraph -->\n<p><em>The exact same block can render any post type — recipes, products, locations, FAQs, anything.</em></p>\n<!-- /wp:paragraph -->";

	$page_id = wp_insert_post( array(
		'post_title'   => 'Evergreen Grid Demo',
		'post_name'    => 'evergreen-demo',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_author'  => 1,
		'post_content' => $demo_content,
	) );

	if ( $page_id ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
	}

	update_option( 'aeg_demo_seeded', true );
} );

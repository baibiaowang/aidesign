<?php
/**
 * v1.8 Home page section library — OFF by default (nothing is written until an action is called).
 *
 * The AI picks section types and fills text; the plugin composes real core blocks (so the page stays
 * editable in the Site Editor) plus WooCommerce shortcodes for the product-driven sections. It can
 * preview the markup, append it, or replace the target page's content (replace needs confirmation).
 *
 * Sections: banner, category_grid, bestsellers, trust_badges, reviews, faq.
 */
defined( 'ABSPATH' ) || exit;

function hsm_home_section_types() {
	return array( 'banner', 'category_grid', 'bestsellers', 'trust_badges', 'reviews', 'faq' );
}

/** Build one section's block markup. */
function hsm_home_section( $type, $p ) {
	$h = function ( $t, $align = '' ) {
		$a = $align ? ' {"textAlign":"' . $align . '"}' : '';
		return "<!-- wp:heading{$a} --><h2 class=\"wp-block-heading" . ( $align ? ' has-text-align-' . $align : '' ) . "\">" . esc_html( $t ) . "</h2><!-- /wp:heading -->";
	};
	$shortcode = function ( $sc ) {
		return "<!-- wp:shortcode -->" . $sc . "<!-- /wp:shortcode -->";
	};
	$para = function ( $t ) {
		return "<!-- wp:paragraph --><p>" . wp_kses_post( $t ) . "</p><!-- /wp:paragraph -->";
	};

	switch ( $type ) {
		case 'banner':
			$img  = esc_url( (string) ( $p['image'] ?? '' ) );
			$h1   = esc_html( (string) ( $p['heading'] ?? '' ) );
			$sub  = wp_kses_post( (string) ( $p['subheading'] ?? '' ) );
			$btn  = esc_html( (string) ( $p['button_text'] ?? '' ) );
			$url  = esc_url( (string) ( $p['button_url'] ?? '' ) );
			$bg   = hsm_design_hex( $p['bg'] ?? '' ) ?: '#111111';
			$dim  = '{"dimRatio":60,"overlayColor":"contrast","minHeight":420,"contentPosition":"center center","align":"full","backgroundColor":"contrast"}';
			$inner = "<!-- wp:heading {\"level\":1,\"textAlign\":\"center\",\"textColor\":\"base\",\"fontSize\":\"x-large\"} --><h1 class=\"wp-block-heading has-text-align-center has-base-color has-text-color has-x-large-font-size\">{$h1}</h1><!-- /wp:heading -->";
			if ( '' !== $sub ) {
				$inner .= "<!-- wp:paragraph {\"align\":\"center\",\"textColor\":\"base\"} --><p class=\"has-text-align-center has-base-color has-text-color\">{$sub}</p><!-- /wp:paragraph -->";
			}
			if ( '' !== $btn && '' !== $url ) {
				$inner .= "<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} --><div class=\"wp-block-buttons\"><!-- wp:button --><div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"{$url}\">{$btn}</a></div><!-- /wp:button --></div><!-- /wp:buttons -->";
			}
			$style = $img ? '{"url":"' . $img . '",' . substr( $dim, 1 ) : '{' . substr( $dim, 1 );
			$cover = "<!-- wp:cover {$style} --><div class=\"wp-block-cover alignfull\"" . ( $img ? '' : ' style="background-color:' . $bg . '"' ) . "><span aria-hidden=\"true\" class=\"wp-block-cover__background has-contrast-background-color has-background-dim\"></span>" . ( $img ? '<img class="wp-block-cover__image-background" alt="" src="' . $img . '"/>' : '' ) . '<div class="wp-block-cover__inner-container">' . $inner . '</div></div><!-- /wp:cover -->';
			return $cover;

		case 'category_grid':
			$out = $h( (string) ( $p['title'] ?? 'Shop by category' ), 'center' );
			$ids = array();
			foreach ( (array) ( $p['categories'] ?? array() ) as $slug ) {
				$t = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
				if ( $t ) {
					$ids[] = (int) $t->term_id;
				}
			}
			$args = 'limit="' . max( 1, (int) ( $p['columns'] ?? 4 ) ) . '" columns="' . max( 1, (int) ( $p['columns'] ?? 4 ) ) . '"';
			if ( $ids ) {
				$args .= ' ids="' . implode( ',', $ids ) . '"';
			}
			return $out . $shortcode( '[product_categories ' . $args . ']' );

		case 'bestsellers':
			$out   = $h( (string) ( $p['title'] ?? 'Best sellers' ), 'center' );
			$order = in_array( $p['orderby'] ?? '', array( 'popularity', 'rating', 'date', 'price' ), true ) ? $p['orderby'] : 'popularity';
			return $out . $shortcode( '[products limit="' . max( 1, (int) ( $p['count'] ?? 4 ) ) . '" columns="' . max( 1, (int) ( $p['columns'] ?? 4 ) ) . '" orderby="' . $order . '"]' );

		case 'reviews':
			$out = $h( (string) ( $p['title'] ?? 'What customers say' ), 'center' );
			$out .= $shortcode( '[products limit="' . max( 1, (int) ( $p['count'] ?? 3 ) ) . '" columns="' . max( 1, (int) ( $p['columns'] ?? 3 ) ) . '" orderby="rating"]' );
			if ( ! empty( $p['note'] ) ) {
				$out .= $para( wp_kses_post( $p['note'] ) );
			}
			return $out;

		case 'trust_badges':
			$items = array_slice( (array) ( $p['items'] ?? array() ), 0, 6 );
			$cols  = max( 1, count( $items ) );
			$inner = '';
			foreach ( $items as $it ) {
				$inner .= '<!-- wp:column --><div class="wp-block-column">'
					. '<!-- wp:heading {"level":3,"fontSize":"medium"} --><h3 class="wp-block-heading has-medium-font-size">' . esc_html( (string) ( $it['title'] ?? '' ) ) . '</h3><!-- /wp:heading -->'
					. ( ! empty( $it['text'] ) ? $para( esc_html( (string) $it['text'] ) ) : '' )
					. '</div><!-- /wp:column -->';
			}
			$title = ! empty( $p['title'] ) ? $h( (string) $p['title'], 'center' ) : '';
			return $title . '<!-- wp:columns --><div class="wp-block-columns">' . $inner . '</div><!-- /wp:columns -->';

		case 'faq':
			$out = $h( (string) ( $p['title'] ?? 'Frequently asked questions' ), 'center' );
			foreach ( (array) ( $p['items'] ?? array() ) as $qa ) {
				$q = esc_html( (string) ( $qa['q'] ?? '' ) );
				$a = wp_kses_post( (string) ( $qa['a'] ?? '' ) );
				if ( '' === $q ) {
					continue;
				}
				$out .= "<!-- wp:details --><details class=\"wp-block-details\"><summary>{$q}</summary><!-- wp:paragraph --><p>{$a}</p><!-- /wp:paragraph --></details><!-- /wp:details -->";
			}
			return $out;
	}
	throw new Exception( 'Unknown section type: ' . $type . '. Allowed: ' . implode( ', ', hsm_home_section_types() ) );
}

/** Compose the markup for a list of section specs. */
function hsm_home_compose( $sections ) {
	$out = array();
	foreach ( (array) $sections as $sec ) {
		$type = (string) ( $sec['type'] ?? '' );
		$out[] = hsm_home_section( $type, $sec );
	}
	return implode( "\n\n", $out );
}

/** The page new sections go to by default (the front page). */
function hsm_home_target_page_id( $in ) {
	if ( ! empty( $in['page_id'] ) ) {
		return (int) $in['page_id'];
	}
	$front = (int) get_option( 'page_on_front' );
	return $front ?: 0;
}

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/home-sections                                      */
/* --------------------------------------------------------------------- */

function hsm_home_actions() {
	return array( 'list_types' => false, 'preview' => false, 'get' => false, 'build' => true );
}

hsm_ability( 'home-sections', array(
	'label'       => 'Home page sections',
	'description' => 'Compose home-page sections as core blocks (editable in the Site Editor). action=list_types | preview (sections → markup, nothing written) | get (page_id: current content length + front page id) | build (page_id?, mode replace|append, sections). Section types and params: banner {heading, subheading, button_text, button_url, image, bg}; category_grid {title, categories:[slugs], columns}; bestsellers {title, count, columns, orderby popularity|rating|date|price}; trust_badges {title, items:[{title, text}]}; reviews {title, count, columns, note}; faq {title, items:[{q,a}]}. mode=replace overwrites the page content and needs confirmation; append adds below.',
	'properties'  => array(
		'action'   => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_home_actions() ) ) ),
		'sections' => hsm_s( 'array', 'List of {type, ...params}.' ),
		'page_id'  => hsm_s( 'integer', 'Target page (default: the site front page).' ),
		'mode'     => hsm_s( 'string', 'replace (default) or append.', array( 'enum' => array( 'replace', 'append' ) ) ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_home_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'list_types' );
		if ( ! array_key_exists( $action, hsm_home_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_home_actions() ) ) );
		}
		if ( 'list_types' === $action ) {
			return array(
				'types' => hsm_home_section_types(),
				'params' => array(
					'banner'       => array( 'heading', 'subheading', 'button_text', 'button_url', 'image', 'bg' ),
					'category_grid' => array( 'title', 'categories[]', 'columns' ),
					'bestsellers'  => array( 'title', 'count', 'columns', 'orderby' ),
					'trust_badges' => array( 'title', 'items[]={title,text}' ),
					'reviews'      => array( 'title', 'count', 'columns', 'note' ),
					'faq'          => array( 'title', 'items[]={q,a}' ),
				),
			);
		}
		if ( 'preview' === $action ) {
			return array( 'markup' => hsm_home_compose( (array) ( $in['sections'] ?? array() ) ) );
		}
		if ( 'get' === $action ) {
			$id = hsm_home_target_page_id( $in );
			$p  = $id ? get_post( $id ) : null;
			return array(
				'page_id'     => $id,
				'front_page'  => (int) get_option( 'page_on_front' ),
				'blog_on_front' => 'posts' === get_option( 'show_on_front' ),
				'title'       => $p ? $p->post_title : null,
				'content_bytes' => $p ? strlen( $p->post_content ) : 0,
				'has_sections' => $p ? ( false !== strpos( $p->post_content, 'wp:cover' ) || false !== strpos( $p->post_content, 'wp:shortcode' ) ) : false,
			);
		}
		// build
		$sections = (array) ( $in['sections'] ?? array() );
		if ( ! $sections ) {
			throw new Exception( 'sections must be a non-empty list.' );
		}
		$id   = hsm_home_target_page_id( $in );
		if ( ! $id ) {
			throw new Exception( 'No target page. Pass page_id, or set a static front page in Settings > Reading.' );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			throw new Exception( 'Page not found: ' . $id );
		}
		$markup = hsm_home_compose( $sections );
		$mode   = (string) ( $in['mode'] ?? 'replace' );
		$new    = 'append' === $mode ? rtrim( $post->post_content ) . "\n\n" . $markup : $markup;
		if ( 'replace' === $mode ) {
			hsm_confirm( $in, sprintf( 'Replace the whole content of "%s" (page %d, %d bytes) with %d new sections.', $post->post_title, $id, strlen( $post->post_content ), count( $sections ) ), array( 'page_id' => $id, 'title' => $post->post_title, 'old_bytes' => strlen( $post->post_content ), 'new_bytes' => strlen( $new ) ) );
		}
		hsm_undo_add( array( 'type' => 'post', 'id' => $id, 'state' => array( 'ID' => $id, 'post_content' => $post->post_content ) ) );
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ) );
		return array( 'action' => 'build', 'page_id' => $id, 'mode' => $mode, 'sections' => count( $sections ), 'bytes' => strlen( $new ), 'url' => get_permalink( $id ) );
	},
) );

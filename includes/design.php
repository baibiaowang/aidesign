<?php
/**
 * v1.8 Design (storefront look & product-page extras) — OFF by default.
 *
 * The AI only ever changes *design parameters*, never theme code:
 *  - global style: primary/secondary colour (#hex only), body font (whitelisted, loaded locally so no
 *    request to Google Fonts), button corner radius, button colours — written into the block theme's
 *    user global styles (the same place the Site Editor writes), not into the theme files;
 *  - announcement bar with a start/end window;
 *  - WhatsApp / phone floating buttons;
 *  - per-category trust block on the product page;
 *  - extra product tabs (size chart, specs, care…) per category or for all products;
 *  - optionally dequeue any Google Fonts stylesheet the theme/plugins load.
 *
 * Colours are validated as #hex, the font must be from the whitelist, everything else is plain text.
 */
defined( 'ABSPATH' ) || exit;

function hsm_design_fonts() {
	return array(
		'system-ui'           => 'system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif',
		'Georgia'             => 'Georgia, serif',
		'"Times New Roman"'   => '"Times New Roman", Times, serif',
		'Arial'               => 'Arial, Helvetica, sans-serif',
		'Helvetica'           => 'Helvetica, Arial, sans-serif',
		'Verdana'             => 'Verdana, Geneva, sans-serif',
		'Tahoma'              => 'Tahoma, Geneva, sans-serif',
		'"Trebuchet MS"'      => '"Trebuchet MS", Helvetica, sans-serif',
		'"Courier New"'       => '"Courier New", Courier, monospace',
	);
}

function hsm_design_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_design', array() ), array(
		'style'        => array( 'enabled' => false, 'primary' => '', 'secondary' => '', 'font' => '', 'radius' => '', 'button_bg' => '', 'button_text' => '' ),
		'announcement' => array( 'enabled' => false, 'text' => '', 'link' => '', 'link_text' => '', 'start' => '', 'end' => '', 'bg' => '', 'fg' => '' ),
		'floating'     => array( 'enabled' => false, 'whatsapp' => '', 'phone' => '' ),
		'no_google_fonts' => array( 'enabled' => false ),
		'trust'        => array( 'enabled' => false, 'title' => 'Shipping & returns', 'text' => '', 'categories' => array() ),
		'tabs'         => array( 'enabled' => false, 'items' => array() ),
	) );
	foreach ( array( 'style', 'announcement', 'floating', 'no_google_fonts', 'trust', 'tabs' ) as $k ) {
		$s[ $k ] = is_array( $s[ $k ] ?? null ) ? $s[ $k ] : array();
	}
	return $s;
}

/** #rrggbb only. */
function hsm_design_hex( $v ) {
	$v = trim( (string) $v );
	return preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $v ) ? strtolower( $v ) : '';
}

/* --------------------------------------------------------------------- */
/* Global styles (block theme user styles)                                 */
/* --------------------------------------------------------------------- */

/**
 * The active theme's user global styles post. WordPress locates it through the `wp_theme`
 * taxonomy term (not the post name), so we ask core's own resolver instead of guessing.
 */
function hsm_design_global_styles_post_id( $create = true ) {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return 0;
	}
	$cpt = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), (bool) $create );
	return ! empty( $cpt['ID'] ) ? (int) $cpt['ID'] : 0;
}

/** Merge the design parameters into the user global styles. */
function hsm_design_apply_style() {
	$st = hsm_design_settings()['style'];
	$id = hsm_design_global_styles_post_id( true );
	$post = get_post( $id );
	$json = json_decode( (string) $post->post_content, true );
	if ( ! is_array( $json ) ) {
		$json = array();
	}
	$json['version']                    = class_exists( 'WP_Theme_JSON' ) ? WP_Theme_JSON::LATEST_SCHEMA : 2;
	$json['isGlobalStylesUserThemeJSON'] = true;
	$json['settings']                   = is_array( $json['settings'] ?? null ) ? $json['settings'] : array();
	$json['styles']                     = is_array( $json['styles'] ?? null ) ? $json['styles'] : array();

	// Clean out our previous values so turning a field off really removes it.
	foreach ( array( 'elements' ) as $group ) {
		$json['styles'][ $group ] = $json['styles'][ $group ] ?? array();
	}
	$json['styles']['elements']['link']   = $json['styles']['elements']['link'] ?? array();
	$json['styles']['elements']['button'] = $json['styles']['elements']['button'] ?? array();

	$primary   = hsm_design_hex( $st['primary'] );
	$secondary = hsm_design_hex( $st['secondary'] );
	$bg        = hsm_design_hex( $st['button_bg'] );
	$fg        = hsm_design_hex( $st['button_text'] );
	$radius    = preg_match( '/^\d{1,3}(px|rem|em|%)$/', (string) $st['radius'] ) ? (string) $st['radius'] : '';
	$font      = hsm_design_fonts()[ $st['font'] ] ?? '';

	// link colour
	if ( $primary ) {
		$json['styles']['elements']['link']['color']['text'] = $primary;
	} else {
		unset( $json['styles']['elements']['link']['color']['text'] );
	}
	// button colours
	$btn_bg = $bg ?: $primary;
	if ( $btn_bg ) {
		$json['styles']['elements']['button']['color']['background'] = $btn_bg;
	} else {
		unset( $json['styles']['elements']['button']['color']['background'] );
	}
	$btn_fg = $fg ?: $secondary;
	if ( $btn_fg ) {
		$json['styles']['elements']['button']['color']['text'] = $btn_fg;
	} else {
		unset( $json['styles']['elements']['button']['color']['text'] );
	}
	if ( $radius ) {
		$json['styles']['elements']['button']['border']['radius'] = $radius;
	} else {
		unset( $json['styles']['elements']['button']['border']['radius'] );
	}
	if ( $font ) {
		$json['styles']['typography']['fontFamily'] = $font;
	} else {
		unset( $json['styles']['typography']['fontFamily'] );
	}

	hsm_undo_add( array( 'type' => 'post', 'id' => $id, 'state' => array( 'ID' => $id, 'post_content' => $post->post_content ) ) );
	wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( wp_json_encode( $json ) ) ) );
	// Make sure WP regenerates the cached styles.
	if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		WP_Theme_JSON_Resolver::clean_cached_data();
	}
	return $json;
}

/* --------------------------------------------------------------------- */
/* Announcement bar                                                        */
/* --------------------------------------------------------------------- */

function hsm_design_announcement_active() {
	$a = hsm_design_settings()['announcement'];
	if ( empty( $a['enabled'] ) || '' === trim( (string) $a['text'] ) ) {
		return false;
	}
	$now = current_time( 'timestamp' );
	if ( ! empty( $a['start'] ) && $now < strtotime( $a['start'] ) ) {
		return false;
	}
	if ( ! empty( $a['end'] ) && $now > strtotime( $a['end'] ) ) {
		return false;
	}
	return true;
}

function hsm_design_announcement_html() {
	$a    = hsm_design_settings()['announcement'];
	$bg   = hsm_design_hex( $a['bg'] );
	$fg   = hsm_design_hex( $a['fg'] );
	$style = 'padding:10px 16px;text-align:center;font-size:15px;' . ( $bg ? "background:$bg;" : 'background:#111;' ) . ( $fg ? "color:$fg;" : 'color:#fff;' );
	$text = esc_html( $a['text'] );
	if ( ! empty( $a['link'] ) ) {
		$label = '' !== trim( (string) $a['link_text'] ) ? $a['link_text'] : $a['text'];
		$text  = '<a href="' . esc_url( $a['link'] ) . '" style="color:inherit;text-decoration:underline">' . esc_html( $label ) . '</a>';
	}
	return '<div class="hsm-announcement" style="' . esc_attr( $style ) . '">' . $text . '</div>';
}

add_action( 'wp_body_open', function () {
	if ( hsm_design_announcement_active() ) {
		echo wp_kses_post( hsm_design_announcement_html() );
	}
}, 5 );

/* --------------------------------------------------------------------- */
/* Floating contact buttons                                                */
/* --------------------------------------------------------------------- */

add_action( 'wp_footer', function () {
	$f = hsm_design_settings()['floating'];
	if ( empty( $f['enabled'] ) ) {
		return;
	}
	$wa = preg_replace( '/[^0-9]/', '', (string) $f['whatsapp'] );
	$ph = preg_replace( '/[^0-9+]/', '', (string) $f['phone'] );
	if ( '' === $wa && '' === $ph ) {
		return;
	}
	$btn = 'display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,.25);text-decoration:none;font-size:24px;margin-left:10px;';
	echo '<div class="hsm-floating" style="position:fixed;right:18px;bottom:18px;z-index:9999">';
	if ( $wa ) {
		echo '<a aria-label="WhatsApp" href="https://wa.me/' . esc_attr( $wa ) . '" target="_blank" rel="noopener" style="' . esc_attr( $btn . 'background:#25d366;color:#fff' ) . '">&#9990;</a>';
	}
	if ( $ph ) {
		echo '<a aria-label="Call" href="tel:' . esc_attr( $ph ) . '" style="' . esc_attr( $btn . 'background:#0b5fff;color:#fff' ) . '">&#9742;</a>';
	}
	echo '</div>';
} );

/* --------------------------------------------------------------------- */
/* Do not load Google Fonts                                                */
/* --------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', function () {
	if ( empty( hsm_design_settings()['no_google_fonts']['enabled'] ) ) {
		return;
	}
	global $wp_styles;
	if ( ! $wp_styles ) {
		return;
	}
	foreach ( (array) $wp_styles->registered as $handle => $style ) {
		if ( ! empty( $style->src ) && is_string( $style->src ) && preg_match( '#fonts\.(googleapis|gstatic)\.com#', $style->src ) ) {
			wp_dequeue_style( $handle );
		}
	}
}, 100 );

/* --------------------------------------------------------------------- */
/* Product page: per-category trust block                                  */
/* --------------------------------------------------------------------- */

function hsm_design_trust_for( $product_id ) {
	$t = hsm_design_settings()['trust'];
	if ( empty( $t['enabled'] ) ) {
		return null;
	}
	$cats = wp_list_pluck( get_the_terms( $product_id, 'product_cat' ) ?: array(), 'slug' );
	foreach ( (array) $t['categories'] as $row ) {
		if ( empty( $row['text'] ) ) {
			continue;
		}
		$rc = array_filter( (array) ( $row['categories'] ?? array() ) );
		if ( ! $rc || array_intersect( $rc, $cats ) ) {
			return array( 'title' => $row['title'] ?? $t['title'], 'text' => $row['text'] );
		}
	}
	return '' !== trim( (string) $t['text'] ) ? array( 'title' => $t['title'], 'text' => $t['text'] ) : null;
}

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product ) {
		return;
	}
	$b = hsm_design_trust_for( $product->get_id() );
	if ( ! $b ) {
		return;
	}
	echo '<div class="hsm-trust" style="margin:16px 0;padding:14px 16px;border:1px solid #e5e5e5;border-radius:6px;background:#fafafa">';
	if ( ! empty( $b['title'] ) ) {
		echo '<strong>' . esc_html( $b['title'] ) . '</strong>';
	}
	echo '<div>' . wp_kses_post( wpautop( $b['text'] ) ) . '</div></div>';
}, 45 );

/* --------------------------------------------------------------------- */
/* Product page: extra tabs                                                */
/* --------------------------------------------------------------------- */

add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	$d = hsm_design_settings()['tabs'];
	if ( empty( $d['enabled'] ) || empty( $d['items'] ) ) {
		return $tabs;
	}
	global $product;
	$pid  = $product ? $product->get_id() : 0;
	$cats = $pid ? wp_list_pluck( get_the_terms( $pid, 'product_cat' ) ?: array(), 'slug' ) : array();
	$i    = 0;
	foreach ( (array) $d['items'] as $item ) {
		if ( empty( $item['title'] ) || '' === trim( (string) ( $item['content'] ?? '' ) ) ) {
			continue;
		}
		$rc = array_filter( (array) ( $item['categories'] ?? array() ) );
		if ( $rc && ! array_intersect( $rc, $cats ) ) {
			continue;
		}
		$i++;
		$content = $item['content'];
		$tabs[ 'hsm_tab_' . $i ] = array(
			'title'    => $item['title'],
			'priority' => 25 + $i,
			'callback' => function () use ( $content ) {
				echo wp_kses_post( wpautop( $content ) );
			},
		);
	}
	return $tabs;
}, 20 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/design                                             */
/* --------------------------------------------------------------------- */

function hsm_design_actions() {
	return array( 'get_settings' => false, 'fonts' => false, 'set_settings' => true, 'apply_style' => true, 'reset_style' => true );
}

hsm_ability( 'design', array(
	'label'       => 'Storefront design',
	'description' => 'Design parameters only (never theme code). action=get_settings | fonts (whitelisted font families) | set_settings (merge a subset of: style {enabled, primary, secondary, font, radius, button_bg, button_text} | announcement {enabled, text, link, link_text, start, end, bg, fg} | floating {enabled, whatsapp, phone} | no_google_fonts {enabled} | trust {enabled, title, text, categories:[{title, text, categories:[slugs]}]} | tabs {enabled, items:[{title, content, categories:[slugs]}]}) | apply_style (write style into the block theme global styles) | reset_style (clear the style values). Colours must be #hex; start/end are YYYY-MM-DD HH:MM.',
	'properties'  => array(
		'action'   => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_design_actions() ) ) ),
		'settings' => hsm_s( 'object', 'Partial settings object to merge.' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_design_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! array_key_exists( $action, hsm_design_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_design_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			return hsm_design_settings();
		}
		if ( 'fonts' === $action ) {
			return array( 'fonts' => array_keys( hsm_design_fonts() ), 'note' => 'System/local fonts only; nothing is loaded from Google.' );
		}
		if ( 'apply_style' === $action ) {
			$json = hsm_design_apply_style();
			return array( 'action' => 'apply_style', 'styles' => $json['styles'], 'note' => 'Written to the active theme user global styles.' );
		}
		if ( 'reset_style' === $action ) {
			$before = get_option( 'hsm_design', array() );
			$new    = $before;
			$new['style'] = array( 'enabled' => false, 'primary' => '', 'secondary' => '', 'font' => '', 'radius' => '', 'button_bg' => '', 'button_text' => '' );
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_design', 'old' => $before ) );
			update_option( 'hsm_design', $new );
			hsm_design_apply_style();
			return array( 'action' => 'reset_style', 'settings' => hsm_design_settings() );
		}
		// set_settings
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_design', array() );
		$new    = $before;
		foreach ( $incoming as $key => $val ) {
			if ( ! in_array( $key, array( 'style', 'announcement', 'floating', 'no_google_fonts', 'trust', 'tabs' ), true ) ) {
				throw new Exception( 'Unknown settings key: ' . $key );
			}
			$new[ $key ] = hsm_design_sanitize( $key, $val );
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_design', 'old' => $before ) );
		update_option( 'hsm_design', $new );
		$out = array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_design_settings() );
		if ( isset( $incoming['style'] ) ) {
			hsm_design_apply_style();
			$out['style_applied'] = true;
		}
		return $out;
	},
) );

/** Sanitize one design settings group. */
function hsm_design_sanitize( $key, $val ) {
	$val = is_array( $val ) ? $val : array();
	$hex = array( 'primary', 'secondary', 'button_bg', 'button_text', 'bg', 'fg' );
	$out = array();
	switch ( $key ) {
		case 'style':
			$out = array(
				'enabled'     => (bool) ( $val['enabled'] ?? false ),
				'primary'     => hsm_design_hex( $val['primary'] ?? '' ),
				'secondary'   => hsm_design_hex( $val['secondary'] ?? '' ),
				'button_bg'   => hsm_design_hex( $val['button_bg'] ?? '' ),
				'button_text' => hsm_design_hex( $val['button_text'] ?? '' ),
				'font'        => isset( hsm_design_fonts()[ $val['font'] ?? '' ] ) ? $val['font'] : '',
				'radius'      => preg_match( '/^\d{1,3}(px|rem|em|%)$/', (string) ( $val['radius'] ?? '' ) ) ? (string) $val['radius'] : '',
			);
			break;
		case 'announcement':
			$out = array(
				'enabled'   => (bool) ( $val['enabled'] ?? false ),
				'text'      => sanitize_text_field( $val['text'] ?? '' ),
				'link'      => esc_url_raw( $val['link'] ?? '', array( 'http', 'https' ) ),
				'link_text' => sanitize_text_field( $val['link_text'] ?? '' ),
				'start'     => hsm_design_datetime( $val['start'] ?? '' ),
				'end'       => hsm_design_datetime( $val['end'] ?? '' ),
				'bg'        => hsm_design_hex( $val['bg'] ?? '' ),
				'fg'        => hsm_design_hex( $val['fg'] ?? '' ),
			);
			break;
		case 'floating':
			$out = array(
				'enabled'  => (bool) ( $val['enabled'] ?? false ),
				'whatsapp' => substr( preg_replace( '/[^0-9]/', '', (string) ( $val['whatsapp'] ?? '' ) ), 0, 20 ),
				'phone'    => substr( preg_replace( '/[^0-9+]/', '', (string) ( $val['phone'] ?? '' ) ), 0, 20 ),
			);
			break;
		case 'no_google_fonts':
			$out = array( 'enabled' => (bool) ( $val['enabled'] ?? false ) );
			break;
		case 'trust':
			$out = array(
				'enabled'    => (bool) ( $val['enabled'] ?? false ),
				'title'      => sanitize_text_field( $val['title'] ?? '' ),
				'text'       => wp_kses_post( $val['text'] ?? '' ),
				'categories' => array(),
			);
			foreach ( (array) ( $val['categories'] ?? array() ) as $row ) {
				$out['categories'][] = array(
					'title'      => sanitize_text_field( $row['title'] ?? '' ),
					'text'       => wp_kses_post( $row['text'] ?? '' ),
					'categories' => array_map( 'sanitize_title', array_filter( (array) ( $row['categories'] ?? array() ) ) ),
				);
			}
			break;
		case 'tabs':
			$out = array( 'enabled' => (bool) ( $val['enabled'] ?? false ), 'items' => array() );
			foreach ( (array) ( $val['items'] ?? array() ) as $row ) {
				$out['items'][] = array(
					'title'      => sanitize_text_field( $row['title'] ?? '' ),
					'content'    => wp_kses_post( $row['content'] ?? '' ),
					'categories' => array_map( 'sanitize_title', array_filter( (array) ( $row['categories'] ?? array() ) ) ),
				);
			}
			break;
	}
	return $out;
}

function hsm_design_datetime( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	$t = strtotime( $v );
	return $t ? gmdate( 'Y-m-d H:i', $t ) : '';
}

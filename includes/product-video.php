<?php
/**
 * v1.8 Product video — OFF by default.
 *
 * One video per product: a YouTube or Vimeo link (embedded with a privacy-friendly iframe) or a
 * self-hosted file URL (a native <video>). Shown under the product summary. The URL is validated to
 * those hosts or a video file extension, so no arbitrary embed can be planted.
 */
defined( 'ABSPATH' ) || exit;

function hsm_video_settings() {
	return wp_parse_args( (array) get_option( 'hsm_product_video', array() ), array( 'enabled' => false, 'title' => 'Product video' ) );
}

/** Turn a stored URL into safe embed markup, or ''. */
function hsm_video_embed( $url ) {
	$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
	if ( '' === $url ) {
		return '';
	}
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( preg_match( '#(^|\.)youtube\.com$#', $host ) ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $q['v'] ?? '' ) );
	} elseif ( preg_match( '#(^|\.)youtu\.be$#', $host ) ) {
		$id = preg_replace( '/[^A-Za-z0-9_-]/', '', ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
	} else {
		$id = '';
	}
	if ( $id ) {
		return '<iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $id ) . '" width="100%" height="400" frameborder="0" allowfullscreen loading="lazy" title="Product video"></iframe>';
	}
	if ( preg_match( '#(^|\.)vimeo\.com$#', $host ) ) {
		$vid = preg_replace( '/[^0-9]/', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( $vid ) {
			return '<iframe src="https://player.vimeo.com/video/' . esc_attr( $vid ) . '" width="100%" height="400" frameborder="0" allowfullscreen loading="lazy" title="Product video"></iframe>';
		}
	}
	if ( preg_match( '#\.(mp4|webm|ogv|mov)(\?.*)?$#i', $url ) ) {
		return '<video controls preload="metadata" style="width:100%;max-height:400px" src="' . esc_url( $url ) . '"></video>';
	}
	return '';
}

add_action( 'woocommerce_after_single_product_summary', function () {
	$s = hsm_video_settings();
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	global $product;
	if ( ! $product ) {
		return;
	}
	$html = hsm_video_embed( get_post_meta( $product->get_id(), '_hsm_video', true ) );
	if ( $html ) {
		echo '<section class="hsm-product-video"><h2>' . esc_html( $s['title'] ) . '</h2>' . $html . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from a validated host/id above
	}
}, 28 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/product-video                                      */
/* --------------------------------------------------------------------- */

hsm_ability( 'product-video', array(
	'label'       => 'Product video',
	'description' => 'Give a product one video. action=get (product_id → its video URL and whether it embeds) | set (product_id, url — a YouTube/Vimeo link or a direct .mp4/.webm file URL; anything else is rejected) | clear (product_id) | get_settings | set_settings (enabled, title). Shown under the product summary.',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array( 'get', 'set', 'clear', 'get_settings', 'set_settings' ) ) ),
		'product_id' => hsm_s( 'integer' ),
		'url'        => hsm_s( 'string' ),
		'settings'   => hsm_s( 'object' ),
	),
	'is_write'    => function ( $in ) {
		return in_array( $in['action'] ?? '', array( 'set', 'clear', 'set_settings' ), true );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'get' );
		if ( ! in_array( $action, array( 'get', 'set', 'clear', 'get_settings', 'set_settings' ), true ) ) {
			throw new Exception( 'Unknown action.' );
		}
		if ( 'get_settings' === $action ) {
			return hsm_video_settings();
		}
		if ( 'set_settings' === $action ) {
			$before = get_option( 'hsm_product_video', array() );
			$s      = (array) $in['settings'];
			$new    = array(
				'enabled' => (bool) ( $s['enabled'] ?? false ),
				'title'   => sanitize_text_field( (string) ( $s['title'] ?? 'Product video' ) ),
			);
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_product_video', 'old' => $before ) );
			update_option( 'hsm_product_video', $new );
			return array( 'action' => 'set_settings', 'settings' => hsm_video_settings() );
		}
		$pid = (int) ( $in['product_id'] ?? 0 );
		if ( ! wc_get_product( $pid ) ) {
			throw new Exception( 'Product not found.' );
		}
		if ( 'clear' === $action ) {
			hsm_undo_add( array( 'type' => 'post_meta', 'id' => $pid, 'key' => '_hsm_video', 'old' => get_post_meta( $pid, '_hsm_video', true ) ) );
			delete_post_meta( $pid, '_hsm_video' );
			return array( 'action' => 'clear', 'product_id' => $pid );
		}
		if ( 'set' === $action ) {
			$url  = esc_url_raw( trim( (string) ( $in['url'] ?? '' ) ), array( 'http', 'https' ) );
			$html = hsm_video_embed( $url );
			if ( ! $html ) {
				throw new Exception( 'Only YouTube/Vimeo links or direct .mp4/.webm/.ogv/.mov file URLs are accepted.' );
			}
			hsm_undo_add( array( 'type' => 'post_meta', 'id' => $pid, 'key' => '_hsm_video', 'old' => get_post_meta( $pid, '_hsm_video', true ) ) );
			update_post_meta( $pid, '_hsm_video', $url );
			return array( 'action' => 'set', 'product_id' => $pid, 'url' => $url );
		}
		$url = (string) get_post_meta( $pid, '_hsm_video', true );
		return array( 'product_id' => $pid, 'url' => $url ?: null, 'embeds' => (bool) hsm_video_embed( $url ) );
	},
) );

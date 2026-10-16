<?php
/**
 * v1.8 Product compare — OFF by default.
 *
 * A "Compare" button on product cards and the product page stores chosen product ids in a cookie
 * (client-side, no server round trip); a floating bar shows the selection, and a [hsm_compare] page
 * renders a side-by-side table. Rows are built from the store's own product data, so nothing is
 * invented. Max 4 products at a time.
 */
defined( 'ABSPATH' ) || exit;

function hsm_compare_defaults() {
	return array(
		'enabled'    => false,
		'title'      => 'Compare products',
		'max'        => 4,
		'rows'       => array( 'image', 'price', 'sku', 'stock', 'weight', 'dimensions', 'short_description' ),
		'button_text' => 'Compare',
	);
}

function hsm_compare_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_compare', array() ), hsm_compare_defaults() );
	$s['enabled'] = (bool) $s['enabled'];
	$s['max']     = max( 2, min( 6, (int) $s['max'] ) );
	$s['rows']    = array_values( array_filter( array_map( 'sanitize_text_field', (array) $s['rows'] ) ) );
	return $s;
}

/** Ids currently in the compare cookie (validated). */
function hsm_compare_ids() {
	$raw = isset( $_COOKIE['hsm_compare'] ) ? (string) $_COOKIE['hsm_compare'] : '';
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
	$ids = array_values( array_unique( $ids ) );
	return array_slice( $ids, 0, hsm_compare_settings()['max'] );
}

/** One row's label and per-product value. */
function hsm_compare_row( $key, $product ) {
	switch ( $key ) {
		case 'image':
			$img = $product->get_image_id() ? wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail', false, array( 'style' => 'max-width:120px;height:auto' ) ) : '';
			return array( '', $img );
		case 'price':
			return array( 'Price', wp_kses_post( $product->get_price_html() ) );
		case 'sku':
			return array( 'SKU', esc_html( $product->get_sku() ) );
		case 'stock':
			return array( 'Availability', esc_html( $product->is_in_stock() ? 'In stock' : 'Out of stock' ) );
		case 'weight':
			return array( 'Weight', esc_html( $product->get_weight() ? wc_format_weight( $product->get_weight() ) : '—' ) );
		case 'dimensions':
			$d = $product->get_dimensions( false );
			return array( 'Dimensions', esc_html( $d ? wc_format_dimensions( $d ) : '—' ) );
		case 'short_description':
			return array( 'Summary', wp_kses_post( wp_trim_words( $product->get_short_description() ?: $product->get_description(), 30 ) ) );
	}
	// A product attribute (pa_* or custom).
	$attrs = $product->get_attributes();
	foreach ( $attrs as $attr ) {
		if ( ! is_a( $attr, 'WC_Product_Attribute' ) ) {
			continue;
		}
		$name = $attr->get_name();
		if ( $name !== $key ) {
			continue;
		}
		$label = wc_attribute_label( $name );
		$opts  = $attr->is_taxonomy() ? wp_list_pluck( $attr->get_terms() ?: array(), 'name' ) : $attr->get_options();
		return array( $label, esc_html( implode( ', ', $opts ) ) );
	}
	return null;
}

/** The comparison table for the cookie's products. */
function hsm_compare_table() {
	$s     = hsm_compare_settings();
	$ids   = hsm_compare_ids();
	$prods = array_filter( array_map( 'wc_get_product', $ids ) );
	if ( count( $prods ) < 2 ) {
		return '<p>' . esc_html__( 'Add at least two products to compare.', 'hark-store-mcp' ) . '</p>';
	}
	$out = '<div class="hsm-compare" style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th></th>';
	foreach ( $prods as $p ) {
		$out .= '<th style="padding:8px;border-bottom:1px solid #eee;text-align:left"><a href="' . esc_url( get_permalink( $p->get_id() ) ) . '">' . esc_html( $p->get_name() ) . '</a></th>';
	}
	$out .= '</tr></thead><tbody>';
	foreach ( $s['rows'] as $key ) {
		$first = hsm_compare_row( $key, reset( $prods ) );
		if ( ! $first ) {
			continue;
		}
		$out .= '<tr><th style="padding:8px;text-align:left;background:#fafafa">' . esc_html( $first[0] ) . '</th>';
		foreach ( $prods as $p ) {
			$r = hsm_compare_row( $key, $p );
			$out .= '<td style="padding:8px;border-bottom:1px solid #eee;vertical-align:top">' . ( $r ? $r[1] : '' ) . '</td>';
		}
		$out .= '</tr>';
	}
	return $out . '</tbody></table></div>';
}

add_shortcode( 'hsm_compare', function () {
	if ( ! hsm_compare_settings()['enabled'] ) {
		return '';
	}
	return '<h2>' . esc_html( hsm_compare_settings()['title'] ) . '</h2>' . hsm_compare_table();
} );

/* --------------------------------------------------------------------- */
/* Buttons + floating bar                                                  */
/* --------------------------------------------------------------------- */

/** A compare toggle button for the current product. */
function hsm_compare_button( $product_id ) {
	$s = hsm_compare_settings();
	if ( empty( $s['enabled'] ) ) {
		return '';
	}
	$in = in_array( (int) $product_id, hsm_compare_ids(), true );
	return '<button type="button" class="hsm-compare-toggle" data-id="' . (int) $product_id . '" aria-pressed="' . ( $in ? 'true' : 'false' ) . '">'
		. esc_html( $in ? '✓ ' . $s['button_text'] : $s['button_text'] ) . '</button>';
}

add_action( 'woocommerce_after_shop_loop_item', function () {
	global $product;
	if ( $product ) {
		echo wp_kses_post( hsm_compare_button( $product->get_id() ) );
	}
}, 20 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( $product ) {
		echo '<div class="hsm-compare-btn" style="margin:8px 0">' . wp_kses_post( hsm_compare_button( $product->get_id() ) ) . '</div>';
	}
}, 37 );

add_action( 'wp_footer', function () {
	$s = hsm_compare_settings();
	if ( empty( $s['enabled'] ) ) {
		return;
	}
	$page = get_option( 'hsm_compare_page' );
	$url  = $page ? get_permalink( (int) $page ) : '';
	?>
<div id="hsm-compare-bar" style="display:none;position:fixed;left:0;right:0;bottom:0;z-index:9998;background:#111;color:#fff;padding:10px 16px;align-items:center;gap:12px;font-size:14px">
	<span id="hsm-compare-count"></span>
	<a id="hsm-compare-go" href="<?php echo esc_url( $url ); ?>" style="color:#fff;text-decoration:underline">Compare now</a>
	<button type="button" id="hsm-compare-clear" style="margin-left:auto;background:none;border:1px solid #fff;color:#fff;border-radius:4px;padding:2px 8px;cursor:pointer">Clear</button>
</div>
<script>
(function(){
  var MAX = <?php echo (int) $s['max']; ?>;
  function read(){ var m = document.cookie.match(/(?:^|; )hsm_compare=([^;]*)/); if(!m) return []; return decodeURIComponent(m[1]).split(",").filter(Boolean); }
  function write(ids){ document.cookie = "hsm_compare=" + ids.join(",") + ";path=/;max-age=2592000"; }
  function sync(){
    var ids = read();
    document.querySelectorAll(".hsm-compare-toggle").forEach(function(b){
      var on = ids.indexOf(b.getAttribute("data-id")) > -1;
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
    var bar = document.getElementById("hsm-compare-bar");
    if (bar) {
      document.getElementById("hsm-compare-count").textContent = ids.length ? ids.length + " selected" : "";
      bar.style.display = ids.length ? "flex" : "none";
    }
  }
  document.addEventListener("click", function(e){
    var b = e.target.closest(".hsm-compare-toggle");
    if (b) {
      e.preventDefault();
      var id = b.getAttribute("data-id"), ids = read(), i = ids.indexOf(id);
      if (i > -1) { ids.splice(i,1); }
      else { if (ids.length >= MAX) { ids.shift(); } ids.push(id); }
      write(ids); sync(); return;
    }
    if (e.target.closest("#hsm-compare-clear")) { write([]); sync(); }
  });
  document.addEventListener("DOMContentLoaded", sync);
  setTimeout(sync, 300);
})();
</script>
	<?php
} );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/compare                                            */
/* --------------------------------------------------------------------- */

hsm_ability( 'compare', array(
	'label'       => 'Product compare',
	'description' => 'A compare button on product cards and the product page (client-side cookie), a floating selection bar, and a [hsm_compare] page that renders the table. action=get_settings | set_settings (enabled, title, max 2-6, rows: [image, price, sku, stock, weight, dimensions, short_description, or a product attribute name like pa_color], button_text) | set_page (page_id of the page that contains [hsm_compare], used by the "Compare now" link).',
	'properties'  => array(
		'action'   => hsm_s( 'string', '', array( 'enum' => array( 'get_settings', 'set_settings', 'set_page' ) ) ),
		'settings' => hsm_s( 'object' ),
		'page_id'  => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return in_array( $in['action'] ?? '', array( 'set_settings', 'set_page' ), true );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! in_array( $action, array( 'get_settings', 'set_settings', 'set_page' ), true ) ) {
			throw new Exception( 'Unknown action.' );
		}
		if ( 'get_settings' === $action ) {
			$s = hsm_compare_settings();
			$s['_page_id'] = (int) get_option( 'hsm_compare_page' );
			return $s;
		}
		if ( 'set_page' === $action ) {
			$pid = (int) ( $in['page_id'] ?? 0 );
			if ( $pid && ! get_post( $pid ) ) {
				throw new Exception( 'Page not found.' );
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_compare_page', 'old' => get_option( 'hsm_compare_page' ) ) );
			$pid ? update_option( 'hsm_compare_page', $pid, false ) : delete_option( 'hsm_compare_page' );
			return array( 'action' => 'set_page', 'page_id' => $pid, 'url' => $pid ? get_permalink( $pid ) : null );
		}
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_compare', array() );
		$new    = $before;
		if ( array_key_exists( 'enabled', $incoming ) ) {
			$new['enabled'] = (bool) $incoming['enabled'];
		}
		if ( array_key_exists( 'title', $incoming ) ) {
			$new['title'] = sanitize_text_field( (string) $incoming['title'] );
		}
		if ( array_key_exists( 'button_text', $incoming ) ) {
			$new['button_text'] = sanitize_text_field( (string) $incoming['button_text'] );
		}
		if ( array_key_exists( 'max', $incoming ) ) {
			$new['max'] = max( 2, min( 6, (int) $incoming['max'] ) );
		}
		if ( array_key_exists( 'rows', $incoming ) ) {
			$new['rows'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $incoming['rows'] ) ) );
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_compare', 'old' => $before ) );
		update_option( 'hsm_compare', $new );
		return array( 'action' => 'set_settings', 'settings' => hsm_compare_settings() );
	},
) );

<?php
/**
 * v1.8 Variation swatches — OFF by default.
 *
 * Shows a colour / button / image swatch row above a variation dropdown on the product page. The
 * swatches drive WooCommerce's own <select> (a click sets the select value and fires its change event),
 * so all of WooCommerce's variation logic — availability, price, image, add-to-cart — keeps working
 * unchanged. No third-party script.
 *
 * Colours come from the plugin's own term meta (_hsm_swatch_color / _hsm_swatch_image) or a mapping in
 * the settings. With no colour the swatch falls back to a text button.
 */
defined( 'ABSPATH' ) || exit;

function hsm_swatches_defaults() {
	return array(
		'enabled'    => false,
		'attributes' => array(),  // attribute names to swatch, e.g. ["pa_color"]; empty = any attribute whose slug contains "color"
		'shape'      => 'circle', // circle | square
		'size'       => 32,
		'hide_select' => false,
		'colors'     => array(),  // term slug => #hex (fallback / override)
	);
}

function hsm_swatches_settings() {
	$s = wp_parse_args( (array) get_option( 'hsm_swatches', array() ), hsm_swatches_defaults() );
	$s['enabled']     = (bool) $s['enabled'];
	$s['shape']       = in_array( $s['shape'], array( 'circle', 'square' ), true ) ? $s['shape'] : 'circle';
	$s['size']        = max( 16, min( 80, (int) $s['size'] ) );
	$s['hide_select'] = (bool) $s['hide_select'];
	$s['attributes']  = array_values( array_filter( array_map( 'sanitize_text_field', (array) $s['attributes'] ) ) );
	$s['colors']      = is_array( $s['colors'] ) ? $s['colors'] : array();
	return $s;
}

function hsm_swatches_applies_to( $attribute ) {
	$s = hsm_swatches_settings();
	if ( $s['attributes'] ) {
		return in_array( $attribute, $s['attributes'], true ) || in_array( 'attribute_' . $attribute, $s['attributes'], true );
	}
	return false !== stripos( $attribute, 'color' ) || false !== stripos( $attribute, 'colour' );
}

/** Colour / image for one term, from term meta or the settings map. */
function hsm_swatches_term_visual( $term_slug, $attribute ) {
	$s = hsm_swatches_settings();
	if ( ! empty( $s['colors'][ $term_slug ] ) ) {
		return array( 'color' => hsm_design_hex( $s['colors'][ $term_slug ] ), 'image' => '' );
	}
	$term = get_term_by( 'slug', $term_slug, $attribute );
	if ( ! $term ) {
		return array( 'color' => '', 'image' => '' );
	}
	foreach ( array( '_hsm_swatch_color', 'product_attribute_color', 'swatch_color', '_color' ) as $k ) {
		$c = get_term_meta( $term->term_id, $k, true );
		if ( $c && hsm_design_hex( $c ) ) {
			return array( 'color' => hsm_design_hex( $c ), 'image' => '' );
		}
	}
	foreach ( array( '_hsm_swatch_image', 'product_attribute_image', 'swatch_image' ) as $k ) {
		$img = get_term_meta( $term->term_id, $k, true );
		if ( $img ) {
			$url = is_numeric( $img ) ? wp_get_attachment_url( (int) $img ) : esc_url_raw( (string) $img );
			if ( $url ) {
				return array( 'color' => '', 'image' => $url );
			}
		}
	}
	return array( 'color' => '', 'image' => '' );
}

/** Swatch row for one attribute dropdown. */
function hsm_swatches_render( $attribute, $options, $product, $select_id ) {
	$s     = hsm_swatches_settings();
	$shape = 'circle' === $s['shape'] ? '50%' : '4px';
	$size  = (int) $s['size'];
	$out   = '<div class="hsm-swatches" data-attribute="' . esc_attr( $attribute ) . '" data-select-id="' . esc_attr( $select_id ) . '" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 10px">';
	foreach ( (array) $options as $opt ) {
		$slug  = sanitize_title( $opt );
		$label = wc_attribute_label( $attribute );
		$name  = $opt;
		if ( taxonomy_exists( $attribute ) ) {
			$t = get_term_by( 'slug', $opt, $attribute );
			if ( $t ) {
				$name = $t->name;
			}
		}
		$vis = hsm_swatches_term_visual( $slug, $attribute );
		if ( $vis['image'] ) {
			$inner = '<img src="' . esc_url( $vis['image'] ) . '" alt="' . esc_attr( $name ) . '" style="width:100%;height:100%;object-fit:cover;border-radius:' . esc_attr( $shape ) . '">';
		} elseif ( $vis['color'] ) {
			$inner = '';
		} else {
			$inner = '<span style="font-size:12px;line-height:1">' . esc_html( mb_substr( $name, 0, 3 ) ) . '</span>';
		}
		$bg = $vis['color'] ? 'background:' . $vis['color'] . ';' : 'background:#fff;';
		$out .= '<button type="button" class="hsm-swatch" data-value="' . esc_attr( $opt ) . '" title="' . esc_attr( $name ) . '" aria-label="' . esc_attr( $name ) . '"'
			. ' style="width:' . $size . 'px;height:' . $size . 'px;border-radius:' . esc_attr( $shape ) . ';border:2px solid #ddd;cursor:pointer;padding:0;display:inline-flex;align-items:center;justify-content:center;' . esc_attr( $bg ) . '">'
			. $inner . '</button>';
	}
	return $out . '</div>';
}

add_filter( 'woocommerce_dropdown_variation_attribute_options_html', function ( $html, $args ) {
	$s = hsm_swatches_settings();
	if ( empty( $s['enabled'] ) || empty( $args['options'] ) || empty( $args['attribute'] ) ) {
		return $html;
	}
	if ( ! hsm_swatches_applies_to( $args['attribute'] ) ) {
		return $html;
	}
	$product = $args['product'] ?? null;
	if ( ! $product ) {
		return $html;
	}
	$select_id = 'hsm-' . sanitize_title( $args['attribute'] ) . '-' . (int) $product->get_id();
	$swatches  = hsm_swatches_render( $args['attribute'], $args['options'], $product, $select_id );
	// Give the real select the id our JS looks for.
	if ( false === strpos( $html, 'id="' . $select_id . '"' ) ) {
		$html = preg_replace( '/<select\b/', '<select id="' . $select_id . '"', $html, 1 );
	}
	return $swatches . $html;
}, 10, 2 );

/** Hide the select and add the sync script + styles. */
add_action( 'wp_footer', function () {
	$s = hsm_swatches_settings();
	if ( empty( $s['enabled'] ) || ! is_product() ) {
		return;
	}
	$hide = $s['hide_select'] ? 'form.variations_form .hsm-swatches + select, form.variations_form select.hsm-hidden{display:none}' : '';
	?>
<style>
.hsm-swatch[aria-pressed="true"]{border-color:#111 !important;box-shadow:0 0 0 1px #111}
.hsm-swatch[data-disabled="1"]{opacity:.3;cursor:not-allowed}
<?php echo $hide; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed CSS string ?>
</style>
<script>
(function(){
  function selectFor(wrap){
    var id = wrap.getAttribute("data-select-id");
    var sel = id ? document.getElementById(id) : null;
    if (sel) return sel;
    var form = wrap.closest("form.variations_form");
    return form ? form.querySelector("select") : null;
  }
  function mark(wrap){
    var sel = selectFor(wrap);
    var val = sel ? sel.value : "";
    wrap.querySelectorAll(".hsm-swatch").forEach(function(b){
      b.setAttribute("aria-pressed", b.getAttribute("data-value") === val ? "true" : "false");
    });
  }
  document.addEventListener("click", function(e){
    var b = e.target.closest(".hsm-swatch");
    if (!b) return;
    e.preventDefault();
    var wrap = b.closest(".hsm-swatches");
    var sel = selectFor(wrap);
    if (!sel) return;
    sel.value = b.getAttribute("data-value");
    sel.dispatchEvent(new Event("change", { bubbles: true }));
    mark(wrap);
  });
  function initAll(){ document.querySelectorAll(".hsm-swatches").forEach(function(w){
    mark(w);
    var sel = selectFor(w);
    if (sel && !sel.dataset.hsmBound){ sel.dataset.hsmBound = "1"; sel.addEventListener("change", function(){ mark(w); }); }
  }); }
  document.addEventListener("DOMContentLoaded", initAll);
  if (window.jQuery) jQuery(document).on("woocommerce_update_variation_values found_variation reset_data", initAll);
  setTimeout(initAll, 300);
})();
</script>
	<?php
} );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/swatches                                           */
/* --------------------------------------------------------------------- */

hsm_ability( 'swatches', array(
	'label'       => 'Variation swatches',
	'description' => 'Show colour / image / button swatches above a variation dropdown. action=get_settings | set_settings (enabled, attributes:["pa_color"] (empty = any attribute whose slug contains "color"), shape: circle|square, size: 16-80, hide_select: bool, colors: {termSlug: "#hex"}) | term_color (taxonomy, term_slug, color → set _hsm_swatch_color on the term) | term_image (taxonomy, term_slug, url). Swatches drive WooCommerce\'s own select, so variation logic is unchanged.',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array( 'get_settings', 'set_settings', 'term_color', 'term_image' ) ) ),
		'settings'   => hsm_s( 'object' ),
		'taxonomy'   => hsm_s( 'string', 'e.g. pa_color' ),
		'term_slug'  => hsm_s( 'string' ),
		'color'      => hsm_s( 'string', '#hex' ),
		'url'        => hsm_s( 'string' ),
	),
	'is_write'    => function ( $in ) {
		return in_array( $in['action'] ?? '', array( 'set_settings', 'term_color', 'term_image' ), true );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! in_array( $action, array( 'get_settings', 'set_settings', 'term_color', 'term_image' ), true ) ) {
			throw new Exception( 'Unknown action.' );
		}
		if ( 'get_settings' === $action ) {
			return hsm_swatches_settings();
		}
		if ( 'set_settings' === $action ) {
			$incoming = (array) ( $in['settings'] ?? array() );
			if ( ! $incoming ) {
				throw new Exception( 'settings must be a non-empty object.' );
			}
			$before = get_option( 'hsm_swatches', array() );
			$new    = $before;
			if ( array_key_exists( 'enabled', $incoming ) ) {
				$new['enabled'] = (bool) $incoming['enabled'];
			}
			if ( array_key_exists( 'attributes', $incoming ) ) {
				$new['attributes'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $incoming['attributes'] ) ) );
			}
			if ( array_key_exists( 'shape', $incoming ) ) {
				$new['shape'] = in_array( $incoming['shape'], array( 'circle', 'square' ), true ) ? $incoming['shape'] : 'circle';
			}
			if ( array_key_exists( 'size', $incoming ) ) {
				$new['size'] = max( 16, min( 80, (int) $incoming['size'] ) );
			}
			if ( array_key_exists( 'hide_select', $incoming ) ) {
				$new['hide_select'] = (bool) $incoming['hide_select'];
			}
			if ( array_key_exists( 'colors', $incoming ) ) {
				$clean = array();
				foreach ( (array) $incoming['colors'] as $slug => $hex ) {
					$h = hsm_design_hex( $hex );
					if ( $h ) {
						$clean[ sanitize_title( $slug ) ] = $h;
					}
				}
				$new['colors'] = $clean;
			}
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_swatches', 'old' => $before ) );
			update_option( 'hsm_swatches', $new );
			return array( 'action' => 'set_settings', 'settings' => hsm_swatches_settings() );
		}
		// term_color / term_image
		$tax  = sanitize_text_field( (string) ( $in['taxonomy'] ?? '' ) );
		$slug = sanitize_title( (string) ( $in['term_slug'] ?? '' ) );
		if ( ! taxonomy_exists( $tax ) ) {
			throw new Exception( 'Unknown taxonomy: ' . $tax );
		}
		$term = get_term_by( 'slug', $slug, $tax );
		if ( ! $term ) {
			throw new Exception( 'Term not found: ' . $slug );
		}
		$key = 'term_color' === $action ? '_hsm_swatch_color' : '_hsm_swatch_image';
		$old = get_term_meta( $term->term_id, $key, true );
		$val = 'term_color' === $action ? hsm_design_hex( (string) ( $in['color'] ?? '' ) ) : esc_url_raw( (string) ( $in['url'] ?? '' ), array( 'http', 'https' ) );
		hsm_undo_add( array( 'type' => 'term_meta', 'id' => $term->term_id, 'key' => $key, 'old' => $old ) );
		$val ? update_term_meta( $term->term_id, $key, $val ) : delete_term_meta( $term->term_id, $key );
		return array( 'action' => $action, 'taxonomy' => $tax, 'term' => $slug, 'value' => $val );
	},
) );

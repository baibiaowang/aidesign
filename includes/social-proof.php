<?php
/**
 * v1.8 Real urgency & social proof — OFF by default.
 *
 * Everything here is driven by the store's own data — never invented:
 *  - sale countdown: counts down to a product's real sale end date (date_on_sale_to), and disappears
 *    the moment it passes;
 *  - low-stock notice: "Only N left" shown only when the real managed stock is at or below a threshold
 *    (never a made-up number);
 *  - sales proof: "N sold in the last X days" summed from real orders, cached for an hour.
 *
 * There is no feature here that resets a timer, fakes stock, or invents a sales count.
 */
defined( 'ABSPATH' ) || exit;

function hsm_proof_defaults() {
	return array(
		'sale_countdown' => array( 'enabled' => false, 'label' => 'Sale ends in' ),
		'stock_urgency'  => array( 'enabled' => false, 'threshold' => 5, 'label' => 'Only {n} left in stock' ),
		'sales_proof'    => array( 'enabled' => false, 'days' => 7, 'label' => '{n} sold in the last {days} days' ),
	);
}

function hsm_proof_settings() {
	$o = (array) get_option( 'hsm_social_proof', array() );
	$s = array();
	foreach ( hsm_proof_defaults() as $k => $def ) {
		$s[ $k ] = wp_parse_args( is_array( $o[ $k ] ?? null ) ? $o[ $k ] : array(), $def );
	}
	return $s;
}

function hsm_proof_on( $k ) {
	return ! empty( hsm_proof_settings()[ $k ]['enabled'] );
}

/** Real units sold in the last N days (HPOS lookup table, or order items on CPT). Cached 1 hour. */
function hsm_proof_sold( $product_id, $days = 7 ) {
	$product_id = (int) $product_id;
	$days       = max( 1, min( 365, (int) $days ) );
	if ( ! $product_id ) {
		return 0;
	}
	$key    = 'hsm_proof_sold_' . $product_id . '_' . $days;
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	global $wpdb;
	$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	// Order line items live in woocommerce_order_items for BOTH HPOS and legacy CPT storage; only the
	// order record itself moves (wc_orders vs wp_posts), so join whichever holds the date and status.
	// wc_order_product_lookup is not reliably populated and is therefore not used.
	$oi    = $wpdb->prefix . 'woocommerce_order_items';
	$oim   = $wpdb->prefix . 'woocommerce_order_itemmeta';
	$hpos  = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( $hpos ) {
		$join = "JOIN {$wpdb->prefix}wc_orders o ON o.id = i.order_id AND o.status IN ('wc-completed','wc-processing') AND o.date_created_gmt >= %s";
	} else {
		$join = "JOIN {$wpdb->posts} o ON o.ID = i.order_id AND o.post_type = 'shop_order' AND o.post_status IN ('wc-completed','wc-processing') AND o.post_date_gmt >= %s";
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(m2.meta_value) FROM {$oi} i
		 JOIN {$oim} m ON m.order_item_id = i.order_item_id AND m.meta_key = '_product_id' AND m.meta_value = %d
		 JOIN {$oim} m2 ON m2.order_item_id = i.order_item_id AND m2.meta_key = '_qty'
		 {$join}
		 WHERE i.order_item_type = 'line_item'",
		$product_id,
		$since
	) );
	$total = max( 0, (int) $total );
	set_transient( $key, $total, HOUR_IN_SECONDS );
	return $total;
}

/* --------------------------------------------------------------------- */
/* Product page                                                            */
/* --------------------------------------------------------------------- */

/** Real sale-end countdown ('' when there is no real deadline in the future). */
function hsm_proof_countdown_html( $product ) {
	$s = hsm_proof_settings()['sale_countdown'];
	if ( empty( $s['enabled'] ) || ! $product || ! $product->is_on_sale() ) {
		return '';
	}
	$end = $product->get_date_on_sale_to();
	if ( ! $end ) {
		return '';
	}
	$ts = $end->getTimestamp();
	if ( $ts <= time() ) {
		return '';
	}
	$id = 'hsm-cd-' . wp_rand( 1000, 9999 );
	$js = sprintf(
		'(function(){var t=%d*1000,el=document.getElementById("%s");if(!el)return;function u(){var d=t-Date.now();if(d<0){var p=el.closest(".hsm-sale-countdown");if(p)p.style.display="none";return;}var s=Math.floor(d/1000),h=Math.floor(s/3600),m=Math.floor(s%%3600/60),x=s%%60;el.textContent=h+"h "+m+"m "+x+"s";}u();setInterval(u,1000);})();',
		$ts,
		$id
	);
	return '<p class="hsm-sale-countdown" style="font-weight:600;color:#b3261e">' . esc_html( $s['label'] ) . ' <span id="' . esc_attr( $id ) . '"></span></p>'
		. '<script>' . $js . '</script>';
}

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( $product ) {
		echo wp_kses_post( hsm_proof_countdown_html( $product ) );
	}
}, 25 );

/** Real low-stock notice. */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product || ! hsm_proof_on( 'stock_urgency' ) ) {
		return;
	}
	$s = hsm_proof_settings()['stock_urgency'];
	if ( ! $product->managing_stock() || ! $product->is_in_stock() ) {
		return;
	}
	$qty = $product->get_stock_quantity();
	if ( null === $qty || (int) $qty > (int) $s['threshold'] ) {
		return;
	}
	echo '<p class="hsm-stock-urgency" style="color:#b3261e">' . esc_html( str_replace( '{n}', (string) (int) $qty, $s['label'] ) ) . '</p>';
}, 33 );

/** Real sales proof. */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product || ! hsm_proof_on( 'sales_proof' ) ) {
		return;
	}
	$s    = hsm_proof_settings()['sales_proof'];
	$days = (int) $s['days'];
	$n    = hsm_proof_sold( $product->get_id(), $days );
	if ( $n < 1 ) {
		return;
	}
	$label = str_replace( array( '{n}', '{days}' ), array( (string) $n, (string) $days ), $s['label'] );
	echo '<p class="hsm-sales-proof" style="color:#2e7d32">' . esc_html( $label ) . '</p>';
}, 34 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/social-proof                                       */
/* --------------------------------------------------------------------- */

hsm_ability( 'social-proof', array(
	'label'       => 'Real urgency & social proof',
	'description' => 'Urgency and social proof built only from the store\'s own data. action=get_settings | set_settings (sale_countdown {enabled, label} — counts down to the product\'s real date_on_sale_to and vanishes when it passes | stock_urgency {enabled, threshold, label with {n}} — "Only N left" only when the real managed stock is <= threshold | sales_proof {enabled, days, label with {n} and {days}} — real units sold in the window, cached 1h) | preview (product_id → the real values that would be shown). Nothing here fakes a timer, stock or sales count.',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array( 'get_settings', 'set_settings', 'preview' ) ) ),
		'settings'   => hsm_s( 'object' ),
		'product_id' => hsm_s( 'integer' ),
	),
	'is_write'    => function ( $in ) {
		return 'set_settings' === ( $in['action'] ?? '' );
	},
	'callback'    => function ( $in ) {
		hsm_require_wc();
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! in_array( $action, array( 'get_settings', 'set_settings', 'preview' ), true ) ) {
			throw new Exception( 'Unknown action.' );
		}
		if ( 'get_settings' === $action ) {
			return hsm_proof_settings();
		}
		if ( 'preview' === $action ) {
			$p = wc_get_product( (int) ( $in['product_id'] ?? 0 ) );
			if ( ! $p ) {
				throw new Exception( 'Product not found.' );
			}
			$end = $p->get_date_on_sale_to();
			return array(
				'product_id'    => $p->get_id(),
				'on_sale'       => $p->is_on_sale(),
				'sale_ends'     => $end ? $end->format( 'c' ) : null,
				'stock_managed' => $p->managing_stock(),
				'stock_qty'     => $p->get_stock_quantity(),
				'sold_7d'       => hsm_proof_sold( $p->get_id(), 7 ),
				'sold_30d'      => hsm_proof_sold( $p->get_id(), 30 ),
			);
		}
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$before = get_option( 'hsm_social_proof', array() );
		$new    = $before;
		foreach ( $incoming as $key => $val ) {
			if ( ! isset( hsm_proof_defaults()[ $key ] ) ) {
				throw new Exception( 'Unknown settings key: ' . $key );
			}
			$out = array();
			foreach ( hsm_proof_defaults()[ $key ] as $k => $d ) {
				$v = array_key_exists( $k, (array) $val ) ? $val[ $k ] : $d;
				if ( is_bool( $d ) ) {
					$out[ $k ] = (bool) $v;
				} elseif ( is_int( $d ) ) {
					$out[ $k ] = max( 0, (int) $v );
				} else {
					$out[ $k ] = wp_kses_post( (string) $v );
				}
			}
			$new[ $key ] = $out;
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_social_proof', 'old' => $before ) );
		update_option( 'hsm_social_proof', $new );
		return array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_proof_settings() );
	},
) );

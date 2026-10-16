<?php
/**
 * v1.8 Product add-ons — OFF by default.
 *
 * Per-product options (a choice, a checkbox, or a text field) with a price adjustment each. The chosen
 * options are shown on the product page, carried into the cart, added to the line price, listed in the
 * cart/checkout and saved on the order line. Prices are re-computed from a stored base price so they
 * are applied once, never compounded.
 *
 * Add-ons are set per product through hark-store/product-addons action=set.
 */
defined( 'ABSPATH' ) || exit;

function hsm_addons_settings() {
	return wp_parse_args( (array) get_option( 'hsm_product_addons', array() ), array( 'enabled' => false, 'title' => 'Product options' ) );
}

/** Validated add-on definitions for a product. */
function hsm_addons_get( $product_id ) {
	$raw = get_post_meta( (int) $product_id, '_hsm_addons', true );
	return is_array( $raw ) ? $raw : array();
}

function hsm_addons_clean( $addons ) {
	$out = array();
	foreach ( array_slice( (array) $addons, 0, 10 ) as $a ) {
		$type = in_array( $a['type'] ?? '', array( 'select', 'radio', 'checkbox', 'text' ), true ) ? $a['type'] : 'select';
		$row  = array(
			'label'    => sanitize_text_field( (string) ( $a['label'] ?? '' ) ),
			'type'     => $type,
			'required' => ! empty( $a['required'] ),
			'help'     => sanitize_text_field( (string) ( $a['help'] ?? '' ) ),
			'options'  => array(),
		);
		if ( '' === $row['label'] ) {
			continue;
		}
		foreach ( array_slice( (array) ( $a['options'] ?? array() ), 0, 20 ) as $o ) {
			$price = round( (float) ( $o['price'] ?? 0 ), 2 );
			if ( $price < 0 ) {
				throw new HSM_Blocked( 'Add-on option prices cannot be negative (a negative add-on would drop the line below the product price).' );
			}
			$row['options'][] = array(
				'label' => sanitize_text_field( (string) ( $o['label'] ?? '' ) ),
				'price' => $price,
			);
		}
		if ( 'text' === $type && ! $row['options'] ) {
			$row['options'][] = array( 'label' => '', 'price' => 0.0 );
		}
		if ( 'checkbox' === $type && ! $row['options'] ) {
			$row['options'][] = array( 'label' => $row['label'], 'price' => 0.0 );
		}
		$out[] = $row;
	}
	return $out;
}

/** Chosen options (from $_POST) for one product, plus their total price. */
function hsm_addons_selected( $product_id, $posted ) {
	$addons = hsm_addons_get( $product_id );
	$chosen = array();
	$total  = 0.0;
	foreach ( $addons as $i => $a ) {
		$key = 'hsm_addon_' . $i;
		if ( 'checkbox' === $a['type'] ) {
			if ( ! empty( $posted[ $key ] ) ) {
				$price    = (float) ( $a['options'][0]['price'] ?? 0 );
				$total   += $price;
				$chosen[] = array( 'label' => $a['label'], 'value' => 'yes', 'price' => $price );
			}
			continue;
		}
		$val = isset( $posted[ $key ] ) ? sanitize_text_field( (string) $posted[ $key ] ) : '';
		if ( 'text' === $a['type'] ) {
			if ( '' !== $val ) {
				$price    = (float) ( $a['options'][0]['price'] ?? 0 );
				$total   += $price;
				$chosen[] = array( 'label' => $a['label'], 'value' => $val, 'price' => $price );
			}
			continue;
		}
		// select / radio: value is the option index.
		if ( '' !== $val && isset( $a['options'][ (int) $val ] ) ) {
			$opt      = $a['options'][ (int) $val ];
			$total   += (float) $opt['price'];
			$chosen[] = array( 'label' => $a['label'], 'value' => $opt['label'], 'price' => (float) $opt['price'] );
		}
	}
	return array( 'chosen' => $chosen, 'total' => round( $total, 2 ) );
}

/* --------------------------------------------------------------------- */
/* Product page                                                            */
/* --------------------------------------------------------------------- */

add_action( 'woocommerce_before_add_to_cart_button', function () {
	if ( empty( hsm_addons_settings()['enabled'] ) ) {
		return;
	}
	global $product;
	if ( ! $product ) {
		return;
	}
	$addons = hsm_addons_get( $product->get_id() );
	if ( ! $addons ) {
		return;
	}
	echo '<div class="hsm-addons" style="margin:12px 0">';
	foreach ( $addons as $i => $a ) {
		$key = 'hsm_addon_' . $i;
		echo '<p class="hsm-addon" style="margin:8px 0">';
		if ( 'checkbox' === $a['type'] ) {
			$price = (float) ( $a['options'][0]['price'] ?? 0 );
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . ( $a['required'] ? 'required' : '' ) . '> ' . esc_html( $a['label'] );
			echo $price > 0 ? ' (+' . wp_kses_post( wc_price( $price ) ) . ')' : '';
			echo '</label>';
		} elseif ( 'text' === $a['type'] ) {
			echo '<label>' . esc_html( $a['label'] ) . ( $a['required'] ? ' *' : '' ) . '<br><input type="text" name="' . esc_attr( $key ) . '" maxlength="200" ' . ( $a['required'] ? 'required' : '' ) . '></label>';
		} else {
			echo '<label>' . esc_html( $a['label'] ) . ( $a['required'] ? ' *' : '' ) . '<br>';
			$tag = 'radio' === $a['type'] ? 'radio' : 'select';
			if ( 'select' === $tag ) {
				echo '<select name="' . esc_attr( $key ) . '" ' . ( $a['required'] ? 'required' : '' ) . '>';
				if ( ! $a['required'] ) {
					echo '<option value="">—</option>';
				}
				foreach ( $a['options'] as $oi => $o ) {
					echo '<option value="' . esc_attr( $oi ) . '">' . esc_html( $o['label'] ) . ( $o['price'] > 0 ? ' (+' . esc_html( wp_strip_all_tags( wc_price( $o['price'] ) ) ) . ')' : '' ) . '</option>';
				}
				echo '</select>';
			} else {
				foreach ( $a['options'] as $oi => $o ) {
					echo '<label style="display:block"><input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $oi ) . '" ' . ( $a['required'] ? 'required' : '' ) . '> ' . esc_html( $o['label'] ) . ( $o['price'] > 0 ? ' (+' . esc_html( wp_strip_all_tags( wc_price( $o['price'] ) ) ) . ')' : '' ) . '</label>';
				}
			}
			echo '</label>';
		}
		if ( ! empty( $a['help'] ) ) {
			echo '<br><small style="color:#666">' . esc_html( $a['help'] ) . '</small>';
		}
		echo '</p>';
	}
	echo '</div>';
}, 10 );

/* --------------------------------------------------------------------- */
/* Cart / order plumbing                                                   */
/* --------------------------------------------------------------------- */

add_filter( 'woocommerce_add_to_cart_validation', function ( $valid, $product_id, $qty ) {
	if ( empty( hsm_addons_settings()['enabled'] ) ) {
		return $valid;
	}
	foreach ( hsm_addons_get( $product_id ) as $i => $a ) {
		if ( ! empty( $a['required'] ) && empty( $_POST[ 'hsm_addon_' . $i ] ) ) {
			wc_add_notice( sprintf( 'Please choose "%s".', $a['label'] ), 'error' );
			return false;
		}
	}
	return $valid;
}, 10, 3 );

add_filter( 'woocommerce_add_cart_item_data', function ( $cart_item_data, $product_id ) {
	if ( empty( hsm_addons_settings()['enabled'] ) ) {
		return $cart_item_data;
	}
	$sel = hsm_addons_selected( $product_id, $_POST );
	if ( $sel['chosen'] ) {
		$cart_item_data['hsm_addons']      = $sel['chosen'];
		$cart_item_data['hsm_addon_total'] = $sel['total'];
		$p = wc_get_product( $product_id );
		if ( $p ) {
			$cart_item_data['hsm_base_price'] = (float) $p->get_price();
		}
	}
	return $cart_item_data;
}, 10, 2 );

/** Apply the add-on price once, from the stored base price (runs before the tier-discount hook). */
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( ! $cart instanceof WC_Cart ) {
		return;
	}
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['hsm_addon_total'] ) || empty( $item['data'] ) ) {
			continue;
		}
		$base = isset( $item['hsm_base_price'] ) ? (float) $item['hsm_base_price'] : (float) $item['data']->get_price();
		$item['data']->set_price( round( $base + (float) $item['hsm_addon_total'], wc_get_price_decimals() ) );
	}
}, 15 );

add_filter( 'woocommerce_get_item_data', function ( $data, $cart_item ) {
	if ( empty( $cart_item['hsm_addons'] ) ) {
		return $data;
	}
	foreach ( (array) $cart_item['hsm_addons'] as $c ) {
		$data[] = array(
			'name'  => $c['label'],
			'value' => $c['value'] . ( (float) $c['price'] > 0 ? ' (+' . wp_strip_all_tags( wc_price( $c['price'] ) ) . ')' : '' ),
		);
	}
	return $data;
}, 10, 2 );

add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values ) {
	if ( empty( $values['hsm_addons'] ) ) {
		return;
	}
	$text = array();
	foreach ( $values['hsm_addons'] as $c ) {
		$text[] = $c['label'] . ': ' . $c['value'] . ( (float) $c['price'] > 0 ? ' (+' . wp_strip_all_tags( wc_price( $c['price'] ) ) . ')' : '' );
	}
	$item->add_meta_data( 'Options', implode( '; ', $text ) );
}, 10, 3 );

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/product-addons                                     */
/* --------------------------------------------------------------------- */

hsm_ability( 'product-addons', array(
	'label'       => 'Product add-ons',
	'description' => 'Per-product options with a price adjustment. action=get (product_id → its add-ons) | set (product_id, addons:[{label, type: select|radio|checkbox|text, required, help, options:[{label, price}]}]) | clear (product_id) | get_settings | set_settings (enabled, title). select/radio choose one priced option; checkbox is a single priced option; text is a free text field (its options[0].price is the optional surcharge). Prices are added to the line price once. Max 10 add-ons, 20 options each.',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array( 'get', 'set', 'clear', 'get_settings', 'set_settings' ) ) ),
		'product_id' => hsm_s( 'integer' ),
		'addons'     => hsm_s( 'array' ),
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
			return hsm_addons_settings();
		}
		if ( 'set_settings' === $action ) {
			$before = get_option( 'hsm_product_addons', array() );
			$s      = (array) $in['settings'];
			$new    = array(
				'enabled' => (bool) ( $s['enabled'] ?? false ),
				'title'   => sanitize_text_field( (string) ( $s['title'] ?? 'Product options' ) ),
			);
			hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_product_addons', 'old' => $before ) );
			update_option( 'hsm_product_addons', $new );
			return array( 'action' => 'set_settings', 'settings' => hsm_addons_settings() );
		}
		$pid = (int) ( $in['product_id'] ?? 0 );
		if ( ! wc_get_product( $pid ) ) {
			throw new Exception( 'Product not found.' );
		}
		if ( 'clear' === $action ) {
			hsm_undo_add( array( 'type' => 'post_meta', 'id' => $pid, 'key' => '_hsm_addons', 'old' => get_post_meta( $pid, '_hsm_addons', true ) ) );
			delete_post_meta( $pid, '_hsm_addons' );
			return array( 'action' => 'clear', 'product_id' => $pid );
		}
		if ( 'set' === $action ) {
			$clean = hsm_addons_clean( (array) ( $in['addons'] ?? array() ) );
			if ( ! $clean ) {
				throw new Exception( 'No valid add-ons (each needs a label).' );
			}
			hsm_undo_add( array( 'type' => 'post_meta', 'id' => $pid, 'key' => '_hsm_addons', 'old' => get_post_meta( $pid, '_hsm_addons', true ) ) );
			update_post_meta( $pid, '_hsm_addons', $clean );
			return array( 'action' => 'set', 'product_id' => $pid, 'count' => count( $clean ), 'addons' => $clean );
		}
		return array( 'product_id' => $pid, 'addons' => hsm_addons_get( $pid ) );
	},
) );

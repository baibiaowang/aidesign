<?php
/**
 * v1.8 Store tweaks — one module for the small storefront changes that would otherwise each need a
 * plugin. Every tweak is OFF by default and is driven by whitelisted settings keys; an agent can turn
 * tweaks on and fill parameters but never inject HTML or code (all text is sanitized, all numbers cast,
 * verification codes restricted to [A-Za-z0-9_-]).
 *
 * Tweaks: free-shipping progress bar, sale badge as a discount percentage, "Add to cart" button text,
 * minimum order amount (classic + block checkout), per-product min/max quantity, related-products count,
 * maintenance / coming-soon mode (staff still see the site), disable comments on posts (product reviews
 * untouched), search-engine site verification meta, checkout field visibility (company / address 2 /
 * phone, via WooCommerce's own options so block checkout is covered), disable XML-RPC.
 *
 * Plus two one-shot actions: duplicate a product (WooCommerce's own duplicate) and a read-only view of
 * WP-Cron / site health.
 */
defined( 'ABSPATH' ) || exit;

function hsm_tweaks_defaults() {
	return array(
		'free_shipping_bar'   => array( 'enabled' => false, 'threshold' => 0, 'text' => 'Add {remaining} more for free shipping!' ),
		'sale_badge_percent'  => array( 'enabled' => false ),
		'add_to_cart_text'    => array( 'enabled' => false, 'text' => '' ),
		'min_order_amount'    => array( 'enabled' => false, 'amount' => 0, 'message' => '' ),
		'qty_limits'          => array( 'enabled' => false, 'min' => 0, 'max' => 0 ),
		'related_count'       => array( 'enabled' => false, 'count' => 4 ),
		'maintenance'         => array( 'enabled' => false, 'message' => 'We are doing a quick update. Please check back soon.' ),
		'disable_post_comments' => array( 'enabled' => false ),
		'site_verification'   => array( 'google' => '', 'bing' => '', 'pinterest' => '', 'facebook' => '' ),
		'checkout_fields'     => array( 'company' => 'hidden', 'address_2' => 'optional', 'phone' => 'optional' ),
		'disable_xmlrpc'      => array( 'enabled' => false ),
	);
}

function hsm_tweaks_settings() {
	$o = (array) get_option( 'hsm_tweaks', array() );
	$s = array();
	foreach ( hsm_tweaks_defaults() as $k => $def ) {
		$s[ $k ] = is_array( $def ) ? wp_parse_args( is_array( $o[ $k ] ?? null ) ? $o[ $k ] : array(), $def ) : ( $o[ $k ] ?? $def );
	}
	return apply_filters( 'hsm_tweaks_settings', $s );
}

function hsm_tweaks_on( $key ) {
	$s = hsm_tweaks_settings();
	return ! empty( $s[ $key ]['enabled'] );
}

/** Lowest configured free-shipping min_amount across zones, or the manual threshold when set. */
function hsm_tweaks_free_shipping_threshold() {
	$s = hsm_tweaks_settings()['free_shipping_bar'];
	if ( (float) $s['threshold'] > 0 ) {
		return (float) $s['threshold'];
	}
	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return 0.0;
	}
	$best  = 0.0;
	$zones = array_merge( WC_Shipping_Zones::get_zones(), array( 0 ) );
	foreach ( $zones as $z ) {
		$zone = is_array( $z ) ? new WC_Shipping_Zone( $z['zone_id'] ?? 0 ) : WC_Shipping_Zones::get_zone( $z );
		if ( ! $zone ) {
			continue;
		}
		foreach ( $zone->get_shipping_methods( true ) as $m ) {
			if ( 'free_shipping' !== $m->id ) {
				continue;
			}
			$min = (float) $m->get_option( 'min_amount' );
			if ( $min > 0 && ( 0.0 === $best || $min < $best ) ) {
				$best = $min;
			}
		}
	}
	return $best;
}

/** Discount percentage for a product (simple, or the best variation for variable products). */
function hsm_tweaks_discount_percent( $product ) {
	$pct = function ( $reg, $sale ) {
		$reg  = (float) $reg;
		$sale = (float) $sale;
		if ( $reg <= 0 || $sale <= 0 || $sale >= $reg ) {
			return 0.0;
		}
		return ( $reg - $sale ) / $reg * 100;
	};
	if ( ! $product || ! $product->is_on_sale() ) {
		return 0;
	}
	if ( $product->is_type( 'variable' ) ) {
		$max = 0.0;
		foreach ( $product->get_children() as $cid ) {
			$v = wc_get_product( $cid );
			if ( $v && $v->is_on_sale() ) {
				$max = max( $max, $pct( $v->get_regular_price(), $v->get_sale_price() ) );
			}
		}
		return (int) round( $max );
	}
	return (int) round( $pct( $product->get_regular_price(), $product->get_sale_price() ) );
}

/* --------------------------------------------------------------------- */
/* Front-end hooks (all gated on their tweak being enabled)                */
/* --------------------------------------------------------------------- */

/** Free-shipping progress bar on the cart page and in the mini-cart. */
function hsm_tweaks_shipping_bar_html() {
	if ( ! hsm_tweaks_on( 'free_shipping_bar' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return '';
	}
	$threshold = hsm_tweaks_free_shipping_threshold();
	if ( $threshold <= 0 ) {
		return '';
	}
	$subtotal = (float) WC()->cart->get_displayed_subtotal();
	$left     = max( 0, $threshold - $subtotal );
	$pct      = min( 100, (int) round( $subtotal / $threshold * 100 ) );
	$tpl      = hsm_tweaks_settings()['free_shipping_bar']['text'];
	if ( $left <= 0 ) {
		$msg = 'You have free shipping!';
	} else {
		$msg = str_replace( '{remaining}', wp_strip_all_tags( wc_price( $left ) ), $tpl );
	}
	return '<div class="hsm-ship-bar"><div class="hsm-ship-msg">' . esc_html( $msg ) . '</div>'
		. '<div class="hsm-ship-track" style="background:#eee;border-radius:4px;height:8px;overflow:hidden">'
		. '<div style="height:100%;width:' . esc_attr( $pct ) . '%;background:#2e7d32"></div></div></div>';
}
add_action( 'woocommerce_before_cart', function () {
	echo wp_kses_post( hsm_tweaks_shipping_bar_html() );
}, 5 );
add_action( 'woocommerce_widget_shopping_cart_before_buttons', function () {
	echo wp_kses_post( hsm_tweaks_shipping_bar_html() );
}, 5 );

/** Sale badge shows the discount percentage. */
add_filter( 'woocommerce_sale_flash', function ( $html, $post, $product ) {
	if ( ! hsm_tweaks_on( 'sale_badge_percent' ) ) {
		return $html;
	}
	$pct = hsm_tweaks_discount_percent( $product );
	return $pct > 0 ? '<span class="onsale">-' . esc_html( $pct ) . '%</span>' : $html;
}, 10, 3 );

/** "Add to cart" button text. */
function hsm_tweaks_cart_text( $text, $product ) {
	if ( ! hsm_tweaks_on( 'add_to_cart_text' ) ) {
		return $text;
	}
	$custom = trim( (string) hsm_tweaks_settings()['add_to_cart_text']['text'] );
	if ( '' === $custom || ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return $text;
	}
	return $custom;
}
add_filter( 'woocommerce_product_single_add_to_cart_text', 'hsm_tweaks_cart_text', 10, 2 );
add_filter( 'woocommerce_product_add_to_cart_text', 'hsm_tweaks_cart_text', 10, 2 );

/** Minimum order amount — classic cart/checkout. */
add_action( 'woocommerce_check_cart_items', function () {
	if ( ! hsm_tweaks_on( 'min_order_amount' ) || ! WC()->cart ) {
		return;
	}
	$s    = hsm_tweaks_settings()['min_order_amount'];
	$min  = (float) $s['amount'];
	if ( $min <= 0 ) {
		return;
	}
	if ( (float) WC()->cart->get_subtotal() < $min ) {
		$msg = $s['message'] ?: sprintf( 'A minimum order of %s is required.', wp_strip_all_tags( wc_price( $min ) ) );
		wc_add_notice( $msg, 'error' );
	}
} );

/** Minimum order amount — block checkout (Store API). */
add_action( 'woocommerce_store_api_cart_errors', function ( $cart_errors, $cart = null ) {
	if ( ! hsm_tweaks_on( 'min_order_amount' ) || ! WC()->cart ) {
		return;
	}
	$s   = hsm_tweaks_settings()['min_order_amount'];
	$min = (float) $s['amount'];
	if ( $min <= 0 || (float) WC()->cart->get_subtotal() >= $min ) {
		return;
	}
	$msg = $s['message'] ?: sprintf( 'A minimum order of %s is required.', wp_strip_all_tags( wc_price( $min ) ) );
	if ( is_object( $cart_errors ) && method_exists( $cart_errors, 'add' ) ) {
		$cart_errors->add( 'hsm_min_order_amount', $msg );
	}
}, 10, 2 );

/** Per-product min/max quantity. */
add_filter( 'woocommerce_quantity_input_args', function ( $args, $product ) {
	if ( ! hsm_tweaks_on( 'qty_limits' ) ) {
		return $args;
	}
	$s = hsm_tweaks_settings()['qty_limits'];
	if ( (int) $s['min'] > 0 ) {
		$args['min_value'] = max( 1, (int) $s['min'] );
	}
	if ( (int) $s['max'] > 0 ) {
		$args['max_value'] = (int) $s['max'];
	}
	return $args;
}, 10, 2 );

function hsm_tweaks_qty_error( $qty ) {
	$s   = hsm_tweaks_settings()['qty_limits'];
	$qty = (int) $qty;
	if ( (int) $s['min'] > 0 && $qty < (int) $s['min'] ) {
		return sprintf( 'The minimum quantity is %d.', (int) $s['min'] );
	}
	if ( (int) $s['max'] > 0 && $qty > (int) $s['max'] ) {
		return sprintf( 'The maximum quantity is %d.', (int) $s['max'] );
	}
	return '';
}
add_filter( 'woocommerce_add_to_cart_validation', function ( $valid, $product_id, $qty ) {
	if ( ! hsm_tweaks_on( 'qty_limits' ) ) {
		return $valid;
	}
	$err = hsm_tweaks_qty_error( $qty );
	if ( '' !== $err ) {
		wc_add_notice( $err, 'error' );
		return false;
	}
	return $valid;
}, 10, 3 );
add_action( 'woocommerce_store_api_validate_add_to_cart', function ( $product, $request = null ) {
	if ( ! hsm_tweaks_on( 'qty_limits' ) || ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
		return;
	}
	$err = hsm_tweaks_qty_error( (int) $request->get_param( 'quantity' ) );
	if ( '' !== $err ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'hsm_qty_limit', esc_html( $err ), 400 );
	}
}, 10, 2 );

/** Related products count. */
add_filter( 'woocommerce_output_related_products_args', function ( $args ) {
	if ( ! hsm_tweaks_on( 'related_count' ) ) {
		return $args;
	}
	$n = (int) hsm_tweaks_settings()['related_count']['count'];
	if ( $n > 0 ) {
		$args['posts_per_page'] = $n;
		$args['columns']        = min( $n, 4 );
	}
	return $args;
}, 20 );

/** Maintenance / coming-soon mode: 503 for visitors, normal site for staff and tooling. */
add_action( 'template_redirect', function () {
	if ( ! hsm_tweaks_on( 'maintenance' ) ) {
		return;
	}
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI )
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| in_array( $GLOBALS['pagenow'] ?? '', array( 'wp-login.php', 'wp-register.php' ), true )
		|| current_user_can( 'manage_options' ) ) {
		return;
	}
	$msg = hsm_tweaks_settings()['maintenance']['message'];
	nocache_headers();
	status_header( 503 );
	header( 'Retry-After: 3600' );
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
		. '<title>' . esc_html( get_bloginfo( 'name' ) ) . '</title></head><body style="font:16px/1.6 system-ui;max-width:640px;margin:12vh auto;padding:0 20px;text-align:center">'
		. '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1><p>' . esc_html( $msg ) . '</p></body></html>';
	exit;
}, 1 );

/** Disable comments on posts (product reviews are a different post type and stay open). */
add_filter( 'comments_open', function ( $open, $post_id ) {
	if ( hsm_tweaks_on( 'disable_post_comments' ) && 'post' === get_post_type( $post_id ) ) {
		return false;
	}
	return $open;
}, 10, 2 );
add_filter( 'pings_open', function ( $open, $post_id ) {
	if ( hsm_tweaks_on( 'disable_post_comments' ) && 'post' === get_post_type( $post_id ) ) {
		return false;
	}
	return $open;
}, 10, 2 );

/** Search-engine site verification meta tags. */
add_action( 'wp_head', function () {
	$v = hsm_tweaks_settings()['site_verification'];
	$map = array(
		'google'    => 'google-site-verification',
		'bing'      => 'msvalidate.01',
		'pinterest' => 'p:domain_verify',
		'facebook'  => 'facebook-domain-verification',
	);
	foreach ( $map as $k => $name ) {
		if ( ! empty( $v[ $k ] ) ) {
			echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $v[ $k ] ) . "\">\n";
		}
	}
}, 1 );

/** Checkout field visibility via WooCommerce's own options (block checkout compatible). */
add_action( 'init', function () {
	$c = hsm_tweaks_settings()['checkout_fields'];
	$map = array( 'company' => 'woocommerce_checkout_company_field', 'address_2' => 'woocommerce_checkout_address_2_field', 'phone' => 'woocommerce_checkout_phone_field' );
	foreach ( $map as $k => $opt ) {
		$want = $c[ $k ] ?? '';
		if ( in_array( $want, array( 'hidden', 'optional', 'required' ), true ) && get_option( $opt ) !== $want ) {
			update_option( $opt, $want );
		}
	}
}, 20 );

/** Disable XML-RPC. */
add_filter( 'xmlrpc_enabled', function ( $enabled ) {
	return hsm_tweaks_on( 'disable_xmlrpc' ) ? false : $enabled;
} );
add_filter( 'wp_headers', function ( $headers ) {
	if ( hsm_tweaks_on( 'disable_xmlrpc' ) ) {
		unset( $headers['X-Pingback'] );
	}
	return $headers;
} );
add_filter( 'xmlrpc_methods', function ( $methods ) {
	if ( hsm_tweaks_on( 'disable_xmlrpc' ) ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
	}
	return $methods;
} );

/* --------------------------------------------------------------------- */
/* One-shot helpers                                                        */
/* --------------------------------------------------------------------- */

/** Duplicate a product with WooCommerce's own duplicator. Returns the new product id. */
function hsm_tweaks_duplicate_product( $product_id ) {
	$product = wc_get_product( (int) $product_id );
	if ( ! $product ) {
		throw new Exception( 'Product not found.' );
	}
	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
	}
	$dup = new WC_Admin_Duplicate_Product();
	$new = $dup->product_duplicate( $product );
	if ( is_wp_error( $new ) ) {
		throw new Exception( $new->get_error_message() );
	}
	$new_id = $new->get_id();
	hsm_undo_add( array( 'type' => 'product_created', 'id' => $new_id ) );
	return $new_id;
}

/* --------------------------------------------------------------------- */
/* MCP tool: hark-store/store-tweaks                                       */
/* --------------------------------------------------------------------- */

function hsm_tweaks_actions() {
	return array( 'get_settings' => false, 'cron_list' => false, 'health' => false, 'post_status' => false, 'set_settings' => true, 'duplicate_product' => true, 'protect_post' => true, 'unprotect_post' => true );
}

/** Password-protect a post/page/product (WordPress native post_password). */
function hsm_tweaks_protect( $post_id, $password ) {
	$p = get_post( (int) $post_id );
	if ( ! $p ) {
		throw new Exception( 'Post not found.' );
	}
	$password = trim( (string) $password );
	if ( strlen( $password ) < 4 ) {
		throw new Exception( 'The password must be at least 4 characters.' );
	}
	hsm_undo_add( array( 'type' => 'post', 'id' => $p->ID, 'state' => array( 'ID' => $p->ID, 'post_password' => $p->post_password ) ) );
	wp_update_post( array( 'ID' => $p->ID, 'post_password' => $password ) );
	return array( 'action' => 'protect_post', 'id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'protected' => true );
}

hsm_ability( 'store-tweaks', array(
	'label'       => 'Storefront tweaks',
	'description' => 'Small storefront changes, each off by default. action=get_settings | set_settings (merge any subset of the settings object) | duplicate_product (product_id) | protect_post (post_id, password — password-protect a page or product) | unprotect_post (post_id) | post_status (post_id → whether it is password-protected) | cron_list (WP-Cron events) | health (WP/PHP/DB + debug flags). Settings keys: free_shipping_bar {enabled, threshold (0 = auto from the free-shipping methods), text with {remaining}}; sale_badge_percent {enabled}; add_to_cart_text {enabled, text}; min_order_amount {enabled, amount, message} (classic + block checkout); qty_limits {enabled, min, max}; related_count {enabled, count}; maintenance {enabled, message} (503 for visitors, staff still see the site); disable_post_comments {enabled} (product reviews stay open); site_verification {google, bing, pinterest, facebook}; checkout_fields {company, address_2, phone} each hidden|optional|required; disable_xmlrpc {enabled}. Text is sanitized; verification codes allow only letters, digits, _ and -.',
	'properties'  => array(
		'action'     => hsm_s( 'string', '', array( 'enum' => array_keys( hsm_tweaks_actions() ) ) ),
		'settings'   => hsm_s( 'object', 'Partial settings object to merge (set_settings).' ),
		'product_id' => hsm_s( 'integer', 'duplicate_product: product to duplicate.' ),
		'post_id'    => hsm_s( 'integer', 'protect_post/unprotect_post/post_status: the page or product.' ),
		'password'   => hsm_s( 'string', 'protect_post: the password (min 4 chars).' ),
		'limit'      => hsm_s( 'integer', 'cron_list: max events (default 100).' ),
	),
	'is_write'    => function ( $in ) {
		return ! empty( hsm_tweaks_actions()[ $in['action'] ?? '' ] );
	},
	'callback'    => function ( $in ) {
		$action = (string) ( $in['action'] ?? 'get_settings' );
		if ( ! array_key_exists( $action, hsm_tweaks_actions() ) ) {
			throw new Exception( 'Unknown action. Allowed: ' . implode( ', ', array_keys( hsm_tweaks_actions() ) ) );
		}
		if ( 'get_settings' === $action ) {
			$s = hsm_tweaks_settings();
			$s['_free_shipping_threshold'] = hsm_tweaks_free_shipping_threshold();
			return $s;
		}
		if ( 'duplicate_product' === $action ) {
			hsm_require_wc();
			$id = hsm_tweaks_duplicate_product( (int) ( $in['product_id'] ?? 0 ) );
			return array( 'action' => 'duplicate_product', 'new_product_id' => $id );
		}
		if ( 'protect_post' === $action ) {
			return hsm_tweaks_protect( (int) ( $in['post_id'] ?? 0 ), (string) ( $in['password'] ?? '' ) );
		}
		if ( 'unprotect_post' === $action ) {
			$p = get_post( (int) ( $in['post_id'] ?? 0 ) );
			if ( ! $p ) {
				throw new Exception( 'Post not found.' );
			}
			hsm_undo_add( array( 'type' => 'post', 'id' => $p->ID, 'state' => array( 'ID' => $p->ID, 'post_password' => $p->post_password ) ) );
			wp_update_post( array( 'ID' => $p->ID, 'post_password' => '' ) );
			return array( 'action' => 'unprotect_post', 'id' => $p->ID, 'protected' => false );
		}
		if ( 'post_status' === $action ) {
			$p = get_post( (int) ( $in['post_id'] ?? 0 ) );
			if ( ! $p ) {
				throw new Exception( 'Post not found.' );
			}
			return array( 'id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'status' => $p->post_status, 'protected' => '' !== (string) $p->post_password );
		}
		if ( 'cron_list' === $action ) {
			$cron = _get_cron_array() ?: array();
			$out  = array();
			foreach ( $cron as $ts => $hooks ) {
				foreach ( $hooks as $hook => $sigs ) {
					foreach ( $sigs as $sig ) {
						$out[] = array( 'hook' => $hook, 'next_run' => gmdate( 'c', $ts ), 'interval' => $sig['interval'] ?? null, 'args' => $sig['args'] ?? array() );
					}
				}
			}
			usort( $out, function ( $a, $b ) { return strcmp( $a['next_run'], $b['next_run'] ); } );
			$limit = max( 1, min( 500, (int) ( $in['limit'] ?? 100 ) ) );
			return array( 'count' => count( $out ), 'events' => array_slice( $out, 0, $limit ) );
		}
		if ( 'health' === $action ) {
			global $wpdb;
			$db_size = (float) $wpdb->get_var( $wpdb->prepare( "SELECT ROUND(SUM(data_length+index_length)/1048576,1) FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME ) );
			return array(
				'wp_version'      => get_bloginfo( 'version' ),
				'php_version'     => PHP_VERSION,
				'mysql_version'   => $wpdb->db_version(),
				'db_size_mb'      => $db_size,
				'debug_mode'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'debug_log'       => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'debug_display'   => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'object_cache'    => wp_using_ext_object_cache(),
				'environment'     => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
				'permalink_structure' => get_option( 'permalink_structure' ),
				'woocommerce'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			);
		}
		// set_settings
		$defs   = hsm_tweaks_defaults();
		$before = get_option( 'hsm_tweaks', array() );
		$incoming = (array) ( $in['settings'] ?? array() );
		if ( ! $incoming ) {
			throw new Exception( 'settings must be a non-empty object.' );
		}
		$new = $before;
		foreach ( $incoming as $key => $val ) {
			if ( ! isset( $defs[ $key ] ) ) {
				throw new Exception( 'Unknown settings key: ' . $key );
			}
			$new[ $key ] = hsm_tweaks_sanitize( $key, $val );
		}
		hsm_undo_add( array( 'type' => 'option', 'name' => 'hsm_tweaks', 'old' => $before ) );
		update_option( 'hsm_tweaks', $new );
		return array( 'action' => 'set_settings', 'changed' => array_keys( $incoming ), 'settings' => hsm_tweaks_settings() );
	},
) );

/** Validate one settings group against its defaults. */
function hsm_tweaks_sanitize( $key, $val ) {
	$def = hsm_tweaks_defaults()[ $key ];
	$val = is_array( $val ) ? $val : array();
	$out = array();
	foreach ( $def as $k => $d ) {
		if ( ! array_key_exists( $k, $val ) ) {
			$out[ $k ] = $d;
			continue;
		}
		$v = $val[ $k ];
		if ( is_bool( $d ) ) {
			$out[ $k ] = (bool) $v;
		} elseif ( is_int( $d ) ) {
			$out[ $k ] = max( 0, (int) $v );
		} elseif ( is_float( $d ) ) {
			$out[ $k ] = max( 0, (float) $v );
		} elseif ( 'checkout_fields' === $key ) {
			$out[ $k ] = in_array( $v, array( 'hidden', 'optional', 'required' ), true ) ? $v : $d;
		} elseif ( 'site_verification' === $key ) {
			$out[ $k ] = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v ), 0, 200 );
		} else {
			$out[ $k ] = sanitize_text_field( (string) $v );
		}
	}
	return $out;
}

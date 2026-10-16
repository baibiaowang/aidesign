<?php
/**
 * Plugin Name: AI Design (Storefront Look)
 * Description: Storefront look and feel an AI agent can drive: theme style parameters (colours, fonts, buttons, radius), an announcement bar, floating contact buttons and trust badges, home-page section blocks, small storefront tweaks (free-shipping bar, sale badges, maintenance mode), product compare, variation swatches, product add-ons and product video.
 * Version: 1.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Hark
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/hsm-core.php';

/** This plugin's MCP namespace (kept on the registry so the three AI plugins can co-exist). */
HSM_Registry::$ns      = 'ai-design/';
HSM_Registry::$cat     = 'ai-design';
HSM_Registry::$version = '1.1.0';

$hsm_ns  = HSM_Registry::$ns;
$hsm_pfx = str_replace( '/', '-', $hsm_ns );

require_once __DIR__ . '/includes/design.php';
require_once __DIR__ . '/includes/store-tweaks.php';
require_once __DIR__ . '/includes/home-sections.php';
require_once __DIR__ . '/includes/compare.php';
require_once __DIR__ . '/includes/swatches.php';
require_once __DIR__ . '/includes/social-proof.php';
require_once __DIR__ . '/includes/product-addons.php';
require_once __DIR__ . '/includes/product-video.php';

/** Ability category. */
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'ai-design', array(
		'label'       => 'AI Design',
		'description' => 'Storefront look and feel for AI agents.',
	) );
} );

/** Register a dedicated MCP server exposing only this plugin's abilities. */
add_action( 'mcp_adapter_init', function ( $adapter ) use ( $hsm_ns ) {
	$tools = array_values( array_filter( array_keys( HSM_Registry::$abilities ), function ( $n ) use ( $hsm_ns ) {
		return 0 === strpos( $n, $hsm_ns );
	} ) );
	$adapter->create_server(
		'ai-design',
		'ai-design',
		'mcp',
		'AI Design',
		'Storefront look and feel: design, sections, swatches, compare, add-ons.',
		HSM_Registry::$version,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		null,
		$tools,
		array(),
		array(),
		'hsm_transport_permission'
	);
} );

if ( ! function_exists( 'hsm_transport_permission' ) ) {
	/** HTTP transport gate: only staff who can use at least one tool may open an MCP session. */
	function hsm_transport_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		foreach ( array( 'manage_woocommerce', 'manage_options', 'edit_posts', 'upload_files' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}

/** tools/list shows only the tools the current user is allowed to call (HTTP and STDIO). */
add_filter( 'mcp_adapter_tools_list', function ( $tools, $server = null ) use ( $hsm_ns, $hsm_pfx ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return $tools;
	}
	return array_values( array_filter( (array) $tools, function ( $t ) use ( $hsm_ns, $hsm_pfx ) {
		$name = is_object( $t ) && method_exists( $t, 'getName' ) ? $t->getName() : ( is_array( $t ) ? ( $t['name'] ?? '' ) : '' );
		if ( 0 !== strpos( $name, $hsm_pfx ) ) {
			return true;
		}
		$ability = wp_get_ability( $hsm_ns . substr( $name, strlen( $hsm_pfx ) ) );
		if ( ! $ability ) {
			return true;
		}
		return true === $ability->check_permissions( array() );
	} ) );
}, 10, 2 );

register_activation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

/** After an upgrade, register new rewrite rules once. */
$hsm_ver = HSM_Registry::$version;
add_action( 'init', function () use ( $hsm_ver ) {
	if ( get_option( 'ai_design_version' ) !== $hsm_ver ) {
		flush_rewrite_rules( false );
		update_option( 'ai_design_version', $hsm_ver );
	}
}, 99 );

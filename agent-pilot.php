<?php
/**
 * Plugin Name: Agent Pilot
 * Description: Publish Agent Skills from WordPress.
 * Author: WP Elevator
 * Author URI: https://wpelevator.com
 * Version: 0.1.1
 * Update URI: https://updates.wpelevator.com/wp-json/update-pilot/v1/plugins
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Text Domain: wpelevator-agent-pilot
 */

namespace WPElevator\Agent_Pilot;

// Ensure WP core is loaded.
if ( ! function_exists( 'add_action' ) ) {
	return;
}

if ( is_readable( __DIR__ . '/vendor-isolated/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-isolated/vendor/autoload.php';
}

// Only if there is no project autoloader that knows about us.
if ( ! class_exists( Plugin::class ) && file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

function plugin(): Plugin {
	static $plugin;

	if ( ! isset( $plugin ) ) {
		$plugin = new Plugin( __FILE__ );
	}

	return $plugin;
}

add_action( 'plugins_loaded', [ plugin(), 'init' ] );

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

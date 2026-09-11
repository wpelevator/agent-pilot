<?php
/**
 * Plugin Name: Agent Pilot Automatic Updates
 * Description: For WordPress multisite only: activate on the main site of the network to enable automatic updates for Agent Pilot plugin without having to network-enable the plugin.
 * Author: WP Elevator
 * Author URI: https://wpelevator.com
 * Update URI: https://updates.wpelevator.com/wp-json/update-pilot/v1/plugins
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Network: true
 *
 * @see https://wpelevator.com/plugins/update-pilot
 */

namespace WPElevator\Agent_Pilot;

use WPElevator\Agent_Pilot_Vendor\WPElevator\Update_Client\Plugin_Update;

if ( ! function_exists( 'add_filter' ) ) {
	return; // Ensure WP core is loaded.
}

if ( is_readable( __DIR__ . '/vendor-isolated/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-isolated/vendor/autoload.php';
}

add_action(
	'init',
	function (): void {
		if ( class_exists( \WPElevator\Update_Pilot\Plugin::class ) ) {
			return; // Skip if Update Pilot is already present and handling the update.
		}

		$update = new Plugin_Update(
			plugin_basename( __DIR__ . '/agent-pilot.php' ),
			'https://updates.wpelevator.com/wp-json/update-pilot/v1/plugins',
			[
				'signing_key' => 'E8AgVyLHxvnyXgB7sqge9Jp9Eo4eAQc+8gfC1KU90iI=',
				'license_key' => null, // TODO: populate it from plugin data.
			]
		);

		$update->init();
	}
);

add_filter(
	'update_pilot__plugins',
	function ( array $plugins ): array {
		$plugins[] = [
			'plugin' => plugin_basename( __DIR__ . '/agent-pilot.php' ),
			'signing_key' => 'E8AgVyLHxvnyXgB7sqge9Jp9Eo4eAQc+8gfC1KU90iI=',
			'license_key' => null, // TODO: forward the setting, if present.
		];

		return $plugins;
	}
);

add_filter(
	'plugins_list',
	function ( array $plugins ): array {
		$basename = plugin_basename( __FILE__ );

		if ( ! is_multisite() ) {
			foreach ( $plugins as &$plugin_list ) {
				unset( $plugin_list[ $basename ] ); // Hide this auto-update plugin on single sites to avoid confusion.
			}
		}

		return $plugins;
	}
);

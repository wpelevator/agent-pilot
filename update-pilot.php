<?php
/**
 * Integrate with Update Pilot even when the Agent Pilot
 * is not enabled on the main site of the WP multisite
 * where the update checks are performed.
 */

namespace WPElevator\Agent_Pilot;

use WPElevator\Agent_Pilot_Vendor\WPElevator\Update_Client\Plugin_Require;

if ( ! function_exists( 'add_filter' ) ) {
	return; // Ensure WP core is loaded.
}

if ( is_readable( __DIR__ . '/vendor-isolated/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-isolated/vendor/autoload.php';
}

add_action(
	'init',
	function () {
		$require = new Plugin_Require(
			[
				'notice' => __( 'Agent Pilot requires the Update Pilot plugin for automatic updates.', 'wpelevator-agent-pilot' ),
			]
		);

		$require->init();
	}
);

add_filter(
	'update_pilot__plugins',
	function ( array $plugins ): array {
		$plugins[] = [
			'file' => plugin_basename( __DIR__ . '/agent-pilot.php' ),
			'license_key' => null,
			'signing_key' => null, // TODO: populate this once we sign the releases.
		];

		return $plugins;
	}
);

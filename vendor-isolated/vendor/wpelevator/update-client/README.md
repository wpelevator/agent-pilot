# Update Pilot Client Library

Enable updates for your WordPress plugins and themes from a custom update server like [WP Elevator Update Server](https://wpelevator.com/plugins/update-pilot-server).

## Usage

To check for updates for plugins and themes hosted outside of the WordPress.org repository, the plugin doing the update check must be active. Importantly, in WordPress multisites the plugin must be active on the main network site as the updates happen only there.

This library enables the following update workflows:

1. Require a dedicated updater plugin to be installed and active (such as [Update Pilot](https://wpelevator.com/plugins/update-pilot)). Best approach since it enables network-only instalation which is decoupled from the plugin activation state.

2. Bundle the updater functionality with your plugin. Requires the plugin to be active (also on the main network site on multisite) to check for updates. Possible to move the updater logic into a separate plugin file within your plugin (yes, WordPress does support this) to enable the updater logic even when the core plugin is selectively disabled.

### Option 1: Require the Updater Plugin

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version: 1.0.0
 * Update URI: https://example.com/to/prevent/wporg/update-checks
 */

// Your existing plugin bootstrap code here...

$update_notice = new WPElevator\Update_Client\Plugin_Require(
	[
		'download_url' => 'https://updates.wpelevator.com/wp-json/update-pilot/v1/download/wpelevator/update-pilot',
		'basename' => 'update-pilot/update-pilot.php',
		'name' => 'Update Pilot',
		'notice' => 'The Update Pilot plugin is required to enable updates to Your Plugin.', // "Install" or "Activate" button is appended to this notice.
		'network' => true,
	]
);

add_action( 'plugins_loaded', [ $update_notice, 'init' ] );
```

### Option 2: Bundle the Updater

Register your plugin with the update server by adding the following to the main plugin file:

```php
<?php
/**
 * Plugin Name: Your Plugin
 * Version: 1.0.0
 * Update URI: https://example.com/to/prevent/wporg/update-checks
 */

// Your existing plugin bootstrap code here...

$plugin_update = new WPElevator\Update_Client\Plugin_Update(
	plugin_basename( __FILE__ ),
	'https://updates.example.com/wp-json/update-pilot/v1/plugins'
);

add_action( 'plugins_loaded', [ $plugin_update, 'init' ] );
```

The update URL must implement the [WordPress plugin update API endpoint](https://wpelevator.com/guides/replace-wordpress-update-apis). The client posts the current plugin headers as a `plugins` JSON payload and returns the matching update object for the configured plugin basename. It also handles the WordPress plugin information modal through the same endpoint.

## TODO

- Document the namespace isolation.

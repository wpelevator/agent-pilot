<?php

namespace WPElevator\Agent_Pilot_Tests;

use WP_REST_Request;
use WPElevator\Agent_Pilot\MCP\Settings;
use WPElevator\Agent_Pilot\Plugin;

require_once __DIR__ . '/class-mcp-test-case.php';

class MCP_Settings_Test extends MCP_Test_Case {

	private Settings $settings;

	public function set_up() {
		parent::set_up();
		$this->settings = new Settings();
		$this->settings->register();
	}

	public function test_exclusions_default_to_empty_and_sanitize_form_values() {
		$this->assertSame( [], $this->settings->get_disabled_abilities(), 'Existing sites should keep all eligible ability mappings by default.' );

		$option = $this->settings->get_option_name( 'mcp_disabled_abilities' );
		update_option( $option, [ '', 'test/ability', 'test/ability', '<script>', [ 'test/nested' ], 1, 'test/another' ] );

		$this->assertSame( [ 'test/ability', 'test/another' ], $this->settings->get_disabled_abilities(), 'Saving the form should retain unique valid names and discard the hidden empty value and malformed entries.' );

		update_option( $option, [ '' ] );
		$this->assertSame( [], $this->settings->get_disabled_abilities(), 'Unchecking every checkbox should clear previously saved exclusions.' );
	}

	public function test_administrator_can_update_exclusions_through_rest_settings() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$option = $this->settings->get_option_name( 'mcp_disabled_abilities' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( [ $option => [ 'agent-pilot/rest-call' ] ] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'Administrators should be able to update the registered array setting.' );
		$this->assertSame( [ 'agent-pilot/rest-call' ], $response->get_data()[ $option ], 'REST settings should return the saved exclusions.' );

		$request->set_body_params( [ $option => [ [ 'nested' => 'invalid' ] ] ] );
		$this->assertSame( 400, rest_do_request( $request )->get_status(), 'The REST array item schema should reject non-string entries.' );
		$this->assertSame( [ 'agent-pilot/rest-call' ], $this->settings->get_disabled_abilities(), 'Invalid REST updates must preserve existing exclusions.' );
	}

	public function test_subscriber_cannot_change_exclusions() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( [ $this->settings->get_option_name( 'mcp_disabled_abilities' ) => [ 'agent-pilot/rest-call' ] ] );

		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'Changing MCP exclusions should require the normal settings capability.' );
		$this->assertSame( [], $this->settings->get_disabled_abilities(), 'An unauthorized update must not disable any ability.' );
	}

	public function test_settings_form_keeps_saved_exclusions_visible() {
		$this->register_ability( 'agent-pilot-test/disabled' );
		$option = $this->settings->get_option_name( 'mcp_disabled_abilities' );
		update_option( $option, [ 'agent-pilot-test/disabled', 'inactive-plugin/ability' ] );

		ob_start();
		( new Plugin( __FILE__ ) )->render_settings_page();
		$html = ob_get_clean();
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( $html );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$xpath = new \DOMXPath( $document );

		foreach ( [ 'agent-pilot-test/disabled', 'inactive-plugin/ability' ] as $name ) {
			$inputs = $xpath->query( '//input[@type="checkbox" and @name="' . $option . '[]" and @value="' . $name . '" and @checked]' );
			$this->assertSame( 1, $inputs->length, 'Saved exclusions must remain checked and editable, including when the provider is unavailable.' );
		}

		$this->assertSame( 1, $xpath->query( '//input[@type="hidden" and @name="' . $option . '[]" and @value=""]' )->length, 'The form needs an empty value so clearing all checkboxes persists.' );
	}
}

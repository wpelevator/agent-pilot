<?php

namespace WPElevator\Agent_Pilot;

use RuntimeException;
use WP_Post;

class Agent_Plugin_Discovery {

	public const QUERY_VAR_PLUGIN_FILE = 'agent_pilot_plugin_file';

	private Agent_Plugins $plugins;
	private Response_Emitter $emitter;

	public function __construct( Agent_Plugins $plugins, Response_Emitter $emitter ) {
		$this->plugins = $plugins;
		$this->emitter = $emitter;
	}

	public function init(): void {
		add_action( 'init', [ $this, 'action_add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'filter_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'action_serve_file' ], 0 );
	}

	public function action_add_rewrite_rules(): void {
		add_rewrite_rule(
			'^' . Plugin::PERMALINK_PREFIX_AGENT_PLUGIN . '/([^/]+)/(plugin\.json|mcp\.json|plugin\.zip)$',
			'index.php?post_type=' . Plugin::POST_TYPE_AGENT_PLUGIN . '&' . Plugin::PERMALINK_PREFIX_AGENT_PLUGIN . '=$matches[1]&' . self::QUERY_VAR_PLUGIN_FILE . '=$matches[2]',
			'top'
		);
	}

	public function filter_query_vars( array $query_vars ): array {
		$query_vars[] = self::QUERY_VAR_PLUGIN_FILE;

		return $query_vars;
	}

	private function get_file_url( Agent_Plugin $plugin, string $file ): string {
		$permalink = $plugin->get_permalink();

		if ( false !== strpos( $permalink, '?' ) ) {
			return add_query_arg( [ self::QUERY_VAR_PLUGIN_FILE => $file ], $permalink );
		}

		return rtrim( $permalink, '/' ) . '/' . $file;
	}

	public function get_plugin_json_url( Agent_Plugin $plugin ): string {
		return $this->get_file_url( $plugin, 'plugin.json' );
	}

	public function get_mcp_json_url( Agent_Plugin $plugin ): ?string {
		return $plugin->get_mcp_json() ? $this->get_file_url( $plugin, 'mcp.json' ) : null;
	}

	public function get_plugin_zip_url( Agent_Plugin $plugin ): string {
		return $this->get_file_url( $plugin, 'plugin.zip' );
	}

	public function get_plugin_zip_file( Agent_Plugin $plugin ): string {
		if ( ! Zip_File::is_supported() ) {
			throw new RuntimeException( __( 'ZipArchive class is not available.', 'wpelevator-agent-pilot' ) );
		}

		$file = get_temp_dir() . '/agent-plugin-v1-' . $plugin->get_hash() . '.zip';
		if ( is_readable( $file ) ) {
			return $file;
		}

		$zip = new Zip_File( $file, $plugin->get_files() );

		return $zip->get_file( $plugin->get_last_modified() );
	}

	public function action_serve_file(): void {
		$format = get_query_var( self::QUERY_VAR_PLUGIN_FILE );
		if ( ! in_array( $format, [ 'plugin.json', 'mcp.json', 'plugin.zip' ], true ) ) {
			return;
		}

		$object = get_queried_object();
		$name = (string) get_query_var( Plugin::PERMALINK_PREFIX_AGENT_PLUGIN );
		$plugin = $object instanceof WP_Post && Plugin::POST_TYPE_AGENT_PLUGIN === $object->post_type
			? new Agent_Plugin( $object, plugin()->get_skills() )
			: $this->plugins->get_public_plugin( $name );

		if ( ! $plugin || ( ! $plugin->is_published() && ! current_user_can( 'read_post', $plugin->get_id() ) ) || ! $plugin->is_valid( $plugin->is_published() ) ) {
			return;
		}

		$headers = $plugin->is_published() ? [] : wp_get_nocache_headers();

		if ( 'plugin.json' === $format ) {
			$this->emitter->send( Response::as_json( 200, json_decode( $plugin->get_plugin_json(), true ), $headers ) );
		}

		if ( 'mcp.json' === $format ) {
			$json = $plugin->get_mcp_json();
			if ( ! $json ) {
				$this->emitter->send( Response::not_found() );
			}
			$this->emitter->send( Response::as_json( 200, json_decode( $json, true ), $headers ) );
		}

		$this->emitter->send( Response::as_file( 200, $this->get_plugin_zip_file( $plugin ), $plugin->get_name() . '.zip', $headers ) );
	}
}

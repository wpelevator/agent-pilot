<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\MCP\Json_Rpc;
use WPElevator\Agent_Pilot\MCP\Resources;
use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\Skills;

require_once __DIR__ . '/class-mcp-test-case.php';

class MCP_Resources_Test extends MCP_Test_Case {

	public function set_up() {
		parent::set_up();

		Plugin::action_register_post_type();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_the_server_advertises_resources() {
		$response = $this->post( $this->message( 'initialize', [ 'protocolVersion' => '2025-06-18' ] ) );
		$capabilities = $response->get_data()['result']['capabilities'];

		$this->assertArrayHasKey( 'resources', $capabilities, 'A server that answers resource requests has to declare the capability.' );
		$this->assertFalse( $capabilities['resources']['subscribe'], 'There is no stream to deliver resource updates on.' );
	}

	public function test_every_packaged_file_is_listed_as_a_resource() {
		$this->create_skill( 'code-review', 'publish' );

		$resources = $this->get_resources();
		$paths = wp_list_pluck( $resources, 'uri' );

		$this->assertContains( 'agent-pilot://skills/code-review/SKILL.md', $paths, 'A skill should publish its generated Markdown as a resource.' );
		$this->assertContains( 'agent-pilot://skills/code-review/assets/notes.txt', $paths, 'A skill should publish its assets as resources, which is the only way an agent reads them over this connection.' );
		$this->assertContains( 'agent-pilot://plugins/example-plugin/plugin.json', $paths, 'An Agent Plugin should publish its manifest as a resource.' );
	}

	public function test_reading_a_package_answers_with_every_file_it_publishes() {
		$this->create_skill( 'code-review', 'publish' );

		$listed = wp_list_pluck( $this->get_resources(), 'uri' );
		$contents = $this->read_all( 'agent-pilot://skills/code-review' );

		$this->assertContains( 'agent-pilot://skills/code-review', $listed, 'A package should be addressable as a whole, so that a client can take it in one round trip.' );
		$this->assertSame(
			[
				'agent-pilot://skills/code-review/SKILL.md',
				'agent-pilot://skills/code-review/assets/notes.txt',
				'agent-pilot://skills/code-review/assets/diagram.png',
			],
			wp_list_pluck( $contents, 'uri' ),
			'Reading a package should answer with its files rather than with an archive the client would have to unpack.'
		);
		$this->assertArrayHasKey( 'text', $contents[0], 'Each file should be carried the way it would be on its own, so text stays text.' );
		$this->assertSame( self::get_png(), base64_decode( $contents[2]['blob'] ), 'Bytes that are not text should still be encoded, file by file.' );
	}

	public function test_an_unpublished_package_is_readable_whole_by_a_caller_who_can_read_it() {
		$this->create_skill( 'draft-skill', 'draft' );

		$contents = $this->read_all( 'agent-pilot://skills/draft-skill' );

		$this->assertContains( 'agent-pilot://skills/draft-skill/SKILL.md', wp_list_pluck( $contents, 'uri' ), 'A draft is served over HTTP from a preview link an access token cannot follow, which is the reason to carry it here.' );
	}

	public function test_a_generated_file_is_read_as_text() {
		$this->create_skill( 'code-review', 'publish' );

		$contents = $this->read( 'agent-pilot://skills/code-review/SKILL.md' );

		$this->assertSame( 'text/markdown', $contents['mimeType'], 'A generated Markdown file should be named as Markdown rather than left unknown.' );
		$this->assertStringContainsString( 'name: code-review', $contents['text'], 'Text should be carried as text rather than encoded.' );
		$this->assertArrayNotHasKey( 'blob', $contents, 'Text should not also be sent as a blob.' );
	}

	public function test_an_upload_that_is_not_text_is_read_as_a_base64_blob() {
		$this->create_skill( 'code-review', 'publish' );

		$contents = $this->read( 'agent-pilot://skills/code-review/assets/diagram.png' );

		$this->assertSame( 'image/png', $contents['mimeType'], 'An uploaded file should be carried under the type it was uploaded as.' );
		$this->assertSame( self::get_png(), base64_decode( $contents['blob'] ), 'Bytes that are not valid UTF-8 can only cross the protocol encoded, and they reach the client through this connection rather than as a URL it may not be able to fetch.' );
		$this->assertArrayNotHasKey( 'text', $contents, 'Binary contents should not also be sent as text.' );
	}

	public function test_an_upload_that_is_text_is_read_as_text() {
		$this->create_skill( 'code-review', 'publish' );

		$contents = $this->read( 'agent-pilot://skills/code-review/assets/notes.txt' );

		$this->assertSame( 'text/plain', $contents['mimeType'], 'An uploaded file should be carried under the type it was uploaded as.' );
		$this->assertSame( "Asset bytes\n", $contents['text'], 'Whether contents are encoded follows the media type rather than where the file came from, so a text upload is carried as text.' );
	}

	public function test_a_package_the_caller_cannot_read_is_not_addressable() {
		$this->create_skill( 'draft-skill', 'draft' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->post(
			$this->message(
				'resources/read',
				[ 'uri' => 'agent-pilot://skills/draft-skill/SKILL.md' ],
				2
			)
		);
		$error = $response->get_data()['error'];

		$this->assertEmpty( $this->get_resources(), 'A caller who may not read a draft should not see its files listed.' );
		$this->assertSame( Json_Rpc::INVALID_PARAMS, $error['code'], 'An unreadable resource should be refused the way an unknown one is, which is the code the specification names.' );
	}

	public function test_an_unknown_uri_is_an_invalid_params_error() {
		$response = $this->post(
			$this->message(
				'resources/read',
				[ 'uri' => 'agent-pilot://skills/no-such-skill/SKILL.md' ],
				3
			)
		);
		$error = $response->get_data()['error'];

		$this->assertSame( Json_Rpc::INVALID_PARAMS, $error['code'], 'The specification names -32602 for a resource that does not exist.' );
		$this->assertArrayNotHasKey( 'contents', $response->get_data()['result'] ?? [], 'An empty contents array would be ambiguous, so there must be none.' );
	}

	private function get_resources(): array {
		$response = $this->post( $this->message( 'resources/list', [], 4 ) );

		return $response->get_data()['result']['resources'];
	}

	private function read( string $uri ): array {
		return $this->read_all( $uri )[0];
	}

	private function read_all( string $uri ): array {
		$response = $this->post( $this->message( 'resources/read', [ 'uri' => $uri ], 5 ) );

		return $response->get_data()['result']['contents'];
	}

	/**
	 * The smallest valid PNG, whose bytes are not valid UTF-8.
	 */
	private static function get_png(): string {
		return (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
	}

	private function create_skill( string $name, string $status ): int {
		$text_upload = wp_upload_bits( 'agent-pilot-notes.txt', null, "Asset bytes\n" );
		$text_id = self::factory()->attachment->create_object(
			$text_upload['file'],
			0,
			[ 'post_mime_type' => 'text/plain' ]
		);
		$image_upload = wp_upload_bits( 'agent-pilot-diagram.png', null, self::get_png() );
		$image_id = self::factory()->attachment->create_object(
			$image_upload['file'],
			0,
			[ 'post_mime_type' => 'image/png' ]
		);
		$skill_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
				'post_name' => $name,
				'post_status' => $status,
				'post_content' => sprintf(
					'%s%s',
					'<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill"><!-- wp:paragraph --><p>Do the thing.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill -->',
					sprintf(
						'<!-- wp:agent-pilot/agent-skill-asset {"attachmentId":%1$d,"fileName":"notes.txt"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset --><!-- wp:agent-pilot/agent-skill-asset {"attachmentId":%2$d,"fileName":"diagram.png"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->',
						$text_id,
						$image_id
					)
				),
			]
		);
		self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_PLUGIN,
				'post_name' => 'example-plugin',
				'post_status' => $status,
				'post_content' => wp_slash( '<!-- wp:agent-pilot/agent-plugin --><div class="wp-block-agent-pilot-agent-plugin"><!-- wp:agent-pilot/agent-plugin-mcp-server {"name":"example","definition":"{\"type\":\"streamable-http\",\"url\":\"https://example.com/mcp\"}"} /--></div><!-- /wp:agent-pilot/agent-plugin -->' ),
			]
		);

		return $skill_id;
	}
}

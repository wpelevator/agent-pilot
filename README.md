# Agent Pilot

Agent Pilot lets WordPress authors create [Agent Skills](https://agentskills.io/specification) in the block editor and publish them through the [Agent Skills Discovery via Well-Known URIs](https://github.com/cloudflare/agent-skills-discovery-rfc) v0.2.0 draft and [Agent Plugins](https://agent-plugins.org/specification). Skills are standalone instructions discovered through the well-known index while plugins are packages that compose Skills and optional MCP server configuration.

## Requirements

- WordPress 6.6 or newer, or 6.9 or newer for the MCP server, which needs the Abilities API.
- PHP 7.4 or newer.
- PHP `zip` extension for archive generation.
- Update Pilot is used for automatic plugin updates. Agent Pilot shows an admin notice when Update Pilot is unavailable.

## Installing Agent Skills

Publish one or more Agent Skills, then install them with the [`skills` CLI](https://www.npmjs.com/package/skills). Replace `https://example.com` with the WordPress site URL.

```bash
# Inspect available skills.
npx skills add https://example.com --list

# Install selected skills in the current project.
npx skills add https://example.com

# Install skills globally instead of in the current project.
npx skills add https://example.com --global
```

The CLI reads:

```text
https://example.com/.well-known/agent-skills/index.json
```

It prompts for skills to install unless options such as `--agent`, `--skill`, or `--yes` are supplied.

Update installed skills after changing their WordPress content:

```bash
# Update all installed project skills.
npx skills update --project

# Update all installed global skills.
npx skills update --global

# Update one skill by name.
npx skills update my-skill
```

## Authoring Skills

Agent Skills are normal WordPress posts with a dedicated post type:

- Post type: `agent_skill`.
- Public permalink prefix: `/agent-skill/{name}`.
- Plain permalink fallback: `?agent-skill={name}`.
- Supports title, editor, excerpt, author, revisions, and custom fields.

The editor uses a locked `agent-pilot/agent-skill` wrapper block. New skills start with one instruction paragraph inside that wrapper.

The skill front matter fields are edited inside the Agent Skill block:

- `name` is saved as the post slug.
- `description` is saved as the native post excerpt.
- `compatibility` is saved as `agent_pilot__compatibility` post meta.

The editor marks the name, description, and instructions as required. Descriptions are limited to 1024 characters, and compatibility notes are limited to 500 characters.

Skill instructions can use the blocks that Agent Pilot can convert to Markdown:

- Paragraphs, headings, lists, code, preformatted text, quotes, separators, and images.
- Image blocks export as Markdown images using the saved image URL and alt text.
- Agent Pilot resource blocks: Reference, Script, and Asset.

Use the Add Reference, Add Script, and Add File buttons below the instructions to append resource blocks. Once a resource filename is configured, the resource panel header shows the path to use in instructions, such as `references/guide.md`, `scripts/build.sh`, or `assets/diagram.png`.

## Resources

Resources are stored in the skill post content, packaged into the skill ZIP, and listed in `SKILL.md`. Resource contents are not inlined into `SKILL.md`. Resource filenames are normalized with the WordPress `sanitize_file_name()` helper during packaging, so directory separators and path traversal sequences never reach the generated archive.

### References

Reference blocks publish supporting documents under `references/`.

- Filenames use lowercase letters and hyphens.
- Formats are `.md` and `.html`.
- New references default to `.md`.
- Custom reference content can be authored with nested blocks.
- A reference can also use an existing post, page, or REST-searchable custom post type selected through the combobox.
- When existing content is selected, it takes precedence over the custom reference content. The custom content remains saved and is used again if the selection is removed.
- Markdown references convert supported blocks to Markdown. HTML references publish rendered block HTML.

### Scripts

Script blocks publish executable or helper text files under `scripts/`.

- Filenames may contain ASCII letters, numbers, dots, underscores, and hyphens.
- Filenames must begin with a letter or number and cannot contain slashes.
- The editor shows the `scripts/` prefix without storing it in the filename.
- Script content is edited with bundled CodeMirror syntax highlighting based on the filename extension.

### Assets

Asset blocks publish Media Library attachment bytes under `assets/`.

- Assets require an attachment.
- The selected attachment filename is saved as the asset filename.
- The editor shows the `assets/` prefix without storing it in the filename.
- Replacing a selected asset happens from the block toolbar.

## Generated Output

Agent Pilot generates `SKILL.md` from:

- Front matter:
  - `name` from the post slug.
  - `description` from the post excerpt.
  - `compatibility` from `agent_pilot__compatibility`.
  - Values are emitted as plain YAML scalars and quoted only when required.
- Body:
  - A top-level heading from the post title.
  - Supported instruction blocks converted to Markdown.
  - `References`, `Assets`, and `Scripts` sections listing packaged resource paths when valid resources exist.

The editor sidebar includes an Agent Skill panel with links to the generated `SKILL.md` file and `SKILL.zip` archive after the post has a usable skill URL.

On human-facing single skill pages, the Agent Skill block renders the generated Markdown inside an escaped `<pre>` block so visitors can inspect the exact output.

## Discovery and Routing

Only published skills appear in public discovery. Draft and private skills stay hidden from the public index and public file routes, while normal authenticated WordPress previews still work.

The discovery index is served at:

```text
/.well-known/agent-skills/index.json
```

Each index entry currently publishes an archive:

- `type`: `archive`
- `url`: `/agent-skill/{name}/skill.zip`
- `digest`: SHA-256 digest of the served ZIP bytes
- `files`: contains `SKILL.md` for compatibility with clients that require a non-empty file list

Pretty permalink artifact routes:

```text
/agent-skill/{name}/skill.md
/agent-skill/{name}/skill.zip
```

Plain permalink artifact routes:

```text
?agent-skill={name}&agent_pilot_skill_format=skill.md
?agent-skill={name}&agent_pilot_skill_format=skill.zip
```

The generated ZIP represents the skill directory contents. Agent Pilot writes `SKILL.md` at the archive root, with valid supporting files under root-level `references/`, `scripts/`, and `assets/` directories. Archive entries reuse the skill last modified time, so an unchanged skill regenerates into byte-identical archives with a stable digest (on PHP 8.0 or newer).

Discovery index and artifact responses support `GET` and `HEAD`, include CORS and `X-Content-Type-Options` headers, and use ETags. WordPress front-end responses advertise the discovery index with an RFC 8288 `Link` header, while REST API responses do not include it:

```text
Link: <https://example.com/.well-known/agent-skills/index.json>; rel="agent-skills"
```

The raw artifact rewrite only accepts a one-segment format suffix such as `skill.md` or `skill.zip`, so nested paths such as `/agent-skill/{name}/references/guide.md` stay with WordPress. Other one-segment suffixes under a skill permalink, including `/feed` and `/embed`, are claimed by the same rewrite and resolve to the single skill view instead of the WordPress feed and embed endpoints.

## Authoring Agent Plugins

Create an **Agent Plugin** post, enter its manifest details in the top-level block, then insert Plugin Skill and MCP Server child blocks. Skills are stored by post ID and are included live: changing a selected skill changes the next generated plugin archive without re-saving the plugin.

The manifest supports `name` (the post slug), `description` (the excerpt), `author`, `license`, and `extensions`. A package needs at least one selected skill or valid MCP server. Published packages may only select published skills. A Plugin Skill block with nothing selected yet is ignored rather than treated as an error, so an empty block left open in the editor never breaks the generated artifacts; a block pointing at a post that is not an Agent Skill still fails validation.

Each MCP server uses a name plus a raw JSON object edited with JSON syntax highlighting. Agent Pilot preserves the object without validating its transport or schema; authors are responsible for supplying configuration that their target MCP client accepts. MCP definitions are public package contents—never put credentials or tokens in them.

Agent Plugin artifacts are available for a valid published plugin at:

```text
/agent-plugin/{name}/plugin.json
/agent-plugin/{name}/mcp.json
/agent-plugin/{name}/plugin.zip
```

The editor sidebar includes an Agent Plugin panel linking to every routed artifact: `plugin.json`, `mcp.json` when available, and `plugin.zip`.

With plain permalinks, use `?agent-plugin={name}&agent_pilot_plugin_format=plugin.json` (or `mcp.json` / `plugin.zip`). Draft previews require permission to read every referenced post and are never publicly cacheable. The ZIP is a distribution convenience: the specification defines the extracted directory layout (`plugin.json`, `skills/{name}/…`, and optional `mcp.json`), not an installation or distribution protocol.

## MCP Server

Agent Pilot can also serve this site's [WordPress Abilities](https://developer.wordpress.org/apis/abilities-api/) as [MCP](https://modelcontextprotocol.io/) tools, so that an agent client can call site functionality directly instead of only reading published instructions. This is separate from the MCP server *definitions* an Agent Plugin carries: those point a client at some other server, while this one is served by WordPress itself.

The server is opt-in. Enable it under **Settings → Agent Pilot**, where the endpoint URL is also shown:

```text
https://example.com/wp-json/agent-pilot/v1/mcp
```

Requires WordPress 6.9 or newer for the Abilities API. Agent Pilot shows a notice on the settings screen when the API is unavailable.

### Disabling MCP abilities

Under **Settings → Agent Pilot → Abilities**, select the abilities to exclude and save. Selected abilities disappear from `tools/list` and cannot be called by name through `tools/call`, even by a client that previously discovered them. Clear a checkbox to restore the ability's normal mapping. No abilities are disabled by default.

The list shows abilities eligible for MCP mapping and any saved exclusions whose provider is currently unavailable. Exclusions apply after the `agent_pilot__mcp_abilities` query filter, so customizing the query does not re-enable a disabled ability. This setting controls Agent Pilot's tool mapping; the abilities and REST endpoints remain available through their normal interfaces.

The setting is stored per site as `agent_pilot__mcp_disabled_abilities`, an array of ability names such as `agent-pilot/rest-call`. Administrators can also update it through `/wp/v2/settings`; send an empty array to clear all exclusions.

### Built-in REST ability

Agent Pilot registers `agent-pilot/rest-call` on WordPress 6.9 or newer and exposes it as the `agent-pilot.rest-call` MCP tool. Enable the MCP server to let authenticated clients discover and call this site's REST endpoints without registering an ability for each route. The ability is also compatible with the official WordPress MCP Adapter.

Pass `method`, `route` (an internal path without a query string), and optional `params`:

```json
{ "method": "GET", "route": "/wp/v2/posts", "params": { "per_page": 5 } }
```

Supported methods are `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, and `OPTIONS`. Parameters become query parameters for `GET`, `HEAD`, and `DELETE`, and body parameters for other methods. Calls run internally as the authenticated WordPress user. The ability's permission callback matches the REST endpoint, prepares its URL parameters, defaults, and sanitized input, then checks the endpoint's permissions before execution. Permission denials are ability errors; the endpoint callback is never executed by the permission check. Normal REST dispatch checks permissions again when executing an allowed call. Results contain `status`, `headers`, and `data`, including native REST validation and routing errors such as 400 and 404.

`OPTIONS <route>` returns one route's methods and parameter schema and is the cheap way to learn an endpoint. `GET /` returns the whole index, which is around 230 KB on a stock site because it carries every route's `args`; narrowing it with `_fields` is what makes it usable, and `_fields=namespaces` answers in about 140 bytes. Route keys in the index are the registered patterns rather than templates, so `/wp/v2/posts/(?P<id>[\d]+)` is called as `/wp/v2/posts/123`.

Because requests are dispatched internally rather than served over HTTP, they never pass through the `rest_post_dispatch` filters. Agent Pilot applies the two core behaviors that shape a response — `_fields` filtering and the `Allow` header — and resolves `_embed` from the request rather than from the query string, so those work as they do over HTTP. Anything else a site hooks to `rest_post_dispatch` for its HTTP responses does not run here.

Because this single tool can modify and delete content, it requires `wp:write` for OAuth callers, including when making a `GET` request. It is annotated as potentially destructive and non-idempotent. The MCP server's existing ability query and registration filters also apply to this built-in ability.

### Built-in skill and plugin abilities

Agent Pilot registers four read-only abilities on WordPress 6.9 or newer, so that a client can find and read what this site publishes without being told its routes first:

| Ability | MCP tool | Answers |
| --- | --- | --- |
| `agent-pilot/list-agent-skills` | `agent-pilot.list-agent-skills` | Every skill the caller may see, with its description, compatibility, status, packaged file paths and archive URL. |
| `agent-pilot/get-agent-skill` | `agent-pilot.get-agent-skill` | One skill by name, with the contents of its generated `SKILL.md` or of a named file. |
| `agent-pilot/list-agent-plugins` | `agent-pilot.list-agent-plugins` | Every Agent Plugin the caller may see, with the skills and MCP servers it bundles and any validation errors. |
| `agent-pilot/get-agent-plugin` | `agent-pilot.get-agent-plugin` | One Agent Plugin by name, with the contents of its `plugin.json` or of a named file. |

They answer with the generated artifacts rather than with the post content behind them. A skill is authored as blocks, so its post content is an editor document that no agent can act on, while `SKILL.md`, the files packaged beside it, `plugin.json` and `mcp.json` are the ones the specifications define. Pass a path from an item's own `files` list as the `file` argument to read one of them. An asset is attachment bytes rather than text, so it is answered with the URL to download it from instead of being inlined into a result.

A skill is installed from the archive its `package_url` points at rather than from `SKILL.md`, because a skill that has references, scripts or assets is only complete as an archive: the Markdown lists those files but does not carry them. An Agent Plugin publishes its own `package_url` the same way.

Throughout, *package* is the generated archive a client installs, while the two things that generate one are always named as the specifications name them: an Agent Skill and an Agent Plugin.

Visibility follows the site rather than a capability of its own. A published skill or Agent Plugin is readable by anyone, because the same content is already served without authentication from its permalink and from the discovery index, and everything else falls back to the capabilities WordPress maps for the post type — the same rule the discovery routes apply, so an ability never shows less, or more, than the site itself does. A skill the caller may not read is reported as missing rather than as forbidden, so that comparing two refusals cannot reveal which unpublished names exist. An item with no slug yet is listed under a generated name such as `agent-skill-12-draft`, which reads back through the same abilities.

All four are annotated read-only and require only `wp:read`, so a client connected with a read-only token can call them. Writing is not covered yet: creating or editing a skill means composing the block markup the editor produces, which is a design question of its own.

### Exposing abilities

An ability opts in through its registration meta, using the same flag the official WordPress MCP Adapter reads, so an ability written for either server works with both:

```php
add_action( 'wp_abilities_api_init', function (): void {
	wp_register_ability( 'my-plugin/count-posts', [
		'label' => __( 'Count Posts', 'my-plugin' ),
		'description' => __( 'Counts the published posts of a given post type.', 'my-plugin' ),
		'category' => 'site',
		'input_schema' => [
			'type' => 'object',
			'properties' => [
				'post_type' => [ 'type' => 'string', 'default' => 'post' ],
			],
		],
		'output_schema' => [
			'type' => 'object',
			'properties' => [
				'published' => [ 'type' => 'integer' ],
			],
		],
		'execute_callback' => fn( $input ) => [ 'published' => (int) wp_count_posts( $input['post_type'] ?? 'post' )->publish ],
		'permission_callback' => fn(): bool => current_user_can( 'read' ),
		'meta' => [
			'mcp' => [ 'public' => true ],
			'annotations' => [ 'readonly' => true, 'idempotent' => true ],
		],
	] );
} );
```

Exposure resolves from most specific to least specific, matching how core derives `show_in_rest` from `public` and how the official MCP Adapter resolves the same flag:

| Metadata | Exposed |
| --- | --- |
| `meta.mcp.public` is `true` | Yes |
| `meta.mcp.public` is `false` | No, even when `meta.public` is `true` |
| `meta.mcp.public` absent or `null` | Inherits `meta.public` |
| Neither is set | No |
| `meta.mcp` is not an array | No — malformed metadata fails closed |

So an ability already published with `'meta' => [ 'public' => true ]` is served over MCP without further changes, and `'meta' => [ 'mcp' => [ 'public' => false ] ]` keeps a REST-published ability off MCP.

One consequence worth knowing before enabling the server: WordPress ships `core/get-site-info`, `core/get-user-info` and `core/get-environment-info` with `meta.public` set, so they are exposed by default. All three are read-only, need only the `wp:read` scope, and still run their own capability checks. Opt any of them out with:

```php
add_filter( 'wp_register_ability_args', function ( array $args, string $name ): array {
	if ( 'core/get-environment-info' === $name ) {
		$args['meta']['mcp']['public'] = false;
	}

	return $args;
}, 10, 2 );
```

To expose abilities you do not control, such as the core ones, filter the query instead of editing their registration:

```php
add_filter( 'agent_pilot__mcp_abilities', fn(): array => [ 'namespace' => 'core' ] );
```

Replacing the query replaces the per-ability opt-in rule along with it, which is the point: the abilities being exposed this way are precisely the ones that never opted in. Keep the default rule while adding to it by including the callback in the returned arguments.

### How abilities map onto tools

| Ability | MCP tool |
| --- | --- |
| Name `core/read-settings` | Name `core.read-settings` — slashes are not legal in tool names, and a dot can never appear in an ability name, so the mapping is reversible |
| `label` | `title` |
| `description` | `description` |
| `input_schema` | `inputSchema`, wrapped in an object under a `value` property when the ability declares a non-object schema, because MCP requires an object |
| `output_schema` | `outputSchema`, advertised only when it is an object schema |
| `meta.annotations.readonly` / `destructive` / `idempotent` | `readOnlyHint` / `destructiveHint` / `idempotentHint`, and only the ones actually declared |

A result is returned as a JSON text block, plus `structuredContent` when the ability advertises an object output schema. A failing ability comes back as a tool result with `isError` set rather than a protocol error, so the model can correct itself and retry.

### Resources

Every file of every skill and Agent Plugin the caller may read is also published as an MCP resource, so an agent can read the bytes over the same authenticated connection it discovered them on:

```text
agent-pilot://skills/{name}
agent-pilot://skills/{name}/SKILL.md
agent-pilot://skills/{name}/references/guide.md
agent-pilot://skills/{name}/assets/diagram.png
agent-pilot://plugins/{name}/plugin.json
agent-pilot://plugins/{name}/skills/{skill}/SKILL.md
```

This is the difference from the URLs the tools hand out. An unpublished package is served from a preview link that needs a WordPress session, and an access token minted for this server is deliberately not accepted anywhere else, so a client could list a draft skill through a tool and then fail to fetch it. A resource read answers with the contents themselves.

Whether contents are carried as `text` or as a base64 `blob` follows the media type rather than where the file came from: an uploaded text file is text, a PNG is a blob. Generated files are text, named as `text/markdown`, `text/html` or `application/json` where that is more precise than `text/plain`.

`resources/list` names every file without generating any of them, and omits `size` for the same reason. Each entry carries `annotations.lastModified` from the package it belongs to. The set narrows to what the caller may read, which the specification allows because credentials are per-request rather than connection state. A URI naming a package the caller may not read is refused exactly as an unknown one is, with `-32602`, so the two cannot be told apart.

The bare package URI reads as every file the package publishes, which the specification allows a read to answer with, so a client takes a whole package in one round trip and each file still arrives under its own media type rather than packed into an archive it would have to unpack. The generated ZIP stays an HTTP download, since a client that can fetch it needs no help and one that cannot can read the files here.

### Authentication

Every request must be authenticated. The server accepts, in order:

1. An OAuth 2.1 bearer token, when [OAuth Pilot](https://wpelevator.com/plugins/oauth-pilot) is active.
2. Whatever WordPress already authenticated — a signed-in user or an Application Password.

Nothing is configured on either side. Agent Pilot registers the MCP endpoint as its own OAuth protected resource through OAuth Pilot's `oauth_pilot__register_resources` action, and an unauthenticated request is answered with the RFC 9728 pointer that lets a client bootstrap from nothing but the site URL:

```text
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer scope="wp:read wp:write", resource_metadata="https://example.com/.well-known/oauth-protected-resource/wp-json/agent-pilot/v1/mcp"
```

The discovery challenge advertises every scope this resource offers, not the least privilege the transport itself needs. Under the MCP scope selection strategy a client treats the challenge as authoritative and asks for nothing more, so naming only `wp:read` would connect every client read-only and fail the first write tool it tried. Advertising the whole set produces a single consent screen covering read and write tools alike, and costs no user access: OAuth Pilot narrows the request to the scopes the approving user can actually grant rather than refusing it, so an author is offered both permissions and a subscriber the read one alone. The set is read from the registered resource, so a site that filters the resource's scopes is advertised accurately. Agent Pilot authenticates every HTTP method before transport or JSON-RPC dispatch, so OAuth discovery probes receive the same challenge whether they use `GET` or `POST`. From there the client discovers the authorization server, registers itself, and a human approves the connection once.

The MCP endpoint is a **separate audience** from the WordPress REST API, so a token minted for `/wp-json/` cannot call MCP and vice versa. Because of that separation the endpoint requires an RFC 8707 `resource` parameter, which the MCP authorization spec already obliges clients to send.

Agent Pilot registers two scopes with OAuth Pilot because it can enforce them at the ability boundary, and an ability's annotation of itself decides which one it needs:

| Ability | Required scope |
| --- | --- |
| `meta.annotations.readonly` is `true` | `wp:read` |
| Anything else, including unannotated | `wp:write` |

Defaulting to `wp:write` is the safe direction: an ability that does not describe itself is not assumed harmless. `tools/list` is filtered by the caller's granted scopes, so a read-only client never even sees the write tools. When that client later invokes a write tool, Agent Pilot responds with an `insufficient_scope` challenge naming `wp:write` so clients that support OAuth step-up authorization can ask the user for the additional permission. None of this widens what the represented user may do — the ability's own `permission_callback` still runs, and a call can be refused even when the tool is listed.

### Protocol support

The server is dual-era, answering both the current revision and the handshake-based ones that shipped clients still use:

| Revision | Notes |
| --- | --- |
| `2026-07-28` | Current. No handshake; per-request `_meta` protocol version, `server/discover`, `resultType` on results |
| `2025-11-25`, `2025-06-18`, `2025-03-26` | Legacy `initialize` handshake with an `Mcp-Session-Id` |

Implemented methods: `initialize`, `notifications/initialized`, `server/discover`, `ping`, `tools/list`, `tools/call`. `DELETE` terminates a session.

Every response is `application/json`. The transport permits either that or an SSE stream, and since this server never sends a message the client did not ask for, it does not open one — a `GET` is answered with `405`, as the specification requires of a server that offers no stream. Resources, prompts, streaming and `notifications/tools/list_changed` are not implemented.

Requests without an `Origin` header are accepted so desktop and server-side MCP clients can connect. When a browser sends the header, its origin must match the site's own address unless an integration extends the allowlist with `agent_pilot__mcp_allowed_origins`. The check runs after authentication, so a client that has never authenticated receives the OAuth challenge it can act on rather than a bare 403; both checks still have to pass and neither dispatches anything.

### Filters

| Filter | Purpose |
| --- | --- |
| `agent_pilot__mcp_enabled` | Override whether the endpoint accepts requests |
| `agent_pilot__mcp_abilities` | Change the `wp_get_abilities()` query deciding what is exposed, opt-in rule included |
| `agent_pilot__mcp_tool` | Adjust one generated tool definition |
| `agent_pilot__mcp_tool_scopes` | Override the scopes one ability requires |
| `agent_pilot__mcp_server_info` | Override the advertised server name and version |
| `agent_pilot__mcp_instructions` | Override the guidance sent to clients |
| `agent_pilot__mcp_allowed_origins` | Add browser origins allowed to call the MCP endpoint |

## TODO

- Making the skills available as ChatGPT and Claude plugins.
- Write abilities for skills and plugins, once composing their block content from an agent is designed.
- Ensure that skill slugs match the spec on save.
- Consider a setting to disable single skill post type views (single template) while keeping the markdown preview.
- Bump skill md and zip hash when a linked reference post or attachment is updated.

## References

- [Agent Skills specification](https://agentskills.io/specification)
- [Agent Skills Discovery via Well-Known URIs RFC](https://github.com/cloudflare/agent-skills-discovery-rfc)
- [`skills` CLI package](https://www.npmjs.com/package/skills)
- [`vercel-labs/skills` source repository](https://github.com/vercel-labs/skills)
- [RFC 8288: Web Linking](https://www.rfc-editor.org/rfc/rfc8288)
- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
- [Model Context Protocol specification](https://modelcontextprotocol.io/specification/2026-07-28/)
- [MCP Streamable HTTP transport](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http)
- [RFC 9728: OAuth 2.0 Protected Resource Metadata](https://www.rfc-editor.org/rfc/rfc9728)

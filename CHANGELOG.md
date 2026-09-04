# Changelog

## Unreleased

## 0.7.0 (2026-09-05)

- Fixed an unauthenticated request from a disallowed browser origin being refused with a bare 403 before the OAuth challenge was sent, leaving a browser-based client no way to discover the authorization server. Origin validation now runs after authentication. Both checks still have to pass and neither dispatches anything, so DNS rebinding protection is unchanged.
- Fixed the MCP discovery challenge naming only `wp:read`, which connected every client read-only and made the first write tool fail. A client applying the MCP scope selection strategy treats the challenge as authoritative and asks for nothing more, so advertising the transport's own least-privilege requirement decided the whole connection: Claude and every other conformant client completed consent with read access and then had no way to run a write ability short of a step-up flow. The challenge now advertises every scope the resource offers, read from the registered resource so that a filtered resource stays accurate, which produces one consent screen covering read and write tools alike. Per-operation challenges still name exactly the scope that operation needs, and OAuth Pilot narrows the wider request to what the approving user can actually grant, so asking for more costs no user access.

## 0.6.0 (2026-09-04)

- fix: advertise the least-privilege `wp:read` scope in the initial MCP OAuth challenge, authenticate every HTTP method before transport or JSON-RPC dispatch, and identify write-scope step-up challenges with `insufficient_scope`, so clients can discover and expand the correct protected-resource grant.

## 0.5.0 (2026-09-04)

- feat: add an MCP server that publishes the site's WordPress Abilities as MCP tools over Streamable HTTP at `/wp-json/agent-pilot/v1/mcp`, opt-in from the settings screen.
- feat: authenticate MCP requests with OAuth Pilot when it is active, registering the endpoint as its own protected resource and answering an unauthenticated request with the RFC 9728 challenge that lets a client bootstrap from the site URL alone, and falling back to signed-in users and Application Passwords otherwise.
- feat: register MCP-specific `wp:read` and `wp:write` scopes with OAuth Pilot, derive the scope an ability requires from its own `readonly` annotation, and narrow `tools/list` to the tools the caller's granted scopes allow.
- feat: answer both the current `2026-07-28` MCP revision and the handshake based `2025-11-25`, `2025-06-18` and `2025-03-26` revisions from one endpoint.
- feat: resolve MCP exposure from `meta.mcp.public` first and inherit `meta.public` when it is absent, matching how WordPress derives `show_in_rest` and how the official MCP Adapter resolves the same flag, so an ability written for either server is exposed identically by both. Malformed `meta.mcp` fails closed. As a result the three read-only core abilities, which ship with `meta.public` set, are exposed by default once the server is enabled.
- docs: document exposing abilities over MCP, the ability to tool mapping, authentication, protocol support, and the available filters.

## 0.4.0 (2026-08-21)

- feat: add an Agent Plugin post type and block-editor workflow for composing portable packages from published Agent Skills and optional MCP server definitions.
- feat: generate Agent Plugin `plugin.json`, optional `mcp.json`, and deterministic `plugin.zip` artifacts, with public routes, draft previews, validation, and editor sidebar links.
- feat: keep packaged skills live so changes to a selected skill are reflected in newly generated Agent Plugin archives without re-saving the plugin.
- feat: add generated skill ZIP links to the Agent Skill editor sidebar.
- change: pretty-print generated JSON artifacts and share deterministic ZIP archive generation between Agent Skills and Agent Plugins.
- docs: document Agent Plugin authoring, manifest fields, artifact routes, package layout, and MCP credential safety.

## 0.3.0 (2026-08-11)

- fix: pin light color scheme on skill section panels so headings, labels, and inputs stay legible when the active theme uses a dark editor color scheme ([#151](https://github.com/wpelevator/basement/pull/151)).
- security: normalize resource filenames with the WordPress `sanitize_file_name()` helper during packaging so directory separators and path traversal sequences never reach the generated skill archives ([#151](https://github.com/wpelevator/basement/pull/151)).
- fix: send the shared CORS, `ETag`, `Content-Length`, and `X-Content-Type-Options` headers with the JSON discovery index response ([#151](https://github.com/wpelevator/basement/pull/151)).
- fix: pin ZIP archive entry times to the skill last modified time (on PHP 8.0 or newer) so regenerating an unchanged skill produces byte-identical archives and a stable discovery digest ([#151](https://github.com/wpelevator/basement/pull/151)).
- feat: add a YAML builder that supports nested mappings and lists, and emit `SKILL.md` front matter values as plain scalars quoted only when required ([#151](https://github.com/wpelevator/basement/pull/151)).
- docs: correct the README description of the discovery `Link` header scope and of unrecognized skill permalink suffixes such as `/feed` and `/embed` ([#151](https://github.com/wpelevator/basement/pull/151)).

## 0.2.0 (2026-08-10)

- fix: place generated ZIP skill files at the archive root and bump the ZIP cache key for v0.2 discovery compatibility.

## 0.1.1 (2026-08-10)

- fix: ensure the `{skill-name}/skill.md` and `{skill-name}/skill.zip` routes are accesible for published skills and unauthenticated requests.

## 0.1.0 (2026-08-10)

- Initial prototype.

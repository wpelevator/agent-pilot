# Changelog

## Unreleased

## 0.10.1 (2026-09-11)

- Agent Pilot updates, and the Update Pilot plugin installed from the "Install" prompt, are now verified against the WP Elevator signing key. An update or install with a missing or invalid signature is refused.
- Fixed the Update Pilot registration using a `file` key where Update Pilot reads `plugin`, so Update Pilot ignored its license and signing keys.

## 0.10.0 (2026-09-10)

- Moved the Agent Pilot admin screens under one top-level menu. Settings is the default page, with Skills, Add Skill, Plugins and Add Plugin nested under it, and the Agent Skill and Agent Plugin post types no longer register their own top-level menus. The settings screen presents Skills and Plugins in the same form table as the MCP Server.
- Changed MCP tool names to use dashes instead of dots, so they comply with the allowed tool-name characters.

## 0.9.0 (2026-09-07)

- Added four read-only abilities that publish this site's own Agent Skills and Agent Plugins: `agent-pilot/list-agent-skills`, `agent-pilot/get-agent-skill`, `agent-pilot/list-agent-plugins` and `agent-pilot/get-agent-plugin`, exposed as MCP tools of the same names. A client could already reach the discovery index and the packaged files over HTTP, but only if it was told the routes; these let it ask. Each answers with the generated artifact rather than with the post content behind it, since a skill is authored as blocks that no agent can act on, and a named `file` argument reads one of the files an item packages. An asset is answered with its download URL rather than with its bytes, and a skill is handed out as the archive its `package_url` points at, because a skill with references, scripts or assets is only complete as an archive.
- Visibility of those abilities follows the site rather than a capability of their own: a published skill or package is readable by anyone, matching what its permalink and the discovery index already serve without authentication, while everything else falls back to the capabilities WordPress maps for the post type. That rule now lives on `Skill` and `Agent_Plugin` themselves, and the discovery routes ask them for it instead of repeating it. A skill the caller may not read is reported as missing rather than as forbidden, so comparing two refusals cannot reveal which unpublished names exist, and an item with no slug yet is listed under a generated name that reads back through the same abilities.
- Rewrote `Agent_Plugin_Post` to read like the rest of the plugin: validation is four named methods instead of one procedure, the skill blocks are resolved in one place, and the loop that skipped unselected blocks with `continue` filters them instead.
- Separated being a post from being a package. `Post` holds what a skill and an Agent Plugin share by virtue of being posts — identity, blocks, the name a draft publishes itself under, the permalink, when it changed, who may read it — while the new `Agent_Package` interface holds what publishing actually needs: a name, the files, a hash, a last-modified time. `Skill_Post` and `Agent_Plugin_Post` are the post-backed implementations, and everything that only packages, archives or serves one now asks for the interface, so a package assembled from blocks that were never saved as a post would work without changing any of it. A package that bundles others asks them only what the interface promises, including whether they are published, so composing one no longer reaches for the post behind it.
- A published file is a `Package_File`: where it sits, how to get its contents, and whether the author supplied it rather than the package generating it. That last one is provenance rather than encoding — PHP has no separate byte type and an uploaded file may well be text — and it is what lets a caller that can only carry text hand over the file's own URL instead. It is recorded by the package building the file, so an Agent Plugin no longer decides by looking for `/assets/` in a path, and a bundled asset is answered with its own URL rather than sending the caller off to find the skill that carries it. Anything else a published file has to carry, such as a media type or the modification time to stamp its archive entry with, belongs here rather than in another map keyed by path.
- Added `Agent_Package_Files`, which holds those files in the order they are published and resolves each one's contents only when it is read. The paths and the contents are now one declaration rather than two lists a test had to keep in agreement, listing the files of every skill on a site renders no blocks and reads no attachments, and reading one reference no longer generates every asset beside it.
- Each component defines the abilities for the data it owns but knows nothing about the names they answer to: it returns the arguments for one ability, and the plugin bootstrap binds every name in one place, next to the post types and the shared Abilities API category. The built-in `agent-pilot/rest-call` is registered the same way, so the whole ability surface a site gets can be read, and renamed, without opening a definition. Throughout, *package* means the generated archive a client installs, never either of the two things that generate one. All four abilities are annotated read-only, so a client holding only `wp:read` can call them. Writing is left to a later release: creating or editing a skill means composing block markup.
- Published every file of every readable skill and Agent Plugin as an MCP resource, under `agent-pilot://skills/{name}/{path}` and `agent-pilot://plugins/{name}/{path}`. A tool hands out a URL, and for an unpublished package that URL is a preview link needing a WordPress session, while an access token minted for this server is deliberately not accepted anywhere else — so a client could list a draft skill and then fail to fetch it. A resource read answers with the contents, and the bare package URI reads as every file the package publishes at once, so a client takes a whole package in one round trip without any of it being packed into an archive it would have to unpack again. Text is carried as text and everything else as a base64 blob, decided by the media type rather than by where the file came from, so an uploaded text file is text and a PNG is not. Listing names every file without generating any of them, narrows to what the caller may read, and refuses a package they may not read exactly as it refuses one that does not exist.
- Archive generation moved out of the two discovery routes, which each held their own copy of it, into `Package_Archive`. It builds an archive for anything that satisfies `Agent_Package` and caches it under that package's own hash.
- Fixed a skill serving a stale archive after a reference or asset it publishes was edited. The generated files are cached under a hash of the skill, and that hash accounted only for the skill's own post, so editing the post a reference publishes the content of changed the files without changing the key that finds them. A skill now counts as changed when the content it links to changes, which the hash is built on. Replacing an attachment's bytes underneath WordPress, without updating the attachment itself, still goes unnoticed.
- Fixed the MCP server handing every ability's permission callback the raw arguments a client sent. Core normalizes and validates input before it reaches a permission callback, but only inside `execute()`, and this server checks permissions first on purpose, so that a refusal is reported as an authorization failure rather than as a tool error a model would retry. Those two steps now run here in the same order core uses, which means a permission callback that reads its own input — deciding what a call would touch before allowing it — sees validated input, and arguments that do not match the tool schema come back as a tool error instead of reaching the callback at all.
- The built-in `agent-pilot/rest-call` stopped looking itself up in the ability registry to validate input inside its permission callback, and applies its own input schema directly instead, which is what let the last ability class forget the name it answers to. Core still validates a call that goes on to execute, filters included. `Agent_Plugin` also no longer takes a skills repository it never read, and `Agent_Plugins` no longer holds one only to pass it along.

## 0.8.0 (2026-09-05)

- Added a built-in `agent-pilot/rest-call` ability, published as the `agent-pilot-rest-call` MCP tool, that dispatches any internal WordPress REST request as the authenticated user. A client can reach this site's REST API without an ability being registered for each route, and the ability works with the official WordPress MCP Adapter as well. The permission callback matches the request to its endpoint, prepares that endpoint's URL parameters, defaults and sanitized input, and runs its own permission callback before execution, so a refusal is reported as an authorization failure rather than as a tool error a model would retry. Dispatch checks permissions again when an allowed call executes. Because one tool covers every method, it is annotated as a write and needs the `wp:write` scope even to read; a read-only token cannot call it.
- An internal request is dispatched rather than served, so it never reaches the `rest_post_dispatch` filters, and `_fields` was accepted and silently ignored: a caller asking for two fields paid for the whole response and was told nothing. `_fields` filtering and the `Allow` header are now applied by name, and `_embed` is resolved from the request rather than from the query string an internal request has no part in. This is what makes discovery affordable — the route index is around 230 KB on a stock site, and `GET /` with `_fields=namespaces` answers in about 140 bytes. Filters a site hooks to `rest_post_dispatch` for its own HTTP responses still do not run here.
- Added a MCP Tools setting to exclude selected abilities from Agent Pilot MCP tool discovery and calls. Exclusions are stored per site and also apply to custom ability queries.
- Fixed an Agent Plugin Skill block with nothing selected yet invalidating the whole package. Inserting the block is the first half of choosing a skill, so an author who added one and saved before picking a post silently lost `plugin.json`, `mcp.json` and `plugin.zip`: the routes bail on an invalid plugin, and the editor sidebar kept showing the three links because the artifact URL fields are derived from the permalink rather than gated on validity. An unselected block is now skipped during validation the same way `get_skills()` already skipped it, so it contributes nothing and blocks nothing. A block that does reference a post which is not an Agent Skill still fails validation, and a plugin whose only child is an empty skill block still reports that it requires a skill or MCP server.

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

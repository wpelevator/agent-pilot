# Changelog

## Unreleased

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

# Agent Pilot

Agent Pilot lets WordPress authors create [Agent Skills](https://agentskills.io/specification) in the block editor and publish them through the [Agent Skills Discovery via Well-Known URIs](https://github.com/cloudflare/agent-skills-discovery-rfc) v0.2.0 draft.

The plugin stores each skill as an `agent_skill` post, generates a deterministic `SKILL.md`, packages any references, scripts, and assets into a ZIP archive, and advertises the public discovery index at:

```text
/.well-known/agent-skills/index.json
```

## Requirements

- WordPress 6.6 or newer.
- PHP 7.4 or newer.
- PHP `zip` extension for archive generation.
- Update Pilot is used for automatic plugin updates. Agent Pilot shows an admin notice when Update Pilot is unavailable.

## Installing Skills

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

The editor sidebar includes an Agent Skill panel with a `SKILL.md` link after the post has a usable skill file URL.

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

## TODO

- Making the skills available as ChatGPT and Claude plugins.
- Ensure that skill slugs match the spec on save.
- Consider a setting to disable single skill post type views (single template) while keeping the markdown preview.
- Bump skill md and zip hash when a linked reference post or attachment is updated.

## References

- [Agent Skills specification](https://agentskills.io/specification)
- [Agent Skills Discovery via Well-Known URIs RFC](https://github.com/cloudflare/agent-skills-discovery-rfc)
- [`skills` CLI package](https://www.npmjs.com/package/skills)
- [`vercel-labs/skills` source repository](https://github.com/vercel-labs/skills)
- [RFC 8288: Web Linking](https://www.rfc-editor.org/rfc/rfc8288)

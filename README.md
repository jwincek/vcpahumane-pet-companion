# VCPA Humane Pet Companion

Companion WordPress plugin for **friends of the Venango County Humane Society**.
Lets partner sites display adoptable pets pulled live from
[vcpahumane.org](https://vcpahumane.org) using Gutenberg blocks. Visitors who
click a pet are sent back to the shelter's adoption page at
`/adopt/pets/{slug}/` to start the adoption process.

This plugin is read-only — it does not require API keys, does not store pet
records locally, and does not duplicate the source plugin's sync logic.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- The source site (vcpahumane.org) must keep the `pet` post type publicly
  exposed in the REST API (it is by default, via `vcpahumane-petstablished-sync`).

## Installation

1. Copy the `vcpahumane-pet-companion` directory into the partner site's
   `wp-content/plugins/` folder.
2. Activate **VCPA Humane Pet Companion** in the WordPress admin.
3. Visit **Settings → VCPA Pets** to confirm the source URL and cache
   duration. The defaults point at `https://vcpahumane.org`.

## Blocks

| Block | What it does |
|---|---|
| **VCPA Featured Pets** | Small set of featured/randomized adoptable pets. Filterable by animal type (Dog/Cat/etc.) and attributes (special needs, good with kids, hypoallergenic, etc.). |
| **VCPA Pet Card** | Embed one specific pet by name. Search the live pet list from the editor sidebar. When the pet is adopted, the block automatically becomes a "Bagel found a home!" celebration card using cached name + image. |

Both blocks render server-side, share a single CSS file, and link out to the
shelter's pet pages in a new tab.

### Adopted-pet handling

When a Pet Card is first inserted, the editor caches the pet's name, image,
and alt text as block attributes. After the pet is adopted and removed from
the source site, the block falls back to those cached values to show a
celebration state — so blog posts featuring "Bagel needs a home" gracefully
become "Bagel found a home!" without partner site editors needing to do
anything.

## Caching

Pet data is cached in transients keyed by query args. The default TTL is
**15 minutes**, configurable on the settings page. Admins can also flush the
cache from that page if a partner needs to force a refresh after a major
update at the shelter.

## Architecture notes

- `includes/class-client.php` — wraps `wp_remote_get` against
  `/wp-json/wp/v2/pet?_embed`, normalizes results to a small shape, and
  caches per-query in transients. Maintains an index option so the cache can
  be flushed cleanly without scanning all transients.
- `includes/class-settings.php` — Settings API page for source URL, link
  domain, and cache TTL.
- `includes/render-helpers.php` — shared `vcpa_pet_companion_render_card()`
  used by all three blocks for visual consistency.
- `blocks/*/render.php` — each block is a thin server-rendered wrapper that
  calls the client and emits cards. No JS bundle, no editor build step
  needed beyond what `register_block_type` provides via `block.json`.

## Development

There is no build step. Edits to PHP, `block.json`, and `assets/style.css`
take effect immediately. The plugin assumes the source site's pet CPT
rewrite slug remains `adopt/pets`; if that ever changes, update
`VCPA_Pet_Companion_Client::pet_url()`.

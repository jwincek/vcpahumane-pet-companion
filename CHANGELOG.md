# Changelog

All notable changes to **VCPA Humane Pet Companion** are documented here.
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.2] — 2026-04-09

### Fixed
- Editor previews could get stuck showing a stale "cURL timeout" error after
  changing a block attribute (most visibly the Featured Pets `count` slider),
  even when the source site was healthy. The negative cache stored network
  errors with the same 60-second TTL as real source failures, so a transient
  loopback timeout poisoned the cache key for a full minute. Network-layer
  failures (timeout, DNS, connection refused) now use a short 5-second TTL,
  while real failures (HTTP 4xx/5xx, malformed JSON) keep the 60-second TTL.

## [0.2.1] — 2026-04-08

### Fixed
- Blocks failed to appear in the editor on some sites because `edit.js` was
  enqueued with no script dependencies, so `window.wp` could be undefined
  when the file ran. Added a hand-maintained `edit.asset.php` next to each
  `edit.js` declaring the required `wp-*` packages
  (`wp-blocks`, `wp-element`, `wp-block-editor`, `wp-components`,
  `wp-server-side-render`, `wp-i18n`). 0.2.0 happened to work on Local by
  Flywheel and other environments where another plugin pre-loaded those
  scripts, but failed on cleaner installs.

## [0.2.0] — 2026-04-08

First public release. The plugin ships two Gutenberg blocks for displaying
adoptable pets from the Venango County Humane Society on partner WordPress
sites.

### Added
- **VCPA Featured Pets** block: configurable count, columns, randomization, and
  filtering by animal type and pet attributes (special needs, good with kids,
  hypoallergenic, etc.).
- **VCPA Pet Card** block: profile-style embed of one specific pet with a
  search picker in the editor sidebar, full shelter writeup, and a prominent
  "Start the adoption process" CTA.
- Adopted-pet handling: when a featured pet is removed from the source site,
  the Pet Card block automatically becomes a "{Name} found a home!"
  celebration profile using cached name, image, and alt text stored as block
  attributes at pick time.
- Block style variants on Featured Pets: **Card** (default), **Minimal**,
  and **Overlay**.
- Core block supports: color, spacing, and border on both blocks so partner
  sites can theme the output to match their brand.
- Image position toggle (left / top) and full-story toggle on Pet Card.
- Settings page (Settings → VCPA Pets) for source URL, link domain, and
  cache TTL, with a manual "Clear pet cache now" button.
- Transient cache layer keyed by query args, defaulting to a 6-hour TTL to
  match the source plugin's daily Petstablished sync cadence.
- Negative cache: failed remote fetches are cached for 60 seconds so a brief
  source outage doesn't make every visitor pay an HTTP timeout.

[0.2.2]: https://github.com/REPLACE-ME/vcpahumane-pet-companion/releases/tag/v0.2.2
[0.2.1]: https://github.com/REPLACE-ME/vcpahumane-pet-companion/releases/tag/v0.2.1
[0.2.0]: https://github.com/REPLACE-ME/vcpahumane-pet-companion/releases/tag/v0.2.0

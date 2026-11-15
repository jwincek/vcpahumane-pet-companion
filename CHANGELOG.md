# Changelog

All notable changes to **VCPA Humane Pet Companion** are documented here.
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] — 2026-11-15

Requires the source to run ShelterKit Pets. Version 2.1 or later is needed for
Pet Card to say a pet found a home.

### Changed
- **Pet Card says a pet found a home only when it was adopted.** It used to
  say so whenever the pet disappeared from the source. Since ShelterKit Pets
  2.0, the shelter's site also stops listing pets that were returned,
  transferred or euthanized, so a partner's post could celebrate an animal
  that died. The card now asks the source, through ShelterKit Pets'
  `petsync/check-adoptions` ability. An adopted pet still gets the
  celebration. Any other pet that is no longer listed gets
  "{Name} isn't up for adoption right now" and a link to the pets still
  waiting. A source on an older ShelterKit Pets cannot answer, so its pets
  always get the neutral card.
- **Featured Pets shows only Available pets.** Adoption Pending and On Hold
  pets stay listed on the shelter's site but are not taking applications,
  and the grid invited visitors to meet them.
- **Pet Card shows a listed pet's status when it isn't Available**, as a
  badge, with "Learn more about {Name}" in place of "Start the adoption
  process".
- **Links go where the source says each pet's page is**, using the REST API's
  `link`, instead of an address built as `/adopt/pets/{slug}/`. The
  "Meet other adoptable pets" link uses the listing address the source
  reports. The **Adoption link domain** setting is now optional and empty by
  default. When it is set, it replaces only the domain of each link.
- **Pets are read from the `vcps_pet` post type**, the name ShelterKit Pets
  gives them. The `pet` post type that 0.2.2 reads no longer exists on the
  shelter's site. This change was committed in July 2026 without a release.

### Fixed
- **A source outage no longer turns every Pet Card into "found a home!"** A
  failed request, such as a timeout, an error page or the post-type rename
  above, looked the same as the pet being gone. The card now shows its empty
  state, and administrators see the error.
- **Changing the source URL takes effect at once.** Cached results were keyed
  by the query alone, so the old source's pets showed until the cache
  expired.

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

[0.3.0]: https://github.com/jwincek/vcpahumane-pet-companion/releases/tag/v0.3.0
[0.2.2]: https://github.com/jwincek/vcpahumane-pet-companion/releases/tag/v0.2.2
[0.2.1]: https://github.com/jwincek/vcpahumane-pet-companion/releases/tag/v0.2.1
[0.2.0]: https://github.com/jwincek/vcpahumane-pet-companion/releases/tag/v0.2.0

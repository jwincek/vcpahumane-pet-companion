# VCPA Humane Pet Companion

Companion WordPress plugin for **friends of the Venango County Humane Society**.
Lets partner sites display adoptable pets pulled live from
[vcpahumane.org](https://vcpahumane.org) using Gutenberg blocks. Visitors who
click a pet go to its page on the shelter's site to start the adoption
process.

This plugin is read-only: it needs no API keys, stores no pet records, and
does not duplicate the shelter site's sync.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- The source site runs **ShelterKit Pets**, which publishes pets through the
  REST API as the `vcps_pet` post type. With ShelterKit Pets **2.1 or later**,
  the source also says when a pet that is no longer listed was adopted. An
  earlier version cannot, so Pet Card never says "found a home" with one.

## Installation

1. Download `vcpahumane-pet-companion-X.Y.Z.zip` from the
   [latest release](https://github.com/jwincek/vcpahumane-pet-companion/releases/latest).
   Not the "Source code" archive: it unpacks to a folder named after the
   version, so WordPress would install it as a separate plugin.
2. In the partner site's admin, go to **Plugins → Add New Plugin → Upload
   Plugin**, choose the zip, then **Install Now** and **Activate**.
3. Visit **Settings → VCPA Pets** to confirm the source URL and cache
   duration. The source defaults to `https://vcpahumane.org`.

### Updating

WordPress can't tell partner sites about new versions, because the plugin
isn't on WordPress.org. Upload the new release's zip the same way. WordPress
says the plugin is already installed and offers **Replace current with
uploaded**. Settings and blocks are kept.

## Blocks

| Block | What it does |
|---|---|
| **VCPA Featured Pets** | A small set of recent or randomized pets that are **Available**. Filterable by animal type (Dog, Cat, etc.) and attributes (special needs, good with kids, hypoallergenic, etc.). Adoption Pending and On Hold pets are left out, because they are not taking applications. |
| **VCPA Pet Card** | Embed one specific pet by name. Search the live pet list from the editor sidebar. What the card says depends on where the pet is now; see below. |

Both blocks render server-side, share a single CSS file, and link to the
shelter's pet pages in a new tab.

### What Pet Card shows

When a Pet Card is inserted, the editor caches the pet's name, image and alt
text as block attributes, so the card has something to show after the pet is
no longer listed.

| The pet is… | The card shows |
|---|---|
| Listed and Available | Its profile and **Start the adoption process** |
| Listed, but Adoption Pending or On Hold | Its profile, the status as a badge, and **Learn more about {Name}** |
| Adopted, listed or not | **{Name} found a home!** and a link to the pets still waiting |
| No longer listed for any other reason, or the source can't say why | **{Name} isn't up for adoption right now** and a link to the pets still waiting |

A pet stops being listed when it is adopted, but also when it is returned,
transferred or euthanized, and the shelter's site does not say which. So the
card asks the source one question, whether the pet was adopted (ShelterKit
Pets' `petsync/check-adoptions` ability), and celebrates only then. A blog
post featuring "Bagel needs a home" becomes "Bagel found a home!" only if
Bagel was adopted.

If the source cannot be reached, the card says nothing about the pet: visitors
see a short "check back soon" message and administrators see the error.

## Caching

Responses are cached in transients keyed by the full request URL, so changing
the source URL never shows the old source's pets. The default TTL is
**6 hours**, configurable on the settings page. Failed requests are cached
for 60 seconds, or 5 seconds for a timeout or connection failure, so an
outage doesn't make every visitor wait. A source that doesn't offer the
adoption check (an older ShelterKit Pets) is remembered for the full TTL.
Admins can flush the cache from the settings page.

## Architecture notes

- `includes/class-client.php`: wraps `wp_remote_get` against the source's
  REST API: pets from `/wp-json/wp/v2/vcps_pet?_embed`, statuses from
  `/wp/v2/pet_status`, and the adoption check from
  `/wp-json/petsync/v1/petsync/check-adoptions/run`. Normalizes pets to a
  small shape, builds links from each pet's REST `link`, and caches per
  request. Maintains an index option so the cache can be flushed without
  scanning all transients.
- `includes/class-settings.php`: Settings API page for the source URL, an
  optional link domain, and the cache TTL.
- `includes/render-helpers.php`: the shared grid card and empty state.
- `blocks/*/render.php`: each block is a thin server-rendered wrapper that
  calls the client and emits markup. `edit.js` is plain ES5 against the
  `wp.*` globals, so there is no build step.

## Development

There is no build step. Edits to PHP, `block.json`, and `assets/style.css`
take effect immediately. The development tools come from Composer and are
not needed at runtime:

```sh
composer install
composer lint              # PHPCS: escaping, nonces, i18n, PHP 8.1 compatibility
composer check:versions    # header, constant, editor scripts and CHANGELOG agree

# Integration tests, against a WordPress test library and a scratch database:
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] 7.1
composer test
```

The tests stand in for the shelter's site with `tests/class-fake-source.php`,
which answers the three routes the plugin calls the way ShelterKit Pets does.
Any request the fake does not answer is refused and fails the test, so a
test can never reach a real shelter. CI runs all of this on every pull
request, against WordPress 6.4 on PHP 8.1 and WordPress 7.1 on PHP 8.4, and
lints every file on PHP 8.1. `main` is protected: changes go through a pull
request, and those checks must pass.

`bin/build-dist.sh --zip` builds the zip partners install into `build/`.
It fails if a development file gets in, a runtime file is missing, an
uncommitted file would ship, or a PHP file lacks its direct-access guard.
CI runs it on every pull request.

### Releasing

1. In a pull request, bump the version in the plugin header, the
   `VCPA_PET_COMPANION_VERSION` constant and both `edit.asset.php` files, and
   turn `[Unreleased]` in `CHANGELOG.md` into the new version's entry.
   `composer check:versions` fails until all of them agree.
2. After it is merged, tag `main`, never the release branch:
   ```sh
   git checkout main && git pull
   git tag vX.Y.Z && git push origin vX.Y.Z
   ```
3. `.github/workflows/release.yml` checks that the tag is on `main` and
   matches the version, builds the zip, and publishes the GitHub Release with
   the zip attached and that version's changelog as its notes. Don't create
   the release by hand: the tag comes first so the checks run before anything
   is published. If a run fails after the tag is right, re-run it from the
   Actions tab with the tag as input.

Pet links use the address the source reports for each pet, so they follow
the shelter site's permalinks. Only a source that reports no address falls
back to ShelterKit Pets' `/adopt/pets/{slug}/`. The optional **Adoption link
domain** setting replaces the domain of every link and keeps the rest.

Never point a development copy at the live shelter site while testing. Set
the source URL to a local ShelterKit Pets site instead.

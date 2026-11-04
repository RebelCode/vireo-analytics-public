<h1 align="center">Vireo Analytics</h1>

<p align="center">
  <a href="https://vireowp.com"><img alt="Docs" src="https://img.shields.io/badge/DOCS-vireowp.com-F5A623?style=for-the-badge"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html"><img alt="License" src="https://img.shields.io/badge/LICENSE-GPL--3.0-5C9E31?style=for-the-badge"></a>
  <a href="https://rebelcode.com"><img alt="Built by" src="https://img.shields.io/badge/BUILT%20BY-REBELCODE-8A2BE2?style=for-the-badge"></a>
</p>

<p align="center"><b>Self-hosted, cookieless analytics for WordPress that imports your Google Analytics history.</b></p>

## What it does

- Records visits on your own server, with no cookies and no third-party tracker.
- Imports your history from [Google Analytics](https://vireowp.com/docs/import-google-analytics/) and [other tools](https://vireowp.com/docs/importing/), so switching doesn't reset your numbers.
- Shows the [Google searches behind each post](https://vireowp.com/docs/search-console/) when Search Console is connected.
- [Filters out bots](https://vireowp.com/docs/bot-filtering/) and referrer spam.

Install it from the [WordPress plugin directory](https://wordpress.org/plugins/vireo-analytics/). Setup is covered in [Getting started](https://vireowp.com/docs/getting-started/), and the [features page](https://vireowp.com/features/) lists everything else.

## Requirements

WordPress 6.9 or later, PHP 7.4 or later.

## Development

`bash build/build-free.sh` builds the zip that ships to WordPress.org.

## License

GPL-3.0-or-later.

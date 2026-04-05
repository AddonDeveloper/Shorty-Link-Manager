# Shorty Link Manager

Shorty Link Manager is a WordPress plugin for finding, managing, and shortening external links.

Version: 0.1
License: GPL-2.0-or-later

## Features

- Scan posts and pages for external links
- Store unique links with occurrence tracking
- Shorten pending links in safe batches
- Restore original URLs when needed
- Automatic mode for shortening on save
- Manual review workflow from the Links page
- Shurli.at support in the first public release

## Installation

1. Copy the plugin folder to `wp-content/plugins/shorty-link-manager/`.
2. Activate **Shorty Link Manager** in WordPress.
3. Open **Shorty Link Manager** in the admin area.
4. Enter your Shurli.at API key in **Settings**.
5. Scan links and process pending URLs from the **Links** page.

## Repository structure

- `shorty-link-manager.php` — main plugin bootstrap file
- `includes/` — plugin classes and provider integration
- `assets/` — admin JavaScript
- `readme.txt` — WordPress.org readme
- `LICENSE` — GPL license text

## Release 0.1

First public release with:

- provider settings
- Shurli.at integration
- bulk scanning and processing
- restore workflow
- progress display for automatic batch processing

## Notes

This repository includes both `README.md` for Git hosting platforms and `readme.txt` for WordPress plugin directory compatibility.

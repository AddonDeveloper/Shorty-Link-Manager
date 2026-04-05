=== Shorty Link Manager ===
Contributors: theveloper
Tags: short url, link shortener, external links, affiliate links, link management
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find, manage, and shorten outgoing links in WordPress. Scan existing posts and pages for external links and process them in bulk.

== Description ==

Shorty Link Manager helps site owners find, manage, and shorten outgoing links directly inside WordPress.

The plugin can scan posts and pages for external links, process them in safe batches, and replace matching URLs with shortened versions.

Features include:

* Admin settings page for provider configuration
* Support for Shurli.at as the currently supported shortening service
* Automatic mode for shortening links when content is saved
* Manual workflow for reviewing and processing existing links from the Links page
* Links overview page for scanning older posts and pages
* Bulk processing of detected outgoing links
* Progress display with safe batch continuation
* Restore original links when needed
* Replaces matching outgoing URLs directly in the corresponding content

The first public release includes support for Shurli.at. It was selected as the initial provider because it offers free API access and allows users to get started without additional service costs.

Shorty Link Manager is designed for users who want a simple workflow for managing and shortening external links from within the WordPress admin area.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or upload the ZIP file through **Plugins > Add New**.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Open **Shorty Link Manager** in the WordPress admin area.
4. Select **Shurli.at** as provider and enter your API key.
5. Use **Shorty Link Manager > Links** to scan and process existing outgoing links.

== Frequently Asked Questions ==

= Which shortening service is currently supported? =

At the moment, Shorty Link Manager supports Shurli.at.

= Why does the first release use Shurli.at? =

Shurli.at was selected for the first public release because it offers free API access and makes it easier to get started without additional service costs.

= Can I scan old posts and pages for existing links? =

Yes. The plugin can scan existing content and detect outgoing links for later processing.

= Can I restore original links? =

Yes. Shortened links can be restored to their original URLs.

= Does the plugin process links automatically? =

Yes. In automatic mode, outgoing links can be shortened when content is saved. In manual mode, you can review and process detected links later from the Links page.

== Changelog ==

= 0.1 =
* First public release
* Settings page for provider configuration
* Shurli.at provider support
* Automatic shortening on save
* Scan existing posts and pages for outgoing links
* Batch processing for discovered links
* Restore original links

== Upgrade Notice ==

= 0.1 =
First public release.
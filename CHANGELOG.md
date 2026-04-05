# Changelog

## 0.1

Initial public release of **Shorty Link Manager**.

### Repository and release preparation

- renamed the plugin branding to **Shorty Link Manager**
- aligned the main plugin version constant with the release version `0.1`
- removed the outdated plugin URI from the plugin header
- added `Requires at least` and `Requires PHP` headers
- added `load_plugin_textdomain()` bootstrap support for translations
- updated admin page labels, headings, menu slugs, and text domain usage to the new plugin name
- corrected manual mode wording so it matches the current workflow via the Links page
- updated the provider API test label to the new plugin name
- updated `readme.txt` for the first public release and current WordPress compatibility target
- added `README.md` for Git repository presentation
- added `LICENSE` with GPL v2 text
- added `.gitignore` for common development and build artifacts
- added an empty `languages/` directory for future translation files
- fixed an admin asset localization issue by ensuring the active provider is loaded before passing the provider name to JavaScript
- ran PHP syntax checks across the plugin files after the changes

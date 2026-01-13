# Switch Language

Switch Language is a WordPress plugin that detects the visitor's browser language, switches the site locale when available, and lets admins extract and translate site text with DeepL.

## Features

- Automatic locale switching based on browser language.
- Extracts text from pages, posts, products, and menus.
- DeepL translation support with manual overrides.
- Shortcode-safe translation handling.
- Multi-select target languages with tabbed translation UI.

## Installation

1. Copy this plugin folder to `wp-content/plugins/switch-language`.
2. Activate **Switch Language** in the WordPress admin.
3. Install any desired language packs in **Settings > General > Site Language**.
4. Add a DeepL API key in **Switch Language > DeepL API Settings**.

## Usage

- Open **Switch Language > Extracted Texts**.
- Click **Extract Texts from All Pages** to populate the text list.
- Pick a source language and target languages, then click **Update Target Languages**.
- Use the per-language tab **Translate Texts** button to translate for a single target.
- Edit any translation inline and click **Save/Update**.

## Notes

- The "Stored Locale" column reflects the site locale at extraction time; it does not control translation source.
- If no translations are added, confirm the DeepL API key and the selected source language.
- Shortcodes are preserved and not translated.

## Development

- Main plugin file: `switch-language.php`
- DeepL integration: `includes/deepl-translation.php`

## License

GPL-2.0-or-later

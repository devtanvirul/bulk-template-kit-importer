# Bulk Template Kit Importer

A WordPress plugin that copies your Elementor designs into Envato's **Template Kit - Export** library in one click, with a serial number per template so the kit keeps the order you want.

## Why

Envato's Template Kit Export plugin skips the Elementor Library entirely when it exports a kit — every template has to be copied into the kit's own library before it can be exported. Normally that means opening each page, clicking "Add New Template," landing on a blank Elementor canvas, and rebuilding or re-importing the design by hand. For a kit with 15–20 templates, that's an afternoon spent on what is really just copying.

This plugin automates that copying step.

## What it does

- Lists every page, post, and saved Elementor template on your site in one screen
- Lets you tick what belongs in the kit and give each row a serial number, so the Global Kit can go in first and the rest follow in the order you want — low numbers first, blank rows go last
- Makes that serial order stick, in the step 3 screen, the Templates list and the exported ZIP alike (see [Ordering](#ordering) for why that takes doing)
- Sets each template's **Kit type** and ticks **Include Template in Export ZIP** during the import, so step 3 needs no dropdown picking or box ticking
- Lets you pick a screenshot for each template straight from the WordPress Media Library
- Sets the page layout (Elementor Canvas by default, so no theme header/footer leaks into the design)
- Copies the Elementor data into the Template Kit library and verifies, character for character, that the copy matches the source exactly
- Shows the position actually stored on each kit template, and flags it when it disagrees with the serial
- Re-importing a row updates the template it made before instead of creating a duplicate
- Has a **Re-apply order and export settings only** pass that fixes a kit's order and settings in about a second, without copying any designs

## Ordering

Template Kit Export orders its templates by `menu_order`, which this plugin writes from your serial — but writing it is not enough on its own. Its sort comparator is:

```php
return $a['order'] > $b['order'] ? 1 : 0;
```

That never returns `-1`, so it can push an item later but never pull one earlier, and a list that starts out backwards cannot be turned around. What it starts from is `get_posts()`, whose defaults are `orderby => date, order => DESC`; a bulk import gives every template the same timestamp, so the database falls back to ID and returns them newest first — the exact reverse of the serial order.

So this plugin orders the query itself, through `pre_get_posts`. The list arrives already correct and their sort finds nothing to move. Because it works at read time, templates imported before you installed this come out in the right order too.

## Requirements

- WordPress
- [Elementor](https://wordpress.org/plugins/elementor/)
- [Template Kit - Export](https://wordpress.org/plugins/template-kit-export/) (the Envato plugin)

## Installation

1. Download this repository as a ZIP (`Code` → `Download ZIP`), or clone it.
2. In WordPress: **Plugins → Add New → Upload Plugin**, and upload the ZIP. (If you cloned the repo, zip the folder first, or copy it directly into `wp-content/plugins/`.)
3. Activate **Bulk Template Kit Importer**.
4. Find it under **Template Kit → Bulk Import to Kit** (or **Tools → Bulk Import to Kit** if your site doesn't expose the Template Kit menu).

## Usage

1. Tick the templates you want in the kit.
2. Give each one a serial number to control the export order. Leave it blank if the order doesn't matter yet — unnumbered rows sort to the end.
3. Check the **Kit type** on each row. One is chosen for you; change it where the guess is wrong.
4. Pick a screenshot for each one. Envato requires one per template and refuses to export without it.
5. Choose a page layout for the import (Canvas is the default and the usual choice).
6. Click **Import selected into Template Kit**.
7. Check the **Kit template** column — "exact match" means the copy is verified byte-for-byte against the source, and the position shown should agree with the serial.

If the order or the settings come out wrong later, **Re-apply order and export settings only** fixes them without copying anything. Nothing needs to be ticked for it.

Version history, and what each release changed, is in [CHANGELOG.md](CHANGELOG.md).

## A note on the byte-for-byte check

`update_post_meta()` runs `wp_unslash()` on the value it's given, which silently strips a layer of backslashes out of Elementor's JSON data — shortening URLs and, worse, corrupting any text that contains a quote mark. Elementor's own code works around this with `wp_slash()` before saving. This plugin does the same, and then re-reads what it wrote to confirm the import landed intact rather than assuming it did.

## License

GPL-2.0-or-later

## Author

Built by [devtanvirul](https://devtanvirul.com).

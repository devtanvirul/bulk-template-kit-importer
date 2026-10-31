# Changelog

## 1.4.0

**Before:** the plugin copied the design across and stopped there. Every template still had to be opened in Template Kit Export's step 3 to pick a Template Type from a dropdown and tick "Include Template in Export ZIP". Miss the tick and the template is silently left out of the export — Envato's builder skips anything unticked — so a kit of fifteen templates meant thirty manual clicks before it could be exported at all, and an export that came out short gave no clue which row had been missed.

**Now:** both are set during the import.

- A **Kit type** column on the main screen, carrying the same options Template Kit Export offers, with a sensible value already chosen. The guess reads what WordPress actually stores the post as rather than what it is called, so a page named "Pricing" stays a full page instead of being mistaken for a pricing section; a saved Elementor header, footer or popup is recognised from its library type; a page with 404 in the name becomes the 404 template. Change it on any row and the choice is remembered.
- **"Include Template in Export ZIP"** is ticked on everything imported, as a batch option that is on by default.
- **"Elementor Pro Required"** can be set for the whole batch too, off by default.

Anything else set by hand over in step 3 survives being imported over — only these keys are written.

## 1.3.0

**Before:** importing a handful of templates worked perfectly and importing the whole kit at once scrambled the order. The cause was that every single template cleared Elementor's site-wide file cache on its own, rebuilding every CSS file on the site once per row. On a kit carrying a few hundred KB of design data per template, that was enough to push the request past PHP's time limit, so the batch died partway through: the templates it had reached held their new position and the rest kept their old one. Nothing reported a failure, because the script never got far enough to report anything.

**Now:**

- The cache is cleared **once, after the whole batch**, which is all it ever needed.
- A **"Re-apply order and export settings only"** button writes the serials and settings without copying any designs. It finishes in about a second, so a kit whose order came out wrong no longer has to be imported from scratch. Nothing needs to be ticked for it.
- The **Kit template** column shows the position actually stored on each template, read back from the database, and turns red with "does not match the serial" when the two disagree. A half-finished run is now visible instead of guessed at.

## 1.2.0

**Before:** serial numbers were written to each kit template's `menu_order`, which is the field Template Kit Export says it orders by — and the order still came out wrong.

Two things in Envato's plugin combine to cause it. Its sort comparator is:

```php
return $a['order'] > $b['order'] ? 1 : 0;
```

It never returns `-1`, so it can say "this one belongs later" but never "this one belongs earlier", and a list that starts out backwards can never be turned around. What it starts from is `get_posts()`, whose own defaults are `orderby => date, order => DESC`. A bulk import gives every template the same timestamp, so the database falls back to ID and hands them back newest first — the exact reverse of the serial order, which is the one case that comparator cannot recover from.

**Now:** the order is set on the query itself, so the list reaches their sort already correct and it finds nothing to move. `get_posts()` suppresses the `posts_*` filters but not `pre_get_posts`, which is the one hook that can reach it from outside their plugin. Because this works at read time, existing templates come out in the right order without being imported again.

This fixes the order everywhere it matters at once — the step 3 screen, the Templates list, and the exported ZIP, which is built by walking the same list.

## 1.1.1

**Before:** the admin link did not appear at all on some sites. Template Kit Export does not leave its post type on the usual `edit.php` menu, so attaching to that parent registered the page without rendering a link to it.

**Now:** the post type is asked where it actually lives, each candidate is checked against the menus that exist, and `tools.php` is the fallback. The menu is also registered late, so other plugins' menus are in place to be found.

**Before:** the row checkbox and the naming option were easy to confuse, and submitting with nothing ticked reported "0 imported" like a failure.

**Now:** the first column is labelled **Import**, the batch options sit in their own box that says they are not row selectors, a live count sits next to the submit button, and submitting with nothing ticked says so instead of running.

## 1.1.0

**Before:** serial numbers started out pre-filled with a guess, which read like a real position someone had chosen.

**Now:** they start empty, because only you know the running order. Rows left blank are imported after everything that has a number.

Also added: a screenshot picker per row off the WordPress media library, and a page layout setting for the import, defaulting to Elementor Canvas so the theme header and footer do not show around the design.

## 1.0.0

First release. Lists everything built with Elementor, copies what you tick into the Template Kit library with a serial number per template, and verifies every copy character for character against its source.

The verification is there for a specific reason. `update_post_meta()` runs `wp_unslash()` on the value it is given, which strips a layer of backslashes out of Elementor's JSON:

```
"https:\/\/site.com"  ->  "https://site.com"   (shorter, still valid)
"he said \"hi\""      ->  "he said "hi""       (JSON now broken)
```

Elementor's own code wraps the value in `wp_slash()` for the same reason. Without it, any design containing a quote mark lands in the kit unparseable and opens as a blank canvas. This plugin does the same, then reads back what it wrote to confirm the copy landed intact rather than assuming it did.

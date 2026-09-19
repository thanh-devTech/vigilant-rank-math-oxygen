# Vigilant Rank Math Oxygen Meta

Small compatibility plugin for Vigilant360 pages built with Oxygen.

## What it does

- Fills empty Rank Math meta descriptions from Oxygen builder content.
- Replaces common Rank Math variables if they remain unresolved in the final title or description.
- Adds a `%vigilant_oxygen_excerpt%` Rank Math variable for templates.
- Generates Focus Keywords from the post title when Rank Math has no keyword yet.
- Saves Facebook/Twitter title and description meta from the generated SEO title/description.
- Adds a Tools admin page for backing up current Rank Math meta by AJAX before bulk updating.
- Adds AJAX restore by backup batch ID.
- Adds AJAX delete for old backup batches.
- Supports Posts, Pages, and public custom post types registered by ACF or other plugins.
- Adds list-table bulk actions for supported post types.
- Falls back to the page title if no clean page text can be found.

## URL safety

The plugin does not change permalinks, slugs, canonical URLs, redirects, or any indexed Google URL. It only updates Rank Math meta fields such as SEO title, description, focus keyword, and social title/description.

## Bulk update flow

1. Go to Tools -> Vigilant SEO Bulk Update.
2. Click `Create Backup by AJAX`.
3. Wait for the backup batch ID.
4. Select the post types to update.
5. Click `Run Bulk Update`.


## Restore flow

1. Go to Tools -> Vigilant SEO Bulk Update.
2. Copy one of the recent backup batch IDs.
3. Paste it into `Backup batch`.
4. Click `Restore Backup by AJAX`.

Restore puts the tracked Rank Math meta fields back to their previous values. If a meta field did not exist before the backup, restore removes it again.

The restore action covers SEO title/description, Focus Keywords, and Facebook/Twitter meta. Use `Delete` beside a backup batch only when the batch is no longer needed.

## Rank Math score notes

The plugin fills the fields Rank Math commonly needs for analysis:

- SEO Title
- SEO Description
- Focus Keyword
- Facebook/Twitter title and description

Rank Math can still score lower if the page content itself does not include the focus keyword in headings/body text, has thin content, missing image alt text, or missing internal/external links.

The backup table is:

```text
{prefix}_vrmom_rank_math_meta_backup
```

## Recommended Rank Math setting

In Rank Math -> Titles & Meta -> Pages, set `Single Page Description` to either:

```text
%excerpt%
```

or:

```text
%vigilant_oxygen_excerpt%
```

The custom variable is more explicit, but the plugin also supports the existing `%excerpt%` setting.

## Quick test

After activating the plugin, check a page source:

```sh
curl -L -s https://vigilant360.com/application-modernization/ | grep -i 'meta name="description"'
```

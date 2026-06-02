## GitPress

**GitPress** is a WordPress plugin by [WestCose Labs](https://westcoselabs.com) for an SEO-safe GitHub-to-WordPress content workflow:

1. Keep content partials in GitHub.
2. Reference that file from a shortcode.
3. Let WordPress render the content server-side into the page HTML.

Search engines see the rendered content in the document source. This is the right pattern if you want GitHub-managed content without relying on an iframe or a client-side fetch after page load.

## What it does

- Renders GitHub-hosted `html`, `markdown`, `text`, or `code` files inside any WordPress theme (works great with Divi).
- Accepts either `owner/repo/path` values or a GitHub file URL.
- Caches each rendered block in WordPress for performance.
- Keeps a last-good snapshot so pages can still render if GitHub is temporarily unavailable.
- Supports GitHub push webhooks to invalidate cache for changed files.

## What it does not do

- It does not execute PHP from GitHub.
- It does not execute remote JavaScript widgets for you.
- It does not use iframes.

Those constraints are intentional. They keep the plugin aligned with security and SEO best practices.

## Recommended architecture

Use GitHub as a content source, not as a runtime application host.

Recommended repo structure:

```text
site-content/
  partials/
    home-hero.html
    pricing-faq.html
  docs/
    product-overview.md
```

Best practice:

- Store render-ready HTML partials when the content should rank.
- Use Markdown for simpler editorial content.
- Keep styling in WordPress/theme CSS, not inline in the GitHub partial whenever possible.
- Use one canonical source for each snippet to avoid duplicate-content mess.

## Installation

1. Copy this folder into `wp-content/plugins/gitpress`.
2. Activate **GitPress** in WordPress.
3. Open **GitPress** in the WordPress admin menu.
4. Set:
   - `GitHub token` if you need private repos.
   - `Default cache TTL`.
   - `Webhook secret` if you want push-triggered cache invalidation.

For private repositories, use a fine-grained personal access token with read-only repository contents access.

## Usage

Add a Divi `Code` module, `Text` module, or any WordPress shortcode-aware block and paste a shortcode.

### Option 1: owner/repo/path

```text
[divi_github_content owner="acme" repo="site-content" path="partials/home-hero.html" branch="main" format="html"]
```

### Option 2: direct GitHub file URL

```text
[divi_github_content url="https://github.com/acme/site-content/blob/main/partials/home-hero.html" format="html"]
```

### Markdown example

```text
[divi_github_content owner="acme" repo="site-content" path="docs/product-overview.md" format="markdown"]
```

### Code example

```text
[divi_github_content owner="acme" repo="site-content" path="snippets/example.js" format="code" source_link="true"]
```

## Page-Level Render Modes

When you attach a GitPress shortcode directly to a page or post with the page-level metabox, choose the render mode that matches the layout you want:

- `Theme Wrapped`
  Keeps the normal WordPress/Divi header, menu, footer, and theme wrapper. This is the recommended mode for SEO pages, service pages, and any page that should stay inside the site theme.
- `Full Canvas`
  Uses standalone landing-page behavior where the shortcode output becomes the page body and the theme/global header/footer may be bypassed. This is the recommended mode for campaign pages or custom repo-driven landing pages.

In `Theme Wrapped` mode, the render position setting controls whether the shortcode appears before, after, or instead of the page content area while keeping the surrounding theme chrome intact. GitPress also enables a `Full-width content area` option by default so Divi pages can keep the global header/footer while hiding the default page title and avoiding the usual boxed/sidebar layout where possible.

## Shortcode attributes

| Attribute | Required | Default | Notes |
| --- | --- | --- | --- |
| `url` | No | `""` | GitHub blob URL or `raw.githubusercontent.com` file URL |
| `owner` | Conditionally | `""` | Required when `url` is not provided |
| `repo` | Conditionally | `""` | Required when `url` is not provided |
| `path` | Conditionally | `""` | Required when `url` is not provided |
| `file` | No | `""` | Alias for `path` |
| `branch` | No | `main` | Branch name |
| `format` | No | `html` | `html`, `markdown`, `text`, `code`, or `raw` |
| `ttl` | No | plugin setting | Cache duration in seconds |
| `class` | No | `""` | Extra wrapper classes |
| `wrapper` | No | `section` | `div`, `section`, `article`, `aside` |
| `source_link` | No | `false` | Adds a "View source on GitHub" link |
| `updated_meta` | No | `true` | Shows the last sync timestamp |
| `stale_notice` | No | `false` | Shows a message when fallback content is used |
| `schema` | No | `""` | Optional schema type like `Article` |
| `language` | No | inferred | Language class for `code`/`raw` blocks |

## Webhook setup

If you want content changes to appear quickly without lowering cache TTL too much:

1. Go to your GitHub repository.
2. Open `Settings > Webhooks`.
3. Add a webhook with:
   - Payload URL: the URL shown in the plugin settings page
   - Content type: `application/json`
   - Secret: the same secret saved in the plugin
   - Events: `Just the push event`

When a push changes a tracked file, the plugin purges the matching cache entry so the next page request pulls the fresh version.

## SEO notes

This plugin is SEO-safe when used the intended way:

- The content is rendered server-side by WordPress.
- The content is present in the page HTML returned to crawlers.
- There is no iframe boundary hiding the content in a separate document.
- There is no front-end fetch that waits for JavaScript to populate the block.

Use HTML partials when the exact markup matters for rankings and rich internal linking. Do not treat this plugin like a way to mount a remote app inside a page.

## Cache behavior

- A fresh GitHub response is stored in a WordPress transient.
- The same payload is also kept as a last-good snapshot.
- If GitHub fails later, the last good copy can still render.
- Webhooks can invalidate only the affected file paths instead of blowing away everything.

## Files

```text
gitpress/
  admin/
    class-settings-page.php
  assets/
    style.css
  includes/
    class-cache-handler.php
    class-github-api.php
    class-shortcode-handler.php
    class-webhook-handler.php
  divi-github-sync.php
  QUICKSTART.md
  README.md
```

## Author

Built by [WestCose Labs](https://westcoselabs.com).

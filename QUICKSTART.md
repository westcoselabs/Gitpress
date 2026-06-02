# Quick Start — GitPress by WestCose Labs

## 1. Install the plugin

Place this folder in:

```text
wp-content/plugins/gitpress
```

Then activate **GitPress** in WordPress.

## 2. Configure settings

Open `WordPress Admin > GitPress` and set:

- `GitHub token`
  Only needed for private repositories.
- `Default cache TTL`
  Start with `3600`.
- `Webhook secret`
  Needed if you want GitHub pushes to purge cache automatically.

## 3. Put content in GitHub

Recommended example:

```text
site-content/
  partials/
    home-hero.html
```

Example file:

```html
<section class="hero-copy">
  <h1>Live content from GitHub</h1>
  <p>This markup is rendered by WordPress, not by an iframe.</p>
</section>
```

## 4. Add the shortcode

Use a Divi `Code` module, `Text` module, or any shortcode-aware block:

```text
[divi_github_content owner="acme" repo="site-content" path="partials/home-hero.html" format="html"]
```

Or paste a GitHub file URL directly:

```text
[divi_github_content url="https://github.com/acme/site-content/blob/main/partials/home-hero.html" format="html"]
```

## 4b. Choose the right page-level render mode

If you use the GitPress metabox on a page or post instead of a Divi module:

- `Theme Wrapped` is best for SEO pages and service pages because the normal Divi header, navigation, footer, and theme wrapper stay in place.
- `Theme Wrapped` enables a full-width content area by default so the page can keep the global header and footer while hiding the default page title and avoiding the usual sidebar layout where possible.
- `Full Canvas` is best for standalone campaign pages where the repo-driven markup should become the main page body.

## 5. Optional: connect a webhook

In GitHub:

1. Open the repository.
2. Go to `Settings > Webhooks`.
3. Add a `push` webhook using the URL shown in the GitPress settings page.
4. Use the same secret you saved in WordPress.

## Best-practice reminder

If the content matters for SEO, prefer:

- server-rendered HTML partials
- Markdown rendered server-side

Avoid:

- iframes
- client-side API fetches
- remote JavaScript embeds for primary page copy

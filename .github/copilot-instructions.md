## Quick context

This repository is a small static marketing site for Hexar Games. The main entry is index.html at the repo root. Assets live under Content/ and images live under media/.

Big picture (what matters to an AI coder):
- Static HTML site using UIkit for layout and jQuery for scripting (see Content/uikit/ and Content/js/theme.js).
- Third-party integrations live in index.html: Google Analytics, AdSense meta, and a cookie consent script. Editing the head can affect analytics and ads behavior.
- Forms use jQuery validation and AJAX patterns (look for the career form handler in index.html); form endpoints are external and not included in this repo.

## Where to look first
- index.html — structure, navigation, external integrations.
- Content/js/theme.js — site JS for UI behaviors and form wiring.
- Content/uikit/dist/ and Content/images/ — styles and image assets.
- media/ — promotional images referenced by the page.

## Project-specific conventions & gotchas
- No build system (no package.json or Makefile). Edit source files directly.
- Asset references are often relative and sometimes parent-relative (e.g., ../code.jquery.com/...). Run a local static server to verify asset paths.
- UIkit attributes (uk-*) are used for interactive components — keep them when refactoring.
- jQuery + jquery-validate are used for client-side form validation. If converting to vanilla JS, update both the markup and validation wiring.

## Developer workflows (preview, test, debug)
- Quick local preview: open index.html in a browser or run a tiny static server (PowerShell examples):

```powershell
# from repo root
python -m http.server 8000
# or
npx http-server -p 8000
```

- Debugging tips:
  - Use DevTools to inspect network requests for external scripts — some CDN references are relative and will 404 locally.
  - Check the console for jQuery validation errors; the career form handler redirects to thank-you/index.html on success.

## Concrete edit examples
- Add a game card: copy the pattern used under the games section (id games) — phone mockup, text column, and app-store badges.
- Update meta: edit the <title> and meta tags in index.html.
- Update analytics/ad code: change the gtag('config', 'G-...') ID and AdSense ca-pub meta values.

## Integrations & external dependencies
- Google Analytics tag G-QK9WQHBMS0 is in index.html.
- AdSense account meta ca-pub-4905271610817367 is present.
- Cookie consent loads from an external CDN.
- App store links point externally to Play Store and App Store.

## Micro-rules when editing
- Preserve UIkit attributes and class names unless migrating off UIkit.
- When changing validation/AJAX, update both markup and Content/js/theme.js.
- Verify asset paths with a local static server after edits.

## Missing items to note
- No README, tests, or CI — assume manual workflows.
- Server-side endpoints are not part of this repo.

If you want deployment steps, CI config, or a small README added, say which and I will add them.

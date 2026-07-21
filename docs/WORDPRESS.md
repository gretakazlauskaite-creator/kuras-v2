# WordPress integration

Phase 3 adds the installable plugin in `wordpress/kuras-pricer`. WordPress remains
the presentation, editorial, advertising and SEO layer; it never imports LEA
files and does not own fuel-price tables.

The plugin supports PHP 8.1 and newer. This requirement is intentionally
independent from the backend service, which remains on PHP 8.2.

## Install on staging

1. Copy `wordpress/kuras-pricer` to `wp-content/plugins/kuras-pricer`.
2. Activate **Kuras Pricer** in WordPress.
3. Open **Settings → Kuras Pricer** and set the API base URL, approved Pricer
   news category, map tile URL and attribution.
4. Create the public fuel page and add the **Kuro kainų palyginimas** block. The
   shortcode `[kuras_pricer]` is available for the classic editor.
5. Open **Settings → Permalinks** and save once if `/degaline/{id}/` pages return
   a 404 after deployment.

Do not activate on production until the staging API URL, map provider terms,
Pricer news category and visual acceptance are confirmed.

## Failure and privacy behaviour

- Public browser calls go through the WordPress REST proxy, not directly to the
  backend API.
- Successful API responses are cached briefly and retained as last-known-good
  data for seven days. If the API fails, the plugin serves that copy and marks it
  as stale.
- Browser location is requested only after the visitor presses **Naudoti mano
  vietą**. Coordinates are sent only for that nearby/sorting request and are not
  stored by the plugin.
- The map provider is configurable. Production owners remain responsible for
  provider terms, attribution, usage limits and privacy disclosures.

## Theme and editorial integration

The plugin inherits the site's `Nunito` font and uses Pricer-compatible tokens:
primary blue `#336688`, orange action `#ffa500`, dark ink `#273646`. The primary
colour is editable in the plugin settings.

The home block queries three published WordPress posts from the configured
category and caches cards for ten minutes. Themes or ad plugins can inject an ad
after the TOP section through:

```php
add_action('kuras_pricer_ad_slot', function (string $slot): void {
    if ($slot === 'after_rankings') {
        // Render the approved Pricer ad component.
    }
});
```

## Release checklist

- API meta, filters, rankings, stations, statistics and viewport map load.
- Mobile list/map tabs, pagination and GPS consent work.
- Stale-copy and complete-outage messages are readable.
- A station link opens its server-rendered profile and history graph.
- The approved news category and ad slot are correct.
- WordPress, PHP and existing backend CI checks pass.

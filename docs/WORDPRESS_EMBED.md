# WordPress embed without a plugin

The fuel application remains built and updated from GitHub, while visitors see
it inside the existing WordPress page. WordPress stores no fuel prices and runs
no importer.

## One-time WordPress setup

1. Open the WordPress page that should display the fuel application.
2. Choose a full-width page template and hide the page title if the theme allows it.
3. Add one **Custom HTML** block.
4. Paste the snippet below and update the host only if production uses a host
   other than the GitHub Pages review address.

```html
<div id="kuras-pricer-embed" style="position:relative;isolation:isolate;z-index:1;width:100%;max-width:none;pointer-events:auto!important">
  <iframe
    id="kuras-pricer-frame"
    src="https://gretakazlauskaite-creator.github.io/kuras-v2/?embed=1"
    title="Degalų kainos Lietuvoje"
    loading="eager"
    scrolling="no"
    allow="geolocation"
    style="position:relative;z-index:1;display:block;width:100%;min-height:1600px;border:0;pointer-events:auto!important;touch-action:auto"
  ></iframe>
</div>
<script>
(function () {
  var frame = document.getElementById('kuras-pricer-frame');
  var allowedOrigin = 'https://gretakazlauskaite-creator.github.io';
  frame.style.setProperty('pointer-events', 'auto', 'important');
  frame.style.touchAction = 'auto';
  window.addEventListener('message', function (event) {
    if (event.origin !== allowedOrigin || event.source !== frame.contentWindow) return;
    if (!event.data || event.data.type !== 'kuras-pricer:height') return;
    var height = Math.max(800, Math.min(12000, Number(event.data.height) || 0));
    frame.style.height = height + 'px';
  });
}());
</script>
```

The `?embed=1` mode hides the duplicate static header and footer. The normal
WordPress header, navigation and footer remain visible around the full fuel
application. The parent validates the message origin before accepting automatic
height changes.

## Operational boundary

- GitHub owns code, LEA ingestion, validation and publishing.
- WordPress owns only the page URL, navigation, surrounding theme and editorial copy.
- A failed import leaves the last successful embedded application online.
- Changing the application later requires no WordPress plugin update.

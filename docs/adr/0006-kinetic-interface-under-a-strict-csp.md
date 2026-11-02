# 0006. A kinetic interface under a strict CSP

- **Status:** accepted
- **Date:** 2026-09-30

## Context

The interface was asked to follow the C2 Montréal site by Koki-Kiko: editorial grotesk type,
ink/paper with a single electric green, pink/orange/purple gradient curtains, expo easing, a page
wipe, and sound design. Three constraints shaped how:

- the Content-Security-Policy is nonce-based with no `'unsafe-inline'`, so **style attributes
  are ignored in production** (the old score bars used `style="width: …"` and rendered empty);
- no Node build step and no third-party runtime (AssetMapper + importmap only);
- the original sound controller stored its AudioContext in `this.context`, which is Stimulus'
  own property: the controller crashed on connect, which is why sound could never be enabled.

## Decision

- Widths and heights are **classes** (`w-0`…`w-100`, `h-0`…`h-100`, steps of five);
  JavaScript may still write custom properties through CSSOM (`el.style.setProperty`), which the
  CSP allows, for pointer-driven effects only.
- Fonts are self-hosted woff2 (Inter Tight and JetBrains Mono, both SIL OFL), served by
  AssetMapper, so `font-src 'self'` stays as is.
- Sound is synthesised with the Web Audio API in one module (`assets/lib/sound.js`) that
  outlives Turbo navigations; controllers only mirror its state. It never plays before a user
  gesture, defaults to on unless reduced motion is requested, and is remembered per browser.
- The page wipe listens to Turbo's own events and only covers link visits to another path;
  frame navigations and filter submissions stay instant. The theme choice is a first-party
  cookie so the server renders `data-theme` without a flash or an inline script.
- `prefers-reduced-motion` disables every animation, the wipe and the cursor ring.

## Consequences

A template must never use `style=""`; `DashboardTest` asserts it on the densest page. New
animated elements hook into `[data-reveal]` / `[data-split]` rather than adding controllers.
In development only, the profiler toolbar's inline script is blocked after a Turbo visit
(its nonce belongs to the first page); production pages carry no inline script.

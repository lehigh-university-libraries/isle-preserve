# Lehigh Islandora

## Collection browsing interfaces

The Pfaffs browser is available at `/pfaffs`. It uses the shared source-browser controller, builder, repository, and template in this module. Collections with the **Journal Browser** display hint appear in its source selector; the current implementation does not restrict that selector to a Pfaffs-specific group.

A future interface for a different set of collections, such as a particular journal, can use its own route and reuse or adapt these browser components. To keep the interfaces' collection choices separate, add an explicit way to assign collections to an interface and apply that grouping when resolving the default source, validating requested sources, building the selector, and finding subject matches across collections. Give each interface its own generated links and test access and navigation for both interfaces. No such grouping is configured yet.

For source-browser data preparation and imports, see [the migration README](../../../../scripts/migration/source-browser/README.md).

## Collection links and existing article zoom links

The display hint makes a collection selectable in the browser; it does not
change links to that collection. To make opted-in collections open in the
browser, generate links to `/pfaffs?source=NODE_ID` for collections carrying the
Journal Browser hint. Apply this consistently to collection cards, search
results, menus, and other collection listings, while leaving unrelated
collections and canonical node pages alone. This is link generation, not a
new redirect. The browser currently requires authentication, so retain existing
collection links for signed-out users until public browser access is supported.
Any rendered link must vary its cache by the relevant access and user context.

Existing article deep links must continue to select the correct page and zoom
region when a reader reaches the browser. The existing Pfaffs CONTENTdm routes
in `lehigh_islandora.routing.yml` can produce a canonical-node URL with a
`pageNumber` query parameter and a `#cdmzoom:ZOOM/X/Y` fragment. The existing
node viewer also recognizes `/page/N` within a fragment. The conversion and
Mirador/OpenSeadragon zoom logic is in the theme's `js/node.js`; it is tied to
the node viewer's `.block-mirador` and viewer settings and is not currently
wired into the source browser. Do not assume the new browser already honors
these fragments.

When implementing browser links, carry the intended source, issue and page into
`/pfaffs` and retain the fragment. URL fragments are only available in the
browser, so any translation of a fragment on an existing page must happen in
JavaScript; the server cannot inspect it. Reuse the existing zoom conversion
rather than inventing a second interpretation. Apply the zoom only after the
correct Mirador canvas and its OpenSeadragon viewer are ready; keep the page
selector, URL, and transcription aligned with that canvas. Handle invalid or
out-of-range fragments without breaking ordinary navigation. This does not
require adding redirects.

Before enabling the links, test actual shared article URLs from each affected
newspaper, including their page and zoom coordinates, direct entry and reload,
back/forward navigation, and browser links with and without fragments. Check
signed-in and signed-out access, mouse and keyboard navigation, and desktop
and mobile viewports. Compare the displayed image region against the current
node viewer; fixture URLs alone cannot establish coordinate fidelity.

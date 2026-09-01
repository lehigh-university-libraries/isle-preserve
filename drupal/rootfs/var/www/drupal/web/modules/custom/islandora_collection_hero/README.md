# Islandora Collection Hero

This module provides an `Islandora collection hero` block for collection browse
pages. It renders the newest accessible image service file attached to the
current Islandora object. When the object has no image service file, it walks
`field_member_of` breadth-first and uses the nearest ancestor hero.

The block expects Islandora's standard `field_media_of`, `field_media_use`,
`field_external_uri`, and `field_member_of` fields. Place it on routes that
provide a `node` parameter; raw numeric View arguments and converted node route
parameters are both supported.

The implementation varies cache entries by route and adds dependencies for all
examined nodes, the selected media entity, and the media list.

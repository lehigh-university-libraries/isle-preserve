# Decision Record for adding embargo functionality to The Preserve

Title: Decision for adding embargo functionality to The Preserve

## Context

When Lehigh migrated from Islandora 7 to Islandora 2, we needed to retain the embargo functionality present on the two Islandora sites we migrated. Only ETD content was using embargoes in Islandora 7, and they were only using file-level embargoes with either a specified or indefinite date.

## Decision

For content needing an embargo, we needed to add file-level embargoes. For content only admins should be able to access, we can simply set the respective node as unpublished.

We initially launched the site using the `discoverygarden/embargo` module, which depends on `discoverygarden/islandora_hierarchical_access`.

As our site was experiencing poor performance (which was mitigated by [our caching strategy](./0002-caching.md)), we began looking into speed improvements using xhprof to identify bottlenecks in our system. The `discoverygarden/islandora_hierarchical_access` module was identified as a major contributor to our performance issues. From [discoverygarden/islandora_hierarchical_access#22](https://github.com/discoverygarden/islandora_hierarchical_access/issues/22)


> with some xhprof investigation today realized the reason our IIIF manifests were taking minutes to render instead of seconds was a combination of the islandora_iiif module's N number of lookups for OCR files per child along with this module's attached access lookups on each of those queries.

So we opted to implement the embargo functionality using a custom [lehigh_embargo](../drupal/rootfs/var/www/drupal/web/modules/custom/lehigh_embargo) module, which uses a field `field_edtf_date_embargo` for our file-level embargoes. A special date `2999-12-31` is used to specify an `indefinite` embargo.

## Consequences

Positive:

- Better site performance for our use case
- Workbench support since the embargo is just an EDTF field

Negative:

- Custom code we need to maintain
- May require future work if we ever need IP embargoes or metadata-level embargoes on The Preserve

# Decision Record for creating PDF derivatives for paged content items

Title: Decision for creating PDF derivatives for paged content items

## Context

A lot of content in The Preserve are paged content items. Meaning, `1` parent item with `N` children.

Our search index does not include results from children items (i.e. `field_model=Page`. The children items do not typically have much unique metadata aside from the OCR text extract from its media, so adding them to our search would clutter up results.

Additionally, we'd prefer to send search result hits for a child to the parent item to get a better view of the underlying materials.

And from a site UX perspective, this provides a convenient way to download all children for a paged content item.

## Decision

Create a microservice, [mergepdf](https://github.com/lehigh-university-libraries/scyllaridae/tree/main/examples/mergepdf), to aggregate all children media into a single PDF. Then attach that PDF to the parent item.

We tried emitting the event using Islandora's standard event action model. Though given this event depends on all children services files getting generated (which in themselves are events on the child original file) the best place to emit the event is when a child service file is being created. Though given how Drupal emits entity events -- namely they're emitted before the object is stored in the database -- we can not run a query to detect if all children of the parent have had their service files created.

So instead, we opted to add a single event in Drupal's queue, and create a queue worker to emit the event on cron runs or when the queue is processed.

## Consequences

Positive:

- A convenient way to download all children images
- Creating the PDF as an original file allows us to leverage how the rest of our repository content is indexed. Namely, since the original file on the paged content item will have OCR created, we get all children page OCR added to our search index

Negative:

- This is a novel event in that it needs to trigger after all children have had their service files generated. This requires some custom logic using Drupal's queue mechanism to emit the event properly. Though this approach might unlock better event management and remove the need for activemq from our stack. So it has potential to simplify our stack and reduce its dependencies, but this will require more work.

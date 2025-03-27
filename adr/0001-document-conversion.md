# Decision Record for choosing a document conversion utility

Title: Decision for choosing a document conversion utility

## Context

[IS-10](https://lehigh.atlassian.net/browse/IS-10) required adding the ability to convert docx, pptx, and xlsx in Islandora to PDF files to allow easy web viewing of these documents.

## Decision

The `libreoffice` utility was ultimately chosen due to its long history, simple install inside a docker container, and ability to handle all microsoft document types.

## Rationale

Several other options were considered other than libreoffice

- [pandoc](https://github.com/jgm/pandoc) - this was the original converter used when only looking at docx files. After discovering there was no community support for doc, pptx, or xlsx file formats as input it was decided not to use this utilty for Microsoft document conversion. However, the PoC using pandoc has been saved should we discover pandoc is a utility we have a need for other document types in the repository
- [jodconverter](https://github.com/jodconverter/jodconverter) - after getting this running the utility didn't appear to be anything more than a wrapper around libreoffice. Resulting what was seen as an unnecessary dependency on top of `libreoffice`
- Several custom python scripts and other utilities were found during the discovery process, and each script seemed tailored to just a specific file type (e.g. xlsx). Given the complexity of one script per file type these types of approaches were not put under serious consideration

## Consequences

Positive:

- A single utility to convert all microsoft document types
- A simple install to get the utility inside a docker container
- A simple command to convert all microsoft documents to PDF

Negative:

- A single utility just for microsoft document types is unfortunate
- Different font types are currently an unknown on how the conversion will look. Only time will tell with what other fonts we may need special libraries installed for
- at over 416MBB compressed it's a pretty large docker container. But that seems fine

## Conclusion

`libreoffice` with the addition of jdoconverter was used in i7, so using libreoffice has some good precedent. With the handful of documents we needed to convert it seems to have done the job well for us.

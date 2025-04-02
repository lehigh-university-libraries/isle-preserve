---
authors: Joe Corall
state: draft
---

# RFD 0000 - Automated Handwritten Text Recognition of Manuscript Document Collections

## Required Approvers

* Special Collections
* Library Technology


## What

Automated Handwritten Text Recognition of Manuscript Document Collections

## Why

To date, the only reliable solution to transcribe handwritten documents has been to manually transcribe these documents, a highly labor-intensive process that only scales through crowdsourcing projects, which struggle to meet the needs of this still-growing area of digitization.
Beyond simple metadata, these handwritten primary source documents remain effectively hidden from researchers despite their rich historical value.   

At Lehigh University, we have created an Islandora-based repository microservice that uses Generative AI to remediate this long-standing problem through the use of new Handwritten Text Recognition (HTR) capabilities.
OpenAI's GPT model was initially selected for early Lehigh prototypes after extensive testing identified it as the most accurate and cost-effective Large Language Model (LLM).  We are now able to automatically index the full text of most hand written manuscript documents within our digital repository.  

## How

### Identifying

First, we need to identify when an item contains handwritten text.

#### Manual detection

We plan to use the `Resource Type` field to determine what material was handwritten.

If the media is a PDF, we will need to split the PDF into individual images and send each image to the HTR service. So PDFs and images are treated similarly.

Should I use `Mixed material` or `Manuscript` if it contains printed text and handwriting? I have also used `Mixed material` to describe items that contain both photographs and text, so it is not a guarantee of handwriting being present.

#### Automated detection

We should also evauluate first sending to tesseract, and evaluting its OCR output to determine if the results did not meet some threshold

### Processing

Once an item is marked as known to contain handwritten text, we will then process the media using our HTR solution.

TODO: flesh out evaulation and testing metrics. One thing we need to ensure is in our test suite are images containing printed text. Will our solution transcribe both? I've read some reports of LLMs struggling with mixed inputs like print and handwriting.


### Testing metrics

TODO: flesh out

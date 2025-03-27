(function ($, Drupal) {
  Drupal.behaviors.lehighIslandoraNodeForm = {
    attach: function (context, settings) {
      function isValidString(inputString) {
        return /^[A-Za-z0-9-_"' ?]+$/.test(inputString);
      }

      $(
        once(
          "populate-title",
          'textarea[name="field_full_title[0][value]"]',
          context,
        ),
      ).each(function () {
        const textarea = document.querySelector(
          'textarea[name="field_full_title[0][value]"]',
        );
        const input = document.querySelector('input[name="title[0][value]"]');
        textarea.addEventListener("input", function () {
          let textareaValue = textarea.value;
          if (textareaValue.length > 255) {
            textareaValue = textareaValue.substring(0, 255);
          }
          input.value = textareaValue;
        });
        $("label").addClass(
          "form-item__label form-item__label--multiple-value-form",
        );
        $("#edit-field-part-detail-1-type").val("volume");
        $("#edit-field-part-detail-2-type").val("issue");
        $("#edit-field-part-detail-3-type").val("page");
        $("#edit-field-part-detail-0-title").attr(
          "placeholder",
          "Publication Title",
        );
        $("#edit-field-edtf-date-issued-0-value").attr(
          "placeholder",
          "YYYY-MM-DD",
        );
        $("#edit-field-input-source-0-value").val("input_form");
        $(
          "#field-self-submission-creator-add-more-wrapper .field-add-more-submit",
        ).val("Add another person");
        const published = document.getElementById("edit-status-value");
        published.checked = FALSE;
        const options = {
          "": "- Select",
          "http://rightsstatements.org/vocab/InC/1.0/": "IN COPYRIGHT",
          "http://rightsstatements.org/vocab/InC-EDU/1.0/":
            "IN COPYRIGHT - EDUCATIONAL USE PERMITTED",
          "http://rightsstatements.org/vocab/InC-NC/1.0/":
            "IN COPYRIGHT - NON-COMMERCIAL USE PERMITTED",
          "http://rightsstatements.org/vocab/CNE/1.0/":
            "I don’t know, get in touch with more information",
        };

        // Find the input element by its ID
        const rights = document.getElementById("edit-field-rights-0-value");

        $(
          'select[name*="field_self_submission_creator"][name*="[inline_entity_form][field_identifier][0][attr0]"]',
        )
          .val("orcid")
          .trigger("change");

        // Create a new select element
        const select = document.createElement("select");
        select.id = rights.id;
        select.name = rights.name;
        select.className = rights.className;
        select.setAttribute(
          "data-drupal-selector",
          rights.getAttribute("data-drupal-selector"),
        );

        // Populate the select element with the options
        for (const [value, text] of Object.entries(options)) {
          const option = document.createElement("option");
          option.value = value;
          option.textContent = text;
          select.appendChild(option);
        }

        // Replace the input element with the new select element
        rights.parentNode.replaceChild(select, rights);
      });
    },
  };
})(jQuery, Drupal);

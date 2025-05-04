document.addEventListener("DOMContentLoaded", function () {
  document
    .querySelectorAll('a[data-bs-target="#accessRequestModal"]')
    .forEach(function (element) {
      element.addEventListener(
        "click",
        function (e) {
          fetch("/ajax/access-request-form")
            .then((response) => response.json())
            .then((data) => {
              var script = document.createElement("script");
              script.type = "text/javascript";
              script.src =
                "https://challenges.cloudflare.com/turnstile/v0/api.js";
              script.onload = function () {
                document.getElementById(
                  "access-request-form-container",
                ).innerHTML = data.form;
                Drupal.attachBehaviors();
              };
              document.head.appendChild(script);
            })
            .catch(() => console.error("Failed to load access request form."));
        },
        { once: true },
      );
    });
});

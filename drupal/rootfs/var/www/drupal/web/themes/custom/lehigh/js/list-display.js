(function ($, Drupal) {
  Drupal.behaviors.lehighListDisplay = {
    attach: function (context, settings) {
      $(once('add-search', '#block-lehigh-content', context)).first().each(function () {
        $('#block-lehigh-content ul li:last-child a').click();
        $('.compond-object-render .node--type-islandora-object').addClass('bg-light');
        $('.row.g-3.w-100').attr('id', 'collapsible-row');
        $('#collapsible-row').after('<div class="float-end"><a href="#" class="float-end" id="expand-link">More &#9660;</a><a href="#" class="float-end d-none" id="collapse-link">Close &#9650;</a></div>');
        document.getElementById("expand-link").addEventListener("click", function(e) {
            e.preventDefault();
            $('#collapse-link').removeClass('d-none');
            $('#expand-link').addClass('d-none');
            const items = document.querySelectorAll("#collapsible-row .col-3");
            for (let i = 4; i < items.length; i++) {
                items[i].style.display = "block";
            }
        });
        document.getElementById("collapse-link").addEventListener("click", function(e) {
          e.preventDefault();
          $('#collapse-link').addClass('d-none');
          $('#expand-link').removeClass('d-none');
          const items = document.querySelectorAll("#collapsible-row .col-3");
          for (let i = 4; i < items.length; i++) {
              items[i].style.display = "none";
          }
      });
    
      });
    }
  }
})(jQuery, Drupal)

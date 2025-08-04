(function ($, Drupal) {
  'use strict';

  async function initMap() {  
    try {
      // Wait for Google Maps to be fully loaded
      while (typeof google === 'undefined' || !google.maps || !google.maps.importLibrary) {
        await new Promise(resolve => setTimeout(resolve, 100));
      }
      const { PlaceAutocompleteElement } = await google.maps.importLibrary("places");
           
      const container = document.getElementById("internship-country-container");
      if (!container) {
        console.log('Container not found');
        return;
      }
           
      const placeAutocomplete = new google.maps.places.PlaceAutocompleteElement();

      container.appendChild(placeAutocomplete);
      
      await new Promise(resolve => setTimeout(resolve, 200));

      const hiddenField = document.getElementById('internship-country-value');

      placeAutocomplete.addEventListener("gmp-select", async ({ placePrediction })  => {
        const place = placePrediction.toPlace();
        await place.fetchFields({ fields: ['location'] });
        if (hiddenField) {
          hiddenField.value = place.location.lat() + ',' + place.location.lng();
        }
      });

      placeAutocomplete.addEventListener('input', () => {
        if (hiddenField) {
          hiddenField.value = '';
        }
      });

    } catch (error) {
      console.error('Error in initMap:', error);
    }
  }

  // Drupal behavior
  Drupal.behaviors.googlePlacesAutocomplete = {
    attach: function (context, settings) {
      const container = context.querySelector ? 
        context.querySelector('#internship-country-container') : 
        document.getElementById('internship-country-container');
      
        
      if (container && !container.hasAttribute('data-initialized')) {
        container.setAttribute('data-initialized', 'true');
        initMap();
      }
    }
  };

})(jQuery, Drupal);
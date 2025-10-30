(function ($, Drupal, cookies) {
  Drupal.behaviors.lehighNode = {
    attach: function (context, settings) {
      $(once('add-search', '.browse', context)).first().each(function () {
        var s = Drupal.behaviors.lehighNode.getQueryParam('search_api_fulltext');
        if (s != null && s != "") {
          $('.browse .node--type-islandora-object a').each(function() {
            let h = $(this).attr('href') + '?search_api_fulltext=' + encodeURIComponent(s);
            $(this).attr('href', h)
          })
        }
      });
      $(once('fix-facet-search', '#main-content a[href*="f%5B0"]', context)).each(function () {
        var href = $(this).attr('href').replace(window.location.pathname, "/browse");
        $(this).attr('href', href);
      });
      $(once('skip', '.block-mirador', context)).first().each(function () {
        if (window.innerWidth < 1200) {
          $('.MuiButtonBase-root[title="Collapse image tools"], button[title="Collapse text overlay options"]').each(function(){
            $(this).click();
          });
        }
        if (!window.location.hash) {
          return;
        }
        const hash = window.location.hash.substring(1);
        const hashParts = hash.split('/');
        const pageIndex = hashParts.indexOf('page');
        let pageValue = null;
        if (pageIndex !== -1 && pageIndex + 1 < hashParts.length) {
          pageValue = parseInt(hashParts[pageIndex + 1], 10);
          if (!isNaN(pageValue)) {
            pageValue -= 1;
          } else {
            pageValue = null;
          }
        }
        let cdmzoomValue = null;
        const cdmzoomIndex = hashParts.findIndex(part => part.startsWith('cdmzoom:'));
        if (cdmzoomIndex !== -1) {
          if (cdmzoomIndex + 2 < hashParts.length) {
            cdmzoomValue = hashParts[cdmzoomIndex] + '/' + hashParts[cdmzoomIndex + 1] + '/' + hashParts[cdmzoomIndex + 2];
          } else {
            cdmzoomValue = hashParts[cdmzoomIndex];
          }
        }
        if (pageValue === null && !cdmzoomValue) {
          return;
        }
        const checkInterval = 100;
        const maxAttempts = 500;
        let attempts = 0;
        const intervalId = setInterval(() => {
          const viewerId = '#' + drupalSettings.mirador_view_id;
          if (typeof Drupal.IslandoraMirador !== 'undefined' &&
              typeof Drupal.IslandoraMirador.instances !== 'undefined' &&
              typeof Drupal.IslandoraMirador.instances[viewerId] !== 'undefined' ) {
            const instance = Drupal.IslandoraMirador.instances[viewerId];
            const state = instance.store.getState();
            const windowId = Object.keys(state.windows)[0];
            if (!windowId
              || typeof state.windows[windowId] == 'undefined'
              || typeof state.windows[windowId].manifestId == 'undefined') {
              return;
            }
            const mid = state.windows[windowId].manifestId;
            if (typeof state.manifests[mid] == 'undefined'
              || typeof state.manifests[mid].json == 'undefined') {
              return;
            }
            const manifest = state.manifests[mid].json;
            if (typeof manifest.sequences == 'undefined'
              || typeof manifest.sequences[0] == 'undefined'
              || typeof manifest.sequences[0].canvases == 'undefined') {
              return;
            }
            clearInterval(intervalId);
            if (pageValue !== null && typeof manifest.sequences[0].canvases[pageValue] !== 'undefined') {
              var action = Mirador.actions.setCanvas(windowId, manifest.sequences[0].canvases[pageValue]['@id']);
              instance.store.dispatch(action);
            }
            if (cdmzoomValue) {
              setTimeout(() => {
                Drupal.behaviors.lehighNode.applyCdmzoom(instance, windowId, cdmzoomValue);
              }, 500);
            }
          } else {
            attempts++;
            if (attempts >= maxAttempts) {
              clearInterval(intervalId);
            }
          }
        }, checkInterval);
      });
    },
    parseCdmzoom: function(cdmzoomString) {
      let paramString = cdmzoomString.startsWith('cdmzoom:') 
        ? cdmzoomString.substring(8) 
        : cdmzoomString;
      const parts = paramString.split('/');
      if (parts.length !== 3) {
        return null;
      }
      const zoom = parseInt(parts[0], 10);
      const x = parseInt(parts[1], 10);
      const y = parseInt(parts[2], 10);
      if (isNaN(zoom) || isNaN(x) || isNaN(y)) {
        return null;
      }
      return { zoom, x, y };
    },
    convertToCoordinates: function(imageWidth, imageHeight, cdmzoomString, viewportMode = 'auto') {
      const params = this.parseCdmzoom(cdmzoomString);
      if (!params) return null;
      const zoomPercent = params.zoom;
      const xCoord = params.x;
      const yCoord = params.y;
      const coordScale = 100.0 / zoomPercent;
      const boxLeft = xCoord * coordScale;
      const boxTop = yCoord * coordScale;
      let useSquared;
      if (viewportMode === 'auto') {
        useSquared = zoomPercent >= 50;
      } else if (viewportMode === 'squared') {
        useSquared = true;
      } else {
        useSquared = false;
      }
      const zoomFactor = zoomPercent / 100.0;
      let viewportWidth, viewportHeight;
      if (useSquared) {
        viewportWidth = (imageWidth * zoomFactor) * zoomFactor;
        viewportHeight = (imageHeight * zoomFactor) * zoomFactor;
      } else {
        viewportWidth = imageWidth * zoomFactor;
        viewportHeight = imageHeight * zoomFactor;
      }
      let boxRight = boxLeft + viewportWidth;
      let boxBottom = boxTop + viewportHeight;
      boxRight = Math.min(boxRight, imageWidth);
      boxBottom = Math.min(boxBottom, imageHeight);
      const boxWidth = boxRight - boxLeft;
      const boxHeight = boxBottom - boxTop;
      const centerX = boxLeft + (boxWidth / 2);
      const centerY = boxTop + (boxHeight / 2);
      return {
        imageWidth: imageWidth,
        imageHeight: imageHeight,
        zoomPercent: zoomPercent,
        x: xCoord,
        y: yCoord,
        box: {
          left: Math.floor(boxLeft),
          top: Math.floor(boxTop),
          right: Math.floor(boxRight),
          bottom: Math.floor(boxBottom),
          width: Math.floor(boxWidth),
          height: Math.floor(boxHeight)
        },
        center: {
          x: Math.floor(centerX),
          y: Math.floor(centerY)
        }
      };
    },
    applyCdmzoom: function(instance, windowId, cdmzoomString) {
      try {
        const state = instance.store.getState();
        const window = state.windows[windowId];
        if (!window || !window.canvasId) {
          return;
        }
        const manifestId = window.manifestId;
        const manifest = state.manifests[manifestId].json;
        const canvasId = window.canvasId;
        let canvas = null;
        if (manifest.sequences && manifest.sequences[0] && manifest.sequences[0].canvases) {
          canvas = manifest.sequences[0].canvases.find(c => c['@id'] === canvasId);
        }
        if (!canvas) {
          return;
        }
        const imageWidth = canvas.width;
        const imageHeight = canvas.height;
        if (!imageWidth || !imageHeight) {
          return;
        }
        const coords = this.convertToCoordinates(imageWidth, imageHeight, cdmzoomString);
        if (!coords) {
          return;
        }
        const regionX = coords.box.left / imageWidth;
        const regionY = coords.box.top / imageHeight;
        const regionWidth = coords.box.width / imageWidth;
        const regionHeight = coords.box.height / imageHeight;
        const zoomFactor = 0.67;
        const viewportWidth = regionWidth * zoomFactor;
        const viewportHeight = regionHeight * zoomFactor;
        const viewportX = regionX + (regionWidth - viewportWidth) / 2;
        const viewportY = regionY + (regionHeight - viewportHeight) / 2;
        const osdContainerId = `${windowId}-osd`;
        const osdContainer = document.getElementById(osdContainerId);
        let viewer = null;
        if (osdContainer) {
          const reactKey = Object.keys(osdContainer).find(k => 
            k.startsWith('__reactInternalInstance') || 
            k.startsWith('__reactFiber')
          );
          if (reactKey) {
            const findViewer = (node, visited = new Set(), depth = 0) => {
              if (!node || depth > 50 || visited.has(node)) return null;
              visited.add(node);
              for (let key in node) {
                const val = node[key];
                if (val && typeof val === 'object') {
                  if (val.viewport && val.world && typeof val.viewport.fitBounds === 'function') {
                    return val;
                  }
                }
              }
              const searchProps = ['return', 'child', 'sibling', 'stateNode', 'memoizedProps', 'memoizedState'];
              for (let prop of searchProps) {
                if (node[prop]) {
                  const result = findViewer(node[prop], visited, depth + 1);
                  if (result) return result;
                }
              }
              return null;
            };
            viewer = findViewer(osdContainer[reactKey]);
          }
        }
        if (viewer && viewer.viewport) {
          const rect = viewer.viewport.imageToViewportRectangle(
            coords.box.left,
            coords.box.top,
            coords.box.width * zoomFactor,
            coords.box.height * zoomFactor
          );
          viewer.viewport.fitBounds(rect, true);
        } else {
          const zoom = 1 / viewportWidth;
          const action = Mirador.actions.updateViewport(windowId, {
            x: viewportX,
            y: viewportY,
            width: viewportWidth,
            height: viewportHeight,
            zoom: zoom,
            flip: false,
            rotation: 0
          });
          instance.store.dispatch(action);
        }
      } catch (error) {
        console.error('Error applying cdmzoom:', error);
      }
    },
    getQueryParam: function(parameterName) {
      var queryString = window.location.search.substring(1);
      var queryParams = queryString.split('&');
      for (var i = 0; i < queryParams.length; i++) {
          var pair = queryParams[i].split('=');
          if (decodeURIComponent(pair[0]) === parameterName) {
              return decodeURIComponent(pair[1]);
          }
      }
      return null;
    }
  };
})(jQuery, Drupal, window.Cookies);
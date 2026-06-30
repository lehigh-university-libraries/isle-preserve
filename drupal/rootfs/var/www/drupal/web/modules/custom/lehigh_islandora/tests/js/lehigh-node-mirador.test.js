const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function loadLehighNodeBehavior() {
  const drupal = { behaviors: {} };
  const context = {
    Drupal: drupal,
    jQuery: function () {},
    window: {
      Cookies: {},
      location: {
        search: '',
      },
    },
    console,
  };
  context.global = context;
  vm.createContext(context);

  const scriptPath = path.resolve(__dirname, '../../../../../themes/custom/lehigh/js/node.js');
  const script = fs.readFileSync(scriptPath, 'utf8');
  vm.runInContext(script, context, { filename: scriptPath });

  return {
    behavior: drupal.behaviors.lehighNode,
    context,
  };
}

test('getManifestCanvases supports IIIF Presentation 2 manifests', () => {
  const { behavior } = loadLehighNodeBehavior();
  const manifest = {
    sequences: [
      {
        canvases: [
          { '@id': 'https://example.test/iiif/canvas/1', width: 1000, height: 1500 },
          { '@id': 'https://example.test/iiif/canvas/2', width: 1200, height: 1600 },
        ],
      },
    ],
  };

  const canvases = behavior.getManifestCanvases(manifest);

  assert.equal(canvases.length, 2);
  assert.equal(behavior.getCanvasId(canvases[1]), 'https://example.test/iiif/canvas/2');
});

test('getManifestCanvases supports IIIF Presentation 3 manifests', () => {
  const { behavior } = loadLehighNodeBehavior();
  const manifest = {
    type: 'Manifest',
    items: [
      { id: 'https://example.test/iiif/canvas/1', type: 'Canvas', width: 1000, height: 1500 },
      { id: 'https://example.test/iiif/canvas/2', type: 'Canvas', width: 1200, height: 1600 },
    ],
  };

  const canvases = behavior.getManifestCanvases(manifest);

  assert.equal(canvases.length, 2);
  assert.equal(behavior.getCanvasId(canvases[1]), 'https://example.test/iiif/canvas/2');
});

test('getCanvasById finds canvases in Presentation 2 and Presentation 3 manifests', () => {
  const { behavior } = loadLehighNodeBehavior();
  const presentation2 = {
    sequences: [
      {
        canvases: [
          { '@id': 'canvas-1', width: 1000, height: 1500 },
          { '@id': 'canvas-2', width: 1200, height: 1600 },
        ],
      },
    ],
  };
  const presentation3 = {
    items: [
      { id: 'canvas-1', width: 1000, height: 1500 },
      { id: 'canvas-2', width: 1200, height: 1600 },
    ],
  };

  assert.equal(behavior.getCanvasById(presentation2, 'canvas-2').width, 1200);
  assert.equal(behavior.getCanvasById(presentation3, 'canvas-2').width, 1200);
  assert.equal(behavior.getCanvasById(presentation3, 'missing-canvas'), null);
});

test('applyCdmzoom can resolve a Presentation 3 canvas and dispatch a viewport update', () => {
  const { behavior, context } = loadLehighNodeBehavior();
  const dispatched = [];
  context.Mirador = {
    actions: {
      updateViewport: (windowId, viewport) => ({ type: 'UPDATE_VIEWPORT', windowId, viewport }),
    },
  };
  context.document = {
    getElementById: () => null,
  };

  const instance = {
    store: {
      getState: () => ({
        windows: {
          windowA: {
            manifestId: 'manifestA',
            canvasId: 'canvas-2',
          },
        },
        manifests: {
          manifestA: {
            json: {
              items: [
                { id: 'canvas-1', width: 1000, height: 1500 },
                { id: 'canvas-2', width: 1200, height: 1600 },
              ],
            },
          },
        },
      }),
      dispatch: action => dispatched.push(action),
    },
  };

  behavior.applyCdmzoom(instance, 'windowA', 'cdmzoom:25/10/20');

  assert.equal(dispatched.length, 1);
  assert.equal(dispatched[0].type, 'UPDATE_VIEWPORT');
  assert.equal(dispatched[0].windowId, 'windowA');
  assert.ok(dispatched[0].viewport.width > 0);
  assert.ok(dispatched[0].viewport.height > 0);
});

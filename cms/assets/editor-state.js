(function (global) {
  'use strict';

  function create() {
    var previewGeneration = 0;
    var previewController = null;
    var uploadTail = Promise.resolve();

    return {
      beginPreview: function () {
        previewGeneration += 1;
        if (previewController) { previewController.abort(); }
        previewController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        return { generation: previewGeneration, signal: previewController ? previewController.signal : undefined };
      },
      isCurrentPreview: function (generation) { return generation === previewGeneration; },
      enqueueUpload: function (operation) {
        var next = uploadTail.then(operation, operation);
        uploadTail = next.catch(function () {});
        return next;
      }
    };
  }

  global.PagecoreEditorState = { create: create };
})(typeof window !== 'undefined' ? window : globalThis);

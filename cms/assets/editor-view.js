(function (global) {
  'use strict';
  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text != null) { node.textContent = text; }
    return node;
  }
  function icon(name) {
    var node = element('span', 'material-symbols-rounded', name);
    node.setAttribute('aria-hidden', 'true');
    return node;
  }
  global.PagecoreEditorView = { element: element, icon: icon };
})(window);

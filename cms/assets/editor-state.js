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

  // Serialize the rendered structure, never innerText: text alone cannot retain
  // Markdown links, media, headings, lists, tables, or code blocks.
  function inlineMarkdown(region) {
    function escapeText(text) {
      return text.replace(/\u00a0/g, ' ')
        .replace(/[\\`*_{}\[\]>#!|~]/g, '\\$&')
        .replace(/^(\s*)([-+])/gm, '$1\\$2')
        .replace(/^(\s*\d+)([.)])/gm, '$1\\$2');
    }
    function children(node) {
      return Array.prototype.map.call(node.childNodes, convert).join('');
    }
    function trim(text) { return text.replace(/^\n+|\n+$/g, ''); }
    function destination(node, attribute) {
      var url = node.getAttribute(attribute) || '';
      var title = node.getAttribute('title');
      return url
        + (title ? (title.includes('"') ? " '" + title + "'" : ' "' + title + '"') : '');
    }
    function convert(node) {
      if (node.nodeType === 3) {
        // Parser-generated whitespace between block elements is not content.
        if (/^\s*\n\s*$/.test(node.nodeValue) && /^(DIV|UL|OL|BLOCKQUOTE|TABLE|THEAD|TBODY|TR)$/.test(node.parentNode.nodeName)) return '';
        var value = node.nodeValue;
        if (node.previousSibling && node.previousSibling.nodeName === 'BR') value = value.replace(/^\n/, '');
        return escapeText(value);
      }
      if (node.nodeType !== 1 || node.classList.contains('cms-inline-actions')) return '';
      var tag = node.nodeName.toLowerCase();
      if (tag === 'pre') {
        var code = node.querySelector('code') || node;
        var text = code.textContent;
        var fences = text.match(/`+/g) || [];
        var fence = '`'.repeat(Math.max(3, ...fences.map(function (run) { return run.length + 1; })));
        var language = (code.getAttribute('class') || '').match(/(?:^|\s)language-([^\s]+)/);
        return fence + (language ? language[1] : '') + '\n' + text + '\n' + fence + '\n\n';
      }
      if (tag === 'code') {
        var value = node.textContent;
        var runs = value.match(/`+/g) || [];
        var delimiter = '`'.repeat(Math.max(1, ...runs.map(function (run) { return run.length + 1; })));
        var pad = /^`|`$|^ .* $/.test(value) && !/^ +$/.test(value) ? ' ' : '';
        return delimiter + pad + value + pad + delimiter;
      }
      if (tag === 'img') return '![' + escapeText(node.getAttribute('alt') || '') + '](' + destination(node, 'src') + ')';
      if (tag === 'br') return '  \n';
      if (tag === 'hr') return '---\n\n';
      if (tag === 'table') {
        var rows = Array.prototype.map.call(node.rows, function (row) {
          return '| ' + Array.prototype.map.call(row.cells, function (cell) { return trim(children(cell)); }).join(' | ') + ' |';
        });
        if (!rows.length) return '';
        var alignment = Array.prototype.map.call(node.rows[0].cells, function (cell) {
          return cell.style.textAlign === 'center' ? ':---:' : cell.style.textAlign === 'right' ? '---:' : cell.style.textAlign === 'left' ? ':---' : '---';
        });
        rows.splice(1, 0, '| ' + alignment.join(' | ') + ' |');
        return rows.join('\n') + '\n\n';
      }
      if (tag === 'ul' || tag === 'ol') {
        var start = Number(node.getAttribute('start') || 1);
        return (node.parentNode.nodeName === 'LI' ? '\n' : '') + Array.prototype.map.call(node.children, function (item, index) {
          var marker = tag === 'ol' ? (start + index) + '. ' : '- ';
          var body = trim(children(item));
          return marker + body.replace(/\n/g, '\n' + ' '.repeat(marker.length));
        }).join('\n') + '\n\n';
      }
      var body = children(node);
      if (/^h[1-6]$/.test(tag)) return '#'.repeat(Number(tag[1])) + ' ' + body + '\n\n';
      if (tag === 'strong' || tag === 'b') return '**' + body + '**';
      if (tag === 'em' || tag === 'i') return '*' + body + '*';
      if (tag === 'del' || tag === 's' || tag === 'strike') return '~~' + body + '~~';
      if (tag === 'a') return '[' + body + '](' + destination(node, 'href') + ')';
      if (tag === 'blockquote') return trim(body).replace(/^/gm, '> ') + '\n\n';
      if (tag === 'p' && node.classList.contains('pdf-download')) {
        var link = node.querySelector('a[download]');
        if (!link) throw new Error('Use Edit to change this PDF block without losing its download link.');
        return 'pdf:' + link.getAttribute('href') + ' "' + link.textContent.replace(/^Download PDF: /, '').replace(/"/g, '&quot;') + '"\n\n';
      }
      if (tag === 'p' || tag === 'div' || tag === 'figure') return trim(body) + '\n\n';
      if (tag === 'span') return body;
      throw new Error('Use Edit to change this content without losing its formatting.');
    }
    return trim(children(region)) + '\n';
  }

  // Compare the proposed Markdown's server-rendered content with the edited
  // DOM before publishing. Unsupported formatting must never be silently lost.
  function inlineContentSignature(region) {
    function children(node, verbatim) {
      return Array.prototype.map.call(node.childNodes, function (child) { return convert(child, verbatim); }).filter(Boolean);
    }
    function convert(node, verbatim) {
      if (node.nodeType === 3) {
        if (!verbatim && /^\s*\n\s*$/.test(node.nodeValue)) return null;
        return verbatim ? node.nodeValue : node.nodeValue.replace(/\s+/g, ' ');
      }
      if (node.nodeType !== 1 || node.classList.contains('cms-inline-actions')) return null;
      var tag = node.nodeName.toLowerCase();
      tag = { b: 'strong', i: 'em', s: 'del', strike: 'del' }[tag] || tag;
      var attributes = Array.prototype.filter.call(node.attributes, function (attribute) {
        return attribute.name !== 'contenteditable' && attribute.name !== 'spellcheck';
      }).map(function (attribute) { return [attribute.name, attribute.value]; }).sort();
      return [tag, attributes, children(node, verbatim || tag === 'pre' || tag === 'code')];
    }
    return JSON.stringify(children(region, false));
  }

  global.PagecoreEditorState = { create: create, inlineMarkdown: inlineMarkdown, inlineContentSignature: inlineContentSignature };
})(typeof window !== 'undefined' ? window : globalThis);

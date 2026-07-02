/* CMS inline editor — vanilla JS, no build step. Loaded only when logged in. */
(function () {
    'use strict';
    var CFG = window.CMS_CONFIG || {};
    var API = CFG.api || '/cms/api.php';
    var TOKEN = CFG.token || '';

    /* ---------------------------------------------------------- helpers */
    function h(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text != null) el.textContent = text;
        return el;
    }
    function api(action, data, isForm) {
        var opts = { method: 'POST', headers: { 'X-CMS-Token': TOKEN } };
        if (isForm) {
            opts.body = data;
        } else {
            var body = new URLSearchParams();
            Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
            opts.body = body;
        }
        return fetch(API + '?action=' + encodeURIComponent(action), opts)
            .then(function (r) { return r.json(); });
    }
    function apiGet(key) {
        return fetch(API + '?action=get&key=' + encodeURIComponent(key), {
            headers: { 'X-CMS-Token': TOKEN }
        }).then(function (r) { return r.json(); });
    }
    function insertAtCaret(ta, text) {
        var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
        var pad = (s > 0 && v[s - 1] !== '\n') ? '\n\n' : '';
        ta.value = v.slice(0, s) + pad + text + '\n' + v.slice(e);
        var pos = s + pad.length + text.length + 1;
        ta.selectionStart = ta.selectionEnd = pos;
        ta.dispatchEvent(new Event('input'));
        ta.focus();
    }

    /* ---------------------------------------------------------- toolbar */
    function buildToolbar() {
        var bar = h('div', 'cms-toolbar');
        bar.appendChild(h('span', null, 'CMS • zalogowano'));
        var out = h('a', null, 'Wyloguj');
        out.addEventListener('click', function () {
            fetch(API + '?action=logout', { method: 'POST', headers: { 'X-CMS-Token': TOKEN } })
                .then(function () { location.href = '/'; });
        });
        bar.appendChild(out);
        document.body.appendChild(bar);
    }

    /* ------------------------------------------------------ edit buttons */
    function decorateRegions() {
        var regions = document.querySelectorAll('.cms-editable');
        Array.prototype.forEach.call(regions, function (el) {
            var btn = h('button', 'cms-edit-btn', '✎ Edytuj');
            btn.type = 'button';
            btn.addEventListener('click', function (ev) {
                ev.preventDefault(); ev.stopPropagation();
                openEditor(el);
            });
            el.appendChild(btn);
        });
    }

    /* ------------------------------------------------------ editor panel */
    var panel = null;

    function closeEditor(force) {
        if (!panel) return;
        if (!force && panel._dirty && !confirm('Porzucić niezapisane zmiany?')) return;
        document.removeEventListener('keydown', panel._keys);
        panel._overlay.remove();
        panel.remove();
        panel = null;
    }

    function openEditor(regionEl) {
        if (panel) closeEditor(true);
        var key = regionEl.getAttribute('data-cms-key');
        var isPost = key.indexOf('post:') === 0;

        var overlay = h('div', 'cms-overlay');
        overlay.addEventListener('click', function () { closeEditor(false); });

        panel = h('div', 'cms-panel');
        panel._overlay = overlay;
        panel._dirty = false;

        var head = h('div', 'cms-panel-head');
        head.appendChild(h('strong', null, 'Edycja: ' + key));
        var close = h('button', 'cms-panel-close', '×');
        close.type = 'button';
        close.addEventListener('click', function () { closeEditor(false); });
        head.appendChild(close);
        panel.appendChild(head);

        var body = h('div', 'cms-panel-body');
        panel.appendChild(body);

        /* post meta form */
        var metaInputs = null;
        if (isPost) {
            var meta = h('div', 'cms-meta');
            metaInputs = {};
            [['title', 'Tytuł', 'text', 'cms-field-wide'],
             ['date', 'Data (RRRR-MM-DD)', 'text', ''],
             ['category', 'Kategoria', 'select', ''],
             ['excerpt', 'Zajawka (lead — opcjonalna)', 'text', 'cms-field-wide']
            ].forEach(function (def) {
                var field = h('div', 'cms-field' + (def[3] ? ' ' + def[3] : ''));
                var lab = h('label', null, def[1]);
                field.appendChild(lab);
                var input;
                if (def[2] === 'select') {
                    input = document.createElement('select');
                    // categories injected server-side into CMS_CONFIG? keep static list from DOM if absent
                    (CFG.categories || []).forEach(function (c) {
                        var o = document.createElement('option');
                        o.value = c[0]; o.textContent = c[1];
                        input.appendChild(o);
                    });
                } else {
                    input = document.createElement('input');
                    input.type = 'text';
                }
                input.addEventListener('input', function () { panel._dirty = true; });
                field.appendChild(input);
                metaInputs[def[0]] = input;
                meta.appendChild(field);
            });
            body.appendChild(meta);
        }

        /* markdown editor */
        var area = h('div', 'cms-editor-area');
        var ta = h('textarea', 'cms-textarea');
        ta.placeholder = 'Ładuję…';
        ta.disabled = true;
        area.appendChild(ta);
        area.appendChild(h('div', 'cms-hint',
            'Markdown (nagłówki #, listy -, tabele |, obrazy ![](url)). ' +
            'Wklej lub przeciągnij obraz/PDF, aby go przesłać.'));
        body.appendChild(area);

        var prevLabel = h('div', 'cms-preview-label', 'Podgląd');
        var preview = h('div', 'cms-preview');
        body.appendChild(prevLabel);
        body.appendChild(preview);

        var foot = h('div', 'cms-panel-foot');
        var save = h('button', 'cms-btn cms-btn-primary', 'Zapisz');
        save.type = 'button';
        var cancel = h('button', 'cms-btn cms-btn-ghost', 'Anuluj');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { closeEditor(false); });
        var status = h('span', 'cms-status', '');
        foot.appendChild(save); foot.appendChild(cancel); foot.appendChild(status);
        panel.appendChild(foot);

        document.body.appendChild(overlay);
        document.body.appendChild(panel);

        /* load current content */
        apiGet(key).then(function (res) {
            if (!res.ok) { status.className = 'cms-status cms-err'; status.textContent = res.error || 'Błąd'; return; }
            ta.value = res.markdown || '';
            ta.disabled = false;
            ta.placeholder = '';
            ta.focus();
            if (isPost && res.meta && metaInputs) {
                metaInputs.title.value = res.meta.title || '';
                metaInputs.date.value = res.meta.date || '';
                metaInputs.category.value = res.meta.category || '';
                metaInputs.excerpt.value = res.meta.excerpt || '';
            }
            renderPreview();
        });

        /* preview (debounced server render) */
        var t = null;
        function renderPreview() {
            api('preview', { markdown: ta.value }).then(function (res) {
                if (res.ok) preview.innerHTML = res.html;
            });
        }
        ta.addEventListener('input', function () {
            panel._dirty = true;
            clearTimeout(t);
            t = setTimeout(renderPreview, 450);
        });

        /* uploads: paste + drag/drop */
        function uploadFile(file) {
            if (!file) return;
            status.className = 'cms-status';
            status.textContent = 'Przesyłanie: ' + file.name + '…';
            var fd = new FormData();
            fd.append('file', file, file.name);
            api('upload', fd, true).then(function (res) {
                if (!res.ok) {
                    status.className = 'cms-status cms-err';
                    status.textContent = res.error || 'Błąd przesyłania';
                    return;
                }
                status.textContent = 'Przesłano: ' + res.url;
                insertAtCaret(ta, res.markdown);
            }).catch(function () {
                status.className = 'cms-status cms-err';
                status.textContent = 'Błąd sieci przy przesyłaniu.';
            });
        }
        ta.addEventListener('paste', function (ev) {
            var files = ev.clipboardData && ev.clipboardData.files;
            if (files && files.length) {
                ev.preventDefault();
                Array.prototype.forEach.call(files, uploadFile);
            }
        });
        ['dragover', 'dragenter'].forEach(function (evName) {
            ta.addEventListener(evName, function (ev) {
                ev.preventDefault();
                ta.classList.add('cms-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evName) {
            ta.addEventListener(evName, function (ev) {
                ev.preventDefault();
                ta.classList.remove('cms-dragover');
            });
        });
        ta.addEventListener('drop', function (ev) {
            var files = ev.dataTransfer && ev.dataTransfer.files;
            if (files && files.length) {
                Array.prototype.forEach.call(files, uploadFile);
            }
        });

        /* save */
        function doSave() {
            save.disabled = true;
            status.className = 'cms-status';
            status.textContent = 'Zapisywanie…';
            var chain = Promise.resolve({ ok: true });
            if (isPost && metaInputs) {
                chain = api('save-post-meta', {
                    slug: key.slice(5),
                    title: metaInputs.title.value,
                    date: metaInputs.date.value,
                    category: metaInputs.category.value,
                    excerpt: metaInputs.excerpt.value
                });
            }
            chain.then(function (m) {
                if (!m.ok) throw new Error(m.error || 'Błąd metadanych');
                return api('save', { key: key, markdown: ta.value });
            }).then(function (res) {
                if (!res.ok) throw new Error(res.error || 'Błąd zapisu');
                /* swap the fragment in place (keep the edit button) */
                var btn = regionEl.querySelector('.cms-edit-btn');
                regionEl.innerHTML = res.html;
                if (btn) regionEl.appendChild(btn);
                panel._dirty = false;
                if (isPost && metaInputs) {
                    var h1 = document.querySelector('main h1');
                    if (h1) h1.textContent = metaInputs.title.value;
                }
                closeEditor(true);
            }).catch(function (err) {
                save.disabled = false;
                status.className = 'cms-status cms-err';
                status.textContent = err.message;
            });
        }
        save.addEventListener('click', doSave);

        panel._keys = function (ev) {
            if (ev.key === 'Escape') { closeEditor(false); }
            if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
                ev.preventDefault();
                doSave();
            }
        };
        document.addEventListener('keydown', panel._keys);
    }

    /* ------------------------------------------------------- add post UI */
    function bindAddPost() {
        Array.prototype.forEach.call(document.querySelectorAll('.cms-add-post'), function (btn) {
            btn.addEventListener('click', function () {
                var cat = btn.getAttribute('data-cms-category');
                var modal = h('div', 'cms-modal');
                var box = h('div', 'cms-modal-box');
                box.appendChild(h('h3', null, 'Nowy wpis'));
                var input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'Tytuł wpisu';
                box.appendChild(input);
                var actions = h('div', 'cms-modal-actions');
                var ok = h('button', 'cms-btn cms-btn-primary', 'Utwórz');
                ok.type = 'button';
                var no = h('button', 'cms-btn cms-btn-ghost', 'Anuluj');
                no.type = 'button';
                no.addEventListener('click', function () { modal.remove(); });
                ok.addEventListener('click', function () {
                    var title = input.value.trim();
                    if (!title) { input.focus(); return; }
                    ok.disabled = true;
                    api('create-post', { title: title, category: cat }).then(function (res) {
                        if (!res.ok) { alert(res.error || 'Błąd'); ok.disabled = false; return; }
                        location.href = res.url + '#cms-edit';
                    });
                });
                input.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') ok.click();
                    if (ev.key === 'Escape') modal.remove();
                });
                actions.appendChild(no); actions.appendChild(ok);
                box.appendChild(actions);
                modal.appendChild(box);
                modal.addEventListener('click', function (ev) { if (ev.target === modal) modal.remove(); });
                document.body.appendChild(modal);
                input.focus();
            });
        });
    }

    /* --------------------------------------------------------------- init */
    function init() {
        buildToolbar();
        decorateRegions();
        bindAddPost();
        if (location.hash === '#cms-edit') {
            var region = document.querySelector('.cms-editable[data-cms-key^="post:"]')
                      || document.querySelector('.cms-editable');
            if (region) openEditor(region);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

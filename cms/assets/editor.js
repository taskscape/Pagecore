/* CMS inline editor — vanilla JS, no build step. Loaded only when logged in. */
(function () {
    'use strict';
    var CFG = window.CMS_CONFIG || {};
    var API = CFG.api || '/cms/api.php';
    var CONTENT = CFG.content || '/cms/content.php';
    var MEDIA = CFG.media || '/cms/media.php';
    var TOKEN = CFG.token || '';
    var VERSION = CFG.version || '';

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
    function apiGetAction(action, key) {
        return fetch(API + '?action=' + encodeURIComponent(action) + '&key=' + encodeURIComponent(key), {
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
        if (VERSION) {
            bar.appendChild(h('span', 'cms-version', 'Pagecore ' + VERSION));
        }
        var content = h('a', null, 'Content');
        content.href = CONTENT;
        content.target = '_blank';
        content.rel = 'noopener';
        bar.appendChild(content);
        var media = h('a', null, 'Media');
        media.href = MEDIA;
        media.target = '_blank';
        media.rel = 'noopener';
        bar.appendChild(media);
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
        window.cmsInsertMedia = null;
        panel._overlay.remove();
        panel.remove();
        panel = null;
    }

    function openEditor(regionEl) {
        if (panel) closeEditor(true);
        var key = regionEl.getAttribute('data-cms-key');
        var isPost = key.indexOf('post:') === 0;
        var currentDraft = null;
        var busy = false;

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

        var draftState = h('div', 'cms-draft-state', 'Ładuję…');
        body.appendChild(draftState);

        /* post meta form */
        var metaInputs = null;
        if (isPost) {
            var meta = h('div', 'cms-meta');
            metaInputs = {};
            [['title', 'Tytuł', 'text', 'cms-field-wide'],
             ['date', 'Data (RRRR-MM-DD)', 'text', ''],
             ['category', 'Kategoria', 'select', ''],
             ['image', 'Featured image URL', 'text', 'cms-field-wide'],
             ['excerpt', 'Zajawka (lead — opcjonalna)', 'text', 'cms-field-wide'],
             ['tags', 'Tagi (oddzielone przecinkami)', 'text', 'cms-field-wide']
            ].forEach(function (def) {
                var field = h('div', 'cms-field' + (def[3] ? ' ' + def[3] : ''));
                var lab = h('label', null, def[1]);
                field.appendChild(lab);
                var input;
                if (def[2] === 'select') {
                    input = document.createElement('select');
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

        var revLabel = h('div', 'cms-preview-label', 'Kopie zapasowe');
        var revisions = h('div', 'cms-revisions', 'Ładuję kopie…');
        body.appendChild(revLabel);
        body.appendChild(revisions);

        var foot = h('div', 'cms-panel-foot');
        var draftBtn = h('button', 'cms-btn cms-btn-primary', 'Zapisz szkic');
        draftBtn.type = 'button';
        var mediaBtn = h('button', 'cms-btn cms-btn-ghost cms-media-open', 'Media library');
        mediaBtn.type = 'button';
        var previewBtn = h('button', 'cms-btn cms-btn-ghost', 'Podgląd szkicu');
        previewBtn.type = 'button';
        var publishBtn = h('button', 'cms-btn cms-btn-publish', 'Opublikuj');
        publishBtn.type = 'button';
        var discardBtn = h('button', 'cms-btn cms-btn-ghost cms-btn-danger', 'Usuń szkic');
        discardBtn.type = 'button';
        var cancel = h('button', 'cms-btn cms-btn-ghost', 'Anuluj');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { closeEditor(false); });
        var status = h('span', 'cms-status', '');
        foot.appendChild(draftBtn);
        foot.appendChild(mediaBtn);
        foot.appendChild(previewBtn);
        foot.appendChild(publishBtn);
        foot.appendChild(discardBtn);
        foot.appendChild(cancel);
        foot.appendChild(status);
        panel.appendChild(foot);

        document.body.appendChild(overlay);
        document.body.appendChild(panel);

        function setStatus(text, isError) {
            status.className = isError ? 'cms-status cms-err' : 'cms-status';
            status.textContent = text || '';
        }
        function syncButtons() {
            var disabled = busy || ta.disabled;
            draftBtn.disabled = disabled;
            mediaBtn.disabled = disabled;
            previewBtn.disabled = disabled;
            publishBtn.disabled = disabled;
            discardBtn.disabled = disabled || !currentDraft;
        }
        function setBusy(value) {
            busy = value;
            syncButtons();
        }
        window.cmsInsertMedia = function (markdown) {
            if (!markdown) { return; }
            insertAtCaret(ta, markdown);
            setStatus('Wstawiono plik z biblioteki.');
        };
        function currentMeta() {
            if (!isPost || !metaInputs) { return {}; }
            return {
                title: metaInputs.title.value,
                date: metaInputs.date.value,
                category: metaInputs.category.value,
                image: metaInputs.image.value,
                excerpt: metaInputs.excerpt.value,
                tags: metaInputs.tags.value
            };
        }
        function currentPayload() {
            var out = { key: key, markdown: ta.value };
            var meta = currentMeta();
            Object.keys(meta).forEach(function (k) { out[k] = meta[k]; });
            return out;
        }
        function setMeta(meta) {
            if (!isPost || !metaInputs) { return; }
            meta = meta || {};
            metaInputs.title.value = meta.title || '';
            metaInputs.date.value = meta.date || '';
            metaInputs.category.value = meta.category || '';
            metaInputs.image.value = meta.image || '';
            metaInputs.excerpt.value = meta.excerpt || '';
            metaInputs.tags.value = meta.tags || '';
        }
        function fillEditor(payload) {
            ta.value = payload.markdown || '';
            setMeta(payload.meta || {});
        }
        function updateDraftState(draft) {
            currentDraft = draft || null;
            draftState.textContent = currentDraft
                ? 'Wczytano szkic zapisany: ' + currentDraft.updated
                : 'Brak zapisanego szkicu. Edycja zaczyna się od wersji opublikowanej.';
            syncButtons();
        }
        function replaceRegionHtml(html) {
            var btn = regionEl.querySelector('.cms-edit-btn');
            regionEl.innerHTML = html || '<p class="cms-empty">(pusty fragment — kliknij, aby edytować)</p>';
            if (btn) regionEl.appendChild(btn);
        }
        function applyPayloadToPage(payload) {
            replaceRegionHtml(payload.html || '');
            if (isPost && payload.meta) {
                var h1 = document.querySelector('main h1');
                if (h1 && payload.meta.title) { h1.textContent = payload.meta.title; }
            }
        }
        function renderRevisions(items) {
            revisions.innerHTML = '';
            if (!items || !items.length) {
                revisions.appendChild(h('p', 'cms-revisions-empty', 'Brak kopii zapasowych dla tego fragmentu.'));
                return;
            }
            items.slice(0, 10).forEach(function (rev) {
                var row = h('div', 'cms-revision-row');
                row.appendChild(h('span', null, rev.label));
                var restore = h('button', 'cms-revision-restore', 'Przywróć');
                restore.type = 'button';
                restore.addEventListener('click', function () { restoreRevision(rev.id, rev.label); });
                row.appendChild(restore);
                revisions.appendChild(row);
            });
        }
        function loadRevisions() {
            apiGetAction('revisions', key).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Nie można pobrać kopii.'); }
                renderRevisions(res.revisions || []);
            }).catch(function (err) {
                revisions.innerHTML = '';
                revisions.appendChild(h('p', 'cms-revisions-empty', err.message));
            });
        }
        function renderPreview() {
            api('preview', { markdown: ta.value }).then(function (res) {
                if (res.ok) preview.innerHTML = res.html;
            });
        }
        function saveDraft(openPreview) {
            var win = openPreview ? window.open('about:blank', '_blank') : null;
            setBusy(true);
            setStatus('Zapisywanie szkicu…');
            return api('save-draft', currentPayload()).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Nie udało się zapisać szkicu.'); }
                panel._dirty = false;
                updateDraftState(res.draft);
                setStatus('Szkic zapisany.');
                if (win && res.draft && res.draft.preview_url) {
                    win.location.href = res.draft.preview_url;
                }
                return res;
            }).catch(function (err) {
                if (win) { win.close(); }
                setStatus(err.message, true);
            }).then(function (res) {
                setBusy(false);
                return res;
            });
        }
        function publishCurrent() {
            if (!confirm('Opublikować tę wersję na stronie?')) { return; }
            setBusy(true);
            setStatus('Publikowanie…');
            api('publish', currentPayload()).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Nie udało się opublikować.'); }
                panel._dirty = false;
                updateDraftState(null);
                applyPayloadToPage(res);
                closeEditor(true);
            }).catch(function (err) {
                setStatus(err.message, true);
            }).then(function () {
                setBusy(false);
            });
        }
        function discardDraft() {
            if (!currentDraft || !confirm('Usunąć zapisany szkic i wrócić do wersji opublikowanej?')) { return; }
            setBusy(true);
            setStatus('Usuwanie szkicu…');
            api('discard-draft', { key: key }).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Nie udało się usunąć szkicu.'); }
                fillEditor(res);
                panel._dirty = false;
                updateDraftState(null);
                renderPreview();
                setStatus('Szkic usunięty.');
            }).catch(function (err) {
                setStatus(err.message, true);
            }).then(function () {
                setBusy(false);
            });
        }
        function restoreRevision(id, label) {
            if (!confirm('Przywrócić kopię z ' + label + '? Zastąpi ona opublikowaną wersję.')) { return; }
            setBusy(true);
            setStatus('Przywracanie kopii…');
            api('restore', { key: key, revision: id }).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Nie udało się przywrócić kopii.'); }
                fillEditor(res);
                applyPayloadToPage(res);
                panel._dirty = false;
                updateDraftState(null);
                renderPreview();
                loadRevisions();
                setStatus('Kopia przywrócona.');
            }).catch(function (err) {
                setStatus(err.message, true);
            }).then(function () {
                setBusy(false);
            });
        }

        /* load current content */
        apiGet(key).then(function (res) {
            if (!res.ok) { setStatus(res.error || 'Błąd', true); return; }
            fillEditor(res.draft || res);
            ta.disabled = false;
            ta.placeholder = '';
            ta.focus();
            updateDraftState(res.draft || null);
            renderPreview();
            loadRevisions();
        }).catch(function () {
            setStatus('Błąd sieci przy pobieraniu treści.', true);
        });

        /* preview (debounced server render) */
        var t = null;
        ta.addEventListener('input', function () {
            panel._dirty = true;
            clearTimeout(t);
            t = setTimeout(renderPreview, 450);
        });

        /* uploads: paste + drag/drop */
        function uploadFile(file) {
            if (!file) return;
            setStatus('Przesyłanie: ' + file.name + '…');
            var fd = new FormData();
            fd.append('file', file, file.name);
            api('upload', fd, true).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Błąd przesyłania'); }
                setStatus('Przesłano: ' + res.url);
                insertAtCaret(ta, res.markdown);
            }).catch(function (err) {
                setStatus(err.message || 'Błąd sieci przy przesyłaniu.', true);
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

        draftBtn.addEventListener('click', function () { saveDraft(false); });
        mediaBtn.addEventListener('click', function () {
            window.open(MEDIA + '?picker=1', 'cms-media', 'width=1100,height=760');
        });
        previewBtn.addEventListener('click', function () { saveDraft(true); });
        publishBtn.addEventListener('click', publishCurrent);
        discardBtn.addEventListener('click', discardDraft);
        syncButtons();

        panel._keys = function (ev) {
            if (ev.key === 'Escape') { closeEditor(false); }
            if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
                ev.preventDefault();
                saveDraft(false);
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

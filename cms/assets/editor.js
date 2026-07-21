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
        bar.appendChild(h('span', null, 'CMS • logged in'));
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
        var out = h('a', null, 'Log out');
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
            var btn = h('button', 'cms-edit-btn', '✎ Edit');
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
        if (!force && panel._dirty && !confirm('Discard unsaved changes?')) return;
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

        var draftState = h('div', 'cms-draft-state', 'Loading…');
        body.appendChild(draftState);

        /* post meta form */
        var metaInputs = null;
        var featuredDrop = null;
        var featuredFileInput = null;
        var featuredPreview = null;
        var featuredSelection = null;
        var featuredRemove = null;
        // Reuse the installation-wide upload limit instead of giving featured images a separate cap.
        var maxFeaturedImageMb = Number(CFG.maxUploadMb || 8);
        if (isPost) {
            var meta = h('div', 'cms-meta');
            metaInputs = {};
            [['title', 'Title', 'text', 'cms-field-wide'],
             ['date', 'Date (YYYY-MM-DD)', 'text', ''],
             ['category', 'Category', 'select', ''],
             ['excerpt', 'Excerpt (optional)', 'text', 'cms-field-wide'],
             ['tags', 'Tags (comma-separated)', 'text', 'cms-field-wide']
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

            // Featured images use a file picker/drop target so editors never need to paste asset URLs.
            var featuredField = h('div', 'cms-field cms-field-wide');
            featuredField.appendChild(h('label', null, 'Featured image'));
            featuredFileInput = document.createElement('input');
            featuredFileInput.type = 'file';
            featuredFileInput.className = 'cms-featured-image-file';
            featuredFileInput.accept = 'image/jpeg,image/png,.jpg,.jpeg,.png';
            featuredFileInput.setAttribute('aria-label', 'Choose featured image');
            featuredDrop = h('div', 'cms-featured-image-drop');
            featuredDrop.tabIndex = 0;
            featuredDrop.setAttribute('role', 'button');
            featuredDrop.setAttribute('aria-label', 'Drag and drop a featured image or choose a file');
            featuredDrop.appendChild(h('strong', null, 'Drop a JPEG or PNG here'));
            featuredDrop.appendChild(h('span', null, 'or click to choose a file — maximum ' + maxFeaturedImageMb + ' MB'));
            featuredPreview = document.createElement('img');
            featuredPreview.className = 'cms-featured-image-preview';
            featuredPreview.alt = 'Selected featured image';
            featuredPreview.hidden = true;
            featuredSelection = h('span', 'cms-featured-image-selection', 'No featured image selected.');
            featuredRemove = h('button', 'cms-featured-image-remove', 'Remove image');
            featuredRemove.type = 'button';
            featuredRemove.hidden = true;
            // Keep the persisted front-matter value out of sight while replacing the editable URL field.
            var featuredImageValue = document.createElement('input');
            featuredImageValue.type = 'hidden';
            metaInputs.image = featuredImageValue;
            featuredField.appendChild(featuredFileInput);
            featuredField.appendChild(featuredDrop);
            featuredField.appendChild(featuredPreview);
            featuredField.appendChild(featuredSelection);
            featuredField.appendChild(featuredRemove);
            featuredField.appendChild(featuredImageValue);
            meta.appendChild(featuredField);
            body.appendChild(meta);
        }

        /* markdown editor */
        var area = h('div', 'cms-editor-area');
        var ta = h('textarea', 'cms-textarea');
        ta.placeholder = 'Loading…';
        ta.disabled = true;
        area.appendChild(ta);
        area.appendChild(h('div', 'cms-hint',
            'Markdown (headings #, lists -, tables |, images ![](url)). ' +
            'Paste or drag an image/PDF to upload it.'));
        body.appendChild(area);

        var prevLabel = h('div', 'cms-preview-label', 'Preview');
        var preview = h('div', 'cms-preview');
        body.appendChild(prevLabel);
        body.appendChild(preview);

        var revLabel = h('div', 'cms-preview-label', 'Revisions');
        var revisions = h('div', 'cms-revisions', 'Loading revisions…');
        body.appendChild(revLabel);
        body.appendChild(revisions);

        var foot = h('div', 'cms-panel-foot');
        var draftBtn = h('button', 'cms-btn cms-btn-primary', 'Save draft');
        draftBtn.type = 'button';
        var mediaBtn = h('button', 'cms-btn cms-btn-ghost cms-media-open', 'Media library');
        mediaBtn.type = 'button';
        var previewBtn = h('button', 'cms-btn cms-btn-ghost', 'Preview draft');
        previewBtn.type = 'button';
        var publishBtn = h('button', 'cms-btn cms-btn-publish', 'Publish');
        publishBtn.type = 'button';
        var discardBtn = h('button', 'cms-btn cms-btn-ghost cms-btn-danger', 'Discard draft');
        discardBtn.type = 'button';
        var cancel = h('button', 'cms-btn cms-btn-ghost', 'Cancel');
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
            // Keep the dedicated upload control in sync with saves and the initial content load.
            if (featuredFileInput) { featuredFileInput.disabled = disabled; }
            if (featuredDrop) {
                featuredDrop.classList.toggle('cms-featured-image-disabled', disabled);
                featuredDrop.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            }
            if (featuredRemove) { featuredRemove.disabled = disabled; }
        }
        function setBusy(value) {
            busy = value;
            syncButtons();
        }
        window.cmsInsertMedia = function (markdown) {
            if (!markdown) { return; }
            insertAtCaret(ta, markdown);
            setStatus('Inserted file from media library.');
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
            updateFeaturedImageDisplay();
        }
        // Show the selected upload while retaining support for images stored by older posts.
        function updateFeaturedImageDisplay() {
            if (!metaInputs || !metaInputs.image || !featuredSelection) { return; }
            var url = metaInputs.image.value;
            featuredSelection.textContent = url ? 'Selected: ' + url : 'No featured image selected.';
            featuredPreview.hidden = !url;
            featuredPreview.removeAttribute('src');
            if (url) { featuredPreview.src = url; }
            featuredRemove.hidden = !url;
        }
        function fillEditor(payload) {
            ta.value = payload.markdown || '';
            setMeta(payload.meta || {});
        }
        function updateDraftState(draft) {
            currentDraft = draft || null;
            draftState.textContent = currentDraft
                ? 'Loaded saved draft: ' + currentDraft.updated
                : 'No saved draft. Editing starts from the published version.';
            syncButtons();
        }
        function replaceRegionHtml(html) {
            var btn = regionEl.querySelector('.cms-edit-btn');
            regionEl.innerHTML = html || '<p class="cms-empty">(empty content — click to edit)</p>';
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
                revisions.appendChild(h('p', 'cms-revisions-empty', 'No saved revisions for this content.'));
                return;
            }
            items.slice(0, 10).forEach(function (rev) {
                var row = h('div', 'cms-revision-row');
                row.appendChild(h('span', null, rev.label));
                var restore = h('button', 'cms-revision-restore', 'Restore');
                restore.type = 'button';
                restore.addEventListener('click', function () { restoreRevision(rev.id, rev.label); });
                row.appendChild(restore);
                revisions.appendChild(row);
            });
        }
        function loadRevisions() {
            apiGetAction('revisions', key).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Could not load revisions.'); }
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
            setStatus('Saving draft…');
            return api('save-draft', currentPayload()).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Could not save draft.'); }
                panel._dirty = false;
                updateDraftState(res.draft);
                setStatus('Draft saved.');
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
            if (!confirm('Publish this version to the site?')) { return; }
            setBusy(true);
            setStatus('Publishing…');
            api('publish', currentPayload()).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Could not publish.'); }
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
            if (!currentDraft || !confirm('Discard the saved draft and return to the published version?')) { return; }
            setBusy(true);
            setStatus('Discarding draft…');
            api('discard-draft', { key: key }).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Could not discard draft.'); }
                fillEditor(res);
                panel._dirty = false;
                updateDraftState(null);
                renderPreview();
                setStatus('Draft discarded.');
            }).catch(function (err) {
                setStatus(err.message, true);
            }).then(function () {
                setBusy(false);
            });
        }
        function restoreRevision(id, label) {
            if (!confirm('Restore revision from ' + label + '? It will replace the published version.')) { return; }
            setBusy(true);
            setStatus('Restoring revision…');
            api('restore', { key: key, revision: id }).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Could not restore revision.'); }
                fillEditor(res);
                applyPayloadToPage(res);
                panel._dirty = false;
                updateDraftState(null);
                renderPreview();
                loadRevisions();
                setStatus('Revision restored.');
            }).catch(function (err) {
                setStatus(err.message, true);
            }).then(function () {
                setBusy(false);
            });
        }

        /* load current content */
        apiGet(key).then(function (res) {
            if (!res.ok) { setStatus(res.error || 'Error', true); return; }
            fillEditor(res.draft || res);
            ta.disabled = false;
            ta.placeholder = '';
            ta.focus();
            updateDraftState(res.draft || null);
            renderPreview();
            loadRevisions();
        }).catch(function () {
            setStatus('Network error while loading content.', true);
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
            setStatus('Uploading: ' + file.name + '…');
            var fd = new FormData();
            fd.append('file', file, file.name);
            api('upload', fd, true).then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Upload error'); }
                setStatus('Uploaded: ' + res.url);
                insertAtCaret(ta, res.markdown);
            }).catch(function (err) {
                setStatus(err.message || 'Network error while uploading.', true);
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

        // Upload a featured image and immediately persist its URL in the post's draft metadata.
        function saveFeaturedImage(file) {
            if (!file || !isPost || busy || ta.disabled) { return; }
            var validMime = file.type === 'image/jpeg' || file.type === 'image/png';
            var validExtension = /\.(jpe?g|png)$/i.test(file.name || '');
            if ((file.type && !validMime) || (!file.type && !validExtension)) {
                setStatus('Featured image must be a JPEG or PNG file.', true);
                return;
            }
            if (file.size > maxFeaturedImageMb * 1024 * 1024) {
                setStatus('Featured image exceeds the ' + maxFeaturedImageMb + ' MB limit.', true);
                return;
            }
            setBusy(true);
            setStatus('Uploading featured image: ' + file.name + '…');
            var fd = new FormData();
            fd.append('file', file, file.name);
            // The shared endpoint receives the scope flag for JPEG/PNG-only server validation.
            fd.append('featured_image', '1');
            api('upload', fd, true).then(function (upload) {
                if (!upload.ok) { throw new Error(upload.error || 'Featured image upload failed.'); }
                metaInputs.image.value = upload.url;
                updateFeaturedImageDisplay();
                panel._dirty = true;
                setStatus('Saving featured image to draft…');
                return api('save-draft', currentPayload());
            }).then(function (saved) {
                if (!saved.ok) { throw new Error(saved.error || 'Could not save featured image to draft.'); }
                panel._dirty = false;
                updateDraftState(saved.draft);
                setStatus('Featured image saved automatically to draft.');
            }).catch(function (err) {
                setStatus(err.message || 'Network error while uploading featured image.', true);
            }).then(function () {
                setBusy(false);
            });
        }
        // The drop target and picker share validation and automatic draft persistence.
        if (featuredFileInput) {
            featuredFileInput.addEventListener('change', function () {
                var file = featuredFileInput.files && featuredFileInput.files[0];
                featuredFileInput.value = '';
                saveFeaturedImage(file);
            });
            featuredDrop.addEventListener('click', function () {
                if (!busy && !ta.disabled) { featuredFileInput.click(); }
            });
            featuredDrop.addEventListener('keydown', function (ev) {
                if ((ev.key === 'Enter' || ev.key === ' ') && !busy && !ta.disabled) {
                    ev.preventDefault();
                    featuredFileInput.click();
                }
            });
            ['dragover', 'dragenter'].forEach(function (evName) {
                featuredDrop.addEventListener(evName, function (ev) {
                    ev.preventDefault();
                    if (!busy && !ta.disabled) { featuredDrop.classList.add('cms-featured-image-dragover'); }
                });
            });
            ['dragleave', 'drop'].forEach(function (evName) {
                featuredDrop.addEventListener(evName, function (ev) {
                    ev.preventDefault();
                    featuredDrop.classList.remove('cms-featured-image-dragover');
                });
            });
            featuredDrop.addEventListener('drop', function (ev) {
                var files = ev.dataTransfer && ev.dataTransfer.files;
                if (files && files.length) { saveFeaturedImage(files[0]); }
            });
            featuredRemove.addEventListener('click', function () {
                // Removing a selection keeps the existing asset intact and marks only post metadata as changed.
                metaInputs.image.value = '';
                updateFeaturedImageDisplay();
                panel._dirty = true;
            });
        }

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
                box.appendChild(h('h3', null, 'New post'));
                var input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'Post title';
                box.appendChild(input);
                var actions = h('div', 'cms-modal-actions');
                var ok = h('button', 'cms-btn cms-btn-primary', 'Create');
                ok.type = 'button';
                var no = h('button', 'cms-btn cms-btn-ghost', 'Cancel');
                no.type = 'button';
                no.addEventListener('click', function () { modal.remove(); });
                ok.addEventListener('click', function () {
                    var title = input.value.trim();
                    if (!title) { input.focus(); return; }
                    ok.disabled = true;
                    api('create-post', { title: title, category: cat }).then(function (res) {
                        if (!res.ok) { alert(res.error || 'Error'); ok.disabled = false; return; }
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

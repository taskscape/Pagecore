const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const sampleRoot = path.join(repoRoot, 'sample-site');
const fixturesContent = path.join(sampleRoot, 'fixtures', 'content');
const fixturesUploads = path.join(sampleRoot, 'fixtures', 'uploads');
const workingContent = path.join(sampleRoot, 'working-content');
const workingUploads = path.join(sampleRoot, 'working-uploads');
const generatedFiles = [
  path.join(sampleRoot, 'search-index.json'),
  path.join(sampleRoot, 'sitemap.xml')
];

function copyDirContents(from, to) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    const source = path.join(from, entry.name);
    const target = path.join(to, entry.name);
    if (entry.isDirectory()) {
      fs.cpSync(source, target, { recursive: true });
    } else {
      fs.copyFileSync(source, target);
    }
  }
}

function resetSampleSite() {
  for (const target of [workingContent, workingUploads, ...generatedFiles]) {
    if (!path.resolve(target).startsWith(path.resolve(sampleRoot))) {
      throw new Error(`Refusing to reset path outside sample site: ${target}`);
    }
    fs.rmSync(target, { recursive: true, force: true });
  }
  copyDirContents(fixturesContent, workingContent);
  copyDirContents(fixturesUploads, workingUploads);
}

async function login(page, next = '/sample-site/') {
  await page.goto(`/cms/login.php?next=${encodeURIComponent(next)}`);
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('pagecore-demo');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('.cms-toolbar')).toBeVisible();
}

async function openEditor(page, key) {
  const region = page.locator(`[data-cms-key="${key}"]`);
  await expect(region).toBeVisible();
  await region.hover();
  await region.locator('.cms-edit-btn').click();
  const panel = page.locator('.cms-panel');
  await expect(panel).toBeVisible();
  return panel;
}

test.beforeEach(() => {
  resetSampleSite();
});

test('visitor sees rendered sample site without editor chrome', async ({ page }) => {
  await page.goto('/sample-site/');

  await expect(page.getByRole('heading', { name: 'Pagecore sample site' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'CMS features on this page' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Launch notes for the sample site' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Showcase' })).toBeVisible();
  await expect(page.locator('.cms-toolbar')).toHaveCount(0);
  await expect(page.locator('link[href="/cms/assets/editor.css"]')).toHaveCount(0);
});

test('failed logins in one browser session do not lock out another session', async ({ browser }) => {
  const samplePort = process.env.PAGECORE_SAMPLE_PORT || '8765';
  const baseUrl = process.env.PAGECORE_BASE_URL || `http://127.0.0.1:${samplePort}`;
  const loginUrl = `${baseUrl}/cms/login.php?next=${encodeURIComponent('/sample-site/')}`;
  const attackerContext = await browser.newContext();
  const editorContext = await browser.newContext();
  try {
    const attackerPage = await attackerContext.newPage();
    await attackerPage.goto(loginUrl);
    // Exhaust this session's throttle to verify it cannot create a global editor lockout.
    for (let attempt = 0; attempt < 5; attempt += 1) {
      await attackerPage.getByLabel('Username').fill('admin');
      await attackerPage.getByLabel('Password').fill('incorrect-password');
      await attackerPage.getByRole('button', { name: 'Sign in' }).click();
    }
    await expect(attackerPage.locator('.error')).toContainText('Too many failed attempts');

    const editorPage = await editorContext.newPage();
    await editorPage.goto(loginUrl);
    await editorPage.getByLabel('Username').fill('admin');
    await editorPage.getByLabel('Password').fill('pagecore-demo');
    await editorPage.getByRole('button', { name: 'Sign in' }).click();
    await expect(editorPage.locator('.cms-toolbar')).toBeVisible();
  } finally {
    await attackerContext.close();
    await editorContext.close();
  }
});

test('showcase demonstrates file-based featured images', async ({ page }) => {
  await page.goto('/sample-site/showcase/');

  await expect(page.getByRole('heading', { name: 'Pagecore file-based content showcase' })).toBeVisible();
  await expect(page.locator('.meta-preview')).toContainText('image: /sample-site/working-uploads/2026/07/featured-pagecore.png');
  await expect(page.locator('.post-card-image[alt="Launch notes for the sample site"]')).toBeVisible();

  await page.getByRole('link', { name: 'Launch notes for the sample site' }).click();
  await expect(page).toHaveURL(/\/sample-site\/post\/launch-notes\/$/);
  await expect(page.locator('.article-image[alt="Launch notes for the sample site"]')).toBeVisible();
});

test('post-login redirects stay on unambiguous local application paths', async ({ page }) => {
  await login(page);
  const invalidTargets = [
    '//evil.example/path',
    '/\\evil.example/path',
    '/%5cevil.example/path',
    '/%255cevil.example/path',
    '/%2fevil.example/path',
    '/%252fevil.example/path',
    'https://evil.example/path',
    '/safe%0d%0aLocation:%20https://evil.example/'
  ];
  for (const target of invalidTargets) {
    const response = await page.request.get(`/cms/login.php?next=${encodeURIComponent(target)}`, { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location, target).toBe('/');
  }

  const validTarget = '/cms/content.php?page=2&q=launch%20notes#posts';
  const valid = await page.request.get(`/cms/login.php?next=${encodeURIComponent(validTarget)}`, { maxRedirects: 0 });
  expect(valid.status()).toBe(302);
  expect(valid.headers().location).toBe(validTarget);
});

test('post links expose Facebook-friendly title, summary, canonical URL, and featured image', async ({ page }) => {
  const samplePort = process.env.PAGECORE_SAMPLE_PORT || '8765';
  const baseUrl = process.env.PAGECORE_BASE_URL || `http://127.0.0.1:${samplePort}`;
  await page.goto('/sample-site/post/launch-notes/');

  await expect(page.locator('meta[property="og:type"]')).toHaveAttribute('content', 'article');
  await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', 'Launch notes for the sample site');
  await expect(page.locator('meta[property="og:description"]')).toHaveAttribute(
    'content',
    'The first sample post demonstrates Markdown body editing, excerpts, categories, generated URLs, and listing pages.'
  );
  await expect(page.locator('meta[property="og:url"]')).toHaveAttribute('content', `${baseUrl}/sample-site/post/launch-notes/`);
  await expect(page.locator('meta[property="og:image"]')).toHaveAttribute(
    'content',
    `${baseUrl}/sample-site/working-uploads/2026/07/featured-pagecore.png`
  );
  await expect(page.locator('meta[property="og:image:alt"]')).toHaveAttribute('content', 'Launch notes for the sample site');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute(
    'content',
    'The first sample post demonstrates Markdown body editing, excerpts, categories, generated URLs, and listing pages.'
  );
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${baseUrl}/sample-site/post/launch-notes/`);
});

test('social summary falls back to post body when no excerpt is authored', async ({ page }) => {
  const postPath = path.join(workingContent, 'posts', 'summer-maintenance.md');
  const withoutExcerpt = fs.readFileSync(postPath, 'utf8').replace(/^excerpt:.*\r?\n/m, '');
  fs.writeFileSync(postPath, withoutExcerpt);
  await page.goto('/sample-site/post/summer-maintenance/');

  await expect(page.locator('meta[property="og:description"]')).toHaveAttribute(
    'content',
    /This event entry demonstrates category filtering and sitemap generation/
  );
  await expect(page.locator('meta[property="og:image"]')).toHaveCount(0);
});

test('non-public posts stay out of every anonymous surface but remain editor-reviewable', async ({ browser }) => {
  const anonymous = await browser.newContext();
  const anonymousPage = await anonymous.newPage();
  try {
    for (const post of [
      { slug: 'private-editor-note', title: 'Private editor note' },
      { slug: 'draft-editor-note', title: 'Draft editor note' }
    ]) {
      const direct = await anonymousPage.request.get(`/sample-site/post/${post.slug}/`);
      expect(direct.status()).toBe(404);

      await anonymousPage.goto('/sample-site/news/');
      await expect(anonymousPage.getByRole('link', { name: post.title })).toHaveCount(0);

      await anonymousPage.goto(`/sample-site/search/?q=${encodeURIComponent(post.title)}`);
      await expect(anonymousPage.getByRole('link', { name: post.title })).toHaveCount(0);

      const searchIndex = await anonymousPage.request.get('/sample-site/search-index.json');
      expect(await searchIndex.text()).not.toContain(post.slug);
      const sitemap = await anonymousPage.request.get('/sample-site/sitemap.xml');
      expect(await sitemap.text()).not.toContain(post.slug);
    }
  } finally {
    await anonymous.close();
  }

  const editor = await browser.newContext();
  const editorPage = await editor.newPage();
  try {
    await login(editorPage, '/sample-site/post/private-editor-note/');
    await expect(editorPage.getByRole('heading', { level: 1, name: 'Private editor note' })).toBeVisible();
    const draft = await editorPage.goto('/sample-site/post/draft-editor-note/');
    expect(draft.ok()).toBeTruthy();
    await expect(editorPage.getByRole('heading', { level: 1, name: 'Draft editor note' })).toBeVisible();
  } finally {
    await editor.close();
  }
});

test('published Markdown escapes executable HTML and unsafe links by default', async ({ page }) => {
  await login(page);

  const panel = await openEditor(page, 'home/hero');
  await panel.locator('textarea').fill([
    '# Safe Markdown',
    '',
    '<script>window.__pagecoreExecutableHtml = "script"</script>',
    '<script>',
    'window.__pagecoreStolenToken = window.CMS_CONFIG.token;',
    'fetch("/cms/api.php?action=save", {',
    '  method: "POST",',
    '  headers: {"Content-Type": "application/x-www-form-urlencoded", "X-CMS-Token": window.CMS_CONFIG.token},',
    '  body: "key=home%2Ffeatures&content=COMPROMISED_BY_STORED_HTML"',
    '});',
    '</script>',
    '',
    '<img src="x" onerror="window.__pagecoreExecutableHtml = \'event-handler\'">',
    '',
    '[Unsafe link](javascript:window.__pagecoreExecutableHtml="link")'
  ].join('\n'));
  page.once('dialog', dialog => dialog.accept());
  await panel.getByRole('button', { name: 'Publish' }).click();

  await page.goto('/sample-site/');
  expect(await page.evaluate(() => window.__pagecoreExecutableHtml)).toBeUndefined();
  expect(await page.evaluate(() => window.__pagecoreStolenToken)).toBeUndefined();
  await expect(page.locator('main script')).toHaveCount(0);
  await expect(page.locator('main img[src="x"]')).toHaveCount(0);
  await expect(page.locator('main')).toContainText('<script>');

  const unsafeLink = page.getByRole('link', { name: 'Unsafe link' });
  await expect(unsafeLink).toBeVisible();
  expect(await unsafeLink.getAttribute('href')).not.toMatch(/^javascript:/i);

  const protectedRegion = await page.request.get('/cms/api.php?action=get&key=home%2Ffeatures');
  expect(protectedRegion.ok()).toBeTruthy();
  expect((await protectedRegion.json()).markdown).not.toContain('COMPROMISED_BY_STORED_HTML');
});

test('editor can see the installed Pagecore version', async ({ page }) => {
  await login(page);

  await expect(page.locator('.cms-toolbar')).toContainText('Pagecore 2.12.1');

  const version = await page.request.get('/cms/api.php?action=version');
  expect(version.ok()).toBeTruthy();
  expect((await version.json()).version).toBe('2.12.1');

  await page.goto('/cms/content.php');
  await expect(page.getByText('Pagecore 2.12.1')).toBeVisible();
});

test('featured image upload accepts JPEG and PNG, saves drafts, and enforces type and size limits', async ({ page }) => {
  await login(page, '/sample-site/post/launch-notes/');
  const panel = await openEditor(page, 'post:launch-notes');
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
    'base64'
  );
  const jpeg = Buffer.from(
    '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/Aaf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/Aaf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
    'base64'
  );
  const featuredInput = panel.getByLabel('Choose featured image');

  // Selecting files exercises the same automatic-save path used by drag and drop.
  await expect(featuredInput).toHaveAttribute('accept', /image\/jpeg,image\/png/);
  await expect(panel.locator('.cms-featured-image-drop')).toContainText('maximum 8 MB');
  await featuredInput.setInputFiles({
    name: 'featured-image.png',
    mimeType: 'image/png',
    buffer: png
  });

  await expect(panel.locator('.cms-status')).toHaveText('Featured image saved automatically to draft.');
  await expect(panel.locator('.cms-featured-image-selection')).toContainText('featured-image');
  await expect(panel.locator('.cms-featured-image-preview')).toBeVisible();
  let draft = fs.readFileSync(path.join(workingContent, '.drafts', 'posts', 'launch-notes.md'), 'utf8');
  expect(draft).toMatch(/image: \/cms\/media-file\.php\?path=\d{4}%2F\d{2}%2Ffeatured-image-[a-f0-9]{6}\.png/);

  await featuredInput.setInputFiles({
    name: 'featured-image.jpeg',
    mimeType: 'image/jpeg',
    buffer: jpeg
  });
  await expect(panel.locator('.cms-status')).toHaveText('Featured image saved automatically to draft.');
  draft = fs.readFileSync(path.join(workingContent, '.drafts', 'posts', 'launch-notes.md'), 'utf8');
  expect(draft).toMatch(/image: \/cms\/media-file\.php\?path=\d{4}%2F\d{2}%2Ffeatured-image-[a-f0-9]{6}\.jpeg/);

  // Browser validation gives immediate feedback for invalid types and files over the shared 8 MB cap.
  await featuredInput.setInputFiles({ name: 'not-featured.gif', mimeType: 'image/gif', buffer: Buffer.from('GIF89a') });
  await expect(panel.locator('.cms-status')).toHaveText('Featured image must be a JPEG or PNG file.');
  await featuredInput.setInputFiles({
    name: 'oversized.png',
    mimeType: 'image/png',
    buffer: Buffer.alloc(8 * 1024 * 1024 + 1)
  });
  await expect(panel.locator('.cms-status')).toHaveText('Featured image exceeds the 8 MB limit.');

  // The API repeats the UI restrictions so crafted requests cannot bypass them.
  const token = await page.evaluate(() => window.CMS_CONFIG && window.CMS_CONFIG.token);
  const invalidType = await page.request.post('/cms/api.php?action=upload', {
    headers: { 'X-CMS-Token': token },
    multipart: {
      featured_image: '1',
      file: { name: 'bypass.gif', mimeType: 'image/gif', buffer: Buffer.from('GIF89a') }
    }
  });
  expect(invalidType.status()).toBe(400);
  expect((await invalidType.json()).error).toContain('JPEG or PNG');
  const oversizedUpload = await page.request.post('/cms/api.php?action=upload', {
    headers: { 'X-CMS-Token': token },
    multipart: {
      featured_image: '1',
      file: { name: 'bypass.png', mimeType: 'image/png', buffer: Buffer.alloc(8 * 1024 * 1024 + 1) }
    }
  });
  expect(oversizedUpload.status()).toBe(400);
  expect((await oversizedUpload.json()).error).toContain('8 MB');
});

test('reusable content and uploads directories ship Apache hardening', () => {
  const contentRules = fs.readFileSync(path.join(repoRoot, 'content', '.htaccess'), 'utf8');
  const uploadRules = fs.readFileSync(path.join(repoRoot, 'uploads', '.htaccess'), 'utf8');

  expect(contentRules).toContain('Require all denied');
  expect(uploadRules).toContain('php_flag engine off');
  expect(uploadRules).toMatch(/FilesMatch[\s\S]*php[\s\S]*Require all denied/);
});

test('development HTTP boundary denies configuration, content, backups, and executable uploads', async ({ page }) => {
  const draftPath = path.join(workingContent, '.drafts', 'pages', 'http-sentinel.md');
  const backupPath = path.join(workingContent, '.backups', 'http-sentinel.md');
  const uploadScript = path.join(workingUploads, 'http-sentinel.php');
  fs.mkdirSync(path.dirname(draftPath), { recursive: true });
  fs.mkdirSync(path.dirname(backupPath), { recursive: true });
  fs.writeFileSync(draftPath, 'PRIVATE_DRAFT_SENTINEL');
  fs.writeFileSync(backupPath, 'PRIVATE_BACKUP_SENTINEL');
  fs.writeFileSync(uploadScript, '<?php echo "EXECUTED_UPLOAD_SENTINEL";');

  const deniedPaths = [
    '/sample-site/config.php',
    '/sample-site/fixtures/content/posts/launch-notes.md',
    '/sample-site/working-content/posts/launch-notes.md',
    '/sample-site/working-content/.drafts/pages/http-sentinel.md',
    '/sample-site/working-content/.backups/http-sentinel.md',
    '/sample-site/working-uploads/http-sentinel.php',
    '/cms/engine.php',
    '/cms/%65ngine.php',
    '/cms/%252e%252e/engine.php',
    '/cms%2Fengine.php',
    '/cms%5Cengine.php',
    '/cms/auth.php',
    '/cms/lib/Parsedown.php',
    '/cms/README.md',
    '/cms/.htaccess'
  ];
  for (const url of deniedPaths) {
    const response = await page.request.get(url);
    expect(response.status(), `${url} must not be HTTP-readable`).toBe(404);
    expect(await response.text()).not.toMatch(/PRIVATE_|EXECUTED_UPLOAD/);
  }
});

test('active uploads are rejected and PDFs are delivered only as downloads', async ({ page }) => {
  await login(page);
  const token = await page.evaluate(() => window.CMS_CONFIG && window.CMS_CONFIG.token);
  expect(token).toBeTruthy();

  const rejectedSvgPayloads = [
    '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
    '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://attacker.invalid/x.svg#x"/></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"/></svg>'
  ];
  for (const [index, payload] of rejectedSvgPayloads.entries()) {
    const response = await page.request.post('/cms/api.php?action=upload', {
      headers: { 'X-CMS-Token': token },
      multipart: {
        file: { name: `active-${index}.svg`, mimeType: 'image/svg+xml', buffer: Buffer.from(payload) }
      }
    });
    expect(response.status()).toBe(400);
    expect((await response.json()).error).toContain('not allowed');
  }

  const polyglot = await page.request.post('/cms/api.php?action=upload', {
    headers: { 'X-CMS-Token': token },
    multipart: {
      file: { name: 'svg-polyglot.png', mimeType: 'image/png', buffer: Buffer.from(rejectedSvgPayloads[1]) }
    }
  });
  expect(polyglot.status()).toBe(400);
  expect((await polyglot.json()).error).toContain('do not match');

  const malformedPng = await page.request.post('/cms/api.php?action=upload', {
    headers: { 'X-CMS-Token': token },
    multipart: {
      file: {
        name: 'truncated.png',
        mimeType: 'image/png',
        buffer: Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex')
      }
    }
  });
  expect(malformedPng.status()).toBe(400);
  expect((await malformedPng.json()).error).toMatch(/contents do not match|Invalid image/);

  const pdfBytes = Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n');
  const pdfUpload = await page.request.post('/cms/api.php?action=upload', {
    headers: { 'X-CMS-Token': token },
    multipart: {
      file: { name: 'security-review.pdf', mimeType: 'application/pdf', buffer: pdfBytes }
    }
  });
  expect(pdfUpload.ok()).toBeTruthy();
  const pdf = await pdfUpload.json();
  expect(pdf.asset.kind).toBe('pdf');
  expect(pdf.url).toMatch(/^\/cms\/media-file\.php\?path=/);

  const download = await page.request.get(pdf.url);
  expect(download.ok()).toBeTruthy();
  expect(download.headers()['content-type']).toContain('application/pdf');
  expect(download.headers()['content-disposition']).toContain('attachment');
  expect(download.headers()['x-content-type-options']).toBe('nosniff');
  expect(download.headers()['content-security-policy']).toContain("sandbox");
  expect(download.headers()['content-security-policy']).toContain("default-src 'none'");
  expect((await download.body()).subarray(0, 5).toString()).toBe('%PDF-');

  const svgDelivery = await page.request.get('/cms/media-file.php?path=2026%2F07%2Fsample-logo.svg');
  expect(svgDelivery.status()).toBe(404);
});

test('editor saves a draft, previews it, publishes, and restores a backup', async ({ page }) => {
  await login(page);

  let panel = await openEditor(page, 'home/hero');
  await panel.locator('textarea').fill('# Draft-only headline\n\nThis copy is visible in preview before it is published.');
  await panel.getByRole('button', { name: 'Save draft' }).click();
  await expect(panel.locator('.cms-draft-state')).toContainText('Loaded saved draft');

  const popupPromise = page.waitForEvent('popup');
  await panel.getByRole('button', { name: 'Preview draft' }).click();
  const preview = await popupPromise;
  await preview.waitForLoadState('domcontentloaded');
  await expect(preview.getByRole('heading', { name: 'Draft-only headline' })).toBeVisible();
  await preview.close();

  await panel.locator('.cms-panel-close').click();
  await page.goto('/sample-site/');
  await expect(page.getByRole('heading', { name: 'Pagecore sample site' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Draft-only headline' })).toHaveCount(0);

  panel = await openEditor(page, 'home/hero');
  await expect(panel.locator('textarea')).toHaveValue(/Draft-only headline/);
  page.once('dialog', dialog => dialog.accept());
  await panel.getByRole('button', { name: 'Publish' }).click();
  await expect(page.getByRole('heading', { name: 'Draft-only headline' })).toBeVisible();

  panel = await openEditor(page, 'home/hero');
  page.once('dialog', dialog => dialog.accept());
  await panel.locator('.cms-revision-restore').first().click();
  await expect(page.locator('main').getByRole('heading', { name: 'Pagecore sample site' })).toBeVisible();
  await expect(page.locator('main').getByRole('heading', { name: 'Draft-only headline' })).toHaveCount(0);
});

test('editor creates a post, publishes body changes, uploads media, and regenerates search and sitemap', async ({ page }) => {
  await login(page, '/sample-site/news/');

  await page.locator('.cms-add-post[data-cms-category="news"]').click();
  await page.locator('.cms-modal input').fill('Playwright Announcement');
  await page.locator('.cms-modal').getByRole('button', { name: 'Create' }).click();
  await expect(page).toHaveURL(/\/sample-site\/post\/playwright-announcement\/#cms-edit$/);

  const panel = page.locator('.cms-panel');
  await expect(panel).toBeVisible();
  await panel.locator('textarea').fill('This post was authored through the sample site test.\n\nIt should appear in search and the sitemap after publishing.');
  page.once('dialog', dialog => dialog.accept());
  await panel.getByRole('button', { name: 'Publish' }).click();

  await page.goto('/sample-site/news/');
  await expect(page.getByRole('link', { name: 'Playwright Announcement' })).toBeVisible();

  const token = await page.evaluate(() => window.CMS_CONFIG && window.CMS_CONFIG.token);
  expect(token).toBeTruthy();
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
    'base64'
  );
  const upload = await page.request.post('/cms/api.php?action=upload', {
    headers: { 'X-CMS-Token': token },
    multipart: {
      file: {
        name: 'pixel.png',
        mimeType: 'image/png',
        buffer: png
      }
    }
  });
  expect(upload.ok()).toBeTruthy();
  const uploaded = await upload.json();
  expect(uploaded.ok).toBe(true);
  expect(uploaded.markdown).toContain('![pixel]');
  await expect.poll(async () => (await page.request.get(uploaded.url)).status()).toBe(200);

  const searchIndex = await page.request.get('/sample-site/search-index.json');
  expect(searchIndex.ok()).toBeTruthy();
  expect(await searchIndex.text()).toContain('Playwright Announcement');

  const sitemap = await page.request.get('/sample-site/sitemap.xml');
  expect(sitemap.ok()).toBeTruthy();
  expect(await sitemap.text()).toContain('/sample-site/post/playwright-announcement/');

  await page.goto('/sample-site/search/?q=Playwright');
  await expect(page.getByRole('link', { name: 'Playwright Announcement' })).toBeVisible();
});

test('post creation skips a slug reserved by another in-flight request', async ({ page }) => {
  await login(page);

  const token = await page.evaluate(() => window.CMS_CONFIG && window.CMS_CONFIG.token);
  expect(token).toBeTruthy();
  const reservedSlug = 'concurrent-post';
  const postsDir = path.join(workingContent, 'posts');
  // Emulate another request holding the exclusive-create reservation before it writes its post file.
  fs.writeFileSync(path.join(postsDir, `${reservedSlug}.md.create.lock`), 'test reservation');

  const response = await page.request.post('/cms/api.php?action=create-post', {
    headers: { 'X-CMS-Token': token },
    form: { title: 'Concurrent Post', category: 'news' }
  });
  expect(response.ok()).toBeTruthy();
  const created = await response.json();
  expect(created.slug).toBe(`${reservedSlug}-2`);
  expect(fs.existsSync(path.join(postsDir, `${reservedSlug}.md`))).toBe(false);
});

test('media library searches assets, edits metadata, inserts existing media, and deletes unused uploads', async ({ page }) => {
  await login(page);

  const token = await page.evaluate(() => window.CMS_CONFIG && window.CMS_CONFIG.token);
  expect(token).toBeTruthy();
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
    'base64'
  );
  const upload = await page.request.post('/cms/api.php?action=upload', {
    headers: { 'X-CMS-Token': token },
    multipart: {
      file: {
        name: 'delete-me-pixel.png',
        mimeType: 'image/png',
        buffer: png
      }
    }
  });
  expect(upload.ok()).toBeTruthy();
  const uploaded = await upload.json();
  expect(uploaded.ok).toBe(true);
  expect(uploaded.asset.rel).toContain('delete-me-pixel');
  expect(uploaded.url).toMatch(/^\/cms\/media-file\.php\?path=/);

  await page.goto('/cms/media.php');
  await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();
  await expect(page.locator('[data-media-rel="2026/07/sample-logo.png"]')).toBeVisible();

  await page.goto('/cms/media.php?q=sample-logo');
  const sampleCard = page.locator('[data-media-rel="2026/07/sample-logo.png"]');
  await expect(sampleCard).toBeVisible();
  await sampleCard.locator('[name="alt"]').fill('Edited library logo');
  await sampleCard.locator('[name="caption"]').fill('Edited caption from Playwright');
  await sampleCard.getByRole('button', { name: 'Save metadata' }).click();
  await expect(sampleCard.locator('.status')).toHaveText('Metadata saved.');

  const meta = JSON.parse(fs.readFileSync(
    path.join(workingUploads, '2026', '07', 'sample-logo.png.meta.json'),
    'utf8'
  ));
  expect(meta).toEqual({
    alt: 'Edited library logo',
    caption: 'Edited caption from Playwright'
  });

  await page.goto('/sample-site/');
  const panel = await openEditor(page, 'home/media');
  const popupPromise = page.waitForEvent('popup');
  await panel.getByRole('button', { name: 'Media library' }).click();
  const media = await popupPromise;
  await media.waitForLoadState('domcontentloaded');
  await media.getByLabel('Search media').fill('sample-logo');
  await media.getByRole('button', { name: 'Search' }).click();
  const pickerCard = media.locator('[data-media-rel="2026/07/sample-logo.png"]');
  await expect(pickerCard).toBeVisible();
  const closePromise = media.waitForEvent('close');
  await pickerCard.getByRole('button', { name: 'Insert' }).click();
  await closePromise;

  await expect(panel.locator('textarea')).toHaveValue(/Edited library logo/);
  await expect(panel.locator('textarea')).toHaveValue(/Edited caption from Playwright/);
  page.once('dialog', dialog => dialog.accept());
  await panel.getByRole('button', { name: 'Publish' }).click();
  await expect(page.locator('main img[alt="Edited library logo"]')).toBeVisible();

  await page.goto(`/cms/media.php?q=${encodeURIComponent(uploaded.asset.rel)}`);
  const uploadedCard = page.locator(`[data-media-rel="${uploaded.asset.rel}"]`);
  await expect(uploadedCard).toBeVisible();
  page.once('dialog', dialog => dialog.accept());
  await uploadedCard.getByRole('button', { name: 'Delete' }).click();
  await expect(uploadedCard).toHaveCount(0);
  await expect.poll(async () => (await page.request.get(uploaded.url)).status()).toBe(404);
});

test('content inventory lists pages, regions, posts, categories, creates missing markdown, and edits navigation', async ({ page }) => {
  await login(page);

  await page.goto('/cms/content.php');
  await expect(page.getByRole('heading', { name: 'Content inventory' })).toBeVisible();
  const sections = page.locator('main [data-section]');
  await expect(sections).toHaveCount(4);
  expect(await sections.locator('h2').allTextContents()).toEqual(['Posts', 'Pages', 'Regions', 'Navigation']);

  const postsSection = sections.filter({ has: page.locator('#posts-title') });
  const pagesSection = sections.filter({ has: page.locator('#pages-title') });
  const regionsSection = sections.filter({ has: page.locator('#regions-title') });
  const navigationSection = sections.filter({ has: page.locator('#nav-title') });
  await expect(postsSection.locator('[data-section-body]')).toBeVisible();
  await expect(pagesSection.locator('[data-section-body]')).toBeHidden();
  await expect(regionsSection.locator('[data-section-body]')).toBeHidden();
  await expect(navigationSection.locator('[data-section-body]')).toBeHidden();

  // Every section can be toggled independently; only Posts starts expanded.
  await postsSection.getByRole('button', { name: 'Collapse' }).click();
  await expect(postsSection.locator('[data-section-body]')).toBeHidden();
  await postsSection.getByRole('button', { name: 'Expand' }).click();
  await pagesSection.getByRole('button', { name: 'Expand' }).click();
  await expect(pagesSection.locator('[data-section-body]')).toBeVisible();
  await pagesSection.getByRole('button', { name: 'Collapse' }).click();
  await pagesSection.getByRole('button', { name: 'Expand' }).click();
  await regionsSection.getByRole('button', { name: 'Expand' }).click();
  await navigationSection.getByRole('button', { name: 'Expand' }).click();
  await expect(page.getByRole('heading', { name: 'Categories' })).toBeVisible();

  await expect(page.getByRole('cell', { name: 'Home', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'Launch notes for the sample site' })).toBeVisible();
  await expect(page.locator('[data-content-region="home/hero"]')).toContainText('Markdown present');

  // Inventory creation requires an explicit category because it is not scoped to a public listing page.
  await page.getByRole('button', { name: '＋ Add post' }).click();
  await page.getByLabel('Post title').fill('Inventory post');
  await page.locator('#post-category').selectOption('news');
  await page.locator('#create-post').click();
  await expect(page).toHaveURL(/\/sample-site\/post\/inventory-post\/#cms-edit$/);
  await expect(page.locator('.cms-panel')).toBeVisible();

  await page.goto('/cms/content.php');
  await expect(page.getByRole('cell', { name: 'Inventory post' })).toBeVisible();
  // The post title doubles as the inventory's edit shortcut, matching the explicit edit action.
  await expect(page.getByRole('link', { name: 'Inventory post' })).toHaveAttribute('href', '/sample-site/post/inventory-post/#cms-edit');

  await page.locator('[data-section]').filter({ has: page.locator('#regions-title') }).getByRole('button', { name: 'Expand' }).click();
  const missing = page.locator('[data-content-region="home/missing-callout"]');
  await expect(missing).toBeVisible();
  await expect(missing).toHaveAttribute('data-content-missing', '1');
  await expect(missing).toContainText('Missing Markdown');
  await missing.getByRole('button', { name: 'Create file' }).click();
  await expect(missing.locator('.status')).toHaveText('Markdown file created.');
  expect(fs.existsSync(path.join(workingContent, 'pages', 'home', 'missing-callout.md'))).toBe(true);

  const inventory = await page.request.get('/cms/api.php?action=content-inventory');
  expect(inventory.ok()).toBeTruthy();
  const inventoryJson = await inventory.json();
  expect(inventoryJson.ok).toBe(true);
  expect(inventoryJson.inventory.regions.some(region => region.key === 'home/missing-callout' && region.exists)).toBe(true);

  await page.locator('[data-section]').filter({ has: page.locator('#nav-title') }).getByRole('button', { name: 'Expand' }).click();
  const navTextarea = page.locator('#nav-json');
  const nav = JSON.parse(await navTextarea.inputValue());
  nav[1].label = 'Articles';
  nav.push({ label: 'Inventory', url: '/sample-site/#inventory', children: [] });
  await navTextarea.fill(JSON.stringify(nav, null, 2));
  await page.getByRole('button', { name: 'Save navigation' }).click();
  await expect(page.locator('#nav-status')).toHaveText('Navigation saved.');

  const navFile = JSON.parse(fs.readFileSync(path.join(workingContent, 'nav.json'), 'utf8'));
  expect(navFile[1].label).toBe('Articles');
  expect(navFile.some(item => item.label === 'Inventory')).toBe(true);

  await page.goto('/sample-site/');
  const primaryNav = page.getByRole('navigation', { name: 'Primary navigation' });
  await expect(primaryNav.getByRole('link', { name: 'Articles' })).toBeVisible();
  await expect(primaryNav.getByRole('link', { name: 'Showcase' })).toBeVisible();
  await expect(primaryNav.getByRole('link', { name: 'Inventory' })).toBeVisible();
  await expect(page.getByText('New content.')).toBeVisible();

  // The inventory delete action removes the published post and refreshes its derived index data.
  await page.goto('/cms/content.php');
  const inventoryPost = page.locator('[data-content-post="inventory-post"]');
  page.once('dialog', dialog => dialog.accept());
  await inventoryPost.getByRole('button', { name: 'Delete' }).click();
  await expect(inventoryPost).toHaveCount(0);
  expect(fs.existsSync(path.join(workingContent, 'posts', 'inventory-post.md'))).toBe(false);
  const deletedPostIndex = await page.request.get('/sample-site/search-index.json');
  expect(await deletedPostIndex.text()).not.toContain('Inventory post');
});

test('content inventory paginates 100 posts and filters by title, slug, and category', async ({ page }) => {
  const postsDir = path.join(workingContent, 'posts');
  fs.mkdirSync(postsDir, { recursive: true });

  // A 101-post fixture proves the screen emits one 100-row page rather than a complete oversized inventory.
  for (let index = 1; index <= 101; index += 1) {
    const category = index % 2 === 0 ? 'news' : 'events';
    const padded = String(index).padStart(3, '0');
    fs.writeFileSync(path.join(postsDir, `inventory-pagination-${padded}.md`), [
      '---',
      `title: Inventory pagination ${index}`,
      `date: 2026-07-${String((index % 28) + 1).padStart(2, '0')}`,
      `category: ${category}`,
      '---',
      'Pagination fixture.'
    ].join('\n'));
  }
  fs.rmSync(path.join(workingContent, 'posts-index.json'), { force: true });

  // Log in through a public page because the inventory deliberately does not render inline-editor chrome.
  await login(page);
  const firstPage = await page.request.get('/cms/api.php?action=content-inventory');
  const firstPageInventory = (await firstPage.json()).inventory;
  expect(firstPageInventory.posts).toHaveLength(100);
  expect(firstPageInventory.post_pagination.per_page).toBe(100);
  expect(firstPageInventory.post_pagination.pages).toBeGreaterThan(1);

  await page.goto('/cms/content.php');
  const postRows = page.locator('[data-content-post]');
  await expect(postRows).toHaveCount(100);
  await page.getByRole('link', { name: 'Next' }).click();
  await expect(page).toHaveURL(/page=2/);
  await expect(postRows).toHaveCount(firstPageInventory.posts_total - 100);

  // The form sends server-side filters: the title search and slug search each keep the result set to one post.
  await page.locator('#post-search').fill('Inventory pagination 100');
  await page.locator('#post-category-filter').selectOption('news');
  await page.getByRole('button', { name: 'Filter' }).click();
  await expect(postRows).toHaveCount(1);
  await expect(page.getByRole('link', { name: 'Inventory pagination 100' })).toBeVisible();

  await page.locator('#post-search').fill('inventory-pagination-101');
  await page.locator('#post-category-filter').selectOption('events');
  await page.getByRole('button', { name: 'Filter' }).click();
  await expect(postRows).toHaveCount(1);
  await expect(page.getByRole('link', { name: 'Inventory pagination 101' })).toBeVisible();
});

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
  await expect(page.locator('.cms-toolbar')).toHaveCount(0);
  await expect(page.locator('link[href="/cms/assets/editor.css"]')).toHaveCount(0);
});

test('editor saves a draft, previews it, publishes, and restores a backup', async ({ page }) => {
  await login(page);

  let panel = await openEditor(page, 'home/hero');
  await panel.locator('textarea').fill('# Draft-only headline\n\nThis copy is visible in preview before it is published.');
  await panel.getByRole('button', { name: 'Zapisz szkic' }).click();
  await expect(panel.locator('.cms-draft-state')).toContainText('Wczytano szkic zapisany');

  const popupPromise = page.waitForEvent('popup');
  await panel.getByRole('button', { name: /Podgl.d szkicu/ }).click();
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
  await panel.getByRole('button', { name: 'Opublikuj' }).click();
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
  await page.locator('.cms-modal').getByRole('button', { name: 'Utwórz' }).click();
  await expect(page).toHaveURL(/\/sample-site\/post\/playwright-announcement\/#cms-edit$/);

  const panel = page.locator('.cms-panel');
  await expect(panel).toBeVisible();
  await panel.locator('textarea').fill('This post was authored through the sample site test.\n\nIt should appear in search and the sitemap after publishing.');
  page.once('dialog', dialog => dialog.accept());
  await panel.getByRole('button', { name: 'Opublikuj' }).click();

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

  await page.goto('/cms/media.php');
  await expect(page.getByRole('heading', { name: 'Media library' })).toBeVisible();
  await expect(page.locator('[data-media-rel="2026/07/sample-logo.svg"]')).toBeVisible();

  await page.goto('/cms/media.php?q=sample-logo');
  const sampleCard = page.locator('[data-media-rel="2026/07/sample-logo.svg"]');
  await expect(sampleCard).toBeVisible();
  await sampleCard.locator('[name="alt"]').fill('Edited library logo');
  await sampleCard.locator('[name="caption"]').fill('Edited caption from Playwright');
  await sampleCard.getByRole('button', { name: 'Save metadata' }).click();
  await expect(sampleCard.locator('.status')).toHaveText('Metadata saved.');

  const meta = JSON.parse(fs.readFileSync(
    path.join(workingUploads, '2026', '07', 'sample-logo.svg.meta.json'),
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
  const pickerCard = media.locator('[data-media-rel="2026/07/sample-logo.svg"]');
  await expect(pickerCard).toBeVisible();
  const closePromise = media.waitForEvent('close');
  await pickerCard.getByRole('button', { name: 'Insert' }).click();
  await closePromise;

  await expect(panel.locator('textarea')).toHaveValue(/Edited library logo/);
  await expect(panel.locator('textarea')).toHaveValue(/Edited caption from Playwright/);
  page.once('dialog', dialog => dialog.accept());
  await panel.getByRole('button', { name: 'Opublikuj' }).click();
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
  await expect(page.getByRole('heading', { name: 'Configured pages' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Editable regions' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Posts' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Categories' })).toBeVisible();

  await expect(page.getByRole('cell', { name: 'Home', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'Launch notes for the sample site' })).toBeVisible();
  await expect(page.locator('[data-content-region="home/hero"]')).toContainText('Markdown present');

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

  const navTextarea = page.locator('#nav-json');
  const nav = JSON.parse(await navTextarea.inputValue());
  nav[1].label = 'Articles';
  nav.push({ label: 'Inventory', url: '/sample-site/#inventory', children: [] });
  await navTextarea.fill(JSON.stringify(nav, null, 2));
  await page.getByRole('button', { name: 'Save navigation' }).click();
  await expect(page.locator('#nav-status')).toHaveText('Navigation saved.');

  const navFile = JSON.parse(fs.readFileSync(path.join(workingContent, 'nav.json'), 'utf8'));
  expect(navFile[1].label).toBe('Articles');
  expect(navFile[4].label).toBe('Inventory');

  await page.goto('/sample-site/');
  const primaryNav = page.getByRole('navigation', { name: 'Primary navigation' });
  await expect(primaryNav.getByRole('link', { name: 'Articles' })).toBeVisible();
  await expect(primaryNav.getByRole('link', { name: 'Inventory' })).toBeVisible();
  await expect(page.getByText('New content.')).toBeVisible();
});

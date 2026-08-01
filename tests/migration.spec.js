const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const fixtureRoot = path.resolve(__dirname, '..', 'sample-site', 'fixtures', 'content');

function readPostFixture(fileName) {
  const source = fs.readFileSync(path.join(fixtureRoot, 'posts', fileName), 'utf8');
  const title = source.match(/^title:\s*(.+)$/m);
  if (!title) {
    throw new Error(`Post fixture ${fileName} has no title front-matter field`);
  }
  return {
    slug: path.basename(fileName, '.md'),
    title: title[1].trim(),
    status: (source.match(/^status:\s*(.+)$/m) || [null, 'publish'])[1].trim()
  };
}

test('migration output contract keeps post slugs unique and directly routeable', async ({ page }) => {
  const posts = fs.readdirSync(path.join(fixtureRoot, 'posts'))
    .filter(fileName => fileName.endsWith('.md'))
    .map(readPostFixture)
    .filter(post => post.status === 'publish');

  expect(posts.length).toBeGreaterThan(0);
  expect(new Set(posts.map(post => post.slug)).size).toBe(posts.length);

  for (const post of posts) {
    const response = await page.goto(`/sample-site/post/${post.slug}/`);
    expect(response.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { level: 1, name: post.title, exact: true })).toBeVisible();
  }
});

test('migration output keeps non-public posts anonymous-inaccessible and editor-reviewable', async ({ browser }) => {
  const posts = fs.readdirSync(path.join(fixtureRoot, 'posts'))
    .filter(fileName => fileName.endsWith('.md'))
    .map(readPostFixture)
    .filter(post => post.status !== 'publish');
  expect(posts.length).toBeGreaterThan(0);

  const anonymous = await browser.newContext();
  try {
    for (const post of posts) {
      const response = await anonymous.request.get(`/sample-site/post/${post.slug}/`);
      expect(response.status()).toBe(404);
    }
  } finally {
    await anonymous.close();
  }

  const editor = await browser.newContext();
  const page = await editor.newPage();
  try {
    await page.goto('/cms/login.php?next=%2Fsample-site%2F');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('pagecore-demo');
    await page.getByRole('button', { name: 'Sign in' }).click();
    for (const post of posts) {
      const response = await page.goto(`/sample-site/post/${post.slug}/`);
      expect(response.ok()).toBeTruthy();
      await expect(page.getByRole('heading', { level: 1, name: post.title, exact: true })).toBeVisible();
    }
  } finally {
    await editor.close();
  }
});

test('migration output contract keeps navigation URLs unique and reachable', async ({ page }) => {
  const nav = JSON.parse(fs.readFileSync(path.join(fixtureRoot, 'nav.json'), 'utf8'));
  const urls = nav.map(item => item.url);

  expect(nav.length).toBeGreaterThan(0);
  expect(new Set(urls).size).toBe(urls.length);

  await page.goto('/sample-site/');
  const navigation = page.getByRole('navigation', { name: 'Primary navigation' });
  for (const item of nav) {
    await expect(navigation.getByRole('link', { name: item.label, exact: true })).toHaveAttribute('href', item.url);
    const response = await page.request.get(item.url);
    expect(response.ok(), `${item.label} should resolve at ${item.url}`).toBeTruthy();
  }
});

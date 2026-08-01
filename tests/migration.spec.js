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
    title: title[1].trim()
  };
}

test('migration output contract keeps post slugs unique and directly routeable', async ({ page }) => {
  const posts = fs.readdirSync(path.join(fixtureRoot, 'posts'))
    .filter(fileName => fileName.endsWith('.md'))
    .map(readPostFixture);

  expect(posts.length).toBeGreaterThan(0);
  expect(new Set(posts.map(post => post.slug)).size).toBe(posts.length);

  for (const post of posts) {
    const response = await page.goto(`/sample-site/post/${post.slug}/`);
    expect(response.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { level: 1, name: post.title, exact: true })).toBeVisible();
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

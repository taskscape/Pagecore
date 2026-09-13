const fs = require('fs');
const path = require('path');
const assert = require('assert');

const repoRoot = path.resolve(__dirname, '..');
const fixtureRoot = path.join(repoRoot, 'sample-site', 'fixtures');
const contentRoot = path.join(fixtureRoot, 'content');
const uploadsRoot = path.join(fixtureRoot, 'uploads');
const contractPath = path.join(fixtureRoot, 'test-site.json');

function readContract() {
  return JSON.parse(fs.readFileSync(contractPath, 'utf8'));
}

function readPost(fileName) {
  const source = fs.readFileSync(path.join(contentRoot, 'posts', `${fileName}.md`), 'utf8');
  const title = source.match(/^title:\s*(.+)$/m);
  const status = source.match(/^status:\s*(.+)$/m);
  assert(title, `Post fixture ${fileName} has no title front matter.`);
  return { title: title[1].trim(), status: status ? status[1].trim() : 'publish' };
}

function assertTestSiteContract() {
  const contract = readContract();
  assert.strictEqual(contract.schemaVersion, 1, 'Unsupported test-site contract version.');
  assert(Array.isArray(contract.pages) && contract.pages.length > 0, 'Test site needs at least one page.');
  assert(Array.isArray(contract.posts) && contract.posts.length > 0, 'Test site needs at least one post.');

  for (const page of contract.pages) {
    const fragment = path.join(contentRoot, 'pages', `${page.key}.md`);
    assert(fs.existsSync(fragment), `Page fixture is missing: ${page.key}`);
    assert(fs.readFileSync(fragment, 'utf8').includes(page.fixtureText || page.heading), `Page fixture text changed: ${page.key}`);
  }

  for (const expected of contract.posts) {
    const actual = readPost(expected.slug);
    assert.strictEqual(actual.title, expected.title, `Post title changed: ${expected.slug}`);
    assert.strictEqual(actual.status, expected.status, `Post status changed: ${expected.slug}`);
  }

  const navigation = JSON.parse(fs.readFileSync(path.join(contentRoot, 'nav.json'), 'utf8'));
  assert.deepStrictEqual(navigation.map(item => item.label), contract.navigation, 'Test-site navigation changed.');
  for (const upload of contract.uploads) {
    assert(fs.existsSync(path.join(uploadsRoot, upload)), `Upload fixture is missing: ${upload}`);
  }
  return contract;
}

if (require.main === module) {
  const contract = assertTestSiteContract();
  process.stdout.write(`Sample browser test-site contract passed: ${contract.name}.\n`);
}

module.exports = { assertTestSiteContract, readContract };

const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const sampleRoot = path.join(repoRoot, 'sample-site');
const fixturesContent = path.join(sampleRoot, 'fixtures', 'content');
const fixturesUploads = path.join(sampleRoot, 'fixtures', 'uploads');

function isWithin(candidate, root) {
  const resolvedCandidate = path.resolve(candidate);
  const resolvedRoot = path.resolve(root);
  return resolvedCandidate !== resolvedRoot && resolvedCandidate.startsWith(resolvedRoot + path.sep);
}

function copyDirContents(from, to) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    const source = path.join(from, entry.name);
    const target = path.join(to, entry.name);
    if (entry.isDirectory()) fs.cpSync(source, target, { recursive: true });
    else fs.copyFileSync(source, target);
  }
}

function assertFixtureSource(source) {
  if (!fs.existsSync(source) || !fs.statSync(source).isDirectory()) {
    throw new Error(`Sample fixture source is unavailable: ${source}`);
  }
}

function listRelativeFiles(root) {
  const files = [];
  for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
    const relative = entry.name;
    const absolute = path.join(root, relative);
    if (entry.isDirectory()) {
      for (const nested of listRelativeFiles(absolute)) files.push(path.join(relative, nested));
    } else if (entry.isFile()) {
      files.push(relative);
    }
  }
  return files.sort();
}

function assertCopiedFixture(source, target) {
  const sourceFiles = listRelativeFiles(source);
  const targetFiles = listRelativeFiles(target);
  if (JSON.stringify(sourceFiles) !== JSON.stringify(targetFiles)) {
    throw new Error(`Sample reset did not reproduce fixture files at ${target}`);
  }
  for (const relative of sourceFiles) {
    if (!fs.readFileSync(path.join(source, relative)).equals(fs.readFileSync(path.join(target, relative)))) {
      throw new Error(`Sample reset did not reproduce fixture content: ${relative}`);
    }
  }
}

function assertSafeTarget(targetRoot, testRoot) {
  const resolved = path.resolve(targetRoot);
  if (resolved === sampleRoot) return;
  if (!testRoot || !isWithin(resolved, testRoot)) {
    throw new Error(`Refusing to reset unassigned sample root: ${resolved}`);
  }
}

function resetSampleSite(targetRoot = sampleRoot, testRoot = process.env.PAGECORE_TEST_ROOT || '') {
  assertSafeTarget(targetRoot, testRoot);
  assertFixtureSource(fixturesContent);
  assertFixtureSource(fixturesUploads);
  const content = targetRoot === sampleRoot ? path.join(sampleRoot, 'working-content') : path.join(targetRoot, 'content');
  const uploads = targetRoot === sampleRoot ? path.join(sampleRoot, 'working-uploads') : path.join(targetRoot, 'uploads');
  const generated = targetRoot === sampleRoot ? sampleRoot : path.join(targetRoot, 'generated');
  const resetTargets = targetRoot === sampleRoot
    ? [content, uploads, path.join(sampleRoot, 'search-index.json'), path.join(sampleRoot, 'sitemap.xml')]
    : [content, uploads, generated];
  for (const target of resetTargets) {
    if (!isWithin(target, targetRoot)) {
      throw new Error(`Refusing to delete outside assigned sample root: ${target}`);
    }
    fs.rmSync(target, { recursive: true, force: true });
  }
  copyDirContents(fixturesContent, content);
  copyDirContents(fixturesUploads, uploads);
  assertCopiedFixture(fixturesContent, content);
  assertCopiedFixture(fixturesUploads, uploads);
  if (targetRoot !== sampleRoot) fs.mkdirSync(generated, { recursive: true });
  return { content, uploads, generated };
}

if (require.main === module) {
  const targetRoot = process.argv[2] ? path.resolve(process.argv[2]) : sampleRoot;
  const roots = resetSampleSite(targetRoot);
  process.stdout.write(`Sample site reset: ${path.dirname(roots.content)}\n`);
}

module.exports = { resetSampleSite, isWithin, assertCopiedFixture };

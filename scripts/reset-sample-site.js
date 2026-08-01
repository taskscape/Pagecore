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

function assertSafeTarget(targetRoot, testRoot) {
  const resolved = path.resolve(targetRoot);
  if (resolved === sampleRoot) return;
  if (!testRoot || !isWithin(resolved, testRoot)) {
    throw new Error(`Refusing to reset unassigned sample root: ${resolved}`);
  }
}

function resetSampleSite(targetRoot = sampleRoot, testRoot = process.env.PAGECORE_TEST_ROOT || '') {
  assertSafeTarget(targetRoot, testRoot);
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
  if (targetRoot !== sampleRoot) fs.mkdirSync(generated, { recursive: true });
  return { content, uploads, generated };
}

if (require.main === module) {
  const targetRoot = process.argv[2] ? path.resolve(process.argv[2]) : sampleRoot;
  const roots = resetSampleSite(targetRoot);
  process.stdout.write(`Sample site reset: ${path.dirname(roots.content)}\n`);
}

module.exports = { resetSampleSite, isWithin };

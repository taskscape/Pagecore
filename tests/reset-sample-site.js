const fs = require('fs');
const os = require('os');
const path = require('path');
const assert = require('assert');
const { resetSampleSite } = require('../scripts/reset-sample-site');

const testRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'pagecore-reset-contract-'));
const workerRoot = path.join(testRoot, 'worker-0');
try {
  const roots = resetSampleSite(workerRoot, testRoot);
  assert(fs.existsSync(path.join(roots.content, 'posts')), 'content fixtures were not copied');
  assert(fs.existsSync(roots.uploads), 'upload fixtures were not copied');
  fs.writeFileSync(path.join(roots.content, 'sentinel.txt'), 'remove me');
  resetSampleSite(workerRoot, testRoot);
  assert(!fs.existsSync(path.join(roots.content, 'sentinel.txt')), 'assigned root was not reset');

  const siblingPrefix = testRoot + '-sibling';
  assert.throws(() => resetSampleSite(siblingPrefix, testRoot), /unassigned/, 'sibling-prefix path bypassed containment');
  assert(!fs.existsSync(siblingPrefix), 'rejected sibling-prefix target was created');
  process.stdout.write('Sample reset helper checks passed.\n');
} finally {
  fs.rmSync(testRoot, { recursive: true, force: true });
}

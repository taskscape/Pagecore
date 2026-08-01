require('../cms/assets/editor-state.js');

const state = globalThis.PagecoreEditorState.create();
const first = state.beginPreview();
const second = state.beginPreview();
if (!first.signal.aborted || !state.isCurrentPreview(second.generation) || state.isCurrentPreview(first.generation)) {
  throw new Error('Preview generation cancellation contract failed.');
}

const order = [];
Promise.all([
  state.enqueueUpload(async () => { await new Promise(resolve => setTimeout(resolve, 15)); order.push('first'); }),
  state.enqueueUpload(async () => { order.push('second'); }),
  state.enqueueUpload(async () => { order.push('third'); })
]).then(() => {
  if (order.join(',') !== 'first,second,third') { throw new Error('Upload queue reordered selections.'); }
  console.log('Editor state checks passed.');
}).catch(error => { console.error(error); process.exitCode = 1; });

(() => {
  const tracker = document.currentScript;
  if (!tracker || navigator.webdriver) return;
  const { pagecoreReadUrl, ticket, content, seconds } = tracker.dataset;
  const minimum = Number(seconds) * 1000;
  if (!pagecoreReadUrl || !ticket || !content || !Number.isFinite(minimum) || minimum <= 0) return;

  let target;
  try {
    target = document.querySelector(content);
  } catch {
    return;
  }
  if (!target) return;

  let visibleTime = 0;
  let reachedMidpoint = false;
  let lastVisibleSample = null;
  function resetSample() {
    lastVisibleSample = null;
  }

  function sample() {
    const bounds = target.getBoundingClientRect();
    const viewportHeight = document.documentElement.clientHeight;
    const visibleHeight = Math.min(bounds.bottom, viewportHeight) - Math.max(bounds.top, 0);
    if (document.visibilityState !== 'visible' || bounds.height <= 0 || viewportHeight <= 0
      || visibleHeight < Math.min(bounds.height, viewportHeight) / 2) {
      resetSample();
      return;
    }
    const now = performance.now();
    if (lastVisibleSample !== null) visibleTime += Math.min(now - lastVisibleSample, 500);
    lastVisibleSample = now;
    // A short article can qualify in place. On a tall article the viewport
    // must reach the middle of the content itself.
    const longContent = bounds.height > viewportHeight;
    reachedMidpoint ||= longContent && bounds.top + bounds.height / 2 <= viewportHeight / 2;
    if (visibleTime < minimum || (longContent && !reachedMidpoint)) return;

    window.clearInterval(timer);
    document.removeEventListener('visibilitychange', resetSample);
    window.removeEventListener('pagehide', resetSample);
    const send = () => fetch(pagecoreReadUrl, {
      method: 'POST',
      mode: 'same-origin',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'text/plain' },
      body: ticket,
      keepalive: true
    });
    // Serialize first reads across tabs so each sees the issued reader cookie.
    const delivery = navigator.locks ? navigator.locks.request(pagecoreReadUrl, send) : send();
    delivery.catch(() => {});
  }

  document.addEventListener('visibilitychange', resetSample);
  window.addEventListener('pagehide', resetSample);
  const timer = window.setInterval(sample, 250);
  sample();
})();

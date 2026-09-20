// Mirrors the lab-page canvas-flow theme onto this standalone document.
// console.html is a separate document (loaded in an iframe by
// pnetlab-webconsole.js), so it does not receive the opener's
// body.pnq-dark class — but it IS same-origin, so the theme choice the
// canvas-flow island writes to localStorage (CanvasFlow.svelte, THEME_LS_KEY)
// is readable here directly. The initial apply() call below runs
// synchronously before the <style> block is parsed, so there is no flash of
// the wrong theme.
//
// The lab page's theme toggle can also be flipped WHILE a console window is
// already open. localStorage writes fire a 'storage' event on every OTHER
// same-origin browsing context (the iframe, or a popped-out console tab) —
// never on the writer itself — so listening for it here keeps an
// already-open console in sync without needing to close/reopen it.
// console-tabs.js listens for the 'pnq:theme-changed' event this dispatches
// to refresh any live xterm foreground override (see setTermBg there).
(function () {
  var KEY = 'pnq.canvasflow.theme';
  function apply() {
    var light = false;
    try { light = window.localStorage.getItem(KEY) === 'light'; } catch (e) { /* best-effort */ }
    document.documentElement.classList.toggle('pnq-light', light);
  }
  apply();
  window.addEventListener('storage', function (e) {
    if (e.key === KEY || e.key === null) {
      apply();
      try { window.dispatchEvent(new Event('pnq:theme-changed')); } catch (e2) { /* best-effort */ }
    }
  });
})();

/**
 * Smooth page transitions (shared by the employee and HR layouts).
 *
 * Fades the content out before navigating to another internal page; the next
 * page fades in through the CSS animation on `.main` (see hris-base.css).
 * Include with:  <script src="../asset/js/page-transition.js" defer></script>
 */
(function () {
  'use strict';

  // Users who prefer reduced motion get normal, instant navigation.
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var FADE_MS = 190;

  function isInternalNavLink(a) {
    if (!a || !a.getAttribute) return false;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#') return false;
    if (a.target && a.target !== '_self') return false;        // new tab/window
    if (a.hasAttribute('download')) return false;
    if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;
    if (/[?&]export=/i.test(href)) return false;                // file download, no navigation
    if (a.hasAttribute('data-pocket-trigger')) return false;    // opens the profile modal
    return a.origin === window.location.origin;                 // same-origin only
  }

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest('a');
    if (!isInternalNavLink(a)) return;
    if (a.href === window.location.href) return;                // already on this page

    e.preventDefault();
    var dest = a.href;
    document.body.classList.add('is-leaving');
    setTimeout(function () { window.location.href = dest; }, FADE_MS);
  });

  // Back/forward cache: make sure the page is visible again.
  window.addEventListener('pageshow', function () {
    document.body.classList.remove('is-leaving');
  });
})();

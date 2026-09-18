// Placement sections can be collapsed; shared DataTables owns search and paging.
(function () {
  'use strict';

  function collapsible(section) {
    var button = section.querySelector('[data-toggle]');
    var body = section.querySelector('[data-body]');
    if (!button || !body) { return; }
    var open = false;
    function render() {
      body.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.textContent = open ? button.getAttribute('data-label-open') : button.getAttribute('data-label-closed');
    }
    button.addEventListener('click', function () { open = !open; render(); });
    render();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-collapsed]').forEach(collapsible);
  });
})();

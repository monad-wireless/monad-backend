// The placement board's island (IP-157 Phase 3). Vanilla JS, no build step, two behaviours:
//
//   1. A text filter over the mirror table: typing narrows rows by their visible text (key, kind,
//      room, verdict, quest names). Case-insensitive substring; nothing is fetched.
//   2. Collapsed sections: a section marked data-collapsed starts hidden and its button toggles it.
//      Without JS the section renders open, so nothing is lost when the script does not run.
//
// Everything else on the page is rendered server-side.
(function () {
  'use strict';

  function filterTable(input) {
    var selector = input.getAttribute('data-filter-for');
    var table = selector ? document.querySelector(selector) : null;
    if (!table) { return; }
    var rows = table.querySelectorAll('tbody tr[data-row]');
    var counter = document.querySelector(input.getAttribute('data-filter-count') || '');

    function apply() {
      var needle = input.value.trim().toLowerCase();
      var shown = 0;
      rows.forEach(function (tr) {
        var hit = needle === '' || tr.textContent.toLowerCase().indexOf(needle) !== -1;
        tr.hidden = !hit;
        if (hit) { shown += 1; }
      });
      if (counter) {
        counter.textContent = needle === '' ? rows.length + ' rows' : shown + ' of ' + rows.length + ' rows';
      }
    }

    input.addEventListener('input', apply);
    apply();
  }

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
    document.querySelectorAll('input[data-filter-for]').forEach(filterTable);
    document.querySelectorAll('[data-collapsed]').forEach(collapsible);
  });
})();

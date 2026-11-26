// IP-157 Phase 4: the composer island on /admin/people/notifications. Vanilla JS, no build step.
//
// Exactly two jobs, both optional for the page to work:
//   1. When a quest is picked, prefill the title and body from one of two callout templates
//      (the option's data-name / data-duration / data-room attributes); both stay editable.
//      The same routine mirrors the current title and body into the CSS phone frame.
//   2. When the type or the audience changes, refresh the recipient estimate from the JSON
//      route the desk exposes. The server-rendered numbers stay if the fetch fails.
(function () {
  'use strict';

  var desk = document.querySelector('.notif-desk');
  var form = document.getElementById('notif-compose');
  if (!desk || !form) { return; }

  var field = function (name) { return form.querySelector('[name$="[' + name + ']"]'); };
  var checked = function (name) {
    var el = form.querySelector('[name$="[' + name + ']"]:checked');
    return el ? el.value : '';
  };

  var title = field('title');
  var body = field('body');
  var quest = field('quest');

  // ── 1. Templates and preview ─────────────────────────────────────────────────────────
  var TEMPLATES = [
    function (q) { return { title: q.name + ' is open now', body: q.name + ' is open now — could you run it?' }; },
    function (q) {
      var mins = q.duration ? q.duration + ' min' : 'a few minutes';
      return { title: 'Someone near ' + q.room + '?', body: 'Someone near ' + q.room + '? ' + q.name + ' takes ' + mins + '.' };
    }
  ];
  var templateIndex = 0;

  var preview = function () {
    var t = desk.querySelector('[data-preview="title"]');
    var b = desk.querySelector('[data-preview="body"]');
    var c = desk.querySelector('[data-preview="channel"]');
    if (t) { t.textContent = (title && title.value.trim()) || 'Title'; }
    if (b) { b.textContent = (body && body.value.trim()) || 'Body'; }
    if (c) { c.textContent = checked('type') === 'quest_callout' ? 'Quest callouts' : 'Messages'; }
  };

  var prefill = function () {
    if (!quest || !quest.value) { preview(); return; }
    var opt = quest.options[quest.selectedIndex];
    var q = {
      name: opt.getAttribute('data-name') || opt.textContent.trim(),
      duration: opt.getAttribute('data-duration') || '',
      // The quest names no room: the building is the honest fallback (FIIT, per IP-157).
      room: opt.getAttribute('data-room') || 'FIIT'
    };
    var filled = TEMPLATES[templateIndex % TEMPLATES.length](q);
    templateIndex += 1;
    if (title) { title.value = filled.title.slice(0, 120); }
    if (body) { body.value = filled.body; }
    // A quest picked is a callout by default; the operator can switch it back.
    var callout = form.querySelector('[name$="[type]"][value="quest_callout"]');
    if (callout && !callout.checked) { callout.checked = true; estimate(); }
    preview();
  };

  if (quest) { quest.addEventListener('change', prefill); }
  if (title) { title.addEventListener('input', preview); }
  if (body) { body.addEventListener('input', preview); }

  // ── 2. Estimate ──────────────────────────────────────────────────────────────────────
  var url = desk.getAttribute('data-estimate-url');
  var status = desk.querySelector('[data-estimate="status"]');
  var inbox = desk.querySelector('[data-estimate="inbox"]');
  var push = desk.querySelector('[data-estimate="push"]');
  var inflight = 0;

  var estimate = function () {
    if (!url) { return; }
    var type = checked('type');
    var audience = checked('audience');
    if (!type || !audience) { return; }
    var seq = ++inflight;
    if (status) { status.textContent = 'updating…'; }
    fetch(url + '?type=' + encodeURIComponent(type) + '&audience=' + encodeURIComponent(audience), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
      .then(function (data) {
        if (seq !== inflight) { return; }
        if (inbox) { inbox.textContent = String(data.inbox); }
        if (push) { push.textContent = String(data.push); }
        if (status) { status.textContent = ''; }
      })
      .catch(function (err) {
        if (seq !== inflight) { return; }
        if (status) { status.textContent = 'estimate not refreshed (' + err.message + '); the numbers shown are from page load.'; }
      });
  };

  form.querySelectorAll('[name$="[type]"], [name$="[audience]"]').forEach(function (el) {
    el.addEventListener('change', function () { estimate(); preview(); });
  });

  preview();
})();

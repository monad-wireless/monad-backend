/*
 * The shell's one behaviour (IP-157 Phase 8): a table row opens its record.
 *
 * `ui.table(...)` marks a clickable row with `data-row-href`. EasyAdmin's own datagrid does
 * the same thing through `data-default-action-url` and its bundled script, so this only has
 * to cover the bespoke tables. A click that lands on a link, a button or a form control is
 * left alone — the inner control is the more specific intent.
 */
document.addEventListener('click', function (event) {
    var row = event.target.closest ? event.target.closest('tr[data-row-href]') : null;
    if (!row) {
        return;
    }
    if (event.target.closest('a, button, input, select, textarea, label, summary')) {
        return;
    }
    if (event.metaKey || event.ctrlKey || event.button !== 0) {
        return;
    }
    window.location.href = row.getAttribute('data-row-href');
});

/* Keyboard parity: a row is reachable and Enter opens it. */
document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') {
        return;
    }
    var row = event.target.closest ? event.target.closest('tr[data-row-href]') : null;
    if (row && event.target === row) {
        window.location.href = row.getAttribute('data-row-href');
    }
});

/* The user menu closes when the click lands outside it. */
document.addEventListener('click', function (event) {
    document.querySelectorAll('details.mc-user[open]').forEach(function (details) {
        if (!details.contains(event.target)) {
            details.removeAttribute('open');
        }
    });
});

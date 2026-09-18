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

/* DataTables owns search, ordering and paging. Never put a client-side pager over
 * EasyAdmin's server-paged datagrid, or over a matrix/fact table with grouped cells. */
document.querySelectorAll('table[data-datatable], table.table-dense, table.ui-table').forEach(function (table) {
    if (!table.tHead || table.classList.contains('matrix') || table.closest('.matrix') || table.hasAttribute('data-static-table')) return;
    var rows = Array.from(table.tBodies[0].rows);
    if (!table.hasAttribute('data-datatable') && rows.length < 5) return;
    var emptyText = 'No records to show.';
    if (rows.length === 1 && rows[0].cells.length === 1 && rows[0].cells[0].colSpan > 1) {
        var emptyLabel = document.createElement('span');
        emptyLabel.textContent = rows[0].textContent.trim();
        emptyText = emptyLabel.innerHTML;
        rows[0].remove();
    } else if (rows.some(function (row) { return Array.from(row.cells).some(function (cell) { return cell.colSpan > 1 || cell.rowSpan > 1; }); })) {
        return;
    }
    var headers = Array.from(table.tHead.rows[0].cells);
    var label = table.dataset.tableLabel || 'records';
    var columns = headers.map(function (th) {
        var name = th.textContent.trim();
        var controls = th.hasAttribute('data-no-sort') || ['Actions', 'Details', 'Notes', ''].includes(name);
        return { orderable: !controls, searchable: !controls };
    });
    var api = new DataTable(table, {
        order: [], pageLength: 25, lengthMenu: [10, 25, 50, 100], autoWidth: false,
        columns: columns,
        language: {
            search: 'Search:', searchPlaceholder: 'Search ' + label + '…',
            lengthMenu: '_MENU_ per page', info: '_START_–_END_ of _TOTAL_ ' + label,
            infoEmpty: '0 ' + label, infoFiltered: '(from _MAX_)',
            emptyTable: emptyText, zeroRecords: 'No matching records. Clear search or change the filters.',
            paginate: {first: 'First', previous: 'Previous', next: 'Next', last: 'Last'}
        }
    });
    var container = api.table().container();
    var filters = document.createElement('div');
    filters.className = 'mc-table-filters';
    headers.forEach(function (th, index) {
        if (!th.hasAttribute('data-filter') && !['Status', 'State', 'Platform', 'Audience'].includes(th.textContent.trim())) return;
        var values = Array.from(new Set(rows.filter(function (row) { return row.cells.length === headers.length; }).map(function (row) { return row.cells[index].textContent.trim(); }))).sort();
        if (values.length < 2 || values.length > 30) return;
        var field = document.createElement('label');
        field.append(document.createTextNode(th.textContent.trim()));
        var select = document.createElement('select');
        select.append(new Option('All', ''));
        values.forEach(function (value) { select.append(new Option(value, value)); });
        select.addEventListener('change', function () { api.column(index).search(select.value, {exact: true}).draw(); });
        field.append(select);
        filters.append(field);
    });
    var reset = document.createElement('button');
    reset.type = 'button'; reset.className = 'ui-btn'; reset.textContent = 'Clear filters';
    reset.addEventListener('click', function () {
        filters.querySelectorAll('select').forEach(function (select) { select.value = ''; });
        headers.forEach(function (_, index) { api.column(index).search(''); });
        api.search('').draw();
    });
    filters.append(reset);
    container.prepend(filters);
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

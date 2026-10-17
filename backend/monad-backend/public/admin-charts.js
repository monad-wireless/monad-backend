/* Charts read the same server-rendered values as their adjacent accessible tables.
 * Plotly is self-hosted and loaded only on pages with charts. Counts are descriptive,
 * never estimates; zero and missing stay distinct. */
document.querySelectorAll('[data-admin-chart]').forEach(function (element) {
    var rows = JSON.parse(element.dataset.adminChart);
    if (!rows.length || rows.every(function (row) { return row.value === null; })) {
        element.className = 'ui-chart-empty';
        element.textContent = 'No observations available yet.';
        return;
    }
    var css = getComputedStyle(document.documentElement);
    var labels = rows.map(function (row) { return row.label; });
    var values = rows.map(function (row) { return row.value; });
    // Plotly supports a subset of HTML in text. Treat every label as plain text.
    function escapeLabel(value) {
        return String(value).replace(/[&<>]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]; });
    }
    Plotly.newPlot(element, [{
        type: 'bar', orientation: 'h',
        x: values, y: labels.map(escapeLabel),
        marker: {color: css.getPropertyValue('--signal-ink').trim()},
        text: values.map(function (value) { return value === null ? 'Not reported' : String(value); }),
        textposition: 'auto', cliponaxis: false,
        hovertemplate: '%{y}: %{x}<extra></extra>'
    }], {
        margin: {t: 12, r: 35, b: 45, l: 130},
        font: {family: css.getPropertyValue('--font-sans'), size: 13, color: css.getPropertyValue('--ink').trim()},
        paper_bgcolor: '#ffffff', plot_bgcolor: '#ffffff',
        xaxis: {title: {text: element.dataset.axisLabel || 'Count'}, range: [0, Math.max(1, ...values) * 1.15], dtick: Math.max(1, Math.ceil(Math.max(...values) / 5)), tickformat: ',d', gridcolor: '#e1dfda', zeroline: false},
        yaxis: {autorange: 'reversed', automargin: true, type: 'category'},
        height: Math.max(240, labels.length * 32 + 65),
        showlegend: false, bargap: 0.35
    }, {
        responsive: true, displaylogo: false,
        modeBarButtonsToRemove: ['select2d', 'lasso2d', 'zoom2d', 'pan2d', 'zoomIn2d', 'zoomOut2d', 'autoScale2d'],
        toImageButtonOptions: {format: 'png', filename: 'monadcount-' + element.id, scale: 2}
    });
});

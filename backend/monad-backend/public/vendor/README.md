# Admin browser libraries

Served locally; admin page loads never contact a CDN.

- DataTables 3.0.4, MIT: https://datatables.net/license/mit
  - https://cdn.datatables.net/3.0.4/js/dataTables.min.js
  - https://cdn.datatables.net/3.0.4/css/dataTables.dataTables.min.css
- Plotly.js 2.35.2, MIT: https://github.com/plotly/plotly.js/blob/v2.35.2/LICENSE
  - https://cdn.plot.ly/plotly-2.35.2.min.js
  - Same pinned distribution used by monad-knowledge's public website.

Retain the distribution notices when updating. DataTables 3 has no jQuery dependency.
Plotly is included only on pages that render charts.

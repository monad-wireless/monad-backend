// The onboarding desk (IP-157 Phase 5): /admin/people/onboarding. Vanilla JS, no build step.
// Two conveniences and nothing else: the status filter submits itself on change (the Apply
// button still works without this), and a withdraw asks once before it scrubs a row.
(function () {
    var desk = document.querySelector('.onboarding');
    if (!desk) { return; }

    var filter = desk.querySelector('form.filter');
    if (filter) {
        filter.querySelectorAll('input[type=checkbox]').forEach(function (box) {
            box.addEventListener('change', function () { filter.submit(); });
        });
        var apply = filter.querySelector('button[type=submit]');
        if (apply) { apply.hidden = true; }
    }

    desk.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
        });
    });
})();

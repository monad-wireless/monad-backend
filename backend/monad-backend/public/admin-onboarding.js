// Delegation also covers rows DataTables has moved off the current page.
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.closest('.onboarding') || !form.hasAttribute('data-confirm')) return;
    if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
});

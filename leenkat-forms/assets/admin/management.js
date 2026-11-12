(function () {
    'use strict';
    const root = document.querySelector('.lkf-management');
    if (!root) return;
    root.addEventListener('change', function (event) {
        if (event.target.id === 'lkf-entry-form-picker') event.target.form.requestSubmit();
    });
    root.addEventListener('click', function (event) {
        if (event.target.closest('.lkf-print-entry')) window.print();
    });
    const columns = root.querySelector('.lkf-columns-toggle');
    const screenOptions = document.getElementById('show-settings-link');
    if (columns && screenOptions) {
        columns.addEventListener('click', function () { screenOptions.click(); screenOptions.focus(); });
        new MutationObserver(function () {
            columns.setAttribute('aria-expanded', screenOptions.getAttribute('aria-expanded') || 'false');
        }).observe(screenOptions, { attributes: true, attributeFilter: ['aria-expanded'] });
    }
    root.addEventListener('submit', function (event) {
        const button = event.submitter;
        const form = event.target;
        let operation = button && button.name === 'lkf_row_action' ? button.value.split(':')[0] : '';
        if (!operation && button && button.name === 'lkf_apply') {
            const select = form.querySelector('[name="' + (button.value === 'bottom' ? 'operation2' : 'operation') + '"]');
            operation = select ? select.value : '';
        }
        if (!operation && button && button.name === 'operation') operation = button.value;
        if (operation === 'delete' && !window.confirm(window.lkfManagement.deleteWarning)) event.preventDefault();
    });
}());

(() => {
    const dialog = document.getElementById('lkf-form-dialog');
    const button = document.getElementById('lkf-add-form');
    if (!dialog || !button) return;
    button.addEventListener('click', () => {
        dialog.returnValue = '';
        dialog.showModal();
    });
    dialog.addEventListener('close', () => {
        if (dialog.returnValue !== 'insert') return;
        const id = document.getElementById('lkf-insert-id')?.value;
        if (!/^[1-9][0-9]*$/.test(id || '')) return;
        const code = '[leenkat_form id="' + id + '"]';
        if (window.wp?.media?.editor?.insert) window.wp.media.editor.insert(code);
        else if (window.tinyMCE?.activeEditor) window.tinyMCE.activeEditor.execCommand('mceInsertContent', false, code);
        else document.getElementById('content')?.setRangeText(code);
    });
})();

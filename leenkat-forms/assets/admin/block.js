(function (blocks, element, components, blockEditor, serverSideRender, i18n) {
    const el = element.createElement;
    const __ = i18n.__;
    const logo = el('img', { src: window.leenfoBlockLogo, alt: '', width: 24, height: 24, style: { objectFit: 'contain' } });
    blocks.registerBlockType('leenkat-forms/form', {
        apiVersion: 3,
        title: __('Leenkat Forms', 'leenkat-forms'),
        icon: logo, category: 'widgets',
        attributes: { formId: { type: 'integer', default: 0 } },
        edit: function (props) {
            const choices = Object.entries(window.leenfoBlockForms || {}).map(([value, label]) => ({ value: Number(value), label }));
            const options = [{ value: 0, label: __('Select a form', 'leenkat-forms') }, ...choices];
            const select = () => el(components.SelectControl, {
                label: __('Form', 'leenkat-forms'), value: props.attributes.formId,
                options, onChange: value => props.setAttributes({ formId: Number(value) })
            });
            return el('div', blockEditor.useBlockProps(),
                el(blockEditor.InspectorControls, {}, el(components.PanelBody, { title: __('Form', 'leenkat-forms') }, select())),
                props.attributes.formId ? el(serverSideRender, { block: 'leenkat-forms/form', attributes: props.attributes }) : el(components.Placeholder, { icon: logo, label: __('Leenkat Forms', 'leenkat-forms') }, select())
            );
        },
        save: function () { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n);

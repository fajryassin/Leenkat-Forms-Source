(function (element, components, i18n) {
    'use strict';
    const e = element.createElement;
    const { useState, useEffect, useRef } = element;
    const { Button, TextControl, TextareaControl, SelectControl, ToggleControl, FormTokenField, Tooltip, Notice, Spinner, Modal } = components;
    const __ = i18n.__;
    const config = window.lkfBuilderConfig;
    const model = window.lkfBuilderModel;
    const standardTypes = [
        ['text', __('Single Line Text', 'leenkat-forms')], ['textarea', __('Paragraph Text', 'leenkat-forms')],
        ['name', __('Name', 'leenkat-forms')], ['email', __('Email', 'leenkat-forms')],
        ['phone', __('Phone', 'leenkat-forms')], ['select', __('Dropdown', 'leenkat-forms')],
        ['radio', __('Multiple Choice', 'leenkat-forms')], ['checkboxes', __('Checkboxes', 'leenkat-forms')],
        ['number', __('Numbers', 'leenkat-forms')], ['number_slider', __('Number Slider', 'leenkat-forms')],
        ['captcha', __('CAPTCHA', 'leenkat-forms')], ['consent', __('GDPR Agreement', 'leenkat-forms')]
    ];
    const moreTypes = [
        ['url', __('Website URL', 'leenkat-forms')], ['datetime', __('Date / Time', 'leenkat-forms')],
        ['address', __('Address', 'leenkat-forms')], ['file_upload', __('File Upload', 'leenkat-forms')], ['page_break', __('Page Break', 'leenkat-forms')], ['section_divider', __('Section Divider', 'leenkat-forms')]
    ];
    const labels = { ...Object.fromEntries([...standardTypes, ...moreTypes]), date: __('Date', 'leenkat-forms'), time: __('Time', 'leenkat-forms'), rating: __('Rating', 'leenkat-forms'), checkbox: __('Checkbox', 'leenkat-forms') };
    const actionIcon = kind => e('svg', { width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true' },
        kind === 'up' ? e('path', { d: 'm5 15 7-7 7 7' }) :
        kind === 'down' ? e('path', { d: 'm5 9 7 7 7-7' }) :
        kind === 'copy' ? e(element.Fragment, {}, e('rect', { x: 8, y: 3, width: 12, height: 15, rx: 2 }), e('path', { d: 'M16 21H6a2 2 0 0 1-2-2V7' })) :
        e(element.Fragment, {}, e('path', { d: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13' }), e('path', { d: 'M10 11v6M14 11v6' }))
    );
    const panelIcon = options => e('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', 'aria-hidden': true }, options
        ? e(element.Fragment, {}, e('path', { d: 'M3 6h18M3 12h18M3 18h18' }), e('circle', { cx: 8, cy: 6, r: 2, fill: 'currentColor' }), e('circle', { cx: 16, cy: 12, r: 2, fill: 'currentColor' }), e('circle', { cx: 9, cy: 18, r: 2, fill: 'currentColor' }))
        : e(element.Fragment, {}, e('rect', { x: 3, y: 5, width: 18, height: 14, rx: 1 }), e('path', { d: 'M7 9h1M7 12h1M7 15h1M11 9h6M11 12h6M11 15h6' })));
    const fresh = () => {
        const definition = { schema_version: 1, next_field_number: 1, title: __('Untitled Form', 'leenkat-forms'), fields: [], settings: { general:{ajax:true,disable_ip:false},...(window.lkfSecurityModel?{security:window.lkfSecurityModel.defaults()}:{}), confirmation: { message: __('Thank you. Your response was submitted.', 'leenkat-forms') } } };
        definition.settings.notifications = { enabled: true, items: [window.lkfNotificationsModel.defaults(definition)] };
        return definition;
    };
    const agreementText = __('I consent to having this website store my submitted information so they can respond to my inquiry.', 'leenkat-forms');
    const choiceTypes = ['select','radio','checkboxes'];
    const newField = (type, id) => ({ id, display_id: id, type, label: labels[type] || type, required: ['consent', 'captcha'].includes(type), width: 100, settings: { ...(choiceTypes.includes(type) ? { options: [__('First Choice','leenkat-forms'),__('Second Choice','leenkat-forms'),__('Third Choice','leenkat-forms')] } : type === 'rating' ? { max: 5 } : type === 'number_slider' ? { min: 0, max: 10, step: 1, default_value: '0', value_display: __("Selected Value: {value}", 'leenkat-forms') } : type==='phone'?{phone_format:'smart'}:type==='consent'?{agreement:agreementText}:{}), ...(sizedTypes.includes(type) ? { field_size: 'large' } : {}),...(type==='select'?{placeholder:'',select_style:'modern'}:type==='datetime'?{datetime_format:'date_time',date_format:'m/d/Y',time_format:'12',time_interval:30}:type==='address'?{address_scheme:'us'}:type==='file_upload'?{upload_style:'modern',max_files:1,max_file_size:10,allowed_extensions:'jpg, jpeg, png, webp, gif, pdf, txt'}:{}) } });
    const sizedTypes = ['text', 'textarea', 'email', 'phone', 'url', 'number', 'select', 'date', 'time', 'name','number_slider','datetime','address'];
    const nameParts = settings => settings?.name_format === 'simple' ? ['first'] : settings?.name_format === 'first_middle_last' ? ['first','middle','last'] : ['first','last'];
    const nameCaption = (part, settings) => settings?.name_format === 'simple' ? __('Name', 'leenkat-forms') : ({first:__('First Name','leenkat-forms'),middle:__('Middle Name','leenkat-forms'),last:__('Last Name','leenkat-forms')})[part];
    const addressCaptions = settings => ({address1:__("Address Line 1", 'leenkat-forms'),address2:__("Address Line 2", 'leenkat-forms'),city:__("City", 'leenkat-forms'),state:settings?.address_scheme==='international'?__("State / Province / Region", 'leenkat-forms'):__("State", 'leenkat-forms'),postal:settings?.address_scheme==='international'?__("Postal Code", 'leenkat-forms'):__("Zip Code", 'leenkat-forms'),country:__("Country", 'leenkat-forms')});
    const addressParts = settings => ['address1','address2','city','state','postal',...(settings?.address_scheme==='international'?['country']:[])].filter(part=>!settings?.address_parts?.[part]?.hidden);
    const addressOptions = (part,settings) => part==='country'?config.addressData?.countries:part==='state' && settings?.address_scheme!=='international'?config.addressData?.states:null;
    const dateParts = settings => settings?.datetime_format==='date'?['date']:settings?.datetime_format==='time'?['time']:['date','time'];
    const uploadIcon = () => e('svg',{width:36,height:36,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:1.5,'aria-hidden':true},e('path',{d:'M3 14h5l2 3h4l2-3h5M3 14l3-9h12l3 9v6H3zM12 3v9m-3-3 3 3 3-3'}));
    const placeholderTypes = ['text', 'textarea', 'email', 'phone', 'url', 'number', 'select'];
    const defaultTypes = ['text', 'textarea', 'email', 'phone', 'url', 'number'];
    const choiceItems = settings => settings?.dynamic_choices && settings.dynamic_choices !== 'off' ? config.choiceOptions?.[settings.dynamic_choices]?.[settings.dynamic_source] || [] : (settings?.options || []).map(value=>({value,label:value}));
    const choiceDecoration = (settings,value) => settings?.image_choices
        ? e('span',{className:'lkf-choice-visual','aria-hidden':true},e('img',{className:'lkf-choice-image',src:/^https?:\/\//i.test(settings.choice_images?.[value] || '')?settings.choice_images[value]:config.choicePlaceholder,alt:''}))
        : settings?.icon_choices ? e('svg',{className:'lkf-choice-icon',viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:1.7,'aria-hidden':true},e('path',{d:config.choiceIcons?.[settings.choice_icons?.[value] || 'star'] || ''})) : null;
    const hints = {
        'Use image choices': __('Show an image with each choice. Upload or select an image in the media library.', 'leenkat-forms'),
        'Image Choice Style': __('Modern uses a card and selection marker. Classic uses a bordered card. None keeps the native choice control.', 'leenkat-forms'),
        'Use icon choices': __('Show a built-in icon with each choice.', 'leenkat-forms'),
        'Randomize Choices': __('Shuffle the choices when the published form is rendered. Their saved values stay the same.', 'leenkat-forms'),
        'Choice Layout': __('Arrange choices in one, two or three columns, or on an inline row.', 'leenkat-forms'),
        'Choice Limit': __('Maximum number of choices a visitor can select. Leave blank for no limit.', 'leenkat-forms'),
        'Enable Disclaimer / Terms of Service Display': __('Display this field description in a scrollable text area.', 'leenkat-forms'),
        'Increment': __('Distance between slider values. Decimal increments are supported.', 'leenkat-forms'),
        'Value Display': __('Use {value} to show the current slider value. Leave blank to hide the text.', 'leenkat-forms'),
        'Phone Format': __('Smart adds a country selector and formats international numbers. US uses a ten-digit national number. International requires a plus sign and country code.', 'leenkat-forms'),
        'Enable Email Confirmation': __('Ask the visitor to enter the same email address twice.', 'leenkat-forms'),
        'Confirmation Placeholder Text': __('Text shown inside the empty confirmation input.', 'leenkat-forms'),
        'Allowlist / Denylist': __('Allow only matching email addresses, or block matching addresses. Use * as a wildcard.', 'leenkat-forms'),
        'Multiple Options Selection': __('Allow more than one dropdown choice to be selected.', 'leenkat-forms'),
        'Style': __('Classic uses a native select. Modern adds search and removable selected items.', 'leenkat-forms'),
        'Dynamic Choices': __('Populate choices from published public content or public taxonomy terms. Private content is excluded.', 'leenkat-forms'),
        'datetime-format': __('Choose date and time together, or only one part.', 'leenkat-forms'),
        'Format': __('Choose one name input, first and last names, or first, middle and last names.', 'leenkat-forms'),
        'Hide Sublabels': __('Hide the captions below the individual inputs on the published form while retaining accessible labels.', 'leenkat-forms'),
        Label: __('The name displayed next to this input.', 'leenkat-forms'),
        Description: __('Extra guidance displayed below this field.', 'leenkat-forms'),
        Required: __('Require an answer before visitors can submit the form.', 'leenkat-forms'),
        'Field Size': __('Choose how much of the form width this input uses.', 'leenkat-forms'),
        'Placeholder Text': __('A hint shown while the input is empty.', 'leenkat-forms'),
        'Limit Length': __('Set the maximum number of characters or words accepted.', 'leenkat-forms'),
        'Default Value': __('Prefill this field with a starting value.', 'leenkat-forms'),
        'Input Mask': __('Use 9 for a digit, A for an ASCII letter, and * for a letter or digit. Separators are added as visitors type. Example: 999-AAA.', 'leenkat-forms'),
        'CSS Classes': __('Add class names separated by spaces to style this field.', 'leenkat-forms'),
        'Hide Label': __('Hide the label on the published form. A muted label remains visible in the builder.', 'leenkat-forms'),
        'Read-Only': __('Display the default value without allowing visitors to change it.', 'leenkat-forms'),
        'Form Description': __('Text or instructions for visitors. Display it with description="true" on the form shortcode.','leenkat-forms'),
        'Submit Button Processing Text': __('Button text shown while a submission is processing.','leenkat-forms'),
        'Form CSS Class': __('CSS class names for the form, separated by spaces.','leenkat-forms'),
        'Submit Button CSS Class': __('CSS class names for the submit button, separated by spaces.','leenkat-forms'),
        'Enable Prefill by URL': __('Use ?lkfFORMID_FIELDID=value, e.g. ?lkf12_f_0001=Hello. Add _first, _last, _date or another part name for composite fields; separate multiple choices with |. Consent, CAPTCHA, uploads and read-only fields cannot be prefilled.','leenkat-forms'),
        'Enable AJAX form submission': __('Submit without reloading the page. When disabled, the form uses a normal POST and returns to the form page.','leenkat-forms'),
        'Disable storing entry information in WordPress': __('Process notifications without saving answers or searchable entry values. Notifications are sent immediately without a persistent retry queue. Uploaded files are attached and removed unless Media Library storage is enabled.','leenkat-forms'),
        'Disable storing user details (IP address and user agent)': __('Exclude IP address and user agent from new entries. This does not rewrite existing entries or web-server logs.','leenkat-forms'),
        'Searchable entry value': __('Include this answer in searchable entry values.', 'leenkat-forms')
    };
    Object.assign(hints, Object.fromEntries(Object.entries(hints).map(([key, value]) => [__(key, 'leenkat-forms'), value])));
    function helpIcon(label, hint) {
        return hint && e(Tooltip, { text: hint }, e('button', { type: 'button', className: 'lkf-option-help', 'aria-label': __('Help: ', 'leenkat-forms') + label }, e('svg', { width: 14, height: 14, viewBox: '0 0 20 20', fill: 'none', stroke: 'currentColor', strokeWidth: 1.7, 'aria-hidden': true }, e('circle', { cx: 10, cy: 10, r: 7.4 }), e('path', { d: 'M7.8 7.1a2.3 2.3 0 0 1 4.4.8c0 1.6-2.2 1.7-2.2 3.3M10 13.5v.2' }))));
    }
    function optionControl(Control, props, key) {
        const id = 'lkf-option-' + (key || props.label).toLowerCase().replace(/\s+/g, '-');
        return e('div', { className: 'lkf-option-control' },
            e('div', { className: 'lkf-control-heading' }, e('label', { htmlFor: id }, props.label), helpIcon(props.label, hints[key] || hints[props.label])),
            e(Control, { ...props, id, hideLabelFromVision: true, 'aria-label': props.label })
        );
    }
    function toggleOption(props, key) {
        return e('div', { className: 'lkf-toggle-option' }, e(ToggleControl, props), helpIcon(props.label, hints[key] || hints[props.label]));
    }
    async function request(url, method = 'GET', body) {
        const response = await fetch(url, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: body === undefined ? undefined : JSON.stringify(body) });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || __('Request failed.', 'leenkat-forms'));
        return data;
    }
    function App() {
        const [formId, setFormId] = useState(config.formId);
        const [definition, setDefinition] = useState(fresh());
        const latest = useRef(definition);
        const nextFieldNumber = useRef(1);
        const saved = useRef(null);
        const formIdRef = useRef(config.formId);
        const revisionRef = useRef(null);
        const saveInFlight = useRef(null);
        const [revision, setRevision] = useState(null);
        const [status, setStatus] = useState('draft');
        const [publishedRevision, setPublishedRevision] = useState(null);
        const [loading, setLoading] = useState(!!config.formId);
        const [saving, setSaving] = useState(false);
        const [publishing, setPublishing] = useState(false);
        const [dirty, setDirty] = useState(false);
        const [message, setMessage] = useState(null);
        const [selected, setSelected] = useState(null);
        const [preview, setPreview] = useState(!!config.initialPreview);
        const [previewStep,setPreviewStep]=useState(0);
        const [toolsOpen, setToolsOpen] = useState(false);
        const [hasEntries, setHasEntries] = useState(false);
        const toolsRef = useRef(null);
        const toolsButtonRef = useRef(null);
        const importInputRef = useRef(null);
        const [section, setSection] = useState('fields');
        const [settingsTab,setSettingsTab]=useState('general');
        const [generalAdvanced,setGeneralAdvanced]=useState(false);
        const [fieldsTab, setFieldsTab] = useState('add');
        const [optionsTab, setOptionsTab] = useState('general');
        const [bulkChoices, setBulkChoices] = useState(null);
        const choiceDrag = useRef(null);
        const [pointerView,setPointerView] = useState(null);
        const pointerCallbacks = useRef({});
        const [choiceHover,setChoiceHover] = useState(-1);
        const [dragSource, setDragSource] = useState(null);
        const dragSourceRef = useRef(null);
        const dragPreviewRef = useRef(null);
        const [dropTarget, setDropTarget] = useState(null);
        const [announcement, setAnnouncement] = useState('');
        const [pendingDelete, setPendingDelete] = useState(null);
        const [history, setHistory] = useState([]);
        const [future, setFuture] = useState([]);
        const [siteState,setSiteState]=useState({});
        const [captchaState,setCaptchaState]=useState(config.captcha||{provider:'none',label:'CAPTCHA',ready:false});
        const captchaRequest=useRef(0);
        const [akismetState,setAkismetState]=useState(config.akismet||{status:'unavailable',ready:false});const akismetRequest=useRef(0);
        async function refreshAkismet(){if(!config.akismetStateApi)return;const seq=++akismetRequest.current;try{const response=await fetch(config.akismetStateApi,{credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':config.nonce}});if(!response.ok)throw new Error(__("Akismet state unavailable", 'leenkat-forms'));const data=await response.json();if(seq===akismetRequest.current)setAkismetState(data);}catch{if(seq===akismetRequest.current)setAkismetState(old=>({...old,status:'unavailable',ready:false}));}}
        useEffect(()=>{if(!config.akismetStateApi)return;let active=true;const refresh=()=>{if(active)refreshAkismet();};refresh();window.addEventListener('focus',refresh);return()=>{active=false;++akismetRequest.current;window.removeEventListener('focus',refresh);};},[]);
        const [pendingImport,setPendingImport]=useState(null);
        const activeConfig={...config,...siteState,captcha:captchaState,refreshCaptcha,akismet:akismetState,refreshAkismet};
        async function refreshCaptcha(){
            if(!config.captchaStateApi)return;const sequence=++captchaRequest.current;
            try{const response=await fetch(config.captchaStateApi,{credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':config.nonce}});if(!response.ok)throw new Error('CAPTCHA state unavailable');const data=await response.json();if(sequence===captchaRequest.current)setCaptchaState({provider:data.provider,label:data.label,type:data.type,ready:data.ready===true});}
            catch{if(sequence===captchaRequest.current)setCaptchaState(previous=>({...previous,ready:false,unavailable:true}));}
        }
        useEffect(()=>{if(!config.captchaStateApi)return;let active=true;const refresh=()=>{if(active)refreshCaptcha();};refresh();window.addEventListener('focus',refresh);return()=>{active=false;++captchaRequest.current;window.removeEventListener('focus',refresh);};},[]);
        const [captchaFocus,setCaptchaFocus]=useState(0);
        useEffect(()=>{if(!captchaFocus)return;const target=document.getElementById('lkf-captcha-settings');target?.scrollIntoView?.({block:'start'});target?.focus?.({preventScroll:true});setCaptchaFocus(0);},[captchaFocus,section,settingsTab]);
        function openCaptcha(){setPreview(false);setSettingsTab('security');setSection('settings');setCaptchaFocus(value=>value+1);}
        function setCaptchaEnabled(value){if(value&&(!captchaState.ready||definition.fields.some(field=>field.type==='captcha')))return;commit({...definition,settings:{...definition.settings,security:{...definition.settings?.security,captcha_enabled:value}}});}
        function activateCaptcha(){
            if(!captchaState.ready||definition.fields.some(field=>field.type==='captcha')||definition.settings?.security?.captcha_enabled){openCaptcha();return;}
            setCaptchaEnabled(true);setAnnouncement(__('CAPTCHA Enabled','leenkat-forms')+': '+captchaState.label);
        }
        function captchaIndicator(){
            if(!definition.settings?.security?.captcha_enabled)return null;
            const providerName={recaptcha:'reCAPTCHA',hcaptcha:'hCaptcha',turnstile:'Cloudflare Turnstile'}[captchaState.provider]||captchaState.label||'CAPTCHA',logo={recaptcha:'recaptcha.svg',hcaptcha:'hcaptcha.svg',turnstile:'cloudflare.svg'}[captchaState.provider];
            const accessibleLabel=(captchaState.ready?__('CAPTCHA Enabled','leenkat-forms'):__('CAPTCHA setup required','leenkat-forms'))+': '+(captchaState.label||'CAPTCHA');
            return e('div',{className:'lkf-captcha-indicator'+(!captchaState.ready?' needs-setup':''),role:'status','aria-live':'polite'},e('button',{type:'button',onClick:openCaptcha,'aria-label':accessibleLabel,title:accessibleLabel+'. '+__('This reflects the form setting. The server verifies CAPTCHA on submission.','leenkat-forms')},
                logo?e('img',{className:'lkf-captcha-badge-logo',src:(config.securityIconsUrl||'')+logo,width:34,height:34,alt:'','aria-hidden':true}):e('span',{className:'lkf-captcha-badge-fallback','aria-hidden':true},'!'),
                e('span',{className:'lkf-captcha-badge-copy'},e('span',{className:'lkf-captcha-badge-name'},providerName),e('span',{className:'lkf-captcha-badge-status'},captchaState.ready?__('Enabled','leenkat-forms'):__('Setup required','leenkat-forms'),captchaState.ready&&e('svg',{width:14,height:14,viewBox:'0 0 20 20','aria-hidden':true,focusable:'false'},e('circle',{cx:10,cy:10,r:9,fill:'currentColor'}),e('path',{d:'m5.5 10 3 3 6-6',fill:'none',stroke:'#fff',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round'}))))));
        }
        useEffect(()=>{if(!config.siteStateApi)return;let active=true;const refresh=()=>request(config.siteStateApi).then(data=>{if(active)setSiteState(data);}).catch(()=>{});window.addEventListener('focus',refresh);return()=>{active=false;window.removeEventListener('focus',refresh);};},[]);
        useEffect(() => {
            if (!toolsOpen) return;
            let active = true;
            if(formId)request(config.api+'/'+formId).then(data=>{if(active)setHasEntries(data.has_entries===true);}).catch(()=>{if(active)setHasEntries(false);});
            toolsRef.current?.querySelector('[role="menuitem"]:not(:disabled):not([aria-disabled="true"])')?.focus();
            const dismiss = event => { if (!toolsRef.current?.contains(event.target)) setToolsOpen(false); };
            window.addEventListener('pointerdown', dismiss);
            window.addEventListener('focusin', dismiss);
            return () => { active=false;window.removeEventListener('pointerdown', dismiss); window.removeEventListener('focusin', dismiss); };
        }, [toolsOpen,formId]);
        useEffect(() => {
            if (!config.formId) return;
            request(config.api + '/' + config.formId).then(data => {
                const loaded = model.withFieldNumbers(data.definition);
                nextFieldNumber.current = loaded.next_field_number;
                setDefinition(loaded); latest.current = loaded;
                saved.current = loaded; revisionRef.current = data.revision;
                setRevision(data.revision); setStatus(data.status); setPublishedRevision(data.published_revision || null); setHasEntries(data.has_entries===true); setLoading(false);
            }).catch(error => { setMessage({ type: 'error', text: error.message }); setLoading(false); });
        }, []);
        useEffect(()=>{
            if(loading || !window.lkfPointerSort)return;
            return window.lkfPointerSort.attach(document.getElementById('lkf-builder-root'),{
                source:target=>pointerCallbacks.current.source(target),
                target:(source,point,last)=>pointerCallbacks.current.target(source,point,last),
                change:(source,target,height)=>setPointerView({source,target,height}),
                drop:(source,target)=>pointerCallbacks.current.drop(source,target),
                end:()=>setPointerView(null)
            });
        },[loading]);
        useEffect(() => {
            const warn = event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } };
            window.addEventListener('beforeunload', warn);
            return () => window.removeEventListener('beforeunload', warn);
        }, [dirty]);
        useEffect(() => {
            if (!pendingDelete) return;
            const timer = window.setTimeout(() => document.getElementById('lkf-confirm-delete')?.focus(), 0);
            return () => window.clearTimeout(timer);
        }, [pendingDelete]);
        function commit(next) {
            next = model.withFieldNumbers(next, nextFieldNumber.current);
            nextFieldNumber.current = next.next_field_number;
            setHistory(items => [...items.slice(-49), definition]); setFuture([]);
            setDefinition(next); latest.current = next; setDirty(true); setMessage(null);
        }
        function undo() {
            if (!history.length) return;
            const previous = model.withFieldNumbers(history[history.length - 1], nextFieldNumber.current);
            setHistory(history.slice(0, -1)); setFuture([definition, ...future]);
            setDefinition(previous); latest.current = previous; setDirty(true);
        }
        function redo() {
            if (!future.length) return;
            const next = model.withFieldNumbers(future[0], nextFieldNumber.current);
            setHistory([...history, definition]); setFuture(future.slice(1));
            setDefinition(next); latest.current = next; setDirty(true);
        }
        async function save() {
            while (saveInFlight.current) {
                const prior = await saveInFlight.current;
                if (!prior) return null;
                if (latest.current === saved.current) {
                    return formIdRef.current;
                }
            }
            setSaving(true);
            const snapshot = latest.current;
            const task = (async () => {
                try {
                    const id = formIdRef.current;
                    const result = await request(id ? config.api + '/' + id : config.api, 'POST', id ? { definition: snapshot, revision: revisionRef.current } : { definition: snapshot });
                    revisionRef.current = result.revision; setRevision(result.revision); setStatus(result.status);
                    if (!id) { formIdRef.current = result.id; setFormId(result.id); window.history.replaceState(null, '', config.editUrl + result.id); }
                    saved.current = snapshot;
                    if (latest.current === snapshot) setDirty(false);
                    return result.id;
                } catch (error) { setMessage({ type: 'error', text: error.message }); return null; }
            })();
            saveInFlight.current = task;
            const result = await task;
            if (saveInFlight.current === task) saveInFlight.current = null;
            setSaving(false);
            if (!result) return null;
            if (latest.current !== saved.current) return save();
            return result;
        }
        useEffect(() => {
            if (!dirty || saving || !definition.title?.trim()) return;
            const timer = setTimeout(() => save(), 2500);
            return () => clearTimeout(timer);
        }, [definition, dirty, saving, revision, formId]);
        async function publish() {
            if((latest.current.rules || []).some(rule=>(rule.groups?rule.groups.flat():[rule]).some(condition=>!condition.source_field_id))){
                setMessage({type:'error',text:__('Choose a field for every conditional rule before saving.','leenkat-forms')});return;
            }
            const notificationSettings=latest.current.settings?.notifications;
            if(notificationSettings?.enabled && (notificationSettings.items || []).some(item=>item.enabled!==false && item.condition?.groups?.flat().some(condition=>!condition.source_field_id))){setMessage({type:'error',text:__('Choose a field for every notification condition before saving.','leenkat-forms')});return;}
            if((latest.current.settings?.confirmations?.items || []).some(item=>item.condition?.groups?.flat().some(condition=>!condition.source_field_id))){setMessage({type:'error',text:__('Choose a field for every confirmation condition before saving.','leenkat-forms')});return;}
            setPublishing(true);
            try {
                const id = dirty || saveInFlight.current || !formIdRef.current ? await save() : formIdRef.current;
                if (!id) return;
                const publishRevision = revisionRef.current;
                await request(config.api + '/' + id + '/publish', 'POST', {});
                setStatus('published'); setPublishedRevision(publishRevision); setMessage(null);
            } catch (error) { setMessage({ type: 'error', text: error.message }); }
            finally { setPublishing(false); }
        }
        async function duplicate() {
            if (!formId) return;
            try { const result = await request(config.api + '/' + formId + '/duplicate', 'POST', {}); window.location.href = config.editUrl + result.id; }
            catch (error) { setMessage({ type: 'error', text: error.message }); }
        }
        function updateField(id, patch) { commit({ ...definition, fields: definition.fields.map(field => field.id === id ? { ...field, ...patch } : field) }); }
        function updateSettings(id, patch) { const field = definition.fields.find(item => item.id === id); if (field) updateField(id, { settings: { ...field.settings, ...patch } }); }
        function updateRule(id, patch) { const rules = [...(definition.rules || [])]; const index = rules.findIndex(rule => rule.field_id === id); if (index < 0) rules.push({ field_id: id, ...patch }); else rules[index] = { ...rules[index], ...patch }; commit({ ...definition, rules }); }
        function removeRule(id) { commit({ ...definition, rules: (definition.rules || []).filter(rule => rule.field_id !== id) }); }
        function ruleSources(rule) { return (rule.groups ? rule.groups.flat() : [rule]).map(condition => condition.source_field_id); }
        function cleanRules(fields) { const positions = Object.fromEntries(fields.map((field, index) => [field.id, index])); return (definition.rules || []).filter(rule => positions[rule.field_id] !== undefined && ruleSources(rule).every(source => (source === '' || positions[source] !== undefined && positions[source] < positions[rule.field_id]))); }
        function updateNotification(kind, patch) { const settings = definition.settings || {}; const notifications = settings.notifications || {}; commit({ ...definition, settings: { ...settings, notifications: { ...notifications, [kind]: { ...(notifications[kind] || {}), ...patch } } } }); }
        function allocateFieldId() {
            const used = new Set(latest.current.fields.flatMap(field => [field.id, field.display_id]));
            let id;
            do { id = 'f_' + String(nextFieldNumber.current++).padStart(4, '0'); } while (used.has(id));
            return id;
        }
        function performDrop(source, target) {
            if (!source || source.kind === 'new' && !labels[source.type]) return;
            if(source.kind==='new'&&source.type==='captcha'){activateCaptcha();return;}
            if(model.legacy(definition)){setMessage({type:'warning',text:__('Convert this draft to Page Breaks before changing its layout.','leenkat-forms')});return;}
            const result = model.applyDrop(definition.fields, source, target, type => newField(type, allocateFieldId()));
            if (!result) {setMessage({type:'warning',text:__('Keep at least one field before, after and between Page Breaks.','leenkat-forms')});return;}
            if (!model.rulesRemainOrdered(result.fields, definition.rules)) {
                setMessage({ type: 'warning', text: __('This move would break a conditional rule. Move its linked fields first.', 'leenkat-forms') });
                return;
            }
            commit({ ...definition, fields: result.fields });
            setSelected(result.selectedId);
            setSection('fields'); setFieldsTab('options'); setOptionsTab('general');
            setAnnouncement(__('Field placed at position', 'leenkat-forms') + ' ' + (result.fields.findIndex(field => field.id === result.selectedId) + 1) + '.');
        }
        function insert(type) {
            if(type==='captcha'){activateCaptcha();return;}
            // Page Break palette clicks split immediately before the last field.
            // Selection never changes this position; drag/drop retains explicit targeting.
            if(type==='page_break' && definition.fields.length<2){setMessage({type:'warning',text:__('Add at least two fields before adding a Page Break.','leenkat-forms')});return;}
            performDrop({kind:'new',type},{kind:'between',index:definition.fields.length-(type==='page_break'?1:0)});
        }
        function moveByKeyboard(id, direction) {
            const index = definition.fields.findIndex(field => field.id === id);
            if (index < 0 || index + direction < 0 || index + direction >= definition.fields.length) return;
            performDrop({ kind: 'field', id }, { kind: 'between', index: direction < 0 ? index - 1 : index + 2 });
        }
        function remove(id) { const fields = definition.fields.filter(field => field.id !== id);
            if(model.pageBreakErrors(fields).length){setMessage({type:'warning',text:__('Move or remove the adjacent Page Break before deleting this field. Every page must contain a field.','leenkat-forms')});return;}
            const settings={...definition.settings};const notifications=settings.notifications;
            if(settings.security?.akismet_fields && Object.values(settings.security.akismet_fields).includes(id))settings.security={...settings.security,akismet_fields:Object.fromEntries(Object.entries(settings.security.akismet_fields).map(([role,fieldId])=>[role,fieldId===id?'':fieldId]))};
            if(notifications?.items)settings.notifications={...notifications,items:notifications.items.map(item=>({...item,file_fields:(item.file_fields || []).filter(fid=>fid!==id),csv_fields:(item.csv_fields || []).filter(fid=>fid!==id),...(item.condition?{condition:{...item.condition,groups:item.condition.groups.map(group=>group.map(clause=>clause.source_field_id===id?{...clause,source_field_id:'',value:''}:clause))}}:{})}))};
            else if(notifications?.user?.email_field===id)settings.notifications={...notifications,user:{...notifications.user,enabled:false}};
            if(settings.confirmations?.items)settings.confirmations={...settings.confirmations,items:settings.confirmations.items.map(item=>({...item,...(item.condition?{condition:{...item.condition,groups:item.condition.groups.map(group=>group.map(clause=>clause.source_field_id===id?{...clause,source_field_id:'',value:''}:clause))}}:{})}))};
            commit({ ...definition, ...(definition.steps?{steps:definition.steps.map(step=>({...step,field_ids:step.field_ids.filter(fid=>fid!==id)}))}:{}), settings, fields, rules: cleanRules(fields) }); if (selected === id) setSelected(null); }
        function openSettings(event) { event.preventDefault(); setPreview(false); setSettingsTab('general'); setSection('settings'); }
        function copy(id) { const index = definition.fields.findIndex(field => field.id === id); if (index < 0 || definition.fields[index].type==='page_break') return; const fields = [...definition.fields]; const newId = allocateFieldId(); const clone = { ...fields[index], id: newId, display_id: newId, settings: { ...fields[index].settings } }; fields.splice(index + 1, 0, clone); commit({ ...definition, fields }); setSelected(clone.id); setSection('fields'); setFieldsTab('options'); setOptionsTab('general'); }
        function selectField(id) { setSelected(id); setSection('fields'); setFieldsTab('options'); setOptionsTab('general'); }
        function startDrag(event, source) {
            event.dataTransfer.setData('application/x-leenkat-field', JSON.stringify(source));
            event.dataTransfer.setData('text/plain', source.kind === 'field' ? source.id : source.type);
            event.dataTransfer.effectAllowed = source.kind === 'field' ? 'move' : 'copy';
            const bounds = event.currentTarget?.getBoundingClientRect?.();
            if (event.dataTransfer.setDragImage && document.createElement && event.currentTarget?.cloneNode) {
                const root = document.getElementById('lkf-builder-root');
                if (root) {
                    const preview = source.kind === 'field' ? event.currentTarget.cloneNode(true) : document.createElement('div');
                    if (source.kind === 'new') {
                        preview.className = 'lkf-canvas-field';
                        const body = document.createElement('div');
                        body.className = 'lkf-field-preview';
                        const label = document.createElement('div');
                        label.className = 'lkf-preview-label';
                        label.textContent = labels[source.type] || source.type;
                        const control = document.createElement(source.type === 'textarea' ? 'textarea' : 'input');
                        control.disabled = true;
                        body.append(label, control);
                        preview.appendChild(body);
                    }
                    preview.classList.remove('is-selected', 'is-dragging', 'is-drop-before', 'is-drop-after');
                    preview.classList.add('lkf-drag-preview');
                    preview.removeAttribute('draggable');
                    const canvas = root.querySelector('.lkf-canvas');
                    preview.style.width = (source.kind === 'field' ? bounds?.width || 600 : Math.max(260, (canvas?.clientWidth || 630) - 60)) + 'px';
                    root.appendChild(preview);
                    dragPreviewRef.current = preview;
                    event.dataTransfer.setDragImage(preview, 24, 24);
                    setTimeout(() => { if (dragPreviewRef.current === preview) { preview.remove(); dragPreviewRef.current = null; } }, 0);
                }
            }
            dragSourceRef.current = source;
            setDragSource(source);
        }
        function endDrag() {
            dragPreviewRef.current?.remove();
            dragPreviewRef.current = null;
            dragSourceRef.current = null;
            setDragSource(null);
            setDropTarget(null);
        }
        function sourceFrom(event) {
            try { return JSON.parse(event.dataTransfer.getData('application/x-leenkat-field')); }
            catch (_) { return dragSourceRef.current; }
        }
        function handleDrop(event, target) {
            event.preventDefault(); event.stopPropagation();
            performDrop(sourceFrom(event), target);
            endDrag();
        }
        function cardDropTarget(event, field) {
            const index = definition.fields.findIndex(item => item.id === field.id);
            if (index < 0) return null;
            if (dragSource?.kind === 'field' && dragSource.id === field.id) {
                return canvasDropTarget(event, event.currentTarget.closest('.lkf-canvas'));
            }
            const bounds = event.currentTarget.getBoundingClientRect();
            if (dragSource && event.clientY > bounds.top + bounds.height * .25 && event.clientY < bounds.bottom - bounds.height * .25) {
                const side = event.clientX < bounds.left + bounds.width * .25 ? 'left' : event.clientX > bounds.right - bounds.width * .25 ? 'right' : null;
                if (side && model.canDropInColumn(definition.fields, dragSource, field.id, side)) {
                    return { kind: 'column', anchorId: field.id, side };
                }
            }
            return { kind: 'between', index: index + (event.clientY >= bounds.top + bounds.height / 2 ? 1 : 0) };
        }
        function canvasDropTarget(event, canvas = event.currentTarget) {
            const cards = [...canvas.querySelectorAll('.lkf-canvas-field')].filter(card => card.dataset.fieldId !== dragSource?.id);
            if (!cards.length) return { kind: 'between', index: 0 };
            const pointerX = event.clientX;
            const pointerY = event.clientY;
            let closest = null;
            let distance = Infinity;
            cards.forEach(card => {
                const index = definition.fields.findIndex(field => field.id === card.dataset.fieldId);
                if (index < 0) return;
                const box = card.getBoundingClientRect();
                const dx = Math.max(box.left - pointerX, 0, pointerX - box.right);
                const dy = Math.max(box.top - pointerY, 0, pointerY - box.bottom);
                const score = dy * dy + dx * dx;
                if (score < distance) { distance = score; closest = { index, box }; }
            });
            if (!closest) return { kind: 'between', index: definition.fields.length };
            const anchor = definition.fields[closest.index];
            if (anchor?.width === 50 && pointerX > closest.box.right && pointerY >= closest.box.top && pointerY <= closest.box.bottom &&
                model.canDropInColumn(definition.fields, dragSource, anchor.id, 'right')) {
                return { kind: 'column', anchorId: anchor.id, side: 'right' };
            }
            return { kind: 'between', index: closest.index + (pointerY >= closest.box.top + closest.box.height / 2 ? 1 : 0) };
        }
        function isNoopDrop(target) {
            if (dragSource?.kind !== 'field' || target?.kind !== 'between') return false;
            const index = definition.fields.findIndex(field => field.id === dragSource.id);
            return index >= 0 && (target.index === index || target.index === index + 1);
        }
        function markDrop(event, target) {
            if (!dragSource || !target) return;
            event.preventDefault(); event.stopPropagation();
            event.dataTransfer.dropEffect = dragSource.kind === 'field' ? 'move' : 'copy';
            const key = isNoopDrop(target) ? null : JSON.stringify(target);
            if (dropTarget !== key) setDropTarget(key);
        }
        function dropZone(target, label, className = '') {
            const key = JSON.stringify(target);
            return e('div', {
                key, className: 'lkf-drop-zone ' + className + (dropTarget === key ? ' is-active' : ''),
                'aria-hidden': 'true',
                onDragOver: event => { event.preventDefault(); event.stopPropagation(); event.dataTransfer.dropEffect = dragSource?.kind === 'field' ? 'move' : 'copy'; if (dropTarget !== key) setDropTarget(key); },
                onDragLeave: () => {},
                onDrop: event => handleDrop(event, target)
            }, e('span', {}, label));
        }
        function renderPreviewField(field, editor = true) {
            const options = field.settings?.options || [];
            let control;
            if (field.type === 'section_divider') control=e('hr',{className:'lkf-section-line'});
            else if (field.type === 'file_upload') control=field.settings?.upload_style==='classic'?e('input',{type:'file',disabled:true}):e('div',{className:'lkf-upload-drop'},uploadIcon(),e('span',{},__('Click or drag a file to this area to upload.','leenkat-forms')));
            else if (field.type === 'datetime') control=e('div',{className:'lkf-datetime-preview'},dateParts(field.settings).map(part=>e('label',{key:part},part==='date' && field.settings?.date_type==='dropdown'?e('div',{className:'lkf-date-dropdowns'},[__("Month", 'leenkat-forms'),__("Day", 'leenkat-forms'),__("Year", 'leenkat-forms')].map(label=>e('select',{key:label,disabled:true},e('option',{},__(label,'leenkat-forms'))))):e('input',{type:'text',disabled:true,placeholder:field.settings?.[part+'_placeholder'] || ''}),e('span',{className:field.settings?.hide_sublabels?'lkf-label-is-hidden':''},part==='date'?__('Date','leenkat-forms'):__('Time','leenkat-forms')))));
            else if (field.type === 'address') control=e('div',{className:'lkf-address-preview'},addressParts(field.settings).map(part=>e('label',{key:part,className:['address1','address2'].includes(part)?'lkf-address-wide':''},addressOptions(part,field.settings)?e('select',{disabled:true,value:field.settings?.address_parts?.[part]?.default_value || ''},e('option',{value:''},field.settings?.address_parts?.[part]?.placeholder || (part==='country'?__("--- Select Country ---", 'leenkat-forms'):__("--- Select State ---", 'leenkat-forms'))),Object.entries(addressOptions(part,field.settings)).map(([value,label])=>e('option',{key:value,value},label))):e('input',{disabled:true,placeholder:field.settings?.address_parts?.[part]?.placeholder || '',value:field.settings?.address_parts?.[part]?.default_value || '',readOnly:true}),e('span',{className:field.settings?.hide_sublabels?'lkf-label-is-hidden':''},__(addressCaptions(field.settings)[part],'leenkat-forms')))));
            else if (field.type === 'textarea') control = e('textarea', { disabled: true, rows: 3, placeholder: field.settings?.placeholder || '', value: field.settings?.default_value || '', readOnly: true });
            else if (field.type === 'select') {
                const items=choiceItems(field.settings); const defaults=field.settings?.default_options || [];
                if(field.settings?.select_style==='modern') {
                    const selected=items.filter(item=>defaults.includes(item.value));
                    control=e('div',{className:'lkf-modern-select-preview'},field.settings.multiple
                        ? selected.map(item=>e('span',{key:item.value,className:'lkf-preview-chip'},item.label,' ×'))
                        : e('span',{},selected[0]?.label || field.settings.placeholder || items[0]?.label || ''),
                        !selected.length && field.settings.multiple && e('span',{className:'lkf-modern-placeholder'},field.settings.placeholder || __('Search choices…','leenkat-forms')));
                } else control = e('span', { className: 'lkf-select-preview'+(field.settings?.multiple?' lkf-select-multiple':'') }, e('select', { disabled: true,multiple:!!field.settings?.multiple,value:field.settings?.multiple?defaults:defaults[0] || (field.settings?.placeholder?'':items[0]?.value || '') }, !!field.settings?.placeholder && e('option',{value:''},field.settings.placeholder), items.map((item, index) => e('option', { key: index,value:item.value }, item.label))), !field.settings?.multiple && e('span', { className: 'lkf-select-chevron', 'aria-hidden': 'true' }));
            }
            else if (field.type === 'email' && field.settings?.email_confirmation) control=e('div',{className:'lkf-email-preview'},['email','confirm'].map(part=>e('label',{key:part},e('input',{type:'email',disabled:true,placeholder:part==='email'?field.settings.placeholder || '':field.settings.confirmation_placeholder || '',value:part==='email'?field.settings.default_value || '':'',readOnly:true}),e('span',{className:field.settings.hide_sublabels?editor?'lkf-label-is-hidden':'lkf-visually-hidden':''},part==='email'?__('Email','leenkat-forms'):__('Confirm Email','leenkat-forms')))));
            else if (field.type === 'radio' || field.type === 'checkboxes') control = e('div', { className: 'lkf-choice-preview lkf-choice-layout-' + (field.settings?.choice_layout || 'one') + (field.settings?.image_choices || field.settings?.icon_choices ? ' lkf-visual-choices' : '') + (field.settings?.image_choices ? ' lkf-image-choices lkf-image-style-' + (field.settings?.image_choice_style || 'modern') : '') }, choiceItems(field.settings).map((item, index) => e('label', { key: index }, e('input', { type: field.type === 'radio' ? 'radio' : 'checkbox', disabled: true,checked:(field.settings?.default_options || []).includes(item.value),readOnly:true }), choiceDecoration(field.settings,item.value),e('span',{},item.label))));
            else if (['checkbox', 'consent'].includes(field.type)) control = e('label', { className: 'lkf-consent-preview' }, e('input', { type: 'checkbox', disabled: true }), ' ' + (field.type === 'consent' ? field.settings?.agreement ?? agreementText : field.label + (field.required ? ' *' : '')));
            else if (field.type === 'name') control = e('div', { className: 'lkf-name-preview lkf-name-' + (field.settings?.name_format || 'first_last') }, nameParts(field.settings).map(part => e('label', {key:part}, e('input', {type:'text',disabled:true,placeholder:field.settings?.name_parts?.[part]?.placeholder || '',value:field.settings?.name_parts?.[part]?.default_value || '',readOnly:true,'aria-label':nameCaption(part,field.settings)}), field.settings?.name_format !== 'simple' && e('span', {className:field.settings?.hide_sublabels ? editor ? 'lkf-label-is-hidden' : 'lkf-visually-hidden' : ''}, ({first:__('First','leenkat-forms'),middle:__('Middle','leenkat-forms'),last:__('Last','leenkat-forms')})[part]))));
            else if (field.type === 'number_slider') control = e('div',{className:'lkf-slider-preview'},e('input', { type: 'range', disabled: true, min: field.settings?.min ?? 0, max: field.settings?.max ?? 100, step: field.settings?.step ?? 1,value:field.settings?.default_value ?? field.settings?.min ?? 0,readOnly:true }),e('output',{},(field.settings?.value_display ?? __("Selected Value: {value}", 'leenkat-forms')).split('{value}').join(String(field.settings?.default_value ?? field.settings?.min ?? 0))));
            else if (field.type === 'captcha') control = e('div', { className: 'lkf-captcha-preview' }, __('A short arithmetic challenge appears on the published form.', 'leenkat-forms'));
            else if (field.type === 'rating') control = e('span', { className: 'lkf-select-preview' }, e('select', { disabled: true }, e('option', {}, __('Select an option', 'leenkat-forms')), Array.from({ length: field.settings?.max || 5 }, (_, index) => e('option', { key: index }, String(index + 1)))), e('span', { className: 'lkf-select-chevron', 'aria-hidden': 'true' }));
            else control = e('input', { type: field.type === 'phone' ? 'tel' : field.type === 'date' || field.type === 'time' || field.type === 'email' || field.type === 'number' || field.type === 'url' ? field.type : 'text', disabled: true, placeholder: field.settings?.placeholder || '', value: field.settings?.default_value || '', readOnly: true });
            return e('div', { className: 'lkf-field-preview lkf-size-' + (field.settings?.field_size || 'large') }, field.type !== 'checkbox' && e('div', { className: 'lkf-preview-label' + (field.settings?.hide_label ? editor ? ' lkf-label-is-hidden' : ' lkf-visually-hidden' : '') },
                field.settings?.hide_label && editor && e('svg', { className: 'lkf-hidden-label-icon', width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, 'aria-label': __('Label hidden on published form', 'leenkat-forms'), role: 'img' }, e('path', { d: 'M3 3l18 18M10.5 4.2A10.8 10.8 0 0 1 21 12a14 14 0 0 1-3.1 4.3M6.2 6.3A14.7 14.7 0 0 0 3 12c2 5 5 7.8 9 7.8a10 10 0 0 0 4.2-1M9.9 9.9a3 3 0 0 0 4.2 4.2' })),
                field.label, field.required && e('span', { className: 'lkf-required' }, ' *')), control, !!field.settings?.description && e('p', { className: 'lkf-preview-description'+(field.type==='checkboxes' && field.settings?.terms_display?' lkf-disclaimer':'') }, field.settings.description));
        }
        function renderFieldCard(field) {
            const pointerPlaceholder=pointerView && pointerView.source.kind!=='choice' && (pointerView.source.kind==='field'?field.id===pointerView.source.id:field.id===pointerPlaceholderId);
            if(pointerPlaceholder)return e('div',{key:field.id,className:'lkf-pointer-placeholder'+(pointerView.target?.blocked?' is-blocked':''),'data-pointer-placeholder':true,style:{height:pointerView.height,minHeight:70},'aria-hidden':true});
            const beforeKey = JSON.stringify({ kind: 'between', index: definition.fields.findIndex(item => item.id === field.id) });
            const afterKey = JSON.stringify({ kind: 'between', index: definition.fields.findIndex(item => item.id === field.id) + 1 });
            const leftColumnKey = JSON.stringify({ kind: 'column', anchorId: field.id, side: 'left' });
            const rightColumnKey = JSON.stringify({ kind: 'column', anchorId: field.id, side: 'right' });
            return e('div', {
                key: field.id, 'data-field-id': field.id, className: 'lkf-canvas-field' + (field.type==='page_break'?' is-page-break':'') + (selected === field.id ? ' is-selected' : '') + (dragSource?.id === field.id ? ' is-dragging' : '') + (dropTarget === beforeKey ? ' is-drop-before' : '') + (dropTarget === afterKey ? ' is-drop-after' : '') + (dropTarget === leftColumnKey ? ' is-drop-column-left' : '') + (dropTarget === rightColumnKey ? ' is-drop-column-right' : ''),
                draggable: !window.lkfPointerSort, tabIndex: 0, role: 'group',
                'aria-label': field.label + '. ' + __('Press Control and an arrow key to reorder.', 'leenkat-forms'),
                'aria-keyshortcuts': 'Control+ArrowUp Control+ArrowDown',
                onDragStart: event => startDrag(event, { kind: 'field', id: field.id }),
                onDragEnd: endDrag,
                onDragOver: event => markDrop(event, cardDropTarget(event, field)),
                onDrop: event => { const target = cardDropTarget(event, field); if (target) handleDrop(event, target); },
                onKeyDown: event => { if (event.target !== event.currentTarget) return; if (event.ctrlKey && !event.altKey && !event.shiftKey && ['ArrowUp', 'ArrowDown'].includes(event.key)) { event.preventDefault(); moveByKeyboard(field.id, event.key === 'ArrowUp' ? -1 : 1); } else if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectField(field.id); } },
                onClick: () => selectField(field.id)
            },
                e('div', { className: 'lkf-card-top' },
                    e('span', { className: 'lkf-drag-handle', 'aria-hidden': 'true' }, '⠿'),
                    e('span', { className: 'lkf-card-kind' }, labels[field.type]),
                    e('div', { className: 'lkf-field-actions' },
                        e(Button, { variant: 'tertiary', className: 'lkf-icon-action', 'aria-label': __('Move field up', 'leenkat-forms'), title: __('Move field up', 'leenkat-forms'), disabled: definition.fields[0]?.id === field.id, onClick: event => { event.stopPropagation(); moveByKeyboard(field.id, -1); } }, actionIcon('up')),
                        e(Button, { variant: 'tertiary', className: 'lkf-icon-action', 'aria-label': __('Move field down', 'leenkat-forms'), title: __('Move field down', 'leenkat-forms'), disabled: definition.fields.at(-1)?.id === field.id, onClick: event => { event.stopPropagation(); moveByKeyboard(field.id, 1); } }, actionIcon('down')),
                        field.type!=='page_break' && e(Button, { variant: 'tertiary', className: 'lkf-icon-action', 'aria-label': __('Duplicate field', 'leenkat-forms'), title: __('Duplicate field', 'leenkat-forms'), onClick: event => { event.stopPropagation(); copy(field.id); } }, actionIcon('copy')),
                        e(Button, { isDestructive: true, variant: 'tertiary', className: 'lkf-icon-action', 'aria-label': __('Delete field', 'leenkat-forms'), title: __('Delete field', 'leenkat-forms'), onClick: event => { event.stopPropagation(); setPendingDelete(field.id); } }, actionIcon('delete'))
                    )
                ),
                field.type!=='page_break'&&model.steps(definition).length>1&&e('span',{className:'lkf-field-step-badge'},model.steps(definition).find(step=>step.fields.some(item=>item.id===field.id))?.title),
                field.type==='page_break'?e('div',{className:'lkf-page-break-divider'},e('span',{},__('Page Break','leenkat-forms')),e('strong',{},field.settings?.page_title || __('Next Page','leenkat-forms'))):renderPreviewField(field)
            );
        }
        async function exportForm() {try{const result=await request(config.transferApi+'encode','POST',{definition});const blob=new Blob([JSON.stringify(result.bundle,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download='leenkat-form-'+(formId||'draft')+'.json';link.click();URL.revokeObjectURL(url);}catch(error){setMessage({type:'error',text:error.message});}}
        async function copyShortcode() {
            if (!formId) return;
            const shortcode = '[leenkat_form id="' + formId + '"]';
            let copied = false;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(shortcode);
                    copied = true;
                }
            } catch { /* Older or restricted contexts may still support selection-based copy. */ }
            if (!copied) {
                const input = document.createElement('textarea');
                input.value = shortcode;
                input.readOnly = true;
                input.style.cssText = 'position:fixed;inset:0 auto auto 0;opacity:0;pointer-events:none';
                document.body.appendChild(input);
                try {
                    input.select();
                    copied = document.execCommand('copy');
                } catch { /* The notice exposes the shortcode for manual copying. */ }
                finally { input.remove(); }
            }
            setToolsOpen(false);
            toolsButtonRef.current?.focus();
            setMessage(copied
                ? {type:'success', text:__('Shortcode copied.','leenkat-forms')}
                : {type:'error', text:e(element.Fragment, {}, __('Copy this shortcode manually.','leenkat-forms'), ' ', e('code',{dir:'ltr'},shortcode))});
        }
        function toolsKeyDown(event) {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setToolsOpen(false); toolsButtonRef.current?.focus(); return; }
            if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return;
            event.preventDefault();
            if (!toolsOpen) { setToolsOpen(true); return; }
            const items = Array.from(toolsRef.current?.querySelectorAll('[role="menuitem"]:not(:disabled):not([aria-disabled="true"])') || []);
            const index = items.indexOf(event.target);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowUp' ? -1 : 1) + items.length) % items.length;
            items[next]?.focus();
        }
        function renderToolsMenu() {
            const documentIcon = direction => e('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:1.7,'aria-hidden':true},e('path',{d:'M14 3H5v18h14V8l-5-5v5h5'}),e('path',{d:direction==='export'?'M12 11v7m-3-3 3 3 3-3':'M12 18v-7m-3 3 3-3 3 3'}));
            return e('div',{className:'lkf-header-tools',ref:toolsRef,onKeyDown:toolsKeyDown},
                e('button',{type:'button',className:'lkf-tools-toggle',ref:toolsButtonRef,'aria-label':__('Form actions','leenkat-forms'),title:__('Form actions','leenkat-forms'),'aria-haspopup':'menu','aria-expanded':toolsOpen,'aria-controls':'lkf-header-tools-menu',onClick:()=>setToolsOpen(!toolsOpen)},
                    e('svg',{width:20,height:20,viewBox:'0 0 20 20',fill:'currentColor','aria-hidden':true},[4,10,16].flatMap(cx=>[4,10,16].map(cy=>e('circle',{key:cx+'-'+cy,cx,cy,r:1.8}))))),
                toolsOpen && e('div',{id:'lkf-header-tools-menu',className:'lkf-tools-menu',role:'menu','aria-label':__('Form actions','leenkat-forms')},
                    e('button',{type:'button',role:'menuitem',disabled:!formId,onClick:()=>{setToolsOpen(false);duplicate();}},actionIcon('copy'),__('Duplicate form','leenkat-forms')),
                    e('button',{type:'button',role:'menuitem',disabled:!formId,onClick:copyShortcode},actionIcon('copy'),__('Copy Shortcode','leenkat-forms')),
                    e('button',{type:'button',role:'menuitem',onClick:()=>{exportForm();setToolsOpen(false);toolsButtonRef.current?.focus();}},documentIcon('export'),__('Export JSON','leenkat-forms')),
                    e('button',{type:'button',role:'menuitem',onClick:()=>{importInputRef.current?.click();setToolsOpen(false);toolsButtonRef.current?.focus();}},documentIcon('import'),__('Import JSON','leenkat-forms')),
                    e('a',{role:'menuitem',href:formId&&hasEntries?config.entriesUrl+formId:undefined,target:'_blank',rel:'noopener noreferrer','aria-disabled':!formId||!hasEntries,tabIndex:formId&&hasEntries?0:-1,onClick:event=>{if(!formId||!hasEntries)event.preventDefault();else setToolsOpen(false);}},e('svg',{width:18,height:18,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:1.7,'aria-hidden':true},e('rect',{x:3,y:5,width:18,height:14,rx:1}),e('path',{d:'m3 6 9 7 9-7'})),__('View Entries','leenkat-forms'))),
                e('input',{ref:importInputRef,type:'file',accept:'.json,application/json','aria-label':__('Import form JSON file','leenkat-forms'),onChange:importForm,hidden:true})
            );
        }
        async function importForm(event) {const file=event.target.files?.[0];if(!file)return;try{if(file.size>2097152)throw new Error(__('Choose a JSON file no larger than 2 MiB.','leenkat-forms'));const value=JSON.parse((await file.text()).replace(/^\uFEFF/,''));const data=await request(config.transferApi+'decode','POST',value);setPendingImport(data);}catch(error){setMessage({type:'error',text:error.message});}event.target.value='';}
        let pointerPlaceholderId='lkf_drag_placeholder';while(definition.fields.some(field=>field.id===pointerPlaceholderId))pointerPlaceholderId+='_';
        const current = definition.fields.find(field => field.id === selected);
        const currentRule = (definition.rules || []).find(rule => rule.field_id === selected);
        const previousFields = current ? definition.fields.slice(0, definition.fields.findIndex(field => field.id === selected)) : [];
        const availableSources = previousFields.filter(field => !['captcha','section_divider','page_break','file_upload','address','datetime'].includes(field.type) && !(definition.rules || []).some(rule => rule.field_id === field.id));
        const hasDependents = (definition.rules || []).some(rule => ruleSources(rule).includes(selected));
        const currentGroups = currentRule ? currentRule.groups || [[{ source_field_id: currentRule.source_field_id, operator: currentRule.operator, value: currentRule.value || '' }]] : [];
        function changeGroups(groups) { if (current) updateRule(current.id, { groups }); }
        function changeCondition(groupIndex, conditionIndex, patch) {
            const groups = currentGroups.map(group => group.map(condition => ({ ...condition })));
            groups[groupIndex][conditionIndex] = { ...groups[groupIndex][conditionIndex], ...patch };
            changeGroups(groups);
        }
        function addCondition(groupIndex) {
            if (currentGroups[groupIndex].length >= 5) return;
            changeGroups(currentGroups.map((group, index) => index === groupIndex ? [...group, { source_field_id: '', operator: 'equals', value: '' }] : group));
        }
        function removeCondition(groupIndex, conditionIndex) {
            const groups = currentGroups.map((group, index) => index === groupIndex ? group.filter((_, position) => position !== conditionIndex) : group).filter(group => group.length);
            if (groups.length) changeGroups(groups); else if (current) removeRule(current.id);
        }
        function renderRuleCondition(condition, groupIndex, conditionIndex) {
            const source = availableSources.find(field => field.id === condition.source_field_id);
            return e('div', { key: groupIndex + '-' + conditionIndex, className: 'lkf-logic-condition' },
                e(SelectControl, { label: __('Field', 'leenkat-forms'), hideLabelFromVision: true, value: condition.source_field_id || '', options: [{value:'',label:__('--- Select Field ---','leenkat-forms')},...availableSources.map(field => ({ value: field.id, label: field.label }))], onChange: value => changeCondition(groupIndex, conditionIndex, { source_field_id: value, value: '' }) }),
                e(SelectControl, { label: __('Condition', 'leenkat-forms'), hideLabelFromVision: true, disabled:!source, value: condition.operator, options: [['equals',__("is", 'leenkat-forms')],['not_equals',__("is not", 'leenkat-forms')],['is_empty',__("empty", 'leenkat-forms')],['not_empty',__("not empty", 'leenkat-forms')],['contains',__("contains", 'leenkat-forms')],['not_contains',__("does not contain", 'leenkat-forms')],['starts_with',__("starts with", 'leenkat-forms')],['ends_with',__("ends with", 'leenkat-forms')],['greater_than',__("greater than", 'leenkat-forms')],['less_than',__("less than", 'leenkat-forms')]].map(([value,label]) => ({value,label:__(label,'leenkat-forms')})), onChange: value => changeCondition(groupIndex, conditionIndex, { operator: value }) }),
                e('div', { className: 'lkf-logic-value' }, !['is_empty', 'not_empty'].includes(condition.operator) && (!source?e(SelectControl,{label:__('Value','leenkat-forms'),hideLabelFromVision:true,disabled:true,value:'',options:[{value:'',label:__('--- Select Choice ---','leenkat-forms')}]}) : ['select','radio','checkboxes','rating'].includes(source?.type)
                    ? e(SelectControl, { label: __('Value', 'leenkat-forms'), hideLabelFromVision: true, value: condition.value || '', options: [{ value: '', label: __('--- Select Choice ---', 'leenkat-forms') }, ...(source?.type === 'rating' ? Array.from({ length: source.settings?.max || 5 }, (_, index) => String(index + 1)) : source.settings?.options || []).map(value => ({ value, label: value }))], onChange: value => changeCondition(groupIndex, conditionIndex, { value }) })
                    : e(TextControl, { label: __('Value', 'leenkat-forms'), hideLabelFromVision: true, value: condition.value || '', onChange: value => changeCondition(groupIndex, conditionIndex, { value }) }))),
                e(Button, { variant: 'primary', disabled: currentGroups[groupIndex].length >= 5, onClick: () => addCondition(groupIndex) }, __('And', 'leenkat-forms')),
                e(Button, { variant: 'tertiary', className: 'lkf-logic-delete', isDestructive: true, 'aria-label': __('Remove condition', 'leenkat-forms'), onClick: () => removeCondition(groupIndex, conditionIndex) }, actionIcon('delete'))
            );
        }
        const paletteButton = ([type, label]) => e('button', {
            key: type, type: 'button', 'data-palette-type':type, className: 'lkf-palette-item', draggable: type!=='captcha'&&!window.lkfPointerSort,
            onDragStart: event => {if(type==='captcha'){event.preventDefault();return;}startDrag(event, { kind: 'new', type });},
            onDragEnd: endDrag,
            onClick: () => insert(type)
        }, e('span', { className: 'lkf-palette-plus', 'aria-hidden': 'true' }, type==='page_break'?e('svg',{className:'lkf-page-break-icon',width:18,height:18,viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:1.7,focusable:'false'},e('path',{d:'M5 9V3h14v6M5 15v6h14v-6M2 12h3m3 0h3m3 0h3m3 0h2'})):'+'), label);
        function renderPalette() {
            return e('div', { className: 'lkf-drawer-content lkf-palette' },
                e('h3', {}, __('Standard Fields', 'leenkat-forms')),
                e('div', { className: 'lkf-palette-grid' }, standardTypes.map(paletteButton)),
                e('h3', {}, __('Fancy Fields', 'leenkat-forms')),
                e('div', { className: 'lkf-palette-grid' }, moreTypes.map(paletteButton))
            );
        }
        function renderAddressOptions() {
            const settings=current.settings || {};
            return Object.entries(addressCaptions(settings)).filter(([part])=>part!=='country' || settings.address_scheme==='international').map(([part,caption])=>{
                const partSettings=settings.address_parts?.[part] || {};
                const set=patch=>updateSettings(current.id,{address_parts:{...(settings.address_parts || {}),[part]:{...partSettings,...patch}}});
                const options=addressOptions(part,settings);
                return e('div',{key:part,className:'lkf-name-part-options lkf-address-part-options'},e('div',{className:'lkf-control-heading'},e('span',{},__(caption,'leenkat-forms')),['address2','postal','country'].includes(part) && e('label',{className:'lkf-address-hide'},e('span',{},__('Hide','leenkat-forms')),e('input',{type:'checkbox','aria-label':__('Hide','leenkat-forms')+' '+__(caption,'leenkat-forms'),checked:!!partSettings.hidden,onChange:event=>set({hidden:event.target.checked})}))),!partSettings.hidden && e('div',{className:'lkf-name-part-inputs'},e(TextControl,{label:caption+__(" Placeholder", 'leenkat-forms'),hideLabelFromVision:true,help:__('Placeholder','leenkat-forms'),value:partSettings.placeholder || '',onChange:placeholder=>set({placeholder})}),e(options?SelectControl:TextControl,{label:caption+__(" Default Value", 'leenkat-forms'),hideLabelFromVision:true,help:__('Default Value','leenkat-forms'),value:partSettings.default_value || '',...(options?{options:[{value:'',label:__("--- Select ---", 'leenkat-forms')},...Object.entries(options).map(([value,label])=>({value,label}))]}:{}),onChange:default_value=>set({default_value})})));
            });
        }
        function renderDateTimeOptions() {
            const settings=current.settings || {};
            const set=patch=>updateSettings(current.id,patch);
            return e('div',{}, dateParts(settings).includes('date') && e('div',{},
                optionControl(SelectControl,{label:__('Date Type','leenkat-forms'),value:settings.date_type || 'datepicker',options:[{value:'datepicker',label:__('Date Picker','leenkat-forms')},{value:'dropdown',label:__('Date Dropdown','leenkat-forms')}],onChange:date_type=>set({date_type})}),
                optionControl(TextControl,{label:__('Date Placeholder','leenkat-forms'),value:settings.date_placeholder || '',onChange:date_placeholder=>set({date_placeholder})}),
                optionControl(SelectControl,{label:__('Date Format','leenkat-forms'),value:settings.date_format || 'm/d/Y',options:['m/d/Y','d/m/Y','Y-m-d','F j, Y'].map(value=>({value,label:value})),onChange:date_format=>set({date_format})}),
                toggleOption({label:__('Limit Days','leenkat-forms'),checked:!!settings.limit_days,onChange:limit_days=>set({limit_days,allowed_days:settings.allowed_days || [1,2,3,4,5]})}),
                settings.limit_days && e('div',{className:'lkf-day-options'},[__("Sunday", 'leenkat-forms'),__("Monday", 'leenkat-forms'),__("Tuesday", 'leenkat-forms'),__("Wednesday", 'leenkat-forms'),__("Thursday", 'leenkat-forms'),__("Friday", 'leenkat-forms'),__("Saturday", 'leenkat-forms')].map((label,day)=>toggleOption({label:__(label,'leenkat-forms'),checked:(settings.allowed_days || [1,2,3,4,5]).includes(day),onChange:checked=>set({allowed_days:checked?[...(settings.allowed_days || [1,2,3,4,5]),day].sort():(settings.allowed_days || [1,2,3,4,5]).filter(value=>value!==day)})}))),
                toggleOption({label:__('Disable Past Dates','leenkat-forms'),checked:!!settings.disable_past_dates,onChange:disable_past_dates=>set({disable_past_dates})})),
                dateParts(settings).includes('time') && e('div',{},
                optionControl(SelectControl,{label:__('Time Interval','leenkat-forms'),value:settings.time_interval || 30,options:[5,10,15,30,60].map(value=>({value,label:value+' minutes'})),onChange:value=>set({time_interval:Number(value)})}),
                optionControl(TextControl,{label:__('Time Placeholder','leenkat-forms'),value:settings.time_placeholder || '',onChange:time_placeholder=>set({time_placeholder})}),
                optionControl(SelectControl,{label:__('Time Format','leenkat-forms'),value:settings.time_format || '12',options:[{value:'12',label:'12 H'},{value:'24',label:'24 H'}],onChange:time_format=>set({time_format})}),
                toggleOption({label:__('Limit Hours','leenkat-forms'),checked:!!settings.limit_hours,onChange:limit_hours=>set({limit_hours})}),
                settings.limit_hours && e('div',{className:'lkf-name-part-inputs'},optionControl(TextControl,{label:__('Start Time','leenkat-forms'),type:'time',value:settings.time_min || '09:00',onChange:time_min=>set({time_min})}),optionControl(TextControl,{label:__('End Time','leenkat-forms'),type:'time',value:settings.time_max || '17:00',onChange:time_max=>set({time_max})}))));
        }
        function renderChoiceEditor() {
            const options=current.settings?.options || [];
            const defaults=current.settings?.default_options || [];
            const multiple=current.type==='checkboxes' || !!current.settings?.multiple;
            const chooseImage=value=>{
                if(!window.wp?.media)return;
                const targetId=current.id;
                const frame=window.wp.media({title:__('Choose image','leenkat-forms'),button:{text:__('Use Image','leenkat-forms')},library:{type:'image'},multiple:false});
                frame.on('select',()=>{const image=frame.state().get('selection').first().toJSON();updateSettings(targetId,{choice_images:{...(current.settings.choice_images || {}),[value]:image.url}});});
                frame.open();
            };
            const setOptions=next=>updateSettings(current.id,{options:next,default_options:defaults.filter(value=>next.includes(value)),...Object.fromEntries(['choice_images','choice_icons'].map(key=>[key,Object.fromEntries(Object.entries(current.settings?.[key] || {}).filter(([value])=>next.includes(value)))]))});
            const choiceEntries=options.map((value,index)=>({value,index}));
            const visualChoiceEntries=pointerView?.source.kind==='choice' && pointerView.source.fieldId===current.id && pointerView.target
                ? window.lkfPointerSort.move(choiceEntries,pointerView.source.from,pointerView.target.index):choiceEntries;
            const addChoice=index=>{let label=__('New Choice','leenkat-forms');let suffix=2;while(options.includes(label)) label=__('New Choice','leenkat-forms')+' '+suffix++;const next=[...options];next.splice(index+1,0,label);setOptions(next);};
            return e('div',{className:'lkf-choice-editor'},
                e('div',{className:'lkf-choices-heading'},e('span',{},__('Choices','leenkat-forms'),helpIcon(__('Choices','leenkat-forms'),__('Add, remove, reorder and select the default choices.','leenkat-forms'))),e(Button,{variant:'tertiary',onClick:()=>setBulkChoices(bulkChoices===null?'':null)},__('Bulk Add','leenkat-forms'))),
                (current.settings?.dynamic_choices && current.settings.dynamic_choices!=='off')?e('p',{},__('Choices are populated from the selected dynamic source.','leenkat-forms')):visualChoiceEntries.map(({value,index})=>pointerView?.source.kind==='choice' && pointerView.source.fieldId===current.id && pointerView.source.from===index?e('div',{key:index,'data-pointer-placeholder':true,className:'lkf-choice-placeholder',style:{height:pointerView.height},'aria-hidden':true}):e('div',{key:index,className:'lkf-choice-row'+(choiceHover===index?' is-choice-target':''),'data-choice-index':index,onDragOver:event=>{if(choiceDrag.current!==null){event.preventDefault();event.stopPropagation();event.dataTransfer.dropEffect='move';}},onDrop:event=>{event.preventDefault();event.stopPropagation();const from=choiceDrag.current;choiceDrag.current=null;if(from===null)return;const next=[...options];next.splice(index,0,next.splice(from,1)[0]);setOptions(next);}},
                    e('input',{type:multiple?'checkbox':'radio',checked:defaults.includes(value),'aria-label':__('Default choice','leenkat-forms')+' '+(index+1),onChange:()=>{},onClick:()=>updateSettings(current.id,{default_options:defaults.includes(value)?defaults.filter(item=>item!==value):multiple?[...defaults,value]:[value]})}),
                    e('span',{role:'button',tabIndex:0,className:'lkf-choice-handle',style:{touchAction:'none'},'aria-label':__('Move choice','leenkat-forms')+' '+(index+1),onPointerDown:event=>{if(window.lkfPointerSort)return;if(event.button!==0)return;event.preventDefault();event.stopPropagation();choiceDrag.current=index;event.currentTarget.setPointerCapture(event.pointerId);},onPointerMove:event=>{if(window.lkfPointerSort)return;if(choiceDrag.current===null)return;const row=document.elementFromPoint(event.clientX,event.clientY)?.closest('.lkf-choice-row');setChoiceHover(row?Number(row.dataset.choiceIndex):-1);},onPointerUp:event=>{if(window.lkfPointerSort)return;const from=choiceDrag.current;choiceDrag.current=null;const row=document.elementFromPoint(event.clientX,event.clientY)?.closest('.lkf-choice-row');setChoiceHover(-1);if(from===null||!row||row.closest('.lkf-choice-editor')!==event.currentTarget.closest('.lkf-choice-editor'))return;const to=Number(row.dataset.choiceIndex);if(to===from)return;const next=[...options];next.splice(to,0,next.splice(from,1)[0]);setOptions(next);},onPointerCancel:()=>{if(window.lkfPointerSort)return;choiceDrag.current=null;setChoiceHover(-1);},onKeyDown:event=>{if(!event.ctrlKey || !['ArrowUp','ArrowDown'].includes(event.key))return;event.preventDefault();const to=index+(event.key==='ArrowUp'?-1:1);if(to<0||to>=options.length)return;const next=[...options];next.splice(to,0,next.splice(index,1)[0]);setOptions(next);}},'☰'),
                    e(TextControl,{label:__('Choice','leenkat-forms')+' '+(index+1),hideLabelFromVision:true,value,onChange:nextValue=>{const next=[...options];next[index]=nextValue;updateSettings(current.id,{options:next,default_options:defaults.map(item=>item===value?nextValue:item),...Object.fromEntries(['choice_images','choice_icons'].map(key=>{const media={...(current.settings?.[key] || {})};if(Object.hasOwn(media,value)){media[nextValue]=media[value];if(nextValue!==value)delete media[value];}return[key,media];}))});}}),
                    e(Button,{className:'lkf-choice-add',disabled:options.length>=100,'aria-label':__('Add choice after','leenkat-forms')+' '+(index+1),onClick:()=>addChoice(index)},'+'),
                    e(Button,{className:'lkf-choice-remove',disabled:options.length<=1,'aria-label':__('Remove choice','leenkat-forms')+' '+(index+1),onClick:()=>setOptions(options.filter((_,position)=>position!==index))},'−'),
                    current.type!=='select' && current.settings?.image_choices && e('div',{className:'lkf-choice-media lkf-choice-media-image'},current.settings.choice_images?.[value]
                        ? e('div',{className:'lkf-choice-image-preview'},e('button',{type:'button',className:'lkf-change-choice-image','aria-label':__('Change image for choice','leenkat-forms')+' '+(index+1),onClick:()=>chooseImage(value)},e('img',{src:current.settings.choice_images[value],alt:''})),e('button',{type:'button',className:'lkf-remove-choice-image','aria-label':__('Remove image for choice','leenkat-forms')+' '+(index+1),onClick:()=>updateSettings(current.id,{choice_images:{...(current.settings.choice_images || {}),[value]:''}})},actionIcon('delete')))
                        : e(Button,{variant:'primary',className:'lkf-upload-image',onClick:()=>chooseImage(value)},__('Upload Image','leenkat-forms'))),
                    current.type!=='select' && current.settings?.icon_choices && e('div',{className:'lkf-choice-media'},choiceDecoration(current.settings,value),e(SelectControl,{label:__('Icon for choice','leenkat-forms')+' '+(index+1),value:current.settings.choice_icons?.[value] || 'star',options:Object.keys(config.choiceIcons || {}).map(icon=>({value:icon,label:({star:__('Star','leenkat-forms'),heart:__('Heart','leenkat-forms'),check:__('Check','leenkat-forms'),circle:__('Circle','leenkat-forms'),home:__('Home','leenkat-forms'),user:__('User','leenkat-forms')})[icon] || icon})),onChange:icon=>updateSettings(current.id,{choice_icons:{...(current.settings.choice_icons || {}),[value]:icon}})}))
                )),
                bulkChoices!==null && e('div',{className:'lkf-bulk-choices'},e(TextareaControl,{label:__('Choices, one per line','leenkat-forms'),value:bulkChoices,onChange:setBulkChoices}),e(Button,{variant:'primary',onClick:()=>{setOptions([...options,...bulkChoices.split(/\r?\n/).map(text=>text.trim()).filter(Boolean)].slice(0,100));setBulkChoices(null);}},__('Add Choices','leenkat-forms'))));
        }
        function convertLegacyDraft(){
            const next=model.convertLegacy(definition);
            if(!next){setMessage({type:'warning',text:__('This layout cannot be converted safely. Correct conditional field order or reduce the field count first.','leenkat-forms')});return;}
            commit(next);selectField('__page_start');
        }
        function renderPageOptions(){
            const general=definition.settings?.general || {};
            const setGeneral=patch=>commit({...definition,settings:{...definition.settings,general:{...general,...patch}}});
            return e('div',{className:'lkf-drawer-content lkf-options lkf-field-settings'},
                e('h2',{},current?.type==='page_break'?__('Page Break','leenkat-forms'):__('First Page / Progress Indicator','leenkat-forms')),
                model.legacy(definition)?e('div',{},e('p',{},__('This form uses the earlier steps format. Convert the draft to edit page boundaries here. Empty legacy pages are omitted; published versions remain unchanged.','leenkat-forms')),e(Button,{variant:'primary',onClick:convertLegacyDraft},__('Convert Draft to Page Breaks','leenkat-forms'))):e(element.Fragment,{},
                    current?.type==='page_break'&&e('div',{},
                        optionControl(TextControl,{label:__('Page Title','leenkat-forms'),value:current.settings?.page_title || '',help:__('Names the page after this divider.','leenkat-forms'),onChange:page_title=>updateSettings(current.id,{page_title})}),
                        optionControl(TextControl,{label:__('Next Label','leenkat-forms'),value:current.settings?.next_label ?? __("Next", 'leenkat-forms'),help:__('Button on the page before this divider.','leenkat-forms'),onChange:next_label=>updateSettings(current.id,{next_label})}),
                        optionControl(TextControl,{label:__('Previous Label','leenkat-forms'),value:current.settings?.previous_label ?? __("Previous", 'leenkat-forms'),help:__('Button on the page after this divider.','leenkat-forms'),onChange:previous_label=>updateSettings(current.id,{previous_label})})),
                    optionControl(TextControl,{label:__('First Page Title','leenkat-forms'),value:general.first_page_title || '',onChange:first_page_title=>setGeneral({first_page_title})}),
                    optionControl(SelectControl,{label:__('Progress Indicator','leenkat-forms'),value:general.progress_indicator || 'bar',options:[['bar',__("Bar", 'leenkat-forms')],['steps',__("Steps", 'leenkat-forms')],['none',__("None", 'leenkat-forms')]].map(([value,label])=>({value,label:__(label,'leenkat-forms')})),onChange:progress_indicator=>setGeneral({progress_indicator})})
                )
            );
        }
        function renderFieldOptions() {
            if(selected==='__page_start'||current?.type==='page_break')return renderPageOptions();
            if (!current) return e('div', { className: 'lkf-empty-options' }, e('h2', {}, __('Field options', 'leenkat-forms')), e('p', {}, __('Select a field in the preview to edit its options.', 'leenkat-forms')));
            return e('div', { className: 'lkf-drawer-content lkf-options lkf-field-settings' },
                e('div', { className: 'lkf-options-subtabs', role: 'tablist', 'aria-label': __('Field option sections', 'leenkat-forms') },
                    [['general', __('General', 'leenkat-forms')], ['advanced', __('Advanced', 'leenkat-forms')], ['logic', __('Smart Logic', 'leenkat-forms')]].map(([key, label]) =>
                        e('button', { key, type: 'button', role: 'tab', 'aria-selected': optionsTab === key, className: optionsTab === key ? 'is-active' : '', onClick: () => setOptionsTab(key) }, label)
                    )
                ),
                e('div', { className: 'lkf-options-title' }, e('h2', {}, labels[current.type]), e('span', { className: 'lkf-field-id', title: current.id === current.display_id ? undefined : __('Storage ID: ', 'leenkat-forms') + current.id }, __('Stable ID: ', 'leenkat-forms'), e('code', {}, current.display_id || current.id))),
                optionsTab === 'general' && e('div', { role: 'tabpanel' },
                    optionControl(TextControl, { label: __('Label', 'leenkat-forms'), value: current.label, onChange: value => updateField(current.id, { label: value }) }),
                    choiceTypes.includes(current.type) && renderChoiceEditor(),
                    ['radio','checkboxes'].includes(current.type) && !!current.settings?.image_choices && e('div',{className:'lkf-image-choices-notice'},e('strong',{},__('Images are not cropped or resized.','leenkat-forms')),e('p',{},__('Use images with matching dimensions, ideally 250 × 250 pixels or smaller.','leenkat-forms'))),
                    ['radio','checkboxes'].includes(current.type) && toggleOption({label:__('Use image choices','leenkat-forms'),checked:!!current.settings?.image_choices,onChange:value=>updateSettings(current.id,{image_choices:value,icon_choices:false})}),
                    ['radio','checkboxes'].includes(current.type) && !!current.settings?.image_choices && optionControl(SelectControl,{label:__('Image Choice Style','leenkat-forms'),value:current.settings?.image_choice_style || 'modern',options:['modern','classic','none'].map(value=>({value,label:({modern:__('Modern','leenkat-forms'),classic:__('Classic','leenkat-forms'),none:__('None','leenkat-forms')})[value]})),onChange:value=>updateSettings(current.id,{image_choice_style:value})}),
                    ['radio','checkboxes'].includes(current.type) && toggleOption({label:__('Use icon choices','leenkat-forms'),checked:!!current.settings?.icon_choices,onChange:value=>updateSettings(current.id,{icon_choices:value,image_choices:false})}),
                    current.type==='consent' && optionControl(TextControl,{label:__('Agreement','leenkat-forms'),value:current.settings?.agreement ?? agreementText,onChange:value=>{updateField(current.id,{required:true,settings:{...current.settings,agreement:value}});}}),
                    current.type==='phone' && optionControl(SelectControl,{label:__('Format','leenkat-forms'),value:current.settings?.phone_format || 'international',options:[{value:'smart',label:__('Smart','leenkat-forms')},{value:'us',label:__('US','leenkat-forms')},{value:'international',label:__('International','leenkat-forms')}],onChange:value=>updateSettings(current.id,{phone_format:value})},__("Phone Format", 'leenkat-forms')),
                    current.type === 'name' && optionControl(SelectControl, {label:__('Format','leenkat-forms'),value:current.settings?.name_format || 'first_last',options:[{value:'simple',label:__('Simple','leenkat-forms')},{value:'first_last',label:__('First Last','leenkat-forms')},{value:'first_middle_last',label:__('First Middle Last','leenkat-forms')}],onChange:value=>updateSettings(current.id,{name_format:value})}),
                    current.type==='datetime' && optionControl(SelectControl,{label:__('Format','leenkat-forms'),value:current.settings?.datetime_format || 'date_time',options:[['date_time',__("Date and Time", 'leenkat-forms')],['date',__("Date", 'leenkat-forms')],['time',__("Time", 'leenkat-forms')]].map(([value,label])=>({value,label:__(label,'leenkat-forms')})),onChange:value=>updateSettings(current.id,{datetime_format:value})},'datetime-format'),
                    current.type==='address' && optionControl(SelectControl,{label:__('Scheme','leenkat-forms'),value:current.settings?.address_scheme || 'us',options:[{value:'us',label:'US'},{value:'international',label:__('International','leenkat-forms')}],onChange:value=>updateSettings(current.id,{address_scheme:value,address_parts:{...(current.settings?.address_parts || {}),state:{...(current.settings?.address_parts?.state || {}),default_value:''}}})}),
                    optionControl(TextareaControl, { label: __('Description', 'leenkat-forms'), value: current.settings?.description || '', onChange: value => updateSettings(current.id, { description: value }) }),
                    current.type==='file_upload' && e('div',{},
                        optionControl(TextControl,{label:__('Allowed File Extensions','leenkat-forms'),value:current.settings?.allowed_extensions || '',help:__('Comma-separated extensions, for example pdf, jpg, png. Blank uses supported safe WordPress file types.','leenkat-forms'),onChange:value=>updateSettings(current.id,{allowed_extensions:value})}),
                        optionControl(TextControl,{label:__('Max File Size','leenkat-forms'),type:'number',min:0.1,max:50,step:0.1,value:current.settings?.max_file_size || '',help:__('Size per file in MB. The server limit also applies.','leenkat-forms'),onChange:value=>updateSettings(current.id,{max_file_size:value===''?0:Number(value)})}),
                        optionControl(TextControl,{label:__('Max File Uploads','leenkat-forms'),type:'number',min:1,max:10,value:current.settings?.max_files ?? 1,onChange:value=>updateSettings(current.id,{max_files:Number(value)})})),
                    !['number_slider','consent','section_divider'].includes(current.type) && toggleOption( { label: __('Required', 'leenkat-forms'), checked: !!current.required, disabled: current.type === 'captcha', onChange: value => updateField(current.id, { required: value }) }),
                    current.type==='email' && toggleOption({label:__('Enable Email Confirmation','leenkat-forms'),checked:!!current.settings?.email_confirmation,onChange:value=>updateSettings(current.id,{email_confirmation:value})}),
                    current.type === 'number_slider' && e('div',{},
                        e('div',{className:'lkf-slider-range-options'},e('div',{className:'lkf-control-heading'},e('span',{},__('Value','leenkat-forms')),helpIcon(__('Value','leenkat-forms'),__('Set the minimum and maximum values accepted by this slider.','leenkat-forms'))),e('div',{className:'lkf-name-part-inputs'},['min','max'].map(key=>e(TextControl,{key,label:key==='min'?__('Minimum','leenkat-forms'):__('Maximum','leenkat-forms'),hideLabelFromVision:true,help:key==='min'?__('Minimum','leenkat-forms'):__('Maximum','leenkat-forms'),type:'number',step:'any',value:current.settings?.[key] ?? (key==='min'?0:100),onChange:value=>updateSettings(current.id,{[key]:Number(value)})})))),
                        optionControl(TextControl,{label:__('Default Value','leenkat-forms'),type:'number',step:'any',value:current.settings?.default_value ?? String(current.settings?.min ?? 0),onChange:value=>updateSettings(current.id,{default_value:value})}),
                        optionControl(TextControl,{label:__('Increment','leenkat-forms'),type:'number',step:'any',min:0.000001,value:current.settings?.step ?? 1,onChange:value=>updateSettings(current.id,{step:Number(value)})})
                    ),
                    current.type === 'rating' && optionControl(SelectControl, { label: __('Maximum rating', 'leenkat-forms'), value: current.settings?.max || 5, options: [2, 3, 4, 5, 6, 7, 8, 9, 10].map(value => ({ label: String(value), value })), onChange: value => updateSettings(current.id, { max: Number(value) }) })
                ),
                optionsTab === 'advanced' && e('div', { role: 'tabpanel' },
                    ['radio','checkboxes'].includes(current.type) && toggleOption({label:__('Randomize Choices','leenkat-forms'),checked:!!current.settings?.randomize_choices,onChange:value=>updateSettings(current.id,{randomize_choices:value})}),
                    ['radio','checkboxes'].includes(current.type) && optionControl(SelectControl,{label:__('Choice Layout','leenkat-forms'),value:current.settings?.choice_layout || 'one',options:[['one',__("One Column", 'leenkat-forms')],['two',__("Two Columns", 'leenkat-forms')],['three',__("Three Columns", 'leenkat-forms')],['inline',__("Inline", 'leenkat-forms')]].map(([value,label])=>({value,label:__(label,'leenkat-forms')})),onChange:value=>updateSettings(current.id,{choice_layout:value})}),
                    current.type==='checkboxes' && optionControl(TextControl,{label:__('Choice Limit','leenkat-forms'),type:'number',min:1,max:100,value:current.settings?.choice_limit || '',onChange:value=>updateSettings(current.id,{choice_limit:value===''?0:Number(value)})}),
                    current.type==='select' && toggleOption({label:__('Multiple Options Selection','leenkat-forms'),checked:!!current.settings?.multiple,onChange:value=>updateSettings(current.id,{multiple:value,default_options:(current.settings?.default_options || []).slice(0,value?100:1)})}),
                    current.type==='select' && optionControl(SelectControl,{label:__('Style','leenkat-forms'),value:current.settings?.select_style || 'classic',options:[{value:'classic',label:__('Classic','leenkat-forms')},{value:'modern',label:__('Modern','leenkat-forms')}],onChange:value=>updateSettings(current.id,{select_style:value})}),
                    sizedTypes.includes(current.type) && optionControl(SelectControl, { label: __('Field Size', 'leenkat-forms'), value: current.settings?.field_size || 'large', options: [['small', __('Small', 'leenkat-forms')], ['medium', __('Medium', 'leenkat-forms')], ['large', __('Large', 'leenkat-forms')]].map(([value, label]) => ({ value, label })), onChange: value => updateSettings(current.id, { field_size: value }) }),
                    current.type==='datetime' && renderDateTimeOptions(),
                    current.type==='address' && renderAddressOptions(),
                    current.type==='file_upload' && e('div',{},optionControl(SelectControl,{label:__('Style','leenkat-forms'),value:current.settings?.upload_style || 'modern',options:[{value:'modern',label:__('Modern','leenkat-forms')},{value:'classic',label:__('Classic','leenkat-forms')}],onChange:value=>updateSettings(current.id,{upload_style:value})},'upload-style'),toggleOption({label:__('Store file in WordPress Media Library','leenkat-forms'),checked:!!current.settings?.media_library,help:__('Files saved to the Media Library use standard WordPress media URLs and may be publicly accessible. Turn this off to store new form attachments privately.','leenkat-forms'),onChange:value=>updateSettings(current.id,{media_library:value})})),
                    current.type==='number_slider' && optionControl(TextControl,{label:__('Value Display','leenkat-forms'),value:current.settings?.value_display ?? __("Selected Value: {value}", 'leenkat-forms'),onChange:value=>updateSettings(current.id,{value_display:value})}),
                    current.type === 'name' && nameParts(current.settings).map(part => e('div',{key:part,className:'lkf-name-part-options'},
                        e('div',{className:'lkf-control-heading'}, e('span',{},nameCaption(part,current.settings)),helpIcon(nameCaption(part,current.settings),__('Set a placeholder and optional initial value for this part of the name.','leenkat-forms'))),
                        e('div',{className:'lkf-name-part-inputs'},['placeholder','default_value'].map(key=>e(TextControl,{key,label:nameCaption(part,current.settings)+' '+(key==='placeholder'?__('Placeholder','leenkat-forms'):__('Default Value','leenkat-forms')),hideLabelFromVision:true,help:key==='placeholder'?__('Placeholder','leenkat-forms'):__('Default Value','leenkat-forms'),value:current.settings?.name_parts?.[part]?.[key] || '',onChange:value=>updateSettings(current.id,{name_parts:{...(current.settings?.name_parts || {}),[part]:{...(current.settings?.name_parts?.[part] || {}),[key]:value}}})}))))),
                    placeholderTypes.includes(current.type) && optionControl(TextControl, { label: __('Placeholder Text', 'leenkat-forms'), value: current.settings?.placeholder || '', onChange: value => updateSettings(current.id, { placeholder: value }) }),
                    current.type==='email' && !!current.settings?.email_confirmation && optionControl(TextControl,{label:__('Confirmation Placeholder Text','leenkat-forms'),value:current.settings?.confirmation_placeholder || '',onChange:value=>updateSettings(current.id,{confirmation_placeholder:value})}),
                    ['text', 'textarea'].includes(current.type) && toggleOption( { label: __('Limit Length', 'leenkat-forms'), checked: !!current.settings?.limit_length_enabled, onChange: value => updateSettings(current.id, { limit_length_enabled: value, max_length: current.settings?.max_length || 100 }) }),
                    ['text', 'textarea'].includes(current.type) && !!current.settings?.limit_length_enabled && e('div', { className: 'lkf-length-controls' },
                        e(TextControl, { label: __('Maximum Length', 'leenkat-forms'), hideLabelFromVision: true, type: 'number', min: 1, max: current.type === 'textarea' ? 5000 : 1000, value: current.settings?.max_length || 100, onChange: value => updateSettings(current.id, { max_length: Math.max(1, Math.min(current.type === 'textarea' ? 5000 : 1000, Number(value) || 1)) }) }),
                        e(SelectControl, { label: __('Length Unit', 'leenkat-forms'), hideLabelFromVision: true, value: current.settings?.length_unit || 'characters', options: [{ label: __('Characters', 'leenkat-forms'), value: 'characters' }, { label: __('Words', 'leenkat-forms'), value: 'words' }], onChange: value => updateSettings(current.id, { length_unit: value }) })),
                    defaultTypes.includes(current.type) && optionControl(TextControl, { label: __('Default Value', 'leenkat-forms'), value: current.settings?.default_value || '', onChange: value => updateSettings(current.id, { default_value: value }) }),
                    current.type==='email' && optionControl(SelectControl,{label:__('Allowlist / Denylist','leenkat-forms'),value:current.settings?.email_filter || 'none',options:[{value:'none',label:__('None','leenkat-forms')},{value:'allow',label:__('Allowlist','leenkat-forms')},{value:'deny',label:__('Denylist','leenkat-forms')}],onChange:value=>updateSettings(current.id,{email_filter:value})}),
                    current.type==='email' && ['allow','deny'].includes(current.settings?.email_filter) && optionControl(TextareaControl,{label:__('Email Patterns, one per line','leenkat-forms'),value:(current.settings?.email_patterns || []).join('\n'),help:__('Use * as a wildcard, for example *@example.com.','leenkat-forms'),onChange:value=>updateSettings(current.id,{email_patterns:value.split(/\r?\n/).map(text=>text.trim()).filter(Boolean)})}),
                    choiceTypes.includes(current.type) && optionControl(SelectControl,{label:__('Dynamic Choices','leenkat-forms'),value:current.settings?.dynamic_choices || 'off',options:[{value:'off',label:__('Off','leenkat-forms')},{value:'post_type',label:__('Post Type','leenkat-forms')},{value:'taxonomy',label:__('Taxonomy','leenkat-forms')}],onChange:value=>updateSettings(current.id,{dynamic_choices:value,dynamic_source:Object.keys(config.choiceSources?.[value] || {})[0] || '',default_options:[]})}),
                    choiceTypes.includes(current.type) && ['post_type','taxonomy'].includes(current.settings?.dynamic_choices) && optionControl(SelectControl,{label:current.settings.dynamic_choices==='post_type'?__('Dynamic Post Type Source','leenkat-forms'):__('Dynamic Taxonomy Source','leenkat-forms'),value:current.settings.dynamic_source || '',options:Object.entries(config.choiceSources?.[current.settings.dynamic_choices] || {}).map(([value,label])=>({value,label})),onChange:value=>updateSettings(current.id,{dynamic_source:value,default_options:[]})}),
                    current.type === 'text' && optionControl(TextControl, { label: __('Input Mask', 'leenkat-forms'), value: current.settings?.input_mask || '', onChange: value => updateSettings(current.id, { input_mask: value }) }),
                    optionControl(TextControl, { label: __('CSS Classes', 'leenkat-forms'), value: current.settings?.css_classes || '', onChange: value => updateSettings(current.id, { css_classes: value }) }),
                    !['checkbox', 'captcha', 'section_divider'].includes(current.type) && toggleOption( { label: __('Hide Label', 'leenkat-forms'), checked: !!current.settings?.hide_label, onChange: value => updateSettings(current.id, { hide_label: value }) }),
                    ['datetime','address'].includes(current.type) && toggleOption({label:__('Hide Sublabels','leenkat-forms'),checked:!!current.settings?.hide_sublabels,onChange:value=>updateSettings(current.id,{hide_sublabels:value})}),
                    current.type==='checkboxes' && toggleOption({label:__('Enable Disclaimer / Terms of Service Display','leenkat-forms'),checked:!!current.settings?.terms_display,onChange:value=>updateSettings(current.id,{terms_display:value})}),
                    current.type === 'name' && current.settings?.name_format !== 'simple' && toggleOption({label:__('Hide Sublabels','leenkat-forms'),checked:!!current.settings?.hide_sublabels,onChange:value=>updateSettings(current.id,{hide_sublabels:value})}),
                    current.type==='email' && !!current.settings?.email_confirmation && toggleOption({label:__('Hide Sublabels','leenkat-forms'),checked:!!current.settings?.hide_sublabels,onChange:value=>updateSettings(current.id,{hide_sublabels:value})}),
                    defaultTypes.includes(current.type) && !['email','phone'].includes(current.type) && toggleOption( { label: __('Read-Only', 'leenkat-forms'), checked: !!current.settings?.read_only, onChange: value => updateSettings(current.id, { read_only: value }) }),
                    !['section_divider','file_upload'].includes(current.type) && toggleOption( { label: __('Searchable entry value', 'leenkat-forms'), checked: !!current.searchable, onChange: value => updateField(current.id, { searchable: value }) })
                ),
                optionsTab === 'logic' && e('div', { role: 'tabpanel' },
                    e('div', { className: 'lkf-toggle-option' }, e(ToggleControl, { label: __('Enable Conditional Logic', 'leenkat-forms'),
                        help: hasDependents ? __('This field is used as a condition source. Remove its dependent conditions before making it conditional.', 'leenkat-forms') : !availableSources.length ? (previousFields.length ? __('Choose an earlier field without conditional logic as the condition source.', 'leenkat-forms') : __('Add a field above this one to use as the condition source.', 'leenkat-forms')) : undefined,
                        disabled: !currentRule && (!availableSources.length || hasDependents), checked: !!currentRule,
                        onChange: value => { if (!value) removeRule(current.id); else if (availableSources.length && !hasDependents) updateRule(current.id, { action: 'show', groups: [[{ source_field_id: '', operator: 'equals', value: '' }]] }); }
                    }), helpIcon(__('Enable Conditional Logic', 'leenkat-forms'), __('Show or hide this field when all conditions in any group match.', 'leenkat-forms'))),
                    currentRule && e('div', { className: 'lkf-logic-builder' },
                        e('div', { className: 'lkf-logic-action' },
                            e(SelectControl, { label: __('Action', 'leenkat-forms'), hideLabelFromVision: true, value: currentRule.action || 'show', options: [{ value: 'show', label: __('Show', 'leenkat-forms') }, { value: 'hide', label: __('Hide', 'leenkat-forms') }], onChange: value => updateRule(current.id, { action: value }) }),
                            e('span', {}, __('this field if', 'leenkat-forms'))
                        ),
                        currentGroups.map((group, groupIndex) => e('div', { key: groupIndex, className: 'lkf-logic-group' },
                            groupIndex > 0 && e('span', { className: 'lkf-logic-join' }, __('or', 'leenkat-forms')),
                            group.map((condition, conditionIndex) => e('div', { key: conditionIndex },
                                renderRuleCondition(condition, groupIndex, conditionIndex)
                            ))
                        )),
                        currentGroups.length < 5 && e('div', { className: 'lkf-logic-add-group' }, e('span', { className: 'lkf-logic-join' }, __('or', 'leenkat-forms')), e(Button, { variant: 'primary', onClick: () => changeGroups([...currentGroups, [{ source_field_id: '', operator: 'equals', value: '' }]]) }, __('Add New Group', 'leenkat-forms')))
                    )
                )
            );
        }
        function renderSettingsNav() {
            return e('nav',{className:'lkf-settings-nav','aria-label':__('Settings sections','leenkat-forms')},[['general',__("General", 'leenkat-forms')],['security',__("Spam Protection and Security", 'leenkat-forms')],['notifications',__("Notifications", 'leenkat-forms')],['confirmations',__("Confirmations", 'leenkat-forms')]].map(([key,label])=>e('button',{type:'button',key,className:settingsTab===key?'is-current':'','aria-current':settingsTab===key?'page':undefined,onClick:()=>setSettingsTab(key)},__(label,'leenkat-forms'),e('span',{'aria-hidden':true},'›'))));
        }
        function renderSettings() {
            const general=definition.settings?.general || {};
            const set=patch=>commit({...definition,settings:{...definition.settings,general:{...general,...patch}}});
            return e('div',{className:'lkf-settings-stage'},e('main',{id:'lkf-form-settings',className:'lkf-settings-sheet lkf-options','aria-label':__('Form settings','leenkat-forms')},
                settingsTab==='general' && e('h2',{className:'lkf-settings-heading'},__('General','leenkat-forms')),
                settingsTab==='general' && e('div',{},
                    optionControl(TextControl,{label:__('Form Name','leenkat-forms'),value:definition.title || '',onChange:title=>commit({...definition,title})}),
                    optionControl(TextareaControl,{label:__('Form Description','leenkat-forms'),value:general.description || '',onChange:description=>set({description})}),
                    e('div',{className:'lkf-general-control lkf-form-tags'},helpIcon(__("Tags", 'leenkat-forms'),__('Organize forms with tags. Type a tag and press Enter to create it.','leenkat-forms')),e(FormTokenField,{label:__('Tags','leenkat-forms'),value:general.tags || [],suggestions:config.tags || [],maxLength:20,tokenizeOnSpace:false,__experimentalExpandOnFocus:true,__experimentalValidateInput:value=>value.trim().length>0 && value.trim().length<=100 && !/[<>]/.test(value),onChange:values=>set({tags:[...new Map(values.map(value=>[value.trim().toLowerCase(),value.trim()])).values()]})})),
                    optionControl(TextControl,{label:__('Submit Button Text','leenkat-forms'),value:general.submit_text ?? __("Submit", 'leenkat-forms'),onChange:submit_text=>set({submit_text})}),
                    optionControl(TextControl,{label:__('Submit Button Processing Text','leenkat-forms'),value:general.processing_text ?? __("Sending...", 'leenkat-forms'),onChange:processing_text=>set({processing_text})}),
                    e('section',{className:'lkf-general-advanced'},e('button',{type:'button',className:'lkf-advanced-toggle','aria-expanded':generalAdvanced,'aria-controls':'lkf-general-advanced',onClick:()=>setGeneralAdvanced(!generalAdvanced)},__('Advanced','leenkat-forms'),e('span',{'aria-hidden':true},generalAdvanced?'⌄':'›')),
                        generalAdvanced && e('div',{id:'lkf-general-advanced'},
                            optionControl(TextControl,{label:__('Form CSS Class','leenkat-forms'),value:general.form_class || '',onChange:form_class=>set({form_class})}),
                            optionControl(TextControl,{label:__('Submit Button CSS Class','leenkat-forms'),value:general.submit_class || '',onChange:submit_class=>set({submit_class})}),
                            toggleOption({label:__('Enable Prefill by URL','leenkat-forms'),checked:!!general.prefill,onChange:prefill=>set({prefill})}),
                            toggleOption({label:__('Enable AJAX form submission','leenkat-forms'),checked:general.ajax!==false,onChange:ajax=>set({ajax})}),
                            toggleOption({label:__('Disable storing entry information in WordPress','leenkat-forms'),checked:!!general.disable_entries,onChange:disable_entries=>set({disable_entries})}),
                            toggleOption({label:__('Disable storing user details (IP address and user agent)','leenkat-forms'),checked:activeConfig.globalDisableUserDetails || (general.disable_ip ?? true),disabled:!!activeConfig.globalDisableUserDetails,onChange:disable_ip=>set({disable_ip})}),
                            activeConfig.globalDisableUserDetails&&e('p',{className:'lkf-notification-note'},__('Disabled for all forms in Leenkat Forms Settings → General.','leenkat-forms'))
                        )
                    )
                ),
                settingsTab==='notifications' && e(window.lkfNotificationsEditor,{definition,formId,config:activeConfig,onChange:notifications=>commit({...definition,settings:{...definition.settings,notifications}})}),
                settingsTab==='confirmations' && e(window.lkfConfirmationsEditor,{definition,config,onChange:confirmations=>commit({...definition,settings:{...definition.settings,confirmations}})}),
                settingsTab==='security' && e(window.lkfSpamProtectionEditor,{definition,config:activeConfig,onChange:security=>commit({...definition,settings:{...definition.settings,security}}),captchaControls:e(window.lkfCaptchaSettingsEditor,{definition,config:activeConfig,embedded:true,onChange:setCaptchaEnabled,onSelectField:selectField})})
            ));
        }
        function renderSetup() {
            return e('div', { className: 'lkf-setup-stage' },
                e('main', { className: 'lkf-setup-sheet', 'aria-label': __('Form setup', 'leenkat-forms') },
                    e('div', { className: 'lkf-setup-name-row' },
                        e('label', { htmlFor: 'lkf-setup-form-name' }, __('Name Your Form', 'leenkat-forms')),
                        e('input', { id: 'lkf-setup-form-name', type: 'text', value: definition.title || '', onChange: event => commit({ ...definition, title: event.target.value }) })
                    ),
                    e('section', { className: 'lkf-setup-template-section', 'aria-labelledby': 'lkf-setup-template-heading' },
                        e('h2', { id: 'lkf-setup-template-heading' }, __('Select a Template', 'leenkat-forms'))
                    )
                )
            );
        }
        function renderPreviewProgress(){
            const pages=model.steps(definition);
            if(pages.length<2)return null;
            const index=Math.max(0,Math.min(previewStep,pages.length-1)),mode=definition.settings?.general?.progress_indicator || 'bar';
            const announcement=__('Step %1$d of %2$d','leenkat-forms').replace('%1$d',String(index+1)).replace('%2$d',String(pages.length));
            return e('div',{className:'lkf-preview-progress'},
                e('p',{role:'status','aria-live':'polite','aria-atomic':true},announcement),
                mode==='bar'&&e('progress',{max:pages.length,value:index+1,'aria-label':__('Form progress','leenkat-forms'),'aria-valuetext':announcement}),
                mode==='steps'&&e('ol',{'aria-label':__('Form progress','leenkat-forms')},pages.map((page,i)=>e('li',{key:page.id,'aria-current':i===index?'step':undefined},page.title)))
            );
        }
        function renderCanvas() {
            let displayFields=definition.fields;
            if(pointerView && pointerView.source.kind!=='choice' && pointerView.target && !pointerView.target.blocked){
                const result=model.applyDrop(definition.fields,pointerView.source,pointerView.target,type=>newField(type,pointerPlaceholderId));
                if(result)displayFields=result.fields;
            }
            return e('div', { className: 'lkf-canvas-stage' },
                e('main', { className: 'lkf-canvas' + (displayFields.length ? ' has-fields' : ' is-empty'), 'aria-label': __('Form canvas', 'leenkat-forms'), onDragOver: event => markDrop(event, canvasDropTarget(event)), onDrop: event => handleDrop(event, canvasDropTarget(event)) },
                    e('div', { className: 'lkf-canvas-heading' }, e('h2', {}, e('a', { href: '#lkf-form-settings', onClick: openSettings, title: __('Edit form settings', 'leenkat-forms') }, definition.title || __('Untitled Form', 'leenkat-forms'))),captchaIndicator()),
                    !displayFields.length && dropZone({ kind: 'between', index: 0 }, __('Drop a field here to start your form', 'leenkat-forms'), 'lkf-empty-canvas'),
                    (model.hasBreaks(definition)||model.legacy(definition))&&e('button',{type:'button',className:'lkf-page-start'+(selected==='__page_start'?' is-selected':''),onClick:()=>selectField('__page_start')},__('First Page / Progress Indicator','leenkat-forms'),e('strong',{},model.steps(definition)[0]?.title)),
                    model.legacy(definition)&&e('div',{className:'lkf-legacy-pages',role:'note'},e('p',{},__('Legacy page layout. Convert the draft to move, add or remove page boundaries. Empty legacy pages will be omitted. Published versions are retained.','leenkat-forms')),e(Button,{variant:'secondary',onClick:convertLegacyDraft},__('Convert Draft to Page Breaks','leenkat-forms'))),
                    model.legacy(definition)?model.steps(definition).map(page=>e('section',{key:page.id,className:'lkf-legacy-page'},e('h3',{},page.title),page.fields.map(field=>e('div',{key:field.id,className:'lkf-canvas-row'},e('div',{className:'lkf-row-single'},renderFieldCard(field)))))):model.rows(displayFields).map(row => e('div', { key: row.fields[0].id, className: 'lkf-canvas-row' },
                        dropZone({ kind: 'between', index: row.start }, __('Place field here', 'leenkat-forms')),
                        e('div', { className: 'lkf-row-single' },renderFieldCard(row.fields[0]))
                    )),
                    !!displayFields.length && dropZone({ kind: 'between', index: definition.fields.length }, __('Place field at end', 'leenkat-forms')),
                    e('div', { className: 'lkf-canvas-footer' }, e('button', { type: 'button', disabled: true, className: 'lkf-preview-submit' }, definition.settings?.general?.submit_text || __('Submit', 'leenkat-forms')))
                )
            );
        }
        pointerCallbacks.current = {
            source:target=>{
                if(preview || section!=='fields'||model.legacy(definition))return null;
                const handle=target.closest('.lkf-choice-handle');
                if(handle && current){const row=handle.closest('.lkf-choice-row');return {source:{kind:'choice',fieldId:current.id,from:Number(row.dataset.choiceIndex)},element:row};}
                const palette=target.closest('.lkf-palette-item');
                if(palette)return palette.dataset.paletteType==='captcha'?null:{source:{kind:'new',type:palette.dataset.paletteType},element:palette};
                if(target.closest('button,a,input,textarea,select'))return null;
                const card=target.closest('.lkf-canvas-field');
                return card && !card.classList.contains('lkf-pointer-helper')?{source:{kind:'field',id:card.dataset.fieldId},element:card}:null;
            },
            target:(source,point,last)=>{
                const hit=document.elementFromPoint(point.x,point.y);
                if(hit?.closest('[data-pointer-placeholder]'))return last;
                if(source.kind==='choice'){
                    const row=hit?.closest('.lkf-choice-row');
                    if(!row || !row.closest('.lkf-choice-editor'))return null;
                    const bounds=row.getBoundingClientRect();
                    const field=definition.fields.find(item=>item.id===source.fieldId);
                    return {index:window.lkfPointerSort.insertionIndex(source.from,Number(row.dataset.choiceIndex),point.y>=bounds.top+bounds.height/2,field.settings.options.length)};
                }
                const canvas=hit?.closest('.lkf-canvas');
                if(!canvas)return null;
                const cards=Array.from(canvas.querySelectorAll('.lkf-canvas-field:not(.lkf-pointer-helper)'));
                let card=hit?.closest('.lkf-canvas-field');
                if(!card && cards.length)card=cards.reduce((best,item)=>{const r=item.getBoundingClientRect(),b=best.getBoundingClientRect();return Math.abs(point.y-(r.top+r.height/2))<Math.abs(point.y-(b.top+b.height/2))?item:best;});
                let target={kind:'between',index:0};
                if(card){
                    const bounds=card.getBoundingClientRect();const index=definition.fields.findIndex(item=>item.id===card.dataset.fieldId);
                    target={kind:'between',index:index+(point.y>=bounds.top+bounds.height/2?1:0)};
                }
                const probe=model.applyDrop(definition.fields,source,target,type=>newField(type,pointerPlaceholderId));
                return probe?{...target,blocked:!model.rulesRemainOrdered(probe.fields,definition.rules)}:null;
            },
            drop:(source,target)=>{
                if(source.kind==='choice'){
                    const field=definition.fields.find(item=>item.id===source.fieldId);
                    if(field && source.from!==target.index)updateSettings(source.fieldId,{options:window.lkfPointerSort.move(field.settings.options,source.from,target.index)});
                }else performDrop(source,target);
            }
        };
        if (loading) return e('div', { className: 'lkf-loading' }, e(Spinner), __('Loading form builder…', 'leenkat-forms'));
        return e('div', { className: 'lkf-builder' + (dragSource ? ' is-dragging' : '') },
            e('header', { className: 'lkf-builder-header' },
                e('div', { className: 'lkf-brand', 'aria-label': __('Leenkat Forms', 'leenkat-forms') }, e('img', { className: 'lkf-brand-mark', src: config.logoUrl, alt: '', width: 56, height: 56 }), e('span', {}, 'Leenkat', e('strong', {}, ' Forms'))),
                e('div', { className: 'lkf-builder-title' }, e('span', {}, __('Now editing', 'leenkat-forms')), e('a', { href: '#lkf-form-settings', onClick: openSettings, title: __('Edit form settings', 'leenkat-forms') }, definition.title || __('Untitled Form', 'leenkat-forms')), e('small', {role:'status','aria-live':'polite'}, status === 'published' ? __('Published', 'leenkat-forms') : __('Draft', 'leenkat-forms'), dirty ? ' · ' + __('Unsaved changes', 'leenkat-forms') : status==='published' && revision!==publishedRevision ? ' · '+__('Unpublished changes — click Save to apply them','leenkat-forms') : '')),
                e('div', { className: 'lkf-toolbar' },
                    renderToolsMenu(),
                    e(Button, { variant: 'secondary', onClick: () => setPreview(!preview) }, preview ? __('Back to edit', 'leenkat-forms') : __('Preview', 'leenkat-forms')),
                    e(Button, { variant: 'primary', className: 'lkf-primary-save', onClick: publish, disabled: saving || publishing || !definition.fields.length || status === 'published' && !dirty && revision === publishedRevision, 'aria-busy': saving || publishing ? 'true' : 'false' },
                        (saving || publishing) && e('span', { className: 'lkf-save-spinner', 'aria-hidden': 'true' }),
                        saving || publishing ? __('Saving…', 'leenkat-forms') : status === 'published' ? __('Save', 'leenkat-forms') : __('Publish', 'leenkat-forms')),
                    e('a', { className: 'lkf-close', href: config.listUrl, 'aria-label': __('Close builder', 'leenkat-forms'), title: __('Close builder', 'leenkat-forms') }, '×')
                )
            ),
            message && e(Notice, { status: message.type, isDismissible: true, onRemove: () => setMessage(null) }, message.text),
            e('p', { className: 'screen-reader-text', role: 'status', 'aria-live': 'polite' }, announcement),
            pendingImport && e(Modal,{title:__('Replace current draft content?','leenkat-forms'),className:'lkf-import-dialog',onRequestClose:()=>setPendingImport(null)},
                e('p',{},__('This replaces the current draft, including unsaved changes, and saves automatically. Published versions stay unchanged. Use Tools → Import to create new forms.','leenkat-forms')),
                e('p',{},pendingImport.definition.title+' · '+pendingImport.definition.fields.length+' fields'),
                ...(pendingImport.warnings||[]).map((warning,index)=>e('p',{key:index},warning)),
                e(Button,{variant:'secondary',onClick:()=>setPendingImport(null)},__('Cancel','leenkat-forms')),
                e(Button,{variant:'primary',onClick:()=>{commit(pendingImport.definition);setSelected(null);setPendingImport(null);}},__('Replace Current Draft','leenkat-forms'))),
            pendingDelete && e(Modal, { title: __('Delete field?', 'leenkat-forms'), onRequestClose: () => setPendingDelete(null), className: 'lkf-delete-modal' },
                e('p', {}, definition.fields.find(field=>field.id===pendingDelete)?.type==='page_break'?__('This merges the pages. All ordinary fields and existing entries are retained.','leenkat-forms'):__('This field and its answers will be removed from future form submissions. Existing entries stay unchanged.', 'leenkat-forms')),
                e('div', { className: 'lkf-delete-modal-actions' },
                    e(Button, { variant: 'secondary', onClick: () => setPendingDelete(null) }, __('Cancel', 'leenkat-forms')),
                    e(Button, { id: 'lkf-confirm-delete', autoFocus: true, variant: 'primary', isDestructive: true, onClick: () => { remove(pendingDelete); setPendingDelete(null); } }, __('Delete field', 'leenkat-forms'))
                )
            ),
            preview ? e('div', { className: 'lkf-preview-stage' },
                e('section', { className: 'lkf-preview', 'aria-label': __('Form preview', 'leenkat-forms') },
                    e('div', { className: 'lkf-preview-header' }, e('h2', {}, definition.title)),
                    renderPreviewProgress(),
                    model.steps(definition).length>1&&e('h3',{className:'lkf-preview-page-title'},model.steps(definition)[Math.min(previewStep,model.steps(definition).length-1)].title),
                    e('div', { className: 'lkf-preview-grid' }, (model.steps(definition).length>1?model.steps(definition)[Math.min(previewStep,model.steps(definition).length-1)].fields:definition.fields).map(field => e('div', { key: field.id, className: 'lkf-full' }, renderPreviewField(field, false)))),
                    model.steps(definition).length>1&&e('div',{className:'lkf-step-editor-actions'},e(Button,{variant:'secondary',disabled:previewStep<=0,onClick:()=>setPreviewStep(previewStep-1)},model.steps(definition)[Math.min(previewStep,model.steps(definition).length-1)]?.previous_label || __('Previous','leenkat-forms')),e(Button,{variant:'secondary',disabled:previewStep>=model.steps(definition).length-1,onClick:()=>setPreviewStep(previewStep+1)},model.steps(definition)[Math.min(previewStep,model.steps(definition).length-1)]?.next_label || __('Next','leenkat-forms'))),
                    e('button', { type: 'button', disabled: true, className: 'lkf-preview-submit' }, definition.settings?.general?.submit_text || __('Submit', 'leenkat-forms'))
                )
            ) : e('div', { className: 'lkf-builder-workspace' + (section === 'setup' ? ' is-setup' : section==='settings'?' is-settings':'') },
                e('nav', { className: 'lkf-rail', 'aria-label': __('Builder sections', 'leenkat-forms') },
                    [['setup', '⚙', __('Setup', 'leenkat-forms')], ['fields', '▤', __('Fields', 'leenkat-forms')], ['settings', '☷', __('Settings', 'leenkat-forms')]].map(([key, icon, label]) =>
                        e('button', { key, type: 'button', className: 'lkf-rail-button' + (section === key ? ' is-current' : ''), 'aria-current': section === key ? 'page' : undefined, onClick: () => setSection(key) }, e('span', { 'aria-hidden': 'true' }, icon), label)
                    ),
                    e('div', { className: 'lkf-rail-history' },
                        e('button', { type: 'button', onClick: undo, disabled: !history.length, 'aria-label': __('Undo', 'leenkat-forms'), title: __('Undo', 'leenkat-forms') }, '↶'),
                        e('button', { type: 'button', onClick: redo, disabled: !future.length, 'aria-label': __('Redo', 'leenkat-forms'), title: __('Redo', 'leenkat-forms') }, '↷')
                    )
                ),
                section === 'setup' ? renderSetup() : section==='settings'?e(element.Fragment,{},e('aside',{className:'lkf-drawer lkf-settings-drawer'},renderSettingsNav()),renderSettings()):e(element.Fragment, {},
                    e('aside', { className: 'lkf-drawer', 'aria-label': section === 'fields' ? __('Field palette and options', 'leenkat-forms') : __('Form settings', 'leenkat-forms') },
                        section === 'fields' && e('div', { className: 'lkf-drawer-tabs', 'aria-label': __('Field panel', 'leenkat-forms') },
                            e('button', { type: 'button', 'aria-pressed': fieldsTab === 'add', className: fieldsTab === 'add' ? 'is-active' : '', onClick: () => setFieldsTab('add') }, panelIcon(false), __('Add Fields', 'leenkat-forms')),
                            e('button', { type: 'button', 'aria-pressed': fieldsTab === 'options', className: fieldsTab === 'options' ? 'is-active' : '', onClick: () => setFieldsTab('options') }, panelIcon(true), __('Field Options', 'leenkat-forms'))
                        ),
                        section === 'fields' ? fieldsTab === 'add' ? renderPalette() : renderFieldOptions() : renderSettings()
                    ),
                    renderCanvas()
                )
            )
        );

    }
    element.render(e(App), document.getElementById('lkf-builder-root'));
})(window.wp.element, window.wp.components, window.wp.i18n);





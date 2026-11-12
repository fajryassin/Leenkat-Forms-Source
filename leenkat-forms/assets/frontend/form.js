(function () {
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;
    // Builder previews can request the same script again while replacing a widget.
    if (window.lkfForms) { window.lkfForms.init(); return; }
    function message(form,key,fallback,value){return window.lkfValidation?.text(form,key,value)||fallback;}
    function securityProof(form) {
        const input=form.querySelector('[data-lkf-security-url]');if(!input)return Promise.resolve();
        if(form._lkfSecurityProof&&Date.now()-(form._lkfSecurityLoaded || 0)<3600000)return form._lkfSecurityProof;
        form._lkfSecurityLoaded=Date.now();
        form._lkfSecurityProof=fetch(input.dataset.lkfSecurityUrl,{credentials:'same-origin',cache:'no-store'}).then(async response=>{const result=await response.json();if(!response.ok||typeof result.token!=='string'||!/^contact_[a-f0-9]{12}$/.test(result.honeypot || ''))throw new Error(result.message || __("Security verification is unavailable. Please reload the form and try again.", 'leenkat-forms'));input.value=result.token;const trap=form.querySelector('[data-lkf-security-trap]');if(!trap)throw new Error(__("Security verification is unavailable. Please reload the form.", 'leenkat-forms'));trap.name='security_traps['+result.honeypot+']';}).catch(error=>{form._lkfSecurityLoaded=0;throw error;});
        return form._lkfSecurityProof;
    }
    function submissionUuid() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
        else for (let index = 0; index < bytes.length; index++) bytes[index] = Math.floor(Math.random() * 256);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }
    function formatMask(value, mask) {
        const raw = value.replace(/[^A-Za-z0-9]/g, '');
        let position = 0;
        let formatted = '';
        for (const symbol of mask) {
            if (position >= raw.length) break;
            if ('9A*'.includes(symbol)) {
                const valid = symbol === '9' ? /[0-9]/ : symbol === 'A' ? /[A-Za-z]/ : /[A-Za-z0-9]/;
                while (position < raw.length && !valid.test(raw[position])) position++;
                if (position < raw.length) formatted += raw[position++];
            } else formatted += symbol;
        }
        return formatted;
    }
    function formValues(form) {
        const values = {};
        for (const input of form.querySelectorAll('[name^="fields["]')) {
            const match = /^fields\[([A-Za-z][A-Za-z0-9_-]{0,63})\](?:\[(first|middle|last|date|time|address1|address2|city|state|postal|country)?\])?$/.exec(input.name);
            if (!match) continue;
            if (match[2]) { const current = values[match[1]] || {}; values[match[1]] = { ...current, [match[2]]: input.value }; continue; }
            if (input.name.endsWith('[]')) { if (!Array.isArray(values[match[1]])) values[match[1]] = []; if(input.tagName==='SELECT') values[match[1]]=Array.from(input.selectedOptions,option=>option.value); else if (input.checked) values[match[1]].push(input.value); continue; }
            if (input.type === 'checkbox' || input.type === 'radio') {
                if (input.checked) values[match[1]] = input.value;
                else if (!(match[1] in values)) values[match[1]] = '';
            } else values[match[1]] = input.dataset?.lkfPhone==='smart' && input.value && input._lkfPhone ? input._lkfPhone.getNumber() : input.value;
        }
        if(form.dataset?.lkfSources)return window.lkfRules.normalizeValues(JSON.parse(form.dataset.lkfSources),values);
        for (const [id, value] of Object.entries(values)) if (value && typeof value === 'object' && !Array.isArray(value)) values[id] = [value.first || '', value.middle || '', value.last || ''].filter(Boolean).join(' ').trim();
        return values;
    }
    function applyRules(form) {
        const values = formValues(form);
        for (const wrapper of form.querySelectorAll('[data-lkf-rule]')) {
            let rule;
            try { rule = JSON.parse(wrapper.dataset.lkfRule); } catch { continue; }
            const show = window.lkfRules.visible(rule.field_id, [rule], values,form.dataset?.lkfSources?JSON.parse(form.dataset.lkfSources):null);
            wrapper.hidden = !show;
            for (const input of wrapper.querySelectorAll('input,select,textarea')) input.disabled = !show;
            for (const select of wrapper.querySelectorAll('[data-lkf-modern]')) { if (select._lkfChoices) show ? select._lkfChoices.enable() : select._lkfChoices.disable(); }
        }
        form._lkfSteps?.refresh();
    }
    function validateFields(form) {
        for(const input of form.querySelectorAll('[data-lkf-max-chars]')) {
            const value=window.lkfRules.normalizeText(input.value),characters=Number(input.dataset.lkfMaxChars),words=Number(input.dataset.lkfMaxWords || 0),bytes=Number(input.dataset.lkfMaxBytes);
            const error=!window.lkfRules.validUnicode(value)?message(form,'unicode',__("Please enter valid Unicode text.", 'leenkat-forms')):window.lkfRules.textLength(value)>characters?message(form,'characters',__("Please use no more than ", 'leenkat-forms')+characters+__(" characters.", 'leenkat-forms'),characters):words&&window.lkfRules.textLength(value,'words')>words?message(form,'words',__("Please use no more than ", 'leenkat-forms')+words+__(" words.", 'leenkat-forms'),words):new TextEncoder().encode(value).length>bytes?message(form,'bytes',__("This value exceeds the text byte-size limit.", 'leenkat-forms')):'';
            input.setCustomValidity(input.disabled?'':error);
        }
        for(const wrapper of form.querySelectorAll('[data-lkf-date-dropdown]')){
            const pieces=Object.fromEntries(Array.from(wrapper.querySelectorAll('[data-date-piece]'),select=>[select.dataset.datePiece,select.value]));
            const complete=pieces.year && pieces.month && pieces.day;
            const value=complete?[pieces.year,pieces.month.padStart(2,'0'),pieces.day.padStart(2,'0')].join('-'):'';
            wrapper.querySelector('input').value=value;
            const probe=complete?new Date(Number(pieces.year),Number(pieces.month)-1,Number(pieces.day)):null;
            const settings=JSON.parse(wrapper.dataset.pickerSettings);
            const valid=!complete || probe.getFullYear()===Number(pieces.year) && probe.getMonth()===Number(pieces.month)-1 && probe.getDate()===Number(pieces.day) && (!settings.disable_past_dates || value>=wrapper.dataset.today) && (!settings.limit_days || (settings.allowed_days || [1,2,3,4,5]).includes(probe.getDay()));
            const first=wrapper.querySelector('select');first.setCustomValidity(first.disabled || valid?'':message(form,'date',__("Please select a valid date within the allowed days.", 'leenkat-forms')));
        }
        for(const input of form.querySelectorAll('[data-lkf-upload]')) {
            const files=Array.from(input.files || []),allowed=input.accept.toLowerCase().split(',');
            const maximum=Number(input.dataset.maxFiles),size=input.dataset.maxSizeLabel||String(Number(input.dataset.maxBytes)/1048576)+' MB',extensions=allowed.map(ext=>ext.trim().replace(/^\./,'')).filter(Boolean).join(', ');
            const error=files.length>maximum?message(form,'file_count',__("You can upload a maximum of ", 'leenkat-forms')+maximum+__(" file(s).", 'leenkat-forms'),maximum):files.some(file=>file.size>Number(input.dataset.maxBytes))?message(form,'file_size',__("This file exceeds the maximum size of ", 'leenkat-forms')+size+'.',size):files.some(file=>!allowed.includes('.'+file.name.split('.').pop().toLowerCase())||/\.(php[0-9]*|pht(?:ml)?|phar|html?|shtml|cgi|pl|py|rb|aspx?|jsp?|svg|exe|bat|cmd|ps1|com|scr|msi|dll)(?:\.|$)/i.test(file.name))?message(form,'file_type',__("This file extension is not allowed. Allowed extensions: ", 'leenkat-forms')+extensions+'.',extensions):'';
            input.setCustomValidity(input.disabled?'':error);const status=input.closest('fieldset')?.querySelector('.lkf-upload-status');if(status)status.textContent=(window.lkfValidation?'':error) || files.map(file=>file.name).join('\n');
        }
        for (const group of form.querySelectorAll('[data-lkf-choice-group]')) {
            const inputs = Array.from(group.querySelectorAll('input[type=checkbox]'));
            inputs.forEach(input => input.setCustomValidity(''));
            const active = inputs.filter(input => !input.disabled);
            if (!active.length) continue;
            const selected = active.filter(input => input.checked).length;
            const limit = Number(group.dataset.choiceLimit || 0);
            active[0].setCustomValidity(group.dataset.required === '1' && !selected ? message(form,'choices',__("Please select at least one option.", 'leenkat-forms')) : limit && selected > limit ? message(form,'choice_limit',__("Please select no more than ", 'leenkat-forms') + limit + __(" options.", 'leenkat-forms'),limit) : '');
        }
        for(const input of form.querySelectorAll('[data-lkf-confirm-for]')) {
            const original=document.getElementById(input.dataset.lkfConfirmFor);
            input.setCustomValidity(input.value.trim()===(original?.value || '').trim()?'':message(form,'email_match',__("Email addresses do not match.", 'leenkat-forms')));
        }
        for(const input of form.querySelectorAll('[data-lkf-email-filter]')) {
            const filter=JSON.parse(input.dataset.lkfEmailFilter);
            const matches=(filter.patterns || []).some(pattern=>new RegExp('^'+pattern.split('*').map(part=>part.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')).join('.*')+'$','i').test(input.value.trim()));
            input.setCustomValidity(!input.value || filter.mode==='none' || (filter.mode==='allow'?matches:!matches)?'':message(form,'email_filter',__("This email address is not allowed.", 'leenkat-forms')));
        }
        for(const input of form.querySelectorAll('[data-lkf-phone]')) {
            const digits=input.value.replace(/\D/g,'');let valid=true;
            if(input.value) {
                if(input.dataset.lkfPhone==='smart') valid=input._lkfPhone?input._lkfPhone.isValidNumber():/^\+[1-9]\d{6,14}$/.test(input.value.replace(/[().\s-]/g,''));
                else if(input.dataset.lkfPhone==='us') valid=/^(?:1)?[2-9]\d{2}[2-9]\d{6}$/.test(digits);
                else valid=/^\+[1-9]\d{6,14}$/.test(input.value.replace(/[().\s-]/g,''));
            }
            input.setCustomValidity(valid?'':message(form,'phone',__("Please enter a valid phone number.", 'leenkat-forms')));
        }
    }
    function initialize(form) {
        form.noValidate=true;
        for(const input of form.querySelectorAll('[data-lkf-picker]')) if(window.flatpickr && !input._flatpickr) {
            const settings=JSON.parse(input.dataset.pickerSettings);const date=input.dataset.lkfPicker==='date';
            const options={disableMobile:true,allowInput:false,altInput:true,dateFormat:date?'Y-m-d':'H:i',altFormat:date?settings.date_format || 'm/d/Y':settings.time_format==='24'?'H:i':'h:i K',enableTime:!date,noCalendar:!date,time_24hr:settings.time_format==='24',minuteIncrement:settings.time_interval || 30,onChange:()=>{delete form.dataset.lkfSubmissionUuid;applyRules(form);validateFields(form);window.lkfValidation?.refresh(form,input);}};
            if(date && settings.disable_past_dates)options.minDate=input.dataset.today;
            const locale=window.lkfWidgetI18n?.date();if(locale)options.locale=locale;
            if(date && settings.limit_days)options.disable=[day=>!(settings.allowed_days || [1,2,3,4,5]).includes(day.getDay())];
            if(!date && settings.limit_hours){options.minTime=settings.time_min || '09:00';options.maxTime=settings.time_max || '17:00';}
            const picker=window.flatpickr(input,options);picker.altInput.setAttribute('aria-label',input.closest('label')?.querySelector('span')?.textContent || (date?__("Date", 'leenkat-forms'):__("Time", 'leenkat-forms')));picker.altInput.placeholder=settings[date?'date_placeholder':'time_placeholder'] || '';picker.altInput.required=input.required;
        }
        for(const input of form.querySelectorAll('[data-lkf-upload]')) if(!input._lkfUploadBound){
            input._lkfUploadBound=true;const drop=input.closest('.lkf-upload-drop');
            if(drop){for(const kind of ['dragenter','dragover'])drop.addEventListener(kind,event=>{event.preventDefault();drop.classList.add('is-dragover');});drop.addEventListener('dragleave',()=>drop.classList.remove('is-dragover'));drop.addEventListener('drop',event=>{event.preventDefault();drop.classList.remove('is-dragover');if(!input.disabled && event.dataTransfer?.files){input.files=event.dataTransfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));}});}
        }
        for (const output of form.querySelectorAll('[data-lkf-value-display]')) {
            const input = document.getElementById(output.getAttribute('for'));
            if (input) output.value = output.dataset.lkfValueDisplay.split('{value}').join(input.value);
        }
        for(const input of form.querySelectorAll('[data-lkf-phone="smart"]')) if(window.intlTelInput && !input._lkfPhone) {
            input._lkfPhone=window.intlTelInput(input,{initialCountry:'us',autoPlaceholder:'off',formatOnDisplay:true,...(window.lkfWidgetI18n?.phone() || {})});
            input.addEventListener('countrychange',()=>{applyRules(form);validateFields(form);window.lkfValidation?.refresh(form,input);});
        }
        for(const select of form.querySelectorAll('[data-lkf-modern]')) if(window.Choices && !select._lkfChoices) select._lkfChoices=new window.Choices(select,{allowHTML:false,shouldSort:false,removeItemButton:select.multiple,searchEnabled:true,itemSelectText:'',placeholder:true,placeholderValue:select.dataset.placeholder,...(window.lkfWidgetI18n?.choices() || {})});
        applyRules(form);validateFields(form);
        form._lkfSteps=window.lkfSteps?.mount(form,()=>{applyRules(form);validateFields(form);});
        window.lkfValidation?.hydrate(form);
    }
    document.addEventListener('input', event => {
        const form = event.target.closest?.('[data-lkf-form]');
        if (form) { if (event.target.dataset?.lkfInputMask) event.target.value = formatMask(event.target.value, event.target.dataset.lkfInputMask); if(event.target.dataset?.lkfPhone==='us') {let digits=event.target.value.replace(/\D/g,'');if(digits.length===11&&digits.startsWith('1'))digits=digits.slice(1);event.target.value=formatMask(digits,'(999) 999-9999');} delete form.dataset.lkfSubmissionUuid; if (event.target.type === 'range') { const output = event.target.parentElement?.querySelector('output'); if (output) output.value = (output.dataset.lkfValueDisplay ?? '{value}').split('{value}').join(event.target.value); } applyRules(form);validateFields(form);window.lkfValidation?.refresh(form,event.target); }
    });
    document.addEventListener('change', event => {
        const form = event.target.closest?.('[data-lkf-form]');
        if (form) { delete form.dataset.lkfSubmissionUuid; applyRules(form);validateFields(form);window.lkfValidation?.refresh(form,event.target); }
    });
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-lkf-form]')) return;
        event.preventDefault();
        if(form.getAttribute('aria-busy')==='true'||form.dataset.lkfCompleted==='1'||form.querySelector('[data-lkf-completed]'))return;
        applyRules(form);
        validateFields(form);
        if(form._lkfSteps && !form._lkfSteps.isLast()){form._lkfSteps.advance();return;}
        if(form._lkfSteps && !form._lkfSteps.validateAll())return;
        if(window.lkfValidation){if(window.lkfValidation.validate(form))return;}else if(!form.reportValidity())return;
        const box = form.querySelector('.lkf-errors');
        const button = form.querySelector('[type="submit"]');
        const originalText=button.textContent;
        const processingText=form.dataset.processingText;
        button.disabled=true;
        form.setAttribute('aria-busy','true');
        try {await securityProof(form);if(form.querySelector('[data-lkf-captcha]')){if(!window.lkfCaptcha)throw new Error(__("CAPTCHA is unavailable. Reload this page and try again.", 'leenkat-forms'));await window.lkfCaptcha.prepare(form);}}catch(error){box.textContent=error.message;box.hidden=false;box.tabIndex=-1;box.focus();button.disabled=false;form.removeAttribute('aria-busy');return;}
        // Never inherit a visitor identity from shared HTML. Keep this identity
        // for unchanged retries, including native POST and bfcache restoration.
        if (!form.dataset.lkfSubmissionUuid) form.dataset.lkfSubmissionUuid = submissionUuid();
        const uuidInput=form.querySelector('input[name="submission_uuid"]');
        if(uuidInput)uuidInput.value=form.dataset.lkfSubmissionUuid;
        const data = new FormData(form);
        const fields = {};
        const uploads = new FormData();let hasUploads=false;
        const captchaTokens = {};
        const emailConfirmations = {};
        for (const [name, value] of data) {
            if(name.startsWith('upload_') && value instanceof File){if(value.size || value.name){uploads.append(name,value);hasUploads=true;}continue;}
            const match = /^fields\[([A-Za-z][A-Za-z0-9_-]{0,63})\](?:\[(first|middle|last|date|time|address1|address2|city|state|postal|country)?\])?$/.exec(name);
            if (match?.[2]) { if (!fields[match[1]]) fields[match[1]] = {}; fields[match[1]][match[2]] = value; }
            else if (match && name.endsWith('[]')) { if (!fields[match[1]]) fields[match[1]] = []; fields[match[1]].push(value); }
            else if (match) fields[match[1]] = value;
            const captcha = /^captcha_tokens\[([A-Za-z][A-Za-z0-9_-]{0,63})\]$/.exec(name);
            if (captcha) captchaTokens[captcha[1]] = value;
            const confirmation=/^email_confirmations\[([A-Za-z][A-Za-z0-9_-]{0,63})\]$/.exec(name);
            if(confirmation)emailConfirmations[confirmation[1]]=value;
        }
        for(const input of form.querySelectorAll('[data-lkf-phone="smart"]')) if(!input.disabled && input.value && input._lkfPhone) {const id=/^fields\[([^\]]+)\]$/.exec(input.name)?.[1];if(id)fields[id]=input._lkfPhone.getNumber();}
        if(form.dataset.lkfAjax==='0') {
            for(const input of form.querySelectorAll('[data-lkf-phone="smart"]'))if(!input.disabled && input.value && input._lkfPhone)input.value=input._lkfPhone.getNumber();
            button.dataset.lkfOriginalText=originalText;
            if(processingText)button.textContent=processingText;
            button.disabled=true;form.setAttribute('aria-busy','true');HTMLFormElement.prototype.submit.call(form);return;
        }
        if(processingText)button.textContent=processingText;
        form.setAttribute('aria-busy','true');
        button.disabled = true;
        box.hidden = true;
        box.tabIndex = -1;
        try {
            if (!form.dataset.lkfSubmissionUuid) form.dataset.lkfSubmissionUuid = submissionUuid();
            const traps={};for(const [name,value] of data){const match=/^security_traps\[(contact_[a-f0-9]{12})\]$/.exec(name);if(match)traps[match[1]]=value;}
            const payload=JSON.stringify({ submission_uuid: form.dataset.lkfSubmissionUuid, fields, email_confirmations:emailConfirmations, captcha_tokens: captchaTokens, website_confirmation: data.get('website_confirmation') || '',security_token:data.get('security_token') || '',security_traps:traps,service_captcha_token:data.get('service_captcha_token') || '' });
            if(hasUploads)uploads.append('payload',payload);
            const response = await fetch(form.dataset.lkfEndpoint || form.action, {method:'POST',...(hasUploads?{}:{headers:{'Content-Type':'application/json'}}),body:hasUploads?uploads:payload});
            const result = await response.json();
            if (!response.ok){const failure=new Error(result.message || __("Submission failed.", 'leenkat-forms'));failure.fieldErrors=result.data?.field_errors;throw failure;}
            window.lkfConfirmation.show(form,result);
        } catch (error) {
            if(!error.fieldErrors||!window.lkfValidation?.server(form,error.fieldErrors)){
                box.textContent = error.message || __("Submission failed. Please try again.", 'leenkat-forms');
                box.hidden = false; box.focus();
            }
        } finally { window.lkfCaptcha?.reset(form);button.disabled = form.dataset.lkfCompleted==='1';button.textContent=originalText;form.removeAttribute('aria-busy'); }
    });
    const initialized = new WeakSet();
    function init(root = document) {
        const forms = [...(root.matches?.('[data-lkf-form]') ? [root] : []), ...root.querySelectorAll('[data-lkf-form]')];
        for (const form of forms) {
            if (initialized.has(form)) continue;
            initialized.add(form);
            initialize(form);
            securityProof(form).catch(()=>{});
            window.lkfCaptcha?.init(form);
        }
    }
    window.lkfForms = { init };
    function start() {
        init();
        new MutationObserver(records => {
            for (const record of records) for (const node of record.addedNodes) {
                if (node.nodeType === 1) init(node);
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
    window.addEventListener('pageshow',()=>{for(const form of document.querySelectorAll('[data-lkf-form]')){const button=form.querySelector('[type="submit"]');if(button?.dataset.lkfOriginalText){button.textContent=button.dataset.lkfOriginalText;button.disabled=false;form.removeAttribute('aria-busy');delete button.dataset.lkfOriginalText;}}});
})();

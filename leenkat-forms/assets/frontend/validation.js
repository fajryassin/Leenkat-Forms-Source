(function(){
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;
    if(window.leenfoValidation)return;
    const states=new WeakMap();
    const defaults={required:__("This field is required.", 'leenkat-forms'),invalid:__("Please enter a valid value.", 'leenkat-forms'),email:__("Please enter a valid email address.", 'leenkat-forms'),phone:__("Please enter a valid phone number.", 'leenkat-forms'),url:__("Please enter a valid URL.", 'leenkat-forms'),number:__("Please enter a valid number.", 'leenkat-forms'),choice:__("Please select an option.", 'leenkat-forms'),checkbox:__("Please check this box.", 'leenkat-forms'),choices:__("Please select at least one option.", 'leenkat-forms'),choice_limit:__("Please select no more than %d options.", 'leenkat-forms'),pattern:__("Please enter a value in the requested format.", 'leenkat-forms'),min:__("Please enter a value greater than or equal to %s.", 'leenkat-forms'),max:__("Please enter a value less than or equal to %s.", 'leenkat-forms'),unicode:__("Please enter valid Unicode text.", 'leenkat-forms'),characters:__("Please use no more than %d characters.", 'leenkat-forms'),words:__("Please use no more than %d words.", 'leenkat-forms'),bytes:__("This value exceeds the text byte-size limit.", 'leenkat-forms'),date:__("Please select a valid date within the allowed days.", 'leenkat-forms'),file_count:__("You can upload a maximum of %d file(s).", 'leenkat-forms'),file_size:__("This file exceeds the maximum size of %s.", 'leenkat-forms'),file_type:__("This file extension is not allowed. Allowed extensions: %s.", 'leenkat-forms'),email_match:__("Email addresses do not match.", 'leenkat-forms'),email_filter:__("This email address is not allowed.", 'leenkat-forms'),summary:__("Please correct the highlighted fields.", 'leenkat-forms')};
    function json(value){try{return JSON.parse(value||'{}');}catch{return {};}}
    function text(form,key,value){const messages=json(form?.dataset?.lkfValidationMessages);return String(messages[key]||defaults[key]||defaults.invalid).replace(/%(?:1\$)?[ds]/,String(value??''));}
    function state(field){if(!states.has(field))states.set(field,{touched:false,server:{}});return states.get(field);}
    function fields(root){return root.matches?.('.lkf-field[data-lkf-field-id]')?[root]:[...root.querySelectorAll('.lkf-field[data-lkf-field-id]')];}
    function picker(input){const original=input.previousElementSibling;return original?._flatpickr?.altInput===input?original:null;}
    function controls(field){return [...field.querySelectorAll('input,select,textarea')].filter(input=>!input.disabled&&input.type!=='hidden'&&(input.willValidate||picker(input)));}
    function part(input){
        if(input.dataset.datePiece)return 'date_'+input.dataset.datePiece;
        if(input.name?.startsWith('email_confirmations['))return 'confirm';
        if(input.type==='email'&&input.closest('.lkf-field')?.querySelector('[data-lkf-confirm-for]'))return 'email';
        const name=input.name||input.previousElementSibling?.name||'';
        return /^fields\[[^\]]+\]\[([^\]]+)\]$/.exec(name)?.[1]||'';
    }
    function error(form,input){
        if(picker(input)&&input.required&&!input.value.trim())return text(form,'required');
        const v=input.validity;if(!v||v.valid)return '';
        if(v.valueMissing)return text(form,input.type==='radio'||input.tagName==='SELECT'?'choice':input.type==='checkbox'?'checkbox':'required');
        if(v.customError)return input.validationMessage||text(form,'invalid');
        if(v.typeMismatch)return text(form,input.type==='email'?'email':input.type==='url'?'url':'invalid');
        if(v.patternMismatch)return text(form,'pattern');
        if(v.badInput)return text(form,'number');
        if(v.rangeUnderflow)return text(form,'min',input.min);
        if(v.rangeOverflow)return text(form,'max',input.max);
        return text(form,'invalid');
    }
    function target(input){
        if(input._flatpickr?.altInput)return input._flatpickr.altInput;
        const field=input.closest('.lkf-field');
        const proxy=input._lkfChoices?(field.querySelector('.choices[tabindex]')||field.querySelector('.choices [role="combobox"]')||field.querySelector('.choices input')||input):input;
        if(proxy!==input&&!proxy.getAttribute('aria-label')&&!proxy.getAttribute('aria-labelledby')){const label=input.labels?.[0];if(label){if(!label.id)label.id=input.id+'-label';proxy.setAttribute('aria-labelledby',label.id);}}
        return proxy;
    }
    function focus(input){if(input){const actual=target(input);if(document.activeElement!==actual)actual.focus();}}
    function clearControl(input,prefix){
        const tokens=(input.getAttribute('aria-describedby')||'').split(/\s+/).filter(token=>token&&!token.startsWith(prefix+'-error-'));
        if(tokens.length)input.setAttribute('aria-describedby',tokens.join(' '));else input.removeAttribute('aria-describedby');
        if((input.getAttribute('aria-errormessage')||'').startsWith(prefix+'-error-'))input.removeAttribute('aria-errormessage');
        if(input.hasAttribute('data-lkf-invalid')){input.removeAttribute('aria-invalid');input.removeAttribute('data-lkf-invalid');}
    }
    function invalidControl(input,id){
        const described=(input.getAttribute('aria-describedby')||'').split(/\s+/).filter(Boolean);if(!described.includes(id))described.push(id);
        input.setAttribute('aria-describedby',described.join(' '));input.setAttribute('aria-invalid','true');input.setAttribute('aria-errormessage',id);input.setAttribute('data-lkf-invalid','');
    }
    function render(form,field){
        const current=state(field),inputs=controls(field),serverInputs=[...field.querySelectorAll('input,select,textarea')].filter(input=>!input.disabled&&!['hidden','button','submit'].includes(input.type)),prefix=field.dataset.lkfErrorPrefix||inputs[0]?.id||serverInputs[0]?.id;
        if(!prefix)return null;
        field.querySelector('[data-lkf-field-errors]')?.remove();
        for(const input of field.querySelectorAll('input,select,textarea,[data-lkf-invalid]')){clearControl(input,prefix);const proxy=target(input);if(proxy!==input)clearControl(proxy,prefix);}
        field.classList.remove('lkf-has-error');
        if(field.hidden){current.server={};return null;}
        const errors=new Map();
        function add(key,message,list){if(!message)return;if(!errors.has(key))errors.set(key,{message,inputs:[]});for(const input of list)if(!errors.get(key).inputs.includes(input))errors.get(key).inputs.push(input);}
        if(current.touched)for(const input of inputs){const key=input.dataset.datePiece&&input.validity.customError?'date':part(input);if(!current.server[key]&&!current.server['']&&!(key.startsWith('date_')&&current.server.date))add(key,error(form,input),[input]);}
        for(const [key,message] of Object.entries(current.server)){
            const applicable=serverInputs.filter(input=>key===''||part(input)===key||key==='date'&&part(input).startsWith('date_'));
            add(key,message,applicable.length?applicable:serverInputs.slice(0,1));
        }
        if(!errors.size)return null;
        const container=document.createElement('div');container.className='lkf-field-errors';container.dataset.lkfFieldErrors='';
        const labels=json(field.dataset.lkfPartLabels);let first=null;
        for(const [key,entry] of errors){
            const id=prefix+'-error-'+(key||'value'),message=document.createElement('p');message.className='lkf-field-error';message.id=id;message.textContent=(labels[key]?labels[key]+': ':'')+entry.message;container.append(message);
            for(const input of entry.inputs){invalidControl(input,id);const proxy=target(input);if(proxy!==input)invalidControl(proxy,id);if(!first)first=input;}
        }
        field.append(container);field.classList.add('lkf-has-error');return first;
    }
    function summary(form){
        const box=form.querySelector('.lkf-errors');if(!box)return;
        const hasErrors=!!form.querySelector('[data-lkf-field-errors]');
        if(hasErrors){const message=text(form,'summary');if(box.textContent!==message)box.textContent=message;box.hidden=false;box.dataset.lkfValidationSummary='1';}
        else if(box.dataset.lkfValidationSummary==='1'){box.textContent='';box.hidden=true;delete box.dataset.lkfValidationSummary;}
    }
    function clearHidden(form){for(const field of fields(form))if(field.hidden&&field.querySelector('[data-lkf-field-errors]'))render(form,field);}
    function validate(form,root=form,moveFocus=true){
        clearHidden(form);let first=null;
        for(const field of fields(root)){if(field.hidden)continue;state(field).touched=true;const invalid=render(form,field);if(!first&&invalid)first=invalid;}
        summary(form);if(first&&moveFocus)focus(first);return first;
    }
    function refresh(form,input){
        clearHidden(form);const field=input?.closest?.('.lkf-field[data-lkf-field-id]');
        if(field){const current=state(field),key=part(input);if(input.type==='file')current.touched=true;if(key in current.server)delete current.server[key];if(key.startsWith('date_'))delete current.server.date;if('' in current.server)delete current.server[''];if(current.touched)render(form,field);}
        summary(form);
    }
    function server(form,map,moveFocus=true){
        let first=null;
        for(const field of fields(form)){
            const incoming=map?.[field.dataset.lkfFieldId];if(!incoming||typeof incoming!=='object'||Array.isArray(incoming))continue;
            const current=state(field);current.server={};current.touched=true;
            for(const [key,message] of Object.entries(incoming))if(/^(?:[a-z][a-z0-9_]{0,31})?$/.test(key)&&typeof message==='string')current.server[key]=message.slice(0,500);
            const invalid=render(form,field);if(!first&&invalid)first=invalid;
        }
        summary(form);if(first&&moveFocus){if(form._leenfoSteps)form._leenfoSteps.reveal(first);else focus(first);}return first;
    }
    function hydrate(form){
        for(const field of fields(form))for(const input of field.querySelectorAll('[data-lkf-modern]'))if(input._lkfChoices)target(input);
        const map={};for(const field of fields(form))if(field.dataset.lkfServerErrors)map[field.dataset.lkfFieldId]=json(field.dataset.lkfServerErrors);
        if(Object.keys(map).length)server(form,map);
    }
    window.leenfoValidation={text,validate,refresh,server,hydrate,focus};
})();


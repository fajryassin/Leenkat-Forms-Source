(function (root, factory) {
    'use strict';
    const api=factory();
    if(typeof module==='object' && module.exports)module.exports=api;
    if(root)root.lkfSteps=api;
})(typeof window==='undefined'?null:window,function(){
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;
    function applicable(panels) {
        return panels.filter(panel=>Array.from(panel.querySelectorAll('.lkf-field')).some(field=>!field.hidden && Array.from(field.querySelectorAll('input,select,textarea')).some(input=>!input.disabled && input.type!=='hidden')));
    }
    function mount(form,validate) {
        const panels=Array.from(form.querySelectorAll('[data-lkf-step]'));
        if(panels.length<2)return null;
        const nav=form.querySelector('[data-lkf-step-nav]'),previous=nav.querySelector('[data-lkf-previous]'),next=nav.querySelector('[data-lkf-next]'),submit=form.querySelector('[type="submit"]'),status=form.querySelector('[data-lkf-step-status]'),progress=form.querySelector('[data-lkf-progress]'),list=form.querySelector('[data-lkf-step-list]'),captcha=form.querySelector('.lkf-service-captcha');
        const nextDefault=next.textContent || __("Next", 'leenkat-forms'),previousDefault=previous.textContent || __("Previous", 'leenkat-forms');
        let active=panels[0],available=[];
        form.noValidate=true;
        nav.hidden=false;
        function focusHeading(){const heading=active?.querySelector('[data-lkf-step-heading]');heading?.focus();}
        function refresh(focus=false) {
            available=applicable(panels);
            if(!available.includes(active))active=available.find(panel=>panels.indexOf(panel)>=panels.indexOf(active)) || available[available.length-1] || null;
            const index=available.indexOf(active),last=index===available.length-1;
            for(const panel of panels)panel.hidden=panel!==active;
            next.textContent=active?.dataset?.nextLabel || nextDefault;previous.textContent=active?.dataset?.previousLabel || previousDefault;
            previous.hidden=index<=0;next.hidden=last;submit.hidden=!last;
            if(captcha)captcha.hidden=!last;
            const title=active?.querySelector('[data-lkf-step-heading]')?.textContent || '';
            const announcement=available.length ? status.dataset.pattern.replace('%1$d',String(index+1)).replace('%2$d',String(available.length))+' — '+title : status.dataset.empty;
            if(status.textContent!==announcement)status.textContent=announcement;
            if(progress){progress.hidden=!available.length;progress.max=available.length || 1;progress.value=index+1;progress.setAttribute('aria-valuetext',status.textContent);}
            if(list)for(const [i,item] of Array.from(list.children).entries()){const position=available.indexOf(panels[i]);item.hidden=position<0;if(panels[i]===active)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');}
            if(focus)focusHeading();
        }
        function firstInvalid(root){return Array.from(root.querySelectorAll('input,select,textarea')).find(input=>!input.disabled && input.willValidate && !input.checkValidity());}
        const errors=typeof window==='undefined'?null:window.leenfoValidation;
        function report(input){if(!input)return true;const panel=input.closest('[data-lkf-step]');if(panel){active=panel;refresh();}if(errors)errors.focus(input);else{input.focus();input.reportValidity();}return false;}
        function advance(){validate(form);const invalid=active && (errors?errors.validate(form,active,false):firstInvalid(active));if(invalid)return report(invalid);const index=available.indexOf(active);if(index<available.length-1){active=available[index+1];refresh(true);}return true;}
        next.addEventListener('click',advance);
        previous.addEventListener('click',()=>{const index=available.indexOf(active);if(index>0){active=available[index-1];refresh(true);}});
        // Enter on a text control advances instead of bypassing an intermediate step.
        form.addEventListener('keydown',event=>{if(event.key==='Enter' && !event.isComposing && event.target.tagName==='INPUT' && !['button','submit','checkbox','radio','file'].includes(event.target.type) && !next.hidden){event.preventDefault();advance();}});
        refresh();
        return {refresh,reveal:report,validateAll(){validate(form);return report(errors?errors.validate(form,form,false):firstInvalid(form));},advance,isLast(){return available.indexOf(active)===available.length-1;}};
    }
    return {applicable,mount};
});

(function(){
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;
    document.addEventListener('DOMContentLoaded',()=>{
        const form=document.querySelector('[data-lkf-settings]');if(!form)return;
        const preview=form.querySelector('[data-lkf-saved-preview]'),saveHint=form.querySelector('[data-lkf-preview-save]');let dirty=false;
        function update(){const provider=form.querySelector('[name=provider]:checked')?.value;for(const panel of form.querySelectorAll('[data-lkf-provider]'))panel.hidden=panel.dataset.lkfProvider!==provider;form.querySelector('[data-lkf-service-options]').hidden=provider==='none';const score=form.querySelector('[data-lkf-score]');score.hidden=form.querySelector('[name="providers[recaptcha][type]"]:checked')?.value!=='v3';const conflict=form.querySelector('[name=no_conflict]');form.querySelector('[data-lkf-switch-caption]').textContent=conflict.checked?__("ON", 'leenkat-forms'):__("OFF", 'leenkat-forms');preview.hidden=dirty||preview.dataset.provider!==provider;saveHint.hidden=!preview.hidden;}
        form.addEventListener('input',event=>{if(!event.target.closest('[data-lkf-saved-preview]')){dirty=true;update();}});form.addEventListener('change',update);update();
        form.querySelector('[data-lkf-test-captcha]')?.addEventListener('click',async event=>{
            const button=event.currentTarget,result=form.querySelector('[data-lkf-preview-result]');button.disabled=true;result.textContent='';
            try{if(!window.lkfCaptcha)throw new Error(__("CAPTCHA is unavailable. Reload this page and try again.", 'leenkat-forms'));const token=await window.lkfCaptcha.prepare(preview);const response=await fetch(button.dataset.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':button.dataset.nonce},body:JSON.stringify({token})});const data=await response.json();if(!response.ok)throw new Error(data.message || __("Verification failed.", 'leenkat-forms'));result.textContent=data.message;result.dataset.error='false';}
            catch(error){result.textContent=error.message;result.dataset.error='true';}finally{window.lkfCaptcha?.reset(preview);button.disabled=false;}
        });
    });
})();

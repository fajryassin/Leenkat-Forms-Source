(function(){
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;
    document.addEventListener('DOMContentLoaded',()=>{
        const form=document.querySelector('[data-lkf-site-settings]');if(!form)return;
        const section=form.dataset.lkfSiteSettings,config=window.leenfoSiteSettings || {},dialog=document.querySelector('[data-lkf-email-dialog]');let previews=config.previews || {},dirty=false,busy=false,timer=null,sequence=0,controller=null,showFormattingError=false;
        const selectedTemplate=()=>form.querySelector('[name="email[template]"]:checked')?.value || 'default';
        const appearance=()=>form.querySelector('[name=appearance]:checked')?.value || 'light';
        const status=text=>{const el=form.querySelector('[data-lkf-preview-status]');if(el)el.textContent=text;};
        function update(){
            for(const label of form.querySelectorAll('.lkf-settings-switch'))label.querySelector('[data-lkf-switch-caption]').textContent=label.querySelector('input').checked?__("ON", 'leenkat-forms'):__("OFF", 'leenkat-forms');
            if(section==='general'){const gdpr=form.querySelector('[name="general[gdpr_enhancements]"]'),options=form.querySelector('[data-lkf-gdpr-options]');if(gdpr&&options){options.hidden=!gdpr.checked;gdpr.setAttribute('aria-expanded',String(gdpr.checked));}}
            if(section==='general'){const control=form.querySelector('[name="general[delete_all_data_on_uninstall]"]'),warning=form.querySelector('[data-lkf-uninstall-warning]');if(control&&warning){warning.hidden=!control.checked;control.setAttribute('aria-expanded',String(control.checked));}}
            if(section!=='email')return;const plain=selectedTemplate()==='plain';form.querySelector('[data-lkf-formatting]').hidden=plain&&!showFormattingError;form.querySelector('[data-lkf-plain-note]').hidden=!plain;
            for(const panel of form.querySelectorAll('[data-lkf-appearance]'))panel.hidden=panel.dataset.lkfAppearance!==appearance();
            for(const control of form.querySelectorAll('[data-lkf-image-control]')){const hasImage=Number(control.querySelector('input').value)>0,preview=control.querySelector('[data-lkf-image-preview]'),size=control.querySelector('[data-lkf-image-size]');preview.hidden=!hasImage;preview.dataset.size=size.value;size.hidden=!hasImage;control.querySelector('[data-lkf-choose-image]').hidden=hasImage;control.querySelector('[data-lkf-remove-image]').hidden=!hasImage;}
        }
        function showNotice(text,error=false){const host=document.querySelector('[data-lkf-save-notice]'),notice=document.createElement('div'),p=document.createElement('p');notice.className='notice '+(error?'notice-error':'notice-success');p.textContent=text;notice.append(p);host.replaceChildren(notice);}
        function emailValues(){const get=name=>form.elements.namedItem(name)?.value;const value={template:selectedTemplate(),optimize_sending:!!form.querySelector('[name="email[optimize_sending]"]').checked,carbon_copy:!!form.querySelector('[name="email[carbon_copy]"]').checked};for(const mode of ['light','dark']){value[mode]={header_image:Number(get('email['+mode+'][header_image]')),header_image_size:get('email['+mode+'][header_image_size]'),typography:get('email['+mode+'][typography]')};for(const key of ['background','body','text','links'])value[mode][key]=get('email['+mode+']['+key+']');}return value;}
        function fitThumbnails(){for(const frame of form.querySelectorAll('[data-lkf-template]')){const wrap=frame.parentElement,scale=wrap.clientWidth/640;if(scale<=0)continue;frame.style.height=Math.ceil(wrap.clientHeight/scale)+'px';frame.style.transform='scale('+scale+')';}}
        function thumbnail(document){return document.replace('</head>','<style>html,body{height:100%;min-height:100%;overflow:hidden}body>table{height:100%}body>table>tbody>tr>td{padding:0!important}.lkf-email-panel{width:100%!important;max-width:none!important;height:100%;margin:0!important;border:0!important}.lkf-email-content{vertical-align:top}.lkf-email-body>pre{margin:0;padding:0}</style></head>');}
        function paint(){for(const frame of form.querySelectorAll('[data-lkf-template]')){const preview=previews[frame.dataset.lkfTemplate];if(preview)frame.srcdoc=thumbnail(preview.document);}fitThumbnails();const large=dialog?.querySelector('iframe'),preview=previews[selectedTemplate()];if(large&&preview)large.srcdoc=preview.document;}
        async function refreshPreview(){
            if(section!=='email'||!form.checkValidity())return;const current=++sequence;controller?.abort();controller=new AbortController();status(__("Updating preview…", 'leenkat-forms'));
            try{const response=await fetch(config.previewApi,{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce},body:JSON.stringify({email:emailValues(),mode:appearance()})});const result=await response.json();if(current!==sequence)return;if(!response.ok)throw new Error(result.message || __("Preview is unavailable.", 'leenkat-forms'));previews=result.templates;paint();status(__("Preview updated. Sample content only; no email is sent.", 'leenkat-forms'));}
            catch(error){if(error.name!=='AbortError'&&current===sequence)status(error.message);}
        }
        function changed(event){const input=event.target;
            if(input.matches('[data-lkf-color-swatch]'))input.closest('.lkf-email-color-input').querySelector('[data-lkf-color-value]').value=input.value;
            if(input.matches('[data-lkf-color-value]')&&/^#[a-f0-9]{6}$/i.test(input.value))input.closest('.lkf-email-color-input').querySelector('[data-lkf-color-swatch]').value=input.value;
            if(input.name!=='appearance')dirty=true;if(form.checkValidity())showFormattingError=false;update();clearTimeout(timer);if(section==='email')timer=setTimeout(refreshPreview,350);
        }
        form.addEventListener('input',changed);form.addEventListener('change',event=>{if(['radio','checkbox'].includes(event.target.type)||event.target.tagName==='SELECT')changed(event);});
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(busy)return;if(!form.checkValidity()){const invalid=form.querySelector(':invalid'),panel=invalid?.closest('[data-lkf-appearance]');if(panel){form.querySelector('[name=appearance][value='+panel.dataset.lkfAppearance+']').checked=true;showFormattingError=true;update();}form.reportValidity();return;}
            busy=true;const button=form.querySelector('[type=submit]'),progress=form.querySelector('[data-lkf-save-progress]');button.disabled=true;progress.textContent=__("Saving…", 'leenkat-forms');
            try{const body=new FormData(form);body.set('leenfo_json','1');const response=await fetch(form.getAttribute('action'),{method:'POST',credentials:'same-origin',body});let data;try{data=await response.json();}catch{throw new Error(__("Settings could not be saved. Reload this page if your session has expired.", 'leenkat-forms'));}if(!response.ok)throw new Error(data.message || __("Settings could not be saved.", 'leenkat-forms'));form.elements.namedItem('revision').value=data.revision;dirty=false;showNotice(data.message);if(section==='email')await refreshPreview();}
            catch(error){showNotice(error.message,true);}finally{busy=false;button.disabled=false;progress.textContent='';}
        });
        window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
        if(section==='email'){
            form.querySelector('[data-lkf-open-preview]').addEventListener('click',()=>{dialog.showModal();paint();});dialog.querySelector('[data-lkf-close-preview]').addEventListener('click',()=>dialog.close());dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientY<r.top||event.clientX>r.right||event.clientY>r.bottom)dialog.close();}});dialog.addEventListener('close',()=>form.querySelector('[data-lkf-open-preview]').focus());
            for(const control of form.querySelectorAll('[data-lkf-image-control]')){
                control.querySelector('[data-lkf-choose-image]').addEventListener('click',()=>{
                    if(!window.wp?.media){showNotice(__("The Media Library is unavailable. Reload this page and try again.", 'leenkat-forms'),true);return;}
                    const frame=window.wp.media({title:__("Choose an email header image", 'leenkat-forms'),button:{text:__("Use image", 'leenkat-forms')},library:{type:'image'},multiple:false});frame.on('select',()=>{const image=frame.state().get('selection').first().toJSON();if(!['image/jpeg','image/png','image/gif','image/webp'].includes(image.mime)||!['http:','https:'].includes(new URL(image.url,location.href).protocol)){showNotice(__("Choose a PNG, JPEG, GIF or WebP image.", 'leenkat-forms'),true);return;}control.querySelector('input').value=String(image.id);control.querySelector('img').src=image.url;dirty=true;update();refreshPreview();});frame.open();
                });
                control.querySelector('[data-lkf-remove-image]').addEventListener('click',()=>{control.querySelector('input').value='0';control.querySelector('img').removeAttribute('src');dirty=true;update();refreshPreview();});
            }
            paint();
            if(window.ResizeObserver){const observer=new ResizeObserver(fitThumbnails);for(const wrap of form.querySelectorAll('.lkf-email-mini-wrap'))observer.observe(wrap);}else window.addEventListener('resize',fitThumbnails);
        }
        update();
    });
})();

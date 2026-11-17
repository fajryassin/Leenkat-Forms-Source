(function(element,components,i18n){
    'use strict';
    const e=element.createElement,{Button,ToggleControl}=components,__=i18n.__;
    function state(definition,provider={}){
        const saved=definition.settings?.security?.captcha_enabled===true,ready=provider.ready===true;
        return {saved,ready,enabled:saved&&ready,legacy:(definition.fields||[]).filter(field=>field.type==='captcha')};
    }
    function Editor({definition,config={},onChange,onSelectField,embedded=false}){
        const provider=config.captcha||{provider:'none',label:'CAPTCHA',ready:false},s=state(definition,provider);
        const title=provider.label||'CAPTCHA',icon={recaptcha:'recaptcha.svg',hcaptcha:'hcaptcha.svg',turnstile:'cloudflare.svg'}[provider.provider];
        return e(embedded?'section':'div',{className:embedded?'lkf-security-section lkf-captcha-editor lkf-captcha-inline':'lkf-captcha-editor',...(embedded?{id:'lkf-captcha-settings',tabIndex:-1,'aria-labelledby':'lkf-captcha-settings-title'}:{})},
            e(embedded?'h3':'h2',{className:embedded?'lkf-captcha-section-heading':'lkf-settings-heading',...(embedded?{id:'lkf-captcha-settings-title'}:{})},__('CAPTCHA','leenkat-forms')),
            e('p',{className:'lkf-security-description'},__('Configure your provider and keys in the global CAPTCHA settings, then choose whether this form requires verification.','leenkat-forms')),
            e('section',{className:'lkf-captcha-activation','aria-label':__('Form CAPTCHA activation','leenkat-forms')},
                e('div',{className:'lkf-captcha-provider'},icon&&e('img',{src:(config.securityIconsUrl||'')+icon,width:60,height:60,alt:'','aria-hidden':true}),e(embedded?'h4':'h3',{},provider.provider==='none'?__('No CAPTCHA provider configured','leenkat-forms'):title)),
                e(ToggleControl,{label:__('Enable ','leenkat-forms')+(provider.provider==='none'?'CAPTCHA':title),checked:s.enabled,disabled:!s.ready||s.legacy.length>0&&!s.saved,onChange:value=>{if(!value||s.ready&&!s.legacy.length)onChange(value);},'aria-describedby':'lkf-captcha-activation-note'}),
                e('p',{id:'lkf-captcha-activation-note',className:'lkf-security-note'},__('This switch controls this form only. It does not change global keys or confirm that the provider will accept them. Verification is enforced by the server on submission.','leenkat-forms')),
                !s.ready&&e('div',{className:'lkf-captcha-notice',role:'status','aria-live':'polite'},e('p',{},provider.unavailable?__('CAPTCHA setup status could not be refreshed. Retry or reload the builder.','leenkat-forms'):__('Choose a provider and save valid site and secret keys in Leenkat Forms → Settings → CAPTCHA before enabling it here.','leenkat-forms')),s.saved&&e('div',{},e('p',{},__('This form still has CAPTCHA requested in its saved settings, but setup is unavailable. Submissions remain protected and will be rejected until setup is restored or you explicitly turn it off.','leenkat-forms')),e(Button,{variant:'secondary',onClick:()=>onChange(false)},__('Turn off saved CAPTCHA setting','leenkat-forms')))),
                !!s.legacy.length&&e('div',{className:'lkf-captcha-notice',role:'note'},e('p',{},__('This form contains an existing Custom CAPTCHA field. Its definition and validation are preserved. Remove it before enabling an additional provider challenge.','leenkat-forms')),s.legacy.map(field=>e(Button,{key:field.id,variant:'secondary',onClick:()=>onSelectField?.(field.id)},__('Edit Custom CAPTCHA: ','leenkat-forms')+field.label))),
                e('div',{className:'lkf-captcha-actions'},e('a',{className:'button',href:config.captchaSettingsUrl,target:'_blank',rel:'noopener noreferrer'},__('Open global CAPTCHA settings →','leenkat-forms')),e(Button,{variant:'secondary',onClick:config.refreshCaptcha},__('Refresh setup status','leenkat-forms')))
            ),
            e('section',{className:'lkf-security-section'},e(embedded?'h4':'h3',{},__('Available CAPTCHA providers','leenkat-forms')),e('p',{className:'lkf-security-note'},__('Provider selection and credentials are managed globally. Opening these settings does not activate CAPTCHA on any form.','leenkat-forms')),
                e('div',{className:'lkf-security-cards'},[
                    ['recaptcha','reCAPTCHA','recaptcha.svg',__("Google Checkbox v2, Invisible v2 or v3 verification.", 'leenkat-forms')],
                    ['hcaptcha','hCaptcha','hcaptcha.svg',__("Privacy-oriented visual CAPTCHA verification.", 'leenkat-forms')],
                    ['turnstile','Cloudflare Turnstile','cloudflare.svg',__("Visitor verification with Cloudflare Turnstile.", 'leenkat-forms')]
                ].map(([key,label,logo,description])=>e('article',{key,className:'lkf-security-card'},e('img',{className:'lkf-security-card-logo',src:(config.securityIconsUrl||'')+logo,width:70,height:70,alt:'','aria-hidden':true}),e(embedded?'h5':'h4',{},label),e('p',{},__(description,'leenkat-forms')),e('a',{href:config.captchaSettingsUrl+'&provider='+key,target:'_blank',rel:'noopener noreferrer'},__('Global settings →','leenkat-forms'))))))
        );
    }
    window.leenfoCaptchaSettings={state};window.leenfoCaptchaSettingsEditor=Editor;
})(window.wp.element,window.wp.components,window.wp.i18n);

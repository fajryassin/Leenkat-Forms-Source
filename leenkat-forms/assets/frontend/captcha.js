(function(){
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;
    const scripts=new Map(),states=new WeakMap();
    const names={recaptcha:'grecaptcha',hcaptcha:'hcaptcha',turnstile:'turnstile'};
    function sdk(config){
        const provider=config.provider,name=names[provider];if(!name)return Promise.reject(new Error(config.message));
        if(window[name]?.render&&window[name]?.getResponse)return Promise.resolve(window[name]);
        if(scripts.has(provider))return scripts.get(provider);
        const promise=new Promise((resolve,reject)=>{
            const callback='leenfoCaptchaLoaded_'+provider,script=document.createElement('script');let finished=false;
            const finish=error=>{if(finished)return;finished=true;clearTimeout(timer);if(error){script.remove();reject(error);}else if(window[name])resolve(window[name]);else reject(new Error(config.message));};
            window[callback]=()=>finish();
            const timer=setTimeout(()=>finish(new Error(config.message)),20000);
            const query=new URLSearchParams({onload:callback,render:provider==='recaptcha'&&config.type==='v3'?config.siteKey:'explicit'});
            if(provider==='hcaptcha')query.set('recaptchacompat','off');
            const base={recaptcha:'https://www.google.com/recaptcha/api.js',hcaptcha:'https://js.hcaptcha.com/1/api.js',turnstile:'https://challenges.cloudflare.com/turnstile/v0/api.js'}[provider];
            script.src=base+'?'+query.toString();script.async=true;script.defer=true;script.dataset.lkfCaptchaSdk=provider;script.onerror=()=>finish(new Error(config.message));document.head.append(script);
        }).catch(error=>{scripts.delete(provider);throw error;});scripts.set(provider,promise);return promise;
    }
    function ensure(container){
        let state=states.get(container);if(!state){
            let config;try{config=JSON.parse(container.dataset.lkfCaptcha);}catch{throw new Error('CAPTCHA configuration is unavailable.');}
            state={config,container,input:container.querySelector('[name=service_captcha_token]'),status:container.querySelector('[data-lkf-captcha-status]'),id:null,api:null,ready:null,pending:null};states.set(container,state);
        }
        if(state.ready)return state.ready;
        state.ready=(async()=>{
            const c=state.config;if(!c.ready)throw new Error(c.message);
            state.api=await sdk(c);
            if(c.provider==='recaptcha'&&c.type==='v3'){
                await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error(c.message)),20000);state.api.ready(()=>{clearTimeout(timer);resolve();});});return state;
            }
            const options={sitekey:c.siteKey,callback:token=>{
                if(typeof token!=='string'||!token)return;state.input.value=token;state.status.textContent='';if(state.pending){const pending=state.pending;state.pending=null;pending.resolve(token);}
            },'expired-callback':()=>{state.input.value='';},'error-callback':()=>{
                state.input.value='';state.status.textContent=c.message;if(state.pending){const pending=state.pending;state.pending=null;pending.reject(new Error(c.message));}
            }};
            if(c.provider==='recaptcha'&&c.type==='invisible')options.size='invisible';
            if(c.provider==='turnstile'){options.theme=c.type;options.action=c.action;options['refresh-expired']='auto';}
            state.id=state.api.render(container.querySelector('[data-lkf-captcha-widget]'),options);return state;
        })().catch(error=>{state.status.textContent=error.message;state.ready=null;throw error;});
        return state.ready;
    }
    function find(root){return root.matches?.('[data-lkf-captcha]')?root:root.querySelector('[data-lkf-captcha]');}
    function timeout(promise,message,milliseconds=120000){return new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error(message)),milliseconds);Promise.resolve(promise).then(value=>{clearTimeout(timer);resolve(value);},error=>{clearTimeout(timer);reject(error);});});}
    async function prepare(root){
        const container=find(root);if(!container)return '';const state=await ensure(container),c=state.config;
        if(c.provider==='recaptcha'&&c.type==='v3'){
            const token=await timeout(state.api.execute(c.siteKey,{action:c.action}),c.message,20000);if(typeof token!=='string'||!token)throw new Error(c.message);state.input.value=token;return token;
        }
        if(c.provider==='recaptcha'&&c.type==='invisible'){
            // Each submission gets a fresh one-use token, including a retry after a network error.
            state.api.reset(state.id);state.input.value='';
            try{return await timeout(new Promise((resolve,reject)=>{state.pending={resolve,reject};try{state.api.execute(state.id);}catch(error){state.pending=null;reject(error);}}),c.message);}
            finally{state.pending=null;}
        }
        const token=state.api.getResponse(state.id);if(typeof token!=='string'||!token)throw new Error(c.completeMessage || __("Please complete the CAPTCHA verification.", 'leenkat-forms'));state.input.value=token;return token;
    }
    function reset(root){const container=find(root),state=container&&states.get(container);if(!state)return;state.input.value='';if(state.api&&state.id!==null){try{state.api.reset(state.id);}catch{state.ready=null;}}}
    function init(root=document){for(const container of root.querySelectorAll('[data-lkf-captcha]'))ensure(container).catch(()=>{});}
    window.leenfoCaptcha={prepare,reset,init};
    document.addEventListener('DOMContentLoaded',()=>init());
    window.addEventListener('pageshow',()=>{for(const container of document.querySelectorAll('[data-lkf-captcha]'))reset(container);});
})();

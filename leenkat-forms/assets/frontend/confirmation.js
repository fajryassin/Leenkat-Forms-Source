(function(){
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;
    if(window.lkfConfirmation)return;
    const presented=new WeakSet();
    function present(box){
        if(presented.has(box))return;
        presented.add(box);
        box.focus({preventScroll:true});
        if(box.dataset.scroll==='1')box.scrollIntoView({behavior:window.matchMedia?.('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
    }
    function show(form,result){
        if(form.dataset.lkfCompleted==='1')return;
        const confirmation=result.confirmation;
        form.dataset.lkfCompleted='1';
        for(const button of form.querySelectorAll('[type="submit"]'))button.disabled=true;
        if(confirmation?.type==='redirect'){window.location.assign(confirmation.url);return;}
        if(result.receipt_url){
            if(result.receipt_ticket){
                const transport=document.createElement('form');transport.method='post';transport.action=result.receipt_url;transport.hidden=true;
                const ticket=document.createElement('input');ticket.type='hidden';ticket.name='receipt_ticket';ticket.value=result.receipt_ticket;
                transport.append(ticket);document.body.append(transport);HTMLFormElement.prototype.submit.call(transport);
            }else window.location.assign(result.receipt_url);
            return;
        }
        const box=document.createElement('div');box.className='lkf-confirmation';box.tabIndex=-1;
        box.setAttribute('role','status');box.setAttribute('aria-live','polite');box.dataset.lkfConfirmation='';
        box.dataset.scroll=confirmation?.scroll===false?'0':'1';
        if(confirmation?.type==='message'){
            // HTML is generated and sanitized by the public endpoint, including submitted values.
            box.innerHTML=confirmation.html;
            if(confirmation.preview_html){const preview=document.createElement('section');preview.className='lkf-entry-preview';const heading=document.createElement('h3');heading.textContent=__("Your submission", 'leenkat-forms');preview.appendChild(heading);const content=document.createElement('div');content.innerHTML=confirmation.preview_html;preview.appendChild(content);box.appendChild(preview);}
        }else box.textContent=result.message || __("Thank you. Your response was submitted.", 'leenkat-forms');
        form.insertAdjacentElement('afterend',box);present(box);
    }
    window.lkfConfirmation={show};
    const start=()=>{for(const box of document.querySelectorAll('[data-lkf-confirmation]'))present(box);};
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();

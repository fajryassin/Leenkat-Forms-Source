(function(element,components,i18n){
    'use strict';
    const e=element.createElement,{useState,useRef,useEffect}=element,{Button,TextControl,TextareaControl,SelectControl,ToggleControl,Modal}=components,__=i18n.__;
    const operators=[['equals',__("is", 'leenkat-forms')],['not_equals',__("is not", 'leenkat-forms')],['is_empty',__("empty", 'leenkat-forms')],['not_empty',__("not empty", 'leenkat-forms')],['contains',__("contains", 'leenkat-forms')],['not_contains',__("does not contain", 'leenkat-forms')],['starts_with',__("starts with", 'leenkat-forms')],['ends_with',__("ends with", 'leenkat-forms')],['greater_than',__("greater than", 'leenkat-forms')],['less_than',__("less than", 'leenkat-forms')]];
    const identifier=()=> 'c_'+(window.crypto?.randomUUID?window.crypto.randomUUID().replaceAll('-',''):Date.now().toString(36)+Math.random().toString(36).slice(2));
    const defaults=(name=__("Default Confirmation", 'leenkat-forms'))=>({id:identifier(),name,type:'message',message:__("Thank you. Your response was submitted.", 'leenkat-forms'),message_scroll:true,entry_preview:false,page_id:0,redirect:''});
    function normalize(definition){
        if(definition.settings?.confirmations?.items)return definition.settings.confirmations;
        const item=defaults();item.id='c_default';
        const old=definition.settings?.confirmation?.message;
        if(old)item.message=old.replace(/<(script|style)[^>]*>[\s\S]*?<\/\1>/gi,'').replace(/<[^>]*>/g,'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
        return {items:[item]};
    }
    function MessageEditor({id,value,onChange}){
        const [mode,setMode]=useState('visual'),latest=useRef({value,onChange});latest.current={value,onChange};
        useEffect(()=>{
            if(mode!=='visual'){document.getElementById(id)?.removeAttribute('aria-hidden');return;}
            if(!window.wp.editor?.initialize)return;
            window.wp.editor.initialize(id,{tinymce:{height:180,menubar:false,toolbar1:'bold italic underline blockquote strikethrough bullist numlist alignleft aligncenter alignright undo redo link unlink',toolbar2:'',wpautop:false,setup:editor=>{
                editor.on('init',()=>editor.setContent(latest.current.value));
                editor.on('change input undo redo',()=>latest.current.onChange(editor.getContent()));
            }},quicktags:false,mediaButtons:false});
            return ()=>{window.wp.editor.remove(id);document.getElementById(id)?.removeAttribute('aria-hidden');};
        },[id,mode]);
        useEffect(()=>{const editor=window.tinymce?.get(id);if(editor && editor.initialized && editor.getContent()!==value)editor.setContent(value);},[value,id]);
        function switchMode(next){const editor=window.tinymce?.get(id);if(editor)latest.current.onChange(editor.getContent());setMode(next);}
        return e('div',{className:'lkf-confirmation-editor'},e('div',{className:'lkf-editor-modes',role:'tablist','aria-label':__('Message editor mode','leenkat-forms')},['visual','code'].map(key=>e('button',{type:'button',role:'tab','aria-selected':mode===key,key,onClick:()=>switchMode(key)},key==='visual'?__('Visual','leenkat-forms'):__('Code','leenkat-forms')))),e('textarea',{id,'aria-label':__('Confirmation Message','leenkat-forms'),value,rows:8,onChange:event=>onChange(event.target.value)}));
    }
    function Editor({definition,onChange,config={}}){
        const settings=normalize(definition),items=settings.items;
        const [collapsed,setCollapsed]=useState({}),[dialog,setDialog]=useState(null),[name,setName]=useState(''),[smart,setSmart]=useState(null);
        const update=(id,patch)=>onChange({...settings,items:items.map(item=>item.id===id?{...item,...patch}:item)});
        const sources=definition.fields.filter(field=>!['captcha','section_divider','page_break','file_upload'].includes(field.type));
        function openDialog(kind,item){setName(kind==='add'?'':item.name);setDialog({kind,id:item?.id});}
        function confirmDialog(){
            if(dialog.kind==='delete')onChange({...settings,items:items.filter((item,index)=>index===0 || item.id!==dialog.id)});
            else if(name.trim()){if(dialog.kind==='add')onChange({...settings,items:[...items,defaults(name.trim())]});else update(dialog.id,{name:name.trim()});}else return;
            setDialog(null);
        }
        function insertTag(item,tag){
            const id='lkf-confirmation-message-'+item.id,editor=window.tinymce?.get(id),token='{'+tag+'}',value=item.message || '';
            if(editor){editor.focus();editor.insertContent(token);update(item.id,{message:editor.getContent()});}
            else {const input=document.getElementById(id),start=input?.selectionStart ?? value.length,end=input?.selectionEnd ?? value.length;update(item.id,{message:value.slice(0,start)+token+value.slice(end)});}
            setSmart(null);
        }
        function smartTags(item){
            const available=definition.fields.filter(field=>!['captcha','section_divider','page_break'].includes(field.type)).flatMap(field=>{
                const tags=[['field:'+field.id,field.label]];
                if(field.type==='name')for(const part of field.settings?.name_format==='simple'?['first']:field.settings?.name_format==='first_middle_last'?['first','middle','last']:['first','last'])tags.push(['field:'+field.id+':'+part,field.label+' – '+({first:__('First', 'leenkat-forms'),middle:__('Middle', 'leenkat-forms'),last:__('Last', 'leenkat-forms')})[part]]);
                return tags;
            });
            const other=[['all_fields',__("All Fields", 'leenkat-forms')],['form_name',__("Form Name", 'leenkat-forms')],['form_id',__("Form ID", 'leenkat-forms')],['entry_id',__("Entry ID", 'leenkat-forms')],['entry_date',__("Entry Date", 'leenkat-forms')],['site_name',__("Site Name", 'leenkat-forms')],['site_url',__("Site URL", 'leenkat-forms')]];
            return e('div',{className:'lkf-confirmation-smart-tags'},e('button',{type:'button',className:'lkf-text-button','aria-expanded':smart===item.id,onClick:()=>setSmart(smart===item.id?null:item.id)},smart===item.id?__('Hide Smart Tags','leenkat-forms'):__('Show Smart Tags','leenkat-forms')),smart===item.id && e('div',{className:'lkf-smart-tags-list','aria-label':__('Confirmation Smart Tags','leenkat-forms')},[[__("Available Fields", 'leenkat-forms'),available],[__("Other", 'leenkat-forms'),other]].map(([heading,tags])=>e('div',{className:'lkf-smart-tags-group',key:heading},e('h4',{},__(heading,'leenkat-forms')),tags.map(([tag,caption])=>e('button',{type:'button',key:tag,onClick:()=>insertTag(item,tag)},caption,e('code',{},'{'+tag+'}')))))));
        }
        function logic(item){
            const condition=item.condition,groups=condition?.groups || [],blank=()=>({source_field_id:'',operator:'equals',value:''});
            const setGroups=next=>update(item.id,{condition:next.length?{...condition,groups:next}:null});
            const patch=(g,c,value)=>setGroups(groups.map((group,i)=>group.map((rule,j)=>i===g && j===c?{...rule,...value}:rule)));
            return e('div',{className:'lkf-notification-logic'},e(ToggleControl,{label:__('Enable Conditional Logic','leenkat-forms'),disabled:!sources.length,checked:!!condition,onChange:enabled=>update(item.id,{condition:enabled?{action:'send',groups:[[blank()]]}:null})}),condition && e('div',{},e('div',{className:'lkf-logic-action'},e(SelectControl,{label:__('Confirmation action','leenkat-forms'),hideLabelFromVision:true,value:condition.action,options:[{value:'send',label:__('Process','leenkat-forms')},{value:'stop',label:__("Don't Process",'leenkat-forms')}],onChange:action=>update(item.id,{condition:{...condition,action}})}),e('em',{},__('this confirmation if','leenkat-forms'))),groups.map((group,g)=>e('div',{key:g},g>0 && e('p',{},__('or','leenkat-forms')),group.map((rule,c)=>{
                const field=sources.find(source=>source.id===rule.source_field_id),choices=field?.settings?.dynamic_choices && field.settings.dynamic_choices!=='off'?(config.choiceOptions?.[field.settings.dynamic_choices]?.[field.settings.dynamic_source] || []):(field?.settings?.options || []).map(value=>({value,label:value}));
                return e('div',{className:'lkf-logic-condition',key:c},e(SelectControl,{label:__('Field','leenkat-forms'),hideLabelFromVision:true,value:rule.source_field_id,options:[{value:'',label:__("--- Select Field ---", 'leenkat-forms')},...sources.map(source=>({value:source.id,label:source.label}))],onChange:source_field_id=>patch(g,c,{source_field_id,value:''})}),e(SelectControl,{label:__('Condition','leenkat-forms'),hideLabelFromVision:true,disabled:!field,value:rule.operator,options:operators.map(([value,label])=>({value,label})),onChange:operator=>patch(g,c,{operator})}),e('div',{className:'lkf-logic-value'},!['is_empty','not_empty'].includes(rule.operator) && (!field || choices.length?e(SelectControl,{label:__('Value','leenkat-forms'),hideLabelFromVision:true,disabled:!field,value:rule.value || '',options:[{value:'',label:__("--- Select Choice ---", 'leenkat-forms')},...choices],onChange:value=>patch(g,c,{value})}):e(TextControl,{label:__('Value','leenkat-forms'),hideLabelFromVision:true,value:rule.value || '',onChange:value=>patch(g,c,{value})}))),e(Button,{variant:'primary',disabled:group.length>=5,onClick:()=>setGroups(groups.map((other,i)=>i===g?[...other,blank()]:other))},__('And','leenkat-forms')),e(Button,{variant:'tertiary',isDestructive:true,'aria-label':__('Remove confirmation condition','leenkat-forms'),onClick:()=>setGroups(groups.map((other,i)=>i===g?other.filter((_,j)=>j!==c):other).filter(other=>other.length))},'×'));
            }))),e(Button,{variant:'primary',disabled:groups.length>=5,onClick:()=>setGroups([...groups,[blank()]])},__('Add New Group','leenkat-forms'))));
        }
        return e('div',{className:'lkf-confirmations'},e('div',{className:'lkf-notification-toolbar'},e('h2',{},__('Confirmations','leenkat-forms')),e(Button,{variant:'primary',disabled:items.length>=20,onClick:()=>openDialog('add')},__('Add New Confirmation','leenkat-forms'))),items.length>1 && e('p',{className:'lkf-confirmation-note'},__('Additional confirmations are checked newest first. If none apply, the default confirmation is checked. When none apply, a generic success message is shown.','leenkat-forms')),items.map((item,index)=>e('section',{className:'lkf-notification-card lkf-confirmation-card',key:item.id,'aria-label':item.name,'data-confirmation-id':item.id},e('div',{className:'lkf-notification-card-header'},e('h3',{},item.name),e(Button,{variant:'tertiary','aria-label':__('Rename confirmation ','leenkat-forms')+item.name,onClick:()=>openDialog('rename',item)},'✎'),e('div',{className:'lkf-notification-actions'},index>0 && e(Button,{variant:'tertiary',isDestructive:true,'aria-label':__('Delete confirmation ','leenkat-forms')+item.name,onClick:()=>openDialog('delete',item)},'×'),e('button',{type:'button','aria-label':__('Toggle confirmation ','leenkat-forms')+item.name,'aria-expanded':!collapsed[item.id],onClick:()=>setCollapsed({...collapsed,[item.id]:!collapsed[item.id]})},collapsed[item.id]?'›':'⌃'))),!collapsed[item.id] && e('div',{className:'lkf-notification-content'},e('div',{className:'lkf-confirmation-select'},e(SelectControl,{label:__('Confirmation Type','leenkat-forms'),value:item.type,options:[{value:'message',label:__('Message','leenkat-forms')},{value:'page',label:__('Show Page','leenkat-forms')},{value:'redirect',label:__('Go to URL (Redirect)','leenkat-forms')}],onChange:type=>update(item.id,{type})})),
            item.type==='message' && e('div',{className:'lkf-confirmation-message-options'},e('label',{htmlFor:'lkf-confirmation-message-'+item.id},__('Confirmation Message','leenkat-forms')),e(MessageEditor,{id:'lkf-confirmation-message-'+item.id,value:item.message || '',onChange:message=>update(item.id,{message})}),smartTags(item),e(ToggleControl,{label:__('Automatically scroll to the confirmation message','leenkat-forms'),checked:item.message_scroll!==false,onChange:message_scroll=>update(item.id,{message_scroll})}),e(ToggleControl,{label:__('Show entry preview after confirmation message','leenkat-forms'),checked:!!item.entry_preview,onChange:entry_preview=>update(item.id,{entry_preview})})),
            item.type==='page' && e('div',{className:'lkf-confirmation-select'},e(SelectControl,{label:__('Confirmation Page','leenkat-forms'),value:item.page_id || 0,options:[{value:0,label:__("--- Select Page ---", 'leenkat-forms')},...(config.confirmationPages || []),...(item.page_id && !(config.confirmationPages || []).some(page=>Number(page.value)===item.page_id)?[{value:item.page_id,label:__('Unavailable page','leenkat-forms')+' (#'+item.page_id+')'}]:[])],onChange:value=>update(item.id,{page_id:Number(value)})})),
            item.type==='redirect' && e(TextControl,{label:__('Confirmation Redirect URL','leenkat-forms')+' *',type:'url',value:item.redirect || '',help:__('Enter a complete http:// or https:// URL.','leenkat-forms'),onChange:redirect=>update(item.id,{redirect})}),logic(item)))),
            dialog && e(Modal,{title:__(dialog.kind==='delete'?__("Delete confirmation?", 'leenkat-forms'):dialog.kind==='add'?__("Add New Confirmation", 'leenkat-forms'):__("Rename Confirmation", 'leenkat-forms'),'leenkat-forms'),className:'lkf-notification-dialog',onRequestClose:()=>setDialog(null)},dialog.kind==='delete'?e('p',{},__('Remove this confirmation from future submissions?','leenkat-forms')):e(TextControl,{label:__('Confirmation Name','leenkat-forms'),value:name,maxLength:100,autoFocus:true,onChange:setName}),e('div',{className:'lkf-notification-dialog-actions'},e(Button,{variant:'secondary',onClick:()=>setDialog(null)},__('Cancel','leenkat-forms')),e(Button,{variant:'primary',isDestructive:dialog.kind==='delete',disabled:dialog.kind!=='delete' && !name.trim(),onClick:confirmDialog},__(dialog.kind==='delete'?__("Delete Confirmation", 'leenkat-forms'):dialog.kind==='add'?__("Add Confirmation", 'leenkat-forms'):__("Save Name", 'leenkat-forms'),'leenkat-forms'))))
        );
    }
    window.leenfoConfirmationsEditor=Editor;window.leenfoConfirmationsModel={defaults,normalize};
})(window.wp.element,window.wp.components,window.wp.i18n);


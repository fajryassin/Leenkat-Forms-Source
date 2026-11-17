(function(element,components,i18n){
    'use strict';
    const e=element.createElement,{useState,useRef}=element,{Button,TextControl,TextareaControl,SelectControl,FormTokenField,ToggleControl,Modal,Tooltip}=components,__=i18n.__;
    const templates=[['default',__("Default Template", 'leenkat-forms'),__("Uses the template selected in global Email settings.", 'leenkat-forms')],['compact',__("Compact", 'leenkat-forms'),__("Less padding and a narrower layout.", 'leenkat-forms')],['modern',__("Modern", 'leenkat-forms'),__("A teal header and generous spacing.", 'leenkat-forms')],['elegant',__("Elegant", 'leenkat-forms'),__("Warm colors with a serif heading.", 'leenkat-forms')],['tech',__("Tech", 'leenkat-forms'),__("A dark header with monospace text.", 'leenkat-forms')],['plain',__("Plain Text", 'leenkat-forms'),__("Unformatted text, supported by all email clients.", 'leenkat-forms')]];
    const operators=[['equals',__("is", 'leenkat-forms')],['not_equals',__("is not", 'leenkat-forms')],['is_empty',__("empty", 'leenkat-forms')],['not_empty',__("not empty", 'leenkat-forms')],['contains',__("contains", 'leenkat-forms')],['not_contains',__("does not contain", 'leenkat-forms')],['starts_with',__("starts with", 'leenkat-forms')],['ends_with',__("ends with", 'leenkat-forms')],['greater_than',__("greater than", 'leenkat-forms')],['less_than',__("less than", 'leenkat-forms')]];
    const identifier=()=> 'n_'+(window.crypto?.randomUUID?window.crypto.randomUUID().replaceAll('-',''):Date.now().toString(36)+Math.random().toString(36).slice(2));
    const clause=()=>({source_field_id:'',operator:'equals',value:''});
    function defaults(definition,name=__("Default Notification", 'leenkat-forms')){
        const email=definition.fields.find(field=>field.type==='email');
        return {id:identifier(),name,enabled:true,to:'{admin_email}',subject:__("New Entry: {form_name} (ID #{form_id})", 'leenkat-forms'),from_name:'{site_name}',from_email:'{admin_email}',reply_to:email?'{field:'+email.id+'}':'',body:'{all_fields}',template:'default',file_attachments:false,file_fields:[],csv_attachment:false,csv_fields:['all_fields'],csv_filename:'entry-details'};
    }
    function normalize(definition){
        const saved=definition.settings?.notifications || {};
        if(Array.isArray(saved.items))return saved;
        const items=[];
        for(const kind of ['admin','user'])if(saved[kind]){const old=saved[kind];items.push({...defaults(definition,kind==='admin'?__("Administrator Notification", 'leenkat-forms'):__("Respondent Notification", 'leenkat-forms')),id:'legacy_'+kind,enabled:!!old.enabled,to:kind==='admin'?(old.recipients || []).join(', '):old.email_field?'{field:'+old.email_field+'}':'',subject:old.subject || __("New form entry", 'leenkat-forms'),body:old.body || __("A new entry was submitted.", 'leenkat-forms'),template:'plain',from_name:'',from_email:'',reply_to:''});}
        return {enabled:items.some(item=>item.enabled),items};
    }
    function Editor({definition,onChange,config={},formId}){
        const settings=normalize(definition),items=settings.items || [];
        const [collapsed,setCollapsed]=useState({}),[advanced,setAdvanced]=useState({}),[smart,setSmart]=useState(null),[dialog,setDialog]=useState(null),[name,setName]=useState(''),[helpHidden,setHelpHidden]=useState(false),[templateModal,setTemplateModal]=useState(null);
        const selection=useRef(null);
        const [previewTemplate,setPreviewTemplate]=useState(null);
        const update=(id,patch)=>onChange({...settings,items:items.map(item=>item.id===id?{...item,...patch}:item)});
        const sources=definition.fields.filter(field=>!['captcha','section_divider','page_break','file_upload'].includes(field.type));
        const uploads=definition.fields.filter(field=>field.type==='file_upload');
        const csvSources=definition.fields.filter(field=>!['captcha','section_divider','page_break'].includes(field.type));
        function openDialog(kind,item){setDialog({kind,id:item?.id});setName(kind==='add'?'':item?.name || '');}
        function confirmDialog(){
            if(dialog.kind==='delete'){const next=items.filter(item=>item.id!==dialog.id);onChange({...settings,enabled:next.length?settings.enabled:false,items:next});}
            else if(name.trim()){
                if(dialog.kind==='add'){const item=defaults(definition,name.trim());onChange({...settings,enabled:true,items:[...items,item]});setCollapsed({...collapsed,[item.id]:false});}
                else update(dialog.id,{name:name.trim()});
            }else return;
            setDialog(null);
        }
        function duplicate(item){if(items.length>=20)return;const copy=JSON.parse(JSON.stringify(item));copy.id=identifier();copy.name=item.name.slice(0,93)+__(" (copy)", 'leenkat-forms');onChange({...settings,items:[...items,copy]});setCollapsed({...collapsed,[copy.id]:false});}
        function tagsFor(key){
            const address=['to','from_email','reply_to','cc'].includes(key),fromName=key==='from_name';
            const fields=definition.fields.filter(field=>address?field.type==='email':fromName?['name','text'].includes(field.type):!['captcha','section_divider','page_break'].includes(field.type));
            const other=address?[['admin_email',__("Site Administrator Email", 'leenkat-forms')],['user_email',__("Logged-in User Email", 'leenkat-forms')]]:fromName?[['site_name',__("Site Name", 'leenkat-forms')]]:[['all_fields',__("All Fields", 'leenkat-forms')],['admin_email',__("Site Administrator Email", 'leenkat-forms')],['user_email',__("Logged-in User Email", 'leenkat-forms')],['form_name',__("Form Name", 'leenkat-forms')],['form_id',__("Form ID", 'leenkat-forms')],['entry_id',__("Entry ID", 'leenkat-forms')],['entry_date',__("Entry Date", 'leenkat-forms')],['site_name',__("Site Name", 'leenkat-forms')],['site_url',__("Site URL", 'leenkat-forms')]];
            const available=fields.flatMap(field=>{
                const entries=[['field:'+field.id,field.label]];
                if(field.type==='name'){
                    const format=field.settings?.name_format || 'first_last';
                    const parts=format==='simple'?['first']:format==='first_middle_last'?['first','middle','last']:['first','last'];
                    parts.forEach(part=>entries.push(['field:'+field.id+':'+part,field.label+' – '+({first:__('First', 'leenkat-forms'),middle:__('Middle', 'leenkat-forms'),last:__('Last', 'leenkat-forms')})[part]]));
                }
                return entries;
            });
            return [[__("Available Fields", 'leenkat-forms'),available],[__("Other", 'leenkat-forms'),other]];
        }
        function smartField(item,key,label,multiline=false,hint=''){
            const id='lkf-notification-'+item.id+'-'+key,active=smart===id;
            const value=item[key] ?? '';
            return e('div',{className:'lkf-notification-field lkf-option-control',key},
                e('div',{className:'lkf-control-heading'},e('label',{htmlFor:id},__(label,'leenkat-forms')),hint && e(Tooltip,{text:hint},e('span',{className:'lkf-notification-help',tabIndex:0,'aria-label':hint},'?')),
                    e('button',{type:'button',className:'lkf-smart-tags-toggle','aria-expanded':active,'aria-controls':id+'-tags',onClick:()=>{const input=document.getElementById(id);selection.current={start:input?.selectionStart ?? value.length,end:input?.selectionEnd ?? value.length};setSmart(active?null:id);}},active?__('Hide Smart Tags','leenkat-forms'):__('Show Smart Tags','leenkat-forms'))),
                active && e('div',{id:id+'-tags',className:'lkf-smart-tags-list','aria-label':__('Smart Tags for ','leenkat-forms')+label},tagsFor(key).map(([heading,entries])=>e('div',{key:heading,className:'lkf-smart-tags-group'},e('h4',{},__(heading,'leenkat-forms')),!entries.length && e('p',{},__('No matching fields.','leenkat-forms')),entries.map(([tag,caption])=>e('button',{type:'button',key:tag,onClick:()=>{const start=selection.current?.start ?? value.length,end=selection.current?.end ?? value.length,token='{'+tag+'}';update(item.id,{[key]:value.slice(0,start)+token+value.slice(end)});setSmart(null);}},__(caption,'leenkat-forms'),e('code',{},'{'+tag+'}')))))),
                e(multiline?TextareaControl:TextControl,{id,label:__(label,'leenkat-forms'),'aria-label':__(label,'leenkat-forms'),hideLabelFromVision:true,value,onChange:next=>update(item.id,{[key]:next})})
            );
        }
        function conditionEditor(item){
            const condition=item.condition,groups=condition?.groups || [];
            const setGroups=next=>update(item.id,{condition:next.length?{...condition,groups:next}:null});
            const patch=(g,c,value)=>setGroups(groups.map((group,i)=>group.map((rule,j)=>i===g && j===c?{...rule,...value}:rule)));
            return e('div',{className:'lkf-notification-logic'},
                e(ToggleControl,{label:__('Enable Conditional Logic','leenkat-forms'),checked:!!condition,disabled:!sources.length,onChange:enabled=>update(item.id,{condition:enabled?{action:'send',groups:[[clause()]]}:null})}),
                !sources.length && e('p',{className:'lkf-notification-note'},__('Add an input field to use notification conditions.','leenkat-forms')),
                condition && e('div',{},e('div',{className:'lkf-logic-action'},e(SelectControl,{label:__('Notification action','leenkat-forms'),hideLabelFromVision:true,value:condition.action,options:[{value:'send',label:__('Send','leenkat-forms')},{value:'stop',label:__("Don't Send",'leenkat-forms')}],onChange:action=>update(item.id,{condition:{...condition,action}})}),e('em',{},__('this notification if','leenkat-forms'))),
                    groups.map((group,g)=>e('div',{key:g},g>0 && e('p',{className:'lkf-logic-or'},__('or','leenkat-forms')),group.map((rule,c)=>{
                        const field=sources.find(source=>source.id===rule.source_field_id);
                        const choices=field?.settings?.dynamic_choices && field.settings.dynamic_choices!=='off'?(config.choiceOptions?.[field.settings.dynamic_choices]?.[field.settings.dynamic_source] || []).map(option=>({value:option.value,label:option.label})):(field?.settings?.options || []).map(value=>({value,label:value}));
                        return e('div',{className:'lkf-logic-condition',key:c},
                            e(SelectControl,{label:__('Field','leenkat-forms'),hideLabelFromVision:true,value:rule.source_field_id,options:[{value:'',label:__("--- Select Field ---", 'leenkat-forms')},...sources.map(source=>({value:source.id,label:source.label}))],onChange:source_field_id=>patch(g,c,{source_field_id,value:''})}),
                            e(SelectControl,{label:__('Condition','leenkat-forms'),hideLabelFromVision:true,disabled:!field,value:rule.operator,options:operators.map(([value,label])=>({value,label:__(label,'leenkat-forms')})),onChange:operator=>patch(g,c,{operator})}),
                            e('div',{className:'lkf-logic-value'},!['is_empty','not_empty'].includes(rule.operator) && (!field || choices.length?e(SelectControl,{label:__('Value','leenkat-forms'),hideLabelFromVision:true,disabled:!field,value:rule.value || '',options:[{value:'',label:__("--- Select Choice ---", 'leenkat-forms')},...choices],onChange:value=>patch(g,c,{value})}):e(TextControl,{label:__('Value','leenkat-forms'),hideLabelFromVision:true,value:rule.value || '',onChange:value=>patch(g,c,{value})}))),
                            e(Button,{variant:'primary',disabled:group.length>=5,onClick:()=>setGroups(groups.map((other,i)=>i===g?[...other,clause()]:other))},__('And','leenkat-forms')),
                            e(Button,{variant:'tertiary',isDestructive:true,'aria-label':__('Remove notification condition','leenkat-forms'),onClick:()=>setGroups(groups.map((other,i)=>i===g?other.filter((_,j)=>j!==c):other).filter(other=>other.length))},'×')
                        );
                    }))),e(Button,{variant:'primary',disabled:groups.length>=5,onClick:()=>setGroups([...groups,[clause()]])},__('Add New Group','leenkat-forms'))
                )
            );
        }
        function selectionField(item,key,label,options){
            const captions=Object.fromEntries(options.map(option=>[option.value,option.label+(definition.fields.some(field=>field.id===option.value)?' ('+option.value+')':'')]));
            return e('div',{className:'lkf-notification-field lkf-attachment-selection'},e(FormTokenField,{label:__(label,'leenkat-forms'),value:(item[key] || []).map(value=>captions[value]).filter(Boolean),suggestions:Object.values(captions),__experimentalExpandOnFocus:true,__experimentalValidateInput:value=>Object.values(captions).includes(value),onChange:tokens=>update(item.id,{[key]:[...new Set(tokens.map(token=>Object.keys(captions).find(id=>captions[id]===token)).filter(Boolean))]})}));
        }
        function senderWarning(item){
            const source=item.from_email || (item.from_name?'{admin_email}':'');
            if(!source || !config.siteHost)return null;
            const tags={admin_email:config.adminEmail || '',...config.senderTagValues,form_name:definition.title || '',form_id:String(formId || '')};
            const email=source.replace(/\{([^{}]+)\}/g,(tag,key)=>key!=='user_email'&&Object.hasOwn(tags,key)?String(tags[key]):tag).trim();
            const dynamic=email.includes('{');
            const domain=email.includes('@')?email.split('@').at(-1).toLowerCase().replace(/\.$/,''):'';
            const host=config.siteHost.toLowerCase().replace(/^www\./,'').replace(/\.$/,'');
            if(!dynamic && (!domain || domain===host))return null;
            return e('div',{className:'lkf-sender-warning',role:'status','aria-live':'polite','aria-atomic':'true'},
                e('strong',{},__('Email delivery warning','leenkat-forms')),
                e('p',{},dynamic?__('The From Email uses a value that is only known after submission. If its domain differs from your website, the sender may be unauthorized and notifications may fail delivery.','leenkat-forms'):__('The resolved From Email domain differs from your website domain. Your mail provider may not authorize this sender, so notifications may fail delivery.','leenkat-forms')),
                e('p',{},__('Use a sender address authorized by your site’s mail provider, preferably from your website domain. An external domain is valid when your SMTP provider explicitly authorizes it. This warning does not block saving or submissions.','leenkat-forms')));
        }
        function templateFrame(value,label,large=false){
            const preview=config.emailTemplatePreviews?.[value];
            return preview?e('iframe',{title:__('Email preview: ','leenkat-forms')+__(label,'leenkat-forms'),srcDoc:preview.document,scrolling:large?'auto':'no',sandbox:'',referrerPolicy:'no-referrer',tabIndex:large?0:-1,className:large?'lkf-email-preview-full':'lkf-email-preview-thumb'}):e('p',{},__('Reload the builder to load email previews.','leenkat-forms'));
        }
        function templateGallery(){
            const selected=templates.find(([value])=>value===previewTemplate);
            return e(Modal,{key:previewTemplate || 'gallery',title:previewTemplate?__('Email Preview: ','leenkat-forms')+__(selected[1],'leenkat-forms'):__('Email Templates','leenkat-forms'),onRequestClose:()=>{setTemplateModal(null);setPreviewTemplate(null);},className:'lkf-template-modal'},
                e('p',{className:'lkf-template-preview-note'},__('These previews use sample content and the actual email renderer. No email is sent.','leenkat-forms')),
                previewTemplate?e('div',{},e('div',{className:'lkf-template-preview-toolbar'},e(Button,{variant:'secondary',onClick:()=>setPreviewTemplate(null)},__('All Templates','leenkat-forms')),e(SelectControl,{label:__('Preview Template','leenkat-forms'),value:previewTemplate,options:templates.map(([value,label])=>({value,label:__(label,'leenkat-forms')})),onChange:setPreviewTemplate}),e(Button,{variant:'primary',onClick:()=>{update(templateModal,{template:previewTemplate});setTemplateModal(null);setPreviewTemplate(null);}},__('Use ','leenkat-forms')+__(selected[1],'leenkat-forms'))),e('p',{className:'lkf-email-preview-subject'},e('strong',{},__('Subject: ','leenkat-forms')),config.emailTemplatePreviews?.[previewTemplate]?.subject),templateFrame(previewTemplate,selected[1],true)):
                    e('div',{className:'lkf-template-gallery'},templates.map(([value,label,description])=>e('section',{key:value,className:'lkf-template-sample lkf-template-'+value},e('h2',{},__(label,'leenkat-forms')),e('p',{},__(description,'leenkat-forms')),templateFrame(value,label),e('div',{className:'lkf-template-sample-actions'},e(Button,{variant:'secondary',onClick:()=>setPreviewTemplate(value)},__('Preview ','leenkat-forms')+__(label,'leenkat-forms')),e(Button,{variant:'primary',onClick:()=>{update(templateModal,{template:value});setTemplateModal(null);}},__('Use ','leenkat-forms')+__(label,'leenkat-forms'))))))
            );
        }
        return e('div',{className:'lkf-notifications'},
            e('div',{className:'lkf-notification-toolbar'},e('h2',{},__('Notifications','leenkat-forms')),settings.enabled && e(Button,{variant:'primary',disabled:items.length>=20,onClick:()=>openDialog('add')},__('Add New Notification','leenkat-forms'))),
            !helpHidden && e('div',{className:'lkf-notification-intro'},e('button',{type:'button','aria-label':__('Dismiss notification introduction','leenkat-forms'),onClick:()=>setHelpHidden(true)},'×'),e('p',{},__('Notifications are emails sent when a form is submitted. Use Smart Tags to include submitted values and form details.','leenkat-forms')),e('p',{},__('Save your settings, then submit a test form to check the message and your mail delivery configuration.','leenkat-forms'))),
            e(ToggleControl,{label:__('Enable Notifications','leenkat-forms'),checked:!!settings.enabled,onChange:enabled=>onChange({...settings,enabled,items:enabled && !items.length?[defaults(definition)]:items})}),
            settings.enabled && items.map(item=>e('section',{className:'lkf-notification-card',key:item.id,'data-notification-id':item.id,'aria-label':item.name},
                e('div',{className:'lkf-notification-card-header'},e('h3',{},item.name),
                    e(Button,{variant:'tertiary','aria-label':__('Rename notification ','leenkat-forms')+item.name,onClick:()=>openDialog('rename',item)},'✎'),
                    e('div',{className:'lkf-notification-actions'},e(ToggleControl,{label:__('Enable notification ','leenkat-forms')+item.name,hideLabelFromVision:true,checked:item.enabled!==false,onChange:enabled=>update(item.id,{enabled})}),
                        e(Button,{variant:'tertiary',disabled:items.length>=20,'aria-label':__('Duplicate notification ','leenkat-forms')+item.name,onClick:()=>duplicate(item)},'⧉'),
                        e(Button,{variant:'tertiary',isDestructive:true,'aria-label':__('Delete notification ','leenkat-forms')+item.name,onClick:()=>openDialog('delete',item)},'×'),
                        e('button',{type:'button','aria-expanded':!collapsed[item.id],'aria-controls':item.id+'-content','aria-label':__('Toggle notification ','leenkat-forms')+item.name,onClick:()=>setCollapsed({...collapsed,[item.id]:!collapsed[item.id]})},collapsed[item.id]?'›':'⌃')
                    )
                ),
                !collapsed[item.id] && e('div',{id:item.id+'-content',className:'lkf-notification-content'},
                    smartField(item,'to',__("Send To Email Address", 'leenkat-forms'),false,__('Separate multiple email addresses with commas. Email field Smart Tags use the submitted email address.','leenkat-forms')),
                    config.emailCarbonCopy&&smartField(item,'cc','CC',false,__('Separate CC addresses with commas. CC recipients can see the other recipients and use Reply All.','leenkat-forms')),
                    smartField(item,'subject',__("Email Subject Line", 'leenkat-forms')),smartField(item,'from_name',__("From Name", 'leenkat-forms')),smartField(item,'from_email',__("From Email", 'leenkat-forms')),senderWarning(item),smartField(item,'reply_to',__("Reply-To", 'leenkat-forms')),smartField(item,'body',__("Email Message", 'leenkat-forms'),true),
                    e('p',{className:'lkf-notification-note'},__('To display all form fields, use the {all_fields} Smart Tag.','leenkat-forms')),conditionEditor(item),
                    e('div',{className:'lkf-notification-advanced'},e('button',{type:'button',className:'lkf-advanced-toggle','aria-expanded':!!advanced[item.id],'aria-controls':item.id+'-advanced',onClick:()=>setAdvanced({...advanced,[item.id]:!advanced[item.id]})},__('Advanced','leenkat-forms'),e('span',{'aria-hidden':true},advanced[item.id]?'⌄':'›')),
                        advanced[item.id] && e('div',{id:item.id+'-advanced'},e('div',{className:'lkf-notification-field'},e(SelectControl,{label:__('Email Template','leenkat-forms'),value:item.template || 'default',options:templates.map(([value,label])=>({value,label:__(label,'leenkat-forms')})),onChange:template=>update(item.id,{template})}),e('p',{className:'lkf-notification-note'},__('Select a template to use for this notification or ','leenkat-forms'),e('button',{type:'button',className:'lkf-text-button',onClick:()=>setTemplateModal(item.id)},__('view templates','leenkat-forms')),'.')),
                            e(ToggleControl,{label:__('Enable File Upload Attachments','leenkat-forms'),disabled:!uploads.length,checked:!!item.file_attachments,onChange:file_attachments=>update(item.id,{file_attachments})}),
                            !uploads.length && e('p',{className:'lkf-notification-note'},__('Add a File Upload field to attach uploaded files.','leenkat-forms')),
                            item.file_attachments && e('div',{},selectionField(item,'file_fields',__("File Upload Fields", 'leenkat-forms'),uploads.map(field=>({value:field.id,label:field.label}))),e('p',{className:'lkf-notification-note'},__('Selected upload attachments and the CSV together must not exceed 20 MB. Mail providers may impose a lower limit.','leenkat-forms'))),
                            e(ToggleControl,{label:__('Enable Entry CSV Attachment','leenkat-forms'),checked:!!item.csv_attachment,onChange:csv_attachment=>update(item.id,{csv_attachment,csv_fields:item.csv_fields?.length?item.csv_fields:['all_fields']})}),
                            item.csv_attachment && e('div',{},selectionField(item,'csv_fields',__("Entry Information", 'leenkat-forms'),[{value:'all_fields',label:__('All Fields','leenkat-forms')},...csvSources.map(field=>({value:field.id,label:field.label})),...['entry_id','form_id','entry_date'].map(value=>({value,label:({entry_id:__("Entry ID", 'leenkat-forms'),form_id:__("Form ID", 'leenkat-forms'),entry_date:__("Entry Date", 'leenkat-forms')})[value]}))]),e('div',{className:'lkf-notification-field'},e(TextControl,{label:__('File Name','leenkat-forms'),value:item.csv_filename || '',onChange:csv_filename=>update(item.id,{csv_filename})})))
                        )
                    )
                )
            )),
            dialog && e(Modal,{title:__(dialog.kind==='delete'?__("Delete notification?", 'leenkat-forms'):dialog.kind==='add'?__("Add New Notification", 'leenkat-forms'):__("Rename Notification", 'leenkat-forms'),'leenkat-forms'),onRequestClose:()=>setDialog(null),className:'lkf-notification-dialog'},dialog.kind==='delete'?e('p',{},__('This notification will be removed from future submissions. Existing entries and sent messages remain unchanged.','leenkat-forms')):e(TextControl,{label:__('Notification Name','leenkat-forms'),value:name,maxLength:100,autoFocus:true,onChange:setName,onKeyDown:event=>{if(event.key==='Enter'){event.preventDefault();confirmDialog();}}}),e('div',{className:'lkf-notification-dialog-actions'},e(Button,{variant:'secondary',onClick:()=>setDialog(null)},__('Cancel','leenkat-forms')),e(Button,{variant:'primary',isDestructive:dialog.kind==='delete',disabled:dialog.kind!=='delete' && !name.trim(),onClick:confirmDialog},__(dialog.kind==='delete'?__("Delete Notification", 'leenkat-forms'):dialog.kind==='add'?__("Add Notification", 'leenkat-forms'):__("Save Name", 'leenkat-forms'),'leenkat-forms')))),
            templateModal && templateGallery()
        );
    }
    window.leenfoNotificationsEditor=Editor;
    window.leenfoNotificationsModel={defaults,normalize};
})(window.wp.element,window.wp.components,window.wp.i18n);



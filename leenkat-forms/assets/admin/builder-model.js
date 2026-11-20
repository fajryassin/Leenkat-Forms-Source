(function (root, factory) {
    'use strict';
    const model = factory();
    if (typeof module === 'object' && module.exports) module.exports = model;
    if (root) root.leenfoBuilderModel = model;
})(typeof window === 'undefined' ? null : window, function () {
    'use strict';
    const __ = typeof window !== 'undefined' && window.wp?.i18n?.__ ? window.wp.i18n.__ : value => value;

    function rows(fields) { return fields.map((field,index)=>({start:index,fields:[field]})); }
    // Multiple-field containers are a future feature. Canvas drops only reorder rows.
    function canPlaceInColumn() { return false; }
    function applyDrop(fields, source, target, createField) {
        if (!source || !target || target.kind !== 'between' || !Array.isArray(fields)) return null;
        if (source.kind !== 'field' && source.kind !== 'new') return null;
        const original = source.kind === 'field' ? fields.findIndex(field => field.id === source.id) : -1;
        if (source.kind === 'field' && original < 0) return null;
        if (source.kind === 'new' && typeof createField !== 'function') return null;
        if (!Number.isInteger(target.index) || target.index < 0 || target.index > fields.length) return null;
        let destination = target.index;

        const next = fields.slice();
        let moving;
        if (source.kind === 'field') {
            moving = next.splice(original, 1)[0];
            if (original < destination) destination -= 1;
        } else {
            moving = createField(source.type);
            if (!moving || !moving.id || next.some(field => field.id === moving.id)) return null;
        }
        if (moving.width !== 100) moving = { ...moving, width: 100 };
        next.splice(destination, 0, moving);
        if (next.length === fields.length && next.every((field, index) => field === fields[index])) return null;
        if(pageBreakErrors(next).length)return null;
        return { fields: next, selectedId: moving.id };
    }

    function rulesRemainOrdered(fields, rules) {
        const positions = Object.fromEntries(fields.map((field, index) => [field.id, index]));
        return (rules || []).every(rule => {
            const conditions = rule.groups ? rule.groups.flat() : [rule];
            return positions[rule.field_id] !== undefined && conditions.filter(condition=>condition.source_field_id!=='').every(condition =>
                positions[condition.source_field_id] !== undefined && positions[condition.source_field_id] < positions[rule.field_id]);
        });
    }

    function canDropInColumn() { return false; }

    function withFieldNumbers(definition, minimumNext = 1) {
        const fields = definition.fields || [];
        const numeric = value => /^f_[0-9]{4,8}$/.test(value || '') ? Number(value.slice(2)) : 0;
        const reserved = new Set(fields.flatMap(field => [numeric(field.id), numeric(field.display_id)]).filter(Boolean));
        const assigned = new Set();
        let candidate = 1;
        const numbered = fields.map(field => {
            let number = numeric(field.display_id) || numeric(field.id);
            if (!number || assigned.has(number)) {
                while (reserved.has(candidate) || assigned.has(candidate)) candidate++;
                number = candidate++;
            }
            assigned.add(number);
            return { ...field, display_id: 'f_' + String(number).padStart(4, '0') };
        });
        const highest = Math.max(0, ...reserved, ...assigned);
        return { ...definition, fields: numbered, next_field_number: Math.max(highest + 1, definition.next_field_number || 1, minimumNext) };
    }

    const hasBreaks=definition=>(definition.fields || []).some(field=>field.type==='page_break');
    const legacy=definition=>!hasBreaks(definition) && !!definition.steps?.length;
    function pageBreakErrors(fields){const errors=[];let previous=true,count=0;for(const field of fields){if(field.type!=='page_break'){previous=false;continue;}if(previous)errors.push(__("Page Breaks cannot lead the form or be consecutive.", 'leenkat-forms'));previous=true;count++;}if(count && previous)errors.push(__("A Page Break cannot end the form.", 'leenkat-forms'));if(count>49)errors.push(__('A form supports at most 50 pages.', 'leenkat-forms'));return errors;}
    function convertLegacy(definition){
        if(!legacy(definition))return definition;
        const pages=steps(definition).filter(page=>page.fields.length),used=new Set(definition.fields.map(field=>field.id)),fields=[];
        for(const [index,page] of pages.entries()){
            if(index){let id='pb_legacy_'+index;while(used.has(id))id+='_';used.add(id);fields.push({id,type:'page_break',label:__("Page Break", 'leenkat-forms'),required:false,width:100,settings:{page_title:page.title}});}
            fields.push(...page.fields);
        }
        if(fields.length>300 || !rulesRemainOrdered(fields,definition.rules))return null;
        const next={...definition,fields,settings:{...definition.settings,general:{...definition.settings?.general,first_page_title:pages[0]?.title || ''}}};delete next.steps;return next;
    }
    function steps(definition) {
        if(hasBreaks(definition)){
            const text=value=>(value ?? '').replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g,'');
            let first='page_start';while(definition.fields.some(field=>field.id===first))first+='_';
            const pages=[{id:first,title:text(definition.settings?.general?.first_page_title) || __("Step 1", 'leenkat-forms'),fields:[]}];
            for(const field of definition.fields){const current=pages.at(-1);if(field.type!=='page_break'){current.fields.push(field);continue;}current.next_label=text(field.settings?.next_label) || __("Next", 'leenkat-forms');pages.push({id:field.id,title:text(field.settings?.page_title) || __("Step ", 'leenkat-forms')+(pages.length+1),previous_label:text(field.settings?.previous_label) || __("Previous", 'leenkat-forms'),fields:[]});}
            return pages;
        }
        const groups=definition.steps || [],membership={};
        groups.forEach((step,index)=>step.field_ids.forEach(id=>membership[id]=index));
        return groups.map((step,index)=>({...step,title:step.title?.trim() || __("Step ", 'leenkat-forms')+(index+1),fields:definition.fields.filter(field=>(membership[field.id] ?? 0)===index)}));
    }
    function stepRulesOrdered(definition){const positions={};steps(definition).forEach((step,index)=>step.fields.forEach(field=>positions[field.id]=index));return (definition.rules || []).every(rule=>(rule.groups?rule.groups.flat():[rule]).every(clause=>(positions[clause.source_field_id] ?? -1)<=(positions[rule.field_id] ?? Infinity)));}
    function assignStep(definition,fieldId,stepId){return {...definition,steps:(definition.steps || []).map(step=>({...step,field_ids:[...step.field_ids.filter(id=>id!==fieldId),...(step.id===stepId?[fieldId]:[])]}))};}
    return { hasBreaks, legacy, pageBreakErrors, convertLegacy, steps, stepRulesOrdered, assignStep, rows, canPlaceInColumn, canDropInColumn, applyDrop, rulesRemainOrdered, withFieldNumbers };
});

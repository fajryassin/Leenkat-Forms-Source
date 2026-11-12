(function (root, factory) {
    const rules = factory();
    if (typeof module === 'object' && module.exports) module.exports = rules;
    if (root) root.lkfRules = rules;
})(typeof window !== 'undefined' ? window : null, function () {
    'use strict';
    const normalizeText = value => value.replace(/\r\n?/g,'\n').replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g,'');
    const whitespace = /[\u0009-\u000d\u0020\u0085\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000]+/u;
    const sourceAllowed = field => ['text','textarea','email','phone','url','number','number_slider','select','radio','rating','checkbox','checkboxes','consent','name','date','time'].includes(field.type);
    function normalizeSource(field,value) {
        if(!sourceAllowed(field))return null;
        if(['checkbox','consent'].includes(field.type))return [true,1,'1'].includes(value)?'1':'';
        if(field.type==='checkboxes' || field.type==='select' && field.settings?.multiple)return Array.isArray(value)?value.filter(item=>typeof item==='string'):[];
        if(['select','radio','rating'].includes(field.type))return typeof value==='string'?value:'';
        if(field.type==='name') {
            const format=field.settings?.name_format || 'first_last';
            const parts=format==='simple'?['first']:format==='first_middle_last'?['first','middle','last']:['first','last'];
            return parts.map(part=>typeof value?.[part]==='string'?normalizeText(value[part]):'').filter(Boolean).join(' ');
        }
        return typeof value==='string'?normalizeText(value):'';
    }
    function normalizeValues(fields,values) { return Object.fromEntries(fields.filter(sourceAllowed).map(field=>[field.id,normalizeSource(field,values[field.id])])); }
    function textLength(value,unit='characters') { value=normalizeText(value);return unit==='words'?value.split(whitespace).filter(Boolean).length:Array.from(value).length; }
    function validUnicode(value) { return !Array.from(value).some(character=>{const point=character.codePointAt(0);return point>=0xd800&&point<=0xdfff;}); }
    function condition(rule, values) {
        if (!rule.source_field_id) return false;
        const value = values[rule.source_field_id];
        if(Array.isArray(value)) {
            if(rule.operator==='is_empty')return value.length===0;
            if(rule.operator==='not_empty')return value.length>0;
            const positive=rule.operator==='not_equals'?'equals':rule.operator==='not_contains'?'contains':rule.operator;
            const matches=value.some(item=>condition({...rule,operator:positive},{[rule.source_field_id]:item}));
            return ['not_equals','not_contains'].includes(rule.operator)?!matches:matches;
        }
        const actual = value === true ? '1' : (value === false || value == null ? '' : String(value));
        const expected = String(rule.value ?? '');
        const numeric = text => {
            const value=normalizeText(text),match=/^[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:e[+-]?[0-9]+)?$/i.exec(value);
            // JS $ also matches before a final Unicode line separator; require the entire value.
            return !!match && match[0]===value && Number.isFinite(Number(value));
        };
        switch (rule.operator) {
            case 'equals': return actual === expected;
            case 'not_equals': return actual !== expected;
            case 'is_empty': return actual === '';
            case 'not_empty': return actual !== '';
            case 'contains': return actual.includes(expected);
            case 'not_contains': return !actual.includes(expected);
            case 'starts_with': return actual.startsWith(expected);
            case 'ends_with': return actual.endsWith(expected);
            case 'greater_than': return numeric(actual) && numeric(expected) && Number(actual) > Number(expected);
            case 'less_than': return numeric(actual) && numeric(expected) && Number(actual) < Number(expected);
            default: return false;
        }
    }
    function visible(fieldId, rules, values, fields=null) {
        return rules.every(rule => {
            if (rule.field_id !== fieldId) return true;
            if(fields!==null){const allowed=fields.filter(sourceAllowed).map(field=>field.id);if((rule.groups || [[rule]]).some(group=>group.some(clause=>!allowed.includes(clause.source_field_id))))return false;}
            const matches = Array.isArray(rule.groups) ? rule.groups.some(group => group.every(item => condition(item, values))) : condition(rule, values);
            return rule.action === 'hide' ? !matches : matches;
        });
    }
    return { condition, visible, normalizeText, sourceAllowed, normalizeSource, normalizeValues, textLength, validUnicode };
});

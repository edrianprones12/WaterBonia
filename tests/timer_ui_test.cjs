'use strict';
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
class Node {
    constructor(dataset={}) { this.dataset=dataset;this.hidden=false;this.disabled=false;this.textContent='';this.style={};this.children=[];this.listeners={};this.attributes={};this.classList={toggle(){},remove(){}}; }
    addEventListener(type,fn){this.listeners[type]=fn;}
    setAttribute(key,value){this.attributes[key]=value;} removeAttribute(key){delete this.attributes[key];}
    replaceChildren(){this.children=[];} appendChild(child){this.children.push(child);}
    querySelector(){return this.input || null;}
}
const ready=()=>({can_dispense:true,device_online:true,telemetry_valid:true,sensor_status:'ok',container_detected:true,pump_status:false,safety_blocked:false,
    has_session:false,has_pending_command:false,presets:{cup:{duration_ms:1000,nominal_target_ml:250},tumbler:{duration_ms:2000,nominal_target_ml:500}}});
async function fixture(initial=ready()) {
    const nodes=new Map(), intervals=[], requests=[]; let state=initial,fail=false,now=10000;
    const el=id=>{if(!nodes.has(id))nodes.set(id,new Node());return nodes.get(id);};
    const cards=['cup','tumbler'].map(type=>{const c=new Node({container:type});c.input=new Node();c.input.value=type;return c;});
    const panels=[1,2,3,4].map(panel=>new Node({panel})),steps=[1,2,3,4].map(step=>new Node({step}));
    const document={hidden:false,getElementById:el,createElement:()=>new Node(),addEventListener(){},querySelectorAll(selector){return selector==='.container-card'?cards:selector==='.dispense-panel'?panels:selector==='.dispense-step'?steps:[];}};
    const context={document,window:{WaterBoniaDispenser:{statusUrl:'/status',csrfToken:'test',targets:{cup:250,tumbler:500}}},crypto:{randomUUID:()=> '01234567-0123-0123-0123-0123456789ab'},AbortController,Date:{now:()=>now},
        setInterval(fn){intervals.push(fn);},setTimeout(){return 1;},clearTimeout(){},async fetch(url,opts){
            if(fail)throw Error('offline');
            const body=opts.body?JSON.parse(opts.body):null; requests.push(body);
            if(body){state={...state,has_pending_command:true,pending_command:{id:77,container_type:body.container_type,duration_ms:1000,target_amount_ml:250},request_command:{id:77,status:'pending'}};return {ok:true,json:async()=>({success:true,command_id:77})};}
            return {ok:true,json:async()=>({success:true,state,activities:[]})};
        }};
    vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/timer-dispenser.js'),'utf8'),context);
    const flush=async()=>{for(let i=0;i<15;i++)await Promise.resolve();};await flush();
    return {el,requests,panels,async select(type){cards.find(c=>c.dataset.container===type).input.listeners.change();await flush();},
        async click(id){await el(id).listeners.click?.();await flush();},async update(next){state=next;intervals[0]();await flush();},
        async offline(){fail=true;intervals[0]();await flush();},async age(ms){now+=ms;intervals[0]();await flush();}};
}
(async()=>{
    let count=0;const check=(ok)=>{assert.ok(ok);count++;};
    const env=await fixture();check(env.el('dispense-btn').disabled);await env.select('cup');check(!env.el('dispense-btn').disabled);
    await env.click('dispense-btn');const sent=env.requests.find(Boolean);check(sent.action==='request_dispense' && sent.container_type==='cup' && !!sent.request_id && !('duration_ms' in sent) && !('threshold_ml' in sent));
    check(!env.panels[2].hidden && env.el('dispense-btn').disabled);
    const running={...ready(),has_session:true,has_pending_command:true,session:{id:1,container_type:'cup',configured_duration_ms:1000,target_amount_ml:250},pending_command:{id:77},request_command:{id:77,session_id:1,status:'running'},active_command_id:77,pump_elapsed_ms:1000,pump_status:true};
    await env.update(running);check(!env.panels[2].hidden && env.panels[3].hidden);check(env.el('progress-percent').textContent!=='100%');
    await env.update({...ready(),request_command:{id:77,session_id:1,status:'completed'},last_session:{id:1,status:'completed',container_type:'cup',target_amount_ml:250,pump_elapsed_ms:1001,stop_reason:'duration_elapsed'}});
    check(!env.panels[3].hidden);check(env.el('complete-detail').textContent.includes('not measured'));check(env.el('co-measured').textContent==='1.001 s');
    await env.click('restart-btn');check(!env.panels[0].hidden);await env.select('cup');check(!env.el('dispense-btn').disabled);
    await env.offline();check(env.el('dispense-btn').disabled);check(env.el('device-status-container').textContent==='UNKNOWN');
    const unavailable=await fixture({...ready(),can_dispense:false});await unavailable.select('cup');await unavailable.click('dispense-btn');check(!unavailable.requests.some(Boolean));
    const guest=await fixture();await guest.select('cup');await guest.click('dispense-btn');check(guest.requests.some(Boolean));
    const unknown=await fixture({...ready(),container_detected:null,telemetry_valid:false,sensor_status:'unavailable'});await unknown.select('cup');check(unknown.el('dispense-btn').disabled);
    const safetyAlert=await fixture({...ready(),safety_blocked:true,safety_message:'Review the previous interruption.'});
    await safetyAlert.select('cup');
    check(safetyAlert.el('device-status-container').textContent==='DETECTED');
    check(safetyAlert.el('dispense-btn').disabled && safetyAlert.el('dispense-hint').textContent.includes('interruption'));
    await safetyAlert.click('dispense-btn');check(!safetyAlert.requests.some(Boolean));
    const uncalibrated=await fixture({...ready(),presets:{cup:{duration_ms:null}}});await uncalibrated.select('cup');check(uncalibrated.el('dispense-btn').disabled);
    const expired=await fixture({...ready(),request_command:{id:99,status:'expired',container_type:'cup'}});
    check(!expired.panels[3].hidden && expired.el('dispense-btn').disabled);
    await expired.click('restart-btn');check(!expired.panels[0].hidden);
    await expired.select('cup');await expired.click('dispense-btn');check(expired.requests.filter(Boolean).length===1);
    const unrelated=await fixture({...ready(),request_command:{id:1,session_id:1,status:'completed'},last_session:{id:2,status:'completed'}});
    check(unrelated.panels[3].hidden);
    console.log(`PASS: ${count} real controller DOM/workflow checks. No real network, database or hardware.`);
})().catch(e=>{console.error(e);process.exitCode=1;});

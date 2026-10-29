'use strict';
const assert = require('node:assert/strict');
const { blockReason } = require('../assets/js/timer-dispenser.js');
const ready = { can_dispense:true, device_online:true, telemetry_valid:true, sensor_status:'ok', container_detected:true,
    pump_status:false, safety_blocked:false, has_session:false, has_pending_command:false, presets:{cup:{duration_ms:1000},tumbler:{duration_ms:30000}} };
let count=0;
function blocked(change, type='cup', age=0) { assert.ok(blockReason({...ready,...change},type,age)); count++; }
assert.equal(blockReason(ready,'cup',0),null); count++;
assert.equal(blockReason(ready,'tumbler',0),null); count++;
blocked({can_dispense:false}); blocked({device_online:false}); blocked({telemetry_valid:false}); blocked({sensor_status:'unavailable'});
blocked({sensor_status:'fault'}); blocked({container_detected:false}); blocked({container_detected:null}); blocked({pump_status:true});
blocked({has_session:true}); blocked({has_pending_command:true}); blocked({safety_blocked:true}); blocked({},null); blocked({},'bottle'); blocked({},'cup',6001);
for (const duration_ms of [null,0,-1,999,30001,1000.5,'1000',NaN]) blocked({presets:{cup:{duration_ms}}});
console.log(`PASS: ${count} real timer readiness cases. No hardware or network access.`);

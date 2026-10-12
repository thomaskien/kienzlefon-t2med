// Run with Node + playwright. All HTTP is mocked; no APS, AMI, GDT or real calls.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const root = path.resolve(__dirname,'../..');
const patient = (n,first,last) => ({patient_number:n,first_name:first,last_name:last,birth_date:'19800102',address:'Musterstraße 1'});
const call = (id,state,patients,extensions=[],transferExtensions=[]) => ({id,state,number:'030555010'+id,extensions,transfer_extensions:transferExtensions,duration_seconds:143,channel_age_seconds:143,name_status:patients.length > 1 ? 'ambiguous' : patients.length ? 'resolved' : 'not_found',patients,lookup_id:'lookup-'+id});
const fixture = () => ({generated_at:new Date().toISOString(),ami_connected:true,stale:false,calls:[call('1','connected',[patient(90001,'Mara','Berger')],['201'],['201']),call('2','waiting',[patient(90002,'Clara','Sommer')],[],['201']),call('3','waiting',[patient(90003,'Paul','Becker'),patient(90004,'Anna','Becker')])],queues:[{name:'Praxis',waiting_count:2,entries:[{call_id:'3',position:2,wait_seconds:31},{call_id:'2',position:1,wait_seconds:74}]}],extensions:[{id:'201',label:'Empfang',state:'busy'},{id:'202',label:'Zimmer',state:'idle'},{id:'203',label:'Labor',state:'idle'}],outgoing:[],transfers:[]});
(async () => {
  const browser = await chromium.launch({headless:true, ...(process.env.UI_BROWSER_CHANNEL ? {channel:process.env.UI_BROWSER_CHANNEL} : {})}); let passed = 0;
  async function run(name,fn) {
    const context = await browser.newContext({viewport:{width:250,height:190},hasTouch:name.startsWith('Touch')}), page = await context.newPage();
    const state = {data:fixture(),posts:[],offline:false,lookup:null,errors:[],transferDelay:0,transferFailures:0};
    page.on('pageerror',e => state.errors.push(String(e)));
    await page.route('http://kienzlefon.test/**',async route => {
      const req = route.request(), url = new URL(req.url());
      if (url.pathname.startsWith('/api/')) {
        if (req.method() === 'POST') state.posts.push({path:url.pathname,body:req.postDataJSON()});
        if (url.pathname === '/api/v1/bootstrap') return route.fulfill({json:{token:'demo-token',demo:true,version:'1.3.1',clients:[{id:'empfang-1',label:'Empfang',extension:'201'},{id:'zimmer-2',label:'Zimmer',extension:'202'}]}});
        if (url.pathname === '/api/v1/telephony') return state.offline ? route.abort() : route.fulfill({json:state.data});
        if (url.pathname === '/api/v1/lookup') return route.fulfill({json:state.lookup || {number:'0305550103',lookup_id:'search',status:'ambiguous',patients:fixture().calls[2].patients}});
        if (url.pathname === '/api/v1/patient-open') return route.fulfill({json:{message:'Demo: Akte geöffnet'}});
        if (url.pathname === '/api/v1/calls') return route.fulfill({status:202,json:{phase:'demo'}});
        if (url.pathname === '/api/v1/transfers') {
          if (state.transferDelay) await new Promise(resolve => setTimeout(resolve,state.transferDelay));
          if (state.transferFailures) { state.transferFailures--; return route.fulfill({status:503,json:{error:'Testfehler'}}); }
          const body=req.postDataJSON(), job={...body,extension:'201',phase:body.mode === 'attended' ? 'consulting' : 'requested',message:body.mode === 'attended' ? 'Rückfrage verbunden' : 'Ziel wird gerufen',can_cancel:body.mode === 'attended',can_complete:body.mode === 'attended',created_at:new Date().toISOString()};
          state.data.transfers=state.data.transfers.filter(item=>item.request_id!==job.request_id).concat(job); return route.fulfill({status:202,json:job});
        }
        const transferAction=url.pathname.match(/^\/api\/v1\/transfers\/([^/]+)\/(complete|cancel)$/);
        if (transferAction) {
          const job=state.data.transfers.find(item=>item.request_id===transferAction[1]), updated={...job,phase:transferAction[2] === 'complete' ? 'completing' : 'cancelling',message:transferAction[2] === 'complete' ? 'Abschluss angefordert' : 'Rückkehr angefordert',can_cancel:false,can_complete:false};
          state.data.transfers=state.data.transfers.map(item=>item.request_id===updated.request_id ? updated : item); return route.fulfill({status:202,json:updated});
        }
      }
      const file = ({'/':'index.html','/waehlen':'index.html','/patient-oeffnen':'index.html','/app.js':'app.js','/style.css':'style.css','/favicon.svg':'favicon.svg'})[url.pathname];
      if (!file) return route.fulfill({status:404,body:''});
      return route.fulfill({body:await fs.readFile(path.join(root,'public',file)),contentType:file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'text/html'});
    });
    const go = (suffix='/?view=compact&client_id=empfang-1') => page.goto('http://kienzlefon.test'+suffix).then(() => page.waitForFunction(() => document.querySelector('#compact-counts').textContent.includes('2 warten')));
    try { await fn({page,state,go,context}); assert.deepEqual(state.errors,[]); passed++; console.log('OK '+name); } finally { await context.close(); }
  }
  try {
    await run('250 × 190: separate conversations and queue, own call, demo and footer fit',async ({page,go}) => {
      await go(); assert.equal(await page.locator('#compact-own .compact-name-text').innerText(),'Mara Berger');
      assert.equal(await page.locator('#compact-own .compact-dob').innerText(),'02.01.1980');
      assert.equal(await page.locator('#compact-own .compact-dob').evaluate(node=>getComputedStyle(node).fontWeight),'400');
      assert.notEqual(await page.locator('#compact-own .compact-name').evaluate(node=>getComputedStyle(node).fontWeight),'400');
      assert.equal(await page.getByRole('region',{name:'Gespräche',exact:true}).getByRole('heading',{name:'Gespräche',exact:true}).isVisible(),true);
      assert.equal(await page.getByRole('region',{name:'Warteschlange',exact:true}).getByRole('heading',{name:'Warteschlange',exact:true}).isVisible(),true);
      assert.equal(await page.locator('#compact-talks .compact-call').count(),1);
      assert.equal(await page.locator('#compact-queue .compact-call').count(),2);
      assert.deepEqual(await page.locator('#compact-waiting .row-mark').allTextContents(),['1','2']);
      assert.match(await page.title(),/^DEMO · 2 warten · 1 Gespräch/);
      assert.equal(await page.locator('#compact-demo').isVisible(),true);
      const bounds = await page.locator('.compact-foot').boundingBox(); assert.ok(bounds.y+bounds.height <= 190,JSON.stringify(bounds));
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      if (process.env.UI_SCREENSHOT) { await page.screenshot({path:process.env.UI_SCREENSHOT}); await page.setViewportSize({width:420,height:190}); await page.screenshot({path:process.env.UI_SCREENSHOT.replace('.png','-420.png')}); }
    });
    await run('Ambiguity needs explicit patient; readable dates and full details',async ({page,state,go}) => {
      await go(); await page.locator('#compact-waiting .ambiguous-name').click();
      assert.equal(state.posts.length,0);
      const detail = page.locator('#compact-waiting .compact-detail:not([hidden])');
      assert.match(await detail.innerText(),/02\.01\.1980/); assert.match(await detail.innerText(),/Musterstraße/);
      await detail.getByRole('button',{name:/Anna Becker/}).click();
      await page.waitForFunction(() => !document.querySelector('#toast').hidden);
      assert.equal(state.posts.filter(p => p.path.endsWith('patient-open')).length,1);
      assert.equal(state.posts.at(-1).body.patient_number,90004);
    });
    await run('All calls stay expanded for this tab session, including reload and view changes',async ({page,state,go}) => {
      state.data.calls.push(call('4','connected',[patient(90005,'Mia','Klein')],['202']),call('5','ringing',[],['203']));
      await go(); const toggle=page.locator('#conversations-toggle'), list=page.locator('#compact-all-calls');
      assert.match(await toggle.innerText(),/Alle Gespräche \(3\)/); assert.equal(await toggle.getAttribute('aria-expanded'),'false');
      await toggle.click(); assert.deepEqual(await list.locator('.row-mark').allTextContents(),['202','203']);
      assert.equal(await toggle.innerText(),'Eigene Gespräche ▴'); assert.equal(await toggle.getAttribute('aria-label'),'Eigene Gespräche anzeigen');
      assert.equal(await page.locator('#compact-own .compact-call').count(),1); assert.equal(await list.locator('.compact-call').count(),2);
      assert.equal(await page.locator('#compact-talks .compact-call').count(),3); assert.equal(await page.locator('#compact-queue .compact-call').count(),2);
      if (process.env.UI_SCREENSHOT) await page.screenshot({path:process.env.UI_SCREENSHOT.replace('.png','-all-calls.png'),fullPage:true});
      await list.locator('.compact-name',{hasText:'Mia Klein'}).click(); await page.waitForFunction(() => !document.querySelector('#toast').hidden);
      assert.equal(state.posts.at(-1).body.patient_number,90005);
      await page.evaluate(() => poll()); assert.equal(await toggle.getAttribute('aria-expanded'),'true');
      await page.getByRole('button',{name:'Übersicht ↗'}).click(); await page.getByRole('button',{name:'Kompaktansicht',exact:true}).click();
      assert.equal(await toggle.getAttribute('aria-expanded'),'true');
      await page.reload(); await page.waitForFunction(() => document.querySelector('#compact-counts').textContent.includes('2 warten'));
      assert.equal(await toggle.getAttribute('aria-expanded'),'true'); assert.equal(await list.locator('.compact-call').count(),2);
      assert.equal(await toggle.innerText(),'Eigene Gespräche ▴');
      await toggle.click(); assert.match(await toggle.innerText(),/Alle Gespräche \(3\)/);
      assert.equal(await page.locator('#compact-conversations').isVisible(),false); assert.equal(await page.locator('#compact-own .compact-call').count(),1);
      assert.equal(await page.locator('#compact-queue .compact-call').count(),2); await toggle.click();
      state.data.calls=state.data.calls.filter(c=>c.id!=='4'); await page.evaluate(() => poll());
      assert.equal(await list.locator('.compact-call').count(),1); assert.equal(await toggle.getAttribute('aria-expanded'),'true');
      state.offline=true; await page.evaluate(() => poll()); assert.equal(await list.locator('.compact-call').count(),0); assert.match(await list.innerText(),/nicht verfügbar/);
      state.offline=false; state.data.calls=[]; state.data.queues=[]; await page.evaluate(() => poll()); assert.match(await list.innerText(),/keine Gespräche/);
      await toggle.click(); await page.reload(); await page.waitForFunction(() => document.querySelector('#compact-counts').textContent.includes('0 warten'));
      assert.equal(await toggle.getAttribute('aria-expanded'),'false');
      assert.match(await toggle.innerText(),/Alle Gespräche/); assert.match(await page.locator('#compact-own').innerText(),/Keine eigenen Gespräche/);
      await page.locator('#menu-toggle').click(); assert.match(await page.locator('#app-version').innerText(),/Version 1\.3/);
    });
    await run('Polling retains keyboard focus and expanded details',async ({page,state,go}) => {
      await go(); const toggle = page.locator('#compact-waiting .compact-row .icon-button').first(); await toggle.click(); await toggle.focus();
      await page.evaluate(() => { window.focusSentinel=document.activeElement; });
      state.data.queues[0].entries[1].wait_seconds=90; await page.evaluate(() => poll());
      assert.ok(await page.evaluate(() => document.activeElement === window.focusSentinel));
      assert.equal(await toggle.getAttribute('aria-expanded'),'true');
      await page.locator('#menu-toggle').click(); await page.locator('#number').fill('0176123');
      await page.evaluate(() => poll()); assert.equal(await page.locator('#number').inputValue(),'0176123');
      assert.equal(await page.locator('#number').evaluate(n => n===document.activeElement),true);
    });
    await run('Transfer arrows require own connected call and explicit server permission',async ({page,state,go}) => {
      state.data.calls.push(call('4','connected',[patient(90005,'Ohne','Freigabe')],['201']),call('5','ringing',[],['201'],['201']),call('6','connected',[],['202'],['202']));
      await go();
      assert.equal(await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').count(),1);
      assert.equal(await page.locator('#compact-waiting [aria-label="Gespräch vermitteln"]').count(),0);
      await page.getByRole('button',{name:'Übersicht ↗'}).click();
      assert.equal(await page.locator('#calls [aria-label="Gespräch vermitteln"]').count(),1);
    });
    await run('Internal direct transfer and external attended transfer use bound call and explicit modes',async ({page,state,go}) => {
      await page.setViewportSize({width:420,height:500}); await go();
      await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').click();
      assert.equal(await page.locator('#transfer-dialog').isVisible(),true); assert.equal(state.posts.length,0);
      if (process.env.UI_SCREENSHOT) await page.screenshot({path:process.env.UI_SCREENSHOT.replace('.png','-transfer.png')});
      assert.deepEqual(await page.locator('#transfer-extension option').allTextContents(),['Nebenstelle auswählen','202 · Zimmer','203 · Labor']);
      await page.locator('#transfer-extension').selectOption('202'); await page.getByRole('button',{name:'Direkt vermitteln'}).click();
      await page.waitForFunction(() => document.querySelectorAll('#compact-transfer-jobs .transfer-job').length === 1);
      let transferPosts=state.posts.filter(item=>item.path==='/api/v1/transfers'); assert.equal(transferPosts.length,1);
      assert.deepEqual({...transferPosts[0].body,request_id:'UUID'},{client_id:'empfang-1',call_id:'1',target_type:'internal',target:'202',mode:'blind',request_id:'UUID'});
      assert.match(await page.locator('#compact-transfer-jobs').innerText(),/Direktvermittlung · Nebenstelle 202/);
      await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').click();
      await page.locator('input[name="target_type"][value="external"]').check(); await page.locator('#transfer-number').fill('+49 30 12345');
      await page.getByRole('button',{name:'Rückfrage starten'}).click(); await page.waitForFunction(() => document.querySelectorAll('#compact-transfer-jobs .transfer-job').length === 2);
      transferPosts=state.posts.filter(item=>item.path==='/api/v1/transfers'); assert.equal(transferPosts.length,2);
      assert.equal(transferPosts[1].body.target_type,'external'); assert.equal(transferPosts[1].body.target,'+49 30 12345'); assert.equal(transferPosts[1].body.mode,'attended');
      await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').click(); await page.locator('[data-action="transfer-close"]').click();
      assert.equal(state.posts.filter(item=>item.path.endsWith('/cancel')).length,0);
    });
    await run('Attended actions follow server flags and update immediately',async ({page,state,go}) => {
      const base={request_id:'job-one',client_id:'empfang-1',call_id:'gone',extension:'201',target_type:'internal',target:'202',mode:'attended',phase:'consulting',message:'Rückfrage verbunden',can_cancel:true,can_complete:true,created_at:'2026-10-12T10:00:00Z'};
      state.data.transfers=[base,{...base,request_id:'job-unknown',phase:'unknown',message:'',can_cancel:false,can_complete:false,created_at:'2026-10-12T09:00:00Z'},{...base,request_id:'foreign-job',client_id:'zimmer-2',created_at:'2026-10-12T12:00:00Z'}]; await go();
      assert.equal(await page.locator('#compact-transfer-jobs .transfer-job').count(),2);
      assert.match(await page.locator('#compact-transfer-jobs').innerText(),/Status ungewiss/);
      const first=page.locator('#compact-transfer-jobs .transfer-job').first();
      await first.getByRole('button',{name:'Vermitteln abschließen'}).click(); await page.waitForFunction(() => document.querySelector('#compact-transfer-jobs').textContent.includes('Abschluss angefordert'));
      assert.equal(state.posts.filter(item=>item.path.endsWith('/complete')).length,1);
      assert.match(state.posts.at(-1).body.request_id,/^[0-9a-f-]{36}$/); assert.notEqual(state.posts.at(-1).body.request_id,base.request_id);
      state.data.transfers=[{...base,request_id:'job-two',created_at:'2026-10-12T11:00:00Z'}]; await page.evaluate(() => poll());
      await page.locator('#compact-transfer-jobs .transfer-job').first().getByRole('button',{name:'Zurück zum Anrufer'}).click();
      await page.waitForFunction(() => document.querySelector('#compact-transfer-jobs').textContent.includes('Rückkehr angefordert'));
      assert.equal(state.posts.filter(item=>item.path.endsWith('/cancel')).length,1);
      assert.equal(await page.locator('#compact-transfer-jobs button').count(),0);
    });
    await run('Transfer double click is guarded; manual retry is idempotent and reload never replays POST',async ({page,state,go}) => {
      state.transferDelay=120; state.transferFailures=1; await go(); await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').click(); await page.locator('#transfer-extension').selectOption('202');
      await page.evaluate(() => { const button=document.querySelector('[data-action="transfer-start"][data-mode="attended"]'); button.click(); button.click(); });
      await page.waitForFunction(() => !document.querySelector('#transfer-error').hidden); await page.waitForTimeout(180);
      assert.equal(state.posts.filter(item=>item.path==='/api/v1/transfers').length,1); const requestId=state.posts.at(-1).body.request_id;
      await page.getByRole('button',{name:'Rückfrage starten'}).click();
      await page.waitForFunction(() => document.querySelectorAll('#compact-transfer-jobs .transfer-job').length === 1);
      const transferPosts=state.posts.filter(item=>item.path==='/api/v1/transfers'); assert.equal(transferPosts.length,2); assert.equal(transferPosts[1].body.request_id,requestId); assert.match(requestId,/^[0-9a-f-]{36}$/);
      await page.reload(); await page.waitForFunction(() => document.querySelectorAll('#compact-transfer-jobs .transfer-job').length === 1);
      assert.equal(state.posts.filter(item=>item.path==='/api/v1/transfers').length,2);
    });
    await run('Polling keeps transfer input and focus; workplace change, hangup and offline block submit',async ({page,state,go}) => {
      await go(); await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').click(); await page.locator('input[name="target_type"][value="external"]').check();
      await page.locator('#transfer-number').fill('030 9876'); await page.locator('#transfer-number').focus(); await page.evaluate(() => poll());
      assert.equal(await page.locator('#transfer-number').inputValue(),'030 9876'); assert.equal(await page.locator('#transfer-number').evaluate(node=>node===document.activeElement),true);
      await page.evaluate(() => { const select=document.querySelector('#client'); select.value='zimmer-2'; select.dispatchEvent(new Event('change')); });
      assert.equal(await page.getByRole('button',{name:'Rückfrage starten'}).isDisabled(),true); assert.match(await page.locator('#transfer-error').innerText(),/Arbeitsplatz wurde gewechselt/);
      await page.locator('[data-action="transfer-close"]').click(); await page.locator('#menu-toggle').click(); await page.locator('#client').selectOption('empfang-1'); await page.locator('#menu-toggle').click();
      await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').click(); state.data.calls[0].state='ended'; await page.evaluate(() => poll());
      assert.equal(await page.getByRole('button',{name:'Direkt vermitteln'}).isDisabled(),true); assert.match(await page.locator('#transfer-error').innerText(),/nicht mehr verbunden/);
      await page.locator('[data-action="transfer-close"]').click(); state.data.calls[0].state='connected'; await page.evaluate(() => poll()); await page.locator('#compact-own [aria-label="Gespräch vermitteln"]').click();
      state.offline=true; await page.evaluate(() => poll()); assert.equal(await page.getByRole('button',{name:'Rückfrage starten'}).isDisabled(),true); assert.match(await page.locator('#transfer-error').innerText(),/nicht aktuell/);
      assert.equal(state.posts.filter(item=>item.path==='/api/v1/transfers').length,0);
    });
    await run('Removing a preceding queue entry does not blur the remaining row',async ({page,state,go}) => {
      await go(); const toggle=page.locator('#compact-waiting .compact-row .icon-button').last(); await toggle.focus();
      await page.evaluate(() => { window.focusSentinel=document.activeElement; });
      state.data.calls=state.data.calls.filter(c=>c.id!=='2'); state.data.queues[0].entries=state.data.queues[0].entries.filter(e=>e.call_id!=='2');
      await page.evaluate(() => poll()); assert.ok(await page.evaluate(() => document.activeElement===window.focusSentinel));
    });
    await run('Connection lost, no stale counts; recovery, empty and hidden numbers',async ({page,state,go}) => {
      await go(); state.offline=true; await page.evaluate(() => poll());
      assert.equal(await page.locator('#compact-offline').isVisible(),true); assert.match(await page.title(),/nicht erreichbar/);
      assert.equal(await page.locator('#compact-own .compact-call').count(),0); assert.match(await page.locator('#compact-counts').innerText(),/– warten/);
      state.offline=false; state.data.calls[1].number=''; state.data.calls[1].patients=[]; state.data.calls[1].name_status='not_found'; await page.evaluate(() => poll());
      assert.match(await page.locator('#compact-waiting').innerText(),/Rufnummer unterdrückt/);
      state.data.calls=[]; state.data.queues=[]; await page.evaluate(() => poll()); assert.match(await page.locator('#compact-waiting').innerText(),/Niemand wartet/);
    });
    await run('Many waiting calls scroll independently; full overview and workplace tools',async ({page,state,go}) => {
      state.data.calls.push(...Array.from({length:15},(_,i) => call(String(i+10),'waiting',[])));
      state.data.queues[0].entries.push(...state.data.calls.slice(3).map((c,i) => ({call_id:c.id,position:i+3,wait_seconds:20})));
      await go(); assert.ok(await page.locator('#compact-waiting').evaluate(n => n.scrollHeight>n.clientHeight));
      assert.equal(await page.locator('#compact-own').isVisible(),true);
      await page.getByRole('button',{name:'Übersicht ↗'}).click(); assert.equal(await page.locator('.columns').isVisible(),true);
      if (process.env.UI_SCREENSHOT) { await page.setViewportSize({width:1200,height:900}); await page.screenshot({path:process.env.UI_SCREENSHOT.replace('.png','-full.png'),fullPage:true}); }
      await page.getByRole('button',{name:'Kompaktansicht',exact:true}).click(); await page.locator('#menu-toggle').click();
      await page.getByRole('button',{name:'Telefonie-App einrichten'}).click(); assert.match(await page.locator('#setup-command').innerText(),/empfang-1/);
    });
    await run('Immediate dial submits once, with correct mapping, no reload/back replay',async ({page,state,go}) => {
      await go('/waehlen?client_id=empfang-1&telefon=%2B493012345&sofort=1');
      await page.waitForFunction(() => !document.querySelector('#toast').hidden);
      assert.equal(state.posts.filter(p => p.path.endsWith('/calls')).length,1); assert.equal(state.posts[0].body.extension,'201'); assert.equal(state.posts[0].body.number,'+493012345');
      assert.equal(await page.locator('#dial-dialog').isVisible(),false); assert.ok(!page.url().includes('telefon='));
      await page.reload(); await page.waitForFunction(() => document.querySelector('#compact-counts').textContent.includes('2 warten'));
      await page.goto('http://kienzlefon.test/'); await page.goBack(); await page.waitForTimeout(100);
      assert.equal(state.posts.filter(p => p.path.endsWith('/calls')).length,1);
    });
    await run('Hidden view still polls, but immediate link waits for visibility',async ({page,state,go}) => {
      await page.addInitScript(() => { window.testHidden=true; Object.defineProperty(document,'hidden',{get:()=>window.testHidden}); });
      await go('/waehlen?client_id=empfang-1&telefon=03012345&sofort=1'); assert.equal(state.posts.length,0);
      state.data.queues[0].waiting_count=4; await page.evaluate(() => poll()); assert.match(await page.title(),/4 warten/);
      await page.evaluate(() => { window.testHidden=false; document.dispatchEvent(new Event('visibilitychange')); });
      await page.waitForFunction(() => !document.querySelector('#toast').hidden); assert.equal(state.posts.length,1);
    });
    await run('Normal dial requires confirmation; local extension mismatch blocks link',async ({page,state,go}) => {
      await go('/waehlen?client_id=empfang-1&telefon=03012345&expected_extension=201');
      assert.equal(await page.locator('#dial-dialog').isVisible(),true); assert.equal(state.posts.length,0);
      await page.getByRole('button',{name:'Jetzt anrufen'}).click(); await page.waitForFunction(() => !document.querySelector('#toast').hidden);
      assert.equal(state.posts.length,1);
      await go('/waehlen?client_id=empfang-1&telefon=03012345&expected_extension=999&sofort=1');
      assert.match(await page.locator('#toast').innerText(),/Registrierung/); assert.equal(state.posts.length,1);
    });
    await run('Patient link: automatic unique GDT, ambiguous selection, no reload replay',async ({page,state,go}) => {
      state.lookup={number:'03012345',lookup_id:'search',status:'resolved',patients:[patient(90009,'Test','Person')]};
      await go('/patient-oeffnen?client_id=empfang-1&telefon=03012345'); await page.waitForFunction(() => !document.querySelector('#toast').hidden);
      assert.equal(state.posts.filter(p => p.path.endsWith('patient-open')).length,1);
      await page.reload(); await page.waitForFunction(() => document.querySelector('#compact-counts').textContent.includes('2 warten')); assert.equal(state.posts.filter(p => p.path.endsWith('patient-open')).length,1);
      state.lookup=null; await go('/patient-oeffnen?client_id=empfang-1&telefon=03012345'); await page.locator('#patients .patient').first().waitFor();
      assert.equal(state.posts.filter(p => p.path.endsWith('patient-open')).length,1); assert.equal(await page.locator('#patients .patient').count(),2);
    });
    await run('Each confirmed patient click is a new GDT request, even when returning immediately',async ({page,state,go}) => {
      await go();
      for (const section of ['#compact-own','#compact-waiting','#compact-own']) {
        await page.locator(section+' .compact-name[data-action=patient]').first().click();
        await page.waitForFunction(() => opening.size === 0);
      }
      const posts=state.posts.filter(p => p.path.endsWith('patient-open'));
      assert.deepEqual(posts.map(p=>p.body.patient_number),[90001,90002,90001]);
      assert.equal(new Set(posts.map(p=>p.body.request_id)).size,3);
    });
    await run('Touch targets and date formats',async ({page,go}) => {
      await go(); assert.ok((await page.locator('#menu-toggle').boundingBox()).height >= 44); assert.ok((await page.locator('#compact-own .compact-name').boundingBox()).height >= 44); assert.deepEqual(await page.evaluate(() => ['19800102','02011980','1980-01-02','19800231'].map(birthDate)),['02.01.1980','02.01.1980','02.01.1980','19800231']);
    });
    console.log(`${passed} UI scenarios passed`);
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode=1; });

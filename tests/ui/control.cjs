// Mocked contract server; no patient backend or telephone calls.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
(async()=>{
 const fixtures=JSON.parse(await fs.readFile(path.join(root,'docs/telefonie-v1/fixtures.json'),'utf8')).fixtures;
 const source=fixtures.find(x=>x.name==='overview-current').body;
 const browser=await chromium.launch({headless:true,channel:process.env.UI_BROWSER_CHANNEL||'chrome'});
 let passed=0;
 async function run(name,fn){
  const context=await browser.newContext({viewport:{width:1280,height:900}}),page=await context.newPage();
  const state={data:structuredClone(source),posts:[],jobs:new Map(),errors:[],offline:false,hold:false,lose:false,reads:0};
  Object.assign(state.data,{ami_connected:true,stale:false,calls:[],queues:[],outgoing:[],transfers:[]});
  state.data.call_statistics.today.status='partial';state.data.call_statistics.today.reason_code='capture_gap';
  state.data.extensions[0].routing.permissions.specialist={can_enable:false,can_disable:false,enable_reason:'admin_locked',disable_reason:'admin_locked'};
  page.on('pageerror',e=>state.errors.push(String(e)));
  await page.route('http://kienzlefon.test/**',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname==='/api/v1/bootstrap')return route.fulfill({json:{token:'browser-token',demo:false,version:'1.5.0',author:'Dr. Thomas Kienzle',clients:[{id:'empfang-1',label:'Empfang',extension:'201'}]}});
   if(url.pathname==='/api/v1/telephony'){
    if(state.offline)return route.abort();if(state.hold)return;
    state.data.generated_at=new Date().toISOString();return route.fulfill({json:state.data});
   }
   if(req.method()==='POST'){
    const command=req.postDataJSON();state.posts.push({path:url.pathname,command});
    const job={...command,state:'accepted',created_at:new Date().toISOString(),updated_at:new Date().toISOString(),applied_at:null,result_revision:null,changed:null,error:null};state.jobs.set(command.request_id,job);
    if(state.lose)return route.abort();return route.fulfill({status:202,json:{request:job,error:null}});
   }
   if(url.pathname.includes('/extension-routing/requests/')){state.reads++;const job=state.jobs.get(url.pathname.split('/').pop());return route.fulfill({json:{request:job,error:job?.error||null}});}
   const file={'/':'index.html','/app.js':'app.js','/style.css':'style.css','/favicon.svg':'favicon.svg'}[url.pathname];
   if(!file)return route.fulfill({status:404,body:''});
   return route.fulfill({body:await fs.readFile(path.join(root,'public',file)),contentType:file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':'text/html'});
  });
  const go=async()=>{await page.goto('http://kienzlefon.test/?view=full&client_id=empfang-1');await page.waitForFunction(()=>document.querySelector('#full-live-ai').textContent==='AI: 1');};
  const input=(extension,group)=>page.locator(`#extensions input[data-routing-extension="${extension}"][data-routing-route="${group}"]`);
  try{await fn({page,state,go,input});assert.deepEqual(state.errors,[]);passed++;console.log('OK '+name);}finally{await context.close();}
 }
 try{
  await run('Counters and route tooltip use contract values',async({page,go})=>{
   await go();assert.equal(await page.locator('#full-today').textContent(),'Anrufe heute: ≈47');
   const title=await page.locator('#full-today').getAttribute('title');assert.ok(title.includes('Mehrere Wege pro Anruf möglich'));assert.ok(title.includes('Ungefährer Wert'));assert.ok(!title.includes('undefined'));
   assert.equal(await page.locator('#extensions button input').count(),0);
  });
  await run('Permissions, foreign extension and confirmed state while pending',async({page,state,go,input})=>{
   await go();assert.equal(await input('201','specialist').isDisabled(),true);
   const checkbox=input('202','normal');assert.equal(await checkbox.isEnabled(),true);const previous=await checkbox.isChecked();
   await checkbox.click();await page.waitForFunction(()=>routingPending.size===1);assert.equal(await checkbox.isChecked(),previous);assert.equal(await checkbox.isDisabled(),true);
   assert.equal(state.posts.length,1);assert.equal(state.posts[0].path,'/api/v1/extension-routing');assert.equal(state.posts[0].command.extension,'202');assert.equal(state.posts[0].command.member,!previous);
   const job=[...state.jobs.values()][0];job.state='applied';job.changed=true;job.applied_at=new Date().toISOString();job.result_revision=job.expected_revision;
   state.data.extensions.find(e=>e.id==='202').routing.normal=!previous;
   await page.evaluate(()=>poll());await page.waitForFunction(()=>routingPending.size===0);assert.equal(await checkbox.isChecked(),!previous);
  });
  await run('Lost POST reply persists across reload; only GET recovers',async({page,state,go,input})=>{
   state.lose=true;await go();await input('202','normal').click();await page.waitForFunction(()=>routingPending.size===1);await page.reload();await page.waitForFunction(()=>routingPending.size===1&&routingPolling===false);
   await page.evaluate(()=>poll());assert.equal(state.posts.length,1);assert.ok(state.reads>=1);assert.equal(await input('202','normal').isDisabled(),true);
  });
  await run('Stale cached state expires during a stalled HTTP request',async({page,state,go,input})=>{
   await page.clock.install();await go();state.hold=true;await page.clock.fastForward(11000);
   assert.equal(await input('202','normal').isDisabled(),true);assert.equal(await input('202','normal').evaluate(n=>n.indeterminate),true);
   assert.equal(await page.locator('#full-live-ai').textContent(),'AI: —');assert.ok((await page.locator('#full-today').textContent()).includes('47'));
  });
  await run('Offline source immediately disables routing and clears live numbers',async({page,state,go,input})=>{
   await go();state.offline=true;await page.evaluate(()=>poll());assert.equal(await input('202','normal').isDisabled(),true);assert.equal(await page.locator('#full-live-waiting').textContent(),'Warten: —');
  });
  await run('Compact controls are reachable without nested dial actions',async({page,state,go,input})=>{
   await go();await page.setViewportSize({width:320,height:400});await page.evaluate(()=>setView('compact'));await page.click('#more-toggle');
   const box=page.locator('#compact-extensions input[data-routing-extension="202"][data-routing-route="normal"]');await box.click();assert.equal(state.posts.length,1);assert.equal(state.posts[0].path,'/api/v1/extension-routing');
   if(process.env.UI_CONTROL_SCREENSHOT)await page.screenshot({path:process.env.UI_CONTROL_SCREENSHOT});
  });
  console.log(`${passed} control UI tests passed`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

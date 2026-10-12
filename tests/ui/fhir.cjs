const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const root = path.resolve(__dirname,'../..');
const routePath = '/telefonverzeichnis-aktualisieren.php';
const fields = {kontextId:'context-123',fhirBasisUrl:'https://t2med.test:16567/aps/fhir/api/r4',oAuthToken:'test+with/=and&symbols'};
const params = new URLSearchParams(fields).toString();
const ready = () => ({provider:'fhir',job:{state:'success',pages:4,patient_count:33,total:33,message:'Telefonverzeichnis erfolgreich aktualisiert.'},directory:{available:true,complete:true,patient_count:33,patients_with_phone:32,phone_count:48,suffix_phone_count:43,unusable_phone_count:0,invalid_patient_numbers:0,imported_at:new Date().toISOString()}});
(async () => {
  const browser=await chromium.launch({headless:true,...(process.env.UI_BROWSER_CHANNEL ? {channel:process.env.UI_BROWSER_CHANNEL} : {})});
  let passed=0;
  async function run(name,suffix,check) {
    const context=await browser.newContext({viewport:{width:900,height:900}}),page=await context.newPage();
    const requests=[],posts=[],errors=[];
    page.on('pageerror',e=>errors.push(String(e)));
    await page.route('http://kienzlefon.test/**',async route=>{
      const req=route.request(),url=new URL(req.url());requests.push(req);
      if (url.pathname==='/api/v1/bootstrap')return route.fulfill({json:{token:'csrf',demo:false}});
      if (url.pathname==='/api/v1/fhir/status')return route.fulfill({json:ready()});
      if (url.pathname==='/api/v1/fhir/import') {posts.push(req.postDataJSON());assert.equal(req.headers()['x-kienzlefon-token'],'csrf');return route.fulfill({status:202,json:ready()});}
      const file=({[routePath]:'fhir.html','/telefonverzeichnis-aktualisieren':'fhir.html','/fhir.js':'fhir.js','/fhir.css':'fhir.css','/favicon.svg':'favicon.svg'})[url.pathname];
      if (!file)return route.fulfill({status:404});
      return route.fulfill({body:await fs.readFile(path.join(root,'public',file)),headers:{'Referrer-Policy':'no-referrer','Cache-Control':'no-store'},contentType:file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':'text/html'});
    });
    try {
      await page.goto('http://kienzlefon.test'+routePath+suffix);
      await page.waitForFunction(()=>document.querySelector('#patient-count')?.textContent==='33');
      await check({page,posts,requests});assert.deepEqual(errors,[]);passed++;console.log('OK '+name);
    } finally {await context.close();}
  }
  try {
    for (const delimiter of ['?','#'])await run(delimiter+' transfers once and scrubs credentials',delimiter+params,async({page,posts,requests})=>{
      assert.equal(posts.length,1); const {request_id,...submitted}=posts[0]; assert.match(request_id,/^[a-f0-9-]{36}$/); assert.deepEqual(submitted,fields);assert.equal(page.url(),'http://kienzlefon.test'+routePath);
      assert.equal(await page.locator('#state').innerText(),'Erfolgreich');assert.equal(await page.locator('#phone-patients').innerText(),'32');
      assert.match(await page.locator('#matching-note').innerText(),/43 Telefonwerte ohne Vorwahl/);assert.match(await page.locator('#matching-note').innerText(),/mehreren passenden Patienten/);assert.ok(await page.locator('#warnings').isHidden());
      assert.ok(!(await page.locator('body').innerText()).includes(fields.oAuthToken));
      assert.equal(await page.evaluate(()=>JSON.stringify([Object.entries(localStorage),Object.entries(sessionStorage)])),'[[],[]]');
      for (const req of requests.filter(r=>!r.isNavigationRequest()))assert.ok(!req.url().includes('oAuthToken'));
      await page.reload();await page.waitForFunction(()=>document.querySelector('#patient-count')?.textContent==='33');assert.equal(posts.length,1);
      if (delimiter==='#' && process.env.FHIR_SCREENSHOT)await page.screenshot({path:process.env.FHIR_SCREENSHOT,fullPage:true});
    });
    for (const [name,suffix] of [
      ['missing token','?kontextId=a&fhirBasisUrl=b'],
      ['duplicate parameter','?'+params+'&oAuthToken=second'],
      ['mixed transport','?'+params+'#'+params],
      ['unreplaced placeholders','?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}'],
      ['bad encoding','?'+params+'&extra=%ZZ'],
      ['unencoded plus','?kontextId=a&fhirBasisUrl=b&oAuthToken=a+b'],
    ])await run(name,suffix,async({page,posts})=>{assert.equal(posts.length,0);assert.equal(page.url(),'http://kienzlefon.test'+routePath);assert.ok(await page.locator('#error').isVisible());});
    await run('status only without launch','',async({posts})=>assert.equal(posts.length,0));
    console.log(passed+' browser scenarios passed');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

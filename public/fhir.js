'use strict';
// Capture and scrub before any API request; never keep credentials in storage or the DOM.
(() => {
  const fields = ['kontextId','fhirBasisUrl','oAuthToken'];
  let launch = null, launchError = '', starting = false, timer, pollBusy = false, disposed = false;
  const url = new URL(location.href), query = new URLSearchParams(url.search), fragment = new URLSearchParams(url.hash.slice(1));
  const hasQuery = fields.some(key => query.has(key)), hasFragment = fields.some(key => fragment.has(key));
  if (hasQuery || hasFragment) {
    try {
      if (hasQuery && hasFragment) throw new Error('Übergabe gleichzeitig hinter ? und # ist nicht eindeutig. Bitte nur eine Variante verwenden.');
      const raw = hasFragment ? url.hash.slice(1) : url.search.slice(1);
      for (const part of raw.split('&')) decodeURIComponent(part.replace(/\+/g,' '));
      const params = hasFragment ? fragment : query;
      launch = {};
      for (const key of fields) {
        if (params.getAll(key).length !== 1 || !params.get(key) || params.get(key).includes('${')) throw new Error('T2med-Übergabe unvollständig oder Platzhalter nicht ersetzt. Bitte erneut aus T2med starten.');
        launch[key] = params.get(key);
      }
      if (/\s/.test(launch.oAuthToken)) throw new Error('Token-Übergabe ungültig. Bitte URL-Kodierung im T2med-Aufruf prüfen.');
    } catch (error) { launch = null; launchError = error instanceof URIError ? 'Ungültige URL-Kodierung in der T2med-Übergabe.' : error.message; }
    // Clear both forms, including malformed/duplicate parameters, before loading status.
    history.replaceState(null,'',url.pathname);
    for (const key of fields) { query.delete(key); fragment.delete(key); }
    url.search = ''; url.hash = '';
  }
  const $ = id => document.getElementById(id);
  async function request(path, body, csrf) {
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(),15000);
    try {
      const options = {cache:'no-store',referrerPolicy:'no-referrer',signal:controller.signal};
      if (body) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-Kienzlefon-Token':csrf},body:JSON.stringify(body)});
      const response = await fetch(path,options), result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Anfrage fehlgeschlagen.');
      return result;
    } finally { clearTimeout(timeout); }
  }
  function show(data) {
    if (data.provider !== 'fhir') { $('state').textContent = 'FHIR deaktiviert'; $('message').textContent = 'Dieser Dienst verwendet derzeit keinen FHIR-Patientenbestand.'; return; }
    const job = data.job || {state:'idle'}, directory = data.directory || {};
    const labels = {idle:'Bereit',running:'Abgleich läuft',success:'Erfolgreich',error:'Abgleich fehlgeschlagen'};
    $('state').textContent = labels[job.state] || 'Status unbekannt'; $('state').className = 'badge ' + job.state;
    $('message').textContent = job.message || 'Bereit für den Start aus T2med.';
    $('progress').textContent = job.pages ? `${job.pages} Seiten · ${job.patient_count} Patienten gelesen${job.total != null ? ' von ' + job.total : ''}` : '';
    $('instructions').hidden = job.state === 'running';
    $('retained').hidden = !directory.available || !['error','running'].includes(job.state);
    if (directory.available) {
      const date = new Date(directory.imported_at), minutes = Math.max(0,Math.floor((Date.now()-date.getTime())/60000));
      $('last-sync').textContent = `Vollständig übernommen: ${date.toLocaleString('de-DE')} · ${minutes < 60 ? minutes + ' Minuten' : Math.floor(minutes/60) + ' Stunden'} alt`;
      $('patient-count').textContent = directory.patient_count; $('phone-patients').textContent = directory.patients_with_phone; $('phone-count').textContent = directory.phone_count;
    }
    const localCount = directory.suffix_phone_count || 0;
    $('matching-note').textContent = localCount ? `${localCount} ${localCount === 1 ? 'Telefonwert ohne Vorwahl wird' : 'Telefonwerte ohne Vorwahl werden'} über das vollständige Rufnummernende abgeglichen. Bei mehreren passenden Patienten ist eine Auswahl erforderlich.` : '';
    $('matching-note').hidden = !localCount;
    const warnings = [];
    if (directory.unusable_phone_count) warnings.push(`${directory.unusable_phone_count} ${directory.unusable_phone_count === 1 ? 'Telefonwert gespeichert, aber für die Anruferkennung nicht nutzbar' : 'Telefonwerte gespeichert, aber für die Anruferkennung nicht nutzbar'}.`);
    if (directory.invalid_patient_numbers) warnings.push(`${directory.invalid_patient_numbers} ${directory.invalid_patient_numbers === 1 ? 'Patient' : 'Patienten'} ohne eindeutige Patientennummer: Aktenaufruf gesperrt.`);
    $('warnings').textContent = warnings.join(' '); $('warnings').hidden = !warnings.length;
  }
  async function poll() {
    if (pollBusy || disposed) return; pollBusy = true;
    try { show(await request('/api/v1/fhir/status')); }
    catch { $('error').hidden = false; $('error').textContent = 'Status derzeit nicht erreichbar. Ein bereits gestarteter Abgleich kann weiterlaufen.'; }
    finally { pollBusy = false; if (!disposed) timer = setTimeout(poll,2000); }
  }
  document.addEventListener('DOMContentLoaded',async () => {
    if (launchError) { $('error').textContent = launchError; $('error').hidden = false; }
    if (launch && !starting) {
      starting = true;
      try {
        if (document.prerendering) await new Promise(resolve => document.addEventListener('prerenderingchange',resolve,{once:true}));
        if (document.hidden) await new Promise(resolve => { const visible = () => { if (!document.hidden) { document.removeEventListener('visibilitychange',visible); resolve(); } }; document.addEventListener('visibilitychange',visible); });
        const info = await request('/api/v1/bootstrap');
        if (!launch || disposed) return;
        const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
        launch.request_id = `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
        const pending = request('/api/v1/fhir/import',launch,info.token); launch = null;
        show(await pending);
      } catch (error) { $('error').textContent = error.name === 'AbortError' ? 'Keine Startbestätigung erhalten. Status wird geprüft; bitte nicht sofort erneut starten.' : error.message; $('error').hidden = false; }
      finally { launch = null; }
    }
    poll();
  },{once:true});
  window.addEventListener('pagehide',() => { disposed = true; clearTimeout(timer); launch = null; });
  window.addEventListener('pageshow',event => { if (event.persisted) { disposed = false; poll(); } });
})();

'use strict';
const $ = id => document.getElementById(id);
const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
const labels = {pending:'Patient wird gesucht …', resolved:'Zugeordnet', ambiguous:'Mehrere mögliche Patienten · bitte auswählen', not_found:'Kein Patient gefunden', timeout:'Suche hat zu lange gedauert', error:'Patientensuche derzeit nicht erreichbar', requested:'Auftrag angenommen', ringing_extension:'Nebenstelle klingelt', dialing_target:'Ziel wird gewählt', ringing_target:'Ziel klingelt', connected:'Verbunden', ended:'Beendet', failed:'Nicht verbunden', unknown:'Ergebnis ungewiss · Telefon prüfen', demo:'Demo · kein Anruf ausgelöst'};
let token = '', clients = [], demo = false, polling = false, toastTimer, latestSearch = null;
const opening = new Set(), requestIds = new Map();
const query = new URLSearchParams(location.search);
const uuid = () => { const b = crypto.getRandomValues(new Uint8Array(16)); b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128; const s = Array.from(b, n => n.toString(16).padStart(2,'0')).join(''); return `${s.slice(0,8)}-${s.slice(8,12)}-${s.slice(12,16)}-${s.slice(16,20)}-${s.slice(20)}`; };
const seconds = n => n == null ? 'Dauer unbekannt' : `${Math.floor(n / 60)}:${String(n % 60).padStart(2,'0')} min`;
function toast(message, error = false) { $('toast').textContent = message; $('toast').className = 'toast' + (error ? ' error' : ''); $('toast').hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => $('toast').hidden = true, 6500); }
async function api(path, data) {
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 12000);
  try {
    const options = {signal:controller.signal, cache:'no-store'};
    if (data !== undefined) Object.assign(options, {method:'POST', headers:{'Content-Type':'application/json','X-Kienzlefon-Token':token}, body:JSON.stringify(data)});
    const response = await fetch(path, options); const value = await response.json();
    if (!response.ok) throw new Error(value.error || 'Anfrage fehlgeschlagen.');
    return value;
  } catch(e) { if (e.name === 'AbortError') throw new Error('Keine rechtzeitige Antwort. Bei einem Rückruf zuerst das Telefon prüfen.'); throw e; }
  finally { clearTimeout(timer); }
}
function selectedClient() { const c = clients.find(c => c.id === $('client').value); if (!c) throw new Error('Bitte oben den Arbeitsplatz wählen.'); return c; }
function rememberClient() { try { localStorage.setItem('kienzlefon.client', $('client').value); } catch {} const c = clients.find(c => c.id === $('client').value); $('footer-client').textContent = c ? `${c.label} · Nebenstelle ${c.extension}` : 'Arbeitsplatz noch nicht zugeordnet'; }
async function openPatient(lookupId, patient, button) {
  let client; try { client = selectedClient(); } catch(e) { toast(e.message,true); $('client').focus(); return; }
  const key = `${client.id}:${lookupId}:${patient.patient_number}`;
  if (opening.has(key)) return;
  opening.add(key); if (button) button.disabled = true;
  if (!requestIds.has(key)) requestIds.set(key, uuid());
  try { const result = await api('/api/v1/patient-open', {client_id:client.id, lookup_id:lookupId, patient_number:patient.patient_number, request_id:requestIds.get(key)}); toast(result.message); const sentId = requestIds.get(key); setTimeout(() => { if (requestIds.get(key) === sentId) requestIds.delete(key); },2000); }
  catch(e) { toast(e.message,true); }
  finally { opening.delete(key); if (button) button.disabled = false; }
}
function patientList(lookupId, patients, status) {
  const wrapper = el('div');
  if (status === 'ambiguous') wrapper.append(el('div','ambiguous',labels.ambiguous));
  if (!patients.length) { wrapper.append(el('p','muted',labels[status] || 'Keine Zuordnung')); return wrapper; }
  const list = el('div','patient-list');
  for (const p of patients) {
    const button = el('button','patient'); button.type = 'button';
    const text = el('span',null,[p.first_name,p.last_name].filter(Boolean).join(' ') || `Patient ${p.patient_number}`);
    text.append(el('small',null,`Nr. ${p.patient_number}${p.birth_date ? ' · ' + p.birth_date : ''}`));
    if (p.address) text.append(el('small',null,p.address));
    button.append(text); button.title = 'Patientenakte in T2med öffnen';
    button.dataset.focusId = `patient-${lookupId}-${p.patient_number}`;
    button.addEventListener('click', () => openPatient(lookupId,p,button)); list.append(button);
  }
  wrapper.append(list); return wrapper;
}
function callCard(call, queueEntry) {
  const card = el('div','call'), top = el('div','call-top');
  const phone = el('span','phone',call.number || 'Rufnummer unterdrückt');
  top.append(phone, el('span','duration',seconds(queueEntry ? queueEntry.wait_seconds : (call.state === 'connected' ? call.duration_seconds : call.channel_age_seconds)))); card.append(top);
  const meta = el('div','call-meta');
  meta.append(el('span','badge' + (queueEntry ? ' wait' : ''),queueEntry ? `Warteplatz ${queueEntry.position}` : (call.state === 'connected' ? 'Im Gespräch' : 'Klingelt / Verbindungsaufbau')));
  if (call.extensions.length) meta.append(el('span',null,`Nebenstelle ${call.extensions.join(', ')}`));
  card.append(meta, patientList(call.lookup_id,call.patients,call.name_status));
  return card;
}
function render(data) {
  const focused = document.activeElement?.dataset.focusId;
  $('connection').textContent = demo ? 'Demo aktiv' : (data.ami_connected && !data.stale ? 'Telefonie verbunden' : 'Telefonie nicht aktuell');
  $('connection').className = 'connection ' + (data.ami_connected && !data.stale ? 'ok' : 'bad');
  $('offline').hidden = !data.stale;
  const calls = data.stale ? [] : data.calls;
  $('waiting-count').textContent = data.stale ? '–' : data.queues.reduce((n,q) => n + q.waiting_count,0);
  $('active-count').textContent = data.stale ? '–' : calls.filter(c => c.state === 'connected').length;
  $('extension-count').textContent = data.extensions.length;
  $('queues').replaceChildren();
  let waiting = false;
  for (const queue of data.queues) {
    const title = el('div','queue-heading'); title.append(el('span',null,queue.name),el('span',null,`${queue.waiting_count} wartend`)); $('queues').append(title);
    for (const entry of queue.entries) { const call = calls.find(c => c.id === entry.call_id); if (call) { $('queues').append(callCard(call,entry)); waiting = true; } }
  }
  if (!waiting) $('queues').append(el('p','empty',data.stale ? 'Verbindung wird wiederhergestellt …' : 'Niemand wartet. Neue Anrufe erscheinen hier automatisch.'));
  $('calls').replaceChildren();
  const conversations = calls.filter(c => c.state !== 'waiting');
  for (const call of conversations) $('calls').append(callCard(call));
  if (!conversations.length) $('calls').append(el('p','empty',data.stale ? 'Aktueller Gesprächsstatus nicht verfügbar.' : 'Zurzeit keine Gespräche.'));
  $('extensions').replaceChildren();
  for (const ext of data.extensions) {
    const node = el('div','extension ' + ext.state), text = el('div'); text.append(el('strong',null,ext.label), el('small',null,({busy:'Im Gespräch',ringing:'Klingelt',idle:'Kein Gespräch',offline:'Nicht erreichbar',unknown:'Status unbekannt'}[ext.state] || ext.state) + (ext.paused ? ' · Pausiert' : '')));
    node.append(el('div','extension-number',ext.id),text); $('extensions').append(node);
  }
  $('updated').textContent = new Date(data.generated_at).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  $('outgoing-panel').hidden = !data.outgoing.length; $('outgoing').replaceChildren();
  for (const call of data.outgoing.slice().reverse()) { const row = el('div','call'); row.append(el('span',null,`${call.extension} → ${call.number}`),el('span','muted',labels[call.phase] || call.phase)); $('outgoing').append(row); }
  if (focused) Array.from(document.querySelectorAll('[data-focus-id]')).find(e => e.dataset.focusId === focused)?.focus({preventScroll:true});
}
async function poll() {
  if (polling || document.hidden) return;
  polling = true;
  try { render(await api('/api/v1/telephony')); }
  catch { $('connection').textContent = 'Middleware nicht erreichbar'; $('connection').className = 'connection bad'; $('offline').hidden = false; $('queues').replaceChildren(el('p','empty','Keine aktuellen Daten.')); $('calls').replaceChildren(el('p','empty','Keine aktuellen Daten.')); $('waiting-count').textContent = '–'; $('active-count').textContent = '–'; }
  finally { polling = false; }
}
async function search(autoOpen = false) {
  const submit = $('search-form').querySelector('[type=submit]'); submit.disabled = true;
  $('search-results').hidden = false; $('patients').replaceChildren(el('p','muted','Patienten werden gesucht …'));
  try {
    const result = await api('/api/v1/lookup',{number:$('number').value}); latestSearch = result;
    $('search-number').textContent = result.number; $('patients').replaceChildren(patientList(result.lookup_id,result.patients,result.status));
    if (autoOpen && result.status === 'resolved' && result.patients.length === 1) await openPatient(result.lookup_id,result.patients[0]);
  } catch(e) { $('patients').replaceChildren(el('p','muted',e.message)); }
  finally { submit.disabled = false; }
}
async function dial() {
  let client; try { client = selectedClient(); } catch(e) { toast(e.message,true); $('client').focus(); return; }
  const number = $('number').value.trim(); if (!number) { $('number').focus(); return; }
  $('dial-description').textContent = `Zuerst klingelt ${client.label} (${client.extension}). Nach dem Abheben wird ${number} gewählt.`;
  const dialog = $('dial-dialog'); dialog.returnValue = ''; dialog.showModal();
  dialog.addEventListener('close', async () => {
    if (dialog.returnValue !== 'call') return;
    $('dial').disabled = true;
    try { const result = await api('/api/v1/calls',{client_id:client.id,extension:client.extension,number,request_id:uuid()}); toast(demo ? 'Demo: Kein Telefonat gestartet.' : (labels[result.phase] || 'Rückrufauftrag angenommen.')); await poll(); }
    catch(e) { toast(e.message,true); }
    finally { $('dial').disabled = false; }
  }, {once:true});
}
$('client').addEventListener('change',rememberClient);
$('search-form').addEventListener('submit',e => { e.preventDefault(); search(); });
$('dial').addEventListener('click',dial);
$('close-search').addEventListener('click',() => $('search-results').hidden = true);
document.addEventListener('visibilitychange',() => { if (!document.hidden) poll(); });
async function boot() {
  try {
    const info = await api('/api/v1/bootstrap'); token = info.token; clients = info.clients; demo = info.demo; $('demo').hidden = !demo;
    for (const client of clients) { const option = el('option',null,`${client.label} · ${client.extension}`); option.value = client.id; $('client').append(option); }
    let remembered = ''; try { remembered = localStorage.getItem('kienzlefon.client') || ''; } catch {}
    const chosen = query.get('client_id') || remembered;
    if (clients.some(c => c.id === chosen)) $('client').value = chosen;
    rememberClient(); await poll(); setInterval(poll,2000);
    if (query.has('telefon')) {
      $('number').value = query.get('telefon');
      const navigation = performance.getEntriesByType('navigation')[0];
      const auto = location.pathname === '/patient-oeffnen' && navigation?.type === 'navigate' && $('client').value !== '';
      // Remove action parameters before executing: refresh/back must not repeat an action.
      history.replaceState(null,'',location.pathname);
      if (document.prerendering) await new Promise(resolve => document.addEventListener('prerenderingchange',resolve,{once:true}));
      if (document.hidden) await new Promise(resolve => { const visible = () => { if (!document.hidden) { document.removeEventListener('visibilitychange',visible); resolve(); } }; document.addEventListener('visibilitychange',visible); });
      if (location.pathname === '/waehlen') await dial(); else await search(auto);
    }
  } catch(e) { toast(e.message,true); $('connection').textContent = 'Verbindung fehlgeschlagen'; }
}
setInterval(() => $('clock').textContent = new Date().toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'}),1000);
boot();

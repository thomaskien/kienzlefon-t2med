'use strict';
const $ = id => document.getElementById(id);
const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
const labels = {pending:'Patient wird gesucht …', resolved:'Zugeordnet', ambiguous:'Mehrere mögliche Patienten · bitte auswählen', not_found:'Kein Patient gefunden', timeout:'Suche hat zu lange gedauert', error:'Patientensuche derzeit nicht erreichbar', requested:'Auftrag angenommen', ringing_extension:'Nebenstelle klingelt', dialing_target:'Ziel wird gewählt', ringing_target:'Ziel klingelt', connected:'Verbunden', ended:'Beendet', failed:'Nicht verbunden', unknown:'Ergebnis ungewiss · Telefon prüfen', demo:'Demo · kein Anruf ausgelöst'};
const extensionLabels = {busy:'Im Gespräch',ringing:'Klingelt',idle:'Frei',offline:'Nicht erreichbar',unknown:'Status unbekannt'};
const transferLabels = {preparing:'Vermittlung wird vorbereitet',requested:'Vermittlung angefordert',ringing:'Ziel klingelt',consulting:'Rückfrage läuft',completing:'Vermittlung wird abgeschlossen',cancelling:'Rückkehr zum Anrufer läuft',transferred:'Gespräch vermittelt',cancelled:'Zurück beim Anrufer',failed:'Vermittlung fehlgeschlagen',unknown:'Status ungewiss · Telefon prüfen'};
let token = '', clients = [], demo = false, polling = false, toastTimer, pollTimer, latestSearch = null, latestData = null, pendingDial = null, pendingTransfer = null, dialBusy = false, transferStartBusy = false, compact = true;
const opening = new Set(), requestIds = new Map(), expanded = new Set(), transferActions = new Set(), transferRequestIds = new Map(), transferControlIds = new Map(), knownTransfers = new Map();
const query = new URLSearchParams(location.search);
const uuid = () => { const b = crypto.getRandomValues(new Uint8Array(16)); b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128; const s = Array.from(b, n => n.toString(16).padStart(2,'0')).join(''); return `${s.slice(0,8)}-${s.slice(8,12)}-${s.slice(12,16)}-${s.slice(16,20)}-${s.slice(20)}`; };
const clockDuration = n => n == null ? '–:–' : `${Math.floor(Math.max(0,n) / 60)}:${String(Math.floor(Math.max(0,n) % 60)).padStart(2,'0')}`;
const seconds = n => n == null ? 'Dauer unbekannt' : `${clockDuration(n)} min`;
const patientName = p => [p.first_name,p.last_name].filter(Boolean).join(' ') || `Patient ${p.patient_number}`;
function birthDate(value) {
  if (!value) return '';
  const raw = String(value); let year, month, day;
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) [year,month,day] = raw.split('-').map(Number);
  else if (/^\d{8}$/.test(raw)) {
    if (+raw.slice(0,4) >= 1800 && +raw.slice(0,4) <= 2100) [year,month,day] = [+raw.slice(0,4),+raw.slice(4,6),+raw.slice(6,8)];
    else [year,month,day] = [+raw.slice(4,8),+raw.slice(2,4),+raw.slice(0,2)];
  } else return raw;
  const date = new Date(Date.UTC(year,month-1,day));
  if (year < 1800 || year > 2100 || date.getUTCFullYear() !== year || date.getUTCMonth() !== month-1 || date.getUTCDate() !== day) return raw;
  return `${String(day).padStart(2,'0')}.${String(month).padStart(2,'0')}.${year}`;
}
function toast(message, error = false) { $('toast').textContent = message; $('toast').className = 'toast' + (error ? ' error' : ''); $('toast').hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => $('toast').hidden = true,6500); }
async function api(path, data) {
  const controller = new AbortController(), timer = setTimeout(() => controller.abort(),12000);
  try {
    const options = {signal:controller.signal,cache:'no-store'};
    if (data !== undefined) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-Kienzlefon-Token':token},body:JSON.stringify(data)});
    const response = await fetch(path,options), value = await response.json();
    if (!response.ok) throw new Error(value.error || 'Anfrage fehlgeschlagen.');
    return value;
  } catch(e) { if (e.name === 'AbortError') throw new Error('Keine rechtzeitige Antwort. Vor einem erneuten Versuch zuerst das Telefon prüfen.'); throw e; }
  finally { clearTimeout(timer); }
}
// Keep existing keyed rows and controls in place; polling never calls focus().
function reconcile(parent, children) {
  const old = Array.from(parent.childNodes), used = new Set();
  children.forEach((fresh,index) => {
    const key = fresh.nodeType === 1 ? fresh.dataset.key : null;
    let current = key ? old.find(n => !used.has(n) && n.nodeType === 1 && n.dataset.key === key && n.nodeName === fresh.nodeName) : old[index];
    if (!current || used.has(current) || current.nodeName !== fresh.nodeName || (!key && current.nodeType === 1 && current.dataset.key)) current = fresh;
    used.add(current);
    if (current !== fresh) {
      if (fresh.nodeType === 3) { if (current.nodeValue !== fresh.nodeValue) current.nodeValue = fresh.nodeValue; }
      else {
        for (const attr of Array.from(current.attributes)) if (!fresh.hasAttribute(attr.name)) current.removeAttribute(attr.name);
        for (const attr of fresh.attributes) if (current.getAttribute(attr.name) !== attr.value) current.setAttribute(attr.name,attr.value);
        reconcile(current,Array.from(fresh.childNodes));
      }
    }
    if (parent.childNodes[index] !== current) {
      if (current.isConnected && current.nodeType === 1 && current.contains(document.activeElement)) {
        // Moving a focused row via insertBefore blurs it in some browsers.
        // Move its neighbours instead, including when a preceding caller leaves.
        while (Array.prototype.indexOf.call(parent.childNodes,current) > index) parent.insertBefore(current.previousSibling,current.nextSibling);
        while (Array.prototype.indexOf.call(parent.childNodes,current) < index && current.nextSibling) parent.insertBefore(current.nextSibling,current);
      } else parent.insertBefore(current,parent.childNodes[index] || null);
    }
  });
  for (const node of old) if (!used.has(node)) node.remove();
}
function selectedClient() { const c = clients.find(c => c.id === $('client').value); if (!c) throw new Error('Bitte einen Arbeitsplatz wählen.'); return c; }
function showTools(open = true) { document.body.classList.toggle('tools-open',open); $('menu-toggle').setAttribute('aria-expanded',String(open)); }
function setView(value, remember = true) {
  compact = value !== 'full'; document.body.classList.toggle('compact-view',compact);
  if (remember) { try { localStorage.setItem('kienzlefon.view',compact ? 'compact' : 'full'); } catch {} }
  const url = new URL(location.href); url.searchParams.set('view',compact ? 'compact' : 'full'); history.replaceState(null,'',url);
}
function rememberClient() {
  try { localStorage.setItem('kienzlefon.client',$('client').value); } catch {}
  const c = clients.find(c => c.id === $('client').value);
  $('footer-client').textContent = c ? `${c.label} · Nebenstelle ${c.extension}` : 'Arbeitsplatz noch nicht zugeordnet';
  $('compact-extension').textContent = c?.extension || '–'; $('compact-extension').title = c ? `${c.label} · Eigene Nebenstelle ${c.extension}` : 'Arbeitsplatz wählen';
  updateSetup(); if (latestData) render(latestData);
}
function updateSetup() {
  const c = clients.find(c => c.id === $('client').value);
  if (!c) { $('setup-workplace').textContent = 'Zuerst den Arbeitsplatz auswählen.'; $('setup-command').textContent = ''; return; }
  const shQuote = value => "'" + value.replaceAll("'","'\\''") + "'";
  const psQuote = value => "'" + value.replaceAll("'","''") + "'";
  const os = $('setup-os').value, url = location.origin;
  $('setup-workplace').textContent = `${c.label} · Client ${c.id} · Nebenstelle ${c.extension}`;
  $('setup-command').textContent = os === 'windows'
    ? `powershell -ExecutionPolicy Bypass -File .\\clients\\install-windows.ps1 -Url ${psQuote(url)} -ClientId ${psQuote(c.id)} -Extension ${psQuote(c.extension)}`
    : `sh clients/install-${os}.sh${os === 'macos' ? ' --make-default' : ''} --url ${shQuote(url)} --client-id ${shQuote(c.id)} --extension ${shQuote(c.extension)}`;
  $('setup-hint').textContent = os === 'windows' ? 'Danach in den Windows-Standard-Apps „Kienzlefon T2med“ für TEL und CALLTO wählen.' : os === 'macos' ? 'Ohne sudo ausführen. Die App wird unter „Programme“ im Benutzerordner registriert. Die Standard-App-Auswahl ist in der Anleitung beschrieben.' : 'Als angemeldeter Desktopbenutzer ohne sudo ausführen. Bei Nachfrage Kienzlefon T2med für tel/callto auswählen.';
}
async function openPatient(lookupId, patient, button) {
  let client; try { client = selectedClient(); } catch(e) { showTools(); toast(e.message,true); $('client').focus(); return; }
  const key = `${client.id}:${lookupId}:${patient.patient_number}`;
  if (opening.has(key)) return;
  opening.add(key); if (button) button.disabled = true;
  if (!requestIds.has(key)) requestIds.set(key,uuid());
  try { const result = await api('/api/v1/patient-open',{client_id:client.id,lookup_id:lookupId,patient_number:patient.patient_number,request_id:requestIds.get(key)}); toast(result.message); requestIds.delete(key); }
  catch(e) { toast(e.message,true); }
  finally { opening.delete(key); if (button) button.disabled = false; }
}
function patientAction(button,lookupId,p,index) {
  button.dataset.action = 'patient'; button.dataset.lookup = lookupId; button.dataset.patientIndex = index;
  button.disabled = !lookupId || opening.has(`${$('client').value}:${lookupId}:${p.patient_number}`);
  button.title = `Akte öffnen: ${patientName(p)} · Nr. ${p.patient_number}`;
}
function patientList(lookupId,patients,status) {
  const wrapper = el('div');
  if (status === 'ambiguous') wrapper.append(el('div','ambiguous',labels.ambiguous));
  if (!patients.length) { wrapper.append(el('p','muted',labels[status] || 'Keine Zuordnung')); return wrapper; }
  const list = el('div','patient-list');
  patients.forEach((p,index) => {
    const button = el('button','patient'); button.type = 'button'; button.dataset.key = `patient-${index}-${p.patient_number}`;
    const text = el('span',null,patientName(p)); text.append(el('small',null,`Nr. ${p.patient_number}${p.birth_date ? ' · ' + birthDate(p.birth_date) : ''}`));
    if (p.address) text.append(el('small',null,p.address));
    button.append(text); patientAction(button,lookupId,p,index); list.append(button);
  });
  wrapper.append(list); return wrapper;
}
function transferAllowed(call,data = latestData) {
  const extension = clients.find(c => c.id === $('client').value)?.extension;
  return Boolean(extension && data && !data.stale && data.ami_connected && call?.state === 'connected' && Array.isArray(call.transfer_extensions) && call.transfer_extensions.includes(extension));
}
function transferButton(call,small = false) {
  const button = el('button',small ? 'icon-button transfer-button' : 'secondary transfer-button',small ? '↪' : 'Gespräch vermitteln');
  button.type = 'button'; button.dataset.action = 'transfer-open'; button.dataset.callId = call.id;
  button.setAttribute('aria-label','Gespräch vermitteln'); button.title = 'Gespräch vermitteln';
  return button;
}
function callCard(call,entry) {
  const card = el('div','call'); card.dataset.key = call.id;
  const top = el('div','call-top'); top.append(el('span','phone',call.number || 'Rufnummer unterdrückt'),el('span','duration',seconds(entry ? entry.wait_seconds : (call.state === 'connected' ? call.duration_seconds : call.channel_age_seconds))));
  const meta = el('div','call-meta'); meta.append(el('span','badge' + (entry ? ' wait' : ''),entry ? `Warteplatz ${entry.position}` : (call.state === 'connected' ? 'Im Gespräch' : 'Klingelt / Verbindungsaufbau')));
  if (call.extensions.length) meta.append(el('span',null,`Nebenstelle ${call.extensions.join(', ')}`));
  if (!entry && transferAllowed(call)) meta.append(transferButton(call));
  card.append(top,meta,patientList(call.lookup_id,call.patients,call.name_status)); return card;
}
function compactRow(call,entry,key,own = false,queueName = '') {
  const card = el('div','compact-call' + (own ? ' own' : '')); card.dataset.key = key;
  const row = el('div','compact-row');
  const marker = el('span','row-mark',entry ? entry.position : '☎'); marker.title = entry ? `${queueName} · Warteplatz ${entry.position}` : own ? 'Eigene Verbindung' : 'Weitere Verbindung';
  const ambiguous = call.name_status === 'ambiguous' || call.patients.length > 1;
  const title = ambiguous ? `${call.number || 'Ohne Rufnummer'} · ${call.patients.length} Treffer` : call.patients.length === 1 ? patientName(call.patients[0]) : call.number || 'Rufnummer unterdrückt';
  const name = el('button','compact-name' + (ambiguous ? ' ambiguous-name' : '')); name.type = 'button'; name.dataset.key = 'name'; name.title = title;
  name.append(el('span','compact-name-text',title));
  if (!ambiguous && call.patients.length === 1 && call.name_status === 'resolved' && call.patients[0].birth_date) name.append(el('span','compact-dob',birthDate(call.patients[0].birth_date)));
  if (!ambiguous && call.patients.length === 1 && call.name_status === 'resolved') patientAction(name,call.lookup_id,call.patients[0],0);
  else { name.dataset.action = 'detail'; name.dataset.detail = key; name.setAttribute('aria-expanded',String(expanded.has(key))); }
  const time = el('span','compact-time',clockDuration(entry ? entry.wait_seconds : call.state === 'connected' ? call.duration_seconds : call.channel_age_seconds)); time.title = entry ? 'Wartezeit' : call.state === 'connected' ? 'Gesprächsdauer' : 'Verbindungsaufbau';
  const toggle = el('button','icon-button detail-toggle',expanded.has(key) ? '▴' : '▾'); toggle.type = 'button'; toggle.dataset.action = 'detail'; toggle.dataset.detail = key; toggle.dataset.key = 'toggle'; toggle.setAttribute('aria-expanded',String(expanded.has(key))); toggle.setAttribute('aria-label',`Details: ${title}`);
  row.append(marker,name,time); if (own && transferAllowed(call)) { row.classList.add('with-transfer'); row.append(transferButton(call,true)); } row.append(toggle);
  const detail = el('div','compact-detail'); detail.dataset.key = 'detail'; detail.hidden = !expanded.has(key);
  detail.append(el('p',null,`${call.number || 'Rufnummer unterdrückt'}${call.extensions.length ? ' · Nst. ' + call.extensions.join(', ') : ''}`));
  detail.append(el('p','muted',entry ? `${queueName} · Position ${entry.position} · ${seconds(entry.wait_seconds)}` : call.state === 'connected' ? `Im Gespräch · ${seconds(call.duration_seconds)}` : 'Klingelt / Verbindungsaufbau'));
  detail.append(patientList(call.lookup_id,call.patients,call.name_status));
  if (call.number) { const callback = el('button','secondary','Rückruf'); callback.type = 'button'; callback.dataset.action = 'callback'; callback.dataset.number = call.number; detail.append(callback); }
  card.append(row,detail); return card;
}
function syncTransfers(jobs) {
  const serverJobs = Array.isArray(jobs) ? jobs : [], ids = new Set(serverJobs.map(job => job.request_id));
  for (const job of serverJobs) knownTransfers.set(job.request_id,{job,seen:true});
  for (const [id,value] of knownTransfers) if (value.seen && !ids.has(id)) knownTransfers.delete(id);
}
function transferJob(job,small = false,offline = false) {
  const row = el('div','transfer-job'); row.dataset.key = job.request_id;
  const target = job.target_type === 'internal' ? `Nebenstelle ${job.target}` : `Rufnummer ${job.target}`;
  const mode = job.mode === 'attended' ? 'Rückfrage' : 'Direktvermittlung';
  const summary = el('div','transfer-summary'); summary.append(el('strong',null,`${mode} · ${target}`),el('span','muted',job.message || transferLabels[job.phase] || `Status: ${job.phase || 'unbekannt'}`));
  row.append(summary);
  const actions = el('div','transfer-actions'), busy = transferActions.has(job.request_id);
  if (job.can_cancel) {
    const cancel = el('button','secondary','Zurück zum Anrufer'); cancel.type = 'button'; cancel.dataset.action = 'transfer-cancel'; cancel.dataset.requestId = job.request_id; cancel.disabled = offline || busy; actions.append(cancel);
  }
  if (job.can_complete) {
    const complete = el('button','primary','Vermitteln abschließen'); complete.type = 'button'; complete.dataset.action = 'transfer-complete'; complete.dataset.requestId = job.request_id; complete.disabled = offline || busy; actions.append(complete);
  }
  if (actions.childElementCount) row.append(actions);
  if (small) row.classList.add('compact-transfer-job');
  return row;
}
function renderTransfers(stale) {
  const clientId = $('client').value, jobs = Array.from(knownTransfers.values(),value => value.job).filter(job => job.client_id === clientId).sort((a,b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
  $('transfer-panel').hidden = !jobs.length; $('compact-transfers').hidden = !jobs.length;
  reconcile($('transfer-jobs'),jobs.map(job => transferJob(job,false,stale)));
  reconcile($('compact-transfer-jobs'),jobs.map(job => transferJob(job,true,stale)));
}
function render(data, unreachable = false, syncJobs = true) {
  latestData = data;
  if (syncJobs && !unreachable) syncTransfers(data.transfers);
  const stale = data.stale || !data.ami_connected, calls = stale ? [] : data.calls;
  const status = unreachable ? 'Middleware nicht erreichbar' : stale ? 'Telefonie nicht aktuell' : demo ? 'Demo aktiv' : 'Telefonie verbunden';
  $('connection').textContent = status; $('connection').className = 'connection ' + (stale ? 'bad' : 'ok');
  $('compact-connection').className = 'status-dot ' + (stale ? 'bad' : 'ok'); $('compact-connection').setAttribute('aria-label',status); $('compact-connection').title = status;
  document.querySelector('.live-label').textContent = stale ? 'NICHT AKTUELL' : 'LIVE';
  $('offline').hidden = !stale; $('compact-offline').hidden = !stale; $('compact-offline').textContent = status;
  const waitingCount = stale ? '–' : data.queues.reduce((n,q) => n + q.waiting_count,0), activeCount = stale ? '–' : calls.filter(c => c.state === 'connected').length;
  $('waiting-count').textContent = waitingCount; $('active-count').textContent = activeCount; $('extension-count').textContent = data.extensions.length;
  $('compact-counts').textContent = `${waitingCount} warten · ${activeCount} ${activeCount === 1 ? 'Gespräch' : 'Gespräche'}`;
  document.title = `${demo ? 'DEMO · ' : ''}${stale ? status : `${waitingCount} warten · ${activeCount} ${activeCount === 1 ? 'Gespräch' : 'Gespräche'}`} · kienzlefon`;
  const queueNodes = [], compactWaiting = [], queues = stale ? [] : data.queues;
  const callMap = new Map(calls.map(c => [c.id,c])); let waiting = false;
  for (const queue of queues) {
    const heading = el('div','queue-heading'); heading.dataset.key = 'queue-' + queue.name; heading.append(el('span',null,queue.name),el('span',null,`${queue.waiting_count} wartend`)); queueNodes.push(heading);
    if (queues.filter(q => q.waiting_count).length > 1 && queue.waiting_count) { const heading = el('div','compact-queue-title',queue.name); heading.dataset.key = 'queue-' + queue.name; compactWaiting.push(heading); }
    for (const entry of queue.entries.slice().sort((a,b) => a.position-b.position)) {
      const call = callMap.get(entry.call_id); if (!call) continue;
      const card = callCard(call,entry); card.dataset.key = `queue-${queue.name}-${call.id}`; queueNodes.push(card);
      compactWaiting.push(compactRow(call,entry,`queue-${queue.name}-${call.id}`,false,queue.name)); waiting = true;
    }
  }
  if (!waiting) { queueNodes.push(el('p','empty',stale ? 'Verbindung wird wiederhergestellt …' : 'Niemand wartet.')); compactWaiting.push(el('p','compact-empty',stale ? 'Anrufstatus nicht verfügbar.' : 'Niemand wartet.')); }
  reconcile($('queues'),queueNodes); reconcile($('compact-waiting'),compactWaiting);
  const conversations = calls.filter(c => c.state !== 'waiting'), currentExtension = clients.find(c => c.id === $('client').value)?.extension;
  const own = conversations.filter(c => currentExtension && c.extensions.includes(currentExtension)), others = conversations.filter(c => !own.includes(c));
  const allCallsShown = !$('compact-conversations').hidden;
  reconcile($('calls'),conversations.length ? conversations.map(c => callCard(c)) : [el('p','empty',stale ? 'Aktueller Gesprächsstatus nicht verfügbar.' : 'Zurzeit keine Gespräche.')]);
  reconcile($('compact-own'),own.length || allCallsShown ? own.map(c => compactRow(c,null,`own-${c.id}`,true)) : [el('p','compact-empty',stale ? 'Gesprächsstatus nicht verfügbar.' : 'Keine eigenen Gespräche.')]);
  const otherCalls = others.map(c => {
    const row = compactRow(c,null,`all-${c.id}`);
    row.querySelector('.row-mark').textContent = c.extensions[0] || '☎';
    row.querySelector('.row-mark').title = c.extensions.length ? `Nebenstellen ${c.extensions.join(', ')}` : 'Nebenstelle noch unbekannt';
    return row;
  });
  reconcile($('compact-all-calls'),otherCalls.length ? otherCalls : [el('p','compact-empty',stale ? 'Gesprächsstatus nicht verfügbar.' : own.length ? 'Keine weiteren Gespräche.' : 'Zurzeit keine Gespräche.')]);
  $('conversations-toggle').textContent = allCallsShown ? 'Eigene Gespräche ▴' : `Alle Gespräche (${stale ? '–' : conversations.length}) ▾`;
  $('conversations-toggle').setAttribute('aria-label',allCallsShown ? 'Eigene Gespräche anzeigen' : `Alle Gespräche anzeigen (${stale ? 'Status unbekannt' : conversations.length})`);
  $('more-toggle').textContent = `Nst. (${data.extensions.length})`;
  const extNodes = [], compactExtensions = [];
  for (const ext of data.extensions) {
    const state = stale ? 'unknown' : ext.state, status = (extensionLabels[state] || state) + (ext.paused ? ' · Pausiert' : '');
    const node = el('div','extension ' + state); node.dataset.key = ext.id;
    const text = el('div'); text.append(el('strong',null,ext.label),el('small',null,status)); node.append(el('div','extension-number',ext.id),text); extNodes.push(node);
    const small = el('div','compact-extension'); small.dataset.key = ext.id; small.append(el('strong',null,`${ext.id} · ${ext.label}`),el('span',null,status)); compactExtensions.push(small);
  }
  reconcile($('extensions'),extNodes); reconcile($('compact-extensions'),compactExtensions);
  renderTransfers(stale); updateTransferDialogState();
  $('updated').textContent = stale ? 'Nicht aktuell' : new Date(data.generated_at).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const outgoing = data.outgoing || []; $('outgoing-panel').hidden = !outgoing.length;
  reconcile($('outgoing'),outgoing.slice().reverse().map(call => { const row = el('div','call'); row.dataset.key = call.request_id; row.append(el('span',null,`${call.extension} → ${call.number}`),el('span','muted',labels[call.phase] || call.phase)); return row; }));
  const presentKeys = new Set([...own.map(c => `own-${c.id}`),...others.map(c => `all-${c.id}`),...compactWaiting.map(n => n.dataset.key)]);
  for (const key of expanded) if (!presentKeys.has(key)) expanded.delete(key);
}
function showTransferError(message = '',source = '') {
  $('transfer-error').textContent = message; $('transfer-error').hidden = !message; $('transfer-error').dataset.source = source;
}
function boundTransferCall() {
  return pendingTransfer && latestData?.calls?.find(call => call.id === pendingTransfer.callId);
}
function updateTransferDialogState() {
  if (!$('transfer-dialog').open || !pendingTransfer) return;
  const sameClient = $('client').value === pendingTransfer.clientId, call = boundTransferCall();
  let error = '';
  if (!sameClient) error = 'Der Arbeitsplatz wurde gewechselt. Bitte schließen und das Gespräch am richtigen Arbeitsplatz erneut auswählen.';
  else if (!call || !transferAllowed(call)) error = latestData?.stale || !latestData?.ami_connected ? 'Telefoniedaten sind nicht aktuell. Vermitteln ist derzeit gesperrt.' : 'Das gebundene Gespräch ist nicht mehr verbunden oder nicht mehr vermittelbar.';
  for (const button of $('transfer-form').querySelectorAll('[data-action="transfer-start"]')) button.disabled = transferStartBusy || Boolean(error);
  if (error) showTransferError(error,'validity');
  else if ($('transfer-error').dataset.source === 'validity') showTransferError();
}
function updateTransferTarget() {
  const type = $('transfer-form').elements.target_type.value;
  $('transfer-internal-label').hidden = type !== 'internal'; $('transfer-external-label').hidden = type !== 'external';
  $('transfer-extension').required = type === 'internal'; $('transfer-number').required = type === 'external';
}
function openTransfer(callId) {
  const call = latestData?.calls?.find(item => item.id === callId);
  if (!call || !transferAllowed(call)) { toast('Dieses Gespräch ist nicht mehr vermittelbar.',true); return; }
  const client = clients.find(item => item.id === $('client').value);
  pendingTransfer = {clientId:client.id,callId:call.id}; transferStartBusy = false;
  $('transfer-call').textContent = `${call.patients?.length === 1 && call.name_status === 'resolved' ? patientName(call.patients[0]) + ' · ' : ''}${call.number || 'Rufnummer unterdrückt'}`;
  $('transfer-extension').replaceChildren(el('option',null,'Nebenstelle auswählen'));
  $('transfer-extension').firstElementChild.value = '';
  for (const extension of latestData.extensions || []) if (extension.id !== client.extension) { const option = el('option',null,`${extension.id} · ${extension.label}`); option.value = extension.id; $('transfer-extension').append(option); }
  $('transfer-form').elements.target_type.value = 'internal'; $('transfer-number').value = ''; showTransferError(); updateTransferTarget();
  $('transfer-dialog').showModal(); updateTransferDialogState();
}
function upsertTransfer(job) {
  knownTransfers.set(job.request_id,{job,seen:false});
  if (latestData) render(latestData,false,false);
}
async function startTransfer(mode) {
  if (transferStartBusy || !pendingTransfer) return;
  const client = clients.find(item => item.id === $('client').value), call = boundTransferCall();
  if (!client || client.id !== pendingTransfer.clientId || !call || !transferAllowed(call)) { updateTransferDialogState(); return; }
  const targetType = $('transfer-form').elements.target_type.value;
  const target = (targetType === 'internal' ? $('transfer-extension').value : $('transfer-number').value).trim();
  if (!target) { showTransferError(targetType === 'internal' ? 'Bitte eine Nebenstelle auswählen.' : 'Bitte eine externe Rufnummer eingeben.','input'); return; }
  transferStartBusy = true; showTransferError(); updateTransferDialogState();
  const signature = JSON.stringify([client.id,call.id,targetType,target,mode]);
  if (!transferRequestIds.has(signature)) transferRequestIds.set(signature,uuid());
  const request = {client_id:client.id,call_id:call.id,target_type:targetType,target,mode,request_id:transferRequestIds.get(signature)};
  try { const job = await api('/api/v1/transfers',request); transferRequestIds.delete(signature); upsertTransfer(job); $('transfer-dialog').close(); toast(job.message || transferLabels[job.phase] || 'Vermittlung angefordert.', ['failed','unknown'].includes(job.phase)); }
  catch(e) { showTransferError(e.message,'request'); }
  finally { transferStartBusy = false; updateTransferDialogState(); }
}
async function transferAction(requestId,action) {
  if (transferActions.has(requestId)) return;
  const value = knownTransfers.get(requestId), job = value?.job, client = clients.find(item => item.id === $('client').value);
  const allowed = action === 'complete' ? job?.can_complete : job?.can_cancel;
  if (!job || !client || job.client_id !== client.id || !allowed || latestData?.stale || !latestData?.ami_connected) return;
  transferActions.add(requestId); renderTransfers(false);
  const key = JSON.stringify([client.id,job.request_id,action]);
  if (!transferControlIds.has(key)) transferControlIds.set(key,uuid());
  try { const updated = await api(`/api/v1/transfers/${encodeURIComponent(job.request_id)}/${action}`,{client_id:client.id,request_id:transferControlIds.get(key)}); upsertTransfer(updated); toast(updated.message || transferLabels[updated.phase], ['failed','unknown'].includes(updated.phase)); }
  catch(e) { toast(e.message,true); }
  finally { transferActions.delete(requestId); renderTransfers(Boolean(latestData?.stale || !latestData?.ami_connected)); }
}
async function poll() {
  if (polling) return;
  polling = true; clearTimeout(pollTimer);
  try { render(await api('/api/v1/telephony')); }
  catch { render({...latestData,ami_connected:false,stale:true,calls:[],queues:[],extensions:latestData?.extensions || [],outgoing:latestData?.outgoing || [],transfers:latestData?.transfers || []},true); }
  finally { polling = false; pollTimer = setTimeout(poll,document.hidden ? 15000 : 2000); }
}
async function search(autoOpen = false) {
  const submit = $('search-form').querySelector('[type=submit]'); submit.disabled = true;
  $('search-results').hidden = false; $('patients').replaceChildren(el('p','muted','Patienten werden gesucht …'));
  try {
    const result = await api('/api/v1/lookup',{number:$('number').value}); latestSearch = result;
    $('search-number').textContent = result.number; $('patients').replaceChildren(patientList(result.lookup_id,result.patients,result.status));
    $('search-results').scrollIntoView({block:'start'});
    if (autoOpen && result.status === 'resolved' && result.patients.length === 1) await openPatient(result.lookup_id,result.patients[0]);
  } catch(e) { $('patients').replaceChildren(el('p','muted',e.message)); }
  finally { submit.disabled = false; }
}
async function sendDial(call) {
  if (dialBusy) return; dialBusy = true; $('dial').disabled = true;
  try { const result = await api('/api/v1/calls',call); toast(demo ? 'Demo: Kein Telefonat gestartet.' : (labels[result.phase] || 'Rückrufauftrag angenommen.')); await poll(); }
  catch(e) { toast(e.message,true); }
  finally { dialBusy = false; $('dial').disabled = false; }
}
async function dial(immediate = false) {
  if (dialBusy || $('dial-dialog').open) return;
  let client; try { client = selectedClient(); } catch(e) { showTools(); toast(e.message,true); $('client').focus(); return; }
  const number = $('number').value.trim(); if (!number) { showTools(); $('number').focus(); return; }
  const call = {client_id:client.id,extension:client.extension,number,request_id:uuid()};
  if (immediate) { await sendDial(call); return; }
  pendingDial = call; $('dial-description').textContent = `Zuerst klingelt ${client.label} (${client.extension}). Nach dem Abheben wird ${number} gewählt.`;
  $('dial-dialog').returnValue = ''; $('dial-dialog').showModal();
}
$('dial-dialog').addEventListener('close',() => { const call = pendingDial; pendingDial = null; if ($('dial-dialog').returnValue === 'call' && call) sendDial(call); });
$('transfer-dialog').addEventListener('close',() => { pendingTransfer = null; transferStartBusy = false; showTransferError(); });
$('transfer-form').addEventListener('submit',e => e.preventDefault());
$('transfer-form').addEventListener('change',e => { if (e.target.name === 'target_type') { updateTransferTarget(); showTransferError(); } });
$('client').addEventListener('change',rememberClient);
$('setup-os').addEventListener('change',updateSetup);
$('search-form').addEventListener('submit',e => { e.preventDefault(); search(); });
$('dial').addEventListener('click',() => dial());
$('close-search').addEventListener('click',() => $('search-results').hidden = true);
document.addEventListener('click',e => {
  const button = e.target.closest('[data-action]'); if (!button || button.disabled) return;
  const action = button.dataset.action;
  if (action === 'tools') showTools(!document.body.classList.contains('tools-open'));
  else if (action === 'full' || action === 'compact') setView(action);
  else if (action === 'more') { $('compact-more').hidden = !$('compact-more').hidden; button.setAttribute('aria-expanded',String(!$('compact-more').hidden)); }
  else if (action === 'conversations') {
    const open = $('compact-conversations').hidden; $('compact-conversations').hidden = !open; button.setAttribute('aria-expanded',String(open));
    try { sessionStorage.setItem('kienzlefon.allCalls',String(open)); } catch {}
    if (latestData) render(latestData);
  }
  else if (action === 'setup') { $('client-setup').hidden = !$('client-setup').hidden; button.setAttribute('aria-expanded',String(!$('client-setup').hidden)); updateSetup(); }
  else if (action === 'detail') { const key = button.dataset.detail; if (expanded.has(key)) expanded.delete(key); else expanded.add(key); if (latestData) render(latestData); }
  else if (action === 'patient') {
    const result = latestSearch?.lookup_id === button.dataset.lookup ? latestSearch : latestData?.calls.find(c => c.lookup_id === button.dataset.lookup);
    const patient = result?.patients[Number(button.dataset.patientIndex)]; if (patient) openPatient(button.dataset.lookup,patient,button); else toast('Zuordnung nicht mehr aktuell. Bitte erneut suchen.',true);
  } else if (action === 'callback') { $('number').value = button.dataset.number; dial(); }
  else if (action === 'transfer-open') openTransfer(button.dataset.callId);
  else if (action === 'transfer-close') $('transfer-dialog').close();
  else if (action === 'transfer-start') startTransfer(button.dataset.mode);
  else if (action === 'transfer-complete') transferAction(button.dataset.requestId,'complete');
  else if (action === 'transfer-cancel') transferAction(button.dataset.requestId,'cancel');
  else if (action === 'popup') { const url = new URL('/',location.origin); url.searchParams.set('view','compact'); if ($('client').value) url.searchParams.set('client_id',$('client').value); window.open(url,'kienzlefon-compact','popup=yes,width=320,height=240'); }
});
document.addEventListener('visibilitychange',() => { if (!document.hidden) poll(); });
async function boot() {
  const navigation = performance.getEntriesByType('navigation')[0], freshNavigation = navigation?.type === 'navigate';
  // Consume action parameters before any network wait. Refresh/back never repeats a call/GDT.
  if (query.has('telefon')) { const url = new URL(location.href); for (const key of ['telefon','sofort','expected_extension']) url.searchParams.delete(key); history.replaceState(null,'',url); }
  let savedView = '', savedClient = ''; try { savedView = localStorage.getItem('kienzlefon.view') || ''; savedClient = localStorage.getItem('kienzlefon.client') || ''; } catch {}
  setView(query.get('view') || savedView || 'compact',false);
  try { const open = sessionStorage.getItem('kienzlefon.allCalls') === 'true'; $('compact-conversations').hidden = !open; $('conversations-toggle').setAttribute('aria-expanded',String(open)); } catch {}
  $('setup-os').value = /Mac/.test(navigator.platform) ? 'macos' : /Linux/.test(navigator.platform) ? 'linux' : 'windows';
  try {
    const info = await api('/api/v1/bootstrap'); token = info.token; clients = info.clients; demo = info.demo; $('demo').hidden = !demo; $('compact-demo').hidden = !demo;
    if (info.version) { $('app-version').textContent = `kienzlefon T2med · Version ${info.version}`; $('app-version').hidden = false; }
    for (const client of clients) { const option = el('option',null,`${client.label} · ${client.extension}`); option.value = client.id; $('client').append(option); }
    const chosen = query.get('client_id') || savedClient;
    if (clients.some(c => c.id === chosen)) $('client').value = chosen;
    rememberClient(); if (!$('client').value) showTools(); poll();
    if (query.has('telefon')) {
      $('number').value = query.get('telefon');
      if (query.has('expected_extension') && clients.find(c => c.id === chosen)?.extension !== query.get('expected_extension')) throw new Error('Nebenstelle stimmt nicht mit der lokalen Registrierung überein. Telefonie-App neu konfigurieren.');
      if (document.prerendering) await new Promise(resolve => document.addEventListener('prerenderingchange',resolve,{once:true}));
      if (document.hidden) await new Promise(resolve => { const visible = () => { if (!document.hidden) { document.removeEventListener('visibilitychange',visible); resolve(); } }; document.addEventListener('visibilitychange',visible); });
      if (location.pathname === '/waehlen') { if (freshNavigation) await dial(query.get('sofort') === '1'); }
      else await search(location.pathname === '/patient-oeffnen' && freshNavigation && $('client').value !== '');
    }
  } catch(e) { toast(e.message,true); if (!token) { $('connection').textContent = 'Verbindung fehlgeschlagen'; $('compact-connection').className = 'status-dot bad'; $('compact-offline').hidden = false; $('compact-offline').textContent = e.message; document.title = 'Verbindung fehlgeschlagen · kienzlefon'; } }
}
setInterval(() => $('clock').textContent = new Date().toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'}),1000);
boot();

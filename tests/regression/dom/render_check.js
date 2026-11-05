'use strict';
/**
 * DOM-level render check (no browser available): runs the real front-end scripts (public/index.html and
 * the scripts it references) in a node vm against a stubbed DOM, renders every view / tab / wizard step /
 * modal as admin, user, viewer and contributor with data containing the marker  Z9" ' < > ;  and checks
 * the produced HTML strings:
 *   1. the marker never appears with a raw " ' < > right after it (i.e. everything is escaped),
 *   2. no inline on*="..." handler contains fixture data (ids, names, urls, file names),
 *   3. every data-on-* delegated descriptor parses, names an existing function, and the delegated
 *      listener really calls that function with the decoded arguments.
 * Usage: node render_check.js <fixture.json>   -> prints a JSON report on the last line.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '..', '..', '..');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const MARKER_PREFIX = 'Z9';

process.on('unhandledRejection', () => {});
process.on('uncaughtException', () => {});

const checks = [];
const check = (ok, label, debug) => checks.push({ ok: !!ok, label, debug: debug === undefined ? null : debug });

// ---------- DOM stub ----------
let captured = [];
const listeners = []; // [type, fn, capture]
const NULLISH = new Set(['nextNode', 'nextSibling', 'previousSibling', 'firstChild', 'lastChild', 'parentNode', 'parentElement',
    'nextElementSibling', 'previousElementSibling', 'firstElementChild', 'lastElementChild', 'closest', 'getAttribute', 'querySelector',
    'offsetParent', 'contentWindow', 'contentDocument', 'currentTarget']);
function stub(label, sink) {
    const store = {};
    const fn = function () { return stub(label + '()', sink); };
    return new Proxy(fn, {
        get(t, p) {
            if (p === Symbol.toPrimitive) return () => '';
            if (p === 'then') return undefined;
            if (Object.prototype.hasOwnProperty.call(store, p)) return store[p];
            if (p === 'insertAdjacentHTML') return (pos, h) => { captured.push({ label, html: String(h) }); };
            if (p === 'write') return (h) => { captured.push({ label: label + '.write', html: String(h) }); };
            if (p === 'addEventListener') return (type, f, cap) => { listeners.push([type, f, cap, label]); };
            if (NULLISH.has(p)) return () => null;
            if (['innerHTML', 'outerHTML', 'textContent', 'innerText', 'value', 'id', 'className', 'href', 'src', 'title', 'name', 'type', 'placeholder'].includes(p)) return '';
            if (p === 'length') return 0;
            if (['files', 'children', 'childNodes', 'options'].includes(p)) return [];
            if (['checked', 'disabled', 'hidden', 'selected'].includes(p)) return false;
            if (['scrollTop', 'scrollLeft', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'selectedIndex'].includes(p)) return 0;
            if (p === 'classList') return { add() {}, remove() {}, toggle() {}, contains() { return false; } };
            if (p === 'style' || p === 'dataset') return (store[p] = store[p] || stub(label + '.' + String(p), sink));
            if (p === 'forEach' || p === 'map' || p === 'filter') return () => [];
            return stub(label + '.' + String(p), sink);
        },
        set(t, p, v) {
            if (p === 'innerHTML' || p === 'outerHTML') captured.push({ label, html: String(v) });
            store[p] = v;
            return true;
        },
        apply() { return stub(label + '()', sink); },
    });
}

const elements = {};
const document = {
    querySelector: (s) => elements[s] || (elements[s] = stub(s)),
    querySelectorAll: () => [],
    getElementById: (s) => elements['#' + s] || (elements['#' + s] = stub('#' + s)),
    createElement: (t) => stub('<' + t + '>'),
    createTreeWalker: () => stub('walker'),
    createTextNode: () => stub('text'),
    body: stub('body'),
    documentElement: stub('html'),
    head: stub('head'),
    addEventListener: (type, f, cap) => { listeners.push([type, f, cap, 'document']); },
    removeEventListener() {},
    write: (h) => captured.push({ label: 'document.write', html: String(h) }),
    cookie: '',
    title: '',
    readyState: 'complete',
};
const store0 = () => ({ getItem: () => null, setItem() {}, removeItem() {}, clear() {}, key: () => null, length: 0 });
const ctx = {
    document, console: { log() {}, warn() {}, error() {}, info() {}, debug() {} },
    localStorage: store0(), sessionStorage: store0(),
    fetch: () => Promise.reject(new Error('stub fetch')),
    navigator: { userAgent: 'node', language: 'id-ID', languages: ['id-ID'], clipboard: { writeText: () => Promise.resolve() } },
    location: { hash: '', search: '', origin: 'http://127.0.0.1', href: 'http://127.0.0.1/', pathname: '/', host: '127.0.0.1', reload() {}, assign() {} },
    history: { pushState() {}, replaceState() {} },
    setTimeout: () => 0, setInterval: () => 0, clearTimeout() {}, clearInterval() {},
    requestAnimationFrame: () => 0, cancelAnimationFrame() {},
    matchMedia: () => ({ matches: false, addEventListener() {}, addListener() {} }),
    getComputedStyle: () => stub('computed'),
    alert() {}, confirm: () => true, prompt: () => '',
    addEventListener: (type, f, cap) => { listeners.push([type, f, cap, 'window']); },
    removeEventListener() {},
    open: () => ({ document: { write: (h) => captured.push({ label: 'window.open.write', html: String(h) }), close() {} }, focus() {}, print() {}, close() {} }),
    EventSource: class { constructor() {} close() {} addEventListener() {} },
    MutationObserver: class { observe() {} disconnect() {} },
    ResizeObserver: class { observe() {} disconnect() {} },
    IntersectionObserver: class { observe() {} disconnect() {} },
    NodeFilter: { SHOW_TEXT: 4, SHOW_ELEMENT: 1 },
    FileReader: class {}, Image: class {}, Blob: class {}, File: class {}, FormData: class { append() {} },
    URL, URLSearchParams, TextEncoder, TextDecoder, AbortController, Intl, structuredClone,
    Event: class { constructor(t) { this.type = t; } }, CustomEvent: class { constructor(t) { this.type = t; } },
    performance: { now: () => 0 }, crypto: { getRandomValues: (a) => a, randomUUID: () => '00000000-0000-4000-8000-000000000000' },
    atob: (s) => Buffer.from(s, 'base64').toString('binary'), btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
    innerWidth: 1280, innerHeight: 800, devicePixelRatio: 1,
};
ctx.window = ctx; ctx.self = ctx; ctx.globalThis = ctx; ctx.top = ctx; ctx.parent = ctx;
ctx.__getCaptured = () => captured;
ctx.__resetCaptured = () => { captured = []; };
vm.createContext(ctx);

// ---------- load the real scripts in page order ----------
const htmlSrc = fs.readFileSync(path.join(root, 'public', 'index.html'), 'utf8');
check(
    /class="tb-toggle"[^>]*aria-controls="nav"/.test(htmlSrc)
        && htmlSrc.includes('body.sidebar-collapsed .app-sidebar')
        && htmlSrc.includes('body.sb-open .app-sidebar'),
    'hamburger menu consistently supports desktop collapse and mobile drawer'
);
check(
    /function syncSidebarToggle\(\)/.test(fs.readFileSync(path.join(root, 'public', 'app.js'), 'utf8'))
        && /function toggleSidebar\(\)[\s\S]*?syncSidebarToggle\(\)/.test(fs.readFileSync(path.join(root, 'public', 'app.js'), 'utf8')),
    'hamburger toggle keeps its accessible expanded state in sync'
);
const scripts = [];
const tagRe = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
let m;
while ((m = tagRe.exec(htmlSrc)) !== null) {
    const src = /\bsrc="([^"]+)"/.exec(m[1]);
    if (src) {
        if (/^https?:/i.test(src[1])) { check(false, 'no external script: ' + src[1]); continue; }
        scripts.push({ name: src[1], code: fs.readFileSync(path.join(root, 'public', src[1].replace(/^\//, '')), 'utf8') });
    } else if (!/type="(?!text\/javascript)/.test(m[1])) {
        scripts.push({ name: 'inline', code: m[2] });
    }
}
const run = (code, name = 'check') => vm.runInContext(code, ctx, { timeout: 8000, filename: name });
for (const s of scripts) {
    try { run(s.code, s.name); } catch (e) { check(false, 'script ' + s.name + ' loads', String(e && e.stack || e).slice(0, 400)); }
}
check(scripts.length > 0 && typeof ctx.render === 'function' || (() => { try { return run('typeof render') === 'function'; } catch (e) { return false; } })(), 'front-end scripts load and define render()');

// ---------- helpers ----------
const sleep = () => new Promise((r) => setImmediate(r));
const errors = [];
function safe(label, code) {
    try { run(code, label); } catch (e) { errors.push(label + ': ' + String(e && e.message || e).slice(0, 120)); }
}
const findings = [];
const seenHtml = new Set();
const allHtml = [];
function harvest(where) {
    for (const c of captured) {
        const key = c.label + '|' + c.html;
        if (seenHtml.has(key)) continue;
        seenHtml.add(key);
        allHtml.push({ where, label: c.label, html: c.html });
    }
    captured = [];
}

const ids = new Set();
(function collect(o) {
    if (Array.isArray(o)) o.forEach(collect);
    else if (o && typeof o === 'object') {
        for (const [k, v] of Object.entries(o)) {
            if ((k === 'id' || k === 'qid' || k === 'file' || k === 'url' || k === 'link' || k === 'projectId') && typeof v === 'string' && v.length >= 4) ids.add(v);
            collect(v);
        }
    } else if (typeof o === 'string' && o.includes(MARKER_PREFIX)) ids.add(o);
})(fixture);

const roles = [['t_admin', 'admin'], ['t_user1', 'user'], ['t_viewer', 'viewer'], ['t_contrib', 'contributor']];

(async () => {
    await sleep();
    ctx.__fx = fixture;
    try {
        const dateChecks = run(`(()=>{
            const previous={DB,DASH_PERIOD,DASH_START,DASH_END,F};
            DB={projects:[
                {id:'spans-period',name:'Spans period',category:'',pic:'',status:'On Progress',progress:20,start:'2026-03-15',end:'2026-05-01',quotations:[],pos:[],stages:[]},
                {id:'within-period',name:'Within period',category:'',pic:'',status:'On Progress',progress:40,start:'2026-04-10',end:'2026-04-20',quotations:[],pos:[],stages:[]},
                {id:'outside-period',name:'Outside period',category:'',pic:'',status:'On Progress',progress:10,start:'2026-05-01',end:'2026-05-31',quotations:[],pos:[],stages:[]},
                {id:'missing-dates',name:'Missing dates',category:'',pic:'',status:'On Progress',progress:0,start:'',end:'',quotations:[],pos:[],stages:[]}
            ]};
            F={cat:'',status:'',pic:'',q:''};
            DASH_PERIOD='custom';DASH_START='2026-04-01';DASH_END='2026-04-30';
            const overlap=dashboardProjects(true).map(p=>p.id).sort().join(',');
            DASH_START='2026-05-01';DASH_END='2026-04-30';
            const reversed=dashboardProjects(true).length;
            DASH_PERIOD='all';
            const all=dashboardProjects(true).length;
            DB=previous.DB;DASH_PERIOD=previous.DASH_PERIOD;DASH_START=previous.DASH_START;DASH_END=previous.DASH_END;F=previous.F;
            return {overlap:overlap,reversed:reversed,all:all};
        })()`);
        check(dateChecks.overlap === 'spans-period,within-period', 'dashboard date filter includes overlapping project schedules only');
        check(dateChecks.reversed === 0, 'dashboard date filter rejects reversed custom ranges');
        check(dateChecks.all === 4, 'dashboard all-dates selection preserves all projects');
    } catch (e) {
        check(false, 'dashboard date filter behavior', String(e && e.stack || e).slice(0, 400));
    }
        const originalApi = ctx.api;
        try {
            ctx.api = async () => ({ name: 'proof.png', size: 1, mime_type: 'image/png' });
            run(`DB=normDB(__fx);ME='t_admin';USER={name:'t_admin',role:'admin'};draft=structuredClone(DB.projects[0]);STAGE_DOC_ID=draft.stages[0].id;VIEW='project_form';isNew=false;ATT_UNSAVED=false;`);
            await run(`uploadStageDocs({files:[{name:'proof.png',size:1,type:'image/png'}],value:''})`);
            const uploadState = run(`(()=>{const stage=draft.stages.find(s=>s.id===STAGE_DOC_ID);return {file:stage.attachments[stage.attachments.length-1],unsaved:ATT_UNSAVED};})()`);
            const uploadedWithoutDescription = uploadState.file && uploadState.file.name === 'proof.png' && uploadState.file.description === '' && uploadState.unsaved;
            if (uploadedWithoutDescription) {
                run(`setStageDocDescription('file',${JSON.stringify(uploadState.file.id)},'Deskripsi ditambahkan setelah unggah')`);
                await run(`saveStageDocDescription('file',${JSON.stringify(uploadState.file.id)})`);
            }
            const savedDescription = run(`(()=>{const stage=draft.stages.find(s=>s.id===STAGE_DOC_ID);return stage.attachments.find(f=>f.id===${JSON.stringify(uploadState.file && uploadState.file.id)}).description;})()`);
            check(uploadedWithoutDescription && savedDescription === 'Deskripsi ditambahkan setelah unggah',
                'stage file can be uploaded first and described afterward');
        } catch (e) {
            check(false, 'stage file upload can precede its description', String(e && e.stack || e).slice(0, 400));
        } finally {
            ctx.api = originalApi;
            run(`DB=normDB(__fx);draft=null;STAGE_DOC_ID='';VIEW='dash';isNew=false;ATT_UNSAVED=false`);
        }
        safe('custom date range controls', `DB=normDB(__fx);ME='t_admin';USER={name:'t_admin',role:'admin'};DASH_PERIOD='custom';DASH_START='2026-04-01';DASH_END='2026-04-30';VIEW='dash';render()`);
    harvest('dashboard:custom-date-controls');
    safe('reset dashboard date range', `DASH_PERIOD='all';DASH_START='';DASH_END='';DB=normDB(__fx)`);
    for (const [name, role] of roles) {
        const w = role;
        safe('state ' + role, `DB=normDB(__fx);ME=${JSON.stringify(name)};USER={name:${JSON.stringify(name)},role:${JSON.stringify(role)}};VIEW='dash';`);
        for (const v of ['dash', 'gantt', 'my_projects', 'list', 'budget', 'improvements', 'imp_review']) {
            safe(w + ' view ' + v, `go(${JSON.stringify(v)})`);
            harvest(w + ':view:' + v);
        }
        if ((fixture.projects || []).length) {
            safe(w + ' project progress modal', `openProjectProgress(${JSON.stringify(fixture.projects[0].id)})`);
            harvest(w + ':project-progress-modal');
            if ((fixture.projects[0].stages || []).length) {
                safe(w + ' stage documentation viewer', `viewStageDocs(${JSON.stringify(fixture.projects[0].id)},${JSON.stringify(fixture.projects[0].stages[0].id)})`);
                harvest(w + ':stage-documentation-viewer');
                    safe(w + ' expandable project stage', `toggleProgressStage(${JSON.stringify(fixture.projects[0].id)},${JSON.stringify(fixture.projects[0].stages[0].id)})`);
                    harvest(w + ':project-progress-expanded-stage');
                }
            safe(w + ' dashboard status modal', `openProjectStatus(${JSON.stringify(fixture.projects[0].status)})`);
            harvest(w + ':dashboard-status-modal');
        }
        for (const modal of ['openSettings()', 'openTpl()', 'openPOS()', 'closeModal()']) {
            safe(w + ' ' + modal, modal);
            harvest(w + ':' + modal);
        }
        if (role === 'admin' || role === 'user') {
            safe(w + ' vendor manager', 'openVendorManager(false)');
            harvest(w + ':vendor-manager');
            safe(w + ' close vendor manager', 'closeVendorManager()');
            harvest(w + ':close-vendor-manager');
            safe(w + ' purchase order vendor form', `PD={id:'vendor-test',no:'PO-1',date:'2026-01-01',currency:'IDR',vendor:'Vendor A',attn:'',tel:'',email:'',addr:'',items:[],tax:0,terms:'',leadTime:'',delivery:'',note:'',useSig:false};POISNEW=true;drawPO()`);
            harvest(w + ':purchase-order-vendor-form');
        }
        const projects = fixture.projects || [];
        for (const p of projects) {
            for (const tab of ['info', 'stage', 'budget', 'ms', 'log']) {
                safe(w + ' detail ' + tab, `viewP(${JSON.stringify(p.id)},${JSON.stringify(tab)})`);
                harvest(`${w}:detail:${p.id}:${tab}`);
                safe(w + ' detail docs', `(()=>{const d=draft;if(d&&d.stages)d.stages.forEach(s=>{viewStageDocs(d.id,s.id);});})()`);
                harvest(`${w}:detail-docs:${p.id}`);
            }
            for (let step = 1; step <= 5; step++) {
                safe(w + ' edit step ' + step, `editP(${JSON.stringify(p.id)},${step})`);
                harvest(`${w}:edit:${p.id}:${step}`);
                if (step === 2) {
                    safe(w + ' stage docs editor', `(()=>{const d=draft;if(d&&d.stages)d.stages.forEach(s=>{openStageDocs(s.id);});})()`);
                    harvest(`${w}:stage-docs-edit:${p.id}`);
                }
                if (step === 3) {
                    safe(w + ' po editor', `(()=>{const d=draft;if(d&&d.pos)d.pos.forEach(o=>{editPO(o.id);openDoc(poHTML(getPO(o.id),draft,false));});})()`);
                    harvest(`${w}:po:${p.id}`);
                    safe(w + ' closeModal', 'closeModal()');
                }
            }
        }
        for (const x of fixture.improvements || []) {
            safe(w + ' imp detail', `viewImprovement(${JSON.stringify(x.id)})`);
            harvest(`${w}:imp-detail:${x.id}`);
            safe(w + ' imp review', `reviewImprovement(${JSON.stringify(x.id)})`);
            harvest(`${w}:imp-review:${x.id}`);
            safe(w + ' imp edit', `editImprovement(${JSON.stringify(x.id)})`);
            harvest(`${w}:imp-edit:${x.id}`);
            for (let s = 1; s <= 3; s++) {
                safe(w + ' imp step ' + s, `setImpWizStep(${s})`);
                harvest(`${w}:imp-step:${x.id}:${s}`);
            }
        }
        safe(w + ' users', 'try{drawUsers()}catch(e){}');
        harvest(w + ':users');
    }

    safe('vendor profile autofill', `DB=normDB(__fx);ME='t_user1';USER={name:'t_user1',role:'user'};PD={id:'vendor-test',no:'',date:'',currency:'IDR',vendor:'',attn:'',tel:'',email:'',addr:'',items:[],tax:0,terms:'',leadTime:'',delivery:'',note:'',useSig:false};setPOVendorProfile('V1')`);
    check(
        run(`PD.attn==='Attn'&&PD.tel==='021'&&PD.email==='a@b.c'&&PD.addr==='Alamat'`),
        'selecting an existing vendor autofills PO contact details'
    );

    // ---------- analysis ----------
    let rendered = 0;
    const raw = [], inlineData = [], badDesc = [];
    const attrRe = /\bon[a-z]+\s*=\s*("([^"]*)"|'([^']*)')/gi;
    const descRe = /\bdata-on-([a-z]+)\s*=\s*"([^"]*)"/gi;
    const unesc = (s) => s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    const descSeen = new Map();
    for (const h of allHtml) {
        rendered += h.html.length;
        const rm = new RegExp(MARKER_PREFIX + '["\'<>]', 'g').exec(h.html);
        if (rm) raw.push(h.where + ' [' + h.label + '] ...' + h.html.slice(Math.max(0, rm.index - 40), rm.index + 30).replace(/\s+/g, ' '));
        let am;
        attrRe.lastIndex = 0;
        while ((am = attrRe.exec(h.html)) !== null) {
            const val = am[2] !== undefined ? am[2] : am[3];
            for (const id of ids) {
                if (val.includes(id) || val.includes(id.replace(/&/g, '&amp;'))) { inlineData.push(h.where + ' handler contains data: ' + val.slice(0, 90)); break; }
            }
            if (val.includes(MARKER_PREFIX)) inlineData.push(h.where + ' handler contains marker: ' + val.slice(0, 90));
        }
        let dm;
        descRe.lastIndex = 0;
        while ((dm = descRe.exec(h.html)) !== null) {
            const ev = dm[1];
            let arr;
            try { arr = JSON.parse(unesc(dm[2])); } catch (e) { badDesc.push(h.where + ' unparsable descriptor: ' + dm[2].slice(0, 80)); continue; }
            if (!Array.isArray(arr) || typeof arr[0] !== 'string') { badDesc.push(h.where + ' descriptor not [fn,...args]: ' + dm[2].slice(0, 80)); continue; }
            const fnName = arr[0].replace(/^!/, '');
            let isFn = false;
            try { isFn = run(`typeof ${/^[A-Za-z_$][\w$]*$/.test(fnName) ? fnName : 'undefined'}`) === 'function'; } catch (e) { /* ignore */ }
            if (!isFn) badDesc.push(h.where + ' descriptor names unknown function: ' + arr[0]);
            descSeen.set(ev + '|' + JSON.stringify(arr), { ev, arr });
        }
    }
    check(rendered > 20000, 'render check actually produced HTML for the views (not vacuous)', { chars: rendered, fragments: allHtml.length });
    for (const v of ['dash', 'list', 'budget', 'improvements', 'gantt']) {
        check(allHtml.some((h) => h.where.endsWith(':view:' + v) && h.html.length > 200), 'view rendered: ' + v);
    }
    const projectListView = allHtml.filter((h) => h.where.endsWith(':view:list')).map((h) => h.html).join('\n');
    const projectListHeader = (projectListView.match(/<thead>[\s\S]*?<\/thead>/) || [''])[0];
    check(
        projectListView.includes('project-list-table')
            && ['Project', 'Owner / PIC', 'Status', 'Progres', 'Mulai', 'Target'].every((label) => projectListHeader.includes(label)),
        'project list shows core ownership, status, progress and schedule columns'
    );
    check(
        !['PO', 'Tahap saat ini', 'Vendor', 'Quotation', 'Budget', 'Komitmen', 'Aksi'].some((label) => projectListHeader.includes(label)),
        'optional PO and secondary details stay out of the compact project list'
    );
    check(projectListView.includes('openProjectProgress'), 'project progress control opens stage details on demand');
    const projectStageViews = allHtml.filter((h) => h.where.includes(':detail:') && h.where.endsWith(':stage')).map((h) => h.html).join('\n');
    check(
        projectStageViews.includes('project-stage-card')
            && projectStageViews.includes('project-stage-date-fields')
            && projectStageViews.includes('Lihat keterangan'),
        'project stage details use responsive cards with actual dates and documentation controls'
    );
    const progressModals = allHtml.filter((h) => h.where.endsWith(':project-progress-modal')).map((h) => h.html).join('\n');
    check(
        progressModals.includes('progress-stage-list')
            && progressModals.includes('Progress Project')
            && progressModals.includes('Buka detail & dokumentasi'),
        'progress modal presents expandable project stage cards'
    );
    const stageDocViewers = allHtml.filter((h) => h.where.endsWith(':stage-documentation-viewer')).map((h) => h.html).join('\n');
    check(
        stageDocViewers.includes('stage-doc-purpose')
            && stageDocViewers.includes('Keterangan / kondisi')
            && stageDocViewers.includes('bukti.pdf')
            && stageDocViewers.includes('stage-doc-rich')
            && stageDocViewers.includes('Unduh'),
        'stage documentation popup shows clear media cards and download controls'
    );
    const expandedStages = allHtml.filter((h) => h.where.endsWith(':project-progress-expanded-stage')).map((h) => h.html).join('\n');
    check(
        expandedStages.includes('Dokumentasi &amp; kondisi')
            && expandedStages.includes('stage-doc-rich')
            && expandedStages.includes('Unduh'),
        'expanded project stage contains inline documentation cards and media previews'
    );
    const mediaState = run(`(()=>{const stage={id:'media-stage',name:'Stage media',attachments:[{id:'media-image',name:'proof.png',file:'proof.png',description:'Foto hasil pemeriksaan'},{id:'media-video',name:'proof.mp4',file:'proof.mp4',description:'Video hasil pemeriksaan'},{id:'media-pdf',name:'report.pdf',file:'report.pdf',description:'Laporan PDF'},{id:'media-docx',name:'report.docx',file:'report.docx',description:'Laporan Word'}],docLinks:[]};const project={id:'media-project',code:'TEST',name:'Media test',category:'Improvement',pic:'t_user1',status:'On Progress',progress:50,start:'2026-01-01',end:'2026-01-31',stages:[stage]};DB=normDB({projects:[project]});openProjectProgress(project.id);toggleProgressStage(project.id,stage.id);const expanded=$('#modal2').innerHTML;__resetCaptured();openStageMediaViewer(project.id,stage.id,'media-image');const imageViewer=__getCaptured().filter(x=>x.label==='#modal2').map(x=>x.html).join('');__resetCaptured();zoomStageMedia(.25);const zoom=STAGE_MEDIA_VIEW.zoom;closeStageMediaViewer();openStageMediaViewer(project.id,stage.id,'media-video');const videoViewer=__getCaptured().filter(x=>x.label==='#modal2').map(x=>x.html).join('');__resetCaptured();closeM2();DB=normDB(__fx);return {expanded,imageViewer,videoViewer,zoom};})()`);
    check(
        mediaState.expanded.includes('stage-doc-image-open')
            && mediaState.expanded.includes('stage-doc-video')
            && mediaState.expanded.includes('stage-doc-speed')
            && mediaState.expanded.includes('class="stage-pdf-preview"')
            && mediaState.expanded.includes('data-pdf-src=')
            && mediaState.expanded.includes('stage-docx-preview')
            && mediaState.expanded.includes('data-docx-src='),
        'inline stage cards render image, video, scrollable PDF and local DOCX previews'
    );
    check(
        mediaState.imageViewer.includes('stage-media-image')
            && mediaState.imageViewer.includes('zoomStageMedia')
            && mediaState.imageViewer.includes('Unduh')
            && mediaState.zoom === 1.25,
        'image viewer supports in-app zoom and download',
        { imageViewer: mediaState.imageViewer.slice(0, 500), zoom: mediaState.zoom }
    );
    check(
        mediaState.videoViewer.includes('stage-media-player')
            && mediaState.videoViewer.includes('setStageVideoSpeed')
            && mediaState.videoViewer.includes('Unduh'),
        'video viewer supports native playback, speed selection and download',
        { videoViewer: mediaState.videoViewer.slice(0, 500) }
    );
    const stageEditor = allHtml.filter((h) => h.where.includes(':edit:') && h.where.endsWith(':2')).map((h) => h.html).join('\n');
    check(
        stageEditor.includes('stage-manage-list')
            && !stageEditor.includes('class="stt"'),
        'manage stage renders responsive cards instead of a horizontally scrolling table'
    );
    const stageDocEditors = allHtml.filter((h) => h.where.includes(':stage-docs-edit:')).map((h) => h.html).join('\n');
    check(
        stageDocEditors.includes('stage_doc_description')
            && stageDocEditors.includes('ditambahkan pada kartu file setelah unggah')
            && stageDocEditors.includes('stage_doc_link_description')
            && stageDocEditors.includes('Simpan keterangan'),
        'manage stage documentation editor collects and updates per-document explanations'
    );
    const statusModals = allHtml.filter((h) => h.where.endsWith(':dashboard-status-modal')).map((h) => h.html).join('\n');
    check(statusModals.includes('dashboard-status-list') && statusModals.includes('openProjectProgress'), 'dashboard status opens a project list that leads to actual progress details');
    const dashboardView = allHtml.filter((h) => h.where.endsWith(':view:dash')).map((h) => h.html).join('\n');
    check(dashboardView.indexOf('dashboard-progress-board') >= 0, 'dashboard highlights the progress board');
    const customDateControls = allHtml.filter((h) => h.where === 'dashboard:custom-date-controls').map((h) => h.html).join('\n');
    check(
        customDateControls.includes('dashboard-date-toolbar')
            && customDateControls.includes('type="date"')
            && customDateControls.includes('Dari tanggal')
            && customDateControls.includes('Sampai tanggal'),
        'custom dashboard period renders date controls in the compact filter toolbar'
    );
    const budgetView = allHtml.filter((h) => h.where.endsWith(':view:budget')).map((h) => h.html).join('\n');
    check(budgetView.includes('q-project-details'), 'quotation list is grouped into project disclosure panels');
    check(!budgetView.includes('Budget vs Komitmen per Project'), 'budget vs committed chart is hidden from the procurement page');
    check(!/<details class="q-project-details"[^>]*\bopen(?:=|\s|>)/.test(budgetView), 'quotation details are collapsed by default');
    check(allHtml.some((h) => /:detail:/.test(h.where) && h.html.length > 200), 'project detail rendered');
    check(allHtml.some((h) => /:edit:/.test(h.where) && h.html.length > 200), 'project edit steps rendered');
    check(allHtml.some((h) => /:imp-detail:/.test(h.where) && h.html.length > 200), 'improvement detail rendered');
    check(allHtml.some((h) => /:po:/.test(h.where) || /document\.write|window\.open/.test(h.label)), 'PO editor / print document rendered');
    const vendorManager = allHtml.filter((h) => h.where.endsWith(':vendor-manager')).map((h) => h.html).join('\n');
    check(vendorManager.includes('Profil vendor baru') && vendorManager.includes('Alamat vendor'), 'vendor manager renders reusable vendor contact profiles');
    const poVendorForms = allHtml.filter((h) => h.where.endsWith(':purchase-order-vendor-form')).map((h) => h.html).join('\n');
    check(poVendorForms.includes('po-vendor-list') && poVendorForms.includes('Kelola profil vendor'), 'purchase order offers vendor selection and profile management');
    const markerSeen = allHtml.filter((h) => h.html.includes(MARKER_PREFIX)).length;
    check(markerSeen > 10, 'marker data reaches the rendered output (test is meaningful)', markerSeen);

    check(raw.length === 0, 'marker never appears unescaped in rendered HTML', raw.slice(0, 12));
    check(inlineData.length === 0, 'no inline on*= handler interpolates data (ids, names, urls, files)', [...new Set(inlineData)].slice(0, 12));
    check(badDesc.length === 0, 'all data-on-* descriptors are valid and name existing functions', [...new Set(badDesc)].slice(0, 12));

    // ---------- delegated listener really dispatches ----------
    const evTypes = new Set([...descSeen.values()].map((d) => d.ev));
    if (evTypes.size === 0) {
        check(false, 'delegated descriptors (data-on-*) are rendered');
    } else {
        let spyFailures = [];
        for (const ev of evTypes) {
            const handler = listeners.filter((l) => l[0] === ev).map((l) => l[1]).pop();
            if (!handler) { spyFailures.push('no delegated listener registered for ' + ev); continue; }
            for (const d of [...descSeen.values()].filter((x) => x.ev === ev).slice(0, 400)) {
                const fnName = d.arr[0].replace(/^!/, '');
                let got = null;
                try { run(`globalThis.__orig_${fnName}=${fnName};${fnName}=function(){globalThis.__got=Array.prototype.slice.call(arguments);}`); } catch (e) { continue; }
                ctx.__got = null;
                const attrName = 'data-on-' + ev;
                const el = {
                    value: 'VAL', checked: true, parentElement: null, parentNode: null,
                    hasAttribute: (n) => n === attrName, getAttribute: (n) => (n === attrName ? JSON.stringify(d.arr) : null),
                    closest(sel) { return sel.includes(attrName) ? el : null; },
                    matches(sel) { return sel.includes(attrName); },
                };
                const evt = { type: ev, target: el, currentTarget: document, stopPropagation() {}, preventDefault() {}, key: '' };
                try { handler(evt); } catch (e) { spyFailures.push(ev + ' ' + d.arr[0] + ' threw ' + e.message); }
                got = ctx.__got;
                run(`${fnName}=globalThis.__orig_${fnName}`);
                const expect = d.arr.slice(1).map((a) => (a === '@value' ? 'VAL' : a === '@checked' ? true : a === '@el' ? el : a));
                const okArgs = Array.isArray(got) && expect.length === got.length && expect.every((a, i) => a === got[i] || (typeof a === 'object' && got[i] === a));
                if (!okArgs) spyFailures.push(`${ev} ${d.arr[0]} args ${JSON.stringify(got)} != ${JSON.stringify(expect)}`);
            }
        }
        check(spyFailures.length === 0, 'delegated listener calls the named function with decoded arguments', spyFailures.slice(0, 10));
    }

    check(errors.length < 400, 'render drivers ran (stub errors are tolerated)', errors.slice(0, 5));
    if (process.env.PT_DEBUG) process.stderr.write(errors.join('\n') + '\n');
    process.stdout.write('\n__REPORT__' + JSON.stringify({ checks }) + '\n');
    process.exit(0);
})();

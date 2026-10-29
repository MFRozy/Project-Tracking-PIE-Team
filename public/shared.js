'use strict';
/* Kode bersama: dipakai browser (klien) dan server. Jangan diubah sepihak. */
const uid=()=>Date.now().toString(36)+Math.random().toString(36).slice(2,7);
const PALETTE=['#2563eb','#f97316','#14b8a6','#a855f7','#eab308','#ec4899','#64748b'];
const DEFTPL=[
 {id:'tpl_nm',name:'New Machine',category:'New Machine',stages:[{name:'URS',days:14},{name:'Spesifikasi & RFQ',days:10},{name:'Quotation vendor',days:14},{name:'Evaluasi & pemilihan vendor',days:7},{name:'RFA / approval',days:10},{name:'PR & PO',days:7},{name:'Desain & fabrikasi',days:60},{name:'FAT (di vendor)',days:7},{name:'Pengiriman',days:14},{name:'Instalasi',days:10},{name:'SAT',days:7},{name:'Validasi',days:21},{name:'Handover & training',days:7},{name:'Closing',days:5}]},
 {id:'tpl_imp',name:'Improvement',category:'Improvement',stages:[{name:'Identifikasi masalah',days:5},{name:'Rencana & desain',days:7},{name:'Approval',days:5},{name:'Quotation & PO',days:10},{name:'Eksekusi',days:21},{name:'Trial',days:7},{name:'Validasi',days:7},{name:'Closing',days:3}]}
];
const DEFPO={company:'PT COSMAX INDONESIA',docCode:'FR.PRC.001.00',logo:'',
 address:'Jl. Raya Bogor KM. 26,4\nCiracas, Jakarta Timur',contact:'Telp. (021) 29616781-86, Fax. (021) 29479397\nWebsite : www.cosmax.com',
 title:'PURCHASE ORDER',pattern:'{NNN}/PO/PI/{RM}/{YYYY}',dateFmt:'dd-Mmm-yy',lang:'en',tax:11,
 terms:'',leadTime:'',delivery:'PT Cosmax Indonesia',
 shipName:'PT. COSMAX INDONESIA',shipAddr:'Jl. Raya Bogor KM 26,4\nCiracas, Jakarta Timur 13740',shipTel:'(021) 29616781-86',shipAttn:'',
 billing:'Payment Billing Must Be Attached :\n1) Copy PO & Original Tax Invoice\n2) Original Receipt & Original Delivery Order\n3) COA ( Certificate of Analysis )\n4) Tax Number/NPWP/NPPKP PT. Cosmax Indonesia : 03.178.339.2-009.000\n5) Address of tax number : Jl. Raya Bogor Km 26, 4 RT.003 RW.007 Ciracas - Ciracas Jakarta Timur',
 notice:"PT Cosmax Indonesia is committed to implementing an Anti-Bribery Management System and maintaining a zero-tolerance policy against bribery, gratuities, corruption, collusion, nepotism, and any other improper conduct, in accordance with the Company's policies and applicable laws and regulations.\n\nIf any violation or misconduct is identified, Business Partners (Vendors) may report it via e-mail: internal.audit@cosmax.com\n\nPT Cosmax Indonesia is committed to protecting whistleblowers and maintaining the confidentiality of all reports received.",
 note:'',sigDefault:false,
 sign:[{label:'Approved',name:'',title:'',img:''},{label:'',name:'',title:'',img:''},{label:'',name:'',title:'',img:''}]};
function normSigners(a){a=Array.isArray(a)?a:[];const o=[];for(let i=0;i<3;i++){let x=a[i];if(typeof x==='string')x={label:x};x=x||{};o.push({label:x.label||'',name:x.name||'',title:x.title||'',img:/^data:image\//.test(x.img||'')?x.img:''});}return o;}
function normP(p){
  const o=Object.assign({id:uid(),code:'',name:'',category:'',status:'Not Started',priority:'Medium',pic:'',area:'',start:'',end:'',progress:0,desc:'',vendor:'',budget:0,actual:0,po:'',quotations:[],milestones:[],stages:[],pos:[],log:[],updatedAt:0,updatedBy:''},p||{});
  o.quotations=(o.quotations||[]).map(q=>Object.assign({id:uid(),vendor:'',amount:0,status:'Diterima',date:'',valid:'',no:'',link:'',file:'',fileName:'',note:''},q));
  o.stages=(o.stages||[]).map(s=>{
    const stg=Object.assign({id:uid(),name:'',pic:'',ps:'',pf:'',as:'',af:'',status:'Not Started',prog:0,note:'',par:false},s);
    if(stg.af||stg.status==='Completed')stg.prog=100;
    else if(stg.status==='Not Started'&&!stg.as)stg.prog=0;
    else stg.prog=Math.max(0,Math.min(100,Number(stg.prog)||0));
    return stg;
  });
  o.pos=(o.pos||[]).map(x=>Object.assign({id:uid(),no:'',date:'',qid:'',vendor:'',currency:'',addr:'',attn:'',qno:'',subject:'',items:[],tax:0,terms:'',delivery:'',note:'',by:'',at:0},x));
  o.pos.forEach(x=>{x.items=(x.items||[]).map(i=>Object.assign({id:uid(),desc:'',qty:1,unit:'Unit',price:0,due:''},i));});
  o.milestones=o.milestones||[];o.log=o.log||[];
  o.progress=Number(o.progress)||0;o.budget=Number(o.budget)||0;o.actual=Number(o.actual)||0;
  return o;
}
function normImprovement(x){
  const o=Object.assign({id:uid(),title:'',department:'',submittedBy:'',submittedAt:0,type:'efficiency',issue:'',background:'',purpose:'',goals:'',savingMp:'',qualityMetric:'',attachments:[],links:[],status:'Baru',piOwner:'',piFeedback:'',piSuggestion:'',piAction:'',piReason:'',decision:'Pending',feedbackLog:[],projectId:'',updatedAt:0,updatedBy:''},x||{});
  o.feedbackLog=Array.isArray(o.feedbackLog)?o.feedbackLog:[];
  o.attachments=Array.isArray(o.attachments)?o.attachments.filter(a=>a&&typeof a==='object'&&typeof a.file==='string').map(a=>Object.assign({id:uid(),name:'Lampiran',file:'',size:0,type:''},a)):[];
  o.links=Array.isArray(o.links)?o.links.filter(link=>link&&typeof link.url==='string').map(link=>Object.assign({id:uid(),title:'Tautan',url:''},link)):[];
  return o;
}
function normDB(d){
  const ds=d.settings||{};
  const st=Object.assign({categories:[{name:'Improvement',color:'#2563eb'},{name:'New Machine',color:'#f97316'}],currency:'IDR',settingsAt:0,useBudget:false,appName:'PI Project Management',autoProgress:true,autoDates:true},ds);
  st.po=Object.assign({},DEFPO,ds.po||{});st.po.sign=normSigners(st.po.sign);
  if(!Array.isArray(st.templates))st.templates=JSON.parse(JSON.stringify(DEFTPL));
  st.templates=st.templates.filter(t=>t&&typeof t==='object').map(t=>({id:t.id||uid(),name:t.name||'Template',category:t.category||'',owner:t.owner||'',stages:Array.isArray(t.stages)?t.stages.map(s=>({name:(s&&s.name)||'',pic:(s&&s.pic)||'',days:Math.max(1,Number(s&&s.days)||1),par:!!(s&&s.par)})):[]}));
  const o={version:4,settings:st,projects:(d.projects||[]).map(normP),improvements:(d.improvements||[]).map(normImprovement),deleted:d.deleted||{}};
  const names=o.settings.categories.map(c=>c.name);
  o.projects.forEach(p=>{if(p.category&&!names.includes(p.category)){names.push(p.category);o.settings.categories.push({name:p.category,color:PALETTE[names.length%PALETTE.length]});}});
  return o;
}
function mergeDB(a,b){
  const out={version:4,settings:((b.settings.settingsAt||0)>(a.settings.settingsAt||0))?b.settings:a.settings,projects:[],improvements:[],deleted:{}};
  [a.deleted||{},b.deleted||{}].forEach(dl=>{for(const k in dl)out.deleted[k]=Math.max(out.deleted[k]||0,dl[k]);});
  const map={};
  (b.projects||[]).concat(a.projects||[]).forEach(p=>{const c=map[p.id];if(!c||(p.updatedAt||0)>=(c.updatedAt||0))map[p.id]=p;});
  for(const id in map){if((out.deleted[id]||0)>(map[id].updatedAt||0))continue;out.projects.push(map[id]);}
  const improvementMap={};
  (b.improvements||[]).concat(a.improvements||[]).forEach(x=>{const c=improvementMap[x.id];if(!c||(x.updatedAt||0)>(c.updatedAt||0))improvementMap[x.id]=x;});
  out.improvements=Object.values(improvementMap);
  return normDB(out);
}
const sig=d=>d.projects.map(p=>p.id+':'+p.updatedAt).sort().join('|')+'#'+(d.improvements||[]).map(x=>x.id+':'+x.updatedAt).sort().join('|')+'#'+Object.keys(d.deleted).sort().join(',');

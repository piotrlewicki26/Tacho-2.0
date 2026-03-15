<?php
/**
 * TachoPro 2.0 – DDD File Parser / Tachograph Analyzer
 *
 * Wgrywa plik DDD / V1B / BIN i wyświetla:
 *  – Dane kierowcy i pojazdu
 *  – Aktywność dzienna (wykresy tygodniowe z zoomem i zaznaczaniem)
 *  – Naruszenia EU 561/2006 z taryfikatorem kar ITD
 *  – Przekroczenia granic
 *
 * Dostęp: wyłącznie superadmin.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();

if (!hasRole('superadmin')) {
    flashSet('danger', 'Dostęp tylko dla Superadmin.');
    redirect('/dashboard.php');
}

$pageTitle  = 'Parser DDD – Tachograf Analyzer';
$activePage = 'ddd_parser';

require __DIR__ . '/../../templates/header.php';
?>

<div class="tp-content" style="padding:0; overflow:visible;">
  <div id="tacho-root" style="min-height:600px;"></div>
</div>

<!-- React 18 + ReactDOM + Babel standalone (JSX in-browser) -->
<script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
  #tacho-root button:focus { outline: none; }
  #tacho-root * { box-sizing: border-box; }
  @media print {
    .tp-topbar, .tp-sidebar, .tp-footer, nav, header { display: none !important; }
    #tacho-root { padding: 0 !important; }
  }
</style>

<script type="text/babel">
const { useState, useRef, useEffect, useMemo, useCallback } = React;

// ═══════════════════════════════════════════════════════════════
// TACHOGRAPH CONSTANTS
// ═══════════════════════════════════════════════════════════════
const EU = { maxWeek:3360, maxDay:540, maxDayEx:600, minRest:660, maxCont:270 };
const LW=74, T1Y=32, T1H=36, T2Y=76, T2H=18, AXY=102, RH=120;
const ACT_FILL  =["#80DEEA","#9FA8DA","#FFCC80","#EF9A9A"];
const ACT_SOLID =["#00ACC1","#5C6BC0","#EF6C00","#E53935"];
const ACT_STROKE=["#00838F","#3949AB","#BF360C","#C62828"];
const ACT_TEXT  =["#006064","#1A237E","#BF360C","#B71C1C"];
const ACT_NAME  =["Odpoczynek","Dyspozycyjnosc","Praca","Jazda"];
const ACT_HFRAC =[0.30, 0.52, 0.72, 1.00];

const CC={
  PL:{bg:"#FFEBEE",bd:"#E53935",tx:"#C62828"},DE:{bg:"#FFFDE7",bd:"#F9A825",tx:"#E65100"},
  CZ:{bg:"#E3F2FD",bd:"#1E88E5",tx:"#1565C0"},SK:{bg:"#E8F5E9",bd:"#43A047",tx:"#2E7D32"},
  AT:{bg:"#FFF3E0",bd:"#EF6C00",tx:"#BF360C"},HU:{bg:"#F3E5F5",bd:"#8E24AA",tx:"#6A1B9A"},
  FR:{bg:"#E8EAF6",bd:"#3949AB",tx:"#283593"},NL:{bg:"#FBE9E7",bd:"#FF7043",tx:"#BF360C"},
};
function ccStyle(code){ return CC[code]||{bg:"#F5F5F5",bd:"#9E9E9E",tx:"#616161"}; }

const BORDER_DB={
  "PL-DE":[{name:"Slubice/Frankfurt(Oder)",lat:52.3483,lon:14.5533,road:"A2/E30"},{name:"Olszyna/Forst",lat:51.355,lon:15.001,road:"A18/E36"},{name:"Zgorzelec/Goerlitz",lat:51.153,lon:14.997,road:"A4/E40"},{name:"Kolbaskowo/Pomellen",lat:53.533,lon:14.42,road:"A6/E28"}],
  "DE-PL":[{name:"Frankfurt(Oder)/Slubice",lat:52.3483,lon:14.5533,road:"A2/E30"},{name:"Forst/Olszyna",lat:51.355,lon:15.001,road:"A18/E36"},{name:"Goerlitz/Zgorzelec",lat:51.153,lon:14.997,road:"A4/E40"}],
  "PL-CZ":[{name:"Cieszyn/Cesky Tesin",lat:49.7497,lon:18.6321,road:"E75"},{name:"Kudowa-Slone/Nachod",lat:50.4308,lon:16.2467,road:"E67"}],
  "CZ-PL":[{name:"Cesky Tesin/Cieszyn",lat:49.7497,lon:18.6321,road:"E75"},{name:"Nachod/Kudowa-Slone",lat:50.4308,lon:16.2467,road:"E67"}],
  "PL-SK":[{name:"Chyzne/Trstena",lat:49.4167,lon:19.6167,road:"E77"},{name:"Zwardon/Makov",lat:49.5,lon:18.9667,road:"E75"}],
  "SK-PL":[{name:"Trstena/Chyzne",lat:49.4167,lon:19.6167,road:"E77"},{name:"Makov/Zwardon",lat:49.5,lon:18.9667,road:"E75"}],
  "CZ-SK":[{name:"Mosty u Jablunkova/Cadca",lat:49.5397,lon:18.7667,road:"E75"}],
  "SK-CZ":[{name:"Cadca/Mosty u Jablunkova",lat:49.5397,lon:18.7667,road:"E75"}],
  "SK-HU":[{name:"Sturovo/Esztergom",lat:47.7986,lon:18.7036,road:"E77"},{name:"Komarno/Komarom",lat:47.7595,lon:18.1286,road:"E575"}],
  "HU-SK":[{name:"Esztergom/Sturovo",lat:47.7986,lon:18.7036,road:"E77"}],
  "DE-NL":[{name:"Venlo/Kaldenkirchen",lat:51.3667,lon:6.1675,road:"A61/E31"},{name:"Oldenzaal/Bad Bentheim",lat:52.3133,lon:6.9286,road:"A1/E30"},{name:"Emmerich/Elten",lat:51.8386,lon:6.2428,road:"A3/E35"}],
  "NL-DE":[{name:"Kaldenkirchen/Venlo",lat:51.3667,lon:6.1675,road:"A61/E31"},{name:"Bad Bentheim/Oldenzaal",lat:52.3133,lon:6.9286,road:"A1/E30"}],
  "DE-AT":[{name:"Freilassing/Salzburg",lat:47.7939,lon:12.9583,road:"A1/E60"},{name:"Kufstein/Kiefersfelden",lat:47.6097,lon:12.1764,road:"A93/E45"}],
  "AT-DE":[{name:"Salzburg/Freilassing",lat:47.7939,lon:12.9583,road:"A1/E60"},{name:"Kiefersfelden/Kufstein",lat:47.6097,lon:12.1764,road:"A93/E45"}],
};
function getCrossings(from,to){const key=from+"-"+to;return BORDER_DB[key]||[{name:from+" > "+to+" (brak danych)",lat:50.06,lon:19.94,road:"-"}];}

// ═══════════════════════════════════════════════════════════════
// DELEGATION CONSTANTS
// ═══════════════════════════════════════════════════════════════
const DEFAULT_COUNTRIES=[
  {code:"DE",name:"Niemcy",flag:"🇩🇪",dietRate:49,minWageEUR:12.41,currency:"EUR"},
  {code:"FR",name:"Francja",flag:"🇫🇷",dietRate:50,minWageEUR:11.65,currency:"EUR"},
  {code:"NL",name:"Holandia",flag:"🇳🇱",dietRate:45,minWageEUR:13.27,currency:"EUR"},
  {code:"BE",name:"Belgia",flag:"🇧🇪",dietRate:45,minWageEUR:11.08,currency:"EUR"},
  {code:"IT",name:"Włochy",flag:"🇮🇹",dietRate:48,minWageEUR:9.50,currency:"EUR"},
  {code:"ES",name:"Hiszpania",flag:"🇪🇸",dietRate:50,minWageEUR:9.10,currency:"EUR"},
  {code:"AT",name:"Austria",flag:"🇦🇹",dietRate:52,minWageEUR:12.38,currency:"EUR"},
  {code:"CH",name:"Szwajcaria",flag:"🇨🇭",dietRate:88,minWageEUR:24.00,currency:"CHF"},
  {code:"NO",name:"Norwegia",flag:"🇳🇴",dietRate:82,minWageEUR:20.00,currency:"NOK"},
  {code:"SE",name:"Szwecja",flag:"🇸🇪",dietRate:64,minWageEUR:14.00,currency:"SEK"},
  {code:"DK",name:"Dania",flag:"🇩🇰",dietRate:76,minWageEUR:18.00,currency:"DKK"},
  {code:"CZ",name:"Czechy",flag:"🇨🇿",dietRate:45,minWageEUR:5.33,currency:"CZK"},
  {code:"SK",name:"Słowacja",flag:"🇸🇰",dietRate:45,minWageEUR:5.74,currency:"EUR"},
  {code:"HU",name:"Węgry",flag:"🇭🇺",dietRate:50,minWageEUR:4.50,currency:"HUF"},
  {code:"RO",name:"Rumunia",flag:"🇷🇴",dietRate:45,minWageEUR:3.74,currency:"RON"},
  {code:"PL",name:"Polska",flag:"🇵🇱",dietRate:45,minWageEUR:5.82,currency:"PLN"},
];
const MOBILITY_PACKAGE_INFO={
  cabotage:{label:"Kabotaż",description:"Maks. 3 operacje w 7 dni"},
  crossTrade:{label:"Cross-trade",description:"Każda operacja cross-trade"},
  international:{label:"Tranzyt międzynarodowy",description:"Przy wjeździe do kraju"},
};
const SAMPLE_CSV=`imie,nazwisko,pesel,nr_prawa_jazdy,kategoria,data_zatrudnienia,wynagrodzenie_podstawowe
Jan,Kowalski,85010112345,PL123456,C+E,2020-03-15,5500`;

// ═══════════════════════════════════════════════════════════════
// SHARED UTILITIES
// ═══════════════════════════════════════════════════════════════
function isoWeek(d){const t=new Date(Date.UTC(d.getFullYear(),d.getMonth(),d.getDate()));t.setUTCDate(t.getUTCDate()+4-(t.getUTCDay()||7));return Math.ceil((((t-new Date(Date.UTC(t.getUTCFullYear(),0,1)))/864e5)+1)/7);}
function monDay(d){const r=new Date(d),dw=r.getDay();r.setDate(r.getDate()-(dw===0?6:dw-1));r.setHours(0,0,0,0);return r;}
function addD(d,n){const r=new Date(d);r.setDate(r.getDate()+n);return r;}
function hhmm(m){return String(Math.floor(m/60)).padStart(2,"0")+":"+String(m%60).padStart(2,"0");}
function hm(m){const h=Math.floor(m/60),mm=m%60;return mm?h+"h "+mm+"m":h+"h";}
function fmtDate(d){return String(d.getDate()).padStart(2,"0")+"."+String(d.getMonth()+1).padStart(2,"0")+"."+d.getFullYear();}
function fmtNum(n,decimals=2){return Number(n).toLocaleString("pl-PL",{minimumFractionDigits:decimals,maximumFractionDigits:decimals});}
function clamp(v,a,b){return Math.max(a,Math.min(b,v));}
function diffDays(from,to){if(!from||!to)return 0;return Math.max(0,Math.round((new Date(to)-new Date(from))/86400000));}
function toInputDate(d){if(!d)return"";const dd=new Date(d);return dd.getFullYear()+"-"+String(dd.getMonth()+1).padStart(2,"0")+"-"+String(dd.getDate()).padStart(2,"0");}

function dayStatus(slots){
  if(!slots||!slots.length)return null;
  const drive=slots.filter(s=>s.activity===3).reduce((a,s)=>a+s.duration,0);
  const rest=slots.filter(s=>s.activity===0).reduce((a,s)=>a+s.duration,0);
  let e=false,w=false;
  if(drive>EU.maxDayEx)e=true;else if(drive>EU.maxDay)w=true;
  let cont=0,maxC=0;
  slots.forEach(s=>{if(s.activity===3){cont+=s.duration;if(cont>maxC)maxC=cont;}else if(s.activity===0&&s.duration>=15)cont=0;});
  if(maxC>EU.maxCont)w=true;
  if(rest<EU.minRest&&drive>0)w=true;
  return e?"error":w?"warn":"ok";
}

function parseCSV(text){
  const lines=text.trim().split("\n");
  const headers=lines[0].split(",").map(h=>h.trim());
  return lines.slice(1).map(line=>{const vals=line.split(",").map(v=>v.trim());return Object.fromEntries(headers.map((h,i)=>[h,vals[i]||""]));});
}

function parseDDD(buffer){
  // EU tachograph driver card – circular buffer parser
  // Confirmed bit layout (binary analysis):
  //   bit15=slot(0=driver), bit14=manning, bits13-11=act(0=REST,1=AVAIL,2=WORK,3=DRIVE), bits10-0=time_min
  // Record header: TimeReal(4) + presenceCounter(2) + distanceKm(2) + entries(2*n)
  // presenceCounter increments by 1 per day → use it for chronological ordering
  // Driver card buffer is circular: new data at "head", wraps around

  const u8=new Uint8Array(buffer),dv=new DataView(buffer);
  const len=u8.length;

  // ── 1. Driver name ──
  let driver=null,vehicle=null;
  const readStr=(s,n)=>{let o='';for(let k=0;k<n&&s+k<len;k++){const b=u8[s+k];o+=(b>=32&&b<127)?String.fromCharCode(b):'\0';}return o;};
  for(let i=0;i<len-4;i++){
    if(u8[i]!==0x05||u8[i+1]!==0x20)continue;
    const bl=dv.getUint16(i+2,false);
    if(bl<40||bl>3000||i+4+bl>len)continue;
    for(let k=0;k<bl-72;k++){
      const b=u8[i+4+k];
      if(b<65||b>90)continue;
      const sn=readStr(i+4+k,36).replace(/\0/g,'').trim();
      const fn=readStr(i+4+k+36,36).replace(/\0/g,'').trim();
      if(sn.length>=3&&/^[A-Z][a-z]{2}/.test(sn)&&fn.length>=2){driver=(fn+' '+sn).trim();break;}
    }
    if(driver)break;
  }

  // ── 2. Vehicle reg ──
  for(let i=0;i<len-14;i++){
    const s=readStr(i,14).trim();
    if(/^[A-Z]{2,4}\s[A-Z0-9]{4,6}$/.test(s)){vehicle=s;break;}
  }

  // ── 3. Find ALL candidate record headers (pres 500-8000, dist≤1100, year 2023-2027) ──
  const cands=[];
  for(let i=0;i<len-8;i+=2){
    let ts,yr;
    try{ts=dv.getUint32(i,false);yr=new Date(ts*1000).getUTCFullYear();}catch(e_){continue;}
    if(yr<2023||yr>2027)continue;
    const pres=dv.getUint16(i+4,false);
    const dist=dv.getUint16(i+6,false);
    if(pres<500||pres>8000||dist>1100)continue;
    cands.push({off:i,ts,pres,dist});
  }

  // ── 4. Deduplicate by date: per date keep the record with median presenceCounter ──
  const byDate={};
  for(const c of cands){
    const d=new Date(c.ts*1000);
    const k=d.toISOString().slice(0,10);
    if(!byDate[k])byDate[k]=[];
    byDate[k].push(c);
  }
  // Pick the candidate with presCounter closest to the median for that date
  const deduped=Object.values(byDate).map(arr=>{
    arr.sort((a,b)=>a.pres-b.pres);
    return arr[Math.floor(arr.length/2)]; // median
  });

  // ── 5. Sort by presenceCounter (chronological) ──
  deduped.sort((a,b)=>a.pres-b.pres);

  // ── 6. Filter outlier presenceCounts (remove records far from main cluster) ──
  const presVals=deduped.map(r=>r.pres).sort((a,b)=>a-b);
  const p25=presVals[Math.floor(presVals.length*0.25)],p75=presVals[Math.floor(presVals.length*0.75)];
  const iqr=p75-p25;
  const presMin=p25-3*iqr,presMax=p75+3*iqr;
  const filtered=deduped.filter(r=>r.pres>=presMin&&r.pres<=presMax);

  // ── 7. Build a lookup of offset→next-record-offset for bounded entry scanning ──
  const offsets=filtered.map(r=>r.off).sort((a,b)=>a-b);
  const nextOff=(off)=>{
    const idx=offsets.indexOf(off);
    return idx>=0&&idx<offsets.length-1?offsets[idx+1]:off+400;
  };

  // ── 8. Parse activity entries for each record ──
  const mkSlots=pts=>{
    // Strictly monotonic filter
    const mono=[];let lt=-1;
    for(const p of pts){if(p.tmin>lt){mono.push(p);lt=p.tmin;}}
    return mono.map((p,i)=>({activity:p.act,startMin:p.tmin,
      endMin:i<mono.length-1?mono[i+1].tmin:1440}))
      .filter(s=>s.endMin>s.startMin).map(s=>({...s,duration:s.endMin-s.startMin}));
  };

  const days=[];
  for(const r of filtered){
    const bound=Math.min(nextOff(r.off),r.off+600,len-1);
    const pts=[];
    for(let j=r.off+8;j<bound-1;j+=2){
      const raw=dv.getUint16(j,false);
      const slot=(raw>>15)&1,act=(raw>>11)&7,tmin=raw&0x7FF;
      if(slot===0&&act<=3&&tmin>=0&&tmin<=1440)pts.push({act,tmin});
    }
    const slots=mkSlots(pts);
    const total=slots.reduce((s,x)=>s+x.duration,0);
    if(total<1350||total>1460)continue;
    days.push({date:new Date(r.ts*1000),slots,distance:r.dist,crossings:[],vehicle});
  }

  if(!days.length)return null;
  return{driver,days};
}

function rnd(a,b){return a+Math.floor(Math.random()*(b-a+1));}
const SCENARIOS=[
  (days)=>{if(days[1])days[1].crossings=[{atMin:rnd(480,540),from:"PL",to:"DE"}];if(days[2])days[2].crossings=[{atMin:rnd(900,960),from:"DE",to:"PL"}];},
  (days)=>{if(days[1])days[1].crossings=[{atMin:rnd(600,660),from:"PL",to:"CZ"}];if(days[2])days[2].crossings=[{atMin:rnd(480,540),from:"CZ",to:"SK"}];if(days[3])days[3].crossings=[{atMin:rnd(600,660),from:"SK",to:"CZ"},{atMin:rnd(960,1020),from:"CZ",to:"PL"}];},
  (days)=>{if(days[0])days[0].crossings=[{atMin:rnd(700,780),from:"PL",to:"DE"}];if(days[1])days[1].crossings=[{atMin:rnd(600,660),from:"DE",to:"NL"}];if(days[2])days[2].crossings=[{atMin:rnd(720,780),from:"NL",to:"DE"}];if(days[3])days[3].crossings=[{atMin:rnd(840,900),from:"DE",to:"PL"}];},
];
function genDemo(){
  const ws=monDay(new Date()),days=[];
  const vehicles=["WPR 5573T","WPR 5573T","KR 12345A","KR 12345A","WPR 5573T"];
  for(let w=-4;w<=0;w++){
    const wd=[];const veh=vehicles[(w+4)%vehicles.length];
    for(let d=0;d<7;d++){
      const date=addD(ws,w*7+d);
      if(d>=5){const day={date,distance:0,slots:[{activity:0,startMin:0,endMin:1440,duration:1440}],crossings:[],vehicle:veh};days.push(day);wd.push(day);continue;}
      let t=rnd(300,420);
      const slots=[{activity:0,startMin:0,endMin:t,duration:t}];
      const push=(act,dur)=>{slots.push({activity:act,startMin:t,endMin:t+dur,duration:dur});t+=dur;};
      push(2,rnd(10,20));push(3,rnd(170,260));push(0,rnd(45,55));push(3,rnd(140,220));
      if(rnd(0,1))push(1,rnd(15,35));
      push(3,rnd(50,110));push(2,rnd(10,20));
      slots.push({activity:0,startMin:t,endMin:1440,duration:1440-t});
      const day={date,distance:rnd(260,730),slots,crossings:[],vehicle:veh};
      days.push(day);wd.push(day);
    }
    SCENARIOS[(w+4)%SCENARIOS.length](wd);
  }
  return{driver:"Jan Kowalski",days,demo:true};
}

// ─── AUTO-IMPORT LOGIC ────────────────────────────────────────
function extractDelegationFromTacho(tachoData) {
  if (!tachoData || !tachoData.days || !tachoData.days.length) return null;
  const sortedDays = [...tachoData.days].sort((a, b) => a.date - b.date);
  const nameParts = (tachoData.driver || '').split(' ').filter(Boolean);
  const imie = nameParts[0] || '';
  const nazwisko = nameParts.slice(1).join(' ') || '';
  const firstDay = sortedDays[0].date;
  const lastDay = sortedDays[sortedDays.length - 1].date;

  const allCrossings = [];
  sortedDays.forEach(day => {
    (day.crossings || []).forEach(c => {
      allCrossings.push({ date: new Date(day.date), atMin: c.atMin, from: c.from || 'PL', to: c.to });
    });
  });
  allCrossings.sort((a, b) => a.date - b.date || a.atMin - b.atMin);

  const countryMap = {};
  let currentCountry = allCrossings.length ? allCrossings[0].from : 'PL';
  sortedDays.forEach(day => {
    const dayCrossings = allCrossings.filter(c => c.date.toDateString() === day.date.toDateString());
    const driveMin = day.slots.filter(s => s.activity === 3).reduce((a, s) => a + s.duration, 0);
    const driveHours = driveMin / 60;
    if (!countryMap[currentCountry]) countryMap[currentCountry] = { days: 0, hours: 0 };
    countryMap[currentCountry].days += 1;
    countryMap[currentCountry].hours += driveHours;
    if (dayCrossings.length) currentCountry = dayCrossings[dayCrossings.length - 1].to;
  });

  const trasa = Object.entries(countryMap)
    .filter(([, v]) => v.days > 0)
    .map(([country, v]) => ({
      country,
      days: v.days,
      hours: Math.max(1, Math.min(13, Math.round(v.hours / v.days) || 8)),
      operationType: country === 'PL' ? 'international' : 'cabotage',
      kilometers: 0,
    }));

  const vehicle = sortedDays.find(d => d.vehicle)?.vehicle || '';
  return {
    driver: { imie, nazwisko, pesel: '', nr_prawa_jazdy: '', kategoria: 'C+E', data_zatrudnienia: '', wynagrodzenie_podstawowe: '' },
    trip: {
      nr_delegacji: `DEL/${new Date().getFullYear()}/${String(Math.floor(Math.random()*999)+1).padStart(3,'0')}`,
      data_wyjazdu: toInputDate(firstDay),
      data_powrotu: toInputDate(lastDay),
      nr_rejestracyjny: vehicle,
      cel_podrozy: '',
      trasa: trasa.length ? trasa : [{ country: 'PL', days: 1, hours: 8, operationType: 'international', kilometers: 0 }],
    }
  };
}

// ═══════════════════════════════════════════════════════════════
// TACHOGRAPH COMPONENTS
// ═══════════════════════════════════════════════════════════════
function TachoSym({act,cx,cy,s}){
  const sc=s||1;const col=ACT_TEXT[act];
  if(act===0)return(<g transform={"translate("+cx+","+cy+") scale("+sc+")"} style={{pointerEvents:"none"}}><rect x={-6} y={1} width={12} height={5} rx={1.5} fill={col} opacity={0.85}/><rect x={-6} y={-2} width={5} height={3.5} rx={1.5} fill={col} opacity={0.7}/><rect x={-7} y={-4} width={2} height={9} rx={1} fill={col} opacity={0.9}/><rect x={5} y={-1} width={2} height={7} rx={1} fill={col} opacity={0.9}/></g>);
  if(act===3){const r=5.5,ri=2;return(<g transform={"translate("+cx+","+cy+") scale("+sc+")"} style={{pointerEvents:"none"}}><circle r={r} fill="none" stroke={col} strokeWidth={1.8} opacity={0.9}/><circle r={ri} fill={col} opacity={0.85}/><line x1={0} y1={-ri} x2={0} y2={-r} stroke={col} strokeWidth={1.4}/><line x1={ri*0.87} y1={ri*0.5} x2={r*0.87} y2={r*0.5} stroke={col} strokeWidth={1.4}/><line x1={-ri*0.87} y1={ri*0.5} x2={-r*0.87} y2={r*0.5} stroke={col} strokeWidth={1.4}/></g>);}
  if(act===2)return(<g transform={"translate("+cx+","+cy+") scale("+sc+")"} style={{pointerEvents:"none"}}><line x1={-4} y1={4} x2={1} y2={-1} stroke={col} strokeWidth={1.6} strokeLinecap="round"/><rect x={0} y={-5} width={5} height={3} rx={0.8} fill={col} opacity={0.9} transform="rotate(-45,2.5,-3.5)"/><line x1={4} y1={4} x2={-1} y2={-1} stroke={col} strokeWidth={1.6} strokeLinecap="round"/><rect x={-5} y={-5} width={5} height={3} rx={0.8} fill={col} opacity={0.9} transform="rotate(45,-2.5,-3.5)"/></g>);
  if(act===1)return(<g transform={"translate("+cx+","+cy+") scale("+sc+")"} style={{pointerEvents:"none"}}><polygon points="-5,-5 5,-5 0,0" fill={col} opacity={0.6}/><polygon points="-5,5 5,5 0,0" fill={col} opacity={0.85}/><line x1={-5} y1={-5} x2={5} y2={-5} stroke={col} strokeWidth={1.5} strokeLinecap="round"/><line x1={-5} y1={5} x2={5} y2={5} stroke={col} strokeWidth={1.5} strokeLinecap="round"/></g>);
  return null;
}

function CrossingModal({crossing,onClose}){
  const [idx,setIdx]=useState(0);
  if(!crossing)return null;
  const {from,to,date,timeLabel}=crossing;
  const options=getCrossings(from,to);
  const safeIdx=Math.min(idx,options.length-1);
  const loc=options[safeIdx];
  const cs=ccStyle(to);const csF=ccStyle(from);
  return(
    <div onClick={onClose} style={{position:"fixed",inset:0,background:"rgba(0,0,0,0.45)",zIndex:10000,display:"flex",alignItems:"center",justifyContent:"center"}}>
      <div onClick={e=>e.stopPropagation()} style={{background:"#FFF",borderRadius:8,width:380,maxWidth:"95vw",boxShadow:"0 20px 60px rgba(0,0,0,0.3)",overflow:"hidden"}}>
        <div style={{padding:"12px 16px",background:"#F8F9FB",borderBottom:"1px solid #E0E4E8",display:"flex",alignItems:"center",gap:8}}>
          <div style={{padding:"2px 8px",background:csF.bg,border:"1px solid "+csF.bd,borderRadius:4,fontSize:11,fontWeight:700,color:csF.tx}}>{from}</div>
          <span style={{fontSize:14,color:"#9AA0AA"}}>→</span>
          <div style={{padding:"2px 8px",background:cs.bg,border:"1px solid "+cs.bd,borderRadius:4,fontSize:11,fontWeight:700,color:cs.tx}}>{to}</div>
          <div style={{marginLeft:"auto",fontSize:10,color:"#9AA0AA"}}>{date} {timeLabel}</div>
          <button onClick={onClose} style={{background:"none",border:"none",fontSize:16,color:"#9AA0AA",cursor:"pointer",padding:"0 2px"}}>✕</button>
        </div>
        <div style={{padding:"14px 16px"}}>
          <div style={{fontSize:11,fontWeight:700,color:"#5A6070",marginBottom:8,textTransform:"uppercase",letterSpacing:1}}>Przejście graniczne</div>
          {options.map((o,i)=>(
            <div key={i} onClick={()=>setIdx(i)} style={{padding:"8px 12px",marginBottom:6,borderRadius:6,border:"1.5px solid "+(i===safeIdx?cs.bd:"#E0E4E8"),background:i===safeIdx?cs.bg:"#FAFBFC",cursor:"pointer",transition:"all .15s"}}>
              <div style={{fontWeight:600,fontSize:12,color:i===safeIdx?cs.tx:"#1A2030"}}>{o.name}</div>
              <div style={{fontSize:10,color:"#9AA0AA",marginTop:2}}>Droga: {o.road} | {o.lat.toFixed(4)}°N {o.lon.toFixed(4)}°E</div>
            </div>
          ))}
          <a href={`https://maps.google.com/?q=${loc.lat},${loc.lon}`} target="_blank" rel="noreferrer"
            style={{display:"block",marginTop:8,padding:"8px",background:cs.bg,border:"1px solid "+cs.bd,borderRadius:6,textAlign:"center",fontSize:12,fontWeight:600,color:cs.tx,textDecoration:"none"}}>
            📍 Otwórz w Google Maps
          </a>
        </div>
      </div>
    </div>
  );
}

function DayModal({day,onClose}){
  const dow=["Niedziela","Poniedzialek","Wtorek","Sroda","Czwartek","Piatek","Sobota"];
  if(!day)return null;
  const dowd=day.date.getDay();
  const drive=day.slots.filter(s=>s.activity===3).reduce((a,s)=>a+s.duration,0);
  const work=day.slots.filter(s=>s.activity===2).reduce((a,s)=>a+s.duration,0);
  const rest=day.slots.filter(s=>s.activity===0).reduce((a,s)=>a+s.duration,0);
  const avail=day.slots.filter(s=>s.activity===1).reduce((a,s)=>a+s.duration,0);
  const st=dayStatus(day.slots);
  const stCol=st==="error"?"#E53935":st==="warn"?"#FF9800":"#43A047";
  const stLbl=st==="error"?"Naruszenie":st==="warn"?"Ostrzezenie":"Zgodny";
  return(
    <div onClick={onClose} style={{position:"fixed",inset:0,background:"rgba(0,0,0,0.5)",zIndex:10000,display:"flex",alignItems:"center",justifyContent:"center",padding:16}}>
      <div onClick={e=>e.stopPropagation()} style={{background:"#FFF",borderRadius:8,width:560,maxWidth:"100%",maxHeight:"85vh",display:"flex",flexDirection:"column",boxShadow:"0 24px 64px rgba(0,0,0,0.3)",overflow:"hidden"}}>
        <div style={{padding:"14px 18px",background:"#F0F4F8",borderBottom:"1px solid #E0E4E8",display:"flex",alignItems:"center",gap:12,flexShrink:0}}>
          <div><div style={{fontSize:10,color:"#9AA0AA",fontWeight:600,marginBottom:2}}>{dow[dowd].toUpperCase()}</div><div style={{fontSize:18,fontWeight:700,color:"#1A2030"}}>{fmtDate(day.date)}</div></div>
          {day.vehicle&&<div style={{padding:"3px 10px",background:"#E3F2FD",border:"1px solid #BBDEFB",borderRadius:4,fontSize:11,color:"#1565C0",fontWeight:600}}>{day.vehicle}</div>}
          {day.distance>0&&<div style={{padding:"3px 10px",background:"#F3F4F7",border:"1px solid #DDE1E6",borderRadius:4,fontSize:11,color:"#5A6070",fontWeight:500}}>{day.distance} km</div>}
          <div style={{padding:"3px 10px",background:stCol+"18",border:"1px solid "+stCol+"60",borderRadius:4,fontSize:11,color:stCol,fontWeight:600}}>{stLbl}</div>
          <button onClick={onClose} style={{marginLeft:"auto",background:"none",border:"none",fontSize:18,color:"#9AA0AA",cursor:"pointer",padding:"0 4px",lineHeight:1}}>&#x2715;</button>
        </div>
        <div style={{display:"flex",gap:0,borderBottom:"1px solid #EEF0F4",flexShrink:0}}>
          {[{act:3,val:drive},{act:2,val:work},{act:1,val:avail},{act:0,val:rest}].filter(x=>x.val>0).map(({act,val})=>(
            <div key={act} style={{flex:1,padding:"10px 14px",borderRight:"1px solid #EEF0F4",background:"#FAFBFC"}}>
              <div style={{display:"flex",alignItems:"center",gap:5,marginBottom:3}}><div style={{width:8,height:8,borderRadius:2,background:ACT_SOLID[act]}}/><span style={{fontSize:9,color:"#9AA0AA",fontWeight:600}}>{ACT_NAME[act].toUpperCase()}</span></div>
              <div style={{fontSize:15,fontWeight:700,color:ACT_SOLID[act],fontFamily:"monospace"}}>{hhmm(val)}</div>
            </div>
          ))}
        </div>
        <div style={{overflowY:"auto",flex:1}}>
          <table style={{width:"100%",borderCollapse:"collapse",fontSize:12,fontFamily:"Inter"}}>
            <thead style={{position:"sticky",top:0,zIndex:1}}>
              <tr style={{background:"#F0F4F8"}}>
                {["#","Start","Stop","Czas","Aktywnosc"].map(h=><th key={h} style={{padding:"7px 14px",textAlign:"left",fontWeight:700,color:"#5A6070",fontSize:10,borderBottom:"2px solid #E0E4E8"}}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {day.slots.map((s,i)=>(
                <tr key={i} style={{background:i%2===0?"#FFF":"#F8FAFC",borderBottom:"1px solid #F0F2F5"}}>
                  <td style={{padding:"6px 14px",color:"#BFC5CC",fontSize:10}}>{i+1}</td>
                  <td style={{padding:"6px 14px",fontFamily:"monospace",fontWeight:600,color:"#1A2030"}}>{hhmm(s.startMin)}</td>
                  <td style={{padding:"6px 14px",fontFamily:"monospace",fontWeight:600,color:"#1A2030"}}>{hhmm(s.endMin)}</td>
                  <td style={{padding:"6px 14px",fontFamily:"monospace",fontWeight:700,color:ACT_SOLID[s.activity]}}>{hhmm(s.duration)}</td>
                  <td style={{padding:"6px 14px"}}><span style={{display:"inline-flex",alignItems:"center",gap:6}}><span style={{width:8,height:8,borderRadius:2,background:ACT_SOLID[s.activity],display:"inline-block",flexShrink:0}}/><span style={{fontWeight:600,color:ACT_SOLID[s.activity]}}>{ACT_NAME[s.activity]}</span></span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function WeekRow({weekStart,days,cw,vs,ve,setTip,onCross,onDayClick}){
  const [expanded,setExpanded]=useState(false);
  const dur=ve-vs;const px=m=>((m-vs)/dur)*cw;
  const now=new Date();
  const todayDi=Array.from({length:7},(_,i)=>addD(weekStart,i)).findIndex(d=>d.toDateString()===now.toDateString());
  const nowAbs=todayDi>=0?todayDi*1440+now.getHours()*60+now.getMinutes():-1;
  const nowX=nowAbs>=0?px(nowAbs):-1;const showNow=nowAbs>=vs&&nowAbs<=ve;
  const flat=[],longRests=[],allCross=[],restStarts=[];
  days.forEach((day,di)=>{
    if(!day)return;
    day.slots.forEach(s=>{
      flat.push({absS:di*1440+s.startMin,absE:di*1440+s.endMin,act:s.activity,dur:s.duration,date:day.date});
      if(s.activity===0&&s.duration>=9*60){longRests.push({absS:di*1440+s.startMin,absE:di*1440+s.endMin,dur:s.duration});restStarts.push({absM:di*1440+s.startMin,label:hhmm(s.startMin)});}
    });
    (day.crossings||[]).forEach(c=>{allCross.push({absM:di*1440+c.atMin,from:c.from||"?",to:c.to,date:fmtDate(day.date),timeLabel:hhmm(c.atMin)});});
  });
  const driveMarkers=[];let prev=null;
  flat.forEach(s=>{if(s.act===3&&(!prev||prev.act!==3))driveMarkers.push({abs:s.absS,type:"start"});else if(s.act!==3&&prev&&prev.act===3)driveMarkers.push({abs:prev.absE,type:"end"});prev=s;});
  if(prev&&prev.act===3)driveMarkers.push({abs:prev.absE,type:"end"});
  const weekDrive=days.reduce((s,d)=>s+(d?d.slots.filter(x=>x.activity===3).reduce((a,b)=>a+b.duration,0):0),0);
  const dCol=weekDrive>EU.maxWeek?"#E53935":weekDrive>EU.maxWeek*0.85?"#FF9800":"#43A047";
  const dayDots=days.map(d=>{const st=dayStatus(d&&d.slots);return st==="error"?"#E53935":st==="warn"?"#FF9800":st==="ok"?"#43A047":null;});
  const totals={0:0,1:0,2:0,3:0};
  const dist=days.reduce((s,d)=>s+(d?d.distance:0),0);
  days.forEach(d=>d&&d.slots.forEach(s=>{totals[s.activity]=(totals[s.activity]||0)+s.duration;}));
  const NB={background:"transparent",border:"none",borderRight:"1px solid #E0E4E8",color:"#5A6070",padding:"0 10px",fontSize:12,cursor:"pointer",fontFamily:"Inter",minHeight:32};
  const ZB={background:"#FFF",border:"1px solid #DDE1E6",color:"#5A6070",padding:"3px 8px",borderRadius:4,fontSize:10,fontFamily:"Inter",cursor:"pointer"};
  return(
    <div style={{borderBottom:"1px solid #E2E4EA",background:"#FFF"}}>
      <div style={{height:3,background:"linear-gradient(90deg,#1E88E5,#42A5F5)",opacity:0.5}}/>
      <div style={{display:"flex",alignItems:"stretch"}}>
        <div style={{width:LW,flexShrink:0,background:"#F8F9FB",borderRight:"1px solid #E2E4EA",padding:"6px 10px",display:"flex",flexDirection:"column",justifyContent:"center"}}>
          <div style={{display:"flex",alignItems:"center",gap:4,marginBottom:2}}><div style={{width:5,height:5,borderRadius:"50%",background:dCol}}/><span style={{fontSize:13,fontWeight:700,color:"#1565C0"}}>W{String(isoWeek(weekStart)).padStart(2,"0")}</span></div>
          <div style={{fontSize:9,color:"#9AA0AA",lineHeight:1.5}}>{fmtDate(weekStart)}</div>
          <div style={{fontSize:9,color:"#9AA0AA"}}>{fmtDate(addD(weekStart,6))}</div>
          <div style={{marginTop:3,fontSize:10,fontWeight:700,color:dCol}}>{hhmm(weekDrive)}</div>
        </div>
        <svg width={cw} height={RH} style={{display:"block",flexShrink:0}}>
          {[0,1,2,3,4,5,6].map(di=>{const x1=px(di*1440),x2=px((di+1)*1440),rx=Math.max(0,x1),rw=Math.min(cw,x2)-rx;if(rw<=0)return null;return <rect key={di} x={rx} y={0} width={rw} height={RH} fill={di%2===0?"#FFF":"#F6F7FA"}/>;})}
          {dayDots.map((col,di)=>{if(!col)return null;const xc=px(di*1440+720);if(xc<4||xc>cw-4)return null;return <circle key={di} cx={xc} cy={10} r={3} fill={col} opacity={0.75}/>;})}
          <rect x={0} y={T1Y} width={cw} height={T1H} fill="#E0F7FA" rx={2} opacity={0.3}/>
          <rect x={0} y={T1Y} width={cw} height={T1H} fill="none" stroke="#B2EBF2" strokeWidth={0.8} rx={2}/>
          {flat.filter(s=>s.absE>vs&&s.absS<ve).map((s,i)=>{
            const x1=Math.max(0,px(s.absS)),x2=Math.min(cw,px(s.absE)),bw=x2-x1;if(bw<0.4)return null;
            const fill=ACT_FILL[s.act];const border=ACT_STROKE[s.act];const trackCY=T1Y+T1H/2;
            const bh=Math.max(4,Math.round((T1H-2)*ACT_HFRAC[s.act]));const by=trackCY-bh/2;const cx=x1+bw/2,cy=trackCY;
            return(<g key={i}><rect x={x1} y={by} width={bw} height={bh} fill={fill} rx={2} onMouseEnter={e=>setTip({mx:e.clientX,my:e.clientY,act:s.act,absS:s.absS,absE:s.absE,dur:s.dur,date:s.date})} onMouseLeave={()=>setTip(null)} style={{cursor:"default"}}/><rect x={x1} y={by} width={bw} height={bh} fill="none" stroke={border} strokeWidth={0.8} rx={2} style={{pointerEvents:"none"}}/>{bw>14&&<TachoSym act={s.act} cx={bw>50?x1+12:cx} cy={cy} s={bw>50?0.9:0.75}/>}{bw>50&&<text x={cx} y={cy+4} textAnchor="middle" fill={ACT_TEXT[s.act]} fontSize={bw>80?10:8} fontFamily="Inter" fontWeight="600" style={{pointerEvents:"none"}}>{hhmm(s.dur)}</text>}</g>);
          })}
          <rect x={0} y={T2Y} width={cw} height={T2H} fill="#E3F2FD" rx={2} opacity={0.35}/>
          <rect x={0} y={T2Y} width={cw} height={T2H} fill="none" stroke="#BBDEFB" strokeWidth={0.8} rx={2}/>
          {longRests.filter(r=>r.absE>vs&&r.absS<ve).map((r,i)=>{const x1=Math.max(0,px(r.absS)),x2=Math.min(cw,px(r.absE)),bw=x2-x1;if(bw<0.4)return null;return(<g key={i}><rect x={x1} y={T2Y+1} width={bw} height={T2H-2} fill="#90CAF9" rx={2} opacity={0.75} onMouseEnter={e=>setTip({mx:e.clientX,my:e.clientY,act:-1,absS:r.absS,absE:r.absE,dur:r.dur})} onMouseLeave={()=>setTip(null)} style={{cursor:"default"}}/>{bw>35&&<text x={x1+bw/2} y={T2Y+T2H/2+4} textAnchor="middle" fill="#1565C0" fontSize={8} fontFamily="Inter" fontWeight="600" style={{pointerEvents:"none"}}>{hhmm(r.dur)}</text>}</g>);})}
          {restStarts.filter(r=>r.absM>=vs&&r.absM<=ve).map((r,i)=>{const x=px(r.absM);if(x<0||x>cw)return null;return(<g key={i} style={{pointerEvents:"none"}}><line x1={x} y1={T2Y-2} x2={x} y2={T2Y+T2H+2} stroke="#43A047" strokeWidth={2} opacity={0.9}/><polygon points={x+","+(T2Y-2)+" "+(x-5)+","+(T2Y-10)+" "+(x+5)+","+(T2Y-10)} fill="#43A047"/><rect x={x-16} y={T2Y-22} width={32} height={12} fill="#E8F5E9" stroke="#43A047" strokeWidth={0.8} rx={2}/><text x={x} y={T2Y-13} textAnchor="middle" fill="#2E7D32" fontSize={8} fontFamily="Inter" fontWeight="700">{r.label}</text></g>);})}
          {[1,2,3,4,5,6].map(di=>{const x=px(di*1440);if(x<0||x>cw)return null;return <line key={di} x1={x} y1={T1Y-8} x2={x} y2={T2Y+T2H+4} stroke="#66BB6A" strokeWidth={1.2} strokeDasharray="4,3" opacity={0.5}/>;})}
          {allCross.filter(c=>c.absM>=vs&&c.absM<=ve).map((c,i)=>{const x=px(c.absM);if(x<0||x>cw)return null;const cs=ccStyle(c.to);const label=c.from&&c.from!=="?"?c.from+">"+c.to:c.to;const bw=label.length*5+8;return(<g key={i} onClick={()=>onCross(c)} style={{cursor:"pointer"}}><line x1={x} y1={T1Y-2} x2={x} y2={T1Y+T1H+2} stroke={cs.bd} strokeWidth={2} opacity={0.85}/><polygon points={x+","+T1Y+" "+(x-4)+","+(T1Y-7)+" "+(x+4)+","+(T1Y-7)} fill={cs.bd}/><rect x={x-bw/2} y={T1Y-21} width={bw} height={13} fill={cs.bg} stroke={cs.bd} strokeWidth={1} rx={2}/><text x={x} y={T1Y-11} textAnchor="middle" fill={cs.tx} fontSize={7} fontFamily="Inter" fontWeight="700">{label}</text></g>);})}
          {driveMarkers.map((m,i)=>{const x=px(m.abs);if(x<-20||x>cw+20)return null;return(<g key={i} style={{pointerEvents:"none"}}><line x1={x} y1={T1Y} x2={x} y2={T1Y+T1H} stroke="#37474F" strokeWidth={1.2}/>{m.type==="start"?<polygon points={x+","+T1Y+" "+(x-3)+","+(T1Y-5)+" "+(x+3)+","+(T1Y-5)} fill="#37474F"/>:<circle cx={x} cy={T1Y-3} r={2.5} fill="#37474F"/>}</g>);})}
          {[0,1,2,3,4,5,6].map(di=>{const xm=px(di*1440+720);if(xm<22||xm>cw-22)return null;const d=addD(weekStart,di);return(<g key={di} onClick={()=>onDayClick&&onDayClick(di)} style={{cursor:"pointer"}}><rect x={xm-30} y={AXY+2} width={60} height={14} fill="transparent"/><text x={xm} y={AXY+13} textAnchor="middle" fill={di>=5?"#9AA0AA":"#1565C0"} fontSize={10} fontFamily="Inter" fontWeight={di>=5?400:600} textDecoration="underline">{fmtDate(d)}</text></g>);})}
          {showNow&&(<g style={{pointerEvents:"none"}}><line x1={nowX} y1={T1Y-8} x2={nowX} y2={T2Y+T2H+4} stroke="#F44336" strokeWidth={1.5} strokeDasharray="3,2" opacity={0.7}/><rect x={nowX-13} y={T1Y-20} width={26} height={12} fill="#F44336" rx={2}/><text x={nowX} y={T1Y-11} textAnchor="middle" fill="#fff" fontSize={8} fontFamily="Inter" fontWeight="600">{hhmm(now.getHours()*60+now.getMinutes())}</text></g>)}
          <line x1={0} y1={AXY} x2={cw} y2={AXY} stroke="#E0E2E8" strokeWidth={1}/>
        </svg>
      </div>
      <div style={{display:"flex",alignItems:"stretch",background:"#F8F9FB",borderTop:"1px solid #EEF0F4"}}>
        <div style={{width:LW,flexShrink:0,borderRight:"1px solid #E2E4EA",padding:"4px 8px",display:"flex",alignItems:"center"}}>{dist>0&&<span style={{fontSize:9,color:"#9AA0AA",fontWeight:500}}>{dist} km</span>}</div>
        <div style={{flex:1,display:"flex",alignItems:"center",flexWrap:"wrap"}}>
          {[3,2,1,0].map(k=>{const val=totals[k]||0;if(!val)return null;return(<div key={k} style={{display:"flex",alignItems:"center",gap:5,padding:"4px 12px",borderRight:"1px solid #EEF0F4"}}><div style={{width:8,height:8,borderRadius:2,background:ACT_SOLID[k],flexShrink:0}}/><span style={{fontSize:9,color:"#6A7080",whiteSpace:"nowrap"}}><span style={{fontWeight:600,color:ACT_SOLID[k]}}>{ACT_NAME[k]}</span> {hm(val)}</span></div>);})}
          <button onClick={()=>setExpanded(v=>!v)} style={{marginLeft:"auto",background:"none",border:"none",fontSize:10,color:"#1E88E5",cursor:"pointer",padding:"4px 14px",fontFamily:"Inter",fontWeight:600,display:"flex",alignItems:"center",gap:4}}><span style={{fontSize:12,lineHeight:1}}>{expanded?"▾":"▸"}</span>{expanded?"Ukryj":"Szczegoly"}</button>
        </div>
      </div>
      {expanded&&(
        <div style={{borderTop:"1px solid #EEF0F4",overflowX:"auto"}}>
          <table style={{width:"100%",borderCollapse:"collapse",fontSize:11,fontFamily:"Inter"}}>
            <thead><tr style={{background:"#F0F4F8"}}>{["Data","Start","Stop","Czas","Aktywnosc","Pojazd"].map(h=><th key={h} style={{padding:"5px 10px",textAlign:"left",fontWeight:700,color:"#5A6070",fontSize:10,borderBottom:"1px solid #E0E4E8",whiteSpace:"nowrap"}}>{h}</th>)}</tr></thead>
            <tbody>
              {days.map((day,di)=>{if(!day||!day.slots)return null;return day.slots.map((s,si)=>{const even=(di*100+si)%2===0;return(<tr key={di+"-"+si} style={{background:even?"#FFF":"#F8FAFC"}}><td style={{padding:"4px 10px",color:"#5A6070",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>{si===0?fmtDate(day.date):""}</td><td style={{padding:"4px 10px",fontFamily:"monospace",color:"#1A2030",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>{hhmm(s.startMin)}</td><td style={{padding:"4px 10px",fontFamily:"monospace",color:"#1A2030",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>{hhmm(s.endMin)}</td><td style={{padding:"4px 10px",fontFamily:"monospace",fontWeight:600,color:"#1A2030",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>{hhmm(s.duration)}</td><td style={{padding:"4px 10px",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}><span style={{display:"inline-flex",alignItems:"center",gap:5}}><span style={{display:"inline-block",width:8,height:8,borderRadius:2,background:ACT_SOLID[s.activity],flexShrink:0}}/><span style={{color:ACT_SOLID[s.activity],fontWeight:600}}>{ACT_NAME[s.activity]}</span></span></td><td style={{padding:"4px 10px",color:"#5A6070",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>{day.vehicle||"-"}</td></tr>);});})}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// VIOLATIONS ENGINE
// ═══════════════════════════════════════════════════════════════
const VIOL_TYPES = {
  DAY_DRIVE_WARN:    { id:"DAY_DRIVE_WARN",   label:"Przekroczenie czasu jazdy dobowej",       detail:"Jazda >9h (bez rozszerzenia)",   art:"art.6 ust.1 rozp.561/2006",  kierMin:100,  kierMax:500,  firmMin:200,  firmMax:2000, severity:"warn"  },
  DAY_DRIVE_ERR:     { id:"DAY_DRIVE_ERR",    label:"Poważne przekroczenie jazdy dobowej",     detail:"Jazda >10h",                     art:"art.6 ust.1 rozp.561/2006",  kierMin:500,  kierMax:2000, firmMin:500,  firmMax:10000,severity:"error" },
  CONT_DRIVE:        { id:"CONT_DRIVE",       label:"Przekroczenie ciągłego czasu jazdy",      detail:"Jazda ciągła >4,5h bez przerwy", art:"art.7 rozp.561/2006",        kierMin:50,   kierMax:1000, firmMin:100,  firmMax:2000, severity:"warn"  },
  DAILY_REST_SHORT:  { id:"DAILY_REST_SHORT", label:"Skrócenie odpoczynku dobowego",           detail:"Odpoczynek <11h (lub <9h split)",art:"art.8 rozp.561/2006",        kierMin:200,  kierMax:2000, firmMin:500,  firmMax:10000,severity:"error" },
  WEEK_DRIVE_WARN:   { id:"WEEK_DRIVE_WARN",  label:"Przekroczenie tygodniowego czasu jazdy",  detail:"Jazda >56h w tygodniu",          art:"art.6 ust.2 rozp.561/2006",  kierMin:200,  kierMax:1000, firmMin:500,  firmMax:5000, severity:"warn"  },
  WEEK_DRIVE_ERR:    { id:"WEEK_DRIVE_ERR",   label:"Poważne przekroczenie jazdy tygodniowej", detail:"Jazda >60h w tygodniu",          art:"art.6 ust.2 rozp.561/2006",  kierMin:500,  kierMax:2000, firmMin:1000, firmMax:10000,severity:"error" },
  BIWEEK_DRIVE:      { id:"BIWEEK_DRIVE",     label:"Przekroczenie jazdy dwutygodniowej",      detail:"Jazda >90h w 2 tyg.",            art:"art.6 ust.3 rozp.561/2006",  kierMin:300,  kierMax:2000, firmMin:500,  firmMax:10000,severity:"error" },
};

function analyzeViolations(days){
  if(!days||!days.length)return{violations:[],weeklyStats:[]};
  const violations=[];

  // ── Per-day checks ──
  for(const day of days){
    if(!day||!day.slots)continue;
    const drive=day.slots.filter(s=>s.activity===3).reduce((a,s)=>a+s.duration,0);
    const rest=day.slots.filter(s=>s.activity===0).reduce((a,s)=>a+s.duration,0);
    if(drive===0&&rest===0)continue;

    // Daily drive >10h (serious)
    if(drive>600){
      const excess=drive-540;
      violations.push({type:VIOL_TYPES.DAY_DRIVE_ERR,date:day.date,measured:drive,limit:540,excess,detail:`Jazda ${hhmm(drive)} (limit 10:00, norma 09:00)`});
    } else if(drive>540){
      const excess=drive-540;
      violations.push({type:VIOL_TYPES.DAY_DRIVE_WARN,date:day.date,measured:drive,limit:540,excess,detail:`Jazda ${hhmm(drive)} (limit 09:00)`});
    }

    // Continuous drive >270min
    let cont=0,maxCont=0,contStart=null,maxContStart=null;
    for(const s of day.slots){
      if(s.activity===3){
        if(cont===0)contStart=s.startMin;
        cont+=s.duration;
        if(cont>maxCont){maxCont=cont;maxContStart=contStart;}
      } else if(s.activity===0&&s.duration>=15){
        cont=0;contStart=null;
      }
    }
    if(maxCont>270){
      const excess=maxCont-270;
      violations.push({type:VIOL_TYPES.CONT_DRIVE,date:day.date,measured:maxCont,limit:270,excess,detail:`Ciągła jazda ${hhmm(maxCont)} (limit 04:30) od ${hhmm(maxContStart||0)}`});
    }

    // Daily rest <660min (only count days with driving)
    if(drive>0&&rest<660){
      const short=660-rest;
      violations.push({type:VIOL_TYPES.DAILY_REST_SHORT,date:day.date,measured:rest,limit:660,excess:short,detail:`Odpoczynek ${hhmm(rest)} (wymagane 11:00)`});
    }
  }

  // ── Weekly checks ──
  const weekMap={};
  for(const day of days){
    if(!day)continue;
    const d=new Date(day.date);
    const wk=`${d.getFullYear()}-W${String(isoWeek(d)).padStart(2,"0")}`;
    if(!weekMap[wk])weekMap[wk]={start:monDay(d),drive:0,days:[]};
    weekMap[wk].drive+=day.slots.filter(s=>s.activity===3).reduce((a,s)=>a+s.duration,0);
    weekMap[wk].days.push(day);
  }
  const weeklyStats=[];
  const weekEntries=Object.entries(weekMap).sort(([a],[b])=>a<b?-1:1);
  for(const[wk,w]of weekEntries){
    weeklyStats.push({wk,start:w.start,drive:w.drive,daysCount:w.days.length});
    if(w.drive>3600){
      violations.push({type:VIOL_TYPES.WEEK_DRIVE_ERR,date:w.start,measured:w.drive,limit:3360,excess:w.drive-3360,detail:`Jazda ${hm(w.drive)} w tyg. ${wk} (limit 60:00, norma 56:00)`,weekly:true});
    } else if(w.drive>3360){
      violations.push({type:VIOL_TYPES.WEEK_DRIVE_WARN,date:w.start,measured:w.drive,limit:3360,excess:w.drive-3360,detail:`Jazda ${hm(w.drive)} w tyg. ${wk} (limit 56:00)`,weekly:true});
    }
  }

  // ── Bi-weekly check (sliding window 2 consecutive weeks) ──
  for(let i=0;i<weekEntries.length-1;i++){
    const [wk1,w1]=weekEntries[i],[,w2]=weekEntries[i+1];
    const combined=w1.drive+w2.drive;
    if(combined>5400){
      violations.push({type:VIOL_TYPES.BIWEEK_DRIVE,date:w1.start,measured:combined,limit:5400,excess:combined-5400,detail:`Jazda ${hm(combined)} w 2 tygodniach (limit 90:00)`,weekly:true});
    }
  }

  violations.sort((a,b)=>new Date(a.date)-new Date(b.date));
  return{violations,weeklyStats};
}

function ViolationsPanel({tachoData}){
  const {violations,weeklyStats}=useMemo(()=>analyzeViolations(tachoData.days),[tachoData]);
  const [filter,setFilter]=useState("all");
  const errors=violations.filter(v=>v.type.severity==="error");
  const warns=violations.filter(v=>v.type.severity==="warn");
  const totalKierMin=violations.reduce((s,v)=>s+v.type.kierMin,0);
  const totalKierMax=violations.reduce((s,v)=>s+v.type.kierMax,0);
  const totalFirmMax=violations.reduce((s,v)=>s+v.type.firmMax,0);

  const shown=filter==="all"?violations:violations.filter(v=>v.type.severity===filter);

  if(!violations.length)return(
    <div style={{margin:"10px 0",padding:"16px 20px",background:"#E8F5E9",border:"1px solid #A5D6A7",borderRadius:8,display:"flex",alignItems:"center",gap:12}}>
      <span style={{fontSize:24}}>✅</span>
      <div><div style={{fontWeight:700,color:"#2E7D32",fontSize:14}}>Brak naruszeń w analizowanym okresie</div><div style={{fontSize:12,color:"#388E3C",marginTop:2}}>Wszystkie parametry zgodne z rozp. 561/2006</div></div>
    </div>
  );

  return(
    <div style={{margin:"10px 0",background:"#FFF",border:"1px solid #E0E4E8",borderRadius:8,overflow:"hidden",boxShadow:"0 1px 4px rgba(0,0,0,0.06)"}}>
      {/* Header */}
      <div style={{padding:"12px 16px",background:"linear-gradient(135deg,#B71C1C,#C62828)",display:"flex",alignItems:"center",gap:12,flexWrap:"wrap"}}>
        <span style={{fontSize:18}}>⚠️</span>
        <div style={{flex:1}}>
          <div style={{fontWeight:700,color:"#fff",fontSize:14,letterSpacing:0.5}}>RAPORT NARUSZEŃ — rozp. (WE) 561/2006</div>
          <div style={{fontSize:11,color:"rgba(255,255,255,0.8)",marginTop:1}}>ustawa o transporcie drogowym · taryfikator kar ITD/GIT</div>
        </div>
        <div style={{display:"flex",gap:8,flexWrap:"wrap"}}>
          <div style={{background:"rgba(255,255,255,0.15)",borderRadius:6,padding:"6px 12px",textAlign:"center"}}>
            <div style={{fontSize:20,fontWeight:800,color:"#fff",lineHeight:1}}>{errors.length}</div>
            <div style={{fontSize:9,color:"rgba(255,255,255,0.7)",marginTop:2}}>POWAŻNE</div>
          </div>
          <div style={{background:"rgba(255,255,255,0.12)",borderRadius:6,padding:"6px 12px",textAlign:"center"}}>
            <div style={{fontSize:20,fontWeight:800,color:"#FFE082",lineHeight:1}}>{warns.length}</div>
            <div style={{fontSize:9,color:"rgba(255,255,255,0.7)",marginTop:2}}>OSTRZEŻENIA</div>
          </div>
          <div style={{background:"rgba(255,255,255,0.12)",borderRadius:6,padding:"6px 12px",textAlign:"center"}}>
            <div style={{fontSize:14,fontWeight:800,color:"#FFCDD2",lineHeight:1}}>{totalKierMin.toLocaleString("pl-PL")}–{totalKierMax.toLocaleString("pl-PL")} zł</div>
            <div style={{fontSize:9,color:"rgba(255,255,255,0.7)",marginTop:2}}>KIEROWCA (widełki)</div>
          </div>
          <div style={{background:"rgba(255,255,255,0.12)",borderRadius:6,padding:"6px 12px",textAlign:"center"}}>
            <div style={{fontSize:14,fontWeight:800,color:"#EF9A9A",lineHeight:1}}>do {totalFirmMax.toLocaleString("pl-PL")} zł</div>
            <div style={{fontSize:9,color:"rgba(255,255,255,0.7)",marginTop:2}}>PRZEWOŹNIK (max)</div>
          </div>
        </div>
      </div>

      {/* Filter tabs */}
      <div style={{display:"flex",borderBottom:"1px solid #EEF0F4",background:"#F8F9FB"}}>
        {[["all","Wszystkie",violations.length,"#5A6070"],["error","Poważne",errors.length,"#C62828"],["warn","Ostrzeżenia",warns.length,"#F57F17"]].map(([f,lbl,cnt,clr])=>(
          <button key={f} onClick={()=>setFilter(f)} style={{background:"none",border:"none",borderBottom:`2px solid ${filter===f?clr:"transparent"}`,color:filter===f?clr:"#9AA0AA",padding:"8px 16px",fontSize:12,fontWeight:filter===f?700:400,cursor:"pointer",fontFamily:"Inter",display:"flex",alignItems:"center",gap:6}}>
            {lbl} <span style={{background:filter===f?clr:"#E0E4E8",color:filter===f?"#fff":"#6A7080",borderRadius:10,padding:"1px 7px",fontSize:10,fontWeight:700}}>{cnt}</span>
          </button>
        ))}
        <div style={{marginLeft:"auto",display:"flex",alignItems:"center",padding:"0 14px",fontSize:10,color:"#BFC5CC"}}>
          Kary wg Dz.U. 2021 poz. 403 | taryf. ITD
        </div>
      </div>

      {/* Table */}
      <div style={{overflowX:"auto"}}>
        <table style={{width:"100%",borderCollapse:"collapse",fontSize:11,fontFamily:"Inter"}}>
          <thead>
            <tr style={{background:"#F0F4F8"}}>
              {["Data","Rodzaj naruszenia","Zmierzono","Limit","Przekroczenie","Kara kierowca","Kara przewoźnik","Art."].map(h=>(
                <th key={h} style={{padding:"7px 10px",textAlign:"left",fontWeight:700,color:"#5A6070",fontSize:10,borderBottom:"1px solid #E0E4E8",whiteSpace:"nowrap"}}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {shown.map((v,i)=>{
              const isErr=v.type.severity==="error";
              const bgRow=i%2===0?"#FFF":"#FAFBFC";
              const flagClr=isErr?"#C62828":"#E65100";
              const flagBg=isErr?"#FFEBEE":"#FFF3E0";
              return(
                <tr key={i} style={{background:bgRow,borderLeft:`3px solid ${flagClr}`}}>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap",color:"#5A6070",fontWeight:600}}>{fmtDate(new Date(v.date))}{v.weekly&&<span style={{fontSize:9,color:"#9AA0AA",marginLeft:4}}>tyg.</span>}</td>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",maxWidth:260}}>
                    <div style={{fontWeight:600,color:flagClr,fontSize:11}}>{v.type.label}</div>
                    <div style={{fontSize:10,color:"#9AA0AA",marginTop:1}}>{v.detail}</div>
                  </td>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",fontFamily:"monospace",fontWeight:700,color:flagClr,whiteSpace:"nowrap"}}>{v.weekly?hm(v.measured):hhmm(v.measured)}</td>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",fontFamily:"monospace",color:"#5A6070",whiteSpace:"nowrap"}}>{v.weekly?hm(v.limit):hhmm(v.limit)}</td>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>
                    <span style={{background:flagBg,color:flagClr,border:`1px solid ${flagClr}40`,borderRadius:4,padding:"2px 8px",fontWeight:700,fontSize:11,fontFamily:"monospace"}}>+{v.weekly?hm(v.excess):hhmm(v.excess)}</span>
                  </td>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>
                    <span style={{color:"#1A2030",fontWeight:600}}>{v.type.kierMin.toLocaleString("pl-PL")} – {v.type.kierMax.toLocaleString("pl-PL")} zł</span>
                  </td>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",whiteSpace:"nowrap"}}>
                    <span style={{color:"#5A6070"}}>do {v.type.firmMax.toLocaleString("pl-PL")} zł</span>
                  </td>
                  <td style={{padding:"6px 10px",borderBottom:"1px solid #F0F2F5",fontSize:10,color:"#9AA0AA",whiteSpace:"nowrap"}}>{v.type.art}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Footer note */}
      <div style={{padding:"8px 16px",background:"#FFFDE7",borderTop:"1px solid #FFF176",display:"flex",alignItems:"flex-start",gap:8}}>
        <span style={{fontSize:14,flexShrink:0}}>ℹ️</span>
        <div style={{fontSize:10,color:"#827717",lineHeight:1.5}}>
          <strong>Uwaga:</strong> Kwoty kar mają charakter orientacyjny i podano je jako widełki z taryfikatora (Dz.U. 2021 poz. 403, zał. nr 3). Ostateczna wysokość kary zależy od decyzji inspektora ITD/GIT, okoliczności oraz historii naruszeń. Kara dla kierowcy i przewoźnika mogą być nakładane niezależnie. Powyższa analiza nie stanowi porady prawnej.
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// TACHOGRAPH ANALYZER PANEL
// ═══════════════════════════════════════════════════════════════
function TachographPanel({tachoData,setTachoData}) {
  const [loading,setLoading]=useState(false);
  const [err,setErr]=useState(null);
  const [numWeeks,setNumWeeks]=useState(5);
  const [startWk,setStartWk]=useState(()=>addD(monDay(new Date()),-4*7));
  const [vs,setVs]=useState(0);
  const [ve,setVe]=useState(7*1440);
  const [tip,setTip]=useState(null);
  const [dragOver,setDragOver]=useState(false);
  const [mode,setMode]=useState("select");
  const [crossModal,setCrossModal]=useState(null);
  const [dayModal,setDayModal]=useState(null);
  const [panStart,setPanStart]=useState(null);
  const [selStart,setSelStart]=useState(null);
  const [selEnd,setSelEnd]=useState(null);
  const chartRef=useRef(null);
  const fileRef=useRef(null);
  const rootRef=useRef(null);
  const [cw,setCw]=useState(900);
  const vsRef=useRef(0);vsRef.current=vs;
  const veRef=useRef(7*1440);veRef.current=ve;

  useEffect(()=>{
    if(!rootRef.current)return;
    const obs=new ResizeObserver(es=>{if(es[0])setCw(es[0].contentRect.width);});
    obs.observe(rootRef.current);return()=>obs.disconnect();
  },[]);
  const chartWidth=Math.max(400,cw-LW-2);
  const dur=ve-vs;

  useEffect(()=>{
    const el=chartRef.current;if(!el)return;
    const fn=e=>{e.preventDefault();const rect=el.getBoundingClientRect();const mx=e.clientX-rect.left-LW;if(mx<0||mx>chartWidth)return;const cv=vsRef.current,ce=veRef.current,cd=ce-cv;const mMin=cv+(mx/chartWidth)*cd;const fac=e.deltaY>0?1.3:0.77;let nd=clamp(cd*fac,360,7*1440);let ns=mMin-(mx/chartWidth)*nd,ne=ns+nd;if(ns<0){ns=0;ne=nd;}if(ne>7*1440){ne=7*1440;ns=7*1440-nd;}setVs(Math.round(ns));setVe(Math.round(ne));};
    el.addEventListener("wheel",fn,{passive:false});return()=>el.removeEventListener("wheel",fn);
  },[chartWidth]);

  const onMouseDown=e=>{if(e.button!==0)return;e.preventDefault();const rect=chartRef.current.getBoundingClientRect();const mx=e.clientX-rect.left-LW;if(mx<0||mx>chartWidth)return;if(mode==="pan")setPanStart({clientX:e.clientX,vs,ve});else{setSelStart(mx);setSelEnd(mx);}};
  const onMouseMove=e=>{if(!chartRef.current)return;const rect=chartRef.current.getBoundingClientRect();const mx=clamp(e.clientX-rect.left-LW,0,chartWidth);if(mode==="pan"&&panStart){const dx=e.clientX-panStart.clientX;const d0=panStart.ve-panStart.vs;const shift=(dx/chartWidth)*d0*-1;let ns=panStart.vs+shift,ne=panStart.ve+shift;if(ns<0){ns=0;ne=d0;}if(ne>7*1440){ne=7*1440;ns=7*1440-d0;}setVs(ns);setVe(ne);}else if(mode==="select"&&selStart!==null){setSelEnd(mx);}};
  const onMouseUp=()=>{if(mode==="pan"){setPanStart(null);}else if(selStart!==null){const a=Math.min(selStart,selEnd||selStart);const b=Math.max(selStart,selEnd||selStart);if(b-a>10){const ns=vs+(a/chartWidth)*dur;const ne=vs+(b/chartWidth)*dur;setVs(ns);setVe(ne);}setSelStart(null);setSelEnd(null);}};
  const onMouseLeave=()=>{setPanStart(null);setSelStart(null);setSelEnd(null);};
  const selX=selStart!==null&&selEnd!==null?Math.min(selStart,selEnd):null;
  const selW=selStart!==null&&selEnd!==null?Math.abs(selEnd-selStart):0;

  const allWeeks=useMemo(()=>Array.from({length:numWeeks},(_,i)=>{const ws=addD(startWk,i*7);const days=Array.from({length:7},(_,di)=>{const d=addD(ws,di);return tachoData.days.find(x=>x.date.toDateString()===d.toDateString())||null;});return{start:ws,days};}),[tachoData,startWk,numWeeks]);
  const availWeeks=useMemo(()=>{const s=new Set();tachoData.days.forEach(d=>s.add(monDay(d.date).toDateString()));return[...s].map(x=>new Date(x)).sort((a,b)=>a-b);},[tachoData]);
  const totalDrive=allWeeks.reduce((s,w)=>s+w.days.reduce((s2,d)=>s2+(d?d.slots.filter(x=>x.activity===3).reduce((a,b)=>a+b.duration,0):0),0),0);

  const loadFile=async f=>{
    if(!f)return;setLoading(true);setErr(null);
    try{const r=parseDDD(await f.arrayBuffer());if(r){setTachoData({...r,demo:false});setStartWk(addD(monDay(r.days[r.days.length-1].date),-(numWeeks-1)*7));}else setErr("Nie wykryto danych aktywnosci (.ddd, .v1b).");}
    catch(ex){setErr("Blad: "+ex.message);}finally{setLoading(false);}
  };
  const NB={background:"transparent",border:"none",borderRight:"1px solid #E0E4E8",color:"#5A6070",padding:"0 12px",fontSize:13,cursor:"pointer",fontFamily:"Inter",minHeight:40};
  const ZB={background:"#FFF",border:"1px solid #DDE1E6",color:"#5A6070",padding:"4px 10px",borderRadius:4,fontSize:10,fontFamily:"Inter",cursor:"pointer"};


  return(
    <div ref={rootRef} style={{fontFamily:"Inter",padding:0}}>
      <div style={{display:"flex",alignItems:"center",gap:10,marginBottom:12,flexWrap:"wrap",padding:"12px 16px",background:"#fff",borderBottom:"1px solid #E0E4E8"}}>
        <div style={{display:"flex",alignItems:"center",gap:8}}>
          <div style={{background:"#1E88E5",color:"#fff",padding:"5px 10px",borderRadius:4,fontSize:12,fontWeight:700,letterSpacing:1}}>TACHO</div>
          <span style={{fontSize:16,fontWeight:600,color:"#1A2030"}}>Analyzer</span>
          <span style={{fontSize:10,color:"#9AA0AA",border:"1px solid #DDE1E6",padding:"2px 7px",borderRadius:3}}>EU 561/2006</span>
        </div>
        {tachoData.driver&&(
          <div style={{display:"flex",alignItems:"center",gap:6,padding:"4px 12px",background:"#FFF",border:"1px solid #E0E4E8",borderRadius:4}}>
            <span style={{fontSize:11,color:"#9AA0AA"}}>Kierowca:</span>
            <span style={{fontSize:13,fontWeight:600,color:"#1A2030"}}>{tachoData.driver}</span>
            <span style={{fontSize:10,color:"#BFC5CC",marginLeft:4}}>{tachoData.days.length} dni</span>
          </div>
        )}
        {tachoData.demo&&<div style={{padding:"4px 10px",background:"#FFF8E1",border:"1px solid #FFE082",borderRadius:4,fontSize:11,color:"#F57F17",fontWeight:600}}>DEMO</div>}
        <div style={{marginLeft:"auto",display:"flex",gap:8,alignItems:"center",flexWrap:"wrap"}}>
          <div style={{padding:"4px 12px",background:"#FFF",border:"1px solid #E0E4E8",borderRadius:4,fontSize:11,color:"#5A6070"}}>
            Jazda ({numWeeks} tyg.): <strong style={{color:"#1A2030"}}>{hhmm(totalDrive)}</strong>
          </div>
          )}
        </div>
      </div>

      <div style={{padding:"0 16px 16px"}}>
        <div onDrop={e=>{e.preventDefault();setDragOver(false);const f=[...e.dataTransfer.files][0];if(f)loadFile(f);}} onDragOver={e=>{e.preventDefault();setDragOver(true);}} onDragLeave={()=>setDragOver(false)} onClick={()=>fileRef.current&&fileRef.current.click()} style={{border:"1.5px dashed "+(dragOver?"#1E88E5":"#C8CDD6"),borderRadius:6,padding:"9px 18px",cursor:"pointer",background:dragOver?"#EAF4FF":"#FAFBFC",display:"flex",alignItems:"center",justifyContent:"center",gap:10,marginBottom:10}}>
          <span style={{fontSize:15,opacity:0.4,color:"#1E88E5"}}>⬆</span>
          <span style={{color:loading?"#1E88E5":"#6A7080",fontSize:12,fontWeight:500}}>{loading?"Przetwarzanie...":"Wczytaj plik DDD / V1B / BIN — lub przeciągnij tutaj"}</span>
          <input ref={fileRef} type="file" accept=".ddd,.v1b,.bin,.c1b,.m1b,*" style={{display:"none"}} onChange={e=>{if(e.target.files[0])loadFile(e.target.files[0]);}}/>
        </div>
        {err&&<div style={{marginBottom:10,padding:"8px 12px",background:"#FFEBEE",border:"1px solid #FFCDD2",borderRadius:4,color:"#C62828",fontSize:12}}>{err}</div>}
        <div style={{display:"flex",alignItems:"stretch",marginBottom:10,background:"#FFF",border:"1px solid #E0E4E8",borderRadius:6,overflow:"hidden",flexWrap:"wrap"}}>
          <button onClick={()=>setStartWk(d=>addD(d,-numWeeks*7))} style={NB}>&lt;&lt;</button>
          <button onClick={()=>setStartWk(d=>addD(d,-7))} style={NB}>&lt;</button>
          <button onClick={()=>setStartWk(addD(monDay(new Date()),-(numWeeks-1)*7))} style={{...NB,color:"#1E88E5",fontWeight:600}}>Dziś</button>
          <button onClick={()=>setStartWk(d=>addD(d,7))} style={NB}>&gt;</button>
          <button onClick={()=>setStartWk(d=>addD(d,numWeeks*7))} style={{...NB,borderRight:"1px solid #E0E4E8"}}>&gt;&gt;</button>
          <div style={{display:"flex",alignItems:"center",gap:6,padding:"0 14px",borderRight:"1px solid #E0E4E8"}}>
            <span style={{fontSize:11,color:"#9AA0AA"}}>Tygodni:</span>
            {[3,4,5,6,8].map(n=>(<button key={n} onClick={()=>setNumWeeks(n)} style={{background:numWeeks===n?"#E3F2FD":"transparent",border:"1px solid "+(numWeeks===n?"#1E88E5":"#DDE1E6"),color:numWeeks===n?"#1E88E5":"#9AA0AA",padding:"3px 9px",borderRadius:3,fontSize:10,cursor:"pointer",fontWeight:numWeeks===n?600:400}}>{n}</button>))}
          </div>
          <div style={{display:"flex",alignItems:"center",padding:"0 14px",fontSize:12,color:"#5A6070",borderRight:"1px solid #E0E4E8"}}>{fmtDate(startWk)} - {fmtDate(addD(startWk,numWeeks*7-1))}</div>
          <div style={{display:"flex",alignItems:"center",gap:3,padding:"5px 10px",marginLeft:"auto",flexWrap:"wrap"}}>
            {availWeeks.slice(-14).map((aw,i)=>{const inV=aw>=startWk&&aw<addD(startWk,numWeeks*7);return <button key={i} onClick={()=>setStartWk(addD(aw,-(numWeeks-1)*7))} style={{background:inV?"#E3F2FD":"transparent",border:"1px solid "+(inV?"#1E88E5":"#DDE1E6"),color:inV?"#1E88E5":"#9AA0AA",padding:"2px 7px",borderRadius:3,fontSize:9,cursor:"pointer",fontWeight:inV?600:400}}>W{isoWeek(aw)}</button>;})}
          </div>
        </div>
        <div style={{background:"#FFF",border:"1px solid #E0E4E8",borderRadius:6,overflow:"hidden",boxShadow:"0 1px 4px rgba(0,0,0,0.06)"}}>
          <div style={{display:"flex",alignItems:"center",gap:10,flexWrap:"wrap",padding:"8px 12px",background:"#F8F9FB",borderBottom:"1px solid #E0E2E8"}}>
            <span style={{fontSize:10,color:"#9AA0AA",fontWeight:600}}>LEGENDA</span>
            {[{fill:"#80DEEA",bd:"#00838F",lbl:"Odpoczynek"},{fill:"#EF9A9A",bd:"#C62828",lbl:"Jazda"},{fill:"#FFCC80",bd:"#BF360C",lbl:"Praca"},{fill:"#9FA8DA",bd:"#3949AB",lbl:"Dyspozycyjnosc"},{fill:"#90CAF9",bd:"#1E88E5",lbl:"Odpoczynek dobowy"}].map((it,i)=>(<div key={i} style={{display:"flex",alignItems:"center",gap:5}}><div style={{width:20,height:10,background:it.fill,border:"1px solid "+it.bd+"80",borderRadius:2}}/><span style={{fontSize:10,color:"#5A6070"}}>{it.lbl}</span></div>))}
            <div style={{marginLeft:"auto",display:"flex",gap:8,fontSize:10,color:"#9AA0AA"}}>
              {[["#43A047","Zgodny"],["#FF9800","Ostrzezenie"],["#E53935","Naruszenie"]].map(([c,l])=>(<span key={l}><span style={{color:c,fontSize:12}}>●</span> {l}</span>))}
            </div>
          </div>
          <div style={{display:"flex",alignItems:"center",gap:8,padding:"7px 12px",background:"#F3F4F7",borderBottom:"1px solid #E0E2E8",flexWrap:"wrap"}}>
            <span style={{fontSize:10,fontWeight:600,color:"#9AA0AA"}}>ZOOM</span>
            <button onClick={()=>{setVs(0);setVe(7*1440);}} style={ZB}>7 dni</button>
            <button onClick={()=>{setVs(0);setVe(5*1440);}} style={ZB}>5 dni</button>
            <button onClick={()=>{setVs(0);setVe(3*1440);}} style={ZB}>3 dni</button>
            <button onClick={()=>{setVs(0);setVe(1440);}} style={ZB}>1 dzień</button>
            <button onClick={()=>{const c=(vs+ve)/2,d=(ve-vs)/4;setVs(clamp(c-d,0,7*1440-360));setVe(clamp(c+d,360,7*1440));}} style={ZB}>🔍+</button>
            <button onClick={()=>{const c=(vs+ve)/2,nd=clamp((ve-vs)*1.6,ve-vs,7*1440);let ns=c-nd/2,ne=c+nd/2;if(ns<0){ns=0;ne=nd;}if(ne>7*1440){ne=7*1440;ns=7*1440-nd;}setVs(clamp(ns,0,7*1440));setVe(clamp(ne,0,7*1440));}} style={ZB}>🔍-</button>
            <div style={{width:1,height:16,background:"#DDE1E6"}}/>
            <button onClick={()=>setMode("select")} style={{...ZB,background:mode==="select"?"#E3F2FD":"#FFF",border:"1px solid "+(mode==="select"?"#1E88E5":"#DDE1E6"),color:mode==="select"?"#1E88E5":"#5A6070",fontWeight:mode==="select"?600:400}}>[ ] Zaznacz</button>
            <button onClick={()=>setMode("pan")} style={{...ZB,background:mode==="pan"?"#E3F2FD":"#FFF",border:"1px solid "+(mode==="pan"?"#1E88E5":"#DDE1E6"),color:mode==="pan"?"#1E88E5":"#5A6070",fontWeight:mode==="pan"?600:400}}>↔ Przesuwaj</button>
            <span style={{fontSize:10,color:"#C0C4CC"}}>Scroll=zoom</span>
            <span style={{marginLeft:"auto",fontSize:10,color:"#9AA0AA"}}>{Math.round((ve-vs)/144)/10} dni</span>
          </div>
          <div style={{display:"flex",background:"#F0F4F8",borderBottom:"1px solid #E0E2E8"}}><div style={{width:LW,flexShrink:0,padding:"5px 10px",fontSize:9,fontWeight:700,color:"#9AA0AA",letterSpacing:1,borderRight:"1px solid #E2E4EA"}}>TYDZIEŃ</div><div style={{flex:1,padding:"5px 12px",fontSize:9,fontWeight:700,color:"#9AA0AA",letterSpacing:1}}>OŚ CZASU 7 DNI — kliknij datę → szczegóły dnia · kliknij kod granicy → przejście</div></div>
          <div ref={chartRef} style={{position:"relative",cursor:mode==="pan"?(panStart?"grabbing":"grab"):"crosshair",userSelect:"none",WebkitUserSelect:"none"}} onMouseDown={onMouseDown} onMouseMove={onMouseMove} onMouseUp={onMouseUp} onMouseLeave={onMouseLeave}>
            {allWeeks.map((w,i)=>(<div key={i} style={{marginBottom:10,borderRadius:4,overflow:"hidden",boxShadow:"0 1px 3px rgba(0,0,0,0.07)"}}><WeekRow weekStart={w.start} days={w.days} cw={chartWidth} vs={vs} ve={ve} setTip={setTip} onCross={c=>{setTip(null);setCrossModal(c);}} onDayClick={di=>{const d=w.days[di];if(d)setDayModal(d);}}/></div>))}
            {mode==="select"&&selX!==null&&selW>4&&(<div style={{position:"absolute",top:0,left:LW+selX,width:selW,height:"100%",background:"rgba(30,136,229,0.1)",border:"1px solid #1E88E5",borderRadius:2,pointerEvents:"none",zIndex:10}}><div style={{position:"absolute",top:4,left:"50%",transform:"translateX(-50%)",background:"#1E88E5",color:"#fff",fontSize:9,padding:"2px 7px",borderRadius:2,whiteSpace:"nowrap",fontFamily:"Inter",fontWeight:600}}>{hm(Math.round((selW/chartWidth)*dur))}</div></div>)}
          </div>
        </div>
        <ViolationsPanel tachoData={tachoData}/>
        <div style={{display:"flex",justifyContent:"space-between",flexWrap:"wrap",gap:4,marginTop:10}}>
          <span style={{fontSize:10,color:"#BFC5CC"}}>TACHO ANALYZER — EC 3821/85 · (WE) 561/2006 · (UE) 165/2014</span>
          <span style={{fontSize:10,color:"#BFC5CC"}}>Dane przetwarzane lokalnie — brak wysyłki</span>
        </div>
      </div>
      {tip&&(<div style={{position:"fixed",left:tip.mx+16,top:tip.my-50,background:"#FFF",border:"1px solid "+(tip.act>=0?ACT_STROKE[tip.act]+"60":"#1E88E540"),borderLeft:"3px solid "+(tip.act>=0?ACT_STROKE[tip.act]:"#1E88E5"),padding:"9px 13px",borderRadius:4,pointerEvents:"none",zIndex:9999,fontFamily:"Inter",fontSize:12,boxShadow:"0 6px 24px rgba(0,0,0,.12)",minWidth:155}}><div style={{fontWeight:700,fontSize:13,marginBottom:6,color:tip.act>=0?ACT_TEXT[tip.act]:"#1565C0"}}>{tip.act>=0?ACT_NAME[tip.act]:"Odpoczynek dobowy"}</div>{[["Od",hhmm(tip.absS%1440)],["Do",hhmm(tip.absE%1440)],["Czas",hm(tip.dur)]].map(([k,v])=>(<div key={k} style={{display:"flex",justifyContent:"space-between",gap:14,marginBottom:2}}><span style={{color:"#9AA0AA",fontSize:10}}>{k}</span><span style={{color:"#333",fontWeight:500}}>{v}</span></div>))}{tip.date&&<div style={{marginTop:5,fontSize:9,color:"#BFC5CC"}}>{fmtDate(tip.date)}</div>}</div>)}
      <CrossingModal crossing={crossModal} onClose={()=>setCrossModal(null)}/>
      <DayModal day={dayModal} onClose={()=>setDayModal(null)}/>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════

// MAIN APP (Tachograph only)
function App(){
  const [tachoData,setTachoData]=React.useState(genDemo);
  React.useEffect(()=>{
    const st=document.createElement('style');
    st.textContent="button:focus{outline:none}";
    document.head.appendChild(st);
  },[]);
  return(
    <div style={{fontFamily:'Inter,sans-serif',padding:0}}>
      <TachographPanel tachoData={tachoData} setTachoData={setTachoData} onSwitchToDelegate={null}/>
    </div>
  );
}
const root=ReactDOM.createRoot(document.getElementById('tacho-root'));
root.render(<App/>);

</script>

<?php require __DIR__ . '/../../templates/footer.php'; ?>

/* TachoPro 2.0 - SVG Tachograph Timeline Chart v2
 * Ported from truck-delegate-pro.jsx (commit ea1fcf7b808040c2256107ee0b6ba4cd4b3c3589)
 * Features:
 *   - Week count selector: 1-6 weeks (default: current + 4 back = 5 weeks)
 *   - Prev / Next / Today navigation
 *   - Click-drag selection -> fragment preview panel (time range + activity breakdown)
 *   - EU 561/2006 violation rendering (day tints, strips, icons, badges, lists)
 *   - Per-week expandable violation + detail tables
 *   - Responsive resize via ResizeObserver
 */
'use strict';

(function (NS) {

  /* == Activity constants (match JSX) ========================= */
  var ACT_FILL   = ['#80DEEA','#9FA8DA','#FFCC80','#EF9A9A'];
  var ACT_SOLID  = ['#00ACC1','#5C6BC0','#EF6C00','#E53935'];
  var ACT_STROKE = ['#00838F','#3949AB','#BF360C','#C62828'];
  var ACT_TEXT   = ['#006064','#1A237E','#BF360C','#B71C1C'];
  var ACT_NAME   = ['Odpoczynek','Dyspozycyjno\u015b\u0107','Praca','Jazda'];
  var ACT_HFRAC  = [0.30, 0.52, 0.72, 1.00];

  /* Layout constants */
  var LW  = 78;
  var T1Y = 28;
  var T1H = 38;
  var T2Y = 74;
  var T2H = 18;
  var AXY = 104;
  var RH  = 122;
  var TOTAL_MIN = 7 * 1440;

  /* EU 561/2006 limits (minutes) */
  var EU_DAY_DRIVE_WARN  = 540;
  var EU_DAY_DRIVE_ERR   = 600;
  var EU_DAY_REST_MIN    = 660;
  var EU_CONT_DRIVE      = 270;
  var EU_WEEK_DRIVE_WARN = 3360;
  var EU_WEEK_DRIVE_ERR  = 3600;

  /* == Helpers ================================================= */
  function hhmm(m) {
    m = Math.round(m);
    return String(Math.floor(m/60)).padStart(2,'0') + ':' + String(m%60).padStart(2,'0');
  }
  function hm(m) {
    var h = Math.floor(m/60), mm = m%60;
    return mm ? h+'h '+mm+'m' : h+'h';
  }
  function pct(v, t) { return t > 0 ? Math.round(v/t*100) : 0; }
  function fmtDate(d) {
    if (!d) return '';
    if (typeof d === 'string') d = new Date(d);
    return String(d.getDate()).padStart(2,'0') + '.' +
           String(d.getMonth()+1).padStart(2,'0') + '.' + d.getFullYear();
  }
  function addD(d, n) { var r = new Date(d); r.setDate(r.getDate()+n); return r; }
  function monDay(d) {
    var r = new Date(d), dw = r.getDay();
    r.setDate(r.getDate() - (dw===0 ? 6 : dw-1));
    r.setHours(0,0,0,0); return r;
  }
  function isoWeek(d) {
    var t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay()||7));
    return Math.ceil((((t - new Date(Date.UTC(t.getUTCFullYear(),0,1)))/864e5)+1)/7);
  }
  function mkSVG(tag, attrs) {
    var el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attrs||{}).forEach(function(kv){ el.setAttribute(kv[0], String(kv[1])); });
    return el;
  }
  function sum(arr, fn) { return arr.reduce(function(a,x){ return a + fn(x); }, 0); }

  /* == EU Violation Engine ===================================== */
  function computeDayViolations(segs) {
    if (!segs || !segs.length) return [];
    var drive = sum(segs.filter(function(s){ return s.act===3; }), function(s){ return s.dur; });
    var rest  = sum(segs.filter(function(s){ return s.act===0; }), function(s){ return s.dur; });
    var viols = [];
    if (drive > EU_DAY_DRIVE_ERR) {
      viols.push({sev:'error', msg:'Jazda '+hhmm(drive)+' > 10:00', rule:'art.6 ust.1 rozp.561/2006'});
    } else if (drive > EU_DAY_DRIVE_WARN) {
      viols.push({sev:'warn',  msg:'Jazda '+hhmm(drive)+' > 9:00',  rule:'art.6 ust.1 rozp.561/2006'});
    }
    var cont = 0, maxCont = 0;
    segs.forEach(function(s) {
      if (s.act===3) { cont+=s.dur; if(cont>maxCont) maxCont=cont; }
      else if (s.act===0 && s.dur>=15) cont=0;
    });
    if (maxCont > EU_CONT_DRIVE) {
      viols.push({sev:'warn', msg:'Jazda ciągła '+hhmm(maxCont)+' > 4:30', rule:'art.7 rozp.561/2006'});
    }
    if (drive > 0 && rest < EU_DAY_REST_MIN) {
      viols.push({sev:'error', msg:'Odpoczynek '+hhmm(rest)+' < 11:00', rule:'art.8 rozp.561/2006'});
    }
    return viols;
  }

  function computeWeekViolations(weekDays) {
    var totalDrive = sum(weekDays, function(d) {
      return d ? sum((d.segs||[]).filter(function(x){ return x.act===3; }), function(s){ return s.dur; }) : 0;
    });
    if (totalDrive > EU_WEEK_DRIVE_ERR)  return [{sev:'error', msg:'Jazda '+hhmm(totalDrive)+' > 60:00/tydz.', rule:'art.6 ust.2 rozp.561/2006', total:totalDrive}];
    if (totalDrive > EU_WEEK_DRIVE_WARN) return [{sev:'warn',  msg:'Jazda '+hhmm(totalDrive)+' > 56:00/tydz.', rule:'art.6 ust.2 rozp.561/2006', total:totalDrive}];
    return [];
  }

  function dayStatus(segs) {
    var viols = computeDayViolations(segs);
    if (!viols.length) {
      var drive = sum((segs||[]).filter(function(s){ return s.act===3; }), function(s){ return s.dur; });
      return drive > 0 ? 'ok' : null;
    }
    return viols.some(function(v){ return v.sev==='error'; }) ? 'error' : 'warn';
  }

  /* == Build one week row ====================================== */
  function buildWeekRow(weekStart, weekDays, cw, chartArea, selCtrl, onSelComplete) {
    var px = function(m) { return (m/TOTAL_MIN)*cw; };

    var weekDrive = 0, dist = 0, totals = {0:0,1:0,2:0,3:0};
    weekDays.forEach(function(d) {
      if (!d) return;
      (d.segs||[]).forEach(function(s) {
        if (s.act===3) weekDrive += s.dur;
        totals[s.act] = (totals[s.act]||0) + s.dur;
      });
      dist += (d.dist||0);
    });
    var weekViols  = computeWeekViolations(weekDays);
    var dayViols   = weekDays.map(function(d){ return d ? computeDayViolations(d.segs||[]) : []; });
    var hasWkErr   = weekViols.some(function(v){ return v.sev==='error'; });
    var hasWkWarn  = weekViols.some(function(v){ return v.sev==='warn'; });
    var dCol = hasWkErr ? '#E53935' : hasWkWarn ? '#FF9800' : '#43A047';

    /* Wrapper */
    var wrapper = document.createElement('div');
    wrapper.style.cssText = 'border-bottom:1px solid #E2E4EA;background:#FFF;';

    /* Top accent */
    var accent = document.createElement('div');
    accent.style.cssText = 'height:3px;background:' + (hasWkErr ? '#FFCDD2' : hasWkWarn ? '#FFE082' : 'linear-gradient(90deg,#1E88E5,#42A5F5)') + ';opacity:0.8;';
    wrapper.appendChild(accent);

    var mainRow = document.createElement('div');
    mainRow.style.cssText = 'display:flex;align-items:stretch;';

    /* Sidebar */
    var sb = document.createElement('div');
    sb.style.cssText = 'width:'+LW+'px;flex-shrink:0;background:#F8F9FB;border-right:1px solid #E2E4EA;padding:6px 8px;display:flex;flex-direction:column;justify-content:center;gap:1px;';
    var wkBadge = '';
    if (weekViols.length) {
      var bCol = hasWkErr ? {bg:'#FFEBEE',tx:'#C62828'} : {bg:'#FFF8E1',tx:'#E65100'};
      wkBadge = '<div style="font-size:8px;padding:1px 4px;border-radius:2px;background:'+bCol.bg+';color:'+bCol.tx+';font-weight:700;margin-top:2px;">'+(hasWkErr ? '&#9940; NARUSZENIE' : '&#9888; OSTRZEZENIE')+'</div>';
    }
    sb.innerHTML =
      '<div style="display:flex;align-items:center;gap:3px;margin-bottom:2px">' +
        '<div style="width:5px;height:5px;border-radius:50%;background:'+dCol+';flex-shrink:0;"></div>' +
        '<span style="font-size:12px;font-weight:700;color:#1565C0;">W'+String(isoWeek(weekStart)).padStart(2,'0')+'</span>' +
      '</div>' +
      '<div style="font-size:8px;color:#9AA0AA;line-height:1.4;">'+fmtDate(weekStart)+'</div>' +
      '<div style="font-size:8px;color:#9AA0AA;">'+fmtDate(addD(weekStart,6))+'</div>' +
      '<div style="margin-top:2px;font-size:10px;font-weight:700;color:'+dCol+';">'+hhmm(weekDrive)+'</div>' +
      wkBadge;

    /* SVG */
    var svgEl = mkSVG('svg', {width:cw, height:RH, style:'display:block;flex-shrink:0;overflow:visible;cursor:crosshair;-webkit-user-select:none;user-select:none;'});

    /* Day backgrounds with violation tint */
    for (var di=0; di<7; di++) {
      var rx = Math.max(0, px(di*1440)), rw = Math.min(cw, px((di+1)*1440)) - rx;
      if (rw<=0) continue;
      var vl = dayViols[di];
      var bg = vl.some(function(v){ return v.sev==='error'; }) ? '#FFF5F5' :
               vl.some(function(v){ return v.sev==='warn';  }) ? '#FFFDE7' :
               di%2===0 ? '#FFF' : '#F6F7FA';
      svgEl.appendChild(mkSVG('rect', {x:rx, y:0, width:rw, height:RH, fill:bg}));
    }

    /* Status dots */
    weekDays.forEach(function(d, di) {
      var st = d ? dayStatus(d.segs) : null;
      if (!st) return;
      var col = st==='error' ? '#E53935' : st==='warn' ? '#FF9800' : '#43A047';
      var xc = px(di*1440+720);
      if (xc>=4 && xc<=cw-4) svgEl.appendChild(mkSVG('circle', {cx:xc, cy:10, r:3.5, fill:col, opacity:0.85}));
    });

    /* Activity track background */
    svgEl.appendChild(mkSVG('rect', {x:0, y:T1Y, width:cw, height:T1H, fill:'#E0F7FA', rx:2, opacity:0.3}));
    svgEl.appendChild(mkSVG('rect', {x:0, y:T1Y, width:cw, height:T1H, fill:'none', stroke:'#B2EBF2', 'stroke-width':0.8, rx:2}));

    /* Activity slots */
    weekDays.forEach(function(day, di) {
      if (!day || !day.segs) return;
      day.segs.forEach(function(s) {
        var absS=di*1440+s.start, absE=di*1440+s.end;
        var x1=Math.max(0,px(absS)), x2=Math.min(cw,px(absE)), bw=x2-x1;
        if (bw<0.4) return;
        var tCY=T1Y+T1H/2, bh=Math.max(4,Math.round((T1H-2)*ACT_HFRAC[s.act])), by=tCY-bh/2;
        var g = mkSVG('g');
        g.appendChild(mkSVG('rect', {x:x1, y:by, width:bw, height:bh, fill:ACT_FILL[s.act], rx:2}));
        g.appendChild(mkSVG('rect', {x:x1, y:by, width:bw, height:bh, fill:'none', stroke:ACT_STROKE[s.act], 'stroke-width':0.8, rx:2, 'pointer-events':'none'}));
        if (bw>50) {
          var txt=mkSVG('text', {x:x1+bw/2, y:tCY+4, 'text-anchor':'middle', fill:ACT_TEXT[s.act], 'font-size':bw>80?10:8, 'font-family':'Inter,sans-serif', 'font-weight':600, 'pointer-events':'none'});
          txt.textContent = hhmm(s.dur);
          g.appendChild(txt);
        }
        svgEl.appendChild(g);
      });
    });

    /* Violation markers per day (strip at top of track + icon) */
    weekDays.forEach(function(day, di) {
      var vl = dayViols[di];
      if (!vl.length) return;
      var hasErr = vl.some(function(v){ return v.sev==='error'; });
      var col = hasErr ? '#E53935' : '#FF9800';
      var x1=px(di*1440), bw=Math.max(0, px((di+1)*1440)-x1);
      if (bw<1) return;
      svgEl.appendChild(mkSVG('rect', {x:x1+1, y:T1Y, width:Math.max(0,bw-2), height:3, fill:col, opacity:0.8, rx:1}));
      if (bw>16) {
        var ic=mkSVG('text', {x:x1+bw/2, y:T1Y-2, 'text-anchor':'middle', 'font-size':11, 'font-family':'Inter,sans-serif', 'pointer-events':'none'});
        ic.textContent = hasErr ? '\u26D4' : '\u26A0\uFE0F';
        svgEl.appendChild(ic);
      }
    });

    /* Daily-rest track */
    svgEl.appendChild(mkSVG('rect', {x:0, y:T2Y, width:cw, height:T2H, fill:'#E3F2FD', rx:2, opacity:0.35}));
    svgEl.appendChild(mkSVG('rect', {x:0, y:T2Y, width:cw, height:T2H, fill:'none', stroke:'#BBDEFB', 'stroke-width':0.8, rx:2}));
    weekDays.forEach(function(day, di) {
      if (!day || !day.segs) return;
      day.segs.forEach(function(s) {
        if (s.act!==0 || s.dur<9*60) return;
        var x1=Math.max(0,px(di*1440+s.start)), bw=Math.min(cw,px(di*1440+s.end))-x1;
        if (bw<0.4) return;
        var g=mkSVG('g');
        g.appendChild(mkSVG('rect', {x:x1, y:T2Y+1, width:bw, height:T2H-2, fill:'#90CAF9', rx:2, opacity:0.75}));
        if (bw>35) {
          var t=mkSVG('text', {x:x1+bw/2, y:T2Y+T2H/2+4, 'text-anchor':'middle', fill:'#1565C0', 'font-size':8, 'font-family':'Inter,sans-serif', 'font-weight':600, 'pointer-events':'none'});
          t.textContent=hhmm(s.dur); g.appendChild(t);
        }
        svgEl.appendChild(g);
      });
    });

    /* Day separators */
    for (var di2=1; di2<7; di2++) {
      var xsep=px(di2*1440);
      if (xsep>=0 && xsep<=cw) svgEl.appendChild(mkSVG('line', {x1:xsep, y1:T1Y-8, x2:xsep, y2:T2Y+T2H+4, stroke:'#66BB6A', 'stroke-width':1.2, 'stroke-dasharray':'4,3', opacity:0.5}));
    }
    svgEl.appendChild(mkSVG('line', {x1:0, y1:AXY, x2:cw, y2:AXY, stroke:'#E0E2E8', 'stroke-width':1}));
    for (var di3=0; di3<7; di3++) {
      var xm=px(di3*1440+720);
      if (xm<22||xm>cw-22) continue;
      var dLbl=addD(weekStart,di3);
      var tl=mkSVG('text', {x:xm, y:AXY+13, 'text-anchor':'middle', fill:di3>=5?'#9AA0AA':'#1565C0', 'font-size':10, 'font-family':'Inter,sans-serif', 'font-weight':di3>=5?400:600});
      tl.textContent=fmtDate(dLbl); svgEl.appendChild(tl);
    }

    /* Selection overlay rects */
    var selRect = mkSVG('rect', {x:0, y:T1Y-8, width:0, height:T2Y+T2H-T1Y+16,
      fill:'rgba(30,136,229,0.12)', stroke:'#1E88E5', 'stroke-width':1.5, rx:2,
      'pointer-events':'none', visibility:'hidden'});
    var selLabelBg  = mkSVG('rect', {x:0, y:T1Y-21, width:0, height:13, fill:'#1E88E5', rx:2, 'pointer-events':'none', visibility:'hidden'});
    var selLabelTxt = mkSVG('text', {x:0, y:T1Y-11, 'text-anchor':'middle', fill:'#fff', 'font-size':9, 'font-family':'Inter,sans-serif', 'font-weight':600, 'pointer-events':'none', visibility:'hidden'});
    svgEl.appendChild(selRect); svgEl.appendChild(selLabelBg); svgEl.appendChild(selLabelTxt);

    /* Selection interaction */
    svgEl.addEventListener('mousedown', function(e) {
      if (e.button!==0) return; e.preventDefault();
      if (selCtrl.clearPrev) selCtrl.clearPrev();
      selCtrl.clearPrev = function() {
        selRect.setAttribute('visibility','hidden');
        selLabelBg.setAttribute('visibility','hidden');
        selLabelTxt.setAttribute('visibility','hidden');
      };
      var startX = Math.max(0, Math.min(cw, e.clientX - svgEl.getBoundingClientRect().left));

      function onMove(ev) {
        var cur = Math.max(0, Math.min(cw, ev.clientX - svgEl.getBoundingClientRect().left));
        var x1=Math.min(startX,cur), x2=Math.max(startX,cur), bw=x2-x1;
        var dur=Math.round((bw/cw)*TOTAL_MIN);
        selRect.setAttribute('x',x1); selRect.setAttribute('width',bw); selRect.setAttribute('visibility','visible');
        var lw=Math.max(40, hm(dur).length*7);
        selLabelBg.setAttribute('x',x1+bw/2-lw/2); selLabelBg.setAttribute('width',lw);
        selLabelBg.setAttribute('visibility', dur>5?'visible':'hidden');
        selLabelTxt.setAttribute('x',x1+bw/2); selLabelTxt.textContent=hm(dur);
        selLabelTxt.setAttribute('visibility', dur>5?'visible':'hidden');
      }
      function onUp(ev) {
        document.removeEventListener('mousemove',onMove); document.removeEventListener('mouseup',onUp);
        var endX=Math.max(0,Math.min(cw,ev.clientX-svgEl.getBoundingClientRect().left));
        var x1=Math.min(startX,endX), x2=Math.max(startX,endX);
        if (x2-x1<8) { selRect.setAttribute('visibility','hidden'); selLabelBg.setAttribute('visibility','hidden'); selLabelTxt.setAttribute('visibility','hidden'); return; }
        var startMin=Math.round((x1/cw)*TOTAL_MIN), endMin=Math.round((x2/cw)*TOTAL_MIN);
        if (onSelComplete) onSelComplete({weekStart:weekStart, weekDays:weekDays, startMin:startMin, endMin:endMin});
      }
      document.addEventListener('mousemove',onMove); document.addEventListener('mouseup',onUp);
    });

    mainRow.appendChild(sb); mainRow.appendChild(svgEl); wrapper.appendChild(mainRow);

    /* Footer */
    var footer=document.createElement('div');
    footer.style.cssText='display:flex;align-items:stretch;background:#F8F9FB;border-top:1px solid #EEF0F4;';
    var ftL=document.createElement('div');
    ftL.style.cssText='width:'+LW+'px;flex-shrink:0;border-right:1px solid #E2E4EA;padding:4px 8px;display:flex;align-items:center;';
    if (dist>0) ftL.innerHTML='<span style="font-size:9px;color:#9AA0AA;font-weight:500;">'+dist+' km</span>';
    var ftR=document.createElement('div');
    ftR.style.cssText='flex:1;display:flex;align-items:center;flex-wrap:wrap;overflow:hidden;';
    [3,2,1,0].forEach(function(k) {
      var val=totals[k]||0; if(!val) return;
      var itm=document.createElement('div');
      itm.style.cssText='display:flex;align-items:center;gap:5px;padding:4px 10px;border-right:1px solid #EEF0F4;';
      itm.innerHTML='<div style="width:8px;height:8px;border-radius:2px;background:'+ACT_SOLID[k]+';flex-shrink:0;"></div>' +
        '<span style="font-size:9px;color:#6A7080;white-space:nowrap;"><span style="font-weight:600;color:'+ACT_SOLID[k]+';">'+ACT_NAME[k]+'</span> '+hm(val)+'</span>';
      ftR.appendChild(itm);
    });

    /* Violation toggle */
    var allDayViols=[];
    weekDays.forEach(function(d,di){ dayViols[di].forEach(function(v){ allDayViols.push(Object.assign({},v,{date:d?fmtDate(new Date(d.date)):''})); }); });
    var allViols=[].concat(weekViols, allDayViols);
    if (allViols.length) {
      var violBtn=document.createElement('button'); violBtn.type='button';
      var vECol=allViols.some(function(v){ return v.sev==='error'; })?'#C62828':'#E65100';
      violBtn.style.cssText='background:none;border:none;font-size:9px;color:'+vECol+';cursor:pointer;padding:4px 10px;font-family:Inter,sans-serif;font-weight:700;white-space:nowrap;';
      violBtn.textContent=(allViols.some(function(v){ return v.sev==='error'; })?'\u26D4':'\u26A0\uFE0F')+' '+allViols.length+' narusz./ostrz.';
      var violPanel=document.createElement('div');
      violPanel.style.cssText='display:none;background:#FFF8E1;border-top:1px solid #FFE082;padding:6px 12px;';
      violPanel.innerHTML=allViols.map(function(v){
        return '<div style="display:flex;align-items:baseline;gap:8px;padding:2px 0;border-bottom:1px solid #FFF9C4;font-size:11px;font-family:Inter,sans-serif;">' +
          '<span>'+(v.sev==='error'?'&#9940;':'&#9888;&#65039;')+'</span>' +
          '<span style="color:#5A3E00;flex:1;">'+(v.date?'<strong>'+v.date+'</strong> \u2014 ':'')+v.msg+'</span>' +
          '<span style="color:#9A7800;font-size:9px;white-space:nowrap;">'+v.rule+'</span></div>';
      }).join('');
      violBtn.addEventListener('click', function() {
        var shown=violPanel.style.display!=='none';
        violPanel.style.display=shown?'none':'block';
        violBtn.textContent=shown?(allViols.some(function(v){ return v.sev==='error'; })?'\u26D4':'\u26A0\uFE0F')+' '+allViols.length+' narusz./ostrz.':'\u25BE Ukryj naruszenia';
      });
      ftR.appendChild(violBtn);
      footer.appendChild(ftL); footer.appendChild(ftR); wrapper.appendChild(footer); wrapper.appendChild(violPanel);
    } else {
      footer.appendChild(ftL); footer.appendChild(ftR); wrapper.appendChild(footer);
    }

    /* Expand detail table */
    var expandBtn=document.createElement('button'); expandBtn.type='button';
    expandBtn.style.cssText='margin-left:auto;background:none;border:none;font-size:10px;color:#1E88E5;cursor:pointer;padding:4px 14px;font-family:Inter,sans-serif;font-weight:600;flex-shrink:0;';
    expandBtn.textContent='\u25B8 Szczegóły';
    var dtWrap=document.createElement('div'); dtWrap.style.cssText='display:none;border-top:1px solid #EEF0F4;overflow-x:auto;';
    var tbH='<table style="width:100%;border-collapse:collapse;font-size:11px;font-family:Inter,sans-serif;"><thead><tr style="background:#F0F4F8;">';
    ['Data','Start','Stop','Czas','Aktywność','Km'].forEach(function(h){ tbH+='<th style="padding:5px 10px;text-align:left;font-weight:700;color:#5A6070;font-size:10px;border-bottom:1px solid #E0E4E8;white-space:nowrap;">'+h+'</th>'; });
    tbH+='</tr></thead><tbody>';
    weekDays.forEach(function(day, di) {
      if (!day||!day.segs) return;
      var dv=dayViols[di];
      day.segs.forEach(function(s, si) {
        var even=(di*100+si)%2===0;
        tbH+='<tr style="background:'+(even?'#FFF':'#F8FAFC')+'">';
        tbH+='<td style="padding:4px 10px;color:#5A6070;border-bottom:1px solid #F0F2F5;white-space:nowrap;">'+(si===0?fmtDate(new Date(day.date)):'')+'</td>';
        tbH+='<td style="padding:4px 10px;font-family:monospace;color:#1A2030;border-bottom:1px solid #F0F2F5;white-space:nowrap;">'+hhmm(s.start)+'</td>';
        tbH+='<td style="padding:4px 10px;font-family:monospace;color:#1A2030;border-bottom:1px solid #F0F2F5;white-space:nowrap;">'+hhmm(s.end)+'</td>';
        tbH+='<td style="padding:4px 10px;font-family:monospace;font-weight:600;color:#1A2030;border-bottom:1px solid #F0F2F5;white-space:nowrap;">'+hhmm(s.dur)+'</td>';
        tbH+='<td style="padding:4px 10px;border-bottom:1px solid #F0F2F5;white-space:nowrap;"><span style="display:inline-flex;align-items:center;gap:5px;"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:'+ACT_SOLID[s.act]+';flex-shrink:0;"></span><span style="color:'+ACT_SOLID[s.act]+';font-weight:600;">'+ACT_NAME[s.act]+'</span></span></td>';
        tbH+='<td style="padding:4px 10px;color:#5A6070;border-bottom:1px solid #F0F2F5;white-space:nowrap;">'+(si===0&&day.dist?day.dist+' km':'')+'</td></tr>';
        if (si===day.segs.length-1 && dv.length) {
          tbH+='<tr style="background:#FFF8E1;"><td colspan="6" style="padding:3px 10px;border-bottom:1px solid #FFE082;font-size:10px;color:#B45309;">'+dv.map(function(v){ return (v.sev==='error'?'&#9940;':'&#9888;')+' '+v.msg+' <em style=\"color:#9A7800\">('+v.rule+')</em>'; }).join(' &nbsp;&middot;&nbsp; ')+'</td></tr>';
        }
      });
    });
    tbH+='</tbody></table>';
    dtWrap.innerHTML=tbH;
    expandBtn.addEventListener('click', function() {
      var shown=dtWrap.style.display!=='none';
      dtWrap.style.display=shown?'none':'block';
      expandBtn.textContent=shown?'\u25B8 Szczegóły':'\u25BE Ukryj';
    });
    ftR.appendChild(expandBtn);
    wrapper.appendChild(dtWrap);
    chartArea.appendChild(wrapper);
  }

  /* == Selection Preview Panel ================================== */
  function showSelectionPreview(panel, info) {
    var weekStart=info.weekStart, weekDays=info.weekDays, startMin=info.startMin, endMin=info.endMin;
    var dur=endMin-startMin;
    var selTotals={0:0,1:0,2:0,3:0};
    weekDays.forEach(function(day, di) {
      if (!day||!day.segs) return;
      var base=di*1440;
      day.segs.forEach(function(s) {
        var absS=base+s.start, absE=base+s.end;
        var iS=Math.max(absS,startMin), iE=Math.min(absE,endMin);
        if (iE>iS) selTotals[s.act]=(selTotals[s.act]||0)+(iE-iS);
      });
    });
    var startD=addD(weekStart, Math.floor(startMin/1440));
    var endD  =addD(weekStart, Math.floor(endMin/1440));
    var startT=hhmm(startMin%1440), endT=hhmm(endMin%1440);
    var html='<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">' +
      '<span style="font-size:15px;">&#128204;</span>' +
      '<strong style="font-size:13px;color:#1565C0;">Zaznaczony fragment</strong>' +
      '<span style="font-size:11px;color:#5A6070;">'+fmtDate(startD)+' <b>'+startT+'</b> &rarr; '+fmtDate(endD)+' <b>'+endT+'</b></span>' +
      '<span style="margin-left:auto;background:#1E88E5;color:#fff;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">'+hm(dur)+'</span>' +
      '</div><div style="display:flex;gap:8px;flex-wrap:wrap;">';
    [3,2,1,0].forEach(function(k) {
      var val=selTotals[k]||0;
      html+='<div style="display:flex;align-items:center;gap:5px;background:#fff;border:1px solid #BBDEFB;border-radius:4px;padding:5px 10px;">' +
        '<div style="width:10px;height:10px;border-radius:2px;background:'+ACT_SOLID[k]+';flex-shrink:0;"></div>' +
        '<span style="font-size:11px;color:#1A2030;"><strong>'+ACT_NAME[k]+'</strong>: '+hm(val)+' <span style="color:#9AA0AA;">('+pct(val,dur)+'%)</span></span></div>';
    });
    html+='</div>';
    panel.innerHTML=html+'<button onclick="this.parentNode.style.display=\'none\'" style="position:absolute;top:6px;right:10px;background:none;border:none;font-size:18px;line-height:1;cursor:pointer;color:#9AA0AA;">&times;</button>';
    panel.style.position='relative';
    panel.style.display='block';
  }

  /* == Public API ============================================== */
  NS.render = function(containerId, daysData) {
    var container=document.getElementById(containerId);
    if (!container) return;
    container.innerHTML='';
    container.style.fontFamily='Inter,sans-serif';
    if (!daysData||!daysData.length) {
      container.innerHTML='<div style="text-align:center;color:#9AA0AA;padding:24px 0;">Brak danych aktywności do wyświetlenia.</div>';
      return;
    }

    /* Group by ISO weeks */
    var weekMap={};
    daysData.forEach(function(day) {
      var d=new Date(day.date), ws=monDay(d), key=ws.toISOString().slice(0,10);
      if (!weekMap[key]) weekMap[key]={start:ws, days:new Array(7).fill(null)};
      var di=Math.round((d-ws)/86400000);
      if (di>=0&&di<7) weekMap[key].days[di]=day;
    });

    /* State */
    var numWeeks=5;
    var thisMonday=monDay(new Date());
    var startWk=addD(thisMonday, -(numWeeks-1)*7);

    function getVisibleWeeks() {
      var res=[];
      for (var i=0;i<numWeeks;i++) {
        var ws=addD(startWk,i*7), key=ws.toISOString().slice(0,10);
        res.push(weekMap[key]||{start:ws, days:new Array(7).fill(null)});
      }
      return res;
    }

    /* Toolbar */
    var toolbar=document.createElement('div');
    toolbar.style.cssText='display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:6px 0;';

    function mkBtn(lbl, extra) {
      var b=document.createElement('button'); b.type='button'; b.textContent=lbl;
      b.style.cssText='background:#FFF;border:1px solid #DDE1E6;border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer;color:#5A6070;font-family:Inter,sans-serif;'+(extra||'');
      return b;
    }
    var prevBtn=mkBtn('\u25C4');
    var todayBtn=mkBtn('Dziś','background:#E3F2FD;border-color:#1E88E5;color:#1E88E5;font-weight:600;');
    var nextBtn=mkBtn('\u25BA');
    var sep=document.createElement('div'); sep.style.cssText='width:1px;height:20px;background:#DDE1E6;';
    var wkLabel=document.createElement('span');
    wkLabel.style.cssText='font-size:11px;color:#9AA0AA;font-family:Inter,sans-serif;';
    wkLabel.textContent='Tygodni:';
    var dateRange=document.createElement('span');
    dateRange.style.cssText='font-size:11px;color:#5A6070;margin-left:auto;font-family:Inter,sans-serif;';

    var wkBtns=[];
    for (var n=1;n<=6;n++) {
      (function(nn) {
        var b=document.createElement('button'); b.type='button'; b.textContent=nn; b.dataset.n=nn;
        var active=nn===numWeeks;
        b.style.cssText='background:'+(active?'#E3F2FD':'#FFF')+';border:1px solid '+(active?'#1E88E5':'#DDE1E6')+';border-radius:3px;padding:3px 9px;font-size:11px;cursor:pointer;color:'+(active?'#1E88E5':'#9AA0AA')+';font-weight:'+(active?600:400)+';font-family:Inter,sans-serif;';
        b.addEventListener('click', function() {
          numWeeks=nn;
          startWk=addD(thisMonday, -(numWeeks-1)*7);
          wkBtns.forEach(function(x) {
            var a=parseInt(x.dataset.n)===nn;
            x.style.background=a?'#E3F2FD':'#FFF'; x.style.borderColor=a?'#1E88E5':'#DDE1E6';
            x.style.color=a?'#1E88E5':'#9AA0AA'; x.style.fontWeight=a?'600':'400';
          });
          renderWeeks();
        });
        wkBtns.push(b);
      })(n);
    }

    prevBtn.addEventListener('click',  function(){ startWk=addD(startWk,-7); renderWeeks(); });
    nextBtn.addEventListener('click',  function(){ startWk=addD(startWk, 7); renderWeeks(); });
    todayBtn.addEventListener('click', function(){ startWk=addD(thisMonday,-(numWeeks-1)*7); renderWeeks(); });

    [prevBtn, todayBtn, nextBtn, sep, wkLabel].concat(wkBtns).concat([dateRange]).forEach(function(el){ toolbar.appendChild(el); });
    container.appendChild(toolbar);

    /* Legend */
    var legend=document.createElement('div');
    legend.style.cssText='display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:4px 0 6px;';
    [{fill:'#80DEEA',bd:'#00838F',lbl:'Odpoczynek'},{fill:'#EF9A9A',bd:'#C62828',lbl:'Jazda'},
     {fill:'#FFCC80',bd:'#BF360C',lbl:'Praca'},{fill:'#9FA8DA',bd:'#3949AB',lbl:'Dyspozycyjność'},
     {fill:'#90CAF9',bd:'#1E88E5',lbl:'Odpocz. >=9h'}].forEach(function(it) {
      var d=document.createElement('div'); d.style.cssText='display:flex;align-items:center;gap:4px;';
      d.innerHTML='<div style="width:18px;height:9px;background:'+it.fill+';border:1px solid '+it.bd+'80;border-radius:2px;flex-shrink:0;"></div><span style="font-size:10px;color:#5A6070;">'+it.lbl+'</span>';
      legend.appendChild(d);
    });
    var stLg=document.createElement('div');
    stLg.style.cssText='margin-left:auto;font-size:10px;color:#9AA0AA;white-space:nowrap;';
    stLg.innerHTML='&#9679; <span style="color:#43A047;">OK</span> &nbsp;&#9679; <span style="color:#FF9800;">Ostrzez.</span> &nbsp;&#9679; <span style="color:#E53935;">Narusz.</span> &nbsp;<span style="opacity:0.6;">| przeciągnij, by zaznaczyć fragment</span>';
    legend.appendChild(stLg);
    container.appendChild(legend);

    /* Header */
    var hdr=document.createElement('div');
    hdr.style.cssText='display:flex;background:#F0F4F8;border:1px solid #E0E2E8;border-radius:4px 4px 0 0;';
    hdr.innerHTML='<div style="width:'+LW+'px;flex-shrink:0;padding:5px 8px;font-size:9px;font-weight:700;color:#9AA0AA;letter-spacing:1px;border-right:1px solid #E2E4EA;font-family:Inter,sans-serif;">TYDZIEN</div>' +
      '<div style="flex:1;padding:5px 12px;font-size:9px;font-weight:700;color:#9AA0AA;letter-spacing:1px;font-family:Inter,sans-serif;">OŚ CZASU (7 DNI) &#x2014; zaznacz fragment myszą &#x2192; podgląd wybranego okresu</div>';
    container.appendChild(hdr);

    /* Chart area */
    var chartArea=document.createElement('div');
    chartArea.style.cssText='border:1px solid #E0E4E8;border-top:none;border-radius:0 0 4px 4px;overflow:hidden;';
    container.appendChild(chartArea);

    /* Selection preview panel */
    var selPanel=document.createElement('div');
    selPanel.style.cssText='display:none;margin-top:8px;border:1.5px solid #1E88E5;border-radius:6px;background:#EAF4FF;padding:12px 16px;font-family:Inter,sans-serif;';
    container.appendChild(selPanel);

    var selCtrl={clearPrev:null};

    /* Render function */
    function renderWeeks() {
      chartArea.innerHTML='';
      selPanel.style.display='none';
      var cw=Math.max(400, container.clientWidth-LW-4);
      var visible=getVisibleWeeks();
      dateRange.textContent=fmtDate(visible[0].start)+' \u2013 '+fmtDate(addD(visible[visible.length-1].start,6));
      visible.forEach(function(w){ buildWeekRow(w.start, w.days, cw, chartArea, selCtrl, function(info){ showSelectionPreview(selPanel, info); }); });
    }

    requestAnimationFrame(renderWeeks);
    if (typeof ResizeObserver!=='undefined') {
      var ro=new ResizeObserver(function(){ renderWeeks(); });
      ro.observe(container);
    }
  };

})(window.TachoChart = window.TachoChart || {});

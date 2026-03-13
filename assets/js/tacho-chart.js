/* TachoPro 2.0 - SVG Tachograph Timeline Chart v3
 * Features:
 *   - Week count selector: 1-6 weeks (default: current + 4 back = 5 weeks)
 *   - Prev / Next / Today navigation
 *   - Click-drag selection -> ZOOMS INTO selected fragment (hour-grid axis)
 *   - Back button to return to full week view
 *   - Click on any activity bar -> tooltip with full segment details
 *   - EU 561/2006 violation rendering (tints, strips, icons, badges, lists)
 *   - Per-week expandable detail + violation tables
 *   - Responsive resize via ResizeObserver
 */
'use strict';

(function (NS) {

  /* == Activity constants ========================================= */
  var ACT_FILL   = ['#80DEEA','#9FA8DA','#FFCC80','#EF9A9A'];
  var ACT_SOLID  = ['#00ACC1','#5C6BC0','#EF6C00','#E53935'];
  var ACT_STROKE = ['#00838F','#3949AB','#BF360C','#C62828'];
  var ACT_TEXT   = ['#006064','#1A237E','#BF360C','#B71C1C'];
  var ACT_NAME   = ['Odpoczynek','Dyspozycyjno\u015b\u0107','Praca','Jazda'];
  var ACT_HFRAC  = [0.30, 0.52, 0.72, 1.00];

  /* Layout */
  var LW  = 110;
  var T1Y = 46;
  var T1H = 130;
  var T2Y = 190;
  var T2H = 60;
  var AXY = 270;
  var RH  = 305;
  var TOTAL_MIN = 7 * 1440;

  /* EU 561/2006 limits (minutes) */
  var EU_DAY_DRIVE_WARN  = 540;
  var EU_DAY_DRIVE_ERR   = 600;
  var EU_DAY_REST_MIN    = 660;
  var EU_CONT_DRIVE      = 270;
  var EU_WEEK_DRIVE_WARN = 3360;
  var EU_WEEK_DRIVE_ERR  = 3600;

  /* == Helpers ==================================================== */
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

  /* == EU Violation Engine ========================================= */
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
      viols.push({sev:'warn', msg:'Jazda ci\u0105g\u0142a '+hhmm(maxCont)+' > 4:30', rule:'art.7 rozp.561/2006'});
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

  /* == Shared tooltip ============================================== */
  var _tip = null;
  function getTip() {
    if (!_tip) {
      _tip = document.createElement('div');
      _tip.id = 'tacho-seg-tip';
      _tip.style.cssText = 'position:fixed;z-index:9999;background:#1A2030;color:#fff;border-radius:7px;padding:10px 14px;font-size:14px;font-family:Inter,sans-serif;pointer-events:none;box-shadow:0 4px 20px rgba(0,0,0,0.35);display:none;max-width:260px;line-height:1.55;';
      document.body.appendChild(_tip);
      document.addEventListener('click', function(e) {
        if (!e._tachoSeg) _tip.style.display = 'none';
      });
    }
    return _tip;
  }
  function hideTip() { getTip().style.display = 'none'; }
  function showTip(e, s, dayDate) {
    var t = getTip();
    t.innerHTML =
      '<div style="display:flex;align-items:center;gap:7px;margin-bottom:7px;">' +
        '<div style="width:11px;height:11px;border-radius:3px;background:'+ACT_SOLID[s.act]+';flex-shrink:0;"></div>' +
        '<strong style="font-size:15px;color:#ECEFF1;">'+ACT_NAME[s.act]+'</strong>' +
      '</div>' +
      '<div style="color:#78909C;font-size:13px;margin-bottom:3px;">'+fmtDate(dayDate)+'</div>' +
      '<div style="color:#B0BEC5;font-size:13px;">'+hhmm(s.start)+'&nbsp;<span style="opacity:0.5;">\u2192</span>&nbsp;'+hhmm(s.end)+'</div>' +
      '<div style="font-size:17px;font-weight:700;color:'+ACT_SOLID[s.act]+';margin-top:3px;">'+hhmm(s.dur)+'</div>';
    t.style.display = 'block';
    var vx = Math.min(e.clientX+15, window.innerWidth - 260);
    var vy = Math.min(e.clientY+15, window.innerHeight - 110);
    t.style.left = vx + 'px';
    t.style.top  = vy + 'px';
  }

  /* == Core SVG content builder =================================== *
   * Draws tracks, activity bars, violations, axis into an existing  *
   * svgEl.  rangeMin/rangeMax control zoom (0/TOTAL_MIN = full view) *
   * onDateClick(di) – called when a day-label is clicked (full view) */
  function fillChartSVG(svgEl, weekStart, weekDays, cw, rangeMin, rangeMax, onSegClick, dayViols, onDateClick) {
    var rangeSpan = rangeMax - rangeMin;
    var isZoomed  = rangeSpan < TOTAL_MIN * 0.9999;
    var px = function(m) { return (m - rangeMin) / rangeSpan * cw; };
    var clampX = function(m) { return Math.max(0, Math.min(cw, px(m))); };

    /* Day backgrounds */
    var firstDay = Math.floor(rangeMin / 1440);
    var lastDay  = Math.min(7, Math.ceil(rangeMax / 1440));
    for (var di = firstDay; di < lastDay; di++) {
      var bgX1 = clampX(di * 1440), bgX2 = clampX((di+1) * 1440);
      var bw0 = bgX2 - bgX1; if (bw0 <= 0) continue;
      var vl = dayViols ? dayViols[di] || [] : [];
      var bg = vl.some(function(v){ return v.sev==='error'; }) ? '#FFF5F5' :
               vl.some(function(v){ return v.sev==='warn';  }) ? '#FFFDE7' :
               di%2===0 ? '#FFF' : '#F6F7FA';
      svgEl.appendChild(mkSVG('rect', {x:bgX1, y:0, width:bw0, height:RH, fill:bg}));
    }

    /* Status dots (full view only) */
    if (!isZoomed) {
      weekDays.forEach(function(d, di) {
        var st = d ? dayStatus(d.segs) : null; if (!st) return;
        var col = st==='error' ? '#E53935' : st==='warn' ? '#FF9800' : '#43A047';
        var xc = px(di*1440+720);
        if (xc>=4 && xc<=cw-4) svgEl.appendChild(mkSVG('circle', {cx:xc, cy:16, r:5, fill:col, opacity:0.85}));
      });
    }

    /* Activity track background */
    svgEl.appendChild(mkSVG('rect', {x:0, y:T1Y, width:cw, height:T1H, fill:'#E0F7FA', rx:2, opacity:0.3}));
    svgEl.appendChild(mkSVG('rect', {x:0, y:T1Y, width:cw, height:T1H, fill:'none', stroke:'#B2EBF2', 'stroke-width':1.2, rx:2}));

    /* Activity slots */
    weekDays.forEach(function(day, di) {
      if (!day || !day.segs) return;
      day.segs.forEach(function(s) {
        var absS = di*1440 + s.start, absE = di*1440 + s.end;
        if (absE <= rangeMin || absS >= rangeMax) return; /* outside zoom */
        var x1 = clampX(Math.max(absS, rangeMin));
        var x2 = clampX(Math.min(absE, rangeMax));
        var bw = x2 - x1; if (bw < 0.4) return;
        var tCY = T1Y + T1H/2;
        var bh = Math.max(4, Math.round((T1H-2) * ACT_HFRAC[s.act]));
        var by = tCY - bh/2;
        var g = mkSVG('g');
        g.setAttribute('style', 'cursor:pointer;');
        g.appendChild(mkSVG('rect', {x:x1, y:by, width:bw, height:bh, fill:ACT_FILL[s.act], rx:2}));
        g.appendChild(mkSVG('rect', {x:x1, y:by, width:bw, height:bh, fill:'none', stroke:ACT_STROKE[s.act], 'stroke-width':1.2, rx:2, 'pointer-events':'none'}));
        if (bw > 50) {
          var txt = mkSVG('text', {x:x1+bw/2, y:tCY+5, 'text-anchor':'middle', fill:ACT_TEXT[s.act], 'font-size':bw>80?15:13, 'font-family':'Inter,sans-serif', 'font-weight':600, 'pointer-events':'none'});
          txt.textContent = hhmm(s.dur); g.appendChild(txt);
        }
        /* Click -> tooltip */
        (function(seg, dObj) {
          g.addEventListener('click', function(ev) {
            ev.stopPropagation();
            ev._tachoSeg = true;
            showTip(ev, seg, dObj ? new Date(dObj.date) : null);
          });
        })(s, day);
        if (onSegClick) {
          (function(seg, dObj) {
            g.addEventListener('click', function(ev) { onSegClick(ev, seg, dObj); });
          })(s, day);
        }
        svgEl.appendChild(g);
      });
    });

    /* Violation markers per day */
    if (dayViols) {
      weekDays.forEach(function(day, di) {
        var vl = dayViols[di]; if (!vl || !vl.length) return;
        var hasErr = vl.some(function(v){ return v.sev==='error'; });
        var col = hasErr ? '#E53935' : '#FF9800';
        var sx1 = clampX(di*1440), sx2 = clampX((di+1)*1440), sw = sx2-sx1;
        if (sw < 1) return;
        svgEl.appendChild(mkSVG('rect', {x:sx1+1, y:T1Y, width:Math.max(0,sw-2), height:5, fill:col, opacity:0.8, rx:1}));
        if (sw > 16) {
          var ic = mkSVG('text', {x:sx1+sw/2, y:T1Y-2, 'text-anchor':'middle', 'font-size':14, 'font-family':'Inter,sans-serif', 'pointer-events':'none'});
          ic.textContent = hasErr ? '\u26D4' : '\u26A0\uFE0F';
          svgEl.appendChild(ic);
        }
      });
    }

    /* Border crossing markers (EF_CardPlacesOfDailyWorkPeriod 0x0522) */
    weekDays.forEach(function(day, di) {
      var crs = day && day.crossings;
      if (!crs || !crs.length) return;
      crs.forEach(function(cr) {
        var absMin = di * 1440 + cr.tmin;
        if (absMin < rangeMin || absMin > rangeMax) return;
        var x = px(absMin);
        if (x < 2 || x > cw - 2) return;

        /* Vertical dashed line through both activity and rest bands */
        svgEl.appendChild(mkSVG('line', {
          x1: x, y1: T1Y + 12, x2: x, y2: T2Y + T2H,
          stroke: '#263238', 'stroke-width': 1.5,
          'stroke-dasharray': '4,2', opacity: 0.65
        }));

        /* Filled circle pin at the top of the line */
        svgEl.appendChild(mkSVG('circle', {
          cx: x, cy: T1Y + 6, r: 5,
          fill: '#263238', stroke: '#FFFFFF', 'stroke-width': 1.5
        }));

        /* Small right-pointing triangle next to circle */
        var arr = mkSVG('text', {
          x: x + 7, y: T1Y + 10,
          'text-anchor': 'start', fill: '#263238',
          'font-size': 10, 'font-family': 'Inter,sans-serif',
          'pointer-events': 'none'
        });
        arr.textContent = '\u25B6';
        svgEl.appendChild(arr);

        /* Country code label above the band */
        var lbl = mkSVG('text', {
          x: x, y: T1Y - 6,
          'text-anchor': 'middle', fill: '#1A237E',
          'font-size': 13, 'font-family': 'Inter,sans-serif',
          'font-weight': 700, 'pointer-events': 'none'
        });
        lbl.textContent = cr.country;
        svgEl.appendChild(lbl);
      });
    });

    /* Daily-rest track */
    svgEl.appendChild(mkSVG('rect', {x:0, y:T2Y, width:cw, height:T2H, fill:'#E3F2FD', rx:2, opacity:0.35}));
    svgEl.appendChild(mkSVG('rect', {x:0, y:T2Y, width:cw, height:T2H, fill:'none', stroke:'#BBDEFB', 'stroke-width':1.2, rx:2}));
    weekDays.forEach(function(day, di) {
      if (!day || !day.segs) return;
      day.segs.forEach(function(s) {
        if (s.act !== 0 || s.dur < 9*60) return;
        var absS = di*1440+s.start, absE = di*1440+s.end;
        if (absE <= rangeMin || absS >= rangeMax) return;
        var x1 = clampX(Math.max(absS,rangeMin)), x2 = clampX(Math.min(absE,rangeMax)), bw = x2-x1;
        if (bw < 0.4) return;
        var g = mkSVG('g');
        g.appendChild(mkSVG('rect', {x:x1, y:T2Y+1, width:bw, height:T2H-2, fill:'#90CAF9', rx:2, opacity:0.75}));
        if (bw > 35) {
          var t = mkSVG('text', {x:x1+bw/2, y:T2Y+T2H/2+4, 'text-anchor':'middle', fill:'#1565C0', 'font-size':13, 'font-family':'Inter,sans-serif', 'font-weight':600, 'pointer-events':'none'});
          t.textContent = hhmm(s.dur); g.appendChild(t);
        }
        svgEl.appendChild(g);
      });
    });

    /* Separators + axis */
    if (isZoomed) {
      /* Hour (or sub-hour) grid lines in zoomed view */
      var step;
      if (rangeSpan <= 2*60) step = 15;
      else if (rangeSpan <= 4*60) step = 30;
      else if (rangeSpan <= 16*60) step = 60;
      else if (rangeSpan <= 3*1440) step = 6*60;
      else step = 1440;

      /* Draw day boundary separators within range */
      for (var d2 = Math.ceil(rangeMin/1440); d2 < Math.floor(rangeMax/1440)+1 && d2 < 7; d2++) {
        var xd = px(d2*1440);
        if (xd > 0 && xd < cw) svgEl.appendChild(mkSVG('line', {x1:xd, y1:T1Y-8, x2:xd, y2:T2Y+T2H+4, stroke:'#66BB6A', 'stroke-width':1.8, 'stroke-dasharray':'4,3', opacity:0.5}));
      }
      svgEl.appendChild(mkSVG('line', {x1:0, y1:AXY, x2:cw, y2:AXY, stroke:'#E0E2E8', 'stroke-width':1.5}));

      /* Time tick labels */
      var firstTick = Math.ceil(rangeMin / step) * step;
      for (var tk = firstTick; tk <= rangeMax; tk += step) {
        var xt = px(tk); if (xt < 10 || xt > cw-10) continue;
        /* vertical grid line */
        svgEl.appendChild(mkSVG('line', {x1:xt, y1:T1Y, x2:xt, y2:AXY, stroke:'#DDE1E6', 'stroke-width':1.2, opacity:0.6}));
        /* label: day-of-week + time */
        var dIdx = Math.floor(tk/1440);
        var timeStr = hhmm(tk % 1440);
        var dObj2 = addD(weekStart, dIdx);
        var lbl = (tk % 1440 === 0) ? fmtDate(dObj2) : timeStr;
        var tl = mkSVG('text', {x:xt, y:AXY+18, 'text-anchor':'middle', fill:'#1565C0', 'font-size':14, 'font-family':'Inter,sans-serif', 'font-weight':tk%1440===0?700:400});
        tl.textContent = lbl; svgEl.appendChild(tl);
      }
    } else {
      /* Normal full-week separators + day labels */
      for (var di2=1; di2<7; di2++) {
        var xsep = px(di2*1440);
        if (xsep>=0 && xsep<=cw) svgEl.appendChild(mkSVG('line', {x1:xsep, y1:T1Y-8, x2:xsep, y2:T2Y+T2H+4, stroke:'#66BB6A', 'stroke-width':1.8, 'stroke-dasharray':'4,3', opacity:0.5}));
      }
      svgEl.appendChild(mkSVG('line', {x1:0, y1:AXY, x2:cw, y2:AXY, stroke:'#E0E2E8', 'stroke-width':1.5}));
      for (var di3=0; di3<7; di3++) {
        var xm = px(di3*1440+720); if (xm<22||xm>cw-22) continue;
        var dLbl = addD(weekStart, di3);
        if (onDateClick) {
          /* clickable group: transparent hit-rect + label text */
          var dg = mkSVG('g');
          dg.setAttribute('style', 'cursor:pointer;');
          var hitW = Math.min(px(1440) - 4, 90);
          dg.appendChild(mkSVG('rect', {x:xm - hitW/2, y:AXY+2, width:hitW, height:20, fill:'transparent', rx:2}));
          var tl2 = mkSVG('text', {x:xm, y:AXY+18, 'text-anchor':'middle', fill:di3>=5?'#9AA0AA':'#1565C0', 'font-size':15, 'font-family':'Inter,sans-serif', 'font-weight':di3>=5?400:600, 'text-decoration':'underline'});
          tl2.textContent = fmtDate(dLbl);
          dg.appendChild(tl2);
          (function(dayIdx) {
            dg.addEventListener('click', function(ev) {
              ev.stopPropagation();
              onDateClick(dayIdx);
            });
          })(di3);
          svgEl.appendChild(dg);
        } else {
          var tl2b = mkSVG('text', {x:xm, y:AXY+18, 'text-anchor':'middle', fill:di3>=5?'#9AA0AA':'#1565C0', 'font-size':15, 'font-family':'Inter,sans-serif', 'font-weight':di3>=5?400:600});
          tl2b.textContent = fmtDate(dLbl); svgEl.appendChild(tl2b);
        }
      }
    }
  }

  /* == Build one week row ========================================= */
  function buildWeekRow(weekStart, weekDays, cw, chartArea, selCtrl, onSelComplete) {
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
      wkBadge = '<div style="font-size:11px;padding:1px 4px;border-radius:2px;background:'+bCol.bg+';color:'+bCol.tx+';font-weight:700;margin-top:2px;">'+(hasWkErr ? '&#9940; NARUSZENIE' : '&#9888; OSTRZEZENIE')+'</div>';
    }
    sb.innerHTML =
      '<div style="display:flex;align-items:center;gap:3px;margin-bottom:2px">' +
        '<div style="width:8px;height:8px;border-radius:50%;background:'+dCol+';flex-shrink:0;"></div>' +
        '<span style="font-size:16px;font-weight:700;color:#1565C0;">W'+String(isoWeek(weekStart)).padStart(2,'0')+'</span>' +
      '</div>' +
      '<div style="font-size:12px;color:#9AA0AA;line-height:1.4;">'+fmtDate(weekStart)+'</div>' +
      '<div style="font-size:12px;color:#9AA0AA;">'+fmtDate(addD(weekStart,6))+'</div>' +
      '<div style="margin-top:2px;font-size:14px;font-weight:700;color:'+dCol+';">'+hhmm(weekDrive)+'</div>' +
      wkBadge;

    /* SVG */
    var svgEl = mkSVG('svg', {width:cw, height:RH, style:'display:block;flex-shrink:0;overflow:visible;cursor:crosshair;-webkit-user-select:none;user-select:none;'});
    fillChartSVG(svgEl, weekStart, weekDays, cw, 0, TOTAL_MIN, null, dayViols, function(di) {
      /* Date label click → zoom the whole day */
      if (onSelComplete) onSelComplete({weekStart:weekStart, weekDays:weekDays, startMin:di*1440, endMin:(di+1)*1440, isDateClick:true});
    });

    /* Selection overlay rects */
    var selRect = mkSVG('rect', {x:0, y:T1Y-8, width:0, height:T2Y+T2H-T1Y+16,
      fill:'rgba(30,136,229,0.12)', stroke:'#1E88E5', 'stroke-width':1.5, rx:2,
      'pointer-events':'none', visibility:'hidden'});
    var selLabelBg  = mkSVG('rect', {x:0, y:T1Y-25, width:0, height:17, fill:'#1E88E5', rx:2, 'pointer-events':'none', visibility:'hidden'});
    var selLabelTxt = mkSVG('text', {x:0, y:T1Y-11, 'text-anchor':'middle', fill:'#fff', 'font-size':13, 'font-family':'Inter,sans-serif', 'font-weight':600, 'pointer-events':'none', visibility:'hidden'});
    svgEl.appendChild(selRect); svgEl.appendChild(selLabelBg); svgEl.appendChild(selLabelTxt);

    /* Selection drag interaction */
    svgEl.addEventListener('mousedown', function(e) {
      if (e.button!==0) return; e.preventDefault();
      hideTip();
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
        if (x2-x1<8) {
          selRect.setAttribute('visibility','hidden'); selLabelBg.setAttribute('visibility','hidden'); selLabelTxt.setAttribute('visibility','hidden'); return;
        }
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
    if (dist>0) ftL.innerHTML='<span style="font-size:12px;color:#9AA0AA;font-weight:500;">'+dist+' km</span>';
    var ftR=document.createElement('div');
    ftR.style.cssText='flex:1;display:flex;align-items:center;flex-wrap:wrap;overflow:hidden;';
    [3,2,1,0].forEach(function(k) {
      var val=totals[k]||0; if(!val) return;
      var itm=document.createElement('div');
      itm.style.cssText='display:flex;align-items:center;gap:5px;padding:4px 10px;border-right:1px solid #EEF0F4;';
      itm.innerHTML='<div style="width:10px;height:10px;border-radius:2px;background:'+ACT_SOLID[k]+';flex-shrink:0;"></div>' +
        '<span style="font-size:12px;color:#6A7080;white-space:nowrap;"><span style="font-weight:600;color:'+ACT_SOLID[k]+';">'+ACT_NAME[k]+'</span> '+hm(val)+'</span>';
      ftR.appendChild(itm);
    });

    /* Violation toggle */
    var allDayViols=[];
    weekDays.forEach(function(d,di){ dayViols[di].forEach(function(v){ allDayViols.push(Object.assign({},v,{date:d?fmtDate(new Date(d.date)):''})); }); });
    var allViols=[].concat(weekViols, allDayViols);
    if (allViols.length) {
      var violBtn=document.createElement('button'); violBtn.type='button';
      var vECol=allViols.some(function(v){ return v.sev==='error'; })?'#C62828':'#E65100';
      violBtn.style.cssText='background:none;border:none;font-size:12px;color:'+vECol+';cursor:pointer;padding:4px 10px;font-family:Inter,sans-serif;font-weight:700;white-space:nowrap;';
      violBtn.textContent=(allViols.some(function(v){ return v.sev==='error'; })?'\u26D4':'\u26A0\uFE0F')+' '+allViols.length+' narusz./ostrz.';
      var violPanel=document.createElement('div');
      violPanel.style.cssText='display:none;background:#FFF8E1;border-top:1px solid #FFE082;padding:6px 12px;';
      violPanel.innerHTML=allViols.map(function(v){
        return '<div style="display:flex;align-items:baseline;gap:8px;padding:2px 0;border-bottom:1px solid #FFF9C4;font-size:13px;font-family:Inter,sans-serif;">' +
          '<span>'+(v.sev==='error'?'&#9940;':'&#9888;&#65039;')+'</span>' +
          '<span style="color:#5A3E00;flex:1;">'+(v.date?'<strong>'+v.date+'</strong> \u2014 ':'')+v.msg+'</span>' +
          '<span style="color:#9A7800;font-size:11px;white-space:nowrap;">'+v.rule+'</span></div>';
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
    expandBtn.style.cssText='margin-left:auto;background:none;border:none;font-size:13px;color:#1E88E5;cursor:pointer;padding:4px 14px;font-family:Inter,sans-serif;font-weight:600;flex-shrink:0;';
    expandBtn.textContent='\u25B8 Szczeg\u00f3\u0142y';
    var dtWrap=document.createElement('div'); dtWrap.style.cssText='display:none;border-top:1px solid #EEF0F4;overflow-x:auto;';
    var tbH='<table style="width:100%;border-collapse:collapse;font-size:13px;font-family:Inter,sans-serif;"><thead><tr style="background:#F0F4F8;">';
    ['Data','Start','Stop','Czas','Aktywno\u015b\u0107','Km'].forEach(function(h){ tbH+='<th style="padding:6px 10px;text-align:left;font-weight:700;color:#5A6070;font-size:12px;border-bottom:1px solid #E0E4E8;white-space:nowrap;">'+h+'</th>'; });
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
          tbH+='<tr style="background:#FFF8E1;"><td colspan="6" style="padding:3px 10px;border-bottom:1px solid #FFE082;font-size:12px;color:#B45309;">'+dv.map(function(v){ return (v.sev==='error'?'&#9940;':'&#9888;')+' '+v.msg+' <em style=\\"color:#9A7800\\">('+v.rule+')</em>'; }).join(' &nbsp;&middot;&nbsp; ')+'</td></tr>';
        }
      });
    });
    tbH+='</tbody></table>';
    dtWrap.innerHTML=tbH;
    expandBtn.addEventListener('click', function() {
      var shown=dtWrap.style.display!=='none';
      dtWrap.style.display=shown?'none':'block';
      expandBtn.textContent=shown?'\u25B8 Szczeg\u00f3\u0142y':'\u25BE Ukryj';
    });
    ftR.appendChild(expandBtn);
    wrapper.appendChild(dtWrap);
    chartArea.appendChild(wrapper);
  }

  /* == Zoomed view panel ========================================== *
   * Renders a full-width zoomed SVG for the selected time fragment,  *
   * with activity breakdown and a Back button.                        */
  function showZoomedView(zoomPanel, info, cw, onBack) {
    var weekStart = info.weekStart, weekDays = info.weekDays;
    var startMin  = info.startMin,  endMin   = info.endMin;
    var dur       = endMin - startMin;

    /* Compute activity totals within selection */
    var selTotals = {0:0,1:0,2:0,3:0};
    weekDays.forEach(function(day, di) {
      if (!day || !day.segs) return;
      var base = di * 1440;
      day.segs.forEach(function(s) {
        var absS=base+s.start, absE=base+s.end;
        var iS=Math.max(absS,startMin), iE=Math.min(absE,endMin);
        if (iE>iS) selTotals[s.act]=(selTotals[s.act]||0)+(iE-iS);
      });
    });

    var startD = addD(weekStart, Math.floor(startMin/1440));
    var endD   = addD(weekStart, Math.floor(endMin/1440));
    var startT = hhmm(startMin%1440), endT = hhmm(endMin%1440);

    zoomPanel.innerHTML = '';
    zoomPanel.style.cssText = 'margin-top:8px;border:2px solid #1E88E5;border-radius:8px;background:#F0F8FF;overflow:hidden;font-family:Inter,sans-serif;';

    /* Panel header */
    var ph = document.createElement('div');
    ph.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;background:#1E88E5;flex-wrap:wrap;';

    var backBtn = document.createElement('button'); backBtn.type='button';
    backBtn.style.cssText = 'background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.5);border-radius:4px;padding:3px 10px;font-size:13px;color:#fff;cursor:pointer;font-family:Inter,sans-serif;font-weight:600;white-space:nowrap;';
    backBtn.textContent = '\u2190 Wr\u00f3\u0107';
    backBtn.addEventListener('click', function() {
      zoomPanel.innerHTML = '';
      zoomPanel.style.cssText = 'display:none;';
      if (onBack) onBack();
    });

    var phTitle = document.createElement('span');
    phTitle.style.cssText = 'font-size:14px;font-weight:700;color:#fff;white-space:nowrap;';
    /* If the range covers exactly one whole day, show "Day view – dd.mm.yyyy" */
    var isFullDay = (dur === 1440 && startMin % 1440 === 0);
    phTitle.textContent = isFullDay
      ? 'Widok dnia \u2014 ' + fmtDate(startD)
      : 'Powi\u0119kszony fragment \u2014 W'+String(isoWeek(weekStart)).padStart(2,'0');

    var phRange = document.createElement('span');
    phRange.style.cssText = 'font-size:13px;color:rgba(255,255,255,0.85);';
    phRange.innerHTML = fmtDate(startD)+' <b>'+startT+'</b> \u2192 '+fmtDate(endD)+' <b>'+endT+'</b>';

    var phDur = document.createElement('span');
    phDur.style.cssText = 'margin-left:auto;background:rgba(255,255,255,0.25);border-radius:4px;padding:2px 10px;font-size:14px;font-weight:700;color:#fff;white-space:nowrap;';
    phDur.textContent = hm(dur);

    ph.appendChild(backBtn); ph.appendChild(phTitle); ph.appendChild(phRange); ph.appendChild(phDur);
    zoomPanel.appendChild(ph);

    /* Zoomed SVG row */
    var svgRow = document.createElement('div');
    svgRow.style.cssText = 'display:flex;align-items:stretch;background:#FFF;border-bottom:1px solid #BBDEFB;';

    var svgSb = document.createElement('div');
    svgSb.style.cssText = 'width:'+LW+'px;flex-shrink:0;background:#F0F8FF;border-right:1px solid #BBDEFB;padding:6px 8px;display:flex;flex-direction:column;justify-content:center;gap:2px;';
    svgSb.innerHTML = '<div style="font-size:12px;color:#1565C0;font-weight:700;margin-bottom:2px;">'+(isFullDay ? 'DZIE\u0143' : 'POWI\u0118KSZENIE')+'</div>' +
      '<div style="font-size:12px;color:#5A6070;">'+(isFullDay ? fmtDate(startD) : hm(dur))+'</div>';

    var zoomSvg = mkSVG('svg', {width:cw, height:RH, style:'display:block;flex-shrink:0;overflow:visible;cursor:default;'});
    var dayViols = weekDays.map(function(d){ return d ? computeDayViolations(d.segs||[]) : []; });
    fillChartSVG(zoomSvg, weekStart, weekDays, cw, startMin, endMin, null, dayViols);

    svgRow.appendChild(svgSb); svgRow.appendChild(zoomSvg);
    zoomPanel.appendChild(svgRow);

    /* Activity breakdown */
    var breakdown = document.createElement('div');
    breakdown.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#fff;border-top:1px solid #BBDEFB;';
    [3,2,1,0].forEach(function(k) {
      var val = selTotals[k]||0;
      var card = document.createElement('div');
      card.style.cssText = 'display:flex;align-items:center;gap:6px;border:1px solid #BBDEFB;border-radius:5px;padding:6px 12px;background:#F8FBFF;';
      card.innerHTML =
        '<div style="width:10px;height:10px;border-radius:3px;background:'+ACT_SOLID[k]+';flex-shrink:0;"></div>' +
        '<span style="font-size:13px;color:#1A2030;"><strong style="color:'+ACT_SOLID[k]+';">'+ACT_NAME[k]+'</strong>: '+hm(val)+'&nbsp;<span style="color:#9AA0AA;font-size:12px;">('+pct(val,dur)+'%)</span></span>';
      breakdown.appendChild(card);
    });
    zoomPanel.appendChild(breakdown);

    zoomPanel.style.display = 'block';
    /* Scroll into view */
    zoomPanel.scrollIntoView({behavior:'smooth', block:'nearest'});
  }

  /* == Range/zoom modal pop-up ===================================== *
   * Opens a fixed overlay showing a zoomed fragment of the week chart *
   * (result of a drag-select on the weekly timeline).                */
  function showRangeModal(info) {
    var weekStart = info.weekStart, weekDays = info.weekDays;
    var startMin  = info.startMin,  endMin   = info.endMin;
    var dur       = endMin - startMin;
    var cw = Math.max(400, Math.min(860, window.innerWidth * 0.9 - LW - 24));
    var dayViols = weekDays.map(function(d){ return d ? computeDayViolations(d.segs||[]) : []; });

    /* Activity totals within selection */
    var selTotals = {0:0,1:0,2:0,3:0};
    weekDays.forEach(function(day, di) {
      if (!day || !day.segs) return;
      var base = di * 1440;
      day.segs.forEach(function(s) {
        var absS=base+s.start, absE=base+s.end;
        var iS=Math.max(absS,startMin), iE=Math.min(absE,endMin);
        if (iE>iS) selTotals[s.act]=(selTotals[s.act]||0)+(iE-iS);
      });
    });

    var startD = addD(weekStart, Math.floor(startMin/1440));
    var endD   = addD(weekStart, Math.floor(endMin/1440));
    var startT = hhmm(startMin%1440), endT = hhmm(endMin%1440);
    var isFullDay = (dur === 1440 && startMin % 1440 === 0);

    /* Remove any existing modal */
    var ex = document.getElementById('tacho-range-modal');
    if (ex) ex.remove();

    /* Backdrop */
    var backdrop = document.createElement('div');
    backdrop.id = 'tacho-range-modal';
    backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding-top:40px;font-family:Inter,sans-serif;';

    /* Panel */
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;border-radius:10px;box-shadow:0 10px 50px rgba(0,0,0,0.4);width:95%;max-width:960px;overflow:hidden;display:flex;flex-direction:column;';

    /* Header */
    var hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 14px;background:#1E88E5;flex-shrink:0;';
    var titleSpan = document.createElement('span');
    titleSpan.style.cssText = 'flex:1;text-align:center;font-size:15px;font-weight:700;color:#fff;';
    titleSpan.textContent = isFullDay
      ? 'Widok dnia \u2014 ' + fmtDate(startD)
      : 'Powi\u0119kszony fragment \u2014 ' + fmtDate(startD) + ' ' + startT + ' \u2013 ' + fmtDate(endD) + ' ' + endT;
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button'; closeBtn.textContent = '\u2715'; closeBtn.title = 'Zamknij';
    closeBtn.style.cssText = 'background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.5);border-radius:4px;padding:4px 10px;font-size:16px;color:#fff;cursor:pointer;margin-left:4px;font-family:Inter,sans-serif;';
    closeBtn.addEventListener('click', function() { backdrop.remove(); document.removeEventListener('keydown', onKey); });
    hdr.appendChild(titleSpan); hdr.appendChild(closeBtn);
    panel.appendChild(hdr);

    /* Duration info bar */
    var durBar = document.createElement('div');
    durBar.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 14px;background:#E3F2FD;border-bottom:1px solid #BBDEFB;font-family:Inter,sans-serif;font-size:13px;color:#1565C0;';
    durBar.innerHTML = '<strong>Zakres:</strong> '+fmtDate(startD)+' <b>'+startT+'</b> \u2192 '+fmtDate(endD)+' <b>'+endT+'</b>' +
      '<span style="margin-left:auto;background:#1E88E5;border-radius:4px;padding:2px 10px;color:#fff;font-weight:700;font-size:14px;">'+hm(dur)+'</span>';
    panel.appendChild(durBar);

    /* Zoomed SVG row */
    var chartDiv = document.createElement('div');
    chartDiv.style.cssText = 'overflow-x:auto;background:#FFF;border-bottom:1px solid #BBDEFB;flex-shrink:0;';
    var svgRow = document.createElement('div');
    svgRow.style.cssText = 'display:flex;align-items:stretch;';
    var svgSb = document.createElement('div');
    svgSb.style.cssText = 'width:'+LW+'px;flex-shrink:0;background:#F0F8FF;border-right:1px solid #BBDEFB;padding:6px 8px;display:flex;flex-direction:column;justify-content:center;gap:2px;';
    svgSb.innerHTML = '<div style="font-size:12px;color:#1565C0;font-weight:700;margin-bottom:2px;">'+(isFullDay?'DZIE\u0143':'ZOOM')+'</div>'+
      '<div style="font-size:12px;color:#5A6070;">'+(isFullDay?fmtDate(startD):hm(dur))+'</div>';
    var zoomSvg = mkSVG('svg', {width:cw, height:RH, style:'display:block;flex-shrink:0;overflow:visible;cursor:default;'});
    fillChartSVG(zoomSvg, weekStart, weekDays, cw, startMin, endMin, null, dayViols);
    svgRow.appendChild(svgSb); svgRow.appendChild(zoomSvg);
    chartDiv.appendChild(svgRow);
    panel.appendChild(chartDiv);

    /* Activity breakdown */
    var breakdownDiv = document.createElement('div');
    breakdownDiv.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#fff;flex-shrink:0;';
    [3,2,1,0].forEach(function(k) {
      var val = selTotals[k]||0;
      var card = document.createElement('div');
      card.style.cssText = 'display:flex;align-items:center;gap:6px;border:1px solid #BBDEFB;border-radius:5px;padding:6px 12px;background:#F8FBFF;';
      card.innerHTML = '<div style="width:10px;height:10px;border-radius:3px;background:'+ACT_SOLID[k]+';flex-shrink:0;"></div>' +
        '<span style="font-size:13px;color:#1A2030;"><strong style="color:'+ACT_SOLID[k]+';">'+ACT_NAME[k]+'</strong>: '+hm(val)+'&nbsp;<span style="color:#9AA0AA;font-size:12px;">('+pct(val,dur)+'%)</span></span>';
      breakdownDiv.appendChild(card);
    });
    panel.appendChild(breakdownDiv);

    backdrop.appendChild(panel);
    document.body.appendChild(backdrop);

    backdrop.addEventListener('click', function(e) {
      if (e.target === backdrop) { backdrop.remove(); document.removeEventListener('keydown', onKey); }
    });
    function onKey(e) {
      if (!document.getElementById('tacho-range-modal')) { document.removeEventListener('keydown', onKey); return; }
      if (e.key === 'Escape') { backdrop.remove(); document.removeEventListener('keydown', onKey); }
    }
    document.addEventListener('keydown', onKey);
  }

  /* == Day modal pop-up ============================================ *
   * Opens a fixed overlay showing one day's zoomed chart with        *
   * ◄ / ► navigation between all available days in daysData.        */
  function showDayModal(initDate, daysData) {
    /* Sort all days by date */
    var sorted = daysData.slice().sort(function(a,b){ return a.date < b.date ? -1 : 1; });
    /* If the requested date has no data, inject a synthetic empty entry so the
       correct day is shown instead of falling back to the first available day. */
    var curIdx = -1;
    for (var i = 0; i < sorted.length; i++) {
      if (sorted[i].date === initDate) { curIdx = i; break; }
    }
    if (curIdx === -1) {
      sorted.push({date: initDate, segs: [], dist: 0});
      sorted.sort(function(a,b){ return a.date < b.date ? -1 : 1; });
      for (var j = 0; j < sorted.length; j++) {
        if (sorted[j].date === initDate) { curIdx = j; break; }
      }
    }

    /* Remove any existing modal */
    var ex = document.getElementById('tacho-day-modal');
    if (ex) ex.remove();

    /* Backdrop */
    var backdrop = document.createElement('div');
    backdrop.id = 'tacho-day-modal';
    backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding-top:40px;font-family:Inter,sans-serif;';

    /* Panel */
    var panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;border-radius:10px;box-shadow:0 10px 50px rgba(0,0,0,0.4);width:95%;max-width:960px;overflow:hidden;display:flex;flex-direction:column;';

    /* Persistent elements updated by go() */
    var prevBtn   = document.createElement('button');
    var nextBtn   = document.createElement('button');
    var titleSpan = document.createElement('span');
    var chartDiv  = document.createElement('div');
    var breakdownDiv = document.createElement('div');
    var counterDiv   = document.createElement('div');

    function go(idx) {
      curIdx = idx;
      var day = sorted[idx];
      var dayDate = new Date(day.date + 'T00:00:00');
      var ws  = monDay(dayDate);
      var di  = Math.round((dayDate - ws) / 86400000);

      /* Build full weekDays context from sorted */
      var weekDays = new Array(7).fill(null);
      sorted.forEach(function(d) {
        var dd  = new Date(d.date + 'T00:00:00');
        var dws = monDay(dd);
        if (dws.getTime() === ws.getTime()) {
          var ddi = Math.round((dd - dws) / 86400000);
          if (ddi >= 0 && ddi < 7) weekDays[ddi] = d;
        }
      });

      var dayStartMin = di * 1440;
      var hasPrev = idx > 0, hasNext = idx < sorted.length - 1;

      /* Button styles */
      var btnCss = function(en) {
        return 'background:rgba(255,255,255,'+(en?'0.2':'0.06')+');border:1px solid rgba(255,255,255,'+(en?'0.55':'0.2')+');border-radius:4px;padding:4px 12px;font-size:13px;color:'+(en?'#fff':'rgba(255,255,255,0.3)')+';cursor:'+(en?'pointer':'default')+';font-family:Inter,sans-serif;font-weight:700;white-space:nowrap;';
      };
      prevBtn.style.cssText = btnCss(hasPrev); prevBtn.disabled = !hasPrev;
      nextBtn.style.cssText = btnCss(hasNext); nextBtn.disabled = !hasNext;
      titleSpan.textContent = 'Widok dnia \u2014 ' + fmtDate(dayDate);

      /* Chart dimensions */
      var cw = Math.max(400, Math.min(860, window.innerWidth * 0.9 - LW - 24));
      var dayViols = weekDays.map(function(d){ return d ? computeDayViolations(d.segs||[]) : []; });

      chartDiv.innerHTML = '';

      /* Helper: activity totals clipped to [absFrom, absTo] */
      function rangeTotals(absFrom, absTo) {
        var t = {0:0,1:0,2:0,3:0};
        (day.segs||[]).forEach(function(s) {
          var sA = dayStartMin + s.start, eA = dayStartMin + s.end;
          var ov = Math.min(eA, absTo) - Math.max(sA, absFrom);
          if (ov > 0) t[s.act] = (t[s.act]||0) + ov;
        });
        return t;
      }

      /* Helper: update activity breakdown panel */
      function updateBreakdown(totals, totalSpan) {
        breakdownDiv.innerHTML = '';
        [3,2,1,0].forEach(function(k) {
          var val = totals[k]||0;
          var card = document.createElement('div');
          card.style.cssText = 'display:flex;align-items:center;gap:6px;border:1px solid #BBDEFB;border-radius:5px;padding:6px 12px;background:#F8FBFF;';
          card.innerHTML =
            '<div style="width:10px;height:10px;border-radius:3px;background:'+ACT_SOLID[k]+';flex-shrink:0;"></div>' +
            '<span style="font-size:13px;color:#1A2030;"><strong style="color:'+ACT_SOLID[k]+';">'+ACT_NAME[k]+'</strong>: '+hm(val)+
            '&nbsp;<span style="color:#9AA0AA;font-size:12px;">('+pct(val, totalSpan)+'%)</span></span>';
          breakdownDiv.appendChild(card);
        });
      }

      /* == Overview row (full day, drag-to-select) == */
      var overviewRow = document.createElement('div');
      overviewRow.style.cssText = 'display:flex;align-items:stretch;';
      var svgSb = document.createElement('div');
      svgSb.style.cssText = 'width:'+LW+'px;flex-shrink:0;background:#F0F8FF;border-right:1px solid #BBDEFB;padding:6px 8px;display:flex;flex-direction:column;justify-content:center;gap:2px;';
      svgSb.innerHTML =
        '<div style="font-size:12px;color:#1565C0;font-weight:700;margin-bottom:2px;">DZIE\u0143</div>' +
        '<div style="font-size:12px;color:#5A6070;">'+fmtDate(dayDate)+'</div>' +
        '<div style="font-size:11px;color:#9AA0AA;margin-top:4px;">przeciągnij \u2192 zoom</div>';
      var overviewSvg = mkSVG('svg', {width:cw, height:RH,
        style:'display:block;flex-shrink:0;overflow:visible;cursor:crosshair;-webkit-user-select:none;user-select:none;'});
      fillChartSVG(overviewSvg, ws, weekDays, cw, dayStartMin, dayStartMin+1440, null, dayViols);

      /* Selection overlay elements */
      var selRect = mkSVG('rect', {x:0, y:T1Y-8, width:0, height:T2Y+T2H-T1Y+16,
        fill:'rgba(30,136,229,0.12)', stroke:'#1E88E5', 'stroke-width':1.5, rx:2,
        'pointer-events':'none', visibility:'hidden'});
      var selLabelBg  = mkSVG('rect', {x:0, y:T1Y-25, width:0, height:17, fill:'#1E88E5', rx:2,
        'pointer-events':'none', visibility:'hidden'});
      var selLabelTxt = mkSVG('text', {x:0, y:T1Y-11, 'text-anchor':'middle', fill:'#fff',
        'font-size':13, 'font-family':'Inter,sans-serif', 'font-weight':600,
        'pointer-events':'none', visibility:'hidden'});
      overviewSvg.appendChild(selRect);
      overviewSvg.appendChild(selLabelBg);
      overviewSvg.appendChild(selLabelTxt);
      overviewRow.appendChild(svgSb);
      overviewRow.appendChild(overviewSvg);
      chartDiv.appendChild(overviewRow);

      /* == Info bar (shown when a range is selected) == */
      var infoBar = document.createElement('div');
      infoBar.style.cssText = 'display:none;align-items:center;gap:8px;padding:5px 12px;background:#E3F2FD;border-bottom:1px solid #BBDEFB;flex-wrap:wrap;font-family:Inter,sans-serif;';
      chartDiv.appendChild(infoBar);

      /* == Zoom row (shown when a range is selected) == */
      var zoomRow = document.createElement('div');
      zoomRow.style.cssText = 'display:none;align-items:stretch;background:#F8FBFF;border-bottom:1px solid #BBDEFB;';
      chartDiv.appendChild(zoomRow);

      /* Apply a time-range selection: show info bar + zoomed SVG */
      function applySelection(selAbsFrom, selAbsTo) {
        var durMin = selAbsTo - selAbsFrom;
        var timeFrom = hhmm(selAbsFrom - dayStartMin);
        var timeTo   = hhmm(selAbsTo   - dayStartMin);

        /* Info bar */
        infoBar.style.display = 'flex';
        infoBar.innerHTML =
          '<span style="font-size:13px;color:#1565C0;font-weight:700;">\u25BC Powi\u0119kszenie:</span>' +
          '<span style="font-size:13px;color:#1A2030;">'+timeFrom+' \u2013 '+timeTo+'</span>' +
          '<span style="font-size:13px;color:#9AA0AA;">('+hm(durMin)+')</span>';
        var resetBtn2 = document.createElement('button');
        resetBtn2.type = 'button'; resetBtn2.textContent = '\u00D7 Resetuj';
        resetBtn2.style.cssText = 'background:#1E88E5;border:none;border-radius:3px;padding:2px 8px;font-size:13px;color:#fff;cursor:pointer;font-family:Inter,sans-serif;margin-left:auto;';
        resetBtn2.addEventListener('click', clearSelection);
        infoBar.appendChild(resetBtn2);

        /* Zoomed SVG */
        zoomRow.style.display = 'flex';
        zoomRow.innerHTML = '';
        var zSb = document.createElement('div');
        zSb.style.cssText = 'width:'+LW+'px;flex-shrink:0;background:#DDEEFF;border-right:1px solid #BBDEFB;padding:6px 8px;display:flex;flex-direction:column;justify-content:center;gap:2px;font-size:12px;';
        zSb.innerHTML =
          '<div style="color:#1565C0;font-weight:700;margin-bottom:2px;">ZOOM</div>' +
          '<div style="color:#5A6070;">'+timeFrom+'</div>' +
          '<div style="color:#5A6070;">'+timeTo+'</div>';
        var zSvg = mkSVG('svg', {width:cw, height:RH,
          style:'display:block;flex-shrink:0;overflow:visible;cursor:default;'});
        fillChartSVG(zSvg, ws, weekDays, cw, selAbsFrom, selAbsTo, null, dayViols);
        zoomRow.appendChild(zSb);
        zoomRow.appendChild(zSvg);

        /* Update breakdown to selection stats */
        updateBreakdown(rangeTotals(selAbsFrom, selAbsTo), durMin);
      }

      /* Clear selection and restore full-day view */
      function clearSelection() {
        selRect.setAttribute('visibility','hidden');
        selLabelBg.setAttribute('visibility','hidden');
        selLabelTxt.setAttribute('visibility','hidden');
        infoBar.style.display = 'none';
        zoomRow.style.display = 'none';
        zoomRow.innerHTML = '';
        updateBreakdown(rangeTotals(dayStartMin, dayStartMin+1440), 1440);
      }

      /* Drag-to-select on overview SVG */
      overviewSvg.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return; e.preventDefault();
        clearSelection();
        var startX = Math.max(0, Math.min(cw, e.clientX - overviewSvg.getBoundingClientRect().left));
        function onMove(ev) {
          var cur = Math.max(0, Math.min(cw, ev.clientX - overviewSvg.getBoundingClientRect().left));
          var x1=Math.min(startX,cur), x2=Math.max(startX,cur), bw=x2-x1;
          var dur=Math.round((bw/cw)*1440);
          selRect.setAttribute('x',x1); selRect.setAttribute('width',bw); selRect.setAttribute('visibility','visible');
          var lw=Math.max(40, hm(dur).length*7);
          selLabelBg.setAttribute('x',x1+bw/2-lw/2); selLabelBg.setAttribute('width',lw);
          selLabelBg.setAttribute('visibility', dur>5?'visible':'hidden');
          selLabelTxt.setAttribute('x',x1+bw/2); selLabelTxt.textContent=hm(dur);
          selLabelTxt.setAttribute('visibility', dur>5?'visible':'hidden');
        }
        function onUp(ev) {
          document.removeEventListener('mousemove',onMove); document.removeEventListener('mouseup',onUp);
          var endX=Math.max(0,Math.min(cw,ev.clientX-overviewSvg.getBoundingClientRect().left));
          var x1=Math.min(startX,endX), x2=Math.max(startX,endX);
          if (x2-x1 < 8) {
            selRect.setAttribute('visibility','hidden');
            selLabelBg.setAttribute('visibility','hidden');
            selLabelTxt.setAttribute('visibility','hidden');
            return;
          }
          var selFrom = dayStartMin + Math.round((x1/cw)*1440);
          var selTo   = dayStartMin + Math.round((x2/cw)*1440);
          applySelection(selFrom, selTo);
        }
        document.addEventListener('mousemove',onMove);
        document.addEventListener('mouseup',onUp);
      });

      /* Initial breakdown: full day */
      updateBreakdown(rangeTotals(dayStartMin, dayStartMin+1440), 1440);
      counterDiv.textContent = 'Dzie\u0144 ' + (idx+1) + ' z ' + sorted.length;
    }

    /* Build static panel structure */
    var hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 14px;background:#1E88E5;flex-shrink:0;';
    prevBtn.type = 'button'; prevBtn.innerHTML = '\u25C4 Poprzedni';
    nextBtn.type = 'button'; nextBtn.innerHTML = 'Nast\u0119pny \u25BA';
    prevBtn.addEventListener('click', function() { if (curIdx > 0) go(curIdx - 1); });
    nextBtn.addEventListener('click', function() { if (curIdx < sorted.length-1) go(curIdx + 1); });
    titleSpan.style.cssText = 'flex:1;text-align:center;font-size:15px;font-weight:700;color:#fff;';
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button'; closeBtn.textContent = '\u2715';
    closeBtn.title = 'Zamknij';
    closeBtn.style.cssText = 'background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.5);border-radius:4px;padding:4px 10px;font-size:16px;color:#fff;cursor:pointer;margin-left:4px;font-family:Inter,sans-serif;';
    closeBtn.addEventListener('click', function() { backdrop.remove(); document.removeEventListener('keydown', onKey); });
    hdr.appendChild(prevBtn); hdr.appendChild(titleSpan); hdr.appendChild(nextBtn); hdr.appendChild(closeBtn);
    panel.appendChild(hdr);

    chartDiv.style.cssText = 'overflow-x:auto;background:#FFF;border-bottom:1px solid #BBDEFB;flex-shrink:0;';
    panel.appendChild(chartDiv);

    breakdownDiv.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#fff;flex-shrink:0;';
    panel.appendChild(breakdownDiv);

    counterDiv.style.cssText = 'text-align:center;padding:5px;font-size:13px;color:#9AA0AA;background:#F8FBFF;border-top:1px solid #E0E4E8;flex-shrink:0;';
    panel.appendChild(counterDiv);

    /* Populate */
    go(curIdx);

    backdrop.appendChild(panel);
    document.body.appendChild(backdrop);

    /* Close on backdrop click */
    backdrop.addEventListener('click', function(e) {
      if (e.target === backdrop) { backdrop.remove(); document.removeEventListener('keydown', onKey); }
    });

    /* Keyboard: ← → navigate, Esc close */
    function onKey(e) {
      if (!document.getElementById('tacho-day-modal')) { document.removeEventListener('keydown', onKey); return; }
      if (e.key === 'Escape') { backdrop.remove(); document.removeEventListener('keydown', onKey); }
      else if (e.key === 'ArrowLeft'  && curIdx > 0) go(curIdx - 1);
      else if (e.key === 'ArrowRight' && curIdx < sorted.length-1) go(curIdx + 1);
    }
    document.addEventListener('keydown', onKey);
  }
  NS.render = function(containerId, daysData) {
    var container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    container.style.fontFamily = 'Inter,sans-serif';
    if (!daysData || !daysData.length) {
      container.innerHTML = '<div style="text-align:center;color:#9AA0AA;padding:24px 0;">Brak danych aktywno\u015bci do wy\u015bwietlenia.</div>';
      return;
    }

    /* Group by ISO weeks */
    var weekMap = {};
    daysData.forEach(function(day) {
      var d=new Date(day.date), ws=monDay(d), key=ws.toISOString().slice(0,10);
      if (!weekMap[key]) weekMap[key]={start:ws, days:new Array(7).fill(null)};
      var di=Math.round((d-ws)/86400000);
      if (di>=0&&di<7) weekMap[key].days[di]=day;
    });

    /* State */
    var numWeeks = 5;
    var thisMonday = monDay(new Date());
    var startWk = addD(thisMonday, -(numWeeks-1)*7);

    function getVisibleWeeks() {
      var res=[];
      for (var i=0;i<numWeeks;i++) {
        var ws=addD(startWk,i*7), key=ws.toISOString().slice(0,10);
        res.push(weekMap[key]||{start:ws, days:new Array(7).fill(null)});
      }
      return res;
    }

    /* Toolbar */
    var toolbar = document.createElement('div');
    toolbar.style.cssText = 'display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:6px 0;';

    function mkBtn(lbl, extra) {
      var b=document.createElement('button'); b.type='button'; b.textContent=lbl;
      b.style.cssText='background:#FFF;border:1px solid #DDE1E6;border-radius:4px;padding:4px 10px;font-size:14px;cursor:pointer;color:#5A6070;font-family:Inter,sans-serif;'+(extra||'');
      return b;
    }
    var prevBtn  = mkBtn('\u25C4');
    var todayBtn = mkBtn('Dzi\u015b','background:#E3F2FD;border-color:#1E88E5;color:#1E88E5;font-weight:600;');
    var nextBtn  = mkBtn('\u25BA');
    var sep      = document.createElement('div'); sep.style.cssText = 'width:1px;height:20px;background:#DDE1E6;';
    var wkLabel  = document.createElement('span');
    wkLabel.style.cssText = 'font-size:13px;color:#9AA0AA;font-family:Inter,sans-serif;';
    wkLabel.textContent = 'Tygodni:';
    var dateRange = document.createElement('span');
    dateRange.style.cssText = 'font-size:13px;color:#5A6070;margin-left:auto;font-family:Inter,sans-serif;';

    var wkBtns = [];
    for (var n=1; n<=6; n++) {
      (function(nn) {
        var b=document.createElement('button'); b.type='button'; b.textContent=nn; b.dataset.n=nn;
        var active=nn===numWeeks;
        b.style.cssText='background:'+(active?'#E3F2FD':'#FFF')+';border:1px solid '+(active?'#1E88E5':'#DDE1E6')+';border-radius:3px;padding:3px 9px;font-size:13px;cursor:pointer;color:'+(active?'#1E88E5':'#9AA0AA')+';font-weight:'+(active?600:400)+';font-family:Inter,sans-serif;';
        b.addEventListener('click', function() {
          numWeeks=nn; startWk=addD(thisMonday,-(numWeeks-1)*7);
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
    var legend = document.createElement('div');
    legend.style.cssText = 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:4px 0 6px;';
    [{fill:'#80DEEA',bd:'#00838F',lbl:'Odpoczynek'},{fill:'#EF9A9A',bd:'#C62828',lbl:'Jazda'},
     {fill:'#FFCC80',bd:'#BF360C',lbl:'Praca'},{fill:'#9FA8DA',bd:'#3949AB',lbl:'Dyspozycyjno\u015b\u0107'},
     {fill:'#90CAF9',bd:'#1E88E5',lbl:'Odpocz. \u22659h'}].forEach(function(it) {
      var d=document.createElement('div'); d.style.cssText='display:flex;align-items:center;gap:4px;';
      d.innerHTML='<div style="width:18px;height:11px;background:'+it.fill+';border:1px solid '+it.bd+'80;border-radius:2px;flex-shrink:0;"></div><span style="font-size:12px;color:#5A6070;">'+it.lbl+'</span>';
      legend.appendChild(d);
    });
    var stLg = document.createElement('div');
    stLg.style.cssText = 'margin-left:auto;font-size:12px;color:#9AA0AA;white-space:nowrap;';
    stLg.innerHTML = '&#9679; <span style="color:#43A047;">OK</span> &nbsp;&#9679; <span style="color:#FF9800;">Ostrzez.</span> &nbsp;&#9679; <span style="color:#E53935;">Narusz.</span> &nbsp;<span style="opacity:0.6;">| przeci\u0105gnij \u2192 powi\u0119kszenie | kliknij dat\u0119 \u2192 poka\u017c dzie\u0144 | kliknij na aktywno\u015b\u0107 \u2192 opis</span>';
    legend.appendChild(stLg);
    container.appendChild(legend);

    /* Chart header */
    var hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex;background:#F0F4F8;border:1px solid #E0E2E8;border-radius:4px 4px 0 0;';
    hdr.innerHTML = '<div style="width:'+LW+'px;flex-shrink:0;padding:5px 8px;font-size:12px;font-weight:700;color:#9AA0AA;letter-spacing:1px;border-right:1px solid #E2E4EA;font-family:Inter,sans-serif;">TYDZIEN</div>' +
      '<div style="flex:1;padding:5px 12px;font-size:11px;font-weight:700;color:#9AA0AA;letter-spacing:1px;font-family:Inter,sans-serif;">O\u015a CZASU (7 DNI) &#x2014; przeci\u0105gnij by powi\u0119kszy\u0107 fragment | kliknij dat\u0119 by zobaczy\u0107 dzie\u0144 | kliknij aktywno\u015b\u0107 by zobaczy\u0107 szczeg\u00f3\u0142y</div>';
    container.appendChild(hdr);

    /* Chart area */
    var chartArea = document.createElement('div');
    chartArea.style.cssText = 'border:1px solid #E0E4E8;border-top:none;border-radius:0 0 4px 4px;overflow:hidden;';
    container.appendChild(chartArea);

    var selCtrl = {clearPrev: null};

    /* Render function */
    function renderWeeks() {
      chartArea.innerHTML = '';
      hideTip();
      var cw = Math.max(400, container.clientWidth - LW - 4);
      var visible = getVisibleWeeks();
      dateRange.textContent = fmtDate(visible[0].start) + ' \u2013 ' + fmtDate(addD(visible[visible.length-1].start, 6));
      visible.forEach(function(w) {
        buildWeekRow(w.start, w.days, cw, chartArea, selCtrl, function(info) {
          if (info.isDateClick) {
            /* Build date string using local calendar fields to avoid UTC offset bug */
            var d = addD(info.weekStart, Math.floor(info.startMin / 1440));
            var dateStr = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
            showDayModal(dateStr, daysData);
          } else {
            showRangeModal(info);
          }
        });
      });
    }

    requestAnimationFrame(renderWeeks);
    if (typeof ResizeObserver !== 'undefined') {
      var _lastRoWidth = 0;
      var ro = new ResizeObserver(function(entries) {
        var w = entries[0].contentRect.width;
        if (Math.abs(w - _lastRoWidth) > 1) { _lastRoWidth = w; renderWeeks(); }
      });
      ro.observe(container);
    }
  };

})(window.TachoChart = window.TachoChart || {});

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
  /* rest=vivid sky-blue, available=vivid green, work=vivid orange, drive=vivid red */
  var ACT_FILL   = ['rgba(41,182,246,0.55)','rgba(76,175,80,0.5)','rgba(255,152,0,0.82)','rgba(244,67,54,0.9)'];
  var ACT_SOLID  = ['#29B6F6','#4CAF50','#FF9800','#F44336'];
  var ACT_STROKE = ['#0288D1','#388E3C','#F57C00','#D32F2F'];
  var ACT_TEXT   = ['#01579B','#1B5E20','#fff','#fff'];
  var ACT_NAME   = ['Odpoczynek','Dyspozycyjno\u015b\u0107','Praca','Jazda'];
  /* Activity icons: — rest, ◇ available, ⚙ work, ▶ drive */
  var ACT_ICONS  = ['\u2014','\u25C7','\u2699','\u25B6'];
  /* Bar heights as fraction of track height, bottom-aligned (rest thin → drive full) */
  var ACT_HEIGHT_FRAC = [0.22, 0.44, 0.72, 1.0];

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

  /* Merge consecutive rest (act=0) segments within a day into single spans */
  function mergeRestSegs(segs) {
    if (!segs || !segs.length) return segs;
    var result = [];
    var pending = null;
    segs.forEach(function(s) {
      if (s.act === 0) {
        if (pending && s.start <= pending.end + 1) {
          pending.end = s.end;
          pending.dur = pending.end - pending.start;
        } else {
          if (pending) result.push(pending);
          pending = {act: 0, start: s.start, end: s.end, dur: s.dur};
        }
      } else {
        if (pending) { result.push(pending); pending = null; }
        result.push(s);
      }
    });
    if (pending) result.push(pending);
    return result;
  }

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
               '#FFFFFF';
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

    /* Activity track background – no border lines, pure white */
    svgEl.appendChild(mkSVG('rect', {x:0, y:T1Y, width:cw, height:T1H, fill:'#FFFFFF'}));

    /* Activity slots – variable heights, bottom-aligned (drive=full, work=72%, available=44%, rest=22%) */
    weekDays.forEach(function(day, di) {
      if (!day || !day.segs) return;
      mergeRestSegs(day.segs).forEach(function(s) {
        var absS = di*1440 + s.start, absE = di*1440 + s.end;
        if (absE <= rangeMin || absS >= rangeMax) return; /* outside zoom */
        var x1 = clampX(Math.max(absS, rangeMin));
        var x2 = clampX(Math.min(absE, rangeMax));
        var bw = x2 - x1; if (bw < 0.4) return;
        /* Height fraction based on activity type, bottom-aligned */
        var barH = T1H - 4;
        var bh = Math.max(3, Math.round(barH * ACT_HEIGHT_FRAC[s.act]));
        var by = T1Y + 2 + (barH - bh);
        var segFill = ACT_SOLID[s.act];
        var textCol = ACT_TEXT[s.act];
        var g = mkSVG('g');
        g.setAttribute('style', 'cursor:pointer;');
        g.appendChild(mkSVG('rect', {x:x1, y:by, width:bw, height:bh, fill:segFill, rx:2}));
        /* Subtle border on all bars */
        g.appendChild(mkSVG('rect', {x:x1, y:by, width:bw, height:bh, fill:'none', stroke:ACT_STROKE[s.act], 'stroke-width':1, rx:2, 'pointer-events':'none'}));
        if (bw > 28 && bh > 16) {
          var fsz = bw > 80 ? 14 : bw > 45 ? 12 : 10;
          /* Duration label – upper portion */
          var txt = mkSVG('text', {x:x1+bw/2, y:by+Math.max(12,bh*0.44), 'text-anchor':'middle', fill:textCol, 'font-size':fsz, 'font-family':'Inter,sans-serif', 'font-weight':600, 'pointer-events':'none'});
          txt.textContent = hhmm(s.dur); g.appendChild(txt);
          /* Activity icon – lower portion (only if bar is tall enough) */
          if (bh > 28) {
            var ico = mkSVG('text', {x:x1+bw/2, y:by+bh-6, 'text-anchor':'middle', fill:textCol, 'font-size':Math.max(9, fsz-2), 'font-family':'Inter,sans-serif', 'pointer-events':'none'});
            ico.textContent = ACT_ICONS[s.act]; g.appendChild(ico);
          }
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

    /* Daily / weekly rest track – pure white background, no border */
    var DAILY_REST_MIN  = 9  * 60;  /* 540 min = minimum daily rest       */
    var WEEKLY_REST_MIN = 45 * 60;  /* 2700 min = regular weekly rest     */
    var REDUCED_WEEKLY  = 24 * 60;  /* 1440 min = reduced weekly rest     */
    svgEl.appendChild(mkSVG('rect', {x:0, y:T2Y, width:cw, height:T2H, fill:'#FFFFFF'}));

    /* Build merged rest spans that cross midnight:
     * each entry = {absStart, absEnd, dur}  (absolute minutes 0..7*1440)
     * Rule: a day with NO segments means the driver's card was not inserted
     * (common during weekly rest).  Any pending rest span is extended through
     * the entire empty day rather than being cut off, ensuring continuity from
     * the end of the last activity to the start of the next one. */
    var restSpans = [];
    var pending = null;   /* rest span being built (might cross midnight) */
    for (var rdi = 0; rdi < 7; rdi++) {
      var rday = weekDays[rdi];
      var rsegs = rday && rday.segs ? rday.segs : [];
      if (!rsegs.length) {
        /* Empty day – no tachograph data (card removed = driver resting).
         * Extend any existing rest span through the whole day; if no rest
         * was pending, do nothing (we cannot assume rest started here). */
        if (pending) {
          pending.absEnd = (rdi + 1) * 1440;
          pending.dur    = pending.absEnd - pending.absStart;
        }
        continue;
      }
      /* Collect rest segments for this day */
      for (var rsi = 0; rsi < rsegs.length; rsi++) {
        var rs = rsegs[rsi];
        if (rs.act !== 0) { if (pending) { restSpans.push(pending); pending = null; } continue; }
        var aS = rdi * 1440 + rs.start, aE = rdi * 1440 + rs.end;
        if (pending && aS <= pending.absEnd + 1) {
          /* continuation of previous rest (cross-midnight merge) */
          pending.absEnd = aE;
          pending.dur = pending.absEnd - pending.absStart;
        } else {
          if (pending) { restSpans.push(pending); }
          pending = {absStart: aS, absEnd: aE, dur: aE - aS};
        }
      }
    }
    if (pending) { restSpans.push(pending); }

    /* Render merged rest spans */
    restSpans.forEach(function(rs) {
      if (rs.dur < DAILY_REST_MIN) return;
      if (rs.absEnd <= rangeMin || rs.absStart >= rangeMax) return;
      var x1 = clampX(Math.max(rs.absStart, rangeMin));
      var x2 = clampX(Math.min(rs.absEnd, rangeMax));
      var bw = x2 - x1; if (bw < 0.4) return;
      var isWeekly = rs.dur >= WEEKLY_REST_MIN;
      var isReducedWeekly = !isWeekly && rs.dur >= REDUCED_WEEKLY;
      /* Vivid: cyan for daily rest, vivid blue shades for weekly rest */
      var restFill = isWeekly ? '#1565C0' : isReducedWeekly ? '#1E88E5' : '#00BCD4';
      var g = mkSVG('g');
      g.appendChild(mkSVG('rect', {x:x1, y:T2Y+1, width:bw, height:T2H-2, fill:restFill, rx:2}));
      if (bw > 22) {
        var ico = mkSVG('text', {x:x1+bw/2, y:T2Y+14, 'text-anchor':'middle', fill:'#fff', 'font-size':11, 'font-family':'Inter,sans-serif', 'pointer-events':'none'});
        ico.textContent = '\u22A2'; g.appendChild(ico);
      }
      if (bw > 35) {
        var t = mkSVG('text', {x:x1+bw/2, y:T2Y+T2H/2+4, 'text-anchor':'middle', fill:'#fff', 'font-size':13, 'font-family':'Inter,sans-serif', 'font-weight':700, 'pointer-events':'none'});
        t.textContent = hhmm(rs.dur); g.appendChild(t);
      }
      if ((isWeekly || isReducedWeekly) && bw > 55) {
        var wl = mkSVG('text', {x:x1+bw/2, y:T2Y+T2H-4, 'text-anchor':'middle', fill:'rgba(255,255,255,0.9)', 'font-size':10, 'font-family':'Inter,sans-serif', 'font-weight':700, 'pointer-events':'none'});
        wl.textContent = isWeekly ? 'TYGODNIOWY' : 'SKR\u00d3CONY'; g.appendChild(wl);
      }
      /* Clickable tooltip for rest span */
      (function(span) {
        var hit = mkSVG('rect', {x:x1, y:T2Y, width:bw, height:T2H, fill:'transparent', cursor:'pointer'});
        hit.addEventListener('click', function(ev) {
          ev.stopPropagation();
          ev._tachoSeg = true;
          var tip = getTip();
          var label = span.dur >= WEEKLY_REST_MIN ? 'Odpoczynek tygodniowy' :
                      span.dur >= REDUCED_WEEKLY  ? 'Odpoczynek skrócony tygodniowy' :
                                                    'Odpoczynek dobowy';
          var startDay = Math.floor(span.absStart / 1440);
          var startMin = span.absStart % 1440;
          var endDay   = Math.floor(span.absEnd   / 1440);
          var endMin   = span.absEnd   % 1440;
          tip.innerHTML =
            '<div style="display:flex;align-items:center;gap:7px;margin-bottom:6px;">' +
              '<div style="width:11px;height:11px;border-radius:3px;background:' + restFill + ';flex-shrink:0;"></div>' +
              '<strong style="font-size:15px;color:#ECEFF1;">' + label + '</strong>' +
            '</div>' +
            '<div style="color:#B0BEC5;font-size:13px;">' + hhmm(startMin) + (endDay > startDay ? ' (D' + (startDay+1) + ')' : '') +
              '&nbsp;\u2192&nbsp;' + hhmm(endMin) + (endDay !== startDay ? ' (D' + (endDay+1) + ')' : '') + '</div>' +
            '<div style="font-size:17px;font-weight:700;color:' + restFill + ';margin-top:3px;">' + hhmm(span.dur) + '</div>';
          tip.style.display = 'block';
          tip.style.left = Math.min(ev.clientX + 15, window.innerWidth - 260) + 'px';
          tip.style.top  = Math.min(ev.clientY + 15, window.innerHeight - 120) + 'px';
        });
        g.appendChild(hit);
      })(rs);
      svgEl.appendChild(g);
    });

    /* Border crossing markers (EF_CardPlacesOfDailyWorkPeriod 0x0522)
     * Drawn LAST so they appear on top of both activity and rest bands.
     * Visual: bold country code above band ▸ filled ● pin at band top ▸ solid
     * vertical line through activity + rest bands — matching reference chart. */
    weekDays.forEach(function(day, di) {
      var crs = day && day.crossings;
      if (!crs || !crs.length) return;
      crs.forEach(function(cr) {
        var absMin = di * 1440 + cr.tmin;
        if (absMin < rangeMin || absMin > rangeMax) return;
        var x = px(absMin);

        /* Solid vertical line from top of activity band through rest band */
        svgEl.appendChild(mkSVG('line', {
          x1: x, y1: T1Y - 16, x2: x, y2: T2Y + T2H,
          stroke: '#1565C0', 'stroke-width': 2, opacity: 0.85
        }));

        /* Filled circle pin at the very top of the activity band */
        svgEl.appendChild(mkSVG('circle', {
          cx: x, cy: T1Y, r: 5,
          fill: '#1565C0', stroke: '#E3F2FD', 'stroke-width': 1.5
        }));

        /* Country code pill/badge above the activity band */
        var pillW = Math.max(22, cr.country.length * 9 + 8);
        svgEl.appendChild(mkSVG('rect', {
          x: x - pillW/2, y: T1Y - 30, width: pillW, height: 17,
          fill: '#1565C0', rx: 3, 'pointer-events': 'none'
        }));
        var lbl = mkSVG('text', {
          x: x, y: T1Y - 16,
          'text-anchor': 'middle', fill: '#FFFFFF',
          'font-size': 11, 'font-family': 'Inter,sans-serif',
          'font-weight': 700, 'pointer-events': 'none'
        });
        lbl.textContent = cr.country;
        svgEl.appendChild(lbl);

        /* Invisible hit-area for tooltip */
        (function(crossing, dayObj) {
          var hit = mkSVG('rect', {
            x: x - 12, y: T1Y - 32, width: 24, height: T2Y + T2H - T1Y + 32,
            fill: 'transparent', cursor: 'pointer'
          });
          hit.addEventListener('click', function(ev) {
            ev.stopPropagation();
            ev._tachoSeg = true;
            var tip = getTip();
            var crossDate = dayObj ? new Date(dayObj.date) : null;
            tip.innerHTML =
              '<div style="display:flex;align-items:center;gap:7px;margin-bottom:7px;">' +
                '<div style="width:11px;height:11px;border-radius:3px;background:#1565C0;flex-shrink:0;"></div>' +
                '<strong style="font-size:15px;color:#ECEFF1;">' + crossing.country + '</strong>' +
              '</div>' +
              (crossDate ? '<div style="color:#78909C;font-size:13px;margin-bottom:3px;">' + fmtDate(crossDate) + '</div>' : '') +
              '<div style="color:#B0BEC5;font-size:13px;">' + hhmm(crossing.tmin) + '</div>' +
              '<div style="font-size:12px;color:#546E7A;margin-top:4px;">' +
                (crossing.type === 0 ? 'Wjazd' : crossing.type === 1 ? 'Wyjazd' : 'Przejazd') +
              '</div>';
            tip.style.display = 'block';
            var vx = Math.min(ev.clientX + 15, window.innerWidth - 220);
            var vy = Math.min(ev.clientY + 15, window.innerHeight - 120);
            tip.style.left = vx + 'px';
            tip.style.top  = vy + 'px';
          });
          svgEl.appendChild(hit);
        })(cr, day);
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
      /* Subtle vertical grid lines + clickable time labels in zoomed view */
      var firstTick = Math.ceil(rangeMin / step) * step;
      for (var tk = firstTick; tk <= rangeMax; tk += step) {
        var xt = px(tk); if (xt < 10 || xt > cw-10) continue;
        if (tk % 1440 !== 0) {
          /* Light vertical guide */
          svgEl.appendChild(mkSVG('line', {x1:xt, y1:T1Y, x2:xt, y2:T2Y+T2H, stroke:'#E8EAF0', 'stroke-width':1, opacity:0.7}));
        }
        /* Clickable time label below the chart */
        (function(absMin, xPos) {
          var dIdx = Math.floor(absMin / 1440);
          var minOfDay = absMin % 1440;
          var timeStr = hhmm(minOfDay);
          var isDay = (absMin % 1440 === 0);
          var tg = mkSVG('g');
          tg.setAttribute('style', 'cursor:pointer;');
          tg.appendChild(mkSVG('rect', {x:xPos-18, y:AXY+2, width:36, height:20, fill:'transparent', rx:3}));
          var tl = mkSVG('text', {x:xPos, y:AXY+17, 'text-anchor':'middle', fill:isDay?'#1565C0':'#546E7A', 'font-size':isDay?13:12, 'font-family':'Inter,sans-serif', 'font-weight':isDay?700:400});
          tl.textContent = isDay ? fmtDate(addD(weekStart, dIdx)) : timeStr;
          tg.appendChild(tl);
          tg.addEventListener('click', function(ev) {
            ev.stopPropagation();
            ev._tachoSeg = true;
            var tip = getTip();
            var dObj = addD(weekStart, dIdx);
            tip.innerHTML =
              '<div style="font-size:15px;color:#ECEFF1;font-weight:700;margin-bottom:4px;">' + fmtDate(dObj) + '</div>' +
              '<div style="font-size:22px;font-weight:800;color:#29B6F6;letter-spacing:1px;">' + timeStr + '</div>';
            tip.style.display = 'block';
            tip.style.left = Math.min(ev.clientX + 15, window.innerWidth - 180) + 'px';
            tip.style.top  = Math.min(ev.clientY + 15, window.innerHeight - 90) + 'px';
          });
          svgEl.appendChild(tg);
        })(tk, xt);
      }
    } else {
      /* Normal full-week view: horizontal time axis + 6-hourly grid marks + day labels */

      /* Horizontal axis line separating chart area from the time labels */
      svgEl.appendChild(mkSVG('line', {
        x1:0, y1:T2Y+T2H+2, x2:cw, y2:T2Y+T2H+2,
        stroke:'#B0BEC5', 'stroke-width':1
      }));

      /* Day separator lines */
      for (var di2=1; di2<7; di2++) {
        var xsep = px(di2*1440);
        if (xsep>=0 && xsep<=cw) svgEl.appendChild(mkSVG('line', {x1:xsep, y1:T1Y-8, x2:xsep, y2:T2Y+T2H+2, stroke:'#66BB6A', 'stroke-width':1.8, 'stroke-dasharray':'4,3', opacity:0.5}));
      }

      /* 6-hourly grid lines + tick marks + hour labels (time axis) */
      var dayPx = cw / 7;                          /* pixels per day          */
      var tickHours = dayPx >= 100 ? [6, 12, 18] : [12]; /* adapt to width   */
      for (var dih=0; dih<7; dih++) {
        tickHours.forEach(function(hr) {
          var xh = px(dih * 1440 + hr * 60);
          if (xh < 0 || xh > cw) return;
          /* Light vertical guide through both tracks */
          svgEl.appendChild(mkSVG('line', {
            x1:xh, y1:T1Y, x2:xh, y2:T2Y+T2H,
            stroke:'#E8EAF0', 'stroke-width':0.8, opacity:0.7
          }));
          /* Short tick on the axis line */
          svgEl.appendChild(mkSVG('line', {
            x1:xh, y1:T2Y+T2H+2, x2:xh, y2:T2Y+T2H+7,
            stroke:'#B0BEC5', 'stroke-width':1
          }));
          /* Hour label (e.g. "6h", "12h", "18h") */
          var hl = mkSVG('text', {x:xh, y:AXY+1, 'text-anchor':'middle',
            fill:'#90A4AE', 'font-size':9, 'font-family':'Inter,sans-serif'});
          hl.textContent = hr + 'h';
          svgEl.appendChild(hl);
        });
      }

      /* Day labels (date) centred in each day column */
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
      '<div style="font-size:12px;color:#0288D1;">\u25A0 Odpocz.: '+hhmm(totals[0]||0)+'</div>' +
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

    /* Inline zoom panel – shown in-place when user drag-selects a range */
    var inlineZoom = document.createElement('div');
    inlineZoom.style.cssText = 'display:none;border-top:2px solid #1E88E5;font-family:Inter,sans-serif;';

    function clearInlineZoom() {
      inlineZoom.style.display = 'none';
      inlineZoom.innerHTML = '';
      selRect.setAttribute('visibility','hidden');
      selLabelBg.setAttribute('visibility','hidden');
      selLabelTxt.setAttribute('visibility','hidden');
    }

    function buildInlineZoom(startMin, endMin) {
      var dur = endMin - startMin;
      var startD = addD(weekStart, Math.floor(startMin / 1440));
      var endD   = addD(weekStart, Math.floor(endMin / 1440));
      var startT = hhmm(startMin % 1440), endT = hhmm(endMin % 1440);
      var isFullDay = (dur === 1440 && startMin % 1440 === 0);

      /* Activity totals within selection */
      var selTotals = {0:0,1:0,2:0,3:0};
      weekDays.forEach(function(day, di) {
        if (!day || !day.segs) return;
        var base = di * 1440;
        day.segs.forEach(function(s) {
          var absS = base+s.start, absE = base+s.end;
          var iS = Math.max(absS, startMin), iE = Math.min(absE, endMin);
          if (iE > iS) selTotals[s.act] = (selTotals[s.act]||0) + (iE-iS);
        });
      });

      inlineZoom.innerHTML = '';

      /* Info bar */
      var infoBar = document.createElement('div');
      infoBar.style.cssText = 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:6px 12px;background:#1E88E5;';
      var titlePart = document.createElement('span');
      titlePart.style.cssText = 'font-size:14px;font-weight:700;color:#fff;white-space:nowrap;';
      titlePart.textContent = isFullDay
        ? ('Widok dnia \u2014 '+fmtDate(startD))
        : 'Powi\u0119kszony fragment';
      var rangePart = document.createElement('span');
      rangePart.style.cssText = 'font-size:13px;color:rgba(255,255,255,0.85);';
      rangePart.innerHTML = fmtDate(startD)+' <b>'+startT+'</b> \u2192 '+fmtDate(endD)+' <b>'+endT+'</b>';
      var durBadge = document.createElement('span');
      durBadge.style.cssText = 'background:rgba(255,255,255,0.25);border-radius:4px;padding:2px 10px;font-size:14px;font-weight:700;color:#fff;white-space:nowrap;';
      durBadge.textContent = hm(dur);
      var closeBtn2 = document.createElement('button');
      closeBtn2.type = 'button'; closeBtn2.textContent = '\u00D7 Zamknij';
      closeBtn2.style.cssText = 'margin-left:auto;background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.5);border-radius:4px;padding:3px 10px;font-size:13px;color:#fff;cursor:pointer;font-family:Inter,sans-serif;font-weight:600;white-space:nowrap;';
      closeBtn2.addEventListener('click', clearInlineZoom);
      infoBar.appendChild(titlePart); infoBar.appendChild(rangePart);
      infoBar.appendChild(durBadge); infoBar.appendChild(closeBtn2);
      inlineZoom.appendChild(infoBar);

      /* Zoomed SVG row */
      var zRow = document.createElement('div');
      zRow.style.cssText = 'display:flex;align-items:stretch;background:#FFF;border-bottom:1px solid #BBDEFB;';
      var zSb = document.createElement('div');
      zSb.style.cssText = 'width:'+LW+'px;flex-shrink:0;background:#F0F8FF;border-right:1px solid #BBDEFB;padding:6px 8px;display:flex;flex-direction:column;justify-content:center;gap:2px;';
      zSb.innerHTML = '<div style="font-size:12px;color:#1565C0;font-weight:700;margin-bottom:2px;">'+(isFullDay?'DZIE\u0143':'ZOOM')+'</div>'+
        '<div style="font-size:12px;color:#5A6070;">'+(isFullDay?fmtDate(startD):hm(dur))+'</div>';
      var zSvg = mkSVG('svg', {width:cw, height:RH, style:'display:block;flex-shrink:0;overflow:visible;cursor:default;'});
      fillChartSVG(zSvg, weekStart, weekDays, cw, startMin, endMin, null, dayViols);
      zRow.appendChild(zSb); zRow.appendChild(zSvg);
      inlineZoom.appendChild(zRow);

      /* Activity breakdown */
      var breakdown = document.createElement('div');
      breakdown.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;padding:8px 12px;background:#fff;border-top:1px solid #BBDEFB;';
      [3,2,1,0].forEach(function(k) {
        var val = selTotals[k]||0;
        var card = document.createElement('div');
        card.style.cssText = 'display:flex;align-items:center;gap:6px;border:1px solid #BBDEFB;border-radius:5px;padding:5px 10px;background:#F8FBFF;';
        card.innerHTML = '<div style="width:10px;height:10px;border-radius:3px;background:'+ACT_SOLID[k]+';flex-shrink:0;"></div>'+
          '<span style="font-size:13px;color:#1A2030;"><strong style="color:'+ACT_SOLID[k]+';">'+ACT_NAME[k]+'</strong>: '+hm(val)+
          ' <span style="color:#9AA0AA;font-size:12px;">('+pct(val,dur)+'%)</span></span>';
        breakdown.appendChild(card);
      });
      inlineZoom.appendChild(breakdown);

      inlineZoom.style.display = 'block';
      inlineZoom.scrollIntoView({behavior:'smooth', block:'nearest'});
    }

    /* Selection drag interaction */
    svgEl.addEventListener('mousedown', function(e) {
      if (e.button!==0) return; e.preventDefault();
      hideTip();
      if (selCtrl.clearPrev) selCtrl.clearPrev();
      selCtrl.clearPrev = function() {
        selRect.setAttribute('visibility','hidden');
        selLabelBg.setAttribute('visibility','hidden');
        selLabelTxt.setAttribute('visibility','hidden');
        inlineZoom.style.display = 'none';
        inlineZoom.innerHTML = '';
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
        buildInlineZoom(startMin, endMin);
      }
      document.addEventListener('mousemove',onMove); document.addEventListener('mouseup',onUp);
    });

    mainRow.appendChild(sb); mainRow.appendChild(svgEl); wrapper.appendChild(mainRow);
    wrapper.appendChild(inlineZoom);

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
    var numWeeks = 4;
    /* Default: show the 4 weeks starting from the Monday of the current month's
     * first week so the timeline always opens at the current month. */
    var _now = new Date();
    var startWk = monDay(new Date(_now.getFullYear(), _now.getMonth(), 1));

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
    var prevBtn  = mkBtn('\u25C4 Poprzedni');
    var nextBtn  = mkBtn('Nast\u0119pny \u25BA');
    var dateRange = document.createElement('span');
    dateRange.style.cssText = 'font-size:13px;color:#5A6070;margin-left:auto;font-family:Inter,sans-serif;';

    prevBtn.addEventListener('click',  function(){ startWk=addD(startWk,-7); renderWeeks(); });
    nextBtn.addEventListener('click',  function(){ startWk=addD(startWk, 7); renderWeeks(); });

    [prevBtn, nextBtn, dateRange].forEach(function(el){ toolbar.appendChild(el); });
    container.appendChild(toolbar);

    /* Legend */
    var legend = document.createElement('div');
    legend.style.cssText = 'display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:4px 0 6px;';
    [{fill:'#29B6F6',bd:'#0288D1',lbl:'Odpoczynek'},{fill:'#F44336',bd:'#D32F2F',lbl:'Jazda'},
     {fill:'#FF9800',bd:'#F57C00',lbl:'Praca'},{fill:'#4CAF50',bd:'#388E3C',lbl:'Dyspozycyjno\u015b\u0107'},
     {fill:'#00BCD4',bd:'#00838F',lbl:'Odpocz. \u22659h'}].forEach(function(it) {
      var d=document.createElement('div'); d.style.cssText='display:flex;align-items:center;gap:4px;';
      d.innerHTML='<div style="width:18px;height:11px;background:'+it.fill+';border:1px solid '+it.bd+'80;border-radius:2px;flex-shrink:0;"></div><span style="font-size:12px;color:#5A6070;">'+it.lbl+'</span>';
      legend.appendChild(d);
    });
    var stLg = document.createElement('div');
    stLg.style.cssText = 'margin-left:auto;font-size:12px;color:#9AA0AA;white-space:nowrap;';
    stLg.innerHTML = '&#9679; <span style="color:#43A047;">OK</span> &nbsp;&#9679; <span style="color:#FF9800;">Ostrzez.</span> &nbsp;&#9679; <span style="color:#E53935;">Narusz.</span> &nbsp;<span style="opacity:0.6;">| przeci\u0105gnij \u2192 powi\u0119kszenie (inline) | kliknij dat\u0119 \u2192 poka\u017c dzie\u0144 | kliknij na aktywno\u015b\u0107 \u2192 opis</span>';
    legend.appendChild(stLg);
    container.appendChild(legend);

    /* Chart header */
    var hdr = document.createElement('div');
    hdr.style.cssText = 'display:flex;background:#F0F4F8;border:1px solid #E0E2E8;border-radius:4px 4px 0 0;';
    hdr.innerHTML = '<div style="width:'+LW+'px;flex-shrink:0;padding:5px 8px;font-size:12px;font-weight:700;color:#9AA0AA;letter-spacing:1px;border-right:1px solid #E2E4EA;font-family:Inter,sans-serif;">TYDZIEN</div>' +
      '<div style="flex:1;padding:5px 12px;font-size:11px;font-weight:700;color:#9AA0AA;letter-spacing:1px;font-family:Inter,sans-serif;">O\u015a CZASU (7 DNI) &#x2014; przeci\u0105gnij by powi\u0119kszy\u0107 fragment (inline) | kliknij dat\u0119 by zobaczy\u0107 dzie\u0144 | kliknij aktywno\u015b\u0107 by zobaczy\u0107 szczeg\u00f3\u0142y</div>';
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

  /* Public: open day-view modal for a given date and dataset */
  NS.showDayView = function(date, daysData) {
    showDayModal(date, daysData);
  };

})(window.TachoChart = window.TachoChart || {});

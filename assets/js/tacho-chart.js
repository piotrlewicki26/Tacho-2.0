/* TachoPro 2.0 – SVG Tachograph Timeline Chart
 * Ported from parseDDD / WeekRow / TachographPanel in truck-delegate-pro.jsx
 * (commit ea1fcf7b808040c2256107ee0b6ba4cd4b3c3589)
 * Renders a per-week horizontal activity timeline with REST/AVAIL/WORK/DRIVE
 * color bands, daily-rest highlights, status dots, and expandable detail tables.
 */
'use strict';

(function (NS) {

  /* ── Constants (match JSX) ──────────────────────────────────── */
  const ACT_FILL   = ['#80DEEA','#9FA8DA','#FFCC80','#EF9A9A'];
  const ACT_SOLID  = ['#00ACC1','#5C6BC0','#EF6C00','#E53935'];
  const ACT_STROKE = ['#00838F','#3949AB','#BF360C','#C62828'];
  const ACT_TEXT   = ['#006064','#1A237E','#BF360C','#B71C1C'];
  const ACT_NAME   = ['Odpoczynek','Dyspozycyjność','Praca','Jazda'];
  const ACT_HFRAC  = [0.30, 0.52, 0.72, 1.00];

  /* Layout constants (from JSX: LW, T1Y, T1H, T2Y, T2H, AXY, RH) */
  const LW  = 74;    // left sidebar width
  const T1Y = 32;    // main track y
  const T1H = 36;    // main track height
  const T2Y = 76;    // daily-rest track y
  const T2H = 18;    // daily-rest track height
  const AXY = 102;   // axis label y
  const RH  = 120;   // total row height

  /* ── Helpers ────────────────────────────────────────────────── */
  function hhmm(m) {
    m = Math.round(m);
    return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
  }
  function hm(m) {
    const h = Math.floor(m / 60), mm = m % 60;
    return mm ? h + 'h ' + mm + 'm' : h + 'h';
  }
  function fmtDate(d) {
    if (!d) return '';
    if (typeof d === 'string') d = new Date(d);
    return String(d.getDate()).padStart(2, '0') + '.' +
           String(d.getMonth() + 1).padStart(2, '0') + '.' +
           d.getFullYear();
  }
  function addD(d, n) { const r = new Date(d); r.setDate(r.getDate() + n); return r; }
  function monDay(d) {
    const r = new Date(d);
    const dw = r.getDay();
    r.setDate(r.getDate() - (dw === 0 ? 6 : dw - 1));
    r.setHours(0, 0, 0, 0);
    return r;
  }
  function isoWeek(d) {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay() || 7));
    return Math.ceil((((t - new Date(Date.UTC(t.getUTCFullYear(), 0, 1))) / 864e5) + 1) / 7);
  }
  function mkSVG(tag, attrs) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [k, v] of Object.entries(attrs || {})) el.setAttribute(k, String(v));
    return el;
  }
  function dayStatus(segs) {
    if (!segs || !segs.length) return null;
    const drive = segs.filter(s => s.act === 3).reduce((a, s) => a + s.dur, 0);
    const rest  = segs.filter(s => s.act === 0).reduce((a, s) => a + s.dur, 0);
    if (drive === 0 && rest === 0) return null;
    let e = false, w = false;
    if (drive > 600) e = true;
    else if (drive > 540) w = true;
    let cont = 0;
    for (const s of segs) {
      if (s.act === 3) { cont += s.dur; }
      else if (s.act === 0 && s.dur >= 15) { cont = 0; }
    }
    if (cont > 270) w = true;
    if (rest < 660 && drive > 0) w = true;
    return e ? 'error' : w ? 'warn' : 'ok';
  }

  /* ── Build one week row ─────────────────────────────────────── */
  function buildWeekRow(weekStart, weekDays, cw, chartArea) {
    /* weekDays: Array(7) of {date, segs, dist} or null */
    const TOTAL_MIN = 7 * 1440;
    const px = m => (m / TOTAL_MIN) * cw;

    // Aggregate weekly stats
    let weekDrive = 0, dist = 0;
    const totals = { 0: 0, 1: 0, 2: 0, 3: 0 };
    weekDays.forEach(day => {
      if (!day) return;
      (day.segs || []).forEach(s => {
        weekDrive += (s.act === 3 ? s.dur : 0);
        totals[s.act] = (totals[s.act] || 0) + s.dur;
      });
      dist += (day.dist || 0);
    });
    const dCol = weekDrive > 3360 ? '#E53935' : weekDrive > 3360 * 0.85 ? '#FF9800' : '#43A047';
    const dayDots = weekDays.map(d => {
      const st = d ? dayStatus(d.segs) : null;
      return st === 'error' ? '#E53935' : st === 'warn' ? '#FF9800' : st === 'ok' ? '#43A047' : null;
    });

    /* wrapper */
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'border-bottom:1px solid #E2E4EA;background:#FFF;';

    /* top accent line */
    const accent = document.createElement('div');
    accent.style.cssText = 'height:3px;background:linear-gradient(90deg,#1E88E5,#42A5F5);opacity:0.5;';
    wrapper.appendChild(accent);

    /* main row */
    const mainRow = document.createElement('div');
    mainRow.style.cssText = 'display:flex;align-items:stretch;';

    /* sidebar */
    const sb = document.createElement('div');
    sb.style.cssText = `width:${LW}px;flex-shrink:0;background:#F8F9FB;border-right:1px solid #E2E4EA;padding:6px 10px;display:flex;flex-direction:column;justify-content:center;`;
    sb.innerHTML =
      `<div style="display:flex;align-items:center;gap:4px;margin-bottom:2px">` +
        `<div style="width:5px;height:5px;border-radius:50%;background:${dCol}"></div>` +
        `<span style="font-size:13px;font-weight:700;color:#1565C0">W${String(isoWeek(weekStart)).padStart(2, '0')}</span>` +
      `</div>` +
      `<div style="font-size:9px;color:#9AA0AA;line-height:1.5">${fmtDate(weekStart)}</div>` +
      `<div style="font-size:9px;color:#9AA0AA">${fmtDate(addD(weekStart, 6))}</div>` +
      `<div style="margin-top:3px;font-size:10px;font-weight:700;color:${dCol}">${hhmm(weekDrive)}</div>`;

    /* SVG */
    const svg = mkSVG('svg', { width: cw, height: RH, style: 'display:block;flex-shrink:0;overflow:visible;' });

    /* day backgrounds */
    for (let di = 0; di < 7; di++) {
      const rx = Math.max(0, px(di * 1440)), rw = Math.min(cw, px((di + 1) * 1440)) - rx;
      if (rw > 0) svg.appendChild(mkSVG('rect', { x: rx, y: 0, width: rw, height: RH, fill: di % 2 === 0 ? '#FFF' : '#F6F7FA' }));
    }
    /* status dots */
    dayDots.forEach((col, di) => {
      if (!col) return;
      const xc = px(di * 1440 + 720);
      if (xc >= 4 && xc <= cw - 4) svg.appendChild(mkSVG('circle', { cx: xc, cy: 10, r: 3, fill: col, opacity: 0.75 }));
    });
    /* track 1 – activity */
    svg.appendChild(mkSVG('rect', { x: 0, y: T1Y, width: cw, height: T1H, fill: '#E0F7FA', rx: 2, opacity: 0.3 }));
    svg.appendChild(mkSVG('rect', { x: 0, y: T1Y, width: cw, height: T1H, fill: 'none', stroke: '#B2EBF2', 'stroke-width': 0.8, rx: 2 }));

    /* activity slots */
    weekDays.forEach((day, di) => {
      if (!day || !day.segs) return;
      day.segs.forEach(s => {
        const absS = di * 1440 + s.start, absE = di * 1440 + s.end;
        const x1 = Math.max(0, px(absS)), x2 = Math.min(cw, px(absE));
        const bw = x2 - x1;
        if (bw < 0.4) return;
        const trackCY = T1Y + T1H / 2;
        const bh = Math.max(4, Math.round((T1H - 2) * ACT_HFRAC[s.act]));
        const by = trackCY - bh / 2;
        const g = mkSVG('g');
        g.appendChild(mkSVG('rect', { x: x1, y: by, width: bw, height: bh, fill: ACT_FILL[s.act], rx: 2 }));
        g.appendChild(mkSVG('rect', { x: x1, y: by, width: bw, height: bh, fill: 'none', stroke: ACT_STROKE[s.act], 'stroke-width': 0.8, rx: 2, 'pointer-events': 'none' }));
        if (bw > 50) {
          const txt = mkSVG('text', { x: x1 + bw / 2, y: trackCY + 4, 'text-anchor': 'middle', fill: ACT_TEXT[s.act], 'font-size': bw > 80 ? 10 : 8, 'font-family': 'Inter,sans-serif', 'font-weight': 600, 'pointer-events': 'none' });
          txt.textContent = hhmm(s.dur);
          g.appendChild(txt);
        }
        svg.appendChild(g);
      });
    });

    /* track 2 – daily rest */
    svg.appendChild(mkSVG('rect', { x: 0, y: T2Y, width: cw, height: T2H, fill: '#E3F2FD', rx: 2, opacity: 0.35 }));
    svg.appendChild(mkSVG('rect', { x: 0, y: T2Y, width: cw, height: T2H, fill: 'none', stroke: '#BBDEFB', 'stroke-width': 0.8, rx: 2 }));
    /* long-rest bands */
    weekDays.forEach((day, di) => {
      if (!day || !day.segs) return;
      day.segs.forEach(s => {
        if (s.act !== 0 || s.dur < 9 * 60) return;
        const absS = di * 1440 + s.start, absE = di * 1440 + s.end;
        const x1 = Math.max(0, px(absS)), x2 = Math.min(cw, px(absE));
        const bw = x2 - x1;
        if (bw < 0.4) return;
        const g = mkSVG('g');
        g.appendChild(mkSVG('rect', { x: x1, y: T2Y + 1, width: bw, height: T2H - 2, fill: '#90CAF9', rx: 2, opacity: 0.75 }));
        if (bw > 35) {
          const txt = mkSVG('text', { x: x1 + bw / 2, y: T2Y + T2H / 2 + 4, 'text-anchor': 'middle', fill: '#1565C0', 'font-size': 8, 'font-family': 'Inter,sans-serif', 'font-weight': 600, 'pointer-events': 'none' });
          txt.textContent = hhmm(s.dur);
          g.appendChild(txt);
        }
        svg.appendChild(g);
      });
    });

    /* day separators */
    for (let di = 1; di < 7; di++) {
      const x = px(di * 1440);
      if (x >= 0 && x <= cw) svg.appendChild(mkSVG('line', { x1: x, y1: T1Y - 8, x2: x, y2: T2Y + T2H + 4, stroke: '#66BB6A', 'stroke-width': 1.2, 'stroke-dasharray': '4,3', opacity: 0.5 }));
    }
    /* horizontal axis */
    svg.appendChild(mkSVG('line', { x1: 0, y1: AXY, x2: cw, y2: AXY, stroke: '#E0E2E8', 'stroke-width': 1 }));
    /* day labels */
    for (let di = 0; di < 7; di++) {
      const xm = px(di * 1440 + 720);
      if (xm >= 22 && xm <= cw - 22) {
        const d = addD(weekStart, di);
        const txt = mkSVG('text', { x: xm, y: AXY + 13, 'text-anchor': 'middle', fill: di >= 5 ? '#9AA0AA' : '#1565C0', 'font-size': 10, 'font-family': 'Inter,sans-serif', 'font-weight': di >= 5 ? 400 : 600 });
        txt.textContent = fmtDate(d);
        svg.appendChild(txt);
      }
    }

    mainRow.appendChild(sb);
    mainRow.appendChild(svg);
    wrapper.appendChild(mainRow);

    /* footer row (totals + expand) */
    const footer = document.createElement('div');
    footer.style.cssText = 'display:flex;align-items:stretch;background:#F8F9FB;border-top:1px solid #EEF0F4;';

    const ftL = document.createElement('div');
    ftL.style.cssText = `width:${LW}px;flex-shrink:0;border-right:1px solid #E2E4EA;padding:4px 8px;display:flex;align-items:center;`;
    if (dist > 0) ftL.innerHTML = `<span style="font-size:9px;color:#9AA0AA;font-weight:500">${dist} km</span>`;

    const ftR = document.createElement('div');
    ftR.style.cssText = 'flex:1;display:flex;align-items:center;flex-wrap:wrap;';
    [3, 2, 1, 0].forEach(k => {
      const val = totals[k] || 0;
      if (!val) return;
      const itm = document.createElement('div');
      itm.style.cssText = 'display:flex;align-items:center;gap:5px;padding:4px 12px;border-right:1px solid #EEF0F4;';
      itm.innerHTML = `<div style="width:8px;height:8px;border-radius:2px;background:${ACT_SOLID[k]};flex-shrink:0"></div>` +
        `<span style="font-size:9px;color:#6A7080;white-space:nowrap"><span style="font-weight:600;color:${ACT_SOLID[k]}">${ACT_NAME[k]}</span> ${hm(val)}</span>`;
      ftR.appendChild(itm);
    });

    const expandBtn = document.createElement('button');
    expandBtn.type = 'button';
    expandBtn.style.cssText = 'margin-left:auto;background:none;border:none;font-size:10px;color:#1E88E5;cursor:pointer;padding:4px 14px;font-family:Inter,sans-serif;font-weight:600;';
    expandBtn.textContent = '▸ Szczegóły';

    /* detail table */
    const dtWrap = document.createElement('div');
    dtWrap.style.cssText = 'display:none;border-top:1px solid #EEF0F4;overflow-x:auto;';
    let tbHtml = `<table style="width:100%;border-collapse:collapse;font-size:11px;font-family:Inter,sans-serif"><thead><tr style="background:#F0F4F8">`;
    ['Data', 'Start', 'Stop', 'Czas', 'Aktywność', 'Dystans'].forEach(h => {
      tbHtml += `<th style="padding:5px 10px;text-align:left;font-weight:700;color:#5A6070;font-size:10px;border-bottom:1px solid #E0E4E8;white-space:nowrap">${h}</th>`;
    });
    tbHtml += `</tr></thead><tbody>`;
    weekDays.forEach((day, di) => {
      if (!day || !day.segs) return;
      day.segs.forEach((s, si) => {
        const even = (di * 100 + si) % 2 === 0;
        tbHtml += `<tr style="background:${even ? '#FFF' : '#F8FAFC'}">`;
        tbHtml += `<td style="padding:4px 10px;color:#5A6070;border-bottom:1px solid #F0F2F5;white-space:nowrap">${si === 0 ? fmtDate(new Date(day.date)) : ''}</td>`;
        tbHtml += `<td style="padding:4px 10px;font-family:monospace;color:#1A2030;border-bottom:1px solid #F0F2F5;white-space:nowrap">${hhmm(s.start)}</td>`;
        tbHtml += `<td style="padding:4px 10px;font-family:monospace;color:#1A2030;border-bottom:1px solid #F0F2F5;white-space:nowrap">${hhmm(s.end)}</td>`;
        tbHtml += `<td style="padding:4px 10px;font-family:monospace;font-weight:600;color:#1A2030;border-bottom:1px solid #F0F2F5;white-space:nowrap">${hhmm(s.dur)}</td>`;
        tbHtml += `<td style="padding:4px 10px;border-bottom:1px solid #F0F2F5;white-space:nowrap">` +
          `<span style="display:inline-flex;align-items:center;gap:5px">` +
          `<span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${ACT_SOLID[s.act]};flex-shrink:0"></span>` +
          `<span style="color:${ACT_SOLID[s.act]};font-weight:600">${ACT_NAME[s.act]}</span></span></td>`;
        tbHtml += `<td style="padding:4px 10px;color:#5A6070;border-bottom:1px solid #F0F2F5;white-space:nowrap">${si === 0 && day.dist ? day.dist + ' km' : ''}</td>`;
        tbHtml += `</tr>`;
      });
    });
    tbHtml += `</tbody></table>`;
    dtWrap.innerHTML = tbHtml;

    expandBtn.addEventListener('click', () => {
      const shown = dtWrap.style.display !== 'none';
      dtWrap.style.display = shown ? 'none' : 'block';
      expandBtn.textContent = shown ? '▸ Szczegóły' : '▾ Ukryj';
    });
    ftR.appendChild(expandBtn);
    footer.appendChild(ftL);
    footer.appendChild(ftR);
    wrapper.appendChild(footer);
    wrapper.appendChild(dtWrap);
    chartArea.appendChild(wrapper);
  }

  /* ── Public API ─────────────────────────────────────────────── */

  /**
   * Render the tachograph timeline chart.
   *
   * @param {string} containerId  DOM id of the host element
   * @param {Array}  daysData     [{date, segs:[{act,start,end,dur}], dist}, …]
   */
  NS.render = function (containerId, daysData) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';

    if (!daysData || !daysData.length) {
      container.innerHTML = '<div class="text-muted text-center py-4">Brak danych aktywności do wyświetlenia.</div>';
      return;
    }

    /* legend + compliance key */
    const legend = document.createElement('div');
    legend.style.cssText = 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 0 10px;font-family:Inter,sans-serif;';
    [
      { fill: '#80DEEA', bd: '#00838F', lbl: 'Odpoczynek' },
      { fill: '#EF9A9A', bd: '#C62828', lbl: 'Jazda' },
      { fill: '#FFCC80', bd: '#BF360C', lbl: 'Praca' },
      { fill: '#9FA8DA', bd: '#3949AB', lbl: 'Dyspozycyjność' },
      { fill: '#90CAF9', bd: '#1E88E5', lbl: 'Odpocz. dobowy' },
    ].forEach(it => {
      const d = document.createElement('div');
      d.style.cssText = 'display:flex;align-items:center;gap:4px;';
      d.innerHTML = `<div style="width:20px;height:10px;background:${it.fill};border:1px solid ${it.bd}80;border-radius:2px;flex-shrink:0"></div>` +
        `<span style="font-size:10px;color:#5A6070">${it.lbl}</span>`;
      legend.appendChild(d);
    });
    const status = document.createElement('div');
    status.style.cssText = 'margin-left:auto;font-size:10px;color:#9AA0AA;white-space:nowrap;';
    status.innerHTML = '● <span style="color:#43A047">Zgodny</span>&nbsp;&nbsp;● <span style="color:#FF9800">Ostrzeżenie</span>&nbsp;&nbsp;● <span style="color:#E53935">Naruszenie</span>';
    legend.appendChild(status);
    container.appendChild(legend);

    /* group days into ISO-weeks */
    const weekMap = {};
    daysData.forEach(day => {
      const d = new Date(day.date);
      const ws = monDay(d);
      const key = ws.toISOString().slice(0, 10);
      if (!weekMap[key]) weekMap[key] = { start: ws, days: new Array(7).fill(null) };
      const di = Math.round((d - ws) / 86400000);
      if (di >= 0 && di < 7) weekMap[key].days[di] = day;
    });
    const sortedWeeks = Object.values(weekMap).sort((a, b) => a.start - b.start);

    /* header */
    const hdr = document.createElement('div');
    hdr.style.cssText = `display:flex;background:#F0F4F8;border:1px solid #E0E2E8;border-radius:4px 4px 0 0;`;
    hdr.innerHTML = `<div style="width:${LW}px;flex-shrink:0;padding:5px 10px;font-size:9px;font-weight:700;color:#9AA0AA;letter-spacing:1px;border-right:1px solid #E2E4EA">TYDZIEŃ</div>` +
      `<div style="flex:1;padding:5px 12px;font-size:9px;font-weight:700;color:#9AA0AA;letter-spacing:1px">OŚ CZASU 7 DNI — status ● odpoczynek ■</div>`;
    container.appendChild(hdr);

    /* chart area */
    const chartArea = document.createElement('div');
    chartArea.style.cssText = 'border:1px solid #E0E4E8;border-top:none;border-radius:0 0 4px 4px;overflow:hidden;';
    container.appendChild(chartArea);

    /* compute chart width on next tick (after layout) */
    requestAnimationFrame(() => {
      const cw = Math.max(400, container.clientWidth - LW - 4);
      sortedWeeks.forEach(w => buildWeekRow(w.start, w.days, cw, chartArea));
    });
  };

})(window.TachoChart = window.TachoChart || {});

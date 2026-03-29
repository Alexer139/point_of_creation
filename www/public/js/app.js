/**
 * public/js/app.js
 * Point of Creation — dashboard client logic
 *
 * PHP injects two globals:
 *   const INITIAL_WIDGETS  — widget objects from DB
 *   const CSRF_TOKEN       — CSRF token string
 */
"use strict";

/* ══ Constants ══════════════════════════════════════════════ */

const WIDGET_ICONS = {
  metric: "🔢",
  note: "📝",
  checklist: "✅",
  goal: "🎯",
  timer: "⏱",
  line_chart: "📈",
  bar_chart: "📊",
  table: "🗂",
  calendar: "📅",
};
const WIDGET_LABELS = {
  metric: "Числовой показатель",
  note: "Заметка",
  checklist: "Список дел",
  goal: "Прогресс / Цель",
  timer: "Таймер",
  line_chart: "Линейный график",
  bar_chart: "Столбчатая диаграмма",
  table: "Таблица",
  calendar: "Календарь",
};
const DEFAULT_SIZES = {
  metric: [1, 1],
  note: [1, 1],
  checklist: [1, 1],
  goal: [1, 1],
  timer: [1, 1],
  line_chart: [1, 1],
  bar_chart: [1, 1],
  table: [1, 1],
  calendar: [1, 1],
};
const BAR_COLORS = {
  metric: "amber",
  note: "indigo",
  checklist: "sage",
  goal: "amber",
  timer: "rose",
  line_chart: "amber",
  bar_chart: "amber",
  table: "indigo",
  calendar: "sage",
};
const CHART_PALETTE = [
  "#f59e0b",
  "#fb923c",
  "#fb7185",
  "#84a98c",
  "#818cf8",
  "#a78bfa",
];
const MONTHS_RU = [
  "Январь",
  "Февраль",
  "Март",
  "Апрель",
  "Май",
  "Июнь",
  "Июль",
  "Август",
  "Сентябрь",
  "Октябрь",
  "Ноябрь",
  "Декабрь",
];

/* ══ State ══════════════════════════════════════════════════ */

let widgets = [];
let pendingType = null;
const chartInst = {};
const autoSaveQ = {};
let dragSrcId = null;

/* ══ Boot ═══════════════════════════════════════════════════ */

document.addEventListener("DOMContentLoaded", () => {
  initTheme();
  initClock();
  INITIAL_WIDGETS.forEach((w) => {
    widgets.push(w);
    renderWidget(w);
  });
  updateWidgetCount();
  renderEmptyIfNeeded();
  bindGlobalShortcuts();
  bindOverlayClose();
});

/* ══ Theme ══════════════════════════════════════════════════ */

function initTheme() {
  applyTheme(localStorage.getItem("poc-theme") || "light", false);
}

function toggleTheme() {
  const cur = document.documentElement.getAttribute("data-theme") || "light";
  applyTheme(cur === "dark" ? "light" : "dark", true);
}

function applyTheme(theme, save) {
  document.documentElement.setAttribute("data-theme", theme);
  const btn = document.getElementById("theme-toggle");
  if (btn) btn.textContent = theme === "dark" ? "☀️" : "🌙";
  if (save) localStorage.setItem("poc-theme", theme);
  // Redraw charts for correct grid/tick colors
  widgets
    .filter((w) => w.type === "line_chart" || w.type === "bar_chart")
    .forEach((w) => {
      const body = document.getElementById(`wb-${w.id}`);
      if (body) buildChart(body, w);
    });
}

/* ══ Nav clock ══════════════════════════════════════════════ */

const DAYS_RU = [
  "Воскресенье",
  "Понедельник",
  "Вторник",
  "Среда",
  "Четверг",
  "Пятница",
  "Суббота",
];
const MONTHS_SHORT_RU = [
  "янв",
  "фев",
  "мар",
  "апр",
  "май",
  "июн",
  "июл",
  "авг",
  "сен",
  "окт",
  "ноя",
  "дек",
];

function initClock() {
  tickClock();
  setInterval(tickClock, 1000);
}
function tickClock() {
  const d = new Date();
  // Sidebar clock
  const timeEl = document.getElementById("sidebar-time");
  if (timeEl)
    timeEl.textContent = pad(d.getHours()) + ":" + pad(d.getMinutes());
  const dateEl = document.getElementById("sidebar-date");
  if (dateEl)
    dateEl.textContent =
      DAYS_RU[d.getDay()] +
      ", " +
      d.getDate() +
      " " +
      MONTHS_SHORT_RU[d.getMonth()];
}

/* ══ API ════════════════════════════════════════════════════ */

async function api(action, body = {}) {
  const res = await fetch("api.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF_TOKEN },
    body: JSON.stringify({ action, csrf: CSRF_TOKEN, ...body }),
  });
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    console.error("api() non-JSON response:", text.slice(0, 300));
    throw new Error("Сервер вернул неверный ответ (код " + res.status + ")");
  }
}

/* ══ Modal ══════════════════════════════════════════════════ */

function openModal(type, label) {
  pendingType = type;
  document.getElementById("modal-title").textContent =
    WIDGET_ICONS[type] + " " + label;
  document.getElementById("modal-body").innerHTML = buildModalHTML(type);
  document.getElementById("overlay").classList.add("overlay--open");
  setTimeout(() => document.querySelector("#modal-body .input")?.focus(), 80);
}
function closeModal() {
  document.getElementById("overlay").classList.remove("overlay--open");
}
function bindOverlayClose() {
  document.getElementById("overlay").addEventListener("click", (e) => {
    if (e.target.id === "overlay") closeModal();
  });
}

function buildModalHTML(type) {
  const [dw, dh] = DEFAULT_SIZES[type] || [2, 2];
  const sizeBlock = `<div class="field"><label class="field__label">Размер</label>
        <div class="field__grid field__grid--2">
            <div><span class="field__sublabel">Ширина</span>
                <select class="input input--select" id="fw">
                    ${[1, 2, 3, 4].map((v) => `<option value="${v}"${v === dw ? " selected" : ""}>${v} кол.</option>`).join("")}
                </select></div>
            <div><span class="field__sublabel">Высота</span>
                <select class="input input--select" id="fh">
                    ${[1, 2, 3].map((v) => `<option value="${v}"${v === dh ? " selected" : ""}>${v} ряд${v > 1 ? "а" : ""}</option>`).join("")}
                </select></div>
        </div></div>`;
  const tf = `<div class="field"><label class="field__label">Заголовок</label>
        <input type="text" class="input" id="ftitle" value="${WIDGET_LABELS[type] || ""}" placeholder="Название виджета">
        </div>`;
  switch (type) {
    case "metric":
      return (
        tf +
        `<div class="field"><label class="field__label">Данные</label>
            <div class="field__group">
                <div class="field__grid field__grid--2">
                    <div><span class="field__sublabel">Значение</span><input type="text" class="input input--sm input--center" id="fval" placeholder="0"></div>
                    <div><span class="field__sublabel">Тренд (%)</span><input type="number" class="input input--sm input--center" id="ftrend" placeholder="+12" step="0.1"></div>
                </div>
                <div class="field__grid field__grid--2" style="margin-top:.5rem">
                    <div><span class="field__sublabel">Префикс</span><input type="text" class="input input--sm input--center" id="fpre" placeholder="₽"></div>
                    <div><span class="field__sublabel">Суффикс</span><input type="text" class="input input--sm input--center" id="fsuf" placeholder="%"></div>
                </div>
            </div></div>` +
        sizeBlock
      );
    case "note":
      return (
        tf +
        `<div class="field"><label class="field__label">Начальный текст</label>
            <textarea class="input input--textarea" id="fnote" placeholder="Начните писать..."></textarea></div>` +
        sizeBlock
      );
    case "checklist":
      return (
        tf +
        `<div class="field"><label class="field__label">Первые задачи</label>
            <div id="init-tasks"></div>
            <button onclick="addInitTask()" class="checklist__add" style="margin-top:.5rem">+ Добавить задачу</button></div>` +
        sizeBlock
      );
    case "goal":
      return (
        tf +
        `<div class="field"><label class="field__label">Параметры</label>
            <div class="field__group">
                <div class="field"><span class="field__sublabel">Подпись</span>
                    <input type="text" class="input input--sm" id="fgoallabel" placeholder="Книги / км / задачи"></div>
                <div class="field__grid field__grid--2" style="margin-top:.5rem">
                    <div><span class="field__sublabel">Текущее</span><input type="number" class="input input--sm input--center" id="fcurrent" value="0" min="0"></div>
                    <div><span class="field__sublabel">Цель</span><input type="number" class="input input--sm input--center" id="ftarget" value="100" min="1"></div>
                </div>
            </div></div>` +
        sizeBlock
      );
    case "timer":
      return (
        tf +
        `<div class="field"><label class="field__label">Режим</label>
            <select class="input input--select" id="ftimermode"
                    onchange="document.getElementById('ftimersecsw').style.display=this.value==='countdown'?'block':'none'">
                <option value="countdown">Таймер обратного отсчёта</option>
                <option value="stopwatch">Секундомер</option>
            </select></div>
            <div class="field" id="ftimersecsw">
                <label class="field__label">Минут</label>
                <input type="number" class="input input--sm" id="ftimermins" value="25" min="1">
                <p class="field__sub">25 мин = Помодоро 🍅</p>
            </div>` +
        sizeBlock
      );
    case "line_chart":
    case "bar_chart":
      return (
        tf +
        `
            <div class="field"><label class="field__label">Метки (через запятую)</label><input type="text" class="input" id="flabels" placeholder="Янв, Фев, Мар"></div>
            <div class="field"><label class="field__label">Значения (через запятую)</label><input type="text" class="input" id="fvalues" placeholder="10, 25, 18"></div>
            <div class="field"><label class="field__label">Название серии</label><input type="text" class="input" id="fsname" placeholder="Данные"></div>` +
        sizeBlock
      );
    case "table":
      return (
        tf +
        `
            <div class="field"><label class="field__label">Столбцы (через запятую)</label><input type="text" class="input" id="fcols" placeholder="Задача, Статус, Дедлайн"></div>
            <div class="field"><label class="field__label">Начальных строк</label>
                <select class="input input--select" id="frowcnt">
                    ${[0, 1, 2, 3, 5].map((n) => `<option value="${n}"${n === 2 ? " selected" : ""}>${n === 0 ? "Без строк" : n}</option>`).join("")}
                </select></div>` +
        sizeBlock
      );
    case "calendar":
      return tf + sizeBlock;
    default:
      return tf + sizeBlock;
  }
}

function addInitTask() {
  const c = document.getElementById("init-tasks");
  if (!c) return;
  const d = document.createElement("div");
  d.className = "checklist__item";
  d.style.marginBottom = ".375rem";
  d.innerHTML = `<input type="text" class="input input--sm" placeholder="Задача..." style="flex:1">
        <button onclick="this.parentElement.remove()" class="widget__action widget__action--delete">✕</button>`;
  c.appendChild(d);
  d.querySelector("input").focus();
}

function extractContent(type) {
  switch (type) {
    case "metric":
      return {
        value: document.getElementById("fval")?.value.trim() || "0",
        prefix: document.getElementById("fpre")?.value.trim() || "",
        suffix: document.getElementById("fsuf")?.value.trim() || "",
        label: "",
        trend: document.getElementById("ftrend")?.value.trim() || "",
        trend_dir:
          parseFloat(document.getElementById("ftrend")?.value || 0) >= 0
            ? "up"
            : "down",
      };
    case "note":
      return { text: document.getElementById("fnote")?.value || "" };
    case "checklist":
      return {
        items: Array.from(
          document.querySelectorAll("#init-tasks input[type=text]"),
        )
          .map((el, i) => ({ id: i + 1, text: el.value.trim(), done: false }))
          .filter((t) => t.text),
      };
    case "goal":
      return {
        label:
          document.getElementById("fgoallabel")?.value.trim() || "Прогресс",
        current: parseFloat(document.getElementById("fcurrent")?.value) || 0,
        target: parseFloat(document.getElementById("ftarget")?.value) || 100,
      };
    case "timer": {
      const mode = document.getElementById("ftimermode")?.value || "countdown";
      const mins = parseInt(document.getElementById("ftimermins")?.value || 25);
      return { mode, seconds: mins * 60, _orig: mins * 60, elapsed: 0 };
    }
    case "line_chart":
    case "bar_chart": {
      const labels = (document.getElementById("flabels")?.value || "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean);
      const values = (document.getElementById("fvalues")?.value || "")
        .split(",")
        .map((s) => parseFloat(s.trim()))
        .filter((v) => !isNaN(v));
      return {
        labels,
        datasets: [
          {
            label: document.getElementById("fsname")?.value.trim() || "Данные",
            data: values,
          },
        ],
      };
    }
    case "table": {
      const cols = (document.getElementById("fcols")?.value || "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean);
      const columns = cols.length ? cols : ["Поле 1", "Поле 2"];
      const rowCount = parseInt(document.getElementById("frowcnt")?.value || 0);
      return {
        columns,
        rows: Array.from({ length: rowCount }, () => columns.map(() => "")),
      };
    }
    case "calendar": {
      const n = new Date();
      return {
        year: n.getFullYear(),
        month: n.getMonth(),
        crossed: {},
        notes: {},
      };
    }
    default:
      return {};
  }
}

/* ══ Add widget ═════════════════════════════════════════════ */

async function confirmAdd() {
  const type = pendingType;
  const title =
    document.getElementById("ftitle")?.value.trim() || WIDGET_LABELS[type];
  const pos_w = parseInt(document.getElementById("fw")?.value || 2);
  const pos_h = parseInt(document.getElementById("fh")?.value || 2);
  const content = extractContent(type);
  const btn = document.getElementById("modal-confirm");
  btn.disabled = true;
  btn.textContent = "…";
  try {
    const data = await api("save_widget", {
      type,
      title,
      content,
      position_w: pos_w,
      position_h: pos_h,
    });
    if (!data.ok) throw new Error(data.error || "Ошибка сервера");
    closeModal();
    const w = {
      id: data.id,
      type,
      title,
      content,
      position_w: pos_w,
      position_h: pos_h,
    };
    widgets.push(w);
    document.getElementById("empty-state")?.remove();
    renderWidget(w);
    updateWidgetCount();
    toast(WIDGET_ICONS[type] + " «" + title + "» добавлен");
  } catch (e) {
    console.error("confirmAdd error:", e);
    toast("Ошибка: " + e.message, "err");
  } finally {
    btn.disabled = false;
    btn.textContent = "✦ Добавить";
  }
}

/* ══ Render widget card ═════════════════════════════════════ */

function renderWidget(w) {
  const el = document.createElement("div");
  el.className = "widget";
  el.id = "w-" + w.id;
  if ((w.position_w || 1) > 1) el.classList.add("col-" + w.position_w);
  if ((w.position_h || 1) > 1) el.classList.add("row-" + w.position_h);
  el.innerHTML = `
        <div class="widget__bar widget__bar--${BAR_COLORS[w.type] || "amber"}"></div>
        <div class="widget__head" id="wh-${w.id}" title="Перетащите для перемещения">
            <span class="widget__icon">${WIDGET_ICONS[w.type] || "▪"}</span>
            <span class="widget__title" id="wt-${w.id}" contenteditable="true"
                  onblur="onTitleBlur(${w.id},this.textContent)"
                  onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur()}"
            >${esc(w.title)}</span>
            <span class="widget__save-dot" id="dot-${w.id}"></span>
            <button class="widget__action widget__action--delete" data-nodrag="1"
                    onclick="deleteWidget(${w.id})" title="Удалить">✕</button>
        </div>
        <div class="widget__body" id="wb-${w.id}"></div>
        <div class="resize-handle-s"  id="rhs-${w.id}"></div>
        <div class="resize-handle-e"  id="rhe-${w.id}"></div>
        <div class="resize-handle-se" id="rhse-${w.id}"></div>`;
  const grid = document.getElementById("muuri-grid");
  if (!grid) {
    console.error(
      "muuri-grid not found — DOM dump:",
      document.body?.innerHTML?.slice(0, 500),
    );
    return;
  }
  grid.appendChild(el);
  bindDragEvents(el, w.id);
  bindResizeHandles(el, w.id);
  renderContent(w);
}

/* ══ Drag & Drop ════════════════════════════════════════════ */

function bindDragEvents(el, wid) {
  const head = el.querySelector(".widget__head");
  if (!head) return;

  head.addEventListener("mousedown", (e) => {
    // Don't start drag on interactive elements
    if (
      e.target.closest("[data-nodrag]") ||
      e.target.closest("[contenteditable]") ||
      e.target.closest("button") ||
      e.target.closest("input") ||
      e.target.closest("select") ||
      e.target.closest("textarea")
    )
      return;
    if (e.button !== 0) return;

    let moved = false;
    let ghost = null;
    let dropTarget = null;

    const onMove = (ev) => {
      if (
        !moved &&
        Math.abs(ev.clientX - e.clientX) < 4 &&
        Math.abs(ev.clientY - e.clientY) < 4
      )
        return;
      moved = true;
      el.classList.add("widget--dragging");
      if (!ghost) {
        ghost = document.createElement("div");
        ghost.className = "widget__drag-ghost";
        ghost.style.cssText = `position:fixed;pointer-events:none;z-index:9999;width:${el.offsetWidth}px;height:48px;background:var(--amber);opacity:.18;border-radius:12px;transition:none`;
        document.body.appendChild(ghost);
      }
      ghost.style.left = ev.clientX - el.offsetWidth / 2 + "px";
      ghost.style.top = ev.clientY - 24 + "px";

      // Highlight drop zone
      const below = document.elementFromPoint(ev.clientX, ev.clientY);
      const tgt = below?.closest?.(".widget");
      document
        .querySelectorAll(".widget--drag-over")
        .forEach((x) => x.classList.remove("widget--drag-over"));
      if (tgt && tgt !== el) {
        tgt.classList.add("widget--drag-over");
        dropTarget = tgt;
      } else dropTarget = null;
    };

    const onUp = () => {
      document.removeEventListener("mousemove", onMove);
      document.removeEventListener("mouseup", onUp);
      el.classList.remove("widget--dragging");
      ghost?.remove();
      document
        .querySelectorAll(".widget--drag-over")
        .forEach((x) => x.classList.remove("widget--drag-over"));
      if (!moved || !dropTarget) return;
      const grid = document.getElementById("muuri-grid");
      const srcNext = el.nextElementSibling;
      if (srcNext === dropTarget) {
        grid.insertBefore(dropTarget, el);
      } else {
        const tgtNext = dropTarget.nextElementSibling;
        grid.insertBefore(el, dropTarget);
        if (tgtNext) grid.insertBefore(dropTarget, tgtNext);
        else grid.appendChild(dropTarget);
      }
      saveOrder();
    };

    document.addEventListener("mousemove", onMove);
    document.addEventListener("mouseup", onUp);
  });
}

async function saveOrder() {
  const order = [...document.querySelectorAll('.widget[id^="w-"]')].map(
    (el, i) => ({ id: parseInt(el.id.replace("w-", "")), sort_order: i }),
  );
  try {
    await api("save_all", {
      widgets: order.map((o) => {
        const w = widgets.find((x) => x.id === o.id);
        return w ? { ...w, sort_order: o.sort_order } : o;
      }),
    });
  } catch (_) {}
}

/* ══ Resize handles ─────────────────────────────────────── */

function bindResizeHandles(el, wid) {
  // SE = both axes
  const hSE = el.querySelector(".resize-handle-se");
  // S  = height only
  const hS = el.querySelector(".resize-handle-s");
  // E  = width only
  const hE = el.querySelector(".resize-handle-e");

  const makeResizer = (resizeW, resizeH) => (e) => {
    e.preventDefault();
    e.stopPropagation();
    const startX = e.clientX,
      startY = e.clientY;
    const startH = el.offsetHeight;
    const grid = document.getElementById("muuri-grid");
    // Snap width: get current col count from class
    const curCols = el.classList.contains("col-4")
      ? 4
      : el.classList.contains("col-3")
        ? 3
        : el.classList.contains("col-2")
          ? 2
          : 1;
    let lastCols = curCols;

    const onMove = (ev) => {
      if (resizeH) {
        const newH = Math.max(100, startH + ev.clientY - startY);
        el.style.minHeight = newH + "px";
        el.style.height = newH + "px";
      }
      if (resizeW && grid) {
        const gap = 14;
        const colW = (grid.offsetWidth - 3 * gap) / 4;
        const dx = ev.clientX - startX;
        // Each column threshold = colW + gap
        const colsDelta = Math.round(dx / (colW + gap));
        const newCols = Math.max(1, Math.min(4, curCols + colsDelta));
        if (newCols !== lastCols) {
          lastCols = newCols;
          el.classList.remove("col-2", "col-3", "col-4");
          if (newCols > 1) el.classList.add("col-" + newCols);
          const w = widgets.find((x) => x.id === wid);
          if (w) w.position_w = newCols;
        }
      }
    };
    const onUp = () => {
      document.removeEventListener("mousemove", onMove);
      document.removeEventListener("mouseup", onUp);
      const w = widgets.find((x) => x.id === wid);
      if (w) {
        if (resizeW || resizeH) scheduleAutoSave(wid);
        if (w.type === "line_chart" || w.type === "bar_chart")
          chartInst[wid]?.resize();
        if (w.type === "calendar") rerenderContent(wid);
      }
    };
    document.addEventListener("mousemove", onMove);
    document.addEventListener("mouseup", onUp);
  };

  if (hSE) hSE.addEventListener("mousedown", makeResizer(true, true));
  if (hS) hS.addEventListener("mousedown", makeResizer(false, true));
  if (hE) hE.addEventListener("mousedown", makeResizer(true, false));
}

/* ══ Render content ═════════════════════════════════════════ */

function renderContent(w) {
  const body = document.getElementById("wb-" + w.id);
  if (!body) return;
  if (chartInst[w.id]) {
    chartInst[w.id].destroy();
    delete chartInst[w.id];
  }
  const c = w.content || {};
  switch (w.type) {
    case "metric":
      body.innerHTML = buildMetric(w.id, c);
      break;
    case "note":
      body.innerHTML = buildNote(w.id, c);
      break;
    case "checklist":
      body.innerHTML = buildChecklist(w.id, c);
      break;
    case "goal":
      body.innerHTML = buildGoal(w.id, c);
      break;
    case "timer":
      body.innerHTML = buildTimer(w.id, c);
      break;
    case "line_chart":
    case "bar_chart":
      buildChart(body, w);
      break;
    case "table":
      body.innerHTML = buildTable(w.id, c);
      break;
    case "calendar":
      body.innerHTML = buildCalendar(w.id, c);
      break;
    default:
      body.innerHTML = `<p style="color:var(--text3);font-size:.8rem">Тип: ${esc(w.type)}</p>`;
  }
}
function rerenderContent(wid) {
  const w = widgets.find((x) => x.id === wid);
  if (w) renderContent(w);
}

/* ══ Metric ═════════════════════════════════════════════════ */

function buildMetric(wid, c) {
  const t = parseFloat(c.trend || 0),
    has = c.trend !== "" && c.trend !== undefined;
  const cls = t >= 0 ? "metric__trend--up" : "metric__trend--dn",
    arr = t >= 0 ? "↑" : "↓";
  return `<div class="metric" onclick="openMetricEdit(${wid})" title="Нажмите для изменения">
        <div class="metric__label">${esc(c.label || "Показатель")}</div>
        <div class="metric__value">${esc(c.prefix || "")}${esc(c.value || "—")}${esc(c.suffix || "")}</div>
        ${has ? `<span class="metric__trend ${cls}">${arr} ${Math.abs(t)}% <span style="font-weight:400;opacity:.7">vs пред.</span></span>` : ""}
        <div class="metric__hint">нажмите для изменения</div></div>`;
}
function openMetricEdit(wid) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  const c = w.content || {},
    body = document.getElementById("wb-" + wid);
  body.innerHTML = `<div class="metric-edit">
        <div class="metric-edit__label">Редактировать показатель</div>
        <div class="metric-edit__row">
            <input class="input input--sm" id="me-val-${wid}" placeholder="Значение" value="${escA(c.value || "")}">
            <input class="input input--sm input--narrow input--center" id="me-pre-${wid}" placeholder="₽" value="${escA(c.prefix || "")}">
            <input class="input input--sm input--narrow input--center" id="me-suf-${wid}" placeholder="%" value="${escA(c.suffix || "")}">
        </div>
        <div class="metric-edit__row">
            <input class="input input--sm" id="me-lbl-${wid}" placeholder="Подпись" value="${escA(c.label || "")}">
            <input class="input input--sm input--narrow" id="me-trn-${wid}" type="number" placeholder="Тренд %" value="${escA(c.trend || "")}">
        </div>
        <div class="metric-edit__actions">
            <button class="btn btn--warm" onclick="applyMetricEdit(${wid})">✦ Применить</button>
            <button class="btn btn--ghost" onclick="rerenderContent(${wid})">Отмена</button>
        </div></div>`;
  document.getElementById("me-val-" + wid)?.focus();
}
function applyMetricEdit(wid) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  const t = parseFloat(document.getElementById("me-trn-" + wid)?.value || 0);
  w.content = {
    value: document.getElementById("me-val-" + wid)?.value.trim() || "0",
    prefix: document.getElementById("me-pre-" + wid)?.value.trim() || "",
    suffix: document.getElementById("me-suf-" + wid)?.value.trim() || "",
    label: document.getElementById("me-lbl-" + wid)?.value.trim() || "",
    trend: document.getElementById("me-trn-" + wid)?.value.trim() || "",
    trend_dir: t >= 0 ? "up" : "down",
  };
  rerenderContent(wid);
  scheduleAutoSave(wid);
}

/* ══ Note ═══════════════════════════════════════════════════ */

function buildNote(wid, c) {
  return `<textarea class="note-textarea" placeholder="Начните писать... изменения сохраняются автоматически."
        oninput="onNoteInput(${wid},this.value)">${esc(c.text || "")}</textarea>`;
}
function onNoteInput(wid, text) {
  const w = widgets.find((x) => x.id === wid);
  if (w) w.content = { ...(w.content || {}), text };
  scheduleAutoSave(wid);
}

/* ══ Checklist ══════════════════════════════════════════════ */

function buildChecklist(wid, c) {
  const items = c.items || [],
    done = items.filter((i) => i.done).length;
  const rows = items
    .map(
      (item) => `
        <div class="checklist__item" id="cli-${wid}-${item.id}">
            <div class="checklist__check ${item.done ? "checklist__check--done" : ""}" onclick="toggleCheckItem(${wid},${item.id})"></div>
            <input class="checklist__text ${item.done ? "checklist__text--done" : ""}" value="${escA(item.text)}"
                   oninput="onCheckItemInput(${wid},${item.id},this.value)"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();focusNextCheckItem(${wid},${item.id})}">
            <button class="checklist__delete" onclick="deleteCheckItem(${wid},${item.id})">✕</button>
        </div>`,
    )
    .join("");
  return `<div class="checklist"><div class="checklist__list" id="cl-${wid}">
        ${rows}<button class="checklist__add" onclick="addCheckItem(${wid})">+ Новая задача</button>
        </div>${items.length ? `<div class="checklist__footer">Выполнено: ${done} / ${items.length}</div>` : ""}</div>`;
}
function toggleCheckItem(wid, itemId) {
  const w = widgets.find((x) => x.id === wid),
    item = w?.content?.items?.find((i) => i.id === itemId);
  if (!item) return;
  item.done = !item.done;
  rerenderContent(wid);
  scheduleAutoSave(wid);
}
function onCheckItemInput(wid, itemId, text) {
  const w = widgets.find((x) => x.id === wid),
    item = w?.content?.items?.find((i) => i.id === itemId);
  if (item) {
    item.text = text;
    scheduleAutoSave(wid);
  }
}
function addCheckItem(wid) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  if (!w.content.items) w.content.items = [];
  const maxId = w.content.items.reduce((m, i) => Math.max(m, i.id), 0);
  w.content.items.push({ id: maxId + 1, text: "", done: false });
  rerenderContent(wid);
  setTimeout(() => {
    const inputs = document.querySelectorAll(
      "#cl-" + wid + " .checklist__text",
    );
    inputs[inputs.length - 1]?.focus();
  }, 50);
  scheduleAutoSave(wid);
}
function deleteCheckItem(wid, itemId) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  w.content.items = (w.content.items || []).filter((i) => i.id !== itemId);
  rerenderContent(wid);
  scheduleAutoSave(wid);
}
function focusNextCheckItem(wid, itemId) {
  const inputs = [
    ...document.querySelectorAll("#cl-" + wid + " .checklist__text"),
  ];
  const idx = inputs.findIndex((el) =>
    el.closest("#cli-" + wid + "-" + itemId),
  );
  if (idx >= 0 && idx < inputs.length - 1) inputs[idx + 1].focus();
  else addCheckItem(wid);
}

/* ══ Goal ═══════════════════════════════════════════════════ */

function buildGoal(wid, c) {
  const cur = parseFloat(c.current || 0),
    tar = parseFloat(c.target || 100);
  const pct = Math.min(100, Math.round((cur / tar) * 100));
  return `<div class="goal">
        <div class="goal__header"><span class="goal__name">${esc(c.label || "Прогресс")}</span><span class="goal__pct" id="gpct-${wid}">${pct}%</span></div>
        <div class="goal__track"><div class="goal__fill" id="gbar-${wid}" style="width:${pct}%"></div></div>
        <div class="goal__nums"><span>${cur.toLocaleString("ru")}</span><span>${tar.toLocaleString("ru")}</span></div>
        <div class="goal__inputs">
            <div><label class="goal__input-label">Текущее</label>
                <input type="number" class="input input--sm input--center" value="${cur}" min="0" onchange="onGoalChange(${wid},'current',this.value)"></div>
            <div><label class="goal__input-label">Цель</label>
                <input type="number" class="input input--sm input--center" value="${tar}" min="1" onchange="onGoalChange(${wid},'target',this.value)"></div>
        </div></div>`;
}
function onGoalChange(wid, field, val) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  w.content[field] = parseFloat(val) || 0;
  const pct = Math.min(
    100,
    Math.round((w.content.current / (w.content.target || 1)) * 100),
  );
  const bar = document.getElementById("gbar-" + wid),
    pctEl = document.getElementById("gpct-" + wid);
  if (bar) bar.style.width = pct + "%";
  if (pctEl) pctEl.textContent = pct + "%";
  scheduleAutoSave(wid);
}

/* ══ Timer (countdown + stopwatch only) ════════════════════ */

function buildTimer(wid, c) {
  const mode = (c.mode === "clock" ? "countdown" : c.mode) || "countdown";
  const display = fmtSecs(
    mode === "stopwatch" ? c.elapsed || 0 : c.seconds || 0,
  );
  const labels = { countdown: "Таймер", stopwatch: "Секундомер" };
  const btns = ["countdown", "stopwatch"]
    .map(
      (m) => `
        <button class="timer__mode-btn ${m === mode ? "timer__mode-btn--active" : ""}"
                onclick="changeTimerMode(${wid},'${m}')">${m === "countdown" ? "Таймер" : "Секундомер"}</button>`,
    )
    .join("");
  return `<div class="timer">
        <div class="timer__mode-label">${labels[mode]}</div>
        <div class="timer__display" id="td-${wid}">${display}</div>
        <div class="timer__controls">
            <button class="timer__btn timer__btn--play" id="tp-${wid}" onclick="toggleTimer(${wid})">▶ Старт</button>
            <button class="timer__btn timer__btn--reset" onclick="resetTimer(${wid})">↺</button>
        </div>
        <div class="timer__modes">${btns}</div></div>`;
}
function toggleTimer(wid) {
  const w = widgets.find((x) => x.id === wid),
    key = "_rt_" + wid;
  if (window[key]) {
    clearInterval(window[key]);
    window[key] = null;
    const btn = document.getElementById("tp-" + wid);
    if (btn) btn.textContent = "▶ Продолжить";
    document
      .getElementById("td-" + wid)
      ?.classList.remove("timer__display--running");
  } else {
    document
      .getElementById("td-" + wid)
      ?.classList.add("timer__display--running");
    const btn = document.getElementById("tp-" + wid);
    if (btn) btn.textContent = "⏸ Пауза";
    const mode = w.content.mode;
    window[key] = setInterval(() => {
      if (mode === "countdown") {
        w.content.seconds = Math.max(0, (w.content.seconds || 0) - 1);
        if (w.content.seconds <= 0) {
          clearInterval(window[key]);
          window[key] = null;
          document
            .getElementById("td-" + wid)
            ?.classList.replace(
              "timer__display--running",
              "timer__display--warning",
            );
          toast("⏱ Таймер завершён!");
        }
      } else {
        w.content.elapsed = (w.content.elapsed || 0) + 1;
      }
      const el = document.getElementById("td-" + wid);
      if (el)
        el.textContent = fmtSecs(
          mode === "countdown" ? w.content.seconds : w.content.elapsed || 0,
        );
    }, 1000);
  }
}
function resetTimer(wid) {
  const w = widgets.find((x) => x.id === wid),
    key = "_rt_" + wid;
  if (window[key]) {
    clearInterval(window[key]);
    window[key] = null;
  }
  if (w.content.mode === "countdown")
    w.content.seconds = w.content._orig || w.content.seconds;
  else w.content.elapsed = 0;
  rerenderContent(wid);
}
function changeTimerMode(wid, mode) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  const key = "_rt_" + wid;
  if (window[key]) {
    clearInterval(window[key]);
    window[key] = null;
  }
  w.content.mode = mode;
  rerenderContent(wid);
  scheduleAutoSave(wid);
}
function fmtSecs(s) {
  s = Math.max(0, Math.floor(s));
  return pad(Math.floor(s / 60)) + ":" + pad(s % 60);
}

/* ══ Charts ═════════════════════════════════════════════════ */

function buildChart(body, w) {
  const c = w.content || {},
    isBar = w.type === "bar_chart";
  const dark = document.documentElement.getAttribute("data-theme") === "dark";
  const gridColor = dark ? "rgba(74,56,40,.6)" : "#f0e8df",
    tickColor = dark ? "#7a6250" : "#b09585";
  if (chartInst[w.id]) {
    chartInst[w.id].destroy();
    delete chartInst[w.id];
  }
  body.innerHTML = `<div class="chart-wrap"><canvas id="cc-${w.id}"></canvas></div>`;
  const ctx = document.getElementById("cc-" + w.id)?.getContext("2d");
  if (!ctx) return;
  const datasets = (c.datasets || []).map((ds, i) => ({
    ...ds,
    borderColor: CHART_PALETTE[i % CHART_PALETTE.length],
    backgroundColor: isBar
      ? CHART_PALETTE[i % CHART_PALETTE.length] + "cc"
      : CHART_PALETTE[i % CHART_PALETTE.length] + "26",
    borderRadius: isBar ? 6 : 0,
    fill: !isBar,
    tension: 0.4,
  }));
  chartInst[w.id] = new Chart(ctx, {
    type: isBar ? "bar" : "line",
    data: { labels: c.labels || [], datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: "index", intersect: false },
      plugins: {
        legend: {
          position: "bottom",
          labels: {
            font: { size: 11, family: "Outfit" },
            padding: 12,
            boxWidth: 10,
            color: tickColor,
          },
        },
        tooltip: {
          backgroundColor: "#3d2c1e",
          titleColor: "#fef3c7",
          bodyColor: "#e8ddd4",
          padding: 10,
          cornerRadius: 10,
        },
      },
      scales: {
        x: {
          grid: { color: gridColor },
          ticks: { color: tickColor, font: { size: 9 } },
        },
        y: {
          grid: { color: gridColor },
          ticks: { color: tickColor, font: { size: 9 } },
          beginAtZero: false,
        },
      },
    },
  });
}

/* ══ Table ══════════════════════════════════════════════════ */

function buildTable(wid, c) {
  const cols = c.columns || ["Поле 1", "Поле 2"],
    rows = c.rows || [];
  const ths = cols.map((col) => `<th>${esc(col)}</th>`).join("");
  const trs = rows
    .map(
      (row, ri) =>
        `<tr>${cols.map((_, ci) => `<td contenteditable="true" onblur="onCellBlur(${wid},${ri},${ci},this.textContent)">${esc(row[ci] || "")}</td>`).join("")}<td style="width:30px"><button onclick="deleteTableRow(${wid},${ri})" class="widget__action widget__action--delete">✕</button></td></tr>`,
    )
    .join("");
  return `<div class="data-table-wrap"><table class="data-table"><thead><tr>${ths}<th style="width:30px"></th></tr></thead><tbody>${trs}</tbody></table><button class="data-table__add" onclick="addTableRow(${wid})">+ Добавить строку</button></div>`;
}
function onCellBlur(wid, ri, ci, val) {
  const w = widgets.find((x) => x.id === wid);
  if (!w || !w.content.rows[ri]) return;
  w.content.rows[ri][ci] = val.trim();
  scheduleAutoSave(wid);
}
function addTableRow(wid) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  w.content.rows.push(
    new Array((w.content.columns || []).length || 2).fill(""),
  );
  rerenderContent(wid);
  scheduleAutoSave(wid);
}
function deleteTableRow(wid, ri) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  w.content.rows.splice(ri, 1);
  rerenderContent(wid);
  scheduleAutoSave(wid);
}

/* ══ Calendar ═══════════════════════════════════════════════ */

function buildCalendar(wid, c) {
  const now = new Date(),
    year = c.year ?? now.getFullYear(),
    month = c.month ?? now.getMonth();
  const crossed = c.crossed || {},
    notes = c.notes || {};
  const firstDay = new Date(year, month, 1),
    lastDay = new Date(year, month + 1, 0);
  let startDow = firstDay.getDay() - 1;
  if (startDow < 0) startDow = 6;
  const todayStr = isoDate(now);
  const DOW = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];
  let cells = "";
  for (let i = 0; i < startDow; i++)
    cells += `<div class="cal__day cal__day--other"></div>`;
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const key = isoDate(new Date(year, month, d));
    let cls = "cal__day";
    if (key === todayStr) cls += " cal__day--today";
    if (crossed[key]) cls += " cal__day--crossed";
    if (notes[key]?.trim()) cls += " cal__day--has-note";
    cells += `<div class="${cls}" data-key="${key}" onclick="openCalPopover(${wid},'${key}',this)" oncontextmenu="toggleCalCross(event,${wid},'${key}',this)">${d}</div>`;
  }
  return `<div class="cal">
        <div class="cal__nav">
            <button class="cal__nav-btn" onclick="calNav(${wid},-1)">‹</button>
            <span class="cal__month">${MONTHS_RU[month]} ${year}</span>
            <button class="cal__nav-btn" onclick="calNav(${wid},+1)">›</button>
        </div>
        <div class="cal__grid">${DOW.map((d) => `<div class="cal__dow">${d}</div>`).join("")}${cells}</div>
    </div>`;
}

function calNav(wid, delta) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  closeCalPopover();
  let month = (w.content.month ?? new Date().getMonth()) + delta,
    year = w.content.year ?? new Date().getFullYear();
  if (month > 11) {
    month = 0;
    year++;
  }
  if (month < 0) {
    month = 11;
    year--;
  }
  w.content.month = month;
  w.content.year = year;
  rerenderContent(wid);
  scheduleAutoSave(wid);
}

function openCalPopover(wid, key, dayEl) {
  closeCalPopover();
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  const note = (w.content.notes || {})[key] || "",
    crossed = !!(w.content.crossed || {})[key];
  const pop = document.createElement("div");
  pop.className = "cal__popover";
  pop.id = "cal-pop";
  pop.innerHTML = `<div class="cal__popover-date">${fmtCalDate(key)}</div>
        <textarea class="cal__popover-note" placeholder="Заметка на этот день...">${esc(note)}</textarea>
        <div class="cal__popover-actions">
            <button class="cal__popover-btn cal__popover-btn--cross" onclick="toggleCalCrossFromPop(${wid},'${key}')">${crossed ? "↺ Снять" : "✕ Зачеркнуть"}</button>
            <button class="cal__popover-btn cal__popover-btn--done" onclick="saveCalPopover(${wid},'${key}')">Сохранить</button>
        </div>`;
  document.body.appendChild(pop);
  // Position using viewport coords (fixed positioning)
  const rect = dayEl.getBoundingClientRect();
  const popW = 220,
    popH = 160;
  let left = rect.left;
  let top = rect.bottom + 6;
  // Keep within viewport
  if (left + popW > window.innerWidth - 8) left = window.innerWidth - popW - 8;
  if (top + popH > window.innerHeight - 8) top = rect.top - popH - 6;
  pop.style.left = Math.max(8, left) + "px";
  pop.style.top = Math.max(8, top) + "px";
  pop.querySelector("textarea").focus();
  setTimeout(
    () =>
      document.addEventListener("click", onOutsideCalPop, { capture: true }),
    100,
  );
}
function onOutsideCalPop(e) {
  const pop = document.getElementById("cal-pop");
  if (
    pop &&
    !pop.contains(e.target) &&
    !e.target.classList.contains("cal__day")
  ) {
    closeCalPopover();
    document.removeEventListener("click", onOutsideCalPop, true);
  }
}
function closeCalPopover() {
  const old = document.getElementById("cal-pop");
  if (old) old.remove();
}
function saveCalPopover(wid, key) {
  const w = widgets.find((x) => x.id === wid),
    pop = document.getElementById("cal-pop");
  if (!w || !pop) return;
  const note = pop.querySelector("textarea")?.value.trim() || "";
  if (!w.content.notes) w.content.notes = {};
  if (note) w.content.notes[key] = note;
  else delete w.content.notes[key];
  closeCalPopover();
  rerenderContent(wid);
  scheduleAutoSave(wid);
}
function toggleCalCross(e, wid, key) {
  e.preventDefault();
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  if (!w.content.crossed) w.content.crossed = {};
  if (w.content.crossed[key]) delete w.content.crossed[key];
  else w.content.crossed[key] = true;
  closeCalPopover();
  rerenderContent(wid);
  scheduleAutoSave(wid);
}
function toggleCalCrossFromPop(wid, key) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  if (!w.content.crossed) w.content.crossed = {};
  if (w.content.crossed[key]) delete w.content.crossed[key];
  else w.content.crossed[key] = true;
  closeCalPopover();
  rerenderContent(wid);
  scheduleAutoSave(wid);
}
function isoDate(d) {
  return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
}
function fmtCalDate(key) {
  const [y, m, d] = key.split("-");
  return parseInt(d) + " " + MONTHS_RU[parseInt(m) - 1] + " " + y;
}

/* ══ Auto-save ══════════════════════════════════════════════ */

function scheduleAutoSave(wid) {
  if (autoSaveQ[wid]) clearTimeout(autoSaveQ[wid]);
  autoSaveQ[wid] = setTimeout(() => doAutoSave(wid), 900);
}
async function doAutoSave(wid) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  try {
    await api("update_content", {
      id: wid,
      content: w.content,
      title: w.title,
    });
    showSaveDot(wid);
    showAutosaveStatus();
  } catch (_) {}
}
function showSaveDot(wid) {
  const dot = document.getElementById("dot-" + wid);
  if (!dot) return;
  dot.classList.add("visible");
  setTimeout(() => dot.classList.remove("visible"), 1500);
}
function showAutosaveStatus() {
  const el = document.getElementById("autosave-status");
  if (!el) return;
  el.classList.add("visible");
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.remove("visible"), 2000);
}

/* ══ Title edit ═════════════════════════════════════════════ */

function onTitleBlur(wid, text) {
  const w = widgets.find((x) => x.id === wid);
  if (!w) return;
  w.title = text.trim() || w.title;
  scheduleAutoSave(wid);
}

/* ══ Delete ═════════════════════════════════════════════════ */

async function deleteWidget(wid) {
  if (!confirm("Удалить виджет?")) return;
  try {
    await api("delete_widget", { id: wid });
  } catch (_) {}
  if (chartInst[wid]) {
    chartInst[wid].destroy();
    delete chartInst[wid];
  }
  if (window["_rt_" + wid]) {
    clearInterval(window["_rt_" + wid]);
    window["_rt_" + wid] = null;
  }
  const el = document.getElementById("w-" + wid);
  if (el) {
    el.style.transition = "all .25s";
    el.style.opacity = "0";
    el.style.transform = "scale(.93)";
  }
  setTimeout(() => {
    el?.remove();
    widgets = widgets.filter((x) => x.id !== wid);
    updateWidgetCount();
    renderEmptyIfNeeded();
  }, 260);
  toast("Виджет удалён");
}

async function clearAllWidgets() {
  if (!widgets.length) return;
  if (!confirm("Удалить все виджеты? Это действие нельзя отменить.")) return;
  // Stop all timers, destroy charts
  widgets.forEach((w) => {
    if (chartInst[w.id]) {
      chartInst[w.id].destroy();
      delete chartInst[w.id];
    }
    if (window["_rt_" + w.id]) {
      clearInterval(window["_rt_" + w.id]);
      window["_rt_" + w.id] = null;
    }
  });
  // Remove from DOM with animation
  document.querySelectorAll('.widget[id^="w-"]').forEach((el) => {
    el.style.transition = "all .2s";
    el.style.opacity = "0";
    el.style.transform = "scale(.93)";
  });
  try {
    await api("save_all", { widgets: [] });
  } catch (_) {}
  setTimeout(() => {
    document.querySelectorAll('.widget[id^="w-"]').forEach((el) => el.remove());
    widgets = [];
    updateWidgetCount();
    renderEmptyIfNeeded();
  }, 220);
  toast("🗑 Все виджеты удалены");
}

/* ══ Utility ════════════════════════════════════════════════ */

function updateWidgetCount() {
  const n = widgets.length,
    sfx = n === 1 ? "" : n >= 2 && n <= 4 ? "а" : "ов";
  const el = document.getElementById("widget-count");
  if (el) el.textContent = n + " виджет" + sfx;
}
function renderEmptyIfNeeded() {
  const grid = document.getElementById("muuri-grid");
  if (!grid) return;
  if (widgets.length === 0 && !document.getElementById("empty-state")) {
    const div = document.createElement("div");
    div.className = "canvas__empty";
    div.id = "empty-state";
    div.innerHTML =
      '<div class="canvas__empty-icon">✦</div><h3>Добавьте первый виджет</h3><p>Выберите тип в левой панели и начните строить своё пространство</p>';
    grid.prepend(div);
  }
}
function esc(s) {
  if (!s) return "";
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
function escA(s) {
  if (!s) return "";
  return String(s).replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}
function pad(n) {
  return String(n).padStart(2, "0");
}
function toast(msg, type = "ok") {
  const container = document.getElementById("toasts");
  if (!container) {
    console.warn("toast:", msg);
    return;
  }
  const el = document.createElement("div");
  el.className = "toast toast--" + type;
  el.textContent = msg;
  container.appendChild(el);
  setTimeout(() => el.remove(), 3200);
}
function bindGlobalShortcuts() {
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      closeModal();
      closeCalPopover();
    }
  });
}

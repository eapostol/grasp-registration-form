/*
  Parent Manual / Handbook Agreement
  - Continuous scroll document viewer (rendered from page images)
  - Overlay required Initials + signature fields
  - Saves draft (encrypted) to localStorage + IndexedDB
  - Preview / Print / Submit modal
*/

(function () {
  "use strict";

  // -----------------------------
  // Storage (copied/adapted from enrollment-app.js)
  // -----------------------------
  const SECRET_KEY_STRING = "grasp-parent-manual-demo-secret-32-chars!!";
  const STORAGE_KEY_ENCRYPTED = "graspParentManualEncryptedData";
  const STORAGE_KEY_LAST_SAVED_AT = "grasp_parent_manual_last_saved_at";
  const STORAGE_KEY_SESSION_ID = "graspParentManualSessionId";
  const STORAGE_DB_NAME = "graspParentManualDB";
  const STORAGE_DB_STORE = "sessions";

  let cryptoKeyCache = null;

  async function getCryptoKey() {
    if (cryptoKeyCache) return cryptoKeyCache;

    const enc = new TextEncoder();
    const rawKey = enc.encode(SECRET_KEY_STRING.padEnd(32, "0").slice(0, 32));

    cryptoKeyCache = await window.crypto.subtle.importKey(
      "raw",
      rawKey,
      { name: "AES-GCM" },
      false,
      ["encrypt", "decrypt"],
    );

    return cryptoKeyCache;
  }

  async function encryptData(plainText) {
    const key = await getCryptoKey();
    const iv = window.crypto.getRandomValues(new Uint8Array(12));

    const encoder = new TextEncoder();
    const encoded = encoder.encode(plainText);

    const ciphertext = await window.crypto.subtle.encrypt(
      { name: "AES-GCM", iv },
      key,
      encoded,
    );

    return {
      iv: btoa(String.fromCharCode(...iv)),
      ciphertext: btoa(String.fromCharCode(...new Uint8Array(ciphertext))),
    };
  }

  async function decryptData(payload) {
    const key = await getCryptoKey();

    const iv = Uint8Array.from(atob(payload.iv), (c) => c.charCodeAt(0));
    const data = Uint8Array.from(atob(payload.ciphertext), (c) => c.charCodeAt(0));

    const plain = await window.crypto.subtle.decrypt(
      { name: "AES-GCM", iv },
      key,
      data,
    );

    const decoder = new TextDecoder();
    return decoder.decode(plain);
  }

  function openDB() {
    return new Promise((resolve, reject) => {
      const req = window.indexedDB.open(STORAGE_DB_NAME, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(STORAGE_DB_STORE)) {
          db.createObjectStore(STORAGE_DB_STORE, { keyPath: "sessionId" });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }

  async function idbPutSession(sessionId, encryptedPayload) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORAGE_DB_STORE, "readwrite");
      const store = tx.objectStore(STORAGE_DB_STORE);
      store.put({
        sessionId,
        encryptedPayload,
        updatedAt: new Date().toISOString(),
      });
      tx.oncomplete = () => resolve(true);
      tx.onerror = () => reject(tx.error);
    });
  }

  async function idbGetSession(sessionId) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORAGE_DB_STORE, "readonly");
      const store = tx.objectStore(STORAGE_DB_STORE);
      const req = store.get(sessionId);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  }

  function ensureSessionId() {
    let sid = window.localStorage.getItem(STORAGE_KEY_SESSION_ID);
    if (!sid) {
      // simple UUID-ish
      sid = "pm_" + Math.random().toString(16).slice(2) + Date.now().toString(16);
      window.localStorage.setItem(STORAGE_KEY_SESSION_ID, sid);
    }
    return sid;
  }

  async function saveDraftToStorage(sessionId, stateObj) {
    const plain = JSON.stringify({
      sessionId,
      updatedAt: new Date().toISOString(),
      data: stateObj,
    });

    const encrypted = await encryptData(plain);
    window.localStorage.setItem(STORAGE_KEY_ENCRYPTED, JSON.stringify(encrypted));
    await idbPutSession(sessionId, encrypted);
  }

  async function loadDraftFromStorage(sessionId) {
    // Prefer IndexedDB (most robust), fallback to localStorage
    let best = null;

    try {
      const idb = await idbGetSession(sessionId);
      if (idb && idb.encryptedPayload) best = { source: "idb", payload: idb.encryptedPayload, updatedAt: idb.updatedAt };
    } catch (e) {
      console.warn("[GRASP][parent-manual] idb read failed, fallback to localStorage", e);
    }

    try {
      const raw = window.localStorage.getItem(STORAGE_KEY_ENCRYPTED);
      if (raw) {
        const parsed = JSON.parse(raw);
        // localStorage payload doesn't include updatedAt; use presence only if no idb
        if (!best) best = { source: "ls", payload: parsed, updatedAt: null };
      }
    } catch (e) {
      // ignore
    }

    if (!best) return null;

    try {
      const plain = await decryptData(best.payload);
      const decoded = JSON.parse(plain);
      if (!decoded || typeof decoded !== "object") return null;
      return decoded.data || {};
    } catch (e) {
      console.warn("[GRASP][parent-manual] draft decrypt/parse failed", e);
      return null;
    }
  }

  // -----------------------------
  // Helpers
  // -----------------------------
  function qs(id) { return document.getElementById(id); }

  function escapeHtml(input) {
    if (input === null || typeof input === "undefined") return "";
    return String(input)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function todayISO() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
  }

  
  // -----------------------------
  // Zoom (for page images)
  // -----------------------------
  const ZOOM_STORAGE_KEY = "graspParentManualZoom";
  const ZOOM_MIN = 0.75;
  const ZOOM_MAX = 1.75;
  const ZOOM_STEP = 0.1;

  function readZoom() {
    const raw = localStorage.getItem(ZOOM_STORAGE_KEY);
    const n = raw ? Number(raw) : NaN;
    if (!Number.isFinite(n)) return 1;
    return clampZoom(n);
  }

  function saveZoom(z) {
    try { localStorage.setItem(ZOOM_STORAGE_KEY, String(z)); } catch (_) {}
  }

  function clampZoom(z) {
    const clamped = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, z));
    return Math.round(clamped * 100) / 100;
  }

  function applyZoom(z) {
    const pages = els.pages();
    if (!pages) return;
    const valueEl = els.zoomValue();
    const final = clampZoom(z);

    // Prefer CSS zoom (Chrome/Edge). This keeps layout + overlays aligned.
    pages.style.zoom = String(final);

    if (valueEl) valueEl.textContent = `${Math.round(final * 100)}%`;
    window.__pmZoom = final;
    saveZoom(final);
  }

  function bindZoomControls() {
    const out = els.zoomOut();
    const inn = els.zoomIn();
    const reset = els.zoomReset();

    const inc = () => applyZoom((window.__pmZoom || 1) + ZOOM_STEP);
    const dec = () => applyZoom((window.__pmZoom || 1) - ZOOM_STEP);
    const res = () => applyZoom(1);

    if (out) out.addEventListener("click", dec);
    if (inn) inn.addEventListener("click", inc);
    if (reset) reset.addEventListener("click", res);

    // Keyboard shortcuts: Ctrl/Cmd + +/- and Ctrl/Cmd + 0
    window.addEventListener("keydown", (e) => {
      const isMod = e.ctrlKey || e.metaKey;
      if (!isMod) return;
      if (e.key === "+" || e.key === "=") { e.preventDefault(); inc(); }
      if (e.key === "-" || e.key === "_") { e.preventDefault(); dec(); }
      if (e.key === "0") { e.preventDefault(); res(); }
    });
  }

function flattenFields(cfg) {
    const out = [];
    (cfg.steps || []).forEach((s) => {
      (s.groups || []).forEach((g) => {
        (g.fields || []).forEach((f) => out.push(f));
      });
    });
    return out;
  }

  function getFieldsByPage(allFields) {
    const map = {};
    allFields.forEach((f) => {
      const p = f?.placement?.page;
      if (!p) return;
      if (!map[p]) map[p] = [];
      map[p].push(f);
    });
    return map;
  }

  function isEmpty(val) {
    return val === null || typeof val === "undefined" || String(val).trim() === "";
  }

function detectDebugMode() {
  try {
    const params = new URLSearchParams(window.location.search || "");
    const raw = params.get("debug") ?? params.get("DEBUG") ?? params.get("Debug");
    if (!raw) return false;
    const v = String(raw).toLowerCase();
    return v === "true" || v === "1" || v === "yes";
  } catch (e) {
    return false;
  }
}

function pickFirstNonEmpty(obj, keys) {
  const o = obj || {};
  for (const k of keys || []) {
    const v = o[k];
    if (!isEmpty(v)) return v;
  }
  return "";
}

async function prefillFromPackageDraftIfDebug() {
  if (!debugEnabled) return;
  try {
    const api = window.GRASP_PACKAGE_DRAFT;
    const loadFn = api?.load || api?.getDraft;
    if (!loadFn) return;

    const pkg = await loadFn.call(api);
    const registrant = (pkg && pkg.registrant) ? pkg.registrant : {};

    // Try common keys used by Enrollment/Waitlist
    let name = pickFirstNonEmpty(registrant, [
      "parent_full_name_signature",   // Enrollment
      "parent_signature",             // Waitlist
      "parent1_name",
      "parent2_name",
    ]);

    // Fallback: construct from parent1 first/last
    if (isEmpty(name)) {
      const fn = pickFirstNonEmpty(registrant, ["parent1_first_name"]);
      const ln = pickFirstNonEmpty(registrant, ["parent1_last_name"]);
      const combined = `${String(fn || "").trim()} ${String(ln || "").trim()}`.trim();
      if (!isEmpty(combined)) name = combined;
    }

    const sigDate = pickFirstNonEmpty(registrant, ["signature_date"]);

    // Only fill missing Parent Manual fields (do not overwrite existing draft values)
    if (!isEmpty(name)) {
      if (isEmpty(window.formState.pm_parent_printed_name)) window.formState.pm_parent_printed_name = name;
      if (isEmpty(window.formState.pm_parent_signature)) window.formState.pm_parent_signature = name;
      if (isEmpty(window.formState.pm_ack_printed_name)) window.formState.pm_ack_printed_name = name;
    }

    if (!isEmpty(sigDate) && isEmpty(window.formState.pm_parent_date)) {
      window.formState.pm_parent_date = sigDate;
    }
  } catch (e) {
    console.warn("[GRASP][parent-manual] debug prefill failed", e);
  }
}


  // -----------------------------
  // App State
  // -----------------------------
  let config = null;
  let sessionId = null;

  // exposed like other forms
  window.formState = {}; // fieldName -> value

  let debugEnabled = false;

  let hasReachedBottom = false;
  let isModalOpen = false;
  let isDirty = false;
  let autoSaveTimer = null;
  let lastSavedAt = null;
  let flashSavedPill = false;
  let submitInFlight = false;


  const els = {
    pages: () => qs("pm-pages"),
    scroll: () => qs("pm-scroll"),
    status: () => qs("pm-status"),
    btnSave: () => qs("pm-btn-save"),
    btnPreview: () => qs("pm-btn-preview"),
    zoomOut: () => qs("pm-zoom-out"),
    zoomIn: () => qs("pm-zoom-in"),
    zoomReset: () => qs("pm-zoom-reset"),
    zoomValue: () => qs("pm-zoom-value"),
    modal: () => qs("grasp-preview-modal"),
    modalBody: () => qs("grasp-preview-body"),
    modalClose: () => qs("grasp-preview-close"),
    modalCancel: () => qs("grasp-preview-cancel"),
    modalPrint: () => qs("grasp-preview-print"),
    modalSubmit: () => qs("grasp-preview-submit"),
  };


  // -----------------------------
  // Submit UX
  // -----------------------------
  function setSubmitInProgress(isOn, message) {
    submitInFlight = !!isOn;

    const modal = els.modal();
    const btnSubmit = els.modalSubmit();
    const btnPrint = els.modalPrint();
    const btnCancel = els.modalCancel();
    const btnClose = els.modalClose();

    const prog = qs("pm-submit-progress");
    const progText = prog ? prog.querySelector(".pm-submit-progress-text") : null;

    if (progText) {
      progText.textContent = message || "Submitting… Please wait.";
    }
    if (prog) {
      prog.style.display = isOn ? "flex" : "none";
    }

    if (modal) {
      if (isOn) modal.setAttribute("aria-busy", "true");
      else modal.removeAttribute("aria-busy");
    }

    if (isOn) {
      if (btnSubmit) {
        if (!btnSubmit.dataset.originalText) btnSubmit.dataset.originalText = btnSubmit.textContent || "Submit";
        btnSubmit.disabled = true;
        btnSubmit.textContent = "Submitting...";
      }
      if (btnPrint) btnPrint.disabled = true;
      if (btnCancel) btnCancel.disabled = true;
      if (btnClose) btnClose.disabled = true;
    } else {
      if (btnPrint) btnPrint.disabled = false;
      if (btnCancel) btnCancel.disabled = false;
      if (btnClose) btnClose.disabled = false;
      if (btnSubmit) {
        btnSubmit.textContent = btnSubmit.dataset.originalText || "Submit";
      }
      // Restore submit enabled/disabled based on validation
      updateStatus();
    }
  }

  // -----------------------------
  // Rendering
  // -----------------------------
  function fieldStyleFromPlacement(field) {
    const rect = field?.placement?.rect;
    if (!rect) return "";

    // Optional fine-tuning in pixels (useful if the PDF is reflowed or boxes shift slightly).
    // Example: placement.nudgePx = { dx: 2, dy: 8, dw: -4, dh: 0 }
    const n = field?.placement?.nudgePx || {};
    const dx = Number(n.dx || 0);
    const dy = Number(n.dy || 0);
    const dw = Number(n.dw || 0);
    const dh = Number(n.dh || 0);

    const left = (rect.x * 100).toFixed(4) + "%";
    const top = (rect.y * 100).toFixed(4) + "%";
    const width = (rect.w * 100).toFixed(4) + "%";
    const height = (rect.h * 100).toFixed(4) + "%";

    // calc(% + px) is valid CSS and keeps the original placement as the base.
    const leftExpr = `calc(${left} + ${dx}px)`;
    const topExpr = `calc(${top} + ${dy}px)`;
    const widthExpr = `calc(${width} + ${dw}px)`;
    const heightExpr = `calc(${height} + ${dh}px)`;

    return `left:${leftExpr};top:${topExpr};width:${widthExpr};height:${heightExpr};`;
  }

  function createInputForField(field) {
    const input = document.createElement("input");
    const t = (field.inputType || field.type || "text").toLowerCase();

    input.type = t === "date" ? "date" : "text";
    input.className = "pm-field" + (field.kind === "initials" ? " pm-field-initials" : "");
    input.id = field.name;
    input.name = field.name;
    input.setAttribute("aria-label", field.label || field.name);
    input.setAttribute("data-field-name", field.name);
    input.setAttribute("style", fieldStyleFromPlacement(field));

    if (field.kind === "initials") {
      input.maxLength = 4;
      input.placeholder = "IN";
      input.autocomplete = "off";
    }

    if (field.disabled || field.officeOnly) {
      input.disabled = true;
      input.className += " pm-field-disabled";
      input.placeholder = "Office use only";
    }

    // initial value
    const current = window.formState[field.name];
    if (!isEmpty(current)) input.value = current;

    input.addEventListener("input", (e) => {
      const v = e.target.value;
      window.formState[field.name] = v;

      // Prefill targets
      if (field.name === "pm_ack_printed_name") {
        const tgt = "pm_parent_printed_name";
        if (isEmpty(window.formState[tgt])) {
          window.formState[tgt] = v;
          const el = document.getElementById(tgt);
          if (el) el.value = v;
        }
      }

      isDirty = true;
      updateStatus();
      scheduleAutoSave();
    });

    input.addEventListener("blur", () => {
      // normalize initials upper
      if (field.kind === "initials") {
        const v = (input.value || "").toUpperCase().replace(/[^A-Z]/g, "").slice(0, 4);
        input.value = v;
        window.formState[field.name] = v;
        isDirty = true;
        scheduleAutoSave();
      }
    });

    return input;
  }

  function normalizeInitialsValue(raw) {
    return String(raw || "").toUpperCase().replace(/[^A-Z]/g, "").slice(0, 4);
  }

  function updateInitialsDisplay(displayEl, value) {
    const v = normalizeInitialsValue(value);
    if (v) {
      displayEl.textContent = v;
      displayEl.classList.remove("pm-initials-empty");
    } else {
      displayEl.textContent = "IN";
      displayEl.classList.add("pm-initials-empty");
    }
  }

  function createInitialsClickToEdit(field) {
    // Creates two layered elements:
    // 1) A read-only bold overlay showing the initials inside the PDF box
    // 2) A hidden input that appears only when the box is clicked
    const frag = document.createDocumentFragment();

    const input = createInputForField(field);
    input.classList.add("pm-initials-input", "pm-initials-hidden");
    input.tabIndex = -1;

    const display = document.createElement("div");
    display.className = "pm-initials-display";
    display.setAttribute("style", fieldStyleFromPlacement(field));
    display.setAttribute("data-initials-for", field.name);
    display.setAttribute("role", "button");
    display.setAttribute("tabindex", "0");
    display.setAttribute("aria-label", `${field.label || "Initials"} (click to edit)`);

    updateInitialsDisplay(display, window.formState[field.name]);

    const startEdit = () => {
      if (input.disabled) return;
      display.classList.add("hidden");
      input.classList.remove("pm-initials-hidden");
      input.tabIndex = 0;
      try { input.focus({ preventScroll: true }); } catch (e) { try { input.focus(); } catch (e2) {} }
      try { input.select(); } catch (e) { /* ignore */ }
    };

    const finishEdit = () => {
      const v = normalizeInitialsValue(input.value);
      input.value = v;
      window.formState[field.name] = v;
      updateInitialsDisplay(display, v);
      input.classList.add("pm-initials-hidden");
      input.tabIndex = -1;
      display.classList.remove("hidden");
      isDirty = true;
      updateStatus();
      scheduleAutoSave();
    };

    display.addEventListener("click", startEdit);
    display.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        startEdit();
      }
    });

    // If the user jumps to this field from the missing-fields list, focus() will land here.
    input.addEventListener("focus", () => {
      if (input.classList.contains("pm-initials-hidden")) startEdit();
    });

    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        input.blur();
      }
      if (e.key === "Escape") {
        e.preventDefault();
        // revert to last committed value
        input.value = window.formState[field.name] || "";
        input.blur();
      }
    });

    // Runs after createInputForField's blur normalization listener (registered earlier).
    input.addEventListener("blur", finishEdit);

    // Stack order: input first, display second (display sits on top until editing).
    frag.appendChild(input);
    frag.appendChild(display);
    return frag;
  }

  function renderPages() {
    const container = els.pages();
    if (!container) return;
    container.innerHTML = "";

    const count = config?.manual?.pageCount || 0;
    const pathTemplate = config?.manual?.pageImagePath || "./assets/pages/page-{page:02d}.jpg";
    const allFields = flattenFields(config);
    const byPage = getFieldsByPage(allFields);

    for (let p = 1; p <= count; p++) {
      const pageDiv = document.createElement("div");
      pageDiv.className = "pm-page";
      pageDiv.setAttribute("data-page", String(p));

      const img = document.createElement("img");
      img.alt = `Parent Manual page ${p}`;
      img.loading = "lazy";
      img.src = pathTemplate.replace("{page:02d}", String(p).padStart(2, "0")).replace("{page}", String(p));

      const overlay = document.createElement("div");
      overlay.className = "pm-overlay";

      (byPage[p] || []).forEach((field) => {
        overlay.appendChild(field.kind === "initials" ? createInitialsClickToEdit(field) : createInputForField(field));
      });

      pageDiv.appendChild(img);
      pageDiv.appendChild(overlay);
      container.appendChild(pageDiv);
    }
  }

  function buildMissingList({ forSubmit }) {
    const allFields = flattenFields(config);
    const missing = [];

    allFields.forEach((f) => {
      const required = !!f.required;
      const requiredForSubmit = !!f.requiredForSubmit;

      if (f.disabled || f.officeOnly) return;

      if (required || (forSubmit && requiredForSubmit)) {
        const v = window.formState[f.name];
        if (isEmpty(v)) {
          let label = f.label || f.name;
          // Avoid duplicate "Initials" labels in the modal by adding page context.
          if (String(label).trim().toLowerCase() === "initials" && f?.placement?.page) {
            label = `Initials (page ${f.placement.page})`;
          }
          missing.push({ name: f.name, label });
        }
      }
    });

    if (forSubmit && !hasReachedBottom) {
      missing.push({ name: "__scroll__", label: config?.validationMessages?.scrollToBottomRequired || "Scroll to the bottom of the manual." });
    }

    return missing;
  }

  function flashSaved() {
    flashSavedPill = true;
    updateStatus();
    window.setTimeout(() => {
      flashSavedPill = false;
      updateStatus();
    }, 1200);
  }

  function formatTime(d) {
    try {
      return d.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
    } catch (e) {
      return "";
    }
  }

  function renderStatusPills() {
    const missingForSubmit = buildMissingList({ forSubmit: true });
    const missingRequiredFields = missingForSubmit.filter((m) => m.name !== "__scroll__");
    const scrollOk = hasReachedBottom;

    const pills = [];
    pills.push(`<span class="pm-pill ${scrollOk ? "ok" : "warn"}">${scrollOk ? "Scrolled to bottom ✓" : "Scroll to bottom"}</span>`);

    if (debugEnabled) {
      pills.push(`<span class="pm-pill warn">Debug mode</span>`);
    }

    if (missingRequiredFields.length === 0) {
      pills.push(`<span class="pm-pill ok">Fields complete ✓</span>`);
    } else {
      pills.push(`<span class="pm-pill warn">${missingRequiredFields.length} missing</span>`);
    }

    if (isDirty) {
      pills.push(`<span class="pm-pill warn">Unsaved changes</span>`);
    } else {
      const t = lastSavedAt ? formatTime(lastSavedAt) : "";
      const label = t ? `Saved ${t}` : "Saved";
      const flash = flashSavedPill ? " pm-pill-flash" : "";
      pills.push(`<span class="pm-pill ok${flash}">${label}</span>`);
    }

    return pills.join(" ");
  }

  function updateStatus() {
    const el = els.status();
    if (!el) return;
    el.innerHTML = renderStatusPills();

    // Enable/disable preview + submit inside modal
    if (els.btnPreview()) {
      // allow preview anytime, but encourage scroll
      els.btnPreview().disabled = false;
    }
    const ms = els.modalSubmit();
    if (ms) {
      const missing = buildMissingList({ forSubmit: true });
      ms.disabled = submitInFlight || missing.length > 0;
    }
  }

  // -----------------------------
  // Scroll tracking (progress + restore)
  // -----------------------------
  function bindScrollTracking() {
    const sc = els.scroll();
    if (!sc) return;

    sc.addEventListener("scroll", () => {
      const nearBottom = sc.scrollTop + sc.clientHeight >= sc.scrollHeight - 30;
      if (nearBottom && !hasReachedBottom) {
        hasReachedBottom = true;
        window.formState.__pm_scrolledToBottom = true;
        isDirty = true;
        updateStatus();
        scheduleAutoSave();
      }

      // persist scroll position (debounced)
      window.formState.__pm_scrollTop = sc.scrollTop;
      isDirty = true;
      scheduleAutoSave();
    });
  }

  function restoreScrollPosition() {
    const sc = els.scroll();
    if (!sc) return;
    const top = Number(window.formState.__pm_scrollTop || 0);
    if (top > 0) {
      // allow layout
      setTimeout(() => {
        sc.scrollTop = top;
      }, 200);
    }
  }

  // -----------------------------
  // Preview / Print / Submit
  // -----------------------------

  function openModal() {
    const modal = els.modal();
    if (!modal) return;

    // Reset submit UX state (e.g., if the user previously encountered an error)
    setSubmitInProgress(false);

    // The global .grasp-modal styles (from enrollment.css) show the modal by default
    // unless the "hidden" class is present. Keep behavior consistent with Enrollment/Waitlist.
    modal.classList.remove("hidden");
    modal.setAttribute("aria-hidden", "false");
    isModalOpen = true;

    // Prevent background scroll while modal is open
    document.body.style.overflow = "hidden";
  }

  function closeModal() {
    const modal = els.modal();
    if (!modal) return;
    if (submitInFlight) return;
    setSubmitInProgress(false);


    modal.classList.add("hidden");
    modal.setAttribute("aria-hidden", "true");
    isModalOpen = false;

    // Re-enable background scroll
    document.body.style.overflow = "";
  }

  function flashField(el) {
    try {
      el.classList.add("pm-field-flash");
      window.setTimeout(() => el.classList.remove("pm-field-flash"), 1400);
    } catch (e) {
      // ignore
    }
  }

  function jumpToMissingItem(name) {
    if (!name) return;

    // Special case: scroll requirement
    if (name === "__scroll__") {
      const sc = els.scroll();
      if (sc) sc.scrollTo({ top: sc.scrollHeight, behavior: "smooth" });
      return;
    }

    const fieldEl = document.getElementById(name);
    if (!fieldEl) return;

    // Scroll the manual viewer to the field
    fieldEl.scrollIntoView({ behavior: "smooth", block: "center" });
    flashField(fieldEl);

    // Focus after a tiny delay (prevents interrupting scroll in some browsers)
    window.setTimeout(() => {
      try { fieldEl.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
    }, 250);
  }

  function buildPreviewHtml() {
    const missingForSubmit = buildMissingList({ forSubmit: true });
    const missingForPrint = buildMissingList({ forSubmit: false }); // required only
    const showMissing = missingForSubmit.length > 0;

    const missingHtml = (() => {
      if (!showMissing) {
        return `<div class="pm-missing pm-missing-ok">
          <strong>All required items are complete.</strong>
        </div>`;
      }

      const MAX_VISIBLE = 5;
      const first = missingForSubmit.slice(0, MAX_VISIBLE);
      const rest = missingForSubmit.slice(MAX_VISIBLE);

      const li = (m) => {
        const label = escapeHtml(m.label);
        const name = escapeHtml(m.name);
        return `<li><button type="button" class="pm-missing-link" data-jump="${name}">${label}</button></li>`;
      };

      const firstHtml = first.map(li).join("");
      const restHtml = rest.map(li).join("");
      const more = rest.length
        ? `<details class="pm-missing-more">
            <summary>Show ${rest.length} more…</summary>
            <ul>${restHtml}</ul>
          </details>`
        : "";

      return `<div class="pm-missing" role="alert">
          <strong>Missing items before you can submit:</strong>
          <ul class="pm-missing-list">${firstHtml}</ul>
          ${more}
        </div>`;
    })();

    const count = config?.manual?.pageCount || 0;
    const pathTemplate = config?.manual?.pageImagePath || "./assets/pages/page-{page:02d}.jpg";

    // Build page preview with values rendered as positioned text
    const allFields = flattenFields(config);
    const byPage = getFieldsByPage(allFields);

    const pagesHtml = [];
    for (let p = 1; p <= count; p++) {
      const imgSrc = pathTemplate.replace("{page:02d}", String(p).padStart(2, "0")).replace("{page}", String(p));
      const overlays = (byPage[p] || []).map((f) => {
        const rect = f?.placement?.rect;
        if (!rect) return "";
        const v = window.formState[f.name] || "";
        const style = `left:${(rect.x*100).toFixed(4)}%;top:${(rect.y*100).toFixed(4)}%;width:${(rect.w*100).toFixed(4)}%;height:${(rect.h*100).toFixed(4)}%;`;
        const display = escapeHtml(v);
        return `<div class="pm-print-value" style="${style}">${display}</div>`;
      }).join("");

      pagesHtml.push(`
        <div class="pm-preview-page">
          <img src="${imgSrc}" alt="Preview page ${p}" />
          <div class="pm-preview-overlay">${overlays}</div>
        </div>
      `);
    }

    const helpNote = `
      <p style="margin:10px 0 0 0; font-size: 13px; color:#333;">
        Print will include your typed values. If the signature is blank, the printed line will be blank so it can be hand-signed.
      </p>
    `;

    return `
      ${missingHtml}
      ${helpNote}
      <div class="pm-preview-scroll">${pagesHtml.join("")}</div>
    `;
  }

  function openPreview() {
    if (!els.modalBody()) return;
    els.modalBody().innerHTML = buildPreviewHtml();
    updateStatus();
    openModal();
  }

  function buildPrintHtmlDocument() {
    const count = config?.manual?.pageCount || 0;
    const pathTemplate = config?.manual?.pageImagePath || "./assets/pages/page-{page:02d}.jpg";

    const allFields = flattenFields(config);
    const byPage = getFieldsByPage(allFields);

    const pages = [];
    for (let p = 1; p <= count; p++) {
      const imgSrc = pathTemplate.replace("{page:02d}", String(p).padStart(2, "0")).replace("{page}", String(p));
      const overlays = (byPage[p] || []).map((f) => {
        const rect = f?.placement?.rect;
        if (!rect) return "";
        const v = window.formState[f.name] || "";
        const style = `left:${(rect.x*100).toFixed(4)}%;top:${(rect.y*100).toFixed(4)}%;width:${(rect.w*100).toFixed(4)}%;height:${(rect.h*100).toFixed(4)}%;`;
        return `<div class="pm-print-value" style="${style}">${escapeHtml(v)}</div>`;
      }).join("");

      pages.push(`
        <div class="pm-print-page">
          <img src="${imgSrc}" alt="Page ${p}" />
          <div class="pm-print-overlay">${overlays}</div>
        </div>
      `);
    }

    return `<div class="pm-print-doc">${pages.join("")}</div>`;
  }

  function doPrint() {
    const iframe = document.createElement("iframe");
    iframe.style.position = "fixed";
    iframe.style.right = "0";
    iframe.style.bottom = "0";
    iframe.style.width = "0";
    iframe.style.height = "0";
    iframe.style.border = "0";
    iframe.setAttribute("aria-hidden", "true");
    document.body.appendChild(iframe);

    const printCssHref = new URL("../css/print.css", window.location.href).toString();
    const pmPrintCssHref = new URL("../css/parent-manual-print.css", window.location.href).toString();

    const html = buildPrintHtmlDocument();

    iframe.onload = () => {
      try {
        iframe.contentWindow && iframe.contentWindow.focus();
        iframe.contentWindow && iframe.contentWindow.print();
      } finally {
        setTimeout(() => iframe.remove(), 1000);
      }
    };

    iframe.srcdoc = `<!doctype html>
      <html lang="en">
        <head>
          <meta charset="utf-8" />
          <meta name="viewport" content="width=device-width, initial-scale=1.0" />
          <title>Parent Manual Print</title>
          <link rel="stylesheet" href="${printCssHref}" media="print" />
          <link rel="stylesheet" href="${pmPrintCssHref}" media="print" />
          <style>
            body { margin: 0; padding: 0; background: #fff; }
          </style>
        </head>
        <body>
          ${html}
        </body>
      </html>`;
  }

  function buildEmailHtmlSummary() {
    const allFields = flattenFields(config);
    const initials = allFields.filter((f) => f.kind === "initials");
    const ack = allFields.filter((f) => f.kind !== "initials" && !f.officeOnly);

    const cellStyle = "border:1px solid #ccc;padding:6px 8px;";
    const labelStyle = cellStyle + "width:40%;font-weight:700;background:#f3f3f3;";
    const thStyle = cellStyle + "text-align:left;font-weight:700;background:#e9e9e9;";

    const ackRows = ack.map((f) => {
      const v = window.formState[f.name] || "";
      const label = f.label || f.name;
      return `<tr>
        <td style="${labelStyle}">${escapeHtml(label)}</td>
        <td style="${cellStyle}">${escapeHtml(v)}</td>
      </tr>`;
    }).join("");

    const initialsRows = initials.map((f) => {
      const v = window.formState[f.name] || "";
      // The config includes sectionTitle for each initials box so the email explains what was signed off.
      const section = f.sectionTitle || f.section || f.title || f.label || f.name;
      return `<tr>
        <td style="${cellStyle}font-weight:700;background:#f3f3f3;">${escapeHtml(section)}</td>
        <td style="${cellStyle}">${escapeHtml(v)}</td>
      </tr>`;
    }).join("");

    const submitted = new Date().toISOString();

    return `
      <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;">
        <h3 style="margin:0 0 10px;">GRASP Parent Manual Agreement</h3>
        <p style="margin:0 0 12px;">Submitted: ${escapeHtml(submitted)}</p>

        <h4 style="margin:16px 0 6px;">Initials</h4>
        <table style="border-collapse:collapse;width:100%;">
          <thead>
            <tr>
              <th style="${thStyle}width:70%;">Signed off Section</th>
              <th style="${thStyle}">Initials</th>
            </tr>
          </thead>
          <tbody>${initialsRows}</tbody>
        </table>

        <h4 style="margin:16px 0 6px;">Acknowledgement</h4>
        <table style="border-collapse:collapse;width:100%;">${ackRows}</table>
      </div>
    `;
  }

  async function submit() {
    if (submitInFlight) return;

    const missing = buildMissingList({ forSubmit: true });
    if (missing.length > 0) {
      alert("Please complete all required items before submitting.");
      updateStatus();
      return;
    }

    setSubmitInProgress(
      true,
      "Submitting… generating PDF and sending email (this can take a few seconds).",
    );

    try {
      const payload = {
        sessionId: sessionId || ensureSessionId(),
        submittedAt: new Date().toISOString(),
        data: window.formState,
        emailHtml: buildEmailHtmlSummary(),
      };

      const res = await fetch("../api/submit_parent_manual.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const json = await res.json().catch(() => ({}));
      if (!res.ok || json?.ok === false) {
        setSubmitInProgress(false);
        const msg = json?.message || "Submission failed. Please try again later.";
        alert(msg);
        return;
      }

      // Done processing — restore UI and show the success message.
      setSubmitInProgress(false);
      alert("Thank you! Your Parent Manual agreement has been submitted.");

      try {
        if (window.GRASP_PACKAGE_DRAFT?.setStatus) {
          await window.GRASP_PACKAGE_DRAFT.setStatus({
            agreementSubmittedAt: new Date().toISOString(),
          });
        }
      } catch (e) {
        console.warn("[GRASP][parent-manual] package status update failed", e);
      }

      closeModal();
    } catch (err) {
      console.error("[GRASP][parent-manual] submit error:", err);
      setSubmitInProgress(false);
      alert("Submission failed. Please check your connection and try again.");
    }
  }

  // -----------------------------
  // Save / Autosave
  // -----------------------------
  function scheduleAutoSave() {
    if (autoSaveTimer) window.clearTimeout(autoSaveTimer);
    autoSaveTimer = window.setTimeout(async () => {
      await doSave({ manual: false });
    }, 700);
  }

  async function doSave(opts) {
    const options = opts || {};
    const manual = options.manual === true;

    try {
      sessionId = sessionId || ensureSessionId();
      await saveDraftToStorage(sessionId, window.formState);
      isDirty = false;

      lastSavedAt = new Date();
      try {
        window.localStorage.setItem(STORAGE_KEY_LAST_SAVED_AT, lastSavedAt.toISOString());
      } catch (e) {
        // ignore
      }

      if (manual) {
        flashSaved();
      }

      updateStatus();
    } catch (e) {
      console.warn("[GRASP][parent-manual] save failed", e);
    }
  }

  // -----------------------------
  // Init
  // -----------------------------
  async function loadConfig() {
    const url = new URL("../config/parent-manual-fields.json", window.location.href).toString();
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error("Failed to load config");
    return await res.json();
  }

  async function init() {
    try {
      sessionId = ensureSessionId();

      // Restore last saved timestamp (for UI pill)
      try {
        const iso = window.localStorage.getItem(STORAGE_KEY_LAST_SAVED_AT);
        if (iso) lastSavedAt = new Date(iso);
      } catch (e) {
        // ignore
      }

      config = await loadConfig();
      window.config = config;

      // load draft
      const draft = await loadDraftFromStorage(sessionId);
      if (draft && typeof draft === "object") {
        window.formState = draft;
      }

      debugEnabled = detectDebugMode();
      await prefillFromPackageDraftIfDebug();

      // reach bottom state
      hasReachedBottom = !!window.formState.__pm_scrolledToBottom;

      // defaults (date)
      const allFields = flattenFields(config);
      allFields.forEach((f) => {
        if (f.defaultToday && isEmpty(window.formState[f.name])) {
          window.formState[f.name] = todayISO();
        }
        if (f.prefillFrom && isEmpty(window.formState[f.name])) {
          const v = window.formState[f.prefillFrom];
          if (!isEmpty(v)) window.formState[f.name] = v;
        }
      });

      renderPages();
    applyZoom(readZoom());
    bindZoomControls();
      bindScrollTracking();
      restoreScrollPosition();

      // buttons
      if (els.btnSave()) els.btnSave().addEventListener("click", () => doSave({ manual: true }));
      if (els.btnPreview()) els.btnPreview().addEventListener("click", openPreview);

      // modal controls
      if (els.modalClose()) els.modalClose().addEventListener("click", closeModal);
      if (els.modalCancel()) els.modalCancel().addEventListener("click", closeModal);
      if (els.modalPrint()) els.modalPrint().addEventListener("click", doPrint);
      if (els.modalSubmit()) els.modalSubmit().addEventListener("click", submit);

      // Clickable "missing items" (event delegation inside modal body)
      if (els.modalBody()) {
        els.modalBody().addEventListener("click", (e) => {
          const btn = e.target && e.target.closest ? e.target.closest(".pm-missing-link") : null;
          if (!btn) return;
          e.preventDefault();
          const name = btn.getAttribute("data-jump");
          closeModal();
          // Allow the modal to close before scrolling the underlying viewer
          window.setTimeout(() => jumpToMissingItem(name), 150);
        });
      }

      // click backdrop to close
      const modal = els.modal();
      if (modal) {
        modal.addEventListener("click", (e) => {
          const t = e.target;
          if (t && t.hasAttribute("data-modal-close")) closeModal();
        });
      }

      // initialize package draft system (shared)
      try {
        if (window.GRASP_PACKAGE_DRAFT?.init) {
          await window.GRASP_PACKAGE_DRAFT.init();
        }
      } catch (e) {
        // non-fatal
      }

      updateStatus();

      // initial save to ensure defaults persist
      await doSave();
    } catch (err) {
      console.error("[GRASP][parent-manual] init error:", err);
      alert("Unable to initialize the Parent Manual form. Please refresh and try again.");
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();

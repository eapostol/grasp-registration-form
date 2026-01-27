/* 
  GRASP Wait List App Script
  -------------------------
  Dynamic multi-step wizard + encrypted local draft storage + enrollment-prefill + submission.

  NOTE: This file is intentionally patterned after enrollment-app.js so it can share:
  - The same preview modal DOM IDs
  - The same debug helper (js/enrollment-debug.js)
*/

/* ============================================================================
   Enrollment -> Wait List prefill mapping
   Source: "GRASP - enrollment and wait-list common fields - Sheet1.pdf"

   Child Information
   - Wait List: Child's Name              <- Enrollment: Child's First Name + Child's Middle Name/Initial + Child's Last Name
   - Wait List: Date of Birth             <- Enrollment: Birth Date
   - Wait List: Subsidy File #            <- Enrollment: Subsidy File # (NOTE: added to wait list config as requested)
   - Wait List: Address                   <- Enrollment: Home Street Address (Parent/Guardian 1)
   - Wait List: Apt / Unit #              <- Enrollment: Apartment / Suite / Unit (Parent/Guardian 1)
   - Wait List: City                      <- Enrollment: Home City (Parent/Guardian 1)
   - Wait List: Postal Code               <- Enrollment: Home Postal Code (Parent/Guardian 1; derived full postal code)
   - Wait List: Home Phone #              <- Enrollment: Cell and home # (Parent/Guardian 1)

   Parent 1 Information
   - Wait List: Parent 1 Name             <- Enrollment: Parent/Guardian 1 First + Middle + Last  (combined with spaces)
   - Wait List: Email address             <- Enrollment: E-Mail Address (Parent/Guardian 1)
   - Wait List: Work phone #              <- Enrollment: Parent Work/School phone # (Parent/Guardian 1)
   - Wait List: Cell phone #              <- Enrollment: Cell and home # (Parent/Guardian 1; same value)

   Parent 2 Information
   - Wait List: Parent 2 Name             <- Enrollment: Parent/Guardian 2 First + Middle + Last  (combined with spaces)
   - Wait List: Email address             <- Enrollment: E-Mail Address (Parent/Guardian 2)
   - Wait List: Work phone #              <- Enrollment: Parent Work/School phone # (Parent/Guardian 2)
   - Wait List: Cell phone #              <- Enrollment: Cell and home # (Parent/Guardian 2; same value)

   Important distinction (from the mapping notes):
   - "Email address" appears twice visually, but one is Parent/Guardian 1 and the other is Parent/Guardian 2.
     We map them separately to avoid overwriting values.
============================================================================ */

(() => {
  // -----------------------------
  // Constants / globals
  // -----------------------------
  const FORM_ID = "grasp_waitlist_2025";
  const STORAGE_KEY_ENCRYPTED = "graspWaitlistEncryptedData";
  const SESSION_ID_KEY = "graspWaitlistSessionId";
  const DB_NAME = "graspWaitlistDB";
  const DB_STORE = "sessions";
  const SECRET_KEY_STRING = "grasp-waitlist-demo-secret-32-char!"; // 32+ chars

  // Enrollment draft keys (read-only; for prefill)
  const ENROLLMENT_FORM_ID = "grasp_enrollment_2025";
  const ENROLLMENT_STORAGE_KEY_ENCRYPTED = "graspEnrollmentEncryptedData";
  const ENROLLMENT_SESSION_ID_KEY = "graspEnrollmentSessionId";
  const ENROLLMENT_DB_NAME = "graspEnrollmentDB";
  const ENROLLMENT_DB_STORE = "sessions";
  const ENROLLMENT_SECRET_KEY_STRING = "grasp-enrollment-demo-secret-32-char!"; // must match enrollment-app.js

  // Public globals expected by enrollment-debug.js (reused for wait list)
  // NOTE: keep names aligned with enrollment-app.js
  window.config = null;     // loaded from config/waitlist-fields.json
  window.formState = {};    // current values
  window.currentStepIndex = 0;
  window.sessionId = null;
  window.isDebugMode = false;

  // -----------------------------
  // DOM refs
  // -----------------------------
  const els = {
    stepNav: () => document.getElementById("grasp-wizard-step-nav"),
    stepTitle: () => document.getElementById("grasp-wizard-step-title"),
    stepDesc: () => document.getElementById("grasp-wizard-step-desc"),
    fields: () => document.getElementById("grasp-wizard-fields"),
    progressFill: () => document.getElementById("grasp-wizard-progress-fill"),
    progressText: () => document.getElementById("grasp-wizard-progress-text"),
    btnPrev: () => document.getElementById("grasp-btn-prev"),
    btnNext: () => document.getElementById("grasp-btn-next"),
    btnSave: () => document.getElementById("grasp-btn-save"),
    btnPreview: () => document.getElementById("grasp-btn-preview"),
    modal: () => document.getElementById("grasp-preview-modal"),
    modalBody: () => document.getElementById("grasp-preview-body"),
    modalClose: () => document.getElementById("grasp-preview-close"),
    modalCancel: () => document.getElementById("grasp-preview-cancel"),
    modalSubmit: () => document.getElementById("grasp-preview-submit")
  };

  // -----------------------------
  // Utilities
  // -----------------------------
  function getQueryParam(name) {
    const params = new URLSearchParams(window.location.search);
    return params.get(name);
  }

  function escapeHtml(str) {
    const s = String(str ?? "");
    return s
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll("\"", "&quot;")
      .replaceAll("'", "&#039;");
  }

  function isEmptyValue(v) {
    return v === undefined || v === null || (typeof v === "string" && v.trim() === "");
  }

  function todayISODate() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
  }

  function normalizeCanadianPostalCode(value) {
    const raw = String(value ?? "").toUpperCase().replace(/\s+/g, "").trim();
    if (raw.length !== 6) return value;
    return `${raw.slice(0, 3)} ${raw.slice(3)}`;
  }

  function looksLikeCanadianPostalCode(value) {
    const v = String(value ?? "").toUpperCase().trim();
    return /^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/.test(v);
  }

  // -----------------------------
  // Crypto helpers (AES-GCM)
  // -----------------------------
  async function getCryptoKey(secretString) {
    const encoder = new TextEncoder();
    const hash = await crypto.subtle.digest("SHA-256", encoder.encode(secretString));
    return crypto.subtle.importKey("raw", hash, { name: "AES-GCM" }, false, ["encrypt", "decrypt"]);
  }

  async function encryptData(plainText, secretString) {
    const key = await getCryptoKey(secretString);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const encoder = new TextEncoder();
    const encryptedBuffer = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, encoder.encode(plainText));
    return { iv: Array.from(iv), data: Array.from(new Uint8Array(encryptedBuffer)) };
  }

  async function decryptData(payload, secretString) {
    const key = await getCryptoKey(secretString);
    const iv = new Uint8Array(payload.iv);
    const data = new Uint8Array(payload.data);
    const decryptedBuffer = await crypto.subtle.decrypt({ name: "AES-GCM", iv }, key, data);
    const decoder = new TextDecoder();
    return decoder.decode(decryptedBuffer);
  }

  // -----------------------------
  // IndexedDB helpers
  // -----------------------------
  function openDB(dbName, storeName) {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(dbName, 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(storeName)) db.createObjectStore(storeName);
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  async function idbGet(dbName, storeName, key) {
    const db = await openDB(dbName, storeName);
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, "readonly");
      const store = tx.objectStore(storeName);
      const req = store.get(key);
      req.onsuccess = () => resolve(req.result ?? null);
      req.onerror = () => reject(req.error);
    });
  }

  async function idbSet(dbName, storeName, key, value) {
    const db = await openDB(dbName, storeName);
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, "readwrite");
      const store = tx.objectStore(storeName);
      const req = store.put(value, key);
      req.onsuccess = () => resolve(true);
      req.onerror = () => reject(req.error);
    });
  }

  // -----------------------------
  // Draft save / load (Wait List)
  // -----------------------------
  function ensureSessionId() {
    if (window.sessionId) return window.sessionId;
    const existing = localStorage.getItem(SESSION_ID_KEY);
    if (existing) {
      window.sessionId = existing;
      return existing;
    }
    const newId = crypto.randomUUID();
    localStorage.setItem(SESSION_ID_KEY, newId);
    window.sessionId = newId;
    return newId;
  }

  async function saveDraft() {
    try {
      ensureSessionId();
      const payload = {
        formId: FORM_ID,
        sessionId: window.sessionId,
        savedAt: new Date().toISOString(),
        data: window.formState
      };

      const encrypted = await encryptData(JSON.stringify(payload), SECRET_KEY_STRING);

      // localStorage
      localStorage.setItem(STORAGE_KEY_ENCRYPTED, JSON.stringify(encrypted));

      // IndexedDB (same encrypted payload)
      await idbSet(DB_NAME, DB_STORE, window.sessionId, encrypted);
      return true;
    } catch (err) {
      console.error("[GRASP][waitlist] Failed to save draft:", err);
      return false;
    }
  }

  async function loadDraft() {
    try {
      // Try localStorage first
      const stored = localStorage.getItem(STORAGE_KEY_ENCRYPTED);
      if (stored) {
        const decryptedStr = await decryptData(JSON.parse(stored), SECRET_KEY_STRING);
        const payload = JSON.parse(decryptedStr);
        if (payload?.formId === FORM_ID && payload?.data) {
          window.sessionId = payload.sessionId || ensureSessionId();
          window.formState = payload.data || {};
          return true;
        }
      }

      // Fallback: IndexedDB (requires sessionId key)
      const sid = localStorage.getItem(SESSION_ID_KEY);
      if (!sid) return false;
      const encrypted = await idbGet(DB_NAME, DB_STORE, sid);
      if (!encrypted) return false;

      const decryptedStr = await decryptData(encrypted, SECRET_KEY_STRING);
      const payload = JSON.parse(decryptedStr);
      if (payload?.formId === FORM_ID && payload?.data) {
        window.sessionId = payload.sessionId || ensureSessionId();
        window.formState = payload.data || {};
        return true;
      }
      return false;
    } catch (err) {
      console.warn("[GRASP][waitlist] No valid draft loaded:", err);
      return false;
    }
  }

  let draftSaveTimer = null;
  function scheduleDraftSave(delayMs = 400) {
    if (draftSaveTimer) clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(() => saveDraft(), delayMs);
  }

  // -----------------------------
  // Enrollment draft load (for prefill)
  // -----------------------------
  async function loadEnrollmentDraftPayload() {
    // localStorage first
    const stored = localStorage.getItem(ENROLLMENT_STORAGE_KEY_ENCRYPTED);
    if (stored) {
      try {
        const decryptedStr = await decryptData(JSON.parse(stored), ENROLLMENT_SECRET_KEY_STRING);
        const payload = JSON.parse(decryptedStr);
        if (payload?.formId === ENROLLMENT_FORM_ID && payload?.data) return payload;
      } catch (err) {
        // swallow and try IDB
      }
    }

    // IndexedDB fallback
    const sid = localStorage.getItem(ENROLLMENT_SESSION_ID_KEY);
    if (!sid) return null;

    try {
      const encrypted = await idbGet(ENROLLMENT_DB_NAME, ENROLLMENT_DB_STORE, sid);
      if (!encrypted) return null;
      const decryptedStr = await decryptData(encrypted, ENROLLMENT_SECRET_KEY_STRING);
      const payload = JSON.parse(decryptedStr);
      if (payload?.formId === ENROLLMENT_FORM_ID && payload?.data) return payload;
      return null;
    } catch (err) {
      return null;
    }
  }

  function joinNonEmpty(parts) {
    return parts
      .map(p => String(p ?? "").trim())
      .filter(p => p.length > 0)
      .join(" ");
  }

  function getEnrollmentDerivedOrFallback(enrollmentData, derivedKey, fallbackParts) {
    const v = enrollmentData?.[derivedKey];
    if (!isEmptyValue(v)) return v;
    if (!fallbackParts) return "";
    return joinNonEmpty(fallbackParts.map(k => enrollmentData?.[k]));
  }

  function mapEnrollmentToWaitlist(enrollmentData) {
    // Only set fields that exist in the wait list config (and are currently empty).
    // This avoids overwriting the user if they already started editing.
    const updates = {};

    // Combined names (use derived fields when available)
    updates.child_name = getEnrollmentDerivedOrFallback(enrollmentData, "child_name", [
      "child_first_name",
      "child_middle_name_or_initial",
      "child_last_name"
    ]);

    updates.child_birth_date = enrollmentData?.child_birth_date ?? "";

    updates.subsidy_file_number = enrollmentData?.subsidy_file_number ?? "";

    // Address: Parent/Guardian 1
    updates.parent1_home_street = enrollmentData?.parent1_home_street ?? "";
    updates.parent1_home_unit = enrollmentData?.parent1_home_unit ?? "";
    updates.parent1_home_city = enrollmentData?.parent1_home_city ?? "";

    const derivedPostal = enrollmentData?.parent1_postal_code;
    if (!isEmptyValue(derivedPostal)) {
      updates.parent1_postal_code = derivedPostal;
    } else {
      const p1 = enrollmentData?.parent1_home_postal1 ?? "";
      const p2 = enrollmentData?.parent1_home_postal2 ?? "";
      const combined = joinNonEmpty([p1, p2]).toUpperCase();
      updates.parent1_postal_code = combined ? normalizeCanadianPostalCode(combined) : "";
    }

    // Home phone (and cell phone per mapping note)
    updates.parent1_phones = enrollmentData?.parent1_phones ?? "";

    // Parents
    updates.parent1_name = getEnrollmentDerivedOrFallback(enrollmentData, "parent1_name", [
      "parent1_first_name",
      "parent1_middle_name_or_initial",
      "parent1_last_name"
    ]);

    updates.parent2_name = getEnrollmentDerivedOrFallback(enrollmentData, "parent2_name", [
      "parent2_first_name",
      "parent2_middle_name_or_initial",
      "parent2_last_name"
    ]);

    // Emails (distinct)
    updates.parent1_email = enrollmentData?.parent1_email ?? "";
    updates.parent2_email = enrollmentData?.parent2_email ?? "";

    // Work phones
    updates.parent1_work_phone = enrollmentData?.parent1_work_phone ?? "";
    updates.parent2_work_phone = enrollmentData?.parent2_work_phone ?? "";

    // Cell phones (note: in enrollment, "Cell and home #" is a combined field per parent)
    updates.parent1_cell_phone = enrollmentData?.parent1_phones ?? "";
    updates.parent2_cell_phone = enrollmentData?.parent2_phones ?? "";

    // Apply only if the destination is empty
    let appliedAny = false;
    for (const [k, v] of Object.entries(updates)) {
      if (typeof window.formState[k] === "undefined" || isEmptyValue(window.formState[k])) {
        if (!isEmptyValue(v)) {
          window.formState[k] = v;
          appliedAny = true;
        }
      }
    }

    if (appliedAny) {
      // Normalize postal code if it looks valid
      if (!isEmptyValue(window.formState.parent1_postal_code) && looksLikeCanadianPostalCode(window.formState.parent1_postal_code)) {
        window.formState.parent1_postal_code = normalizeCanadianPostalCode(window.formState.parent1_postal_code);
      }
    }

    return appliedAny;
  }

  // -----------------------------
  // Derived fields
  // -----------------------------
  window.syncDerivedFields = function syncDerivedFields() {
    // For wait list we primarily keep things as entered.
    // We *do* normalize Parent 1 postal code for display consistency.
    const k = "parent1_postal_code";
    if (!isEmptyValue(window.formState[k]) && looksLikeCanadianPostalCode(window.formState[k])) {
      window.formState[k] = normalizeCanadianPostalCode(window.formState[k]);
    }

    // Default date_applied if empty and available
    if (isEmptyValue(window.formState.date_applied)) {
      window.formState.date_applied = todayISODate();
    }
  };

  function applyFieldDefaults() {
    // Apply config-level defaults (if any)
    for (const step of window.config.steps) {
      for (const group of step.groups) {
        for (const field of group.fields) {
          if (typeof field.default !== "undefined" && isEmptyValue(window.formState[field.name])) {
            window.formState[field.name] = field.default;
          }
        }
      }
    }
    window.syncDerivedFields();
  }

  // -----------------------------
  // Rendering
  // -----------------------------
  function setProgress() {
    const total = window.config.steps.length;
    const current = window.currentStepIndex + 1;
    const pct = Math.round((current / total) * 100);
    if (els.progressFill()) els.progressFill().style.width = `${pct}%`;
    if (els.progressText()) els.progressText().textContent = `Step ${current} of ${total}`;
  }

  function buildStepNav() {
    const nav = els.stepNav();
    if (!nav) return;

    nav.innerHTML = "";
    window.config.steps.forEach((step, idx) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "grasp-step-btn";
      btn.textContent = step.title;
      btn.setAttribute("role", "tab");
      btn.setAttribute("aria-selected", idx === window.currentStepIndex ? "true" : "false");
      btn.addEventListener("click", () => {
        // Allow navigating freely but keep validation on Preview/Submit.
        window.currentStepIndex = idx;
        renderCurrentStep();
      });
      nav.appendChild(btn);
    });
  }

  function renderCurrentStep() {
    const step = window.config.steps[window.currentStepIndex];
    if (!step) return;

    // Step title / description
    if (els.stepTitle()) els.stepTitle().textContent = step.title;
    if (els.stepDesc()) els.stepDesc().textContent = step.description || "";

    // Update tab selection
    const nav = els.stepNav();
    if (nav) {
      [...nav.querySelectorAll(".grasp-step-btn")].forEach((btn, idx) => {
        btn.classList.toggle("active", idx === window.currentStepIndex);
        btn.setAttribute("aria-selected", idx === window.currentStepIndex ? "true" : "false");
      });
    }

    // Render fields
    renderFields(step);

    // Buttons
    if (els.btnPrev()) els.btnPrev().disabled = window.currentStepIndex === 0;
    if (els.btnNext()) els.btnNext().disabled = window.currentStepIndex === window.config.steps.length - 1;

    setProgress();
  }

  function renderFields(step) {
    const root = els.fields();
    if (!root) return;

    root.innerHTML = "";
    for (const group of step.groups) {
      const groupEl = document.createElement("div");
      groupEl.className = "grasp-group";

      const h = document.createElement("h3");
      h.className = "grasp-group-title";
      h.textContent = group.title || "";
      groupEl.appendChild(h);

      for (const field of group.fields) {
        groupEl.appendChild(renderField(field));
      }

      root.appendChild(groupEl);
    }
  }

  function renderField(field) {
    const wrap = document.createElement("div");
    wrap.className = "grasp-field";

    const label = document.createElement("label");
    label.className = "grasp-label";
    label.setAttribute("for", `fld_${field.name}`);
    label.innerHTML = `${escapeHtml(field.label)}${field.required ? ' <span class="req">*</span>' : ""}`;

    const input = createInput(field);
    const err = document.createElement("div");
    err.className = "grasp-error";
    err.id = `err_${field.name}`;
    err.style.display = "none";

    wrap.appendChild(label);
    wrap.appendChild(input);
    wrap.appendChild(err);

    return wrap;
  }

  function createInput(field) {
    const id = `fld_${field.name}`;
    const val = window.formState[field.name];

    if (field.type === "textarea") {
      const el = document.createElement("textarea");
      el.id = id;
      el.name = field.name;
      el.value = val ?? "";
      el.rows = 4;
      el.className = "grasp-input";
      el.addEventListener("input", () => setFieldValue(field.name, el.value));
      return el;
    }

    if (field.type === "radio") {
      const wrap = document.createElement("div");
      wrap.className = "grasp-radio-wrap";
      (field.options || []).forEach((opt, idx) => {
        const rid = `${id}_${idx}`;
        const label = document.createElement("label");
        label.className = "grasp-radio-label";
        const radio = document.createElement("input");
        radio.type = "radio";
        radio.name = field.name;
        radio.id = rid;
        radio.value = opt.value;
        radio.checked = String(val ?? "") === String(opt.value);
        radio.addEventListener("change", () => {
          if (radio.checked) setFieldValue(field.name, radio.value);
        });
        label.appendChild(radio);
        label.appendChild(document.createTextNode(" " + opt.label));
        wrap.appendChild(label);
      });
      return wrap;
    }

    if (field.type === "checkbox") {
      const el = document.createElement("input");
      el.id = id;
      el.name = field.name;
      el.type = "checkbox";
      el.checked = Boolean(val);
      el.addEventListener("change", () => setFieldValue(field.name, el.checked));
      return el;
    }

    // default: input
    const el = document.createElement("input");
    el.id = id;
    el.name = field.name;
    el.type = field.inputType || field.type || "text";
    el.value = val ?? "";
    el.className = "grasp-input";
    if (field.placeholder) el.placeholder = field.placeholder;

    el.addEventListener("input", () => setFieldValue(field.name, el.value));
    el.addEventListener("blur", () => {
      // Normalize postal code on blur
      if (field.name === "parent1_postal_code") {
        el.value = normalizeCanadianPostalCode(el.value);
        setFieldValue(field.name, el.value);
      }
      validateCurrentStep();
    });

    return el;
  }

  function setFieldValue(name, value) {
    window.formState[name] = value;
    window.syncDerivedFields();
    scheduleDraftSave();
  }

  // -----------------------------
  // Validation
  // -----------------------------
  function showFieldError(name, message) {
    const err = document.getElementById(`err_${name}`);
    if (!err) return;
    err.textContent = message;
    err.style.display = message ? "block" : "none";
  }

  function validateField(field) {
    const value = window.formState[field.name];

    if (field.required) {
      if (field.type === "checkbox") {
        if (!value) return "This field is required.";
      } else if (isEmptyValue(value)) {
        return "This field is required.";
      }
    }

    // Simple email validation (if present)
    if ((field.inputType === "email" || field.type === "email") && !isEmptyValue(value)) {
      const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim());
      if (!ok) return "Please enter a valid email address.";
    }

    // Postal validation
    if (field.name === "parent1_postal_code" && !isEmptyValue(value)) {
      if (!looksLikeCanadianPostalCode(value)) return "Please enter a valid Canadian postal code (e.g., A1A 1A1).";
    }

    return "";
  }

  function validateCurrentStep() {
    const step = window.config.steps[window.currentStepIndex];
    let ok = true;
    for (const group of step.groups) {
      for (const field of group.fields) {
        const msg = validateField(field);
        showFieldError(field.name, msg);
        if (msg) ok = false;
      }
    }
    return ok;
  }

  function validateAllSteps() {
    let ok = true;
    for (const step of window.config.steps) {
      for (const group of step.groups) {
        for (const field of group.fields) {
          const msg = validateField(field);
          if (msg) ok = false;
        }
      }
    }
    return ok;
  }

  // -----------------------------
  // Preview modal
  // -----------------------------
  function buildPreviewHtml() {
    const parts = [];
    parts.push("<div class='grasp-preview'>");

    for (const step of window.config.steps) {
      parts.push(`<h4>${escapeHtml(step.title)}</h4>`);
      for (const group of step.groups) {
        parts.push(`<h5>${escapeHtml(group.title || "")}</h5>`);
        parts.push("<table class='grasp-preview-table'>");
        for (const field of group.fields) {
          const label = field.label + (field.required ? " *" : "");
          let value = window.formState[field.name];

          if (field.type === "checkbox") value = value ? "YES" : "NO";
          if (field.type === "radio") {
            const opt = (field.options || []).find(o => String(o.value) === String(value));
            value = opt ? opt.label : (value ?? "");
          }
          parts.push(
            `<tr><td class='grasp-preview-label'>${escapeHtml(label)}</td><td class='grasp-preview-value'>${escapeHtml(value ?? "")}</td></tr>`
          );
        }
        parts.push("</table>");
      }
    }

    parts.push("</div>");
    return parts.join("");
  }

  function openPreview() {
    // Validate all steps before preview
    if (!validateAllSteps()) {
      // Also validate the current step to show inline errors
      validateCurrentStep();
      alert("Please correct the highlighted fields before previewing.");
      return;
    }

    if (els.modalBody()) els.modalBody().innerHTML = buildPreviewHtml();
    const modal = els.modal();
    if (!modal) return;
    modal.setAttribute("aria-hidden", "false");
    modal.classList.remove("hidden");
  }

  function closePreview() {
    const modal = els.modal();
    if (!modal) return;
    modal.setAttribute("aria-hidden", "true");
    modal.classList.add("hidden");
  }

  // -----------------------------
  // Submission (email via PHP)
  // -----------------------------
  async function submitWaitlist() {
    try {
      const payload = {
        formId: FORM_ID,
        sessionId: window.sessionId || ensureSessionId(),
        submittedAt: new Date().toISOString(),
        data: window.formState,
        emailHtml: buildPreviewHtml()
      };

      const res = await fetch("../api/submit_waitlist.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });

      const json = await res.json().catch(() => ({}));
      if (!res.ok || json?.ok === false) {
        const msg = json?.message || "Submission failed. Please try again later.";
        alert(msg);
        return;
      }

      alert("Thank you! Your wait list application has been submitted.");
      closePreview();
    } catch (err) {
      console.error("[GRASP][waitlist] submit error:", err);
      alert("Submission failed. Please check your connection and try again.");
    }
  }

  // -----------------------------
  // Init
  // -----------------------------
  async function loadConfig() {
    const res = await fetch("../config/waitlist-fields.json");
    if (!res.ok) throw new Error("Could not load wait list form configuration.");
    window.config = await res.json();
  }

  async function init() {
    window.isDebugMode = (String(getQueryParam("debug") ?? getQueryParam("DEBUG") ?? "")).toLowerCase() === "true";

    await loadConfig();

    // 1) Load wait list draft (highest precedence)
    const hadWaitlistDraft = await loadDraft();

    // 2) If no wait list draft, attempt to prefill from enrollment draft (second precedence)
    if (!hadWaitlistDraft) {
      const enrollmentPayload = await loadEnrollmentDraftPayload();
      if (enrollmentPayload?.data) {
        const applied = mapEnrollmentToWaitlist(enrollmentPayload.data);
        if (applied) {
          console.info("[GRASP] Prefilled from enrollment draft");
          await saveDraft(); // persists so this log only occurs once in normal usage
        }
      }
    }

    // 3) Defaults (and derived normalization). Debug script runs after init event and fills ONLY missing fields.
    applyFieldDefaults();

    // Render UI
    buildStepNav();
    renderCurrentStep();

    // Wire buttons
    if (els.btnPrev()) {
      els.btnPrev().addEventListener("click", () => {
        if (window.currentStepIndex > 0) {
          window.currentStepIndex -= 1;
          renderCurrentStep();
        }
      });
    }

    if (els.btnNext()) {
      els.btnNext().addEventListener("click", () => {
        if (!validateCurrentStep()) return;
        if (window.currentStepIndex < window.config.steps.length - 1) {
          window.currentStepIndex += 1;
          renderCurrentStep();
        }
      });
    }

    if (els.btnSave()) {
      els.btnSave().addEventListener("click", async () => {
        const ok = await saveDraft();
        alert(ok ? "Draft saved." : "Draft could not be saved.");
      });
    }

    if (els.btnPreview()) {
      els.btnPreview().addEventListener("click", () => {
        validateCurrentStep();
        openPreview();
      });
    }

    if (els.modalClose()) els.modalClose().addEventListener("click", closePreview);
    if (els.modalCancel()) els.modalCancel().addEventListener("click", closePreview);
    if (els.modalSubmit()) els.modalSubmit().addEventListener("click", submitWaitlist);

    // Ensure modal is hidden on initial load (CSS defaults .grasp-modal to visible)
    const modal = els.modal();
    if (modal) {
      modal.classList.add("hidden");
      modal.setAttribute("aria-hidden", "true");
    }

    // Allow esc to close modal
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closePreview();
    });

    // Signal init so enrollment-debug.js can apply debug defaults (lowest precedence)
    window.dispatchEvent(new CustomEvent("graspWaitlistInit"));

    // If debug mode is on, validate current step once to show missing required fields (after debug fill runs)
    setTimeout(() => {
      try { validateCurrentStep(); } catch (_) {}
    }, 50);
  }

  document.addEventListener("DOMContentLoaded", () => {
    init().catch(err => {
      console.error("[GRASP][waitlist] init error:", err);
      alert("Could not initialize wait list form. Please refresh the page.");
    });
  });
})();

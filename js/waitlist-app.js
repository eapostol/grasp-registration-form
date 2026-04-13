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
  const ENROLLMENT_SECRET_KEY_STRING =
    "grasp-enrollment-demo-secret-32-chars!!";
  // must match enrollment-app.js

  // Public globals expected by enrollment-debug.js (reused for wait list)
  // NOTE: keep names aligned with enrollment-app.js
  window.config = null; // loaded from config/waitlist-fields.json
  window.formState = {}; // current values
  window.currentStepIndex = 0;
  window.sessionId = null;
  window.isDebugMode = false;

  // -----------------------------
  // DOM refs
  // -----------------------------
  const els = {
    stepNav: () => document.getElementById("grasp-wizard-step-list"),
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
    modalPrint: () => document.getElementById("grasp-preview-print"),
    modalSubmit: () => document.getElementById("grasp-preview-submit"),
  };

  let _previewLastHtml = "";

  function openPrintWindow(previewHtml) {
  const html = previewHtml || "";

  // Avoid popup blockers (especially in Incognito/Private) by printing from a hidden iframe.
  const iframe = document.createElement("iframe");
  iframe.setAttribute("aria-hidden", "true");
  iframe.style.position = "fixed";
  iframe.style.right = "0";
  iframe.style.bottom = "0";
  iframe.style.width = "0";
  iframe.style.height = "0";
  iframe.style.border = "0";
  iframe.style.visibility = "hidden";
  document.body.appendChild(iframe);

  // Prefer shared print stylesheet; keep minimal fallback print CSS inline.
  const printCss = `
    @page { size: Letter; margin: 16mm 12mm; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #111; }
    h4 { font-size: 14pt; margin: 0 0 8px; }
    h5 { font-size: 12pt; margin: 16px 0 6px; }
    table { width: 100%; border-collapse: collapse; margin: 0 0 10px; }
    td { border: 1px solid #bbb; padding: 6px 8px; vertical-align: top; }
    .grasp-preview-label { width: 34%; background: #f3f3f3; font-weight: 700; }
    .grasp-page-break { break-before: page; page-break-before: always; }
  `;

  const printCssHref = new URL("../css/print.css", window.location.href).toString();

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
        <title>Print Preview</title>
        <link rel="stylesheet" href="${printCssHref}" media="print" />
        <style>${printCss}</style>
      </head>
      <body>
        <div class="grasp-print-container">${html}</div>
      </body>
    </html>`;
}


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
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function isEmptyValue(v) {
    return (
      v === undefined ||
      v === null ||
      (typeof v === "string" && v.trim() === "")
    );
  }

  function todayISODate() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
  }

  function normalizeCanadianPostalCode(value) {
    const raw = String(value ?? "")
      .toUpperCase()
      .replace(/\s+/g, "")
      .trim();
    if (raw.length !== 6) return value;
    return `${raw.slice(0, 3)} ${raw.slice(3)}`;
  }

  function looksLikeCanadianPostalCode(value) {
    const v = String(value ?? "")
      .toUpperCase()
      .trim();
    return /^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/.test(v);
  }

  // -----------------------------
  // Crypto helpers (AES-GCM)
  // -----------------------------
  async function getCryptoKey(secretString) {
    const encoder = new TextEncoder();
    const hash = await crypto.subtle.digest(
      "SHA-256",
      encoder.encode(secretString),
    );
    return crypto.subtle.importKey("raw", hash, { name: "AES-GCM" }, false, [
      "encrypt",
      "decrypt",
    ]);
  }

  async function encryptData(plainText, secretString) {
    const key = await getCryptoKey(secretString);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const encoder = new TextEncoder();
    const encryptedBuffer = await crypto.subtle.encrypt(
      { name: "AES-GCM", iv },
      key,
      encoder.encode(plainText),
    );
    return {
      iv: Array.from(iv),
      data: Array.from(new Uint8Array(encryptedBuffer)),
    };
  }

  async function decryptData(payload, secretString) {
    const key = await getCryptoKey(secretString);
    const iv = new Uint8Array(payload.iv);
    const data = new Uint8Array(payload.data);
    const decryptedBuffer = await crypto.subtle.decrypt(
      { name: "AES-GCM", iv },
      key,
      data,
    );
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
        if (!db.objectStoreNames.contains(storeName))
          db.createObjectStore(storeName);
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

  window.waitlistDraftVersion = window.waitlistDraftVersion || 0;

  async function saveDraft() {
    try {
      ensureSessionId();

      // increment version on each save
      window.waitlistDraftVersion = Math.max(
        1,
        Number(window.waitlistDraftVersion || 0) + 1,
      );

      const payload = {
        formId: FORM_ID,
        sessionId: window.sessionId,
        version: window.waitlistDraftVersion,
        updatedAt: new Date().toISOString(),
        data: window.formState,
      };

      const encrypted = await encryptData(
        JSON.stringify(payload),
        SECRET_KEY_STRING,
      );

      // localStorage
      localStorage.setItem(STORAGE_KEY_ENCRYPTED, JSON.stringify(encrypted));

      // IndexedDB
      await idbSet(DB_NAME, DB_STORE, window.sessionId, encrypted);

      // Update shared package draft (common fields)
      try {
        if (window.GRASP_PACKAGE_DRAFT?.upsertRegistrant) {
          await window.GRASP_PACKAGE_DRAFT.upsertRegistrant(window.formState, {
            onlyFillMissing: false,
          });
        }
      } catch (e) {
        console.warn("[GRASP][waitlist] packageDraft upsert failed", e);
      }

      return true;
    } catch (err) {
      console.error("[GRASP][waitlist] Failed to save draft:", err);
      return false;
    }
  }

  async function loadDraft() {
    try {
      let localEncrypted = null;
      let localPayload = null;

      const stored = localStorage.getItem(STORAGE_KEY_ENCRYPTED);
      if (stored) {
        localEncrypted = JSON.parse(stored);
        const decryptedStr = await decryptData(
          localEncrypted,
          SECRET_KEY_STRING,
        );
        const payload = JSON.parse(decryptedStr);
        if (payload?.formId === FORM_ID && payload?.data) {
          localPayload = payload;
        }
      }

      // session id from payload or LS
      if (localPayload?.sessionId) {
        window.sessionId = localPayload.sessionId;
        localStorage.setItem(SESSION_ID_KEY, window.sessionId);
      } else {
        ensureSessionId();
      }

      let idbEncrypted = null;
      let idbPayload = null;

      const sid = localStorage.getItem(SESSION_ID_KEY);
      if (sid) {
        idbEncrypted = await idbGet(DB_NAME, DB_STORE, sid);
        if (idbEncrypted) {
          const decryptedStr = await decryptData(
            idbEncrypted,
            SECRET_KEY_STRING,
          );
          const payload = JSON.parse(decryptedStr);
          if (payload?.formId === FORM_ID && payload?.data) {
            idbPayload = payload;
          }
        }
      }

      function meta(p) {
        return {
          version: Number.isFinite(Number(p?.version)) ? Number(p.version) : 0,
          updatedAtMs: Date.parse(p?.updatedAt || p?.savedAt || "") || 0,
        };
      }

      const candidates = [];
      if (localPayload && localEncrypted)
        candidates.push({
          payload: localPayload,
          encrypted: localEncrypted,
          src: "ls",
        });
      if (idbPayload && idbEncrypted)
        candidates.push({
          payload: idbPayload,
          encrypted: idbEncrypted,
          src: "idb",
        });

      if (candidates.length === 0) return false;

      let chosen = candidates[0];
      if (candidates.length === 2) {
        const a = candidates[0],
          b = candidates[1];
        const ma = meta(a.payload),
          mb = meta(b.payload);
        if (ma.version !== mb.version) chosen = ma.version > mb.version ? a : b;
        else chosen = ma.updatedAtMs >= mb.updatedAtMs ? a : b;
      }

      window.sessionId = chosen.payload.sessionId || ensureSessionId();
      window.formState = chosen.payload.data || {};
      window.waitlistDraftVersion = Number.isFinite(
        Number(chosen.payload.version),
      )
        ? Number(chosen.payload.version)
        : 0;

      // keep LS + IDB synced to chosen
      localStorage.setItem(
        STORAGE_KEY_ENCRYPTED,
        JSON.stringify(chosen.encrypted),
      );
      await idbSet(DB_NAME, DB_STORE, window.sessionId, chosen.encrypted);

      return true;
    } catch (err) {
      console.warn("[GRASP][waitlist] No valid draft loaded:", err);
      return false;
    }
  }

  function bindClearSavedDataButton() {
    const btn = document.getElementById("grasp-btn-clear-drafts");
    if (!btn) return;

    btn.addEventListener("click", async () => {
      const ok = window.confirm(
        "This will clear ALL saved Enrollment/Wait List drafts on this device. Continue?",
      );
      if (!ok) return;

      try {
        await window.GRASP_PACKAGE_DRAFT?.clearAllDrafts?.();
      } catch (e) {
        console.warn("[GRASP] clearAllDrafts failed", e);
      }
      window.location.reload();
    });
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
        const decryptedStr = await decryptData(
          JSON.parse(stored),
          ENROLLMENT_SECRET_KEY_STRING,
        );
        const payload = JSON.parse(decryptedStr);
        if (payload?.formId === ENROLLMENT_FORM_ID && payload?.data)
          return payload;
      } catch (err) {
        // swallow and try IDB
      }
    }

    // IndexedDB fallback
    const sid = localStorage.getItem(ENROLLMENT_SESSION_ID_KEY);
    if (!sid) return null;

    try {
      const encrypted = await idbGet(
        ENROLLMENT_DB_NAME,
        ENROLLMENT_DB_STORE,
        sid,
      );
      if (!encrypted) return null;
      const decryptedStr = await decryptData(
        encrypted,
        ENROLLMENT_SECRET_KEY_STRING,
      );
      const payload = JSON.parse(decryptedStr);
      if (payload?.formId === ENROLLMENT_FORM_ID && payload?.data)
        return payload;
      return null;
    } catch (err) {
      return null;
    }
  }

  function joinNonEmpty(parts) {
    return parts
      .map((p) => String(p ?? "").trim())
      .filter((p) => p.length > 0)
      .join(" ");
  }

  function getEnrollmentDerivedOrFallback(
    enrollmentData,
    derivedKey,
    fallbackParts,
  ) {
    const v = enrollmentData?.[derivedKey];
    if (!isEmptyValue(v)) return v;
    if (!fallbackParts) return "";
    return joinNonEmpty(fallbackParts.map((k) => enrollmentData?.[k]));
  }

  /*
Draft mapping (Enrollment -> Wait List), source:
  "GRASP - enrollment and wait-list common fields.xlsx"

Sections:
- Child Information:
  child_first_name + child_middle_name_or_initial + child_last_name => child_name (combined with spaces)
  child_birth_date => child_birth_date
  subsidy_file_number => subsidy_file_number

- Parent/Guardian 1 Information:
  parent1_home_street => parent1_home_street
  parent1_home_unit => parent1_home_unit
  parent1_home_city => parent1_home_city
  parent1_postal_code (or parent1_home_postal1 + parent1_home_postal2) => parent1_postal_code (combined)
  parent1_phones ("Cell and home #") => parent1_phones AND parent1_cell_phone (same source)
  parent1_first_name + parent1_middle_name_or_initial + parent1_last_name => parent1_name (combined)
  parent1_email => parent1_email
  parent1_work_phone => parent1_work_phone

- Parent/Guardian 2 Information:
  parent2_first_name + parent2_middle_name_or_initial + parent2_last_name => parent2_name (combined)
  parent2_email => parent2_email
  parent2_work_phone => parent2_work_phone
  parent2_phones ("Cell and home #") => parent2_cell_phone (same source)

Notes:
- Parent 1 vs Parent 2 email fields must remain distinct (do NOT overwrite each other).
- Combined-name fields in wait list should not overwrite split-name fields in enrollment; sync is conservative.
*/

  function mapEnrollmentToWaitlist(enrollmentData) {
    // Only set fields that exist in the wait list config (and are currently empty).
    // This avoids overwriting the user if they already started editing.
    const updates = {};

    // Combined names (use derived fields when available)
    updates.child_name = getEnrollmentDerivedOrFallback(
      enrollmentData,
      "child_name",
      ["child_first_name", "child_middle_name_or_initial", "child_last_name"],
    );

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
      updates.parent1_postal_code = combined
        ? normalizeCanadianPostalCode(combined)
        : "";
    }

    // Home phone (and cell phone per mapping note)
    updates.parent1_phones = enrollmentData?.parent1_phones ?? "";

    // Parents
    updates.parent1_name = getEnrollmentDerivedOrFallback(
      enrollmentData,
      "parent1_name",
      [
        "parent1_first_name",
        "parent1_middle_name_or_initial",
        "parent1_last_name",
      ],
    );

    updates.parent2_name = getEnrollmentDerivedOrFallback(
      enrollmentData,
      "parent2_name",
      [
        "parent2_first_name",
        "parent2_middle_name_or_initial",
        "parent2_last_name",
      ],
    );

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
      if (
        typeof window.formState[k] === "undefined" ||
        isEmptyValue(window.formState[k])
      ) {
        if (!isEmptyValue(v)) {
          window.formState[k] = v;
          appliedAny = true;
        }
      }
    }

    if (appliedAny) {
      // Normalize postal code if it looks valid
      if (
        !isEmptyValue(window.formState.parent1_postal_code) &&
        looksLikeCanadianPostalCode(window.formState.parent1_postal_code)
      ) {
        window.formState.parent1_postal_code = normalizeCanadianPostalCode(
          window.formState.parent1_postal_code,
        );
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
    if (
      !isEmptyValue(window.formState[k]) &&
      looksLikeCanadianPostalCode(window.formState[k])
    ) {
      window.formState[k] = normalizeCanadianPostalCode(window.formState[k]);
    }

    // Default date_applied if empty and available
    if (isEmptyValue(window.formState.date_applied)) {
      window.formState.date_applied = todayISODate();
    }

    // Default signature_date if present (Waitlist signature section)
    if (isEmptyValue(window.formState.signature_date)) {
      window.formState.signature_date = todayISODate();
    }
  };

  function applyFieldDefaults() {
    // Apply config-level defaults (if any)
    for (const step of window.config.steps) {
      for (const group of step.groups) {
        for (const field of group.fields) {
          if (
            typeof field.default !== "undefined" &&
            isEmptyValue(window.formState[field.name])
          ) {
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
    if (els.progressText())
      els.progressText().textContent = `Step ${current} of ${total}`;
  }

  function buildStepNav() {
    const nav = els.stepNav();
    if (!nav) return;

    nav.innerHTML = "";
    window.config.steps.forEach((step, idx) => {
      const li = document.createElement("li");

      const t = String(step.title || "").trim();
      const hasLeadingNumber = /^\d+\./.test(t);
      li.textContent = hasLeadingNumber ? t : `${idx + 1}. ${t}`;

      if (idx === window.currentStepIndex) {
        li.classList.add("active-step");
      } else if (idx < window.currentStepIndex) {
        li.classList.add("completed-step");
      }

      // Make each step behave like a button
      li.dataset.stepIndex = String(idx);
      li.tabIndex = 0;
      li.role = "button";
      li.setAttribute(
        "aria-selected",
        idx === window.currentStepIndex ? "true" : "false",
      );

      // Clicking a tab always navigates to that step.
      li.addEventListener("click", () => {
        // Allow navigating freely but keep validation on Preview/Submit.
        window.currentStepIndex = idx;
        renderCurrentStep();
      });

      // Keyboard access (space / enter)
      li.addEventListener("keypress", (ev) => {
        if (ev.key === " " || ev.key === "Enter") {
          ev.preventDefault();
          li.click();
        }
      });

      nav.appendChild(li);
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
      [...nav.querySelectorAll("li")].forEach((li, idx) => {
        li.classList.toggle("active-step", idx === window.currentStepIndex);
        li.classList.toggle("completed-step", idx < window.currentStepIndex);
        li.setAttribute(
          "aria-selected",
          idx === window.currentStepIndex ? "true" : "false",
        );
      });
    }

    // Render fields
    renderFields(step);

    // Buttons
    if (els.btnPrev()) els.btnPrev().disabled = window.currentStepIndex === 0;
    if (els.btnNext())
      els.btnNext().disabled =
        window.currentStepIndex === window.config.steps.length - 1;

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

  function waitlistAttendanceInlineNote(fieldName) {
    switch (fieldName) {
      case "currently_attends_daycare":
        return "day care at the current time.";
      case "currently_attending_school":
        return "at the current time.";
      case "will_attend_when_require_care":
        return "when we require care at GRASP.";
      default:
        return "";
    }
  }

  function isStructuralField(field) {
    const t = String(field?.type || "").toLowerCase();
    return t === "divider" || t === "hr";
  }

  function renderField(field) {
    if (isStructuralField(field)) {
      const wrap = document.createElement("div");
      wrap.className = "grasp-field grasp-field-divider";
      const hr = document.createElement("hr");
      hr.className = "grasp-divider";
      wrap.appendChild(hr);
      return wrap;
    }

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

    // Waitlist: add sentence-style inline notes under Current Attendance inputs
    const noteText = waitlistAttendanceInlineNote(field.name);
    if (noteText) {
      const note = document.createElement("div");
      // Reuse label styling so it matches the bold label look
      note.className = "grasp-label grasp-inline-note";
      note.innerHTML = '<strong>' + noteText + '</strong>';
      wrap.appendChild(note);
    }

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
      el.addEventListener("change", () =>
        setFieldValue(field.name, el.checked),
      );
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
    if (isStructuralField(field)) return "";

    const value = window.formState[field.name];

    if (field.required) {
      if (field.type === "checkbox") {
        if (!value) return "This field is required.";
      } else if (isEmptyValue(value)) {
        return "This field is required.";
      }
    }

    // Simple email validation (if present)
    if (
      (field.inputType === "email" || field.type === "email") &&
      !isEmptyValue(value)
    ) {
      const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim());
      if (!ok) return "Please enter a valid email address.";
    }

    // Postal validation
    if (field.name === "parent1_postal_code" && !isEmptyValue(value)) {
      if (!looksLikeCanadianPostalCode(value))
        return "Please enter a valid Canadian postal code (e.g., A1A 1A1).";
    }

    return "";
  }

  function validateCurrentStep() {
    const step = window.config.steps[window.currentStepIndex];
    let ok = true;
    for (const group of step.groups) {
      for (const field of group.fields) {
        if (isStructuralField(field)) continue;
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
          if (isStructuralField(field)) continue;
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
          if (isStructuralField(field)) continue;
          const label = field.label + (field.required ? " *" : "");
          const hintHtml = field.hint ? `<div style="font-size:9pt;color:#444;margin-top:2px;">${escapeHtml(field.hint)}</div>` : "";
          let value = window.formState[field.name];

          if (field.type === "checkbox") value = value ? "YES" : "NO";
          if (field.type === "radio") {
            const opt = (field.options || []).find(
              (o) => String(o.value) === String(value),
            );
            value = opt ? opt.label : (value ?? "");
          }
          parts.push(`<tr><td class='grasp-preview-label'>${escapeHtml(label)}${hintHtml}</td><td class='grasp-preview-value'>${escapeHtml(value ?? "")}</td></tr>`);
        }
        parts.push("</table>");
      }
    }

    parts.push("</div>");
    return parts.join("");
  }



  // ---------------------------------
  // PDF-style print template (Waitlist)
  // ---------------------------------
  function buildWaitlistPrintHtml() {
    const cfg = window.config;
    const state = window.formState || {};
    const parts = [];

    const intro = "GRASP maintains an ongoing waiting list for families that have children that attend Greenland Public School, as well as other schools within the Don Mills Community. Once a registration form has been filled out, your child(ren)'s names will be added to the waiting list in sequence according to the date of application and using the published criteria.";

    function fieldDisplayValue(field) {
      let v = state[field.name];
      if (field.type === "checkbox") return v ? "YES" : "NO";
      if (field.type === "radio") {
        const opt = (field.options || []).find(
          (o) => String(o.value) === String(v),
        );
        return opt ? opt.label : (v ?? "");
      }
      return v ?? "";
    }

    parts.push('<div class="grasp-page">');
    parts.push(`
      <div class="grasp-header">
        <h1 class="grasp-header-title">Greenland Recreational After School Program</h1>
        <div class="grasp-form-title">GRASP Wait List Application Form</div>
      </div>
      <div class="grasp-brand-bar"></div>
      <p class="grasp-paragraph">${escapeHtml(intro)}</p>
    `);

    // Render all groups as compact 2-col table sections.
    // If signature fields exist in config, skip them here and render at bottom.
    const skip = new Set(["parent_signature", "signature_date"]);
    for (const step of (cfg?.steps || [])) {
      for (const group of (step.groups || [])) {
        const fields = (group.fields || []).filter(
          (f) => !isStructuralField(f) && !skip.has(f.name),
        );
        if (fields.length === 0) continue;

        parts.push('<div class="grasp-section">');
        if (group.title) {
          parts.push(`<div class="grasp-section-title">${escapeHtml(group.title)}</div>`);
        }
        parts.push('<table class="grasp-kv-table"><tbody>');
        for (const field of fields) {
          const label = `${field.label}${field.required ? " *" : ""}`;
          const value = fieldDisplayValue(field);
          parts.push(
            `<tr><td class="grasp-kv-label">${escapeHtml(label)}</td><td class="grasp-kv-value">${escapeHtml(value)}</td></tr>`,
          );
        }
        parts.push('</tbody></table>');
        parts.push('</div>');
      }
    }

    // Signature + Date (centered with underlines)
    const sig = String(state.parent_signature ?? "").trim();
    const sdate = String(state.signature_date ?? "").trim();
    parts.push(`
      <div class="grasp-section">
        <div class="grasp-signature-center">
          <div class="grasp-signature-line">
            <div class="grasp-signature-value">${escapeHtml(sig)}</div>
            <div class="grasp-signature-caption">Parent signature (type in your full name)</div>
          </div>
          <div class="grasp-signature-line">
            <div class="grasp-signature-value">${escapeHtml(sdate)}</div>
            <div class="grasp-signature-caption">Date</div>
          </div>
        </div>
      </div>
      <div class="grasp-static-footer">Page 1</div>
    `);

    parts.push('</div>');
    return parts.join('');
  }
  function openPreview() {
    // Validate all steps before preview
    if (!validateAllSteps()) {
      // Also validate the current step to show inline errors
      validateCurrentStep();
      alert("Please correct the highlighted fields before previewing.");
      return;
    }

    _previewLastHtml = buildPreviewHtml();
    if (els.modalBody()) els.modalBody().innerHTML = _previewLastHtml;
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
        emailHtml: buildPreviewHtml(),
      };

      const res = await fetch("../api/submit_waitlist.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const json = await res.json().catch(() => ({}));
      if (!res.ok || json?.ok === false) {
        const msg =
          json?.message || "Submission failed. Please try again later.";
        alert(msg);
        return;
      }

      alert("Thank you! Your wait list application has been submitted.");

      // ✅ Add this block on success
      try {
        if (window.GRASP_PACKAGE_DRAFT?.setStatus) {
          await window.GRASP_PACKAGE_DRAFT.setStatus({
            waitlistSubmittedAt: new Date().toISOString(),
          });
        }
      } catch (e) {
        console.warn("[GRASP][waitlist] package status update failed", e);
      }
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
    // IMPORTANT: use a relative path so deployments under a subdirectory
    // (e.g. https://greenlandrecreational.com/staging/) resolve correctly.
    const res = await fetch(new URL("../config/waitlist-fields.json", window.location.href).toString());
    if (!res.ok)
      throw new Error("Could not load wait list form configuration.");
    window.config = await res.json();
  }

  async function init() {
    window.isDebugMode =
      String(
        getQueryParam("debug") ?? getQueryParam("DEBUG") ?? "",
      ).toLowerCase() === "true";

    // 30-day stale draft check (shared)
    if (window.GRASP_PACKAGE_DRAFT?.checkAndHandleStaleDraft) {
      const ok = await window.GRASP_PACKAGE_DRAFT.checkAndHandleStaleDraft();
      if (!ok) return; // it will reload if cleared
    }

    // Load wait list configuration BEFORE anything that needs config.steps
    await loadConfig();

    // 1) Load wait list draft
    const hadWaitlistDraft = await loadDraft();

    // 2) Prefill from PACKAGE draft (fills missing only, never overwrites)
    try {
      const pkg = await window.GRASP_PACKAGE_DRAFT?.load?.();
      if (pkg?.registrant && typeof pkg.registrant === "object") {
        mapEnrollmentToWaitlist(pkg.registrant); // safe: fills only missing
      }
    } catch (e) {
      console.warn("[GRASP][waitlist] packageDraft load/prefill failed", e);
    }

    // 3) Prefill from enrollment draft mapping (fills missing only)
    const enrollmentPayload = await loadEnrollmentDraftPayload();
    if (enrollmentPayload?.data) {
      const applied = mapEnrollmentToWaitlist(enrollmentPayload.data);
      if (applied) {
        // One-time confirmation log to make testing easier
        const alreadyLogged =
          localStorage.getItem("graspWaitlistPrefilledFromEnrollment") === "true";
        if (!alreadyLogged) {
          console.info("[GRASP] Prefilled from enrollment draft");
          localStorage.setItem("graspWaitlistPrefilledFromEnrollment", "true");
        }
        await saveDraft();
      }
    }

    // 4) Defaults (debug runs after init and fills missing only)
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

    if (els.modalClose())
      els.modalClose().addEventListener("click", closePreview);
    if (els.modalCancel())
      els.modalCancel().addEventListener("click", closePreview);
    if (els.modalPrint())
      els.modalPrint().addEventListener("click", () => {
        // Print uses a dedicated print template (PDF-like), separate from the on-screen email preview.
        try {
          const printHtml = buildWaitlistPrintHtml();
          openPrintWindow(printHtml);
        } catch (e) {
          console.warn(
            "[GRASP][waitlist] print template failed; falling back to preview HTML",
            e,
          );
          openPrintWindow(_previewLastHtml);
        }
      });
    if (els.modalSubmit())
      els.modalSubmit().addEventListener("click", submitWaitlist);

    bindClearSavedDataButton();

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
      try {
        validateCurrentStep();
      } catch (_) {}
    }, 50);
  }

  document.addEventListener("DOMContentLoaded", () => {
    init().catch((err) => {
      console.error("[GRASP][waitlist] init error:", err);
      alert("Could not initialize wait list form. Please refresh the page.");
    });
  });
})();

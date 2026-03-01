// enrollment app script: dynamic multi-step wizard + encrypted local storage + submission
// Designed to run on the Greenland Recreational site. Assumes css/style.css and css/enrollment.css are loaded.

// TODO(refactor): enrollment-app.js is large; regroup functions by concern:
// - config + bootstrapping (initWizard)
// - storage/drafts (LS + IndexedDB + reconciliation)
// - derived field syncing
// - UI rendering (wizard/fields)
// - validation + navigation
// - preview modal + submission
// Consider extracting shared draft + utilities into shared modules.

const FORM_ID = "grasp_enrollment_2025";
const STORAGE_KEY_ENCRYPTED = "graspEnrollmentEncryptedData";
const STORAGE_KEY_SESSION_ID = "graspEnrollmentSessionId";
const STORAGE_DB_NAME = "graspEnrollmentDB";
const STORAGE_DB_STORE = "sessions";

// NOTE: This secret key is embedded in the front-end, so it provides
// obfuscation and basic protection if the device is lost, but it is not
// equivalent to server-side encryption. For a production deployment,
// consider deriving a key from a user-provided passphrase or device-specific secret.
const SECRET_KEY_STRING = "grasp-enrollment-demo-secret-32-chars!!";

let cryptoKeyCache = null;

/**
 * Get or import the AES-GCM CryptoKey from SECRET_KEY_STRING.
 */
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

/**
 * Encrypt data (ArrayBuffer or string) to { iv, ciphertext } Base64.
 */
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
    data: btoa(String.fromCharCode(...new Uint8Array(ciphertext))),
  };
}

/**
 * Decrypt data from { iv, data } Base64 to string.
 */
async function decryptData(encrypted) {
  try {
    const key = await getCryptoKey();
    const ivBytes = Uint8Array.from(atob(encrypted.iv), (c) => c.charCodeAt(0));
    const dataBytes = Uint8Array.from(atob(encrypted.data), (c) =>
      c.charCodeAt(0),
    );

    const decrypted = await window.crypto.subtle.decrypt(
      { name: "AES-GCM", iv: ivBytes },
      key,
      dataBytes,
    );

    const decoder = new TextDecoder();
    return decoder.decode(decrypted);
  } catch (err) {
    console.error("decryptData error", err);
    return null;
  }
}

/**
 * IndexedDB helpers for storing encrypted form sessions
 */
function openIndexedDB() {
  return new Promise((resolve, reject) => {
    const request = window.indexedDB.open(STORAGE_DB_NAME, 1);

    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains(STORAGE_DB_STORE)) {
        db.createObjectStore(STORAGE_DB_STORE, { keyPath: "sessionId" });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function saveSessionToIndexedDB(sessionId, encryptedPayload) {
  try {
    const db = await openIndexedDB();
    const tx = db.transaction(STORAGE_DB_STORE, "readwrite");
    const store = tx.objectStore(STORAGE_DB_STORE);
    await new Promise((resolve, reject) => {
      const req = store.put({ sessionId, payload: encryptedPayload });
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error);
    });
    db.close();
  } catch (err) {
    console.warn("IndexedDB save failed", err);
  }
}

async function loadSessionFromIndexedDB(sessionId) {
  try {
    const db = await openIndexedDB();
    const tx = db.transaction(STORAGE_DB_STORE, "readonly");
    const store = tx.objectStore(STORAGE_DB_STORE);

    const payload = await new Promise((resolve, reject) => {
      const req = store.get(sessionId);
      req.onsuccess = () => resolve(req.result ? req.result.payload : null);
      req.onerror = () => reject(req.error);
    });

    db.close();
    return payload;
  } catch (err) {
    console.warn("IndexedDB load failed", err);
    return null;
  }
}

/**
 * Utility: Generate a pseudo-random session ID
 */
function generateSessionId() {
  const arr = new Uint8Array(16);
  window.crypto.getRandomValues(arr);
  return Array.from(arr)
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

/**
 * Utility: get query params
 */
function getQueryParams() {
  const params = {};
  const search = window.location.search.substring(1);
  if (!search) return params;

  search.split("&").forEach((pair) => {
    const [k, v] = pair.split("=");
    if (!k) return;
    params[decodeURIComponent(k)] = v ? decodeURIComponent(v) : "";
  });

  return params;
}

/**
 * Global state
 */
let config = null; // enrollment-fields.json contents
let currentStepIndex = 0;
let formState = {}; // name -> value
let sessionId = null;
let isDebugMode = false;

/**
 * Apply default values from config for fields that are empty.
 */
function applyFieldDefaults() {
  if (!config || !config.steps) return;

  (config.steps || []).forEach((step) => {
    (step.groups || []).forEach((group) => {
      (group.fields || []).forEach((fieldDef) => {
        if (!fieldDef || !fieldDef.name) return;
        if (typeof fieldDef.default === "undefined") return;

        const name = fieldDef.name;
        const current = formState[name];
        if (
          current === undefined ||
          current === null ||
          String(current).trim() === ""
        ) {
          formState[name] = fieldDef.default;
        }
      });
    });
  });
}

/**
 * Helper to get an element by ID
 */
function byId(id) {
  return document.getElementById(id);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/**
 * Set a user-facing status message at the top of the form
 */
function setStatus(message, type = "info") {
  // Current markup uses #grasp-wizard-status. Keep a fallback for older IDs.
  const el = byId("grasp-wizard-status") || byId("grasp-form-status");
  if (!el) return;

  el.textContent = message || "";
  // Align to existing CSS classes (.grasp-wizard-status.success/.error).
  el.classList.remove("success", "error");
  if (!message) return;
  if (type === "success") el.classList.add("success");
  if (type === "error") el.classList.add("error");
}

// Render the clickable step tabs at the top of the wizard.
function renderStepList() {
  const list = byId("grasp-wizard-step-list");
  const header = byId("grasp-wizard-step-header");
  if (!list || !config) return;

  list.innerHTML = "";

  (config.steps || []).forEach((step, idx) => {
    const li = document.createElement("li");
    li.textContent = idx + 1 + ". " + step.title;

    if (idx === currentStepIndex) {
      li.classList.add("active-step");
    } else if (idx < currentStepIndex) {
      li.classList.add("completed-step");
    }

    // Make each step behave like a button
    li.dataset.stepIndex = String(idx);
    li.tabIndex = 0;
    li.role = "button";

    // Clicking a tab always navigates to that step.
    li.addEventListener("click", () => {
      setStatus("");
      currentStepIndex = idx;
      renderCurrentStep();
    });

    // Keyboard access (space / enter)
    li.addEventListener("keypress", (ev) => {
      if (ev.key === " " || ev.key === "Enter") {
        ev.preventDefault();
        li.click();
      }
    });

    list.appendChild(li);
  });

  // Ensure the header bar is visible once we have steps.
  if (header) {
    header.classList.remove("hidden");
  }

  updateProgressBar();
}

/* current - can roll back this functionality if needed
function updateProgressBar() {
  const fill = byId("grasp-wizard-progress-fill");
  if (!fill || !config) return;
  const total = config.steps.length || 1;
  const pct = ((currentStepIndex + 1) / total) * 100;
  fill.style.width = pct.toFixed(0) + "%";
}
  */

// Update the green progress bar at the very top.
function updateProgressBar() {
  // Current markup uses #grasp-wizard-progress-fill.
  // Keep a fallback for older IDs.
  const bar =
    byId("grasp-wizard-progress-fill") || byId("grasp-wizard-progress-bar");
  if (!bar || !config || !config.steps || !config.steps.length) {
    return;
  }

  const pct = ((currentStepIndex + 1) / config.steps.length) * 100;
  bar.style.width = pct + "%";
}

/**
 * Load config JSON
 */
async function loadConfig() {
  // IMPORTANT: use a relative path so deployments under a subdirectory
  // (e.g. https://greenlandrecreational.com/staging/) resolve correctly.
  const res = await fetch("../config/enrollment-fields.json", {
    cache: "no-store",
  });
  if (!res.ok) {
    throw new Error("Failed to load enrollment-fields.json");
  }
  config = await res.json();
  // Expose for debug tools and cross-form helpers
  window.config = config;

}

/**
 * Local storage helpers
 */
function getEncryptedFromLocalStorage() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY_ENCRYPTED);
    if (!raw) return null;
    return JSON.parse(raw);
  } catch (err) {
    console.warn("Failed to parse encrypted data from localStorage", err);
    return null;
  }
}

function setEncryptedToLocalStorage(obj) {
  try {
    window.localStorage.setItem(STORAGE_KEY_ENCRYPTED, JSON.stringify(obj));
  } catch (err) {
    console.warn("Failed to write encrypted data to localStorage", err);
  }
}

function clearLocalStorage() {
  try {
    window.localStorage.removeItem(STORAGE_KEY_ENCRYPTED);
    window.localStorage.removeItem(STORAGE_KEY_SESSION_ID);
  } catch (err) {
    console.warn("Failed to clear localStorage", err);
  }
}

/**
 * Serialize the current form state
 */
let draftVersion = 0;

function serializeFormState() {
  // increment version on each save
  draftVersion = Math.max(1, Number(draftVersion || 0) + 1);

  return {
    formId: FORM_ID,
    sessionId,
    version: draftVersion,
    data: { ...formState },
    updatedAt: new Date().toISOString(),
  };
}

/**
 * Save the current state (encrypted) to local storage + IndexedDB
 */
async function saveDraft() {
  try {
    const payload = serializeFormState();

    // 1) Encrypt
    const encrypted = await encryptData(JSON.stringify(payload));

    // 2) Persist to LocalStorage
    setEncryptedToLocalStorage(encrypted);
    localStorage.setItem(STORAGE_KEY_SESSION_ID, sessionId);

    // 3) Persist to IndexedDB
    await saveSessionToIndexedDB(sessionId, encrypted);

    // 4) NOW update shared package draft (common fields)
    try {
      if (window.GRASP_PACKAGE_DRAFT?.upsertRegistrant) {
        await window.GRASP_PACKAGE_DRAFT.upsertRegistrant(payload.data, {
          onlyFillMissing: false,
        });
      }
    } catch (e) {
      console.warn("[GRASP][enrollment] packageDraft upsert failed", e);
      // (don’t throw — draft save should still be considered successful)
    }

    // 5) Whatever you already do next (toast, UI state, etc.)
    return true;
  } catch (err) {
    console.error("[GRASP][enrollment] Failed to save draft:", err);
    return false;
  }
}

/**
 * Load any previously saved draft
 */
async function loadDraft() {
  // Try to recover even if sessionId key is missing but encrypted exists.
  const storedSessionId = window.localStorage.getItem(STORAGE_KEY_SESSION_ID);

  let localEncrypted = getEncryptedFromLocalStorage();
  let localPayload = null;

  if (localEncrypted) {
    const decrypted = await decryptData(localEncrypted);
    if (decrypted) {
      try {
        localPayload = JSON.parse(decrypted);
        if (localPayload?.sessionId && !storedSessionId) {
          sessionId = localPayload.sessionId;
          window.localStorage.setItem(STORAGE_KEY_SESSION_ID, sessionId);
        }
      } catch (_) {}
    }
  }

  if (storedSessionId) sessionId = storedSessionId;

  let idbEncrypted = null;
  let idbPayload = null;

  if (sessionId) {
    idbEncrypted = await loadSessionFromIndexedDB(sessionId);
    if (idbEncrypted) {
      const decrypted = await decryptData(idbEncrypted);
      if (decrypted) {
        try {
          idbPayload = JSON.parse(decrypted);
        } catch (_) {}
      }
    }
  }

  // choose newest by version, then updatedAt
  function meta(p) {
    return {
      version: Number.isFinite(Number(p?.version)) ? Number(p.version) : 0,
      updatedAtMs: Date.parse(p?.updatedAt || "") || 0,
    };
  }

  let chosenEncrypted = null;
  let chosenPayload = null;

  const candidates = [];
  if (
    localPayload?.formId === FORM_ID &&
    localPayload?.data &&
    localEncrypted
  ) {
    candidates.push({ payload: localPayload, encrypted: localEncrypted });
  }
  if (idbPayload?.formId === FORM_ID && idbPayload?.data && idbEncrypted) {
    candidates.push({ payload: idbPayload, encrypted: idbEncrypted });
  }

  if (candidates.length === 0) return;

  if (candidates.length === 1) {
    chosenPayload = candidates[0].payload;
    chosenEncrypted = candidates[0].encrypted;
  } else {
    const a = candidates[0],
      b = candidates[1];
    const ma = meta(a.payload),
      mb = meta(b.payload);
    if (ma.version !== mb.version) {
      chosenPayload = ma.version > mb.version ? a.payload : b.payload;
      chosenEncrypted = ma.version > mb.version ? a.encrypted : b.encrypted;
    } else {
      chosenPayload = ma.updatedAtMs >= mb.updatedAtMs ? a.payload : b.payload;
      chosenEncrypted =
        ma.updatedAtMs >= mb.updatedAtMs ? a.encrypted : b.encrypted;
    }
  }

  // apply
  sessionId = chosenPayload.sessionId || sessionId;
  if (sessionId) window.localStorage.setItem(STORAGE_KEY_SESSION_ID, sessionId);

  formState = { ...(chosenPayload.data || {}) };
  draftVersion = Number.isFinite(Number(chosenPayload.version))
    ? Number(chosenPayload.version)
    : 0;

  // keep stores synced to chosen
  try {
    setEncryptedToLocalStorage(chosenEncrypted);
  } catch (_) {}
  if (sessionId) {
    await saveSessionToIndexedDB(sessionId, chosenEncrypted);
  }

  // push shared fields into package draft (non-destructive)
  try {
    if (window.GRASP_PACKAGE_DRAFT?.upsertRegistrant) {
      await window.GRASP_PACKAGE_DRAFT.upsertRegistrant(formState, {
        onlyFillMissing: true,
      });
    }
  } catch (e) {
    console.warn("[GRASP][enrollment] packageDraft sync failed", e);
  }

  // Expose for cross-form helpers / debug tooling.
  try {
    window.formState = formState;
    window.sessionId = sessionId;
  } catch (e2) {
    /* ignore */
  }
}

/**
 * Derived field logic (names + addresses)
 */

// Build full name from parts
function buildFullName(first, middle, last) {
  const parts = [];
  if (first) parts.push(first.trim());
  if (middle) parts.push(middle.trim());
  if (last) parts.push(last.trim());
  return parts.join(" ");
}

// [GRASP-DERIVED] Synchronise derived name fields
function syncDerivedNames() {
  const childFirst = formState["child_first_name"] || "";
  const childMiddle = formState["child_middle_name_or_initial"] || "";
  const childLast = formState["child_last_name"] || "";
  formState["child_name"] = buildFullName(childFirst, childMiddle, childLast);

  const p1First = formState["parent1_first_name"] || "";
  const p1Last = formState["parent1_last_name"] || "";
  formState["parent1_name"] = buildFullName(p1First, "", p1Last);

  const p2First = formState["parent2_first_name"] || "";
  const p2Last = formState["parent2_last_name"] || "";
  formState["parent2_name"] = buildFullName(p2First, "", p2Last);
}

// Build multiline address and full postal
function buildAddressAndPostal(contextPrefix) {
  const street = (formState[contextPrefix + "_street"] || "").trim();
  const unit = (formState[contextPrefix + "_unit"] || "").trim();
  const city = (formState[contextPrefix + "_city"] || "").trim();
  const province = (formState[contextPrefix + "_province"] || "").trim();
  const p1 = (formState[contextPrefix + "_postal1"] || "").trim();
  const p2 = (formState[contextPrefix + "_postal2"] || "").trim();

  const lines = [];
  const line1 = unit ? `${unit} ${street}`.trim() : street;
  if (line1) lines.push(line1);

  const line2Parts = [];
  if (city) line2Parts.push(city);
  if (province) line2Parts.push(province);
  const fullPostal = p1 && p2 ? `${p1} ${p2}` : p1 || p2;
  if (fullPostal) line2Parts.push(fullPostal);

  if (line2Parts.length) {
    lines.push(line2Parts.join(", "));
  }

  return {
    address: lines.join("\n"),
    postal: fullPostal,
  };
}

// [GRASP-DERIVED] Synchronise all derived addresses
function syncDerivedAddresses() {
  // Map split-field contexts to the *actual* hidden derived field names
  // used in config/enrollment-fields.json.
  const mappings = [
    {
      ctx: "parent1_home",
      address: "parent1_home_address",
      postal: "parent1_postal_code",
    },
    {
      ctx: "parent1_work",
      address: "parent1_work_address",
      postal: "parent1_work_postal_code",
    },
    {
      ctx: "parent2_home",
      address: "parent2_home_address",
      postal: "parent2_postal_code",
    },
    {
      ctx: "parent2_work",
      address: "parent2_work_address",
      postal: "parent2_work_postal_code",
    },
    { ctx: "doctor", address: "doctor_address", postal: "doctor_postal_code" },
  ];

  mappings.forEach(({ ctx, address, postal }) => {
    const result = buildAddressAndPostal(ctx);
    formState[address] = result.address;
    formState[postal] = result.postal;
  });
}

// [GRASP-DERIVED] Wrapper to sync all derived fields
function syncDerivedFields() {
  syncDerivedNames();
  syncDerivedAddresses();
}

/**
 * Parent 2 "same as Parent 1 home address" logic
 */
function applyParent2SameAsParent1() {
  const same = !!formState["parent2_home_same_as_parent1"];

  const fields = ["street", "unit", "city", "province", "postal1", "postal2"];

  if (same) {
    fields.forEach((suffix) => {
      const srcKey = `parent1_home_${suffix}`;
      const dstKey = `parent2_home_${suffix}`;
      const value = formState[srcKey] || "";
      formState[dstKey] = value;

      const input = byId("field_" + dstKey);
      if (input) {
        input.value = value;
      }
    });
  } else {
    fields.forEach((suffix) => {
      const dstKey = `parent2_home_${suffix}`;
      formState[dstKey] = "";
      const input = byId("field_" + dstKey);
      if (input) {
        input.value = "";
      }
    });
  }

  syncDerivedAddresses();
}

/**
 * Generic helpers for reading/writing field values
 */
function getFieldValue(name) {
  return formState[name] ?? "";
}

let draftTimer = null;
function scheduleDraftSave() {
  if (draftTimer) {
    clearTimeout(draftTimer);
  }
  draftTimer = setTimeout(() => {
    saveDraft().catch((err) => console.error("saveDraft error", err));
  }, 800);
}

function setFieldValue(name, value) {
  formState[name] = value;

  if (name === "parent2_home_same_as_parent1") {
    applyParent2SameAsParent1();
  }

  // existing local save call if present...
  scheduleDraftSave();
  // [GRASP-DERIVED] Keep derived names/addresses
  // in sync whenever base fields change
  syncDerivedFields();
}

// [GRASP-POSTAL-UX] Helpers for combined postal-code UI (two 3-character inputs with one label).
function isPostalHalfFieldName(fieldName) {
  return /_postal[12]$/i.test(fieldName || "");
}

function isPostalFirstHalfFieldName(fieldName) {
  return /_postal1$/i.test(fieldName || "");
}

// Build a single, user-friendly label for a postal-code pair based on the first half's field name.
function getPostalPairLabel(fieldDef) {
  const name = fieldDef && fieldDef.name ? fieldDef.name : "";
  switch (name) {
    case "parent1_home_postal1":
      return "Home Postal Code (A1A 1A1)";
    case "parent1_work_postal1":
      return "Parent Work / School Postal Code (A1A 1A1)";
    case "parent2_home_postal1":
      return "Parent / Guardian 2 Home Postal Code (A1A 1A1)";
    case "parent2_work_postal1":
      return "Parent / Guardian 2 Work / School Postal Code (A1A 1A1)";
    case "doctor_postal1":
      return "Doctor's Postal Code (A1A 1A1)";
    default:
      // Fallback: use existing label but normalise the hint
      const base =
        fieldDef && fieldDef.label
          ? String(fieldDef.label).split("(")[0].trim()
          : "Postal Code";
      return base + " (A1A 1A1)";
  }
}

// [GRASP-POSTAL-UX] Create one half of a postal code input (A1A or 1A1).
function createPostalHalfControl(fieldDef) {
  const partWrapper = document.createElement("div");
  partWrapper.className = "grasp-postal-part";

  const value = getFieldValue(fieldDef.name);

  const input = document.createElement("input");
  input.className = "grasp-input grasp-postal-input";
  input.id = "field_" + fieldDef.name;
  input.type = fieldDef.inputType || fieldDef.type || "text";
  input.value = value;

  // Optional placeholder hint (e.g., A1A / 1A1)
  if (fieldDef.placeholder) {
    input.placeholder = fieldDef.placeholder;
  }

  // Visually and technically limit to 3 characters.
  const maxLen = fieldDef.maxLength || 3;
  input.maxLength = maxLen;
  input.size = maxLen;
  input.style.width = maxLen + 1 + "ch";

  input.addEventListener("input", () => {
    let currentValue = input.value;

    // Re-use the existing postal normalisation helper if available.
    if (
      typeof window !== "undefined" &&
      window.GRASP_POSTAL &&
      typeof window.GRASP_POSTAL.normalizeInput === "function"
    ) {
      const normalized = window.GRASP_POSTAL.normalizeInput(
        fieldDef.name,
        currentValue,
      );
      if (normalized !== currentValue) {
        currentValue = normalized;
        input.value = normalized;
      }
    }

    // Update form state
    setFieldValue(fieldDef.name, currentValue);

    // Auto-advance from *_postal1 to *_postal2 once 3 chars entered.
    if (/_postal1$/i.test(fieldDef.name) && currentValue.length === 3) {
      const nextName = fieldDef.name.replace(/_postal1$/i, "_postal2");
      const nextInput = document.getElementById("field_" + nextName);
      if (nextInput && typeof nextInput.focus === "function") {
        nextInput.focus();
        if (typeof nextInput.select === "function") {
          nextInput.select();
        }
      }
    }
  });

  partWrapper.appendChild(input);

  const error = document.createElement("div");
  error.className = "grasp-error-text";
  error.id = "error_" + fieldDef.name;
  partWrapper.appendChild(error);

  return partWrapper;
}

// [GRASP-POSTAL-UX] Render a combined postal-code row containing both halves on one line.
function createPostalRow(postal1Def, postal2Def) {
  const wrapper = document.createElement("div");
  wrapper.className = "grasp-field-row grasp-postal-row";
  wrapper.dataset.fieldName = postal1Def.name;

  const label = document.createElement("label");
  label.className = "grasp-field-label";
  label.htmlFor = "field_" + postal1Def.name;
  label.textContent = getPostalPairLabel(postal1Def);

  if (postal1Def.required || (postal2Def && postal2Def.required)) {
    const req = document.createElement("span");
    req.className = "grasp-required";
    req.textContent = " *";
    label.appendChild(req);
  }

  // Postal rows are always input rows; always render the label.
  wrapper.appendChild(label);

  if (postal1Def.helpText) {
    const help = document.createElement("div");
    help.className = "grasp-field-help";
    help.textContent = postal1Def.helpText;
    wrapper.appendChild(help);
  }

  const inputsRow = document.createElement("div");
  inputsRow.className = "grasp-postal-inputs";
  // Inline styles ensure reasonable layout even if CSS is not yet updated.
  inputsRow.style.display = "flex";
  inputsRow.style.flexWrap = "nowrap";
  inputsRow.style.alignItems = "flex-start";
  inputsRow.style.gap = "0.5rem";

  const part1 = createPostalHalfControl(postal1Def);
  inputsRow.appendChild(part1);

  if (postal2Def) {
    const part2 = createPostalHalfControl(postal2Def);
    inputsRow.appendChild(part2);
  }

  wrapper.appendChild(inputsRow);

  return wrapper;
}

function createFieldRow(fieldDef) {
  const wrapper = document.createElement("div");
  wrapper.className = "grasp-field-row";
  wrapper.dataset.fieldName = fieldDef.name;

  // [GRASP-A11Y] For radio groups, use a non-label heading element so we
  // don't have a <label> without a corresponding control. The group is still
  // labelled via aria-labelledby on the radiogroup container.
  const labelText = (fieldDef.type === "static" && Object.prototype.hasOwnProperty.call(fieldDef, "label") && String(fieldDef.label).trim() === "")
    ? ""
    : (fieldDef.label || fieldDef.name);
  let label;

  if (fieldDef.type === "radio" || fieldDef.type === "static") {
    label = document.createElement("div");
    label.className = "grasp-field-label";
    const labelId = "label_" + fieldDef.name;
    label.id = labelId;
    label.textContent = labelText;
  } else {
    label = document.createElement("label");
    label.className = "grasp-field-label";
    label.htmlFor = "field_" + fieldDef.name;
    label.textContent = labelText;
  }

  if (fieldDef.required) {
    const req = document.createElement("span");
    req.className = "grasp-required";
    req.textContent = " *";
    label.appendChild(req);
  }

  wrapper.appendChild(label);

  if (fieldDef.helpText) {
    const help = document.createElement("div");
    help.className = "grasp-field-help";
    help.textContent = fieldDef.helpText;
    wrapper.appendChild(help);
  }

  let control = null;
  const value = getFieldValue(fieldDef.name);

  if (fieldDef.type === "static") {
    control = document.createElement("div");
    control.className = "grasp-input grasp-static";
    control.id = "field_" + fieldDef.name;
    // Display-only value (derived or prefilled from earlier steps)
// If "html" is provided, render it as markup (used for policy blocks).
if (fieldDef.html) {
  control.innerHTML = fieldDef.html;
} else {
  control.textContent = value;
}
    wrapper.appendChild(control);
  } else
  if (fieldDef.type === "textarea") {
    control = document.createElement("textarea");
    control.className = "grasp-textarea";
    control.id = "field_" + fieldDef.name;
    control.value = value;
    if (fieldDef.placeholder) {
      control.placeholder = fieldDef.placeholder;
    }
    control.addEventListener("input", () => {
      setFieldValue(fieldDef.name, control.value);
    });
    wrapper.appendChild(control);
  } else if (fieldDef.type === "select") {
    control = document.createElement("select");
    control.className = "grasp-select";
    control.id = "field_" + fieldDef.name;

    const placeholderOption = document.createElement("option");
    placeholderOption.value = "";
    placeholderOption.textContent = "-- Please choose --";
    control.appendChild(placeholderOption);

    (fieldDef.options || []).forEach((opt) => {
      const o = document.createElement("option");
      o.value = opt.value;
      o.textContent = opt.label;
      control.appendChild(o);
    });

    control.value = value;
    control.addEventListener("change", () => {
      setFieldValue(fieldDef.name, control.value);
    });
    wrapper.appendChild(control);
  } else if (fieldDef.type === "radio") {
    const group = document.createElement("div");
    group.className = "grasp-radio-group";

    // Accessibility: tie this group to the main label above.
    const labelId = "label_" + fieldDef.name;
    group.setAttribute("role", "radiogroup");
    group.setAttribute("aria-labelledby", labelId);

    (fieldDef.options || []).forEach((opt, idx) => {
      const optId = "field_" + fieldDef.name + "_" + idx;
      const span = document.createElement("label");
      span.className = "grasp-radio-option";

      const input = document.createElement("input");
      input.type = "radio";
      input.name = "field_" + fieldDef.name;
      input.id = optId;
      input.value = opt.value;
      input.checked = value === opt.value;

      input.addEventListener("change", () => {
        if (input.checked) {
          setFieldValue(fieldDef.name, opt.value);
        }
      });

      const txt = document.createTextNode(" " + opt.label);
      span.appendChild(input);
      span.appendChild(txt);
      group.appendChild(span);
    });

    wrapper.appendChild(group);
  } else if (fieldDef.type === "checkbox") {
    const group = document.createElement("div");
    group.className = "grasp-checkbox-group";

    const input = document.createElement("input");
    input.type = "checkbox";
    input.id = "field_" + fieldDef.name;
    input.checked = !!value;

    input.addEventListener("change", () => {
      setFieldValue(fieldDef.name, input.checked);
    });

    const lbl = document.createElement("label");
    lbl.className = "grasp-checkbox-option";
    lbl.htmlFor = input.id;
    lbl.appendChild(input);
    lbl.appendChild(
      document.createTextNode(" " + (fieldDef.checkboxLabel || "Yes")),
    );

    group.appendChild(lbl);
    wrapper.appendChild(group);
  } else {
    // default: text/date/email/tel/etc.
    control = document.createElement("input");
    control.className = "grasp-input";
    control.id = "field_" + fieldDef.name;
    control.type = fieldDef.inputType || fieldDef.type || "text";
    control.value = value;
    // [GRASP-POSTAL-UX] Honour any maxLength hint for text inputs (e.g., short 3-character fields).
    if (typeof fieldDef.maxLength === "number") {
      control.maxLength = fieldDef.maxLength;
    }

    if (fieldDef.placeholder) {
      control.placeholder = fieldDef.placeholder;
    }

    control.addEventListener("input", () => {
      let currentValue = control.value;

      // [GRASP-POSTAL] Normalize postal halves (A1A / 1A1) if helper is available.
      if (
        typeof window !== "undefined" &&
        window.GRASP_POSTAL &&
        typeof window.GRASP_POSTAL.normalizeInput === "function"
      ) {
        const normalized = window.GRASP_POSTAL.normalizeInput(
          fieldDef.name,
          currentValue,
        );
        if (normalized !== currentValue) {
          currentValue = normalized;
          control.value = normalized;
        }
      }

      // Update form state
      setFieldValue(fieldDef.name, currentValue);

      // Auto-focus on the second half when first half has 3 chars
      if (/_postal1$/i.test(fieldDef.name) && currentValue.length === 3) {
        const nextName = fieldDef.name.replace(/_postal1$/i, "_postal2");
        const nextInput = document.getElementById("field_" + nextName);
        if (nextInput && typeof nextInput.focus === "function") {
          nextInput.focus();
          if (typeof nextInput.select === "function") {
            nextInput.select();
          }
        }
      }
    });

    wrapper.appendChild(control);
  }

  const error = document.createElement("div");
  error.className = "grasp-error-text";
  error.id = "error_" + fieldDef.name;
  wrapper.appendChild(error);

  return wrapper;
}

/**
 * Render the current step
 */
/*
function renderCurrentStep() {
  const step = config.steps[currentStepIndex];
  const container = byId("grasp-wizard-step-content");
  if (!step || !container) return;

  container.innerHTML = "";

  const title = document.createElement("div");
  title.className = "grasp-wizard-step-title";
  title.textContent = step.title;
  container.appendChild(title);

  if (step.description) {
    const desc = document.createElement("div");
    desc.className = "grasp-wizard-step-description";
    desc.textContent = step.description;
    container.appendChild(desc);
  }

  step.groups.forEach((group) => {
    const groupEl = document.createElement("section");
    groupEl.className = "grasp-form-group";

    const gTitle = document.createElement("div");
    gTitle.className = "grasp-form-group-title";
    gTitle.textContent = group.title;
    groupEl.appendChild(gTitle);


    // Render group-level contentBlocks (policy/static paragraphs) above fields (online UI only).
    // Email/PDF already render contentBlocks server-side; this is purely for the web form.
    (group.contentBlocks || []).forEach((cb) => {
      if (!cb || !cb.html) return;
      const blockWrap = document.createElement("div");
      blockWrap.className = "grasp-content-block";

      // Match existing policy styling used by static fields (e.g., Disclosure of Information Policy)
      const safeTitle = (cb.title || "").trim();
      if (safeTitle) {
        blockWrap.innerHTML = `<div class="grasp-policy-block"><div class="grasp-policy-heading"><u><b>${escapeHtml(safeTitle)}</b></u></div>${cb.html}</div>`;
      } else {
        blockWrap.innerHTML = cb.html;
      }

      groupEl.appendChild(blockWrap);
    });

    (group.fields || []).forEach((fieldDef, index, allFields) => {
      // [GRASP-HIDDEN] Skip hidden/derived fields when rendering the UI.
      // They still exist in config + formState for email/DB use.
      if (fieldDef.type === "hidden") {
        return;
      }

      const fieldName = fieldDef.name || "";

      // [GRASP-POSTAL-UX] Render postal halves as a single combined row with one label.
      // *_postal2 fields are skipped here because they are rendered together with *_postal1.
      if (isPostalHalfFieldName(fieldName)) {
        if (!isPostalFirstHalfFieldName(fieldName)) {
          // This is the second half (e.g., *_postal2); it will be rendered
          // alongside its *_postal1 partner, so do nothing here.
          return;
        }

        const partnerName = fieldName.replace(/_postal1$/i, "_postal2");
        const partnerDef =
          (allFields || []).find((f) => f && f.name === partnerName) || null;

        const postalRow = createPostalRow(fieldDef, partnerDef);
        groupEl.appendChild(postalRow);
        return;
      }

      const row = createFieldRow(fieldDef);
      groupEl.appendChild(row);
    });

    container.appendChild(groupEl);
  });

  updateNavButtons();
}
  */
// Render the current step's content (title, groups, fields).
function renderCurrentStep() {
  if (!config || !config.steps || !config.steps.length) return;

  const step = config.steps[currentStepIndex];
  const container = byId("grasp-wizard-step-content");
  if (!step || !container) return;

  container.innerHTML = "";

  // Step title
  const title = document.createElement("div");
  title.className = "grasp-wizard-step-title";
  title.textContent = step.title;
  container.appendChild(title);

  // Optional step description
  if (step.description) {
    const desc = document.createElement("div");
    desc.className = "grasp-wizard-step-description";
    desc.textContent = step.description;
    container.appendChild(desc);
  }

  // Step groups + fields
  (step.groups || []).forEach((group) => {
    const groupEl = document.createElement("section");
    groupEl.className = "grasp-form-group";

    const gTitle = document.createElement("div");
    gTitle.className = "grasp-form-group-title";
    gTitle.textContent = group.title;
    groupEl.appendChild(gTitle);


    // Render group-level contentBlocks (policy/static paragraphs) above fields (online UI only).
    // Email/PDF already render contentBlocks server-side; this is purely for the web form.
    (group.contentBlocks || []).forEach((cb) => {
      if (!cb || !cb.html) return;
      const blockWrap = document.createElement("div");
      blockWrap.className = "grasp-content-block";

      // Match existing policy styling used by static fields (e.g., Disclosure of Information Policy)
      const safeTitle = (cb.title || "").trim();
      if (safeTitle) {
        blockWrap.innerHTML = `<div class="grasp-policy-block"><div class="grasp-policy-heading"><u><b>${escapeHtml(safeTitle)}</b></u></div>${cb.html}</div>`;
      } else {
        blockWrap.innerHTML = cb.html;
      }

      groupEl.appendChild(blockWrap);
    });

    (group.fields || []).forEach((fieldDef, index, allFields) => {
      // Skip hidden/derived fields in the UI (still exist in formState/email/DB)
      if (fieldDef.type === "hidden") return;

      const fieldName = fieldDef.name || "";

      // Combined postal-code UI: *_postal1 + *_postal2 on one line with one label
      if (isPostalHalfFieldName(fieldName)) {
        // Only render the first half; the second half is rendered alongside it
        if (!isPostalFirstHalfFieldName(fieldName)) return;

        const partnerName = fieldName.replace(/_postal1$/i, "_postal2");
        const partnerDef =
          (allFields || []).find((f) => f && f.name === partnerName) || null;

        const postalRow = createPostalRow(fieldDef, partnerDef);
        groupEl.appendChild(postalRow);
        return;
      }

      // Normal field row
      const row = createFieldRow(fieldDef);
      groupEl.appendChild(row);
    });

    container.appendChild(groupEl);
  });

  // Keep step tabs + progress bar in sync
  renderStepList();
  updateNavButtons();
}

/**
 * Step navigation & validation
 */
function validateStep(stepIndex) {
  const step = config.steps[stepIndex];
  if (!step) return true;

  let valid = true;

  (step.groups || []).forEach((group) => {
    (group.fields || []).forEach((fieldDef) => {
      // [GRASP-HIDDEN] Do not validate hidden
      if (fieldDef.type === "hidden") {
        return;
      }

      const name = fieldDef.name;
      const value = formState[name];

      const errorEl = byId("error_" + name);
      if (errorEl) {
        errorEl.textContent = "";
      }

      // Postal-specific validation
      let postalResult = null;
      if (
        typeof window !== "undefined" &&
        window.GRASP_POSTAL &&
        typeof window.GRASP_POSTAL.validateField === "function"
      ) {
        postalResult = window.GRASP_POSTAL.validateField(fieldDef, value);
      }

      if (postalResult && !postalResult.ok) {
        valid = false;
        if (errorEl) {
          errorEl.textContent = postalResult.message || "Invalid postal code.";
        }
        return;
      }

      if (fieldDef.required) {
        if (fieldDef.type === "radio") {
          const selected = (fieldDef.options || []).some(
            (opt) => formState[name] === opt.value,
          );
          if (!selected) {
            valid = false;
            if (errorEl) {
              errorEl.textContent = "This field is required.";
            }
          }
        } else if (
          fieldDef.type === "checkbox" &&
          fieldDef.enforceChecked === true
        ) {
          if (!value) {
            valid = false;
            if (errorEl) {
              errorEl.textContent = "You must check this box to proceed.";
            }
          }
        } else if (
          fieldDef.type !== "checkbox" &&
          (value === undefined || value === null || String(value).trim() === "")
        ) {
          valid = false;
          if (errorEl) {
            errorEl.textContent = "This field is required.";
          }
        }
      }
    });
  });

  return valid;
}

/**
 * Navigation handlers
 */
function goToStep(targetIndex, { skipValidation = false } = {}) {
  if (
    !config ||
    !config.steps ||
    targetIndex < 0 ||
    targetIndex >= config.steps.length
  ) {
    return;
  }

  // Only validate when moving forward via Next / Prev.
  if (!skipValidation && targetIndex > currentStepIndex) {
    const valid = validateStep(currentStepIndex);
    if (!valid) {
      setStatus("Please complete required fields before continuing.", "error");
      return;
    }
  }

  setStatus("");
  currentStepIndex = targetIndex;
  renderCurrentStep();
}

/*
function handleNext() {
  if (!validateStep(currentStepIndex)) {
    setStatus(
      "Please fix the errors on this step before continuing.",
      "error"
    );
    return;
  }

  setStatus("");

  if (currentStepIndex < config.steps.length - 1) {
    currentStepIndex += 1;
    renderCurrentStep();
  }
}

function handlePrev() {
  if (currentStepIndex > 0) {
    currentStepIndex -= 1;
    renderCurrentStep();
  }
}
  */
async function handlePrev() {
  await goToStep(currentStepIndex - 1);
}

async function handleNext() {
  await goToStep(currentStepIndex + 1);
}

function updateNavButtons() {
  const prevBtn = byId("grasp-btn-prev");
  const nextBtn = byId("grasp-btn-next");
  const saveBtn = byId("grasp-btn-save");
  const previewBtn = byId("grasp-btn-preview");
  const submitBtn = byId("grasp-btn-submit");

  if (!config || !config.steps) return;

  const isLast = currentStepIndex >= config.steps.length - 1;

  if (prevBtn) prevBtn.disabled = currentStepIndex === 0;
  if (nextBtn) nextBtn.disabled = isLast;
  if (saveBtn) saveBtn.disabled = false;

  // Preview is allowed on any step.
  if (previewBtn) previewBtn.disabled = false;

  // Toggle Next vs Submit on the last step
  if (nextBtn) nextBtn.style.display = isLast ? "none" : "inline-block";
  if (submitBtn) submitBtn.style.display = isLast ? "inline-block" : "none";
}

/**
 * Save draft button handler
 */
async function handleSaveDraft() {
  try {
    await saveDraft();
    setStatus("Your progress has been saved.", "success");
  } catch (err) {
    console.error("handleSaveDraft error", err);
    setStatus("Failed to save your progress. Please try again.", "error");
  }
}

/**
 * Email preview builder
 */
function buildEmailHtml(data, submittedAt, emailHtmlFromClient) {
  if (emailHtmlFromClient) {
    return emailHtmlFromClient;
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  let rows = "";

  // const debugMode = !!(window && window.GRASP_DEBUG && window.GRASP_DEBUG.enabled);
  const debugMode =
    typeof window !== "undefined" &&
    (window.GRASP_DEBUG === true || isDebugMode === true);

  const labelMap = {};
  const orderedNames = [];
  (config.steps || []).forEach((step) => {
    (step.groups || []).forEach((group) => {
      (group.fields || []).forEach((field) => {
        if (!field || !field.name) return;
        // Include hidden/derived fields so they can display friendly labels
        // in the preview/email (they are not rendered in the UI, but they
        // still matter for submission).
        labelMap[field.name] = field.label || field.name;
        if (!orderedNames.includes(field.name)) orderedNames.push(field.name);
      });
    });
  });
  (orderedNames.length ? orderedNames : Object.keys(data || {})).forEach(
    (name) => {
      const label = labelMap[name] || name;
      const value =
        data && Object.prototype.hasOwnProperty.call(data, name)
          ? data[name]
          : undefined;

      if (name === "parent2_home_same_as_parent1") return;

      const displayValue =
        value === undefined || value === null || value === ""
          ? "—"
          : String(value);

      const rawName = name.startsWith("field_") ? name.slice(6) : name;

      let cellLabel = escapeHtml(label);
      if (debugMode) {
        cellLabel =
          escapeHtml(rawName) +
          '<div style="font-size: 11px; color: #6b7280; margin-top: 2px;">' +
          escapeHtml(label) +
          "</div>";
      }

      rows +=
        "<tr>" +
        '<td style="border:1px solid #e5e7eb;padding:6px 8px;font-weight:600;width:38%;">' +
        cellLabel +
        "</td>" +
        '<td style="border:1px solid #e5e7eb;padding:6px 8px;">' +
        escapeHtml(displayValue) +
        "</td>" +
        "</tr>";
    },
  );

  const html =
    '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;">' +
    '<h2 style="margin:0 0 10px;">GRASP Enrollment Submission</h2>' +
    '<p style="margin:0 0 12px;">Submitted at: ' +
    escapeHtml(submittedAt || new Date().toISOString()) +
    "</p>" +
    '<table style="border-collapse:collapse;width:100%;max-width:900px;">' +
    rows +
    "</table>" +
    "</div>";

  return html;
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

/**
 * Preview modal
 */
/**
 * Preview modal helpers (static markup in index.html)
 */
let _previewModalWired = false;
let _previewOnSubmit = null;
let _previewLastHtml = "";

function printPreviewViaIframe(previewHtml) {
  const html = previewHtml || "";

  // Avoid popup blockers by printing from a hidden iframe.
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

  const printCss = `
    body { margin: 0; padding: 0; }
  `;

  const printCssHref = new URL("css/print.css", window.location.href).toString();

  iframe.onload = () => {
    try {
      iframe.contentWindow && iframe.contentWindow.focus();
      iframe.contentWindow && iframe.contentWindow.print();
    } finally {
      // cleanup after a short delay to allow print dialog
      setTimeout(() => iframe.remove(), 1000);
    }
  };

  iframe.srcdoc = `<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Print Preview</title>
  <style>${printCss}</style>
  <link rel="stylesheet" href="${printCssHref}" media="print" />
</head>
<body>
  <div class="grasp-print-container">${html}</div>
</body>
</html>`;
}



function hidePreviewModal() {
  const modal = byId("grasp-preview-modal");
  if (!modal) return;
  modal.classList.add("hidden");
  modal.setAttribute("aria-hidden", "true");
  // Re-enable page scroll
  document.body.style.overflow = "";
}

function showPreviewModal(html, { canSubmit = false, onSubmit = null } = {}) {
  const modal = byId("grasp-preview-modal");
  const content = byId("grasp-preview-content");
  const btnCloseX = byId("grasp-preview-close");
  const btnClose = byId("grasp-preview-close-btn");
  const btnPrint = byId("grasp-preview-print");
  const btnSubmit = byId("grasp-preview-confirm");

  if (!modal || !content) {
    console.warn("Preview modal markup not found; cannot show preview.");
    return;
  }

  _previewLastHtml = html || "";
  content.innerHTML = _previewLastHtml;
  _previewOnSubmit = onSubmit;

  const dialog = modal.querySelector(".grasp-modal-dialog");
  let statusEl = byId("grasp-preview-status");
  if (!statusEl && dialog) {
    statusEl = document.createElement("div");
    statusEl.id = "grasp-preview-status";
    statusEl.setAttribute("role", "status");
    statusEl.style.margin = "0 0 12px";
    statusEl.style.fontWeight = "700";
    statusEl.style.color = "#b00020";
    statusEl.style.display = "none";
    dialog.insertBefore(statusEl, dialog.firstChild);
  }
  if (statusEl) {
    statusEl.textContent = "";
    statusEl.style.display = "none";
  }

  if (btnSubmit) {
    btnSubmit.disabled = !canSubmit;
    btnSubmit.textContent = "Submit Enrollment";
    btnSubmit.title = canSubmit
      ? ""
      : "Complete all required fields to enable submission.";
  }

  if (!_previewModalWired) {
    const close = () => hidePreviewModal();

    if (btnCloseX) btnCloseX.addEventListener("click", close);
    if (btnClose) btnClose.addEventListener("click", close);

    if (btnPrint) {
      btnPrint.addEventListener("click", () => {
        // Print uses a dedicated print template (PDF-like), separate from the on-screen email preview.
        try {
          const build = window.GRASP_PRINT_TEMPLATES && window.GRASP_PRINT_TEMPLATES.buildEnrollmentPrintHtml;
          const printHtml = typeof build === "function" ? build(formState, window.config) : _previewLastHtml;
          printPreviewViaIframe(printHtml);
        } catch (e) {
          console.warn('[GRASP][enrollment] print template failed; falling back to preview HTML', e);
          printPreviewViaIframe(_previewLastHtml);
        }
      });
    }

    // Click outside dialog closes modal
    modal.addEventListener("click", (e) => {
      if (e.target === modal) close();
    });

    // ESC closes modal
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      const m = byId("grasp-preview-modal");
      if (m && !m.classList.contains("hidden")) close();
    });

    // Submit handler
    if (btnSubmit) {
      btnSubmit.addEventListener("click", async () => {
        const m = byId("grasp-preview-modal");
        if (!m || m.classList.contains("hidden")) return;
        if (btnSubmit.disabled) return;

        btnSubmit.disabled = true;
        const originalText = btnSubmit.textContent;
        btnSubmit.textContent = "Submitting...";

        try {
          if (typeof _previewOnSubmit === "function") {
            await _previewOnSubmit();
          }

          if (statusEl) {
            statusEl.textContent =
              "Form submitted. A staff member from GRASP will contact you shortly.";
            statusEl.style.display = "block";
          }

          btnSubmit.textContent = "Submitted";

          await new Promise((resolve) => setTimeout(resolve, 6000));
          hidePreviewModal();
        } catch (err) {
          console.error("Error in preview submit", err);
          btnSubmit.disabled = false;
          btnSubmit.textContent = originalText || "Submit Enrollment";
          if (statusEl) {
            statusEl.textContent = "";
            statusEl.style.display = "none";
          }
          alert(
            "Sorry, an error occurred while submitting your enrollment. Please try again.",
          );
        }
      });
    }

    if (content) {
      content.addEventListener("click", (event) => {
        const target = event.target.closest("[data-preview-jump]");
        if (!target) return;

        event.preventDefault();

        const stepIndex = Number(target.getAttribute("data-step"));
        const fieldName = target.getAttribute("data-field") || "";

        hidePreviewModal();
        jumpToField(stepIndex, fieldName);
      });
    }

    _previewModalWired = true;
  }

  modal.classList.remove("hidden");
  modal.setAttribute("aria-hidden", "false");
  // Prevent background scroll while modal is open
  document.body.style.overflow = "hidden";

  try {
    (btnCloseX || btnClose || btnSubmit).focus();
  } catch {}
}

function jumpToField(stepIndex, fieldName) {
  if (!Number.isFinite(stepIndex) || stepIndex < 0) return;
  if (!fieldName) return;

  goToStep(stepIndex, { skipValidation: true });

  window.setTimeout(() => {
    let field = byId("field_" + fieldName);
    if (!field) {
      field = document.querySelector('input[name="field_' + fieldName + '"]');
    }

    if (field && typeof field.scrollIntoView === "function") {
      field.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    if (field && typeof field.focus === "function") {
      field.focus();
    }
  }, 0);
}

function collectValidationIssues() {
  const issues = [];
  if (!config || !config.steps) return issues;

  const messages = (config && config.validationMessages) || {};
  const requiredMsg = messages.required || "This field is required.";
  const radioRequiredMsg = messages.radioRequired || "Please select an option.";

  for (let stepIndex = 0; stepIndex < config.steps.length; stepIndex += 1) {
    const step = config.steps[stepIndex];
    for (const group of step.groups || []) {
      for (const fieldDef of group.fields || []) {
        if (!fieldDef || fieldDef.type === "hidden") continue;

        const name = fieldDef.name;
        if (!name) continue;
        if (name === "parent2_home_same_as_parent1") continue;

        const value = formState[name];
        const label = fieldDef.label || name;

        // Postal format validation (if present)
        if (
          typeof window !== "undefined" &&
          window.GRASP_POSTAL &&
          typeof window.GRASP_POSTAL.validateField === "function"
        ) {
          const res = window.GRASP_POSTAL.validateField(fieldDef, value);
          if (res && !res.ok) {
            issues.push({
              name,
              label,
              stepIndex,
              stepTitle: step.title || "",
              reason: res.message || "Invalid postal code.",
            });
            continue;
          }
        }

        if (fieldDef.required) {
          if (fieldDef.type === "radio") {
            const selected = (fieldDef.options || []).some(
              (opt) => formState[name] === opt.value,
            );
            if (!selected) {
              issues.push({
                name,
                label,
                stepIndex,
                stepTitle: step.title || "",
                reason: radioRequiredMsg,
              });
            }
          } else if (
            fieldDef.type === "checkbox" &&
            fieldDef.enforceChecked === true
          ) {
            if (!value) {
              issues.push({
                name,
                label,
                stepIndex,
                stepTitle: step.title || "",
                reason: "You must check this box to proceed.",
              });
            }
          } else if (
            fieldDef.type !== "checkbox" &&
            (value === undefined ||
              value === null ||
              String(value).trim() === "")
          ) {
            issues.push({
              name,
              label,
              stepIndex,
              stepTitle: step.title || "",
              reason: requiredMsg,
            });
          }
        }
      }
    }
  }

  return issues;
}

function isFormSubmittable() {
  return collectValidationIssues().length === 0;
}
/**
 * Preview + submit handler
 */
async function openPreview() {
  // Keep derived hidden fields in sync before previewing.
  try {
    if (typeof syncDerivedFields === "function") {
      syncDerivedFields();
    }
  } catch (e) {
    console.warn("openPreview: syncDerivedFields failed", e);
  }

  setStatus("");

  const issues = collectValidationIssues();
  const canSubmit = issues.length === 0;
  const submittedAt = new Date().toISOString();

  const payload = {
    formId: FORM_ID,
    sessionId,
    submittedAt,
    data: { ...formState },
  };

  let previewHtml = buildEmailHtml(payload.data, payload.submittedAt, null);

  if (!canSubmit) {
    const issueListHtml =
      '<ul style="margin:8px 0 0 18px;">' +
      issues
        .map((issue) => {
          const label = escapeHtml(issue.label || issue.name);
          const reason = escapeHtml(issue.reason || "Missing or invalid");
          const stepTitle = escapeHtml(issue.stepTitle || "");
          const stepMeta = stepTitle ? " (" + stepTitle + ")" : "";
          return (
            '<li><button type="button" data-preview-jump="true" data-step="' +
            String(issue.stepIndex) +
            '" data-field="' +
            escapeHtml(issue.name) +
            '" style="background:none;border:none;color:#2563eb;padding:0;cursor:pointer;text-decoration:underline;">' +
            label +
            "</button>" +
            stepMeta +
            ": " +
            reason +
            "</li>"
          );
        })
        .join("") +
      "</ul>";

    previewHtml =
      '<div style="padding:10px 12px;border:1px solid #fde68a;background:#fffbeb;border-radius:10px;margin:0 0 12px;">' +
      "<strong>Preview only:</strong> Some required fields are missing or invalid. " +
      "You can review what you've entered so far, but <em>Submit Enrollment</em> is disabled until the form is complete." +
      issueListHtml +
      "</div>" +
      previewHtml;
  }

  const onSubmit = async () => {
    await submitEnrollment(payload, previewHtml);
  };

  showPreviewModal(previewHtml, { canSubmit, onSubmit });
}

/**
 * Submit enrollment to backend
 */
async function submitEnrollment(payload, previewHtml) {
  try {
    const res = await fetch("../api/submit_enrollment.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        ...payload,
        emailHtml: previewHtml,
      }),
    });

    if (!res.ok) {
      throw new Error("Non-200 response from submit_enrollment.php");
    }

    const json = await res.json();
    if (!json.success) {
      throw new Error(json.error || "Unknown error");
    }

    // remove draft clearing onsubmit - set package status instead
    // clearLocalStorage();
    try {
      if (window.GRASP_PACKAGE_DRAFT?.setStatus) {
        await window.GRASP_PACKAGE_DRAFT.setStatus({
          enrollmentSubmittedAt: new Date().toISOString(),
        });
      }
    } catch (e) {
      console.warn("[GRASP][enrollment] package status update failed", e);
    }

    setStatus("Thank you! Your enrollment form has been submitted.", "success");

    formState = {};
    sessionId = null;
    currentStepIndex = 0;
    renderCurrentStep();
  } catch (err) {
    console.error("submitEnrollment error", err);
    setStatus(
      "Sorry, there was a problem submitting your enrollment. Please try again.",
      "error",
    );
  }
}

/**
 * Initialise the wizard
 */
async function initWizard() {
  try {
    const params = getQueryParams();
    isDebugMode =
      params.DEBUG === "true" ||
      params.debug === "true" ||
      params.debug === "1";

    await loadConfig();
    await loadDraft();

    // Expose live state for enrollment-debug.js and cross-form sync
    window.formState = formState;
    window.sessionId = sessionId;
    window.currentStepIndex = currentStepIndex;


    // ✅ ADD THIS BLOCK HERE (before loadConfig/loadDraft)
    if (window.GRASP_PACKAGE_DRAFT?.checkAndHandleStaleDraft) {
      const ok = await window.GRASP_PACKAGE_DRAFT.checkAndHandleStaleDraft();
      if (!ok) return; // it will reload if stale cleared
    }

    applyFieldDefaults();

    if (!sessionId) {
      sessionId = generateSessionId();
      window.localStorage.setItem(STORAGE_KEY_SESSION_ID, sessionId);
    }
    /*
    if (
      isDebugMode &&
      window.GRASP_DEBUG &&
      typeof window.GRASP_DEBUG.applyDebugDefaults === "function"
    ) {
      window.GRASP_DEBUG.applyDebugDefaults(config.steps, formState, () => {
        syncDerivedFields();
        renderCurrentStep();
      });
    } else {
      syncDerivedFields();
      renderCurrentStep();
    }
*/

    // Once config and any stored draft are loaded, render once.
    syncDerivedFields();
    renderCurrentStep();

    // If debug mode is enabled, let enrollment-debug.js know so it
    // can prefill the form and add its badge. It listens for this event.
    if (
      typeof window !== "undefined" &&
      (window.GRASP_DEBUG === true || isDebugMode) &&
      typeof window.dispatchEvent === "function"
    ) {
      try {
        const evt = new CustomEvent("graspEnrollmentInit", {
          detail: { config, formState, sessionId },
        });
        window.dispatchEvent(evt);
      } catch (e) {
        console.warn("Failed to dispatch graspEnrollmentInit", e);
      }
    }

    const btnPrev = byId("grasp-btn-prev");
    const btnNext = byId("grasp-btn-next");
    const btnSave = byId("grasp-btn-save");
    const btnPreview = byId("grasp-btn-preview");
    const btnSubmit = byId("grasp-btn-submit");

    bindClearSavedDataButton();

    if (btnPrev) btnPrev.addEventListener("click", handlePrev);
    if (btnNext) btnNext.addEventListener("click", handleNext);
    if (btnSave) btnSave.addEventListener("click", handleSaveDraft);
    if (btnPreview) btnPreview.addEventListener("click", openPreview);
    // On the last step, the primary "Submit" button should behave like
    // "Preview & Submit" (it will validate all steps before opening preview).
    if (btnSubmit) btnSubmit.addEventListener("click", openPreview);

    updateNavButtons();

    if (isDebugMode && window.GRASP_DEBUG && window.GRASP_DEBUG.showBadge) {
      window.GRASP_DEBUG.showBadge();
    }
  } catch (err) {
    console.error("initWizard error", err);
    setStatus(
      "Sorry, there was a problem loading the enrollment form. Please try again later.",
      "error",
    );
  }
}

document.addEventListener("DOMContentLoaded", () => {
  initWizard().catch((err) => console.error("initWizard top-level error", err));
});

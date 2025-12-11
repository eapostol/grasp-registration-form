// enrollment app script: dynamic multi-step wizard + encrypted local storage + submission
// Designed to run on the Greenland Recreational site. Assumes css/style.css and css/enrollment.css are loaded.

const FORM_ID = "grasp_enrollment_2025";
const STORAGE_KEY_ENCRYPTED = "graspEnrollmentEncryptedData";
const STORAGE_KEY_SESSION_ID = "graspEnrollmentSessionId";
const STORAGE_DB_NAME = "graspEnrollmentDB";
const STORAGE_DB_STORE = "sessions";

// NOTE: This secret key is embedded in the front-end, so it provides
// obfuscation and basic protection if the device is lost, but it is not
// equivalent to server-side encryption. For a production deployment,
// consider deriving a key from a user-provided passphrase or device‑specific secret.
const SECRET_KEY_STRING = "grasp-enrollment-demo-secret-32-chars!!";

let cryptoKeyCache = null;

/**
 * Get or import the AES-GCM CryptoKey from SECRET_KEY_STRING.
 */
async function getCryptoKey() {
  if (cryptoKeyCache) return cryptoKeyCache;

  const encoder = new TextEncoder();
  // Ensure we have 32 bytes for AES-256
  let keyBytes = encoder.encode(SECRET_KEY_STRING);
  if (keyBytes.length < 32) {
    const padded = new Uint8Array(32);
    padded.set(keyBytes);
    keyBytes = padded;
  } else if (keyBytes.length > 32) {
    keyBytes = keyBytes.slice(0, 32);
  }

  cryptoKeyCache = await window.crypto.subtle.importKey(
    "raw",
    keyBytes,
    { name: "AES-GCM" },
    false,
    ["encrypt", "decrypt"]
  );
  return cryptoKeyCache;
}

/**
 * Encrypt an object to a base64 string.
 */
async function encryptData(obj) {
  const key = await getCryptoKey();
  const encoder = new TextEncoder();
  const json = JSON.stringify(obj);
  const data = encoder.encode(json);
  const iv = window.crypto.getRandomValues(new Uint8Array(12));

  const cipherBuffer = await window.crypto.subtle.encrypt(
    { name: "AES-GCM", iv },
    key,
    data
  );

  const combined = new Uint8Array(iv.byteLength + cipherBuffer.byteLength);
  combined.set(iv, 0);
  combined.set(new Uint8Array(cipherBuffer), iv.byteLength);

  let binary = "";
  combined.forEach((b) => (binary += String.fromCharCode(b)));
  return btoa(binary);
}

/**
 * Decrypt a base64 string to an object (or null on failure).
 */
async function decryptData(base64Str) {
  try {
    const key = await getCryptoKey();
    const binary = atob(base64Str);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }

    const iv = bytes.slice(0, 12);
    const cipherBytes = bytes.slice(12);

    const plainBuffer = await window.crypto.subtle.decrypt(
      { name: "AES-GCM", iv },
      key,
      cipherBytes
    );
    const decoder = new TextDecoder();
    const json = decoder.decode(plainBuffer);
    return JSON.parse(json);
  } catch (err) {
    console.warn("Failed to decrypt data", err);
    return null;
  }
}

/**
 * Generate a simple random session ID.
 */
function generateSessionId() {
  const arr = new Uint8Array(16);
  window.crypto.getRandomValues(arr);
  return Array.from(arr)
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

/**
 * IndexedDB helpers (best-effort)
 */
function openIndexedDb() {
  return new Promise((resolve, reject) => {
    if (!("indexedDB" in window)) {
      resolve(null);
      return;
    }

    const req = window.indexedDB.open(STORAGE_DB_NAME, 1);
    req.onerror = () => resolve(null);
    req.onsuccess = () => resolve(req.result);
    req.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains(STORAGE_DB_STORE)) {
        db.createObjectStore(STORAGE_DB_STORE, { keyPath: "sessionId" });
      }
    };
  });
}

async function saveToIndexedDb(sessionId, encryptedPayload) {
  const db = await openIndexedDb();
  if (!db) return false;

  return new Promise((resolve) => {
    const tx = db.transaction(STORAGE_DB_STORE, "readwrite");
    const store = tx.objectStore(STORAGE_DB_STORE);
    const record = {
      sessionId,
      encryptedPayload,
      updatedAt: new Date().toISOString(),
    };
    store.put(record);
    tx.oncomplete = () => resolve(true);
    tx.onerror = () => resolve(false);
  });
}

async function loadFromIndexedDb(sessionId) {
  const db = await openIndexedDb();
  if (!db) return null;

  return new Promise((resolve) => {
    const tx = db.transaction(STORAGE_DB_STORE, "readonly");
    const store = tx.objectStore(STORAGE_DB_STORE);
    const req = store.get(sessionId);
    req.onsuccess = () => resolve(req.result || null);
    req.onerror = () => resolve(null);
  });
}

/**
 * Local storage helpers (fallback)
 */
function saveToLocalStorage(sessionId, encryptedPayload) {
  try {
    const wrapper = {
      formId: FORM_ID,
      sessionId,
      encryptedPayload,
      updatedAt: new Date().toISOString(),
    };
    window.localStorage.setItem(STORAGE_KEY_ENCRYPTED, JSON.stringify(wrapper));
    window.localStorage.setItem(STORAGE_KEY_SESSION_ID, sessionId);
    return true;
  } catch (e) {
    console.warn("Failed to save to localStorage", e);
    return false;
  }
}

function loadFromLocalStorage() {
  try {
    const wrapperStr = window.localStorage.getItem(STORAGE_KEY_ENCRYPTED);
    if (!wrapperStr) return null;
    return JSON.parse(wrapperStr);
  } catch (e) {
    console.warn("Failed to load from localStorage", e);
    return null;
  }
}

function clearLocalStorage() {
  try {
    window.localStorage.removeItem(STORAGE_KEY_ENCRYPTED);
    // keep session ID so we can correlate submissions with later edits if needed
  } catch (e) {
    console.warn("Failed to clear localStorage", e);
  }
}

// ===============================
// [GRASP-NAMES] Derived name helpers
// ===============================

function buildFullName(first, middle, last) {
  if (!first && !last) return "";
  const parts = [];
  if (first) parts.push(first.trim());
  if (middle && middle.trim()) parts.push(middle.trim());
  if (last) parts.push(last.trim());
  return parts.join(" ");
}

function syncDerivedNames() {
  if (!formState) return;

  // Child full name from parts
  const cf = formState["child_first_name"] || "";
  const cm = formState["child_middle_name_or_initial"] || "";
  const cl = formState["child_last_name"] || "";
  const childFull = buildFullName(cf, cm, cl);
  if (childFull) {
    formState["child_name"] = childFull;
  }

  // Parent 1 full name
  const p1f = formState["parent1_first_name"] || "";
  const p1l = formState["parent1_last_name"] || "";
  const parent1Full = buildFullName(p1f, "", p1l);
  if (parent1Full) {
    formState["parent1_name"] = parent1Full;
  }

  // Parent 2 full name
  const p2f = formState["parent2_first_name"] || "";
  const p2l = formState["parent2_last_name"] || "";
  const parent2Full = buildFullName(p2f, "", p2l);
  if (parent2Full) {
    formState["parent2_name"] = parent2Full;
  }
}

// ===============================
// [GRASP-ADDR] Derived address helpers
// ===============================

function composePostalCode(part1, part2) {
  const p1 = (part1 || "").trim().toUpperCase();
  const p2 = (part2 || "").trim().toUpperCase();
  if (!p1 && !p2) return "";
  return (p1 + " " + p2).trim();
}

function composeAddress(street, unit, city, province, postalFull) {
  const lines = [];
  if (street && street.trim()) lines.push(street.trim());
  if (unit && unit.trim()) lines.push(unit.trim());

  const lastLineParts = [];
  if (city && city.trim()) lastLineParts.push(city.trim());
  if (province && province.trim()) lastLineParts.push(province.trim());
  if (postalFull && postalFull.trim()) lastLineParts.push(postalFull.trim());

  if (lastLineParts.length) {
    lines.push(
      lastLineParts.join(", ").replace(", ", ", ").replace(", ON", ", ON")
    );
  }

  return lines.join("\n");
}

function syncDerivedAddresses() {
  if (!formState) return;

  // Helper to apply pattern to a specific prefix and target fields
  function applyAddress(prefix, fullField, postalField) {
    const street = formState[`${prefix}_street`] || "";
    const unit = formState[`${prefix}_unit`] || "";
    const city = formState[`${prefix}_city`] || "";
    const prov = formState[`${prefix}_province`] || "";
    const p1 = formState[`${prefix}_postal1`] || "";
    const p2 = formState[`${prefix}_postal2`] || "";

    const postalFull = composePostalCode(p1, p2);
    if (postalField) {
      formState[postalField] = postalFull;
    }

    const addrFull = composeAddress(street, unit, city, prov, postalFull);
    if (fullField) {
      formState[fullField] = addrFull;
    }
  }

  // Parent 1 home
  applyAddress("parent1_home", "parent1_home_address", "parent1_postal_code");

  // Parent 2 home
  applyAddress("parent2_home", "parent2_home_address", "parent2_postal_code");

  // Parent 1 work/school
  applyAddress(
    "parent1_work",
    "parent1_work_address",
    "parent1_work_postal_code"
  );

  // Parent 2 work/school
  applyAddress(
    "parent2_work",
    "parent2_work_address",
    "parent2_work_postal_code"
  );

  // Doctor/clinic
  applyAddress("doctor", "doctor_address", "doctor_postal_code");
}

// Wrapper to keep things in sync whenever we touch fields.
function syncDerivedFields() {
  syncDerivedNames();
  syncDerivedAddresses();
}

// [GRASP-ADDR] Copy Parent 1 home address into Parent 2 when checkbox is toggled
function applyParent2SameAsParent1() {
  const same = !!formState["parent2_home_same_as_parent1"];

  const keys = [
    "home_street",
    "home_unit",
    "home_city",
    "home_province",
    "home_postal1",
    "home_postal2",
  ];

  if (same) {
    keys.forEach((suffix) => {
      formState[`parent2_${suffix}`] = formState[`parent1_${suffix}`] || "";
    });
  } else {
    keys.forEach((suffix) => {
      formState[`parent2_${suffix}`] = "";
    });
  }

  // After copying/clearing, update derived fields too
  syncDerivedFields();
}

/**
 * Global form state
 */
let config = null;
let currentStepIndex = 0;
let formState = {};
let sessionId = null;

function byId(id) {
  return document.getElementById(id);
}

function setStatus(message, type = "") {
  const el = byId("grasp-wizard-status");
  if (!el) return;
  el.textContent = message || "";
  el.className = "grasp-wizard-status" + (type ? " " + type : "");
}

/**
 * Render step list and progress bar
 */
function renderStepList() {
  const listEl = byId("grasp-wizard-step-list");
  if (!listEl || !config) return;
  listEl.innerHTML = "";

  config.steps.forEach((step, index) => {
    const li = document.createElement("li");
    li.textContent = index + 1 + ". " + step.title;
    if (index === currentStepIndex) {
      li.classList.add("active-step");
    }
    li.addEventListener("click", () => {
      goToStep(index);
    });
    listEl.appendChild(li);
  });

  updateProgressBar();
}

function updateProgressBar() {
  const fill = byId("grasp-wizard-progress-fill");
  if (!fill || !config) return;
  const total = config.steps.length || 1;
  const pct = ((currentStepIndex + 1) / total) * 100;
  fill.style.width = pct.toFixed(0) + "%";
}

/**
 * Create a field DOM node from field definition
 */
function getFieldValue(name) {
  return formState[name] ?? "";
}

let draftTimer = null;
function scheduleDraftSave() {
  clearTimeout(draftTimer);
  draftTimer = setTimeout(async () => {
    try {
      await fetch("api/save_draft.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ formId: FORM_ID, sessionId, data: formState }),
      });
    } catch (e) {}
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

function createFieldRow(fieldDef) {
  const wrapper = document.createElement("div");
  wrapper.className = "grasp-field-row";
  wrapper.dataset.fieldName = fieldDef.name;

  const label = document.createElement("label");
  label.className = "grasp-field-label";
  label.htmlFor = "field_" + fieldDef.name;
  label.textContent = fieldDef.label || fieldDef.name;

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

  if (fieldDef.type === "textarea") {
    control = document.createElement("textarea");
    control.className = "grasp-textarea";
    control.id = "field_" + fieldDef.name;
    control.value = value;
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
          setFieldValue(fieldDef.name, input.value);
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
      document.createTextNode(" " + (fieldDef.checkboxLabel || "Yes"))
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
    control.addEventListener("input", () => {
      // [GRASP-POSTAL] Normalize postal halves (A1A / 1A1) on each keystroke.
      if (
        typeof window !== "undefined" &&
        window.GRASP_POSTAL &&
        typeof window.GRASP_POSTAL.normalizeInput === "function"
      ) {
        control.value = window.GRASP_POSTAL.normalizeInput(
          fieldDef.name,
          control.value
        );
      }

      setFieldValue(fieldDef.name, control.value);
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
/**
 * Render the current step
 */
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

    (group.fields || []).forEach((fieldDef) => {
      // [GRASP-HIDDEN] Skip hidden/derived fields when rendering the UI.
      // They still exist in config + formState for email/DB use.
      if (fieldDef.type === "hidden") {
        return;
      }

      const row = createFieldRow(fieldDef);
      groupEl.appendChild(row);
    });

    container.appendChild(groupEl);
  });

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
      // [GRASP-HIDDEN] Do not validate hidden/derived fields directly.
      // They will be populated by derived logic (names/addresses).
      if (fieldDef.type === "hidden") return;

      const value = formState[fieldDef.name];
      const errorEl = byId("error_" + fieldDef.name);
      if (errorEl) errorEl.textContent = "";

      // [GRASP-POSTAL] Postal-specific pattern validation (A1A / 1A1).
      if (
        typeof window !== "undefined" &&
        window.GRASP_POSTAL &&
        typeof window.GRASP_POSTAL.validateField === "function"
      ) {
        const postalResult = window.GRASP_POSTAL.validateField(fieldDef, value);
        if (!postalResult.ok) {
          valid = false;
          if (errorEl) {
            errorEl.textContent = postalResult.message;
          }
          // Skip generic "required" checks for this field so we don't
          // overwrite the more specific postal error.
          return;
        }
      }

      // If the field is not required, no further validation needed.
      if (!fieldDef.required) return;

      if (fieldDef.type === "radio") {
        if (!value) {
          valid = false;
          if (errorEl) {
            errorEl.textContent =
              (config.validationMessages &&
                config.validationMessages.radioRequired) ||
              "Please select an option.";
          }
        }
      } else {
        if (!value || String(value).trim() === "") {
          valid = false;
          if (errorEl) {
            errorEl.textContent =
              (config.validationMessages &&
                config.validationMessages.required) ||
              "This field is required.";
          }
        }
      }
    });
  });


  return valid;
}

async function saveFormState() {
  if (!config) return;

  const payload = {
    formId: FORM_ID,
    sessionId,
    updatedAt: new Date().toISOString(),
    data: formState,
  };

  const encryptedPayload = await encryptData(payload);
  await saveToIndexedDb(sessionId, encryptedPayload);
  saveToLocalStorage(sessionId, encryptedPayload);
}

async function loadFormState() {
  // Try localStorage first to get sessionId
  let storedSessionId = null;
  try {
    storedSessionId = window.localStorage.getItem(STORAGE_KEY_SESSION_ID);
  } catch (_) {}

  if (storedSessionId) {
    sessionId = storedSessionId;
  } else {
    sessionId = generateSessionId();
    try {
      window.localStorage.setItem(STORAGE_KEY_SESSION_ID, sessionId);
    } catch (_) {}
  }

  // Try IndexedDB, then localStorage
  let encryptedPayload = null;
  const idbRecord = await loadFromIndexedDb(sessionId);
  if (idbRecord && idbRecord.encryptedPayload) {
    encryptedPayload = idbRecord.encryptedPayload;
  } else {
    const localWrapper = loadFromLocalStorage();
    if (localWrapper && localWrapper.sessionId === sessionId) {
      encryptedPayload = localWrapper.encryptedPayload;
    }
  }

  if (!encryptedPayload) {
    formState = {};
    return;
  }

  const payload = await decryptData(encryptedPayload);
  if (!payload || payload.formId !== FORM_ID) {
    formState = {};
    return;
  }

  formState = payload.data || {};

  // [GRASP-DERIVED] Make sure combined fields reflect latest parts
  syncDerivedFields();
}

/**
 * Nav button logic
 */
function updateNavButtons() {
  const btnPrev = byId("grasp-btn-prev");
  const btnNext = byId("grasp-btn-next");
  const btnSubmit = byId("grasp-btn-submit");

  const lastIndex = config.steps.length - 1;
  if (btnPrev) {
    btnPrev.disabled = currentStepIndex === 0;
  }
  if (btnNext) {
    btnNext.style.display =
      currentStepIndex === lastIndex ? "none" : "inline-block";
  }
  if (btnSubmit) {
    btnSubmit.style.display =
      currentStepIndex === lastIndex ? "inline-block" : "none";
  }
}

async function goToStep(targetIndex) {
  if (targetIndex < 0 || targetIndex >= config.steps.length) return;
  // Validate current step before moving forward
  if (targetIndex > currentStepIndex) {
    const valid = validateStep(currentStepIndex);
    if (!valid) {
      setStatus("Please complete required fields before continuing.", "error");
      return;
    }
  }
  setStatus("");
  currentStepIndex = targetIndex;
  await saveFormState();
  renderCurrentStep();
}

async function handlePrev() {
  await goToStep(currentStepIndex - 1);
}

async function handleNext() {
  await goToStep(currentStepIndex + 1);
}

async function handleSave() {
  // [GRASP-DERIVED] Make sure combined fields reflect latest parts
  syncDerivedFields();

  await saveFormState();
  setStatus(
    "Saved on this device. You can return later using the same browser.",
    "success"
  );
}

/**
 * Build an HTML representation of the submission that roughly matches
 * the PDF structure, using the JSON config + formState.
 */
function buildEmailHtml() {
  const submittedAt = new Date().toISOString();
  const debugMode =
    typeof window !== "undefined" && window.GRASP_DEBUG === true;

  // [GRASP-EMAIL] Internal/meta field names that should not appear
  // in the outgoing email or preview.
  const INTERNAL_FIELD_NAMES = new Set([
    "parent2_home_same_as_parent1", // copy Parent 1 home address checkbox
  ]);

  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function formatFieldValue(field, value) {
    if (value === null || value === undefined) return "";
    if (Array.isArray(value)) {
      return value.join(", ");
    }
    if (typeof value === "boolean") {
      return value ? "yes" : "no";
    }
    return String(value);
  }

  function getFriendlyLabel(field) {
    if (!field) return "";
    if (field.label) return field.label;
    // Fallback: prettify the raw field name if no label exists
    const raw = (field.name || "").replace(/^field_/, "");
    return raw.replace(/_/g, " ").replace(/\b\w/g, (ch) => ch.toUpperCase());
  }

  let html = "";
  html += "<h2>GRASP Enrollment Submission</h2>";
  html +=
    '<p>Submitted at: <span style="font-family:monospace;">' +
    escapeHtml(submittedAt) +
    "</span></p>";
  html +=
    '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:800px;">';
  html += "<tbody>";

  const steps = (config && config.steps) || [];
  steps.forEach((step) => {
    (step.groups || []).forEach((group) => {
      (group.fields || []).forEach((field) => {
        if (!field || !field.name) return;

        // [GRASP-EMAIL] Skip internal/meta fields like the "same as Parent 1" checkbox.
        if (INTERNAL_FIELD_NAMES.has(field.name)) {
          return;
        }

        const rawName = (field.name || "").replace(/^field_/, "");
        const friendly = getFriendlyLabel(field);
        const value = formatFieldValue(field, formState[field.name]);

        let labelHtml;
        if (debugMode) {
          // DEBUG ON: show raw field name + friendly label (smaller) in same cell
          labelHtml =
            '<div style="font-weight:bold;">' +
            escapeHtml(rawName) +
            "</div>" +
            '<div style="font-size:11px;color:#555;margin-top:2px;">' +
            escapeHtml(friendly) +
            "</div>";
        } else {
          // DEBUG OFF: show only the friendly label
          labelHtml =
            '<div style="font-weight:bold;">' + escapeHtml(friendly) + "</div>";
        }

        html += "<tr>";
        html +=
          '<td style="border:1px solid #ccc;padding:4px 6px;vertical-align:top;width:40%;">' +
          labelHtml +
          "</td>";
        html +=
          '<td style="border:1px solid #ccc;padding:4px 6px;vertical-align:top;width:60%;">' +
          escapeHtml(value) +
          "</td>";
        html += "</tr>";
      });
    });
  });

  html += "</tbody></table>";
  return html;
}

function buildEmailHtmlOld() {
  if (!config) return "";

  const escapeHtml = (str) => {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  };

  let html = "";
  html +=
    "<h2>Greenland Recreational After School Program – Enrollment Form</h2>";
  html +=
    "<p><strong>Submitted at:</strong> " + new Date().toLocaleString() + "</p>";
  html += "<p><strong>Session ID:</strong> " + escapeHtml(sessionId) + "</p>";

  config.steps.forEach((step, stepIndex) => {
    html +=
      '<h3 style="margin-top:18px;border-top:1px solid #ccc;padding-top:8px;">' +
      (stepIndex + 1) +
      ". " +
      escapeHtml(step.title) +
      "</h3>";
    if (step.description) {
      html += "<p>" + escapeHtml(step.description) + "</p>";
    }

    (step.groups || []).forEach((group) => {
      html +=
        '<h4 style="margin:8px 0 4px 0;">' + escapeHtml(group.title) + "</h4>";
      html +=
        '<table cellpadding="4" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-size:13px;">';
      (group.fields || []).forEach((field) => {
        const rawVal = formState[field.name];
        let displayVal = "";
        if (rawVal === null || rawVal === undefined || rawVal === "") {
          displayVal = "";
        } else if (field.type === "checkbox") {
          displayVal = rawVal ? "Yes" : "No";
        } else {
          displayVal = String(rawVal);
        }
        html += "<tr>";
        html +=
          '<td style="border-bottom:1px solid #eee;width:35%;vertical-align:top;"><strong>' +
          escapeHtml(field.label || field.name) +
          "</strong></td>";
        html +=
          '<td style="border-bottom:1px solid #eee;width:65%;vertical-align:top;">' +
          escapeHtml(displayVal) +
          "</td>";
        html += "</tr>";
      });
      html += "</table>";
    });
  });

  return html;
}

async function handleSubmit() {
  // [GRASP-DERIVED] Make sure combined fields reflect latest parts
  syncDerivedFields();

  // validate all steps before submit
  let allValid = true;
  for (let i = 0; i < config.steps.length; i++) {
    if (!validateStep(i)) {
      allValid = false;
    }
  }
  if (!allValid) {
    setStatus(
      "Please complete all required fields before submitting.",
      "error"
    );
    // jump to the first invalid step for convenience
    currentStepIndex = 0;
    renderCurrentStep();
    return;
  }

  setStatus("Submitting, please wait…");

  await saveFormState();

  const payload = {
    formId: FORM_ID,
    sessionId,
    submittedAt: new Date().toISOString(),
    data: formState,
    emailHtml: buildEmailHtml(),
  };

  try {
    const response = await fetch("api/submit_enrollment.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      throw new Error("HTTP error " + response.status);
    }

    const result = await response.json();
    if (result && result.success) {
      setStatus(
        "Thank you! Your enrollment form has been submitted.",
        "success"
      );
      clearLocalStorage();
    } else {
      setStatus(
        "There was a problem sending your form. Please contact the office.",
        "error"
      );
    }
  } catch (err) {
    console.error(err);
    setStatus(
      "Network error while submitting the form. Please try again later.",
      "error"
    );
  }
}

/**
 * Initial bootstrapping
 */
async function initEnrollmentForm() {
  const root = byId("enrollment-root");
  if (!root) return;

  // Load config
  try {
    const res = await fetch("config/enrollment-fields.json", {
      cache: "no-cache",
    });
    config = await res.json();
  } catch (err) {
    console.error("Failed to load fields config", err);
    setStatus("Unable to load enrollment form configuration.", "error");
    return;
  }

  await loadFormState();

  // Wire up buttons
  const btnPrev = byId("grasp-btn-prev");
  const btnNext = byId("grasp-btn-next");
  const btnSave = byId("grasp-btn-save");
  const btnSubmit = byId("grasp-btn-submit");

  if (btnPrev)
    btnPrev.addEventListener("click", (e) => {
      e.preventDefault();
      handlePrev();
    });
  if (btnNext)
    btnNext.addEventListener("click", (e) => {
      e.preventDefault();
      handleNext();
    });
  if (btnSave)
    btnSave.addEventListener("click", (e) => {
      e.preventDefault();
      handleSave();
    });
  if (btnSubmit)
    btnSubmit.addEventListener("click", (e) => {
      e.preventDefault();
      handleSubmit();
    });

  renderCurrentStep();
  setStatus("");

  // Notify optional debug helpers that initialization is complete.
  if (
    typeof window !== "undefined" &&
    typeof window.dispatchEvent === "function"
  ) {
    try {
      const evt = new CustomEvent("graspEnrollmentInit", {
        detail: { config, formState, sessionId },
      });
      window.dispatchEvent(evt);
    } catch (e) {
      // Older browsers may not support CustomEvent without a polyfill; fail silently.
      console.warn("Failed to dispatch graspEnrollmentInit event", e);
    }
  }
}

function openPreview() {
  // [GRASP-DERIVED] Make sure combined fields reflect latest parts
  syncDerivedFields();

  // Validate all steps before allowing preview
  let allValid = true;
  let firstInvalidStep = -1;

  for (let i = 0; i < config.steps.length; i++) {
    const stepOk = validateStep(i);
    if (!stepOk) {
      allValid = false;
      if (firstInvalidStep === -1) {
        firstInvalidStep = i;
      }
    }
  }

  if (!allValid) {
    // Show a global status message
    setStatus(
      "Please correct the highlighted errors before previewing your submission.",
      "error"
    );

    // Jump to the first invalid step so the user can see and fix the issue
    if (firstInvalidStep !== -1 && firstInvalidStep !== currentStepIndex) {
      currentStepIndex = firstInvalidStep;
      renderCurrentStep();
      // After rendering, run validation again so error messages appear
      validateStep(firstInvalidStep);
    }

    return; // Do not open preview
  }

  // All steps valid: build preview as before
  const modal = byId("grasp-preview-modal");
  const content = byId("grasp-preview-content");
  content.innerHTML = buildEmailHtml(); // reuse your existing HTML
  modal.classList.remove("hidden");
  modal.setAttribute("aria-hidden", "false");
}


function closePreview() {
  const modal = byId("grasp-preview-modal");
  modal.classList.add("hidden");
  modal.setAttribute("aria-hidden", "true");
}

document.addEventListener("DOMContentLoaded", () => {
  initEnrollmentForm();
  const btnPreview = byId("grasp-btn-preview");
  const btnPrevClose = byId("grasp-preview-close");
  const btnPrevEdit = byId("grasp-preview-edit");
  const btnPrevConfirm = byId("grasp-preview-confirm");
  if (btnPreview)
    btnPreview.addEventListener("click", (e) => {
      e.preventDefault();
      openPreview();
    });
  if (btnPrevClose) btnPrevClose.addEventListener("click", closePreview);
  if (btnPrevEdit)
    btnPrevEdit.addEventListener("click", (e) => {
      e.preventDefault();
      closePreview();
    });
  if (btnPrevConfirm)
    btnPrevConfirm.addEventListener("click", async (e) => {
      e.preventDefault();
      closePreview();
      await handleSubmit(); // submits to PHP as before
    });
});

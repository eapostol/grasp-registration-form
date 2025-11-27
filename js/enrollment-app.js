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
    li.textContent = (index + 1) + ". " + step.title;
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

let draftTimer=null;
function scheduleDraftSave(){
  clearTimeout(draftTimer);
  draftTimer = setTimeout(async ()=>{
    try {
      await fetch("api/save_draft.php", {
        method:"POST", headers:{ "Content-Type":"application/json" },
        body: JSON.stringify({ formId: FORM_ID, sessionId, data: formState })
      });
    } catch(e) {}
  }, 800);
}

function setFieldValue(name, value) {
  formState[name] = value;
  // existing local save call if present...
  scheduleDraftSave();
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
    lbl.appendChild(document.createTextNode(" " + (fieldDef.checkboxLabel || "Yes")));

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
      if (!fieldDef.required) return;

      const value = formState[fieldDef.name];
      const errorEl = byId("error_" + fieldDef.name);
      if (errorEl) errorEl.textContent = "";

      if (fieldDef.type === "radio") {
        if (!value) {
          valid = false;
          if (errorEl) {
            errorEl.textContent =
              (config.validationMessages && config.validationMessages.radioRequired) ||
              "Please select an option.";
          }
        }
      } else if (fieldDef.type === "checkbox") {
        if (!value) {
          valid = false;
          if (errorEl) {
            errorEl.textContent =
              (config.validationMessages && config.validationMessages.required) ||
              "This field is required.";
          }
        }
      } else {
        if (!value || String(value).trim() === "") {
          valid = false;
          if (errorEl) {
            errorEl.textContent =
              (config.validationMessages && config.validationMessages.required) ||
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
    btnNext.style.display = currentStepIndex === lastIndex ? "none" : "inline-block";
  }
  if (btnSubmit) {
    btnSubmit.style.display = currentStepIndex === lastIndex ? "inline-block" : "none";
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
  await saveFormState();
  setStatus("Saved on this device. You can return later using the same browser.", "success");
}

/**
 * Build an HTML representation of the submission that roughly matches
 * the PDF structure, using the JSON config + formState.
 */
function buildEmailHtml() {
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
  html += "<h2>Greenland Recreational After School Program – Enrollment Form</h2>";
  html += "<p><strong>Submitted at:</strong> " + new Date().toLocaleString() + "</p>";
  html += "<p><strong>Session ID:</strong> " + escapeHtml(sessionId) + "</p>";

  config.steps.forEach((step, stepIndex) => {
    html += '<h3 style="margin-top:18px;border-top:1px solid #ccc;padding-top:8px;">'
      + (stepIndex + 1) + ". " + escapeHtml(step.title) + "</h3>";
    if (step.description) {
      html += "<p>" + escapeHtml(step.description) + "</p>";
    }

    (step.groups || []).forEach((group) => {
      html += '<h4 style="margin:8px 0 4px 0;">' + escapeHtml(group.title) + "</h4>";
      html += '<table cellpadding="4" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-size:13px;">';
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
        html += '<td style="border-bottom:1px solid #eee;width:35%;vertical-align:top;"><strong>' +
          escapeHtml(field.label || field.name) + "</strong></td>";
        html += '<td style="border-bottom:1px solid #eee;width:65%;vertical-align:top;">' +
          escapeHtml(displayVal) + "</td>";
        html += "</tr>";
      });
      html += "</table>";
    });
  });

  return html;
}

async function handleSubmit() {
  // validate all steps before submit
  let allValid = true;
  for (let i = 0; i < config.steps.length; i++) {
    if (!validateStep(i)) {
      allValid = false;
    }
  }
  if (!allValid) {
    setStatus("Please complete all required fields before submitting.", "error");
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
      setStatus("Thank you! Your enrollment form has been submitted.", "success");
      clearLocalStorage();
    } else {
      setStatus("There was a problem sending your form. Please contact the office.", "error");
    }
  } catch (err) {
    console.error(err);
    setStatus("Network error while submitting the form. Please try again later.", "error");
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
    const res = await fetch("config/enrollment-fields.json", { cache: "no-cache" });
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

  if (btnPrev) btnPrev.addEventListener("click", (e) => { e.preventDefault(); handlePrev(); });
  if (btnNext) btnNext.addEventListener("click", (e) => { e.preventDefault(); handleNext(); });
  if (btnSave) btnSave.addEventListener("click", (e) => { e.preventDefault(); handleSave(); });
  if (btnSubmit) btnSubmit.addEventListener("click", (e) => { e.preventDefault(); handleSubmit(); });

  renderCurrentStep();
  setStatus("");
}

function openPreview() {
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
  if (btnPreview) btnPreview.addEventListener("click", (e)=>{e.preventDefault(); openPreview();});
  if (btnPrevClose) btnPrevClose.addEventListener("click", closePreview);
  if (btnPrevEdit) btnPrevEdit.addEventListener("click", (e)=>{e.preventDefault(); closePreview();});
  if (btnPrevConfirm) btnPrevConfirm.addEventListener("click", async (e)=>{
    e.preventDefault();
    closePreview();
    await handleSubmit(); // submits to PHP as before
  });
});
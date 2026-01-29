/* global indexedDB, crypto */
/**
 * GRASP shared "package draft" (Enrollment + Waitlist + later Agreement)
 * - Encrypted storage in localStorage + IndexedDB
 * - Versioned reconciliation (newest wins)
 * - 30-day stale draft prompt
 * - Clear-all-drafts (Enrollment + Waitlist + Package) + reload
 */

(function () {
  "use strict";

  // -----------------------------
  // Config
  // -----------------------------
  const PACKAGE_FORM_ID = "grasp_package_2025";
  const PACKAGE_SCHEMA_VERSION = 1;

  const PACKAGE_STORAGE_KEY_ENCRYPTED = "graspPackageEncryptedData";
  const PACKAGE_STORAGE_KEY_SESSION_ID = "graspPackageSessionId";

  const PACKAGE_DB_NAME = "graspPackageDB";
  const PACKAGE_DB_STORE = "sessions";

  // NOTE: This is client-side only; for true security you would not hardcode this.
  // For now it matches the project’s existing approach (Enrollment/Waitlist drafts).
  const PACKAGE_SECRET_KEY_STRING = "grasp-package-demo-secret-32-chars!!";

  const TTL_DAYS = 30;
  const TTL_MS = TTL_DAYS * 24 * 60 * 60 * 1000;

  // Known draft keys in this project (cleared by clearAllDrafts)
  const ENROLLMENT_STORAGE_KEY_ENCRYPTED = "graspEnrollmentEncryptedData";
  const ENROLLMENT_STORAGE_KEY_SESSION_ID = "graspEnrollmentSessionId";
  const ENROLLMENT_DB_NAME = "graspEnrollmentDB";

  const WAITLIST_STORAGE_KEY_ENCRYPTED = "graspWaitlistEncryptedData";
  const WAITLIST_STORAGE_KEY_SESSION_ID = "graspWaitlistSessionId";
  const WAITLIST_DB_NAME = "graspWaitlistDB";

  // -----------------------------
  // Helpers
  // -----------------------------
  function isEmptyValue(v) {
    return v === null || v === undefined || (typeof v === "string" && v.trim() === "");
  }

  function safeParseJSON(s) {
    try {
      return JSON.parse(s);
    } catch (_) {
      return null;
    }
  }

  function nowIso() {
    return new Date().toISOString();
  }

  function parseIsoToMs(iso) {
    const t = Date.parse(iso || "");
    return Number.isFinite(t) ? t : 0;
  }

  function generateSessionId() {
    try {
      if (crypto && crypto.randomUUID) return crypto.randomUUID();
    } catch (_) {}
    // fallback
    return "sid_" + Math.random().toString(16).slice(2) + "_" + Date.now();
  }

  // -----------------------------
  // Crypto (AES-GCM)
  // -----------------------------
  let cryptoKeyCache = null;

  async function getCryptoKey() {
    if (cryptoKeyCache) return cryptoKeyCache;
    const enc = new TextEncoder();
    const rawKey = enc.encode(PACKAGE_SECRET_KEY_STRING.padEnd(32, "0").slice(0, 32));
    cryptoKeyCache = await crypto.subtle.importKey("raw", rawKey, { name: "AES-GCM" }, false, [
      "encrypt",
      "decrypt",
    ]);
    return cryptoKeyCache;
  }

  async function encryptData(plainText) {
    const key = await getCryptoKey();
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const encoded = new TextEncoder().encode(plainText);
    const ciphertext = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, encoded);
    return {
      iv: btoa(String.fromCharCode(...iv)),
      data: btoa(String.fromCharCode(...new Uint8Array(ciphertext))),
    };
  }

  async function decryptData(encrypted) {
    try {
      if (!encrypted || !encrypted.iv || !encrypted.data) return null;

      const key = await getCryptoKey();
      const iv = Uint8Array.from(atob(encrypted.iv), (c) => c.charCodeAt(0));
      const data = Uint8Array.from(atob(encrypted.data), (c) => c.charCodeAt(0));

      const plainBuffer = await crypto.subtle.decrypt({ name: "AES-GCM", iv }, key, data);
      return new TextDecoder().decode(plainBuffer);
    } catch (err) {
      console.warn("[GRASP][package] decrypt failed", err);
      return null;
    }
  }

  // -----------------------------
  // IndexedDB (simple KV store)
  // -----------------------------
  function openDb(dbName, storeName) {
    return new Promise((resolve, reject) => {
      if (!window.indexedDB) return resolve(null);

      const req = window.indexedDB.open(dbName, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(storeName)) {
          db.createObjectStore(storeName);
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }

  async function idbGet(dbName, storeName, key) {
    const db = await openDb(dbName, storeName);
    if (!db) return null;

    return new Promise((resolve) => {
      const tx = db.transaction(storeName, "readonly");
      const store = tx.objectStore(storeName);
      const req = store.get(key);
      req.onsuccess = () => resolve(req.result ?? null);
      req.onerror = () => resolve(null);
    });
  }

  async function idbSet(dbName, storeName, key, value) {
    const db = await openDb(dbName, storeName);
    if (!db) return false;

    return new Promise((resolve) => {
      const tx = db.transaction(storeName, "readwrite");
      const store = tx.objectStore(storeName);
      const req = store.put(value, key);
      req.onsuccess = () => resolve(true);
      req.onerror = () => resolve(false);
    });
  }

  function idbDeleteDatabase(dbName) {
    return new Promise((resolve) => {
      if (!window.indexedDB) return resolve(true);
      const req = window.indexedDB.deleteDatabase(dbName);
      req.onsuccess = () => resolve(true);
      req.onerror = () => resolve(false);
      req.onblocked = () => resolve(false);
    });
  }

  // -----------------------------
  // Package payload + reconciliation
  // -----------------------------
  function buildEmptyPackage(sessionId) {
    return {
      formId: PACKAGE_FORM_ID,
      schemaVersion: PACKAGE_SCHEMA_VERSION,
      sessionId,
      version: 1,
      updatedAt: nowIso(),
      registrant: {},
      status: {
        enrollmentSubmittedAt: null,
        waitlistSubmittedAt: null,
        agreementSubmittedAt: null,
      },
    };
  }

  function getMeta(p) {
    const version = Number.isFinite(Number(p?.version)) ? Number(p.version) : 0;
    const updatedAtMs = parseIsoToMs(p?.updatedAt || p?.savedAt);
    return { version, updatedAtMs };
  }

  function chooseNewest(a, b) {
    // a,b = {payload, encrypted}
    const ma = getMeta(a?.payload);
    const mb = getMeta(b?.payload);

    if (ma.version !== mb.version) return ma.version > mb.version ? a : b;
    return ma.updatedAtMs >= mb.updatedAtMs ? a : b;
  }

  async function loadDecryptedFromEncrypted(encryptedObj) {
    const s = await decryptData(encryptedObj);
    if (!s) return null;
    const payload = safeParseJSON(s);
    if (!payload || payload.formId !== PACKAGE_FORM_ID) return null;
    return payload;
  }

  async function syncStores(sessionId, encryptedObj) {
    try {
      localStorage.setItem(PACKAGE_STORAGE_KEY_SESSION_ID, sessionId);
      localStorage.setItem(PACKAGE_STORAGE_KEY_ENCRYPTED, JSON.stringify(encryptedObj));
    } catch (e) {
      console.warn("[GRASP][package] localStorage sync failed", e);
    }
    await idbSet(PACKAGE_DB_NAME, PACKAGE_DB_STORE, sessionId, encryptedObj);
  }

  async function load() {
    let localEncrypted = null;
    let localPayload = null;

    const localStr = localStorage.getItem(PACKAGE_STORAGE_KEY_ENCRYPTED);
    if (localStr) {
      localEncrypted = safeParseJSON(localStr);
      if (localEncrypted) {
        localPayload = await loadDecryptedFromEncrypted(localEncrypted);
      }
    }

    // If we don't have a sessionId stored, try to derive it from decrypted payload.
    let sessionId = localStorage.getItem(PACKAGE_STORAGE_KEY_SESSION_ID);
    if (!sessionId && localPayload?.sessionId) {
      sessionId = localPayload.sessionId;
      try {
        localStorage.setItem(PACKAGE_STORAGE_KEY_SESSION_ID, sessionId);
      } catch (_) {}
    }

    let idbEncrypted = null;
    let idbPayload = null;

    if (sessionId) {
      idbEncrypted = await idbGet(PACKAGE_DB_NAME, PACKAGE_DB_STORE, sessionId);
      if (idbEncrypted) {
        idbPayload = await loadDecryptedFromEncrypted(idbEncrypted);
      }
    }

    const candidates = [];
    if (localPayload && localEncrypted) candidates.push({ payload: localPayload, encrypted: localEncrypted, src: "ls" });
    if (idbPayload && idbEncrypted) candidates.push({ payload: idbPayload, encrypted: idbEncrypted, src: "idb" });

    if (candidates.length === 0) return null;

    const chosen = candidates.length === 1 ? candidates[0] : chooseNewest(candidates[0], candidates[1]);

    // keep stores in sync with chosen
    const chosenSessionId = chosen.payload.sessionId || sessionId || generateSessionId();
    await syncStores(chosenSessionId, chosen.encrypted);

    return chosen.payload;
  }

  async function save(nextPayload) {
    const sessionId = nextPayload.sessionId || localStorage.getItem(PACKAGE_STORAGE_KEY_SESSION_ID) || generateSessionId();

    const prev = await load();
    const prevVersion = Number.isFinite(Number(prev?.version)) ? Number(prev.version) : 0;

    const payload = {
      ...buildEmptyPackage(sessionId),
      ...(prev || {}),
      ...(nextPayload || {}),
      sessionId,
      version: Math.max(prevVersion + 1, Number(nextPayload?.version || 0), 1),
      updatedAt: nextPayload?.updatedAt || nowIso(),
    };

    const encrypted = await encryptData(JSON.stringify(payload));
    await syncStores(sessionId, encrypted);

    return payload;
  }

  async function upsertRegistrant(partialRegistrant, opts = {}) {
    const onlyFillMissing = !!opts.onlyFillMissing;
    const pkg = (await load()) || buildEmptyPackage(localStorage.getItem(PACKAGE_STORAGE_KEY_SESSION_ID) || generateSessionId());

    const nextRegistrant = { ...(pkg.registrant || {}) };
    const incoming = partialRegistrant || {};

    for (const [k, v] of Object.entries(incoming)) {
      if (isEmptyValue(v)) continue;
      if (onlyFillMissing && !isEmptyValue(nextRegistrant[k])) continue;
      nextRegistrant[k] = v;
    }

    return save({ ...pkg, registrant: nextRegistrant });
  }

  async function setStatus(partialStatus) {
    const pkg = (await load()) || buildEmptyPackage(localStorage.getItem(PACKAGE_STORAGE_KEY_SESSION_ID) || generateSessionId());
    const nextStatus = { ...(pkg.status || {}) };

    for (const [k, v] of Object.entries(partialStatus || {})) {
      nextStatus[k] = v;
    }

    return save({ ...pkg, status: nextStatus });
  }

  async function checkAndHandleStaleDraft() {
    const pkg = await load();
    if (!pkg?.updatedAt) return true;

    const ageMs = Date.now() - parseIsoToMs(pkg.updatedAt);
    if (ageMs <= TTL_MS) return true;

    // Confirm prompt:
    // OK => resume
    // Cancel => clear + reload
    const msg =
      `We found a saved GRASP application draft that is older than ${TTL_DAYS} days.\n\n` +
      `Press OK to resume it.\n` +
      `Press Cancel to clear saved data and start fresh.`;

    const resume = window.confirm(msg);
    if (resume) return true;

    await clearAllDrafts();
    window.location.reload();
    return false;
  }

  async function clearAllDrafts() {
    // Remove LS keys
    try {
      localStorage.removeItem(PACKAGE_STORAGE_KEY_ENCRYPTED);
      localStorage.removeItem(PACKAGE_STORAGE_KEY_SESSION_ID);

      localStorage.removeItem(ENROLLMENT_STORAGE_KEY_ENCRYPTED);
      localStorage.removeItem(ENROLLMENT_STORAGE_KEY_SESSION_ID);

      localStorage.removeItem(WAITLIST_STORAGE_KEY_ENCRYPTED);
      localStorage.removeItem(WAITLIST_STORAGE_KEY_SESSION_ID);

      // optional flags
      localStorage.removeItem("graspWaitlistPrefilledFromEnrollment");
    } catch (err) {
      console.warn("[GRASP][package] clear localStorage failed", err);
    }

    // Delete DBs
    await idbDeleteDatabase(PACKAGE_DB_NAME);
    await idbDeleteDatabase(ENROLLMENT_DB_NAME);
    await idbDeleteDatabase(WAITLIST_DB_NAME);

    return true;
  }

  // Expose
  window.GRASP_PACKAGE_DRAFT = {
    load,
    save,
    upsertRegistrant,
    setStatus,
    checkAndHandleStaleDraft,
    clearAllDrafts,
    _consts: {
      TTL_DAYS,
      PACKAGE_FORM_ID,
      PACKAGE_STORAGE_KEY_ENCRYPTED,
      PACKAGE_DB_NAME,
    },
  };
})();
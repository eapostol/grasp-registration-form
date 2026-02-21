// enrollment-debug.js
// Optional DEBUG helpers for the GRASP enrollment form.
// Activate by appending ?DEBUG=true (or 1/yes) to the URL. In normal
// usage (no DEBUG param), this file is effectively a no-op.

(function () {
  "use strict";

  // [GRASP-DEBUG]
  // Design goals (Enrollment + Wait List):
  // 1) DEBUG is opt-in via ?debug=true (or ?DEBUG=true).
  // 2) If stored values already exist (local draft / enrollment-prefill), DO NOT override them.
  // 3) DEBUG fills only missing/empty fields.
  // 4) Works for both pages by responding to both init events:
  //      - graspEnrollmentInit
  //      - graspWaitlistInit
  //    and also supports a fallback timer in case this script loads after the event.

  function detectDebugMode() {
    try {
      var params = new URLSearchParams(window.location.search);
      var raw = params.get("DEBUG") || params.get("debug");
      if (!raw) return false;
      var value = String(raw).toLowerCase();
      return value === "true" || value === "1" || value === "yes";
    } catch (e) {
      console.warn("DEBUG: failed to read DEBUG query param", e);
      return false;
    }
  }

  function toTitleCase(str) {
    if (!str) return "";
    return String(str)
      .toLowerCase()
      .replace(/\b\w/g, function (ch) {
        return ch.toUpperCase();
      });
  }

  function generateRandomCanadianPostalCode() {
    // Generate a valid-looking Canadian postal code in the format A1A 1A1.
    // This is ONLY for test / DEBUG data.
    var letters = "ABCEGHJKLMNPRSTVWXYZ";
    var digits = "0123456789";
    var pick = function (chars) {
      return chars.charAt(Math.floor(Math.random() * chars.length));
    };
    return (
      pick(letters) +
      pick(digits) +
      pick(letters) +
      " " +
      pick(digits) +
      pick(letters) +
      pick(digits)
    );
  }

  function generateRandomTorontoPostalCode() {
    // Toronto postal codes begin with "M" (GTA).
    var letters = "ABCEGHJKLMNPRSTVWXYZ";
    var digits = "0123456789";
    var pick = function (chars) {
      return chars.charAt(Math.floor(Math.random() * chars.length));
    };
    return (
      "M" +
      pick(digits) +
      pick(letters) +
      " " +
      pick(digits) +
      pick(letters) +
      pick(digits)
    );
  }

  function collectAllFieldNamesFromConfig(cfg) {
    var names = new Set();
    if (!cfg || !cfg.steps) return names;
    cfg.steps.forEach(function (step) {
      (step.groups || []).forEach(function (group) {
        (group.fields || []).forEach(function (field) {
          if (field && field.name) {
            names.add(field.name);
          }
        });
      });
    });
    return names;
  }

  function addDebugBadge() {
    if (document.getElementById("grasp-debug-badge")) return;

    var badge = document.createElement("div");
    badge.id = "grasp-debug-badge";
    badge.textContent = "DEBUG MODE";

    badge.style.position = "fixed";
    badge.style.bottom = "16px";
    badge.style.left = "16px";
    badge.style.padding = "6px 10px";
    badge.style.background = "rgba(200, 0, 0, 0.9)";
    badge.style.color = "#ffffff";
    badge.style.fontSize = "11px";
    badge.style.fontFamily =
      "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    badge.style.letterSpacing = "0.05em";
    badge.style.textTransform = "uppercase";
    badge.style.borderRadius = "4px";
    badge.style.zIndex = "9999";
    badge.style.boxShadow = "0 2px 4px rgba(0, 0, 0, 0.3)";
    badge.style.pointerEvents = "none";

    if (document.body) {
      document.body.appendChild(badge);
    } else {
      document.addEventListener("DOMContentLoaded", function () {
        if (!document.getElementById("grasp-debug-badge")) {
          document.body.appendChild(badge);
        }
      });
    }
  }

  // [GRASP-DEBUG] Build a bundle of first/last names for split-name fields.
  // [GRASP-DEBUG] Build a bundle of first/last names for split-name fields.
  // NOTE (2026-02): randomuser.me no longer permits cross-origin browser fetches
  // in many environments, causing noisy CORS console errors. We now generate
  // realistic Canadian-style names locally for debug mode.
  async function buildDebugNameBundle() {
    // Optional: allow external randomuser API when explicitly enabled.
    // Set window.GRASP_DEBUG_USE_RANDOMUSER = true before this runs if desired.
    var allowExternal = (typeof window !== "undefined" && window.GRASP_DEBUG_USE_RANDOMUSER === true);

    function pick(arr) {
      return arr[Math.floor(Math.random() * arr.length)];
    }

    function toTitleCaseSafe(s) {
      return toTitleCase(String(s || ""));
    }

    // Local fallback lists (small but varied)
    var FIRST_NAMES = [
      "Liam","Noah","Oliver","Elijah","James","William","Benjamin","Lucas","Henry","Theodore",
      "Charlotte","Amelia","Olivia","Ava","Sophia","Isabella","Mia","Evelyn","Harper","Ella",
      "Mason","Ethan","Logan","Aiden","Jacob","Michael","Daniel","Jackson","Sebastian","Jack",
      "Emily","Abigail","Grace","Chloe","Lily","Zoey","Hannah","Aria","Scarlett","Victoria"
    ];
    var LAST_NAMES = [
      "Martin","Roy","Gagnon","Lee","Wilson","Taylor","Brown","Anderson","Clark","Wright",
      "Johnson","MacDonald","Campbell","Thompson","Nguyen","Singh","Patel","Kaur","Chen","Wong"
    ];

    function buildLocal() {
      var lastName = toTitleCaseSafe(pick(LAST_NAMES));
      return {
        childFirst: toTitleCaseSafe(pick(FIRST_NAMES)),
        childLast: lastName,
        parent1First: toTitleCaseSafe(pick(FIRST_NAMES)),
        parent1Last: lastName,
        parent2First: toTitleCaseSafe(pick(FIRST_NAMES)),
        parent2Last: lastName,
        witnessFirst: toTitleCaseSafe(pick(FIRST_NAMES)),
        witnessLast: toTitleCaseSafe(pick(LAST_NAMES)),
      };
    }

    if (!allowExternal) {
      return buildLocal();
    }

    // External mode (may fail due to CORS) — fall back silently to local.
    try {
      var res = await fetch("https://randomuser.me/api/?results=3&nat=ca");
      if (!res.ok) throw new Error("Bad response from randomuser");
      var data = await res.json();
      var results = (data && data.results) || [];
      if (!results.length) throw new Error("No random users returned");

      var childUser = results[0];
      var parent1User = results[1] || results[0];
      var parent2User = results[2] || results[1] || results[0];

      var lastName = toTitleCaseSafe(childUser.name.last);
      var childFirst = toTitleCaseSafe(childUser.name.first);
      var parent1First = toTitleCaseSafe(parent1User.name.first);
      var parent2First = toTitleCaseSafe(parent2User.name.first);

      return {
        childFirst: childFirst,
        childLast: lastName,
        parent1First: parent1First,
        parent1Last: lastName,
        parent2First: parent2First,
        parent2Last: lastName,
        witnessFirst: toTitleCaseSafe(pick(FIRST_NAMES)),
        witnessLast: toTitleCaseSafe(pick(LAST_NAMES)),
      };
    } catch (e) {
      // Keep the console clean — this is debug-only.
      return buildLocal();
    }
  }

  // [GRASP-DEBUG] Provide explicit overrides for key split fields (names + addresses).
  async function buildDebugFormStateOverrides() {
    var names = await buildDebugNameBundle();
    var now = new Date();
    var testYear = now.getFullYear() - 3; // three years younger than current year
    var testBirthDate = String(testYear).padStart(4, "0") + "-12-12";

    // Random ISO date within the past year (YYYY-MM-DD) for <input type="date">
    function pad2(n) {
      return (n < 10 ? "0" : "") + String(n);
    }

    function randomIsoDateWithinPastYear() {
      var today = new Date();
      var daysBack = Math.floor(Math.random() * 365);
      var d = new Date(today.getTime() - daysBack * 24 * 60 * 60 * 1000);
      return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
    }

    // Use valid-looking Canadian postal codes and split into halves.
    function splitPostal(code) {
      if (!code || code.length < 7) {
        return { p1: "", p2: "" };
      }
      return {
        p1: code.substring(0, 3),
        p2: code.substring(4, 7),
      };
    }

    var homePostalFull = generateRandomCanadianPostalCode();
    var workPostalFull = generateRandomCanadianPostalCode();
    var doctorPostalFull = generateRandomCanadianPostalCode();

    var homePostal = splitPostal(homePostalFull);
    var workPostal = splitPostal(workPostalFull);
    var doctorPostal = splitPostal(doctorPostalFull);

    var overrides = {
      // -------------------------
      // Child
      // -------------------------
      child_first_name: names.childFirst,
      child_middle_name_or_initial: "",
      child_last_name: names.childLast,
      child_birth_date: testBirthDate,

      // -------------------------
      // Parent / Guardian 1
      // -------------------------
      parent1_first_name: names.parent1First,
      parent1_last_name: names.parent1Last,
      parent1_email: "test@test.com",

      parent1_home_street: "456 Sample Street",
      parent1_home_unit: "",
      parent1_home_city: "Toronto",
      parent1_home_province: "ON",
      parent1_home_postal1: homePostal.p1,
      parent1_home_postal2: homePostal.p2,
      parent1_phones: "111-222-3333",

      parent1_work_street: "123 Anywhere Street",
      parent1_work_unit: "",
      parent1_work_city: "Toronto",
      parent1_work_province: "ON",
      parent1_work_postal1: workPostal.p1,
      parent1_work_postal2: workPostal.p2,
      parent1_work_phone: "222-333-4444",

      // -------------------------
      // Parent / Guardian 2 (optional)
      // -------------------------
      parent2_first_name: names.parent2First,
      parent2_last_name: names.parent2Last,
      parent2_email: "test@test.com",

      parent2_home_street: "456 Sample Street",
      parent2_home_unit: "",
      parent2_home_city: "Toronto",
      parent2_home_province: "ON",
      parent2_home_postal1: homePostal.p1,
      parent2_home_postal2: homePostal.p2,
      parent2_phones: "111-222-3333",

      parent2_work_street: "123 Anywhere Street",
      parent2_work_unit: "",
      parent2_work_city: "Toronto",
      parent2_work_province: "ON",
      parent2_work_postal1: workPostal.p1,
      parent2_work_postal2: workPostal.p2,
      parent2_work_phone: "222-333-4444",

      // -------------------------
      // Doctor / Clinic
      // -------------------------
      doctor_name: "Dr. Test Physician",
      doctor_phone: "333-444-5555",
      doctor_street: "789 Clinic Road",
      doctor_unit: "Suite 10",
      doctor_city: "Toronto",
      doctor_province: "ON",
      doctor_postal1: doctorPostal.p1,
      doctor_postal2: doctorPostal.p2,

      // -------------------------
      // Final Acknowledgement & Signature
      // -------------------------
      // Parent/Guardian full name should match Parent/Guardian 1 when DEBUG is enabled.
      parent_full_name_signature: names.parent1First + ' ' + names.parent1Last,
      witness: names.witnessFirst + ' ' + names.witnessLast,

      // -------------------------
      // General Health
      // -------------------------
      // "Date of last medical examination" should be a real date in DEBUG mode.
      last_medical_exam_date: randomIsoDateWithinPastYear(),
    };

    return overrides;
  }

  function generateDebugFieldValue(field) {
    if (!field) return undefined;

    // [GRASP-DEBUG] Hidden / derived fields are computed via syncDerivedFields,
    // so we do not assign them directly in DEBUG mode.
    if (field.type === "hidden") {
      return undefined;
    }

    var type = field.type || "text";
    var name = (field.name || "").toLowerCase();
    var label = (field.label || "").toLowerCase();
    var options = field.options || [];

    // [GRASP-DEBUG] Explicit field overrides (must run BEFORE generic postal/phone rules).
    if (name === "emergency_contact_address") {
      return "123 Someplace Street, Toronto, Ontario, M1M 2M2";
    }
    if (name === "authorized_pickups") {
      return [
        "Jane Doe, aunt, 123-456-7893",
        "John Smith, uncle, 123-345-6666",
        "June Cleaver, cousin, 123-343-2489",
      ].join("\n");
    }


    // Emails
    if (label.includes("email") || name.includes("email")) {
      return "test@test.com";
    }

    // Phones
    if (
      label.includes("phone") ||
      label.includes("cell") ||
      label.includes("home #") ||
      name.includes("phone") ||
      name.includes("phones")
    ) {
      // Work phones
      if (label.includes("work") || name.includes("work")) {
        return "222-333-4444";
      }
      return "111-222-3333";
    }

    // Postal codes
    if (label.includes("postal") || name.includes("postal")) {
      return generateRandomCanadianPostalCode();
    }

    // Emergency contact day-time address handled above via explicit override.
    // Addresses
    if (label.includes("address") || name.includes("address")) {
      return "123 Anywhere Street\nToronto, Ontario\nM1M 2M2";
    }

    // City / Province
    if (label.includes("city") || name.includes("city")) {
      return "Toronto";
    }
    if (label.includes("province") || label.includes("state")) {
      return "Ontario";
    }

    // Relationship
    if (label.includes("relationship")) {
      return "Parent";
    }

    // Emergency / pickup contacts
    if (label.includes("emergency contact") && label.includes("name")) {
      return "Test Emergency Contact";
    }
    if (label.includes("emergency contact") && label.includes("phone")) {
      return "333-444-5555";
    }
    if (name === "authorized_pickups" || (label.includes("authorized") && (label.includes("pick up") || label.includes("pick ups") || label.includes("pickups") || label.includes("pickup")))) {
      return [
        "Jane Doe, aunt, 123-456-7893",
        "John Smith, uncle, 123-345-6666",
        "June Cleaver, cousin, 123-343-2489",
      ].join("\n");
    }

    // Doctors / clinics
    if (label.includes("doctor")) {
      return "Dr. Test Physician";
    }
    if (label.includes("clinic")) {
      return "Test Clinic";
    }

    // Allergies / medical / medications
    if (label.includes("allerg")) {
      return "No known allergies.";
    }
    if (label.includes("medical") || label.includes("condition")) {
      return "No known medical conditions.";
    }
    if (label.includes("medication")) {
      return "None.";
    }

    // School / grade (text-ish fields only). Avoid overriding radio/select values
    // for acknowledgement questions that mention "school" in their label.
    if (label.includes("school") && type !== "radio" && type !== "select") {
      return "Sample Elementary School";
    }
    if (label.includes("grade") && type !== "radio" && type !== "select") {
      return "3";
    }

    // Specific override: for "Date of last medical examination" use today's date (YYYY-MM-DD)
    if (label.toLowerCase().includes("date of last medical examination")) {
      var today = new Date();
      var mm = String(today.getMonth() + 1).padStart(2, "0");
      var dd = String(today.getDate()).padStart(2, "0");
      return today.getFullYear() + "-" + mm + "-" + dd;
    }
    // Dates (other than birth date, which we explicitly handle)
    if (label.includes("date")) {
      var now = new Date();
      var year = now.getFullYear();
      return String(year) + "-09-01";
    }

    // Radio / select: choose "yes" if available, otherwise first option
    if (type === "radio" || type === "select") {
      var selected = null;
      // prefer an option whose value looks like "yes"
      for (var i = 0; i < options.length; i++) {
        var opt = options[i];
        if (!opt) continue;
        var v =
          typeof opt === "string"
            ? opt
            : typeof opt.value !== "undefined"
            ? opt.value
            : null;
        if (v && String(v).toLowerCase() === "yes") {
          selected = v;
          break;
        }
      }
      // if nothing matched "yes", pick the first non-empty option
      if (!selected) {
        for (var j = 0; j < options.length; j++) {
          var opt2 = options[j];
          if (!opt2) continue;
          var v2 =
            typeof opt2 === "string"
              ? opt2
              : typeof opt2.value !== "undefined"
              ? opt2.value
              : null;
          if (v2) {
            selected = v2;
            break;
          }
        }
      }
      return selected || "";
    }

    // Checkboxes: default to true (consent given)
    if (type === "checkbox") {
      return true;
    }

    // Notes / comments
    if (label.includes("notes") || label.includes("comment")) {
      return "Test notes for DEBUG submission.";
    }

    // Default fallback for text-ish fields
    return "Test value";
  }

  function isEmptyValue(v) {
    // NOTE: false/0 are valid values and should NOT be considered empty.
    return (
      typeof v === "undefined" ||
      v === null ||
      (typeof v === "string" && v.trim() === "")
    );
  }

  function isLegacyEmergencyAddressValue(v) {
    // Legacy debug could set this field to a bare postal code because the label contains "(incl. postal code)".
    if (typeof v !== "string") return false;
    var t = v.trim();
    // Canadian postal code pattern: A1A 1A1 (space optional)
    return /^[A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d$/.test(t);
  }

  function isLegacyAuthorizedPickupsValue(v) {
    // Legacy debug could set this field to a single phone number because the label contains "phone numbers".
    if (typeof v !== "string") return false;
    var t = v.trim();
    // Simple phone pattern (e.g., 111-222-3333) with optional spaces.
    return /^\d{3}[-\s]?\d{3}[-\s]?\d{4}$/.test(t);
  }

  function safeCssEscape(value) {
    if (typeof value === "undefined" || value === null) return "";
    var s = String(value);
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(s);
    }
    // Minimal escape fallback for attribute selectors.
    return s.replace(/[^a-zA-Z0-9_\-]/g, "\\$&");
  }

  function getConfigRef() {
    try {
      if (typeof config !== "undefined" && config) return config;
    } catch (e) {}
    return window && window.config ? window.config : null;
  }

  function getFormStateRef() {
    // Enrollment form uses a global lexical binding (let formState).
    // Wait list form uses window.formState.
    try {
      if (typeof formState !== "undefined") {
        return {
          get: function () {
            return formState;
          },
          set: function (next) {
            formState = next;
          },
        };
      }
    } catch (e) {}

    if (window && typeof window.formState !== "undefined") {
      return {
        get: function () {
          return window.formState;
        },
        set: function (next) {
          window.formState = next;
        },
      };
    }

    return null;
  }

  function callIfExists(fnName) {
    // Prefer window.fnName if present, otherwise fall back to a global binding.
    if (window && typeof window[fnName] === "function") {
      try {
        window[fnName]();
      } catch (e) {
        console.warn("DEBUG: " + fnName + " failed", e);
      }
      return true;
    }
    try {
      // eslint-disable-next-line no-undef
      if (typeof eval(fnName) === "function") {
        // eslint-disable-next-line no-undef
        eval(fnName)();
        return true;
      }
    } catch (e2) {}
    return false;
  }

  function setDomValueForField(field, value) {
    if (!field || !field.name) return;
    var name = field.name;

    // Enrollment uses:  field_<name>
    // Wait list uses:   fld_<name>
    var id1 = "field_" + name;
    var id2 = "fld_" + name;
    var el = document.getElementById(id1) || document.getElementById(id2);

    if ((field.type || "").toLowerCase() === "radio") {
      var sel = 'input[type="radio"][name="' + safeCssEscape(name) + '"]';
      var radios = document.querySelectorAll(sel);
      radios.forEach(function (r) {
        r.checked = String(r.value) === String(value);
      });
      return;
    }

    if ((field.type || "").toLowerCase() === "checkbox") {
      if (el && (el.type || "").toLowerCase() === "checkbox") {
        el.checked = Boolean(value);
      }
      return;
    }

    if (!el) return;
    if (typeof el.value !== "undefined") {
      el.value = value == null ? "" : String(value);
    }
  }

  function triggerChangeEventsForField(field) {
    if (!field || !field.name) return;
    var name = field.name;
    var id1 = "field_" + name;
    var id2 = "fld_" + name;
    var el = document.getElementById(id1) || document.getElementById(id2);

    var type = (field.type || "text").toLowerCase();

    try {
      if (type === "radio") {
        var sel = 'input[type="radio"][name="' + safeCssEscape(name) + '"]';
        var radios = document.querySelectorAll(sel);
        radios.forEach(function (r) {
          if (r.checked) {
            r.dispatchEvent(new Event("change", { bubbles: true }));
          }
        });
        return;
      }

      if (type === "checkbox") {
        if (el) {
          el.dispatchEvent(new Event("change", { bubbles: true }));
        }
        return;
      }

      if (el) {
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("blur", { bubbles: true }));
      }
    } catch (e) {
      console.warn("DEBUG: failed to trigger events for field", name, e);
    }
  }

  async function applyDebugDefaultsOnceReady() {
    var cfg = getConfigRef();
    var fsRef = getFormStateRef();

    if (!cfg || !cfg.steps) {
      return false;
    }
    if (!fsRef) {
      return false;
    }

    try {
      var formStateObj = fsRef.get();
      if (!formStateObj || typeof formStateObj !== "object") {
        formStateObj = {};
        fsRef.set(formStateObj);
      }

      var overrides = await buildDebugFormStateOverrides();
      var steps = cfg.steps || [];
      var changedFields = [];

      // IMPORTANT: Do NOT wipe existing values. Only fill empty fields.
      steps.forEach(function (step) {
        (step.groups || []).forEach(function (group) {
          (group.fields || []).forEach(function (field) {
            if (!field || !field.name) return;
            var name = field.name;

            // Precedence rule:
            // If debug is enabled AND enrollment/waitlist draft exists:
            //   - Keep stored values
            //   - Fill only missing waitlist fields with debug values (do not override)
            var existingValue = formStateObj[name];
            var allowOverride =
              (name === "emergency_contact_address" && isLegacyEmergencyAddressValue(existingValue)) ||
              (name === "authorized_pickups" && isLegacyAuthorizedPickupsValue(existingValue));

            if (!allowOverride && !isEmptyValue(existingValue)) {
              return;
            }

            var hasExplicit = Object.prototype.hasOwnProperty.call(overrides, name);
            var explicitValue = hasExplicit ? overrides[name] : undefined;
            var value =
              typeof explicitValue !== "undefined"
                ? explicitValue
                : generateDebugFieldValue(field);

            if (typeof value !== "undefined" && value !== null && !isEmptyValue(value)) {
              formStateObj[name] = value;
              changedFields.push(name);
              // Mirror into the UI where possible.
              setDomValueForField(field, value);
            }
          });
        });
      });

      // Derived field sync (both forms). Prefer window.syncDerivedFields.
      callIfExists("syncDerivedFields");

      // Persist: enrollment exposes saveDraft(); wait list keeps it private.
      // If saveDraft is not accessible, we still nudge the app by emitting events
      // for changed fields (the input listeners schedule draft saves internally).
      var saved = false;
      if (window && typeof window.saveDraft === "function") {
        try {
          await window.saveDraft();
          saved = true;
        } catch (e) {
          console.warn("DEBUG: window.saveDraft failed", e);
        }
      } else {
        try {
          // eslint-disable-next-line no-undef
          if (typeof saveDraft === "function") {
            // eslint-disable-next-line no-undef
            await saveDraft();
            saved = true;
          }
        } catch (e2) {
          // ignore
        }
      }

      if (!saved && changedFields.length) {
        // Best-effort: trigger input/change events so the app's own listeners run.
        steps.forEach(function (step) {
          (step.groups || []).forEach(function (group) {
            (group.fields || []).forEach(function (field) {
              if (!field || !field.name) return;
              if (changedFields.indexOf(field.name) === -1) return;
              triggerChangeEventsForField(field);
            });
          });
        });
      }

      // Re-render if the app exposes a renderer (Enrollment does).
      callIfExists("renderCurrentStep");

      return true;
    } catch (e) {
      console.warn("DEBUG: error while applying debug defaults", e);
      return false;
    }
  }

  // Only attach listeners if DEBUG mode is actually requested.
  var debugEnabled = detectDebugMode();
  if (!debugEnabled) {
    return;
  }

  // Expose a simple global flag so other scripts (like the email
  // builder) can tell when DEBUG mode was enabled at submission time.
  if (typeof window !== "undefined") {
    window.GRASP_DEBUG = true;
  }

  var applied = false;
  var applying = false;
  var _retryCount = 0;
  var _maxRetries = 40; // ~2s at 50ms
  var _retryDelayMs = 50;
  var _warnedMissing = false;

function doApplyIfNeeded() {
  if (applied || applying) return;
  applying = true;

  try {
    addDebugBadge();
  } catch (e) {
    console.warn("DEBUG: failed to add debug badge", e);
  }

  applyDebugDefaultsOnceReady()
    .then(function (ok) {
      if (ok) {
        applied = true;
        _retryCount = 0;
        return;
      }

      // Not ready yet (usually config/formState). Retry briefly before warning.
      _retryCount += 1;
      if (_retryCount <= _maxRetries) {
        setTimeout(doApplyIfNeeded, _retryDelayMs);
      } else if (!_warnedMissing) {
        _warnedMissing = true;
        console.warn("DEBUG: config not available; cannot apply debug defaults.");
      }
    })
    .catch(function (err) {
      console.warn("DEBUG: failed to apply debug defaults", err);
    })
    .finally(function () {
      applying = false;
    });
}


  // Once the core scripts finish their initial bootstrapping, apply defaults.
  window.addEventListener("graspEnrollmentInit", doApplyIfNeeded);
  window.addEventListener("graspWaitlistInit", doApplyIfNeeded);

  // Fallback: if this script loads after the init event, attempt shortly after load.
  // (We keep this best-effort and one-time to avoid masking real init issues.)
  window.addEventListener("DOMContentLoaded", function () {
    setTimeout(doApplyIfNeeded, 25);
  });
})();

// enrollment-debug.js
// Optional DEBUG helpers for the GRASP enrollment form.
// Activate by appending ?DEBUG=true (or 1/yes) to the URL. In normal
// usage (no DEBUG param), this file is effectively a no-op.

(function () {
  "use strict";

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
  async function buildDebugNameBundle() {
    var fallbackLast = "Registrant";
    try {
      var res = await fetch("https://randomuser.me/api/?results=3&nat=ca");
      if (!res.ok) throw new Error("Bad response from randomuser");
      var data = await res.json();
      var results = (data && data.results) || [];
      if (!results.length) throw new Error("No random users returned");

      var childUser = results[0];
      var parent1User = results[1] || results[0];
      var parent2User = results[2] || results[1] || results[0];

      var lastName = toTitleCase(childUser.name.last);
      var childFirst = toTitleCase(childUser.name.first);
      var parent1First = toTitleCase(parent1User.name.first);
      var parent2First = toTitleCase(parent2User.name.first);

      return {
        // Child
        childFirst: childFirst,
        childLast: lastName,
        // Parent / Guardian 1
        parent1First: parent1First,
        parent1Last: lastName,
        // Parent / Guardian 2
        parent2First: parent2First,
        parent2Last: lastName,
      };
    } catch (e) {
      console.warn(
        "DEBUG: failed to fetch random names, falling back to defaults",
        e
      );
      return {
        childFirst: "Test",
        childLast: "Registrant",
        parent1First: "Parent",
        parent1Last: fallbackLast,
        parent2First: "Guardian",
        parent2Last: fallbackLast,
      };
    }
  }

  // [GRASP-DEBUG] Provide explicit overrides for key split fields (names + addresses).
  async function buildDebugFormStateOverrides() {
    var names = await buildDebugNameBundle();
    var now = new Date();
    var testYear = now.getFullYear() - 3; // three years younger than current year
    var testBirthDate = String(testYear).padStart(4, "0") + "-12-12";

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
    if (label.includes("authorized") && label.includes("pickup")) {
      return "Test Authorized Pickup";
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

    // School / grade
    if (label.includes("school")) {
      return "Sample Elementary School";
    }
    if (label.includes("grade")) {
      return "3";
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

  async function applyDebugDefaults() {
    if (typeof config === "undefined" || !config || !config.steps) {
      console.warn("DEBUG: config not available; cannot apply debug defaults.");
      return;
    }
    if (typeof formState === "undefined") {
      console.warn(
        "DEBUG: formState not available; cannot apply debug defaults."
      );
      return;
    }

    try {
      var overrides = await buildDebugFormStateOverrides();
      var steps = config.steps || [];

      // Start from a clean state for test purposes.
      formState = {};

      steps.forEach(function (step) {
        (step.groups || []).forEach(function (group) {
          (group.fields || []).forEach(function (field) {
            if (!field || !field.name) return;
            var name = field.name;

            var hasExplicit = Object.prototype.hasOwnProperty.call(
              overrides,
              name
            );
            var explicitValue = hasExplicit ? overrides[name] : undefined;
            var value =
              typeof explicitValue !== "undefined"
                ? explicitValue
                : generateDebugFieldValue(field);

            if (typeof value !== "undefined" && value !== null) {
              formState[name] = value;
            }
          });
        });
      });

      // [GRASP-DEBUG] After populating base fields, compute all derived
      // name/address fields so preview/email/DB see consistent values.
      if (typeof syncDerivedFields === "function") {
        try {
          syncDerivedFields();
        } catch (e) {
          console.warn("DEBUG: syncDerivedFields failed", e);
        }
      }

      if (typeof saveFormState === "function") {
        try {
          await saveFormState();
        } catch (e) {
          console.warn("DEBUG: saveFormState failed", e);
        }
      }

      if (typeof renderCurrentStep === "function") {
        renderCurrentStep();
      }
    } catch (e) {
      console.warn("DEBUG: error while applying debug defaults", e);
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

  // Once the core enrollment script finishes its initial bootstrapping,
  // we apply our overrides and show the badge.
  window.addEventListener("graspEnrollmentInit", function () {
    try {
      addDebugBadge();
    } catch (e) {
      console.warn("DEBUG: failed to add debug badge", e);
    }
    applyDebugDefaults().catch(function (err) {
      console.warn("DEBUG: failed to apply debug defaults", err);
    });
  });
})();

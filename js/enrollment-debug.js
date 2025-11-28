// enrollment-debug.js
// Optional DEBUG helpers for the GRASP enrollment form.
// Activate by appending ?DEBUG=true (or 1/yes) to the URL. In normal
// usage (no DEBUG param), this file is effectively a no-op.

(function () {
  "use strict";

  function detectDebugMode() {
    try {
      const params = new URLSearchParams(window.location.search);
      const raw = params.get("DEBUG") || params.get("debug");
      if (!raw) return false;
      const value = String(raw).toLowerCase();
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
      .replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
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
    badge.style.fontFamily = "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
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
        childName: childFirst + " " + lastName,
        parent1Name: parent1First + " " + lastName,
        parent2Name: parent2First + " " + lastName
      };
    } catch (e) {
      console.warn("DEBUG: failed to fetch random names, falling back to defaults", e);
      return {
        childName: "Test Registrant",
        parent1Name: "Parent " + fallbackLast,
        parent2Name: "Guardian " + fallbackLast
      };
    }
  }

  async function buildDebugFormStateOverrides() {
    var names = await buildDebugNameBundle();
    var now = new Date();
    var testYear = now.getFullYear() - 3; // three years younger than current year
    var testBirthDate = String(testYear).padStart(4, "0") + "-12-12";

    var homeAddress = "456 Sample Street\nToronto, Ontario";
    var workAddress = "123 Anywhere Street\nToronto, Ontario\nM1M 2M2";
    var postal1 = generateRandomCanadianPostalCode();
    var postal2 = generateRandomCanadianPostalCode();

    var overrides = {
      // Child
      child_name: names.childName,
      child_birth_date: testBirthDate,

      // Parent / Guardian 1
      parent1_name: names.parent1Name,
      parent1_email: "test@test.com",
      parent1_home_address: homeAddress,
      parent1_postal_code: postal1,
      parent1_phones: "111-222-3333",
      parent1_work_address: workAddress,
      parent1_work_phone: "222-333-4444",

      // Parent / Guardian 2 (optional)
      parent2_name: names.parent2Name,
      parent2_email: "test@test.com",
      parent2_home_address: homeAddress,
      parent2_postal_code: postal2,
      parent2_phones: "111-222-3333",
      parent2_work_address: workAddress,
      parent2_work_phone: "222-333-4444"
    };

    return overrides;
  }

  async function applyDebugDefaults() {
    if (typeof config === "undefined" || !config) {
      console.warn("DEBUG: config not available; cannot apply debug defaults.");
      return;
    }
    if (typeof formState === "undefined") {
      console.warn("DEBUG: formState not available; cannot apply debug defaults.");
      return;
    }

    try {
      var overrides = await buildDebugFormStateOverrides();
      var allowedNames = collectAllFieldNamesFromConfig(config);

      // Start from a clean state for test purposes.
      formState = {};

      Object.keys(overrides).forEach(function (name) {
        if (allowedNames.has(name)) {
          formState[name] = overrides[name];
        }
      });

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
  if (!detectDebugMode()) {
    return;
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

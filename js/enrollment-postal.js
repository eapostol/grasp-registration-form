// enrollment-postal.js
// Shared postal-code utilities for the GRASP enrollment form.
// - Enforces A1A / 1A1 patterns for *_postal1 / *_postal2
// - Normalizes input to uppercase and strips invalid characters
// - Returns friendly error messages for invalid patterns

(function (global) {
  "use strict";

  function isPostalFirstHalfName(name) {
    return /_postal1$/i.test(name || "");
  }

  function isPostalSecondHalfName(name) {
    return /_postal2$/i.test(name || "");
  }

  /**
   * Normalize input for postal-code halves:
   * - Uppercase letters
   * - Strip non-alphanumeric characters
   * - Limit to 3 characters
   */
  function normalizeInput(fieldName, value) {
    if (!fieldName) return value;

    var name = String(fieldName).toLowerCase();
    if (!isPostalFirstHalfName(name) && !isPostalSecondHalfName(name)) {
      return value;
    }

    var v = value == null ? "" : String(value);
    v = v.toUpperCase().replace(/[^A-Z0-9]/g, "");
    if (v.length > 3) {
      v = v.slice(0, 3);
    }
    return v;
  }

  /**
   * Validate a single field for postal-code format.
   *
   * - For *_postal1: A1A (letter, digit, letter)
   * - For *_postal2: 1A1 (digit, letter, digit)
   * - Empty values pass here; "required" is handled by core validation.
   *
   * Returns: { ok: boolean, message: string|null }
   */
  function validateField(fieldDef, rawValue) {
    if (!fieldDef || !fieldDef.name) {
      return { ok: true, message: null };
    }

    var name = String(fieldDef.name).toLowerCase();
    var value = rawValue == null ? "" : String(rawValue).trim().toUpperCase();

    // Not a postal-part field? Nothing to do.
    if (!isPostalFirstHalfName(name) && !isPostalSecondHalfName(name)) {
      return { ok: true, message: null };
    }

    // If empty: let the general "required" logic handle it (if required).
    if (!value) {
      return { ok: true, message: null };
    }

    if (isPostalFirstHalfName(name)) {
      var reFirst = /^[A-Z][0-9][A-Z]$/;
      if (!reFirst.test(value)) {
        return {
          ok: false,
          message:
            "Please enter the first part of the postal code in A1A format (letter, digit, letter).",
        };
      }
    } else if (isPostalSecondHalfName(name)) {
      var reSecond = /^[0-9][A-Z][0-9]$/;
      if (!reSecond.test(value)) {
        return {
          ok: false,
          message:
            "Please enter the second part of the postal code in 1A1 format (digit, letter, digit).",
        };
      }
    }

    return { ok: true, message: null };
  }

  // Expose in a single namespace for reuse
  global.GRASP_POSTAL = {
    normalizeInput: normalizeInput,
    validateField: validateField,
  };
})(window);

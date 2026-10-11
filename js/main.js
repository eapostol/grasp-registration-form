// Modal + form to contact site administrator
(function () {
  const EMAIL_REGEX =
    /^[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*@(?:[A-Za-z0-9-]+\.){1,2}[A-Za-z]{2,}$/;
  const SCRIPT_URL = new URL(document.currentScript.src, window.location.href);
  const STYLESHEET_HREF = new URL("../css/contact_admin_modal.css", SCRIPT_URL).href;
  const CONFIG_URL = new URL("../api/contact_admin_config.php", SCRIPT_URL).href;
  const SUBMIT_URL = new URL("public_html/cgi-bin/submit.php", SCRIPT_URL).href;
  const TRIGGER_TEXT = "site administrator";
  let recaptchaLoader;

  function loadRecaptcha() {
    if (!recaptchaLoader) {
      recaptchaLoader = new Promise((resolve, reject) => {
        const script = document.createElement("script");
        const timer = setTimeout(() => fail(), 15000);
        const fail = () => {
          clearTimeout(timer);
          script.remove();
          reject(new Error("Verification could not load."));
        };
        const ready = () => {
          window.grecaptcha.enterprise.ready(() => {
            clearTimeout(timer);
            resolve(window.grecaptcha.enterprise);
          });
        };
        if (window.grecaptcha?.enterprise?.render) {
          ready();
          return;
        }
        script.src = "https://www.google.com/recaptcha/enterprise.js?render=explicit";
        script.async = true;
        script.defer = true;
        script.onload = () => {
          if (window.grecaptcha?.enterprise?.ready) ready();
          else fail();
        };
        script.onerror = fail;
        document.head.appendChild(script);
      }).catch((error) => {
        recaptchaLoader = null;
        throw error;
      });
    }
    return recaptchaLoader;
  }

  function normalizeHref(href) {
    if (!href) return href;
    if (href.startsWith("http://") || href.startsWith("https://")) return href;
    if (href.startsWith("/")) return href;
    return "/" + href.replace(/^\.?\//, "");
  }

  // Preserve ?debug=true when navigating between Enrollment/Waitlist/Parent Manual pages.
  // Any <a> with data-propagate-debug="true" will receive debug=true if the current URL has debug enabled.
  function isDebugEnabled() {
    try {
      const params = new URLSearchParams(window.location.search || "");
      const raw =
        params.get("debug") ??
        params.get("DEBUG") ??
        params.get("Debug");
      if (!raw) return false;
      const val = String(raw).toLowerCase();
      return val === "true" || val === "1" || val === "yes";
    } catch (_e) {
      return false;
    }
  }

function propagateDebugToLinks() {
  if (!isDebugEnabled()) return;

  const links = document.querySelectorAll('a[data-propagate-debug="true"]');
  links.forEach((a) => {
    const href = a.getAttribute("href");
    if (!href) return;

    // Skip anchors and non-http(s) schemes
    if (href.startsWith("#")) return;
    if (/^(mailto:|tel:|sms:|javascript:)/i.test(href)) return;

    try {
      const url = new URL(href, window.location.href);

      // Only rewrite same-origin links (prevents breaking external URLs)
      if (url.origin !== window.location.origin) return;

      url.searchParams.set("debug", "true");

      // Keep it site-relative (works on dev/prod domains)
      a.setAttribute("href", url.pathname + url.search + url.hash);
    } catch (_e) {
      // no-op
    }
  });
}




  document.addEventListener("DOMContentLoaded", () => {
    propagateDebugToLinks();
    ensureStylesheetLoaded();
    const modal = buildModal();
    attachFooterTrigger(modal.open);
  });

  function ensureStylesheetLoaded() {
    const alreadyLoaded = Array.from(document.querySelectorAll("link")).some(
      (link) => normalizeHref(link.getAttribute("href")) === STYLESHEET_HREF
    );

    if (!alreadyLoaded) {
      const link = document.createElement("link");
      link.rel = "stylesheet";
      link.href = STYLESHEET_HREF;
      document.head.appendChild(link);
    }
  }

  function buildField(labelText, type, name) {
    const wrapper = document.createElement("div");
    wrapper.className = "contact-admin-field";

    const label = document.createElement("label");
    const input = type === "textarea" ? document.createElement("textarea") : document.createElement("input");
    const id = `contact-admin-${name}`;

    label.htmlFor = id;
    label.textContent = labelText;

    input.id = id;
    input.name = name;
    input.required = true;
    input.className = "contact-admin-input";
    if (type === "email") {
      input.type = "email";
      input.autocomplete = "email";
    } else if (type === "text") {
      input.type = "text";
      input.autocomplete = "name";
    } else if (type === "textarea") {
      input.rows = 4;
    }

    const error = document.createElement("span");
    error.className = "contact-admin-error";
    error.setAttribute("aria-live", "polite");

    wrapper.append(label, input, error);

    return { wrapper, input, error };
  }

  function buildModal() {
    const overlay = document.createElement("div");
    overlay.className = "contact-admin-overlay";
    overlay.setAttribute("aria-hidden", "true");

    const modal = document.createElement("div");
    modal.className = "contact-admin-modal";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "contact-admin-title");

    const closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "contact-admin-close";
    closeButton.setAttribute("aria-label", "Close dialog");
    closeButton.textContent = "\u00D7";

    const title = document.createElement("h2");
    title.id = "contact-admin-title";
    title.textContent = "Contact Site Administrator";

    const form = document.createElement("form");
    form.className = "contact-admin-form";
    form.noValidate = true;

    const nameField = buildField("Full Name", "text", "fullName");
    const emailField = buildField("Email", "email", "email");
    const messageField = buildField("Message", "textarea", "message");
    nameField.input.maxLength = 200;
    emailField.input.maxLength = 254;
    messageField.input.maxLength = 10000;

    const captchaContainer = document.createElement("div");
    captchaContainer.className = "contact-admin-captcha";
    const captchaHelp = document.createElement("p");
    captchaHelp.className = "contact-admin-captcha-help";
    captchaHelp.textContent = "Complete the verification checkbox before submitting.";
    let captchaApi;
    let widgetId;
    let preparing;
    let submitting = false;
    let successTimer;

    const status = document.createElement("div");
    status.className = "contact-admin-status";
    status.setAttribute("role", "status");
    status.setAttribute("aria-live", "polite");

    const actions = document.createElement("div");
    actions.className = "contact-admin-actions";

    const cancelButton = document.createElement("button");
    cancelButton.type = "button";
    cancelButton.className = "contact-admin-btn contact-admin-btn-secondary";
    cancelButton.textContent = "Cancel";

    const submitButton = document.createElement("button");
    submitButton.type = "submit";
    submitButton.className = "contact-admin-btn";
    submitButton.textContent = "Submit";
    submitButton.disabled = true;

    actions.append(cancelButton, submitButton);
    form.append(
      nameField.wrapper,
      emailField.wrapper,
      messageField.wrapper,
      captchaHelp,
      captchaContainer,
      status,
      actions
    );
    modal.append(closeButton, title, form);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const clearErrors = () => {
      [nameField, emailField, messageField].forEach(({ input, error }) => {
        error.textContent = "";
        input.removeAttribute("aria-invalid");
      });
    };

    const setError = (field, message) => {
      field.error.textContent = message;
      field.input.setAttribute("aria-invalid", "true");
    };

    const clearStatus = () => {
      status.textContent = "";
      status.dataset.tone = "";
    };

    const setStatus = (message, tone) => {
      status.textContent = message;
      status.dataset.tone = tone;
    };

    const resetCaptcha = () => {
      if (widgetId !== undefined) captchaApi.reset(widgetId);
      submitButton.disabled = true;
    };

    const prepareCaptcha = () => {
      if (preparing) return preparing;
      setStatus("Loading verification…", "info");
      preparing = (async () => {
        const response = await fetch(CONFIG_URL, {
          cache: "no-store",
          signal: AbortSignal.timeout(15000),
        });
        if (!response.ok) throw new Error("Verification unavailable.");
        const config = await response.json();
        if (typeof config.siteKey !== "string" || !config.siteKey) {
          throw new Error("Verification unavailable.");
        }
        captchaApi = await loadRecaptcha();
        widgetId = captchaApi.render(captchaContainer, {
          sitekey: config.siteKey,
          size: modal.clientWidth < 370 ? "compact" : "normal",
          callback: () => {
            if (!submitting && overlay.classList.contains("is-visible")) {
              submitButton.disabled = false;
              clearStatus();
            }
          },
          "expired-callback": () => {
            submitButton.disabled = true;
            if (!submitting) setStatus("Verification expired. Please complete the checkbox again.", "error");
          },
          "error-callback": () => {
            submitButton.disabled = true;
            if (!submitting) setStatus("Verification could not load. Check your connection and reopen this form to retry.", "error");
          },
        });
        if (overlay.classList.contains("is-visible")) clearStatus();
        else resetCaptcha();
      })().catch(() => {
        preparing = null;
        submitButton.disabled = true;
        if (overlay.classList.contains("is-visible")) {
          setStatus("Verification is unavailable. Please close this form and try again later.", "error");
        }
      });
      return preparing;
    };

    const closeModal = () => {
      if (submitting) return;
      clearTimeout(successTimer);
      overlay.classList.remove("is-visible");
      overlay.setAttribute("aria-hidden", "true");
      clearStatus();
      clearErrors();
      form.reset();
      resetCaptcha();
      document.removeEventListener("keydown", onEscape);
    };

    const openModal = () => {
      ensureStylesheetLoaded();
      overlay.classList.add("is-visible");
      overlay.setAttribute("aria-hidden", "false");
      clearStatus();
      clearErrors();
      form.reset();
      nameField.input.focus();
      document.addEventListener("keydown", onEscape);
      if (widgetId !== undefined) resetCaptcha();
      else prepareCaptcha();
    };

    const onEscape = (event) => {
      if (event.key === "Escape") {
        closeModal();
      }
    };

    const validate = () => {
      let isValid = true;
      clearErrors();

      const trimmedName = nameField.input.value.trim();
      const trimmedEmail = emailField.input.value.trim();
      const trimmedMessage = messageField.input.value.trim();

      if (!trimmedName) {
        setError(nameField, "Full Name is required.");
        isValid = false;
      }

      if (!trimmedEmail) {
        setError(emailField, "Email is required.");
        isValid = false;
      } else if (!EMAIL_REGEX.test(trimmedEmail)) {
        setError(emailField, "Please enter a valid email address.");
        isValid = false;
      }

      if (!trimmedMessage) {
        setError(messageField, "Message cannot be blank.");
        isValid = false;
      }

      return {
        isValid,
        values: {
          fullName: trimmedName,
          email: trimmedEmail,
          message: trimmedMessage,
        },
      };
    };

    const submitForm = async () => {
      if (submitting) return;
      const { isValid, values } = validate();
      if (!isValid) {
        setStatus("Please correct the highlighted fields.", "error");
        return;
      }

      const token = widgetId !== undefined ? captchaApi.getResponse(widgetId) : "";
      if (!token) {
        setStatus("Please complete the verification checkbox.", "error");
        return;
      }

      setStatus("Sending\u2026", "info");
      submitting = true;
      submitButton.disabled = true;
      closeButton.disabled = true;
      cancelButton.disabled = true;

      const payload = new FormData();
      payload.append("fullName", values.fullName);
      payload.append("email", values.email);
      payload.append("message", values.message);
      payload.append("g-recaptcha-response", token);

      try {
        const response = await fetch(SUBMIT_URL, {
          method: "POST",
          body: payload,
          signal: AbortSignal.timeout(20000),
        });

        let data;
        try {
          data = await response.json();
        } catch (_error) {
          data = {};
        }

        if (!response.ok) {
          const message =
            (data && data.message) ||
            "Something went wrong. Please try again later.";
          setStatus(message, "error");
          return;
        }

        setStatus(
          (data && data.message) ||
            "Your message has been sent. Thank you!",
          "success"
        );
        successTimer = setTimeout(() => {
          closeModal();
        }, 800);
      } catch (_error) {
        setStatus("Unable to confirm delivery. Please wait before trying again, as your message may have been sent.", "error");
      } finally {
        submitting = false;
        closeButton.disabled = false;
        cancelButton.disabled = false;
        resetCaptcha();
      }
    };

    closeButton.addEventListener("click", closeModal);
    cancelButton.addEventListener("click", closeModal);
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) {
        closeModal();
      }
    });
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      submitForm();
    });
    [nameField.input, emailField.input, messageField.input].forEach((input) =>
      input.addEventListener("input", () => {
        input.removeAttribute("aria-invalid");
        const error = input.parentElement.querySelector(".contact-admin-error");
        if (error) {
          error.textContent = "";
        }
      })
    );

    return { open: openModal, close: closeModal };
  }

  function attachFooterTrigger(openModal) {
    const footer = document.getElementById("footer");
    if (!footer) {
      return;
    }

    const paragraphs = footer.querySelectorAll("p");
    if (!paragraphs.length) {
      return;
    }

    paragraphs.forEach((paragraph) => {
      const text = paragraph.textContent || "";
      const lower = text.toLowerCase();
      const target = TRIGGER_TEXT.toLowerCase();
      const index = lower.indexOf(target);

      if (index === -1) {
        return;
      }

      const before = text.slice(0, index);
      const after = text.slice(index + target.length);

      paragraph.textContent = "";
      if (before) {
        paragraph.appendChild(document.createTextNode(before));
      }

      const trigger = document.createElement("button");
      trigger.type = "button";
      trigger.className = "contact-admin-trigger";
      trigger.textContent = "site administrator";
      trigger.addEventListener("click", () => openModal());
      paragraph.appendChild(trigger);

      if (after) {
        paragraph.appendChild(document.createTextNode(after));
      }
    });
  }
})();

/* Global email link handler (obfuscated) */
(function () {
  // Default email: "info@greenlandrecreational.com" (Base64)
  const DEFAULT_EMAIL_B64 = "aW5mb0BncmVlbmxhbmRyZWNyZWF0aW9uYWwuY29t";

  // Any of these will be treated as an email trigger:
  //  - class="js-email"
  //  - data-email="..." (plain or Base64)
  //  - href="mailto:..." (legacy)
  const EMAIL_LINK_SELECTOR = 'a.js-email, a[data-email], a[href^="mailto:"]';

  function safeAtob(value) {
    try {
      return window.atob(value);
    } catch (_e) {
      return "";
    }
  }

  function extractEmailFromMailtoHref(href) {
    if (!href) return "";
    const raw = String(href).trim();
    if (!raw.toLowerCase().startsWith("mailto:")) return "";
    const withoutScheme = raw.slice(7); // after "mailto:"
    // mailto can include query params (e.g., ?subject=...)
    const emailPart = withoutScheme.split("?")[0];
    return (emailPart || "").trim();
  }

  function resolveEmailForElement(el) {
    if (!el) return "";

    // 1) data-email override (plain or Base64)
    const override = (el.getAttribute("data-email") || "").trim();
    if (override) {
      if (override.includes("@")) return override;
      const decodedOverride = safeAtob(override);
      if (decodedOverride && decodedOverride.includes("@")) return decodedOverride.trim();
    }

    // 2) legacy mailto: in href
    const href = el.getAttribute("href") || "";
    const legacy = extractEmailFromMailtoHref(href);
    if (legacy) return legacy;

    // 3) default Base64
    const decodedDefault = safeAtob(DEFAULT_EMAIL_B64);
    return decodedDefault || "info@greenlandrecreational.com";
  }

  function wireEmailLink(el) {
    if (!el || el.dataset.emailWired === "true") return;

    // Ensure we can "track" these in markup via class (no styling assumed)
    if (!el.classList.contains("js-email")) {
      el.classList.add("js-email");
    }

    // If this is a legacy mailto link, neutralize the href to prevent exposing email in-page behavior.
    const href = el.getAttribute("href") || "";
    if (/^mailto:/i.test(href)) {
      el.setAttribute("href", "#");
    }

    el.addEventListener("click", function (event) {
      event.preventDefault();
      const email = resolveEmailForElement(el);
      if (!email) return;
      window.location.href = "mailto:" + email;
    });

    el.dataset.emailWired = "true";
  }

  function initEmailLinks() {
    const links = document.querySelectorAll(EMAIL_LINK_SELECTOR);
    links.forEach(wireEmailLink);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initEmailLinks);
  } else {
    initEmailLinks();
  }
})();

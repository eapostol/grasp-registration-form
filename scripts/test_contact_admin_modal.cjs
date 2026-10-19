// DOM unit tests, not a browser or a replacement for the real Google challenge.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const source = fs.readFileSync(require("node:path").join(__dirname, "../js/main.js"), "utf8");
let checks = 0;
function check(condition, label) { assert.ok(condition, label); checks++; }
class Element {
  constructor(tag) {
    this.tagName = tag; this.children = []; this.events = {}; this.attributes = {};
    this.dataset = {}; this.value = ""; this.clientWidth = 288;
    this.classes = new Set();
    this.classList = { add: (...xs) => xs.forEach(x => this.classes.add(x)),
      remove: x => this.classes.delete(x), contains: x => this.classes.has(x) };
  }
  append(...children) { children.forEach(child => { this.children.push(child); child.parentElement = this; }); }
  appendChild(child) { this.append(child); if (this.onAppend) this.onAppend(child); }
  setAttribute(key, value) { this.attributes[key] = value; }
  getAttribute(key) { return this.attributes[key] ?? null; }
  removeAttribute(key) { delete this.attributes[key]; }
  addEventListener(name, callback) { this.events[name] = callback; }
  removeEventListener(name) { delete this.events[name]; }
  focus() {}
  remove() { this.removed = true; }
  reset() { walk(this).forEach(node => { if (node.name) node.value = ""; }); }
  querySelector(selector) { return walk(this).find(node => "." + node.className === selector); }
  querySelectorAll(selector) { return walk(this).filter(node => node.tagName === selector); }
}
function walk(node) { return [node, ...node.children.flatMap(walk)]; }
async function settle() { for (let i = 0; i < 20; i++) await Promise.resolve(); }
function setup(page, unavailable = false) {
  const requests = [], timers = new Map();
  const document = new Element("document");
  document.head = new Element("head"); document.body = new Element("body");
  document.readyState = "loading";
  document.createElement = tag => new Element(tag);
  document.createTextNode = text => Object.assign(new Element("text"), { textContent: text });
  document.currentScript = { src: new URL("js/main.js", new URL("/staging/", page)).href };
  const footer = new Element("footer"), paragraph = new Element("p");
  paragraph.textContent = "For issues, contact our site administrator."; footer.append(paragraph);
  document.getElementById = id => id === "footer" ? footer : null;
  document.querySelectorAll = () => [];
  let token = "", callbacks, renders = 0, resets = 0, reply = { ok: true, message: "Sent" };
  let pendingSubmission;
  const enterprise = { ready: fn => fn(), render: (container, options) => {
    callbacks = options; renders++; return 0;
  }, reset: () => { token = ""; resets++; }, getResponse: () => token };
  const window = { location: new URL(page), grecaptcha: { enterprise } };
  const context = { window, document, URL, FormData, AbortSignal,
    setTimeout: fn => { const id = timers.size + 1; timers.set(id, fn); return id; },
    clearTimeout: id => timers.delete(id),
    fetch: async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith("contact_admin_config.php")) {
        return { ok: !unavailable, json: async () => ({ siteKey: "public-test-key" }) };
      }
      if (pendingSubmission) await pendingSubmission;
      return { ok: reply.ok, json: async () => ({ message: reply.message }) };
    },
  };
  vm.runInNewContext(source, context);
  document.events.DOMContentLoaded();
  return { document, footer, requests, timers, enterprise,
    setToken: () => { token = "google-token"; callbacks.callback(); },
    expire: () => { token = ""; callbacks["expired-callback"](); },
    failReply: () => { reply = { ok: false, message: "Verification expired" }; },
    hold: () => { pendingSubmission = new Promise(resolve => { context.release = resolve; }); },
    release: () => context.release(),
    renders: () => renders, resets: () => resets,
  };
}

// Browsers register multiple listeners; retain them all in this test DOM too.
Element.prototype.addEventListener = function (name, callback) {
  const previous = this.events[name];
  this.events[name] = previous ? event => { previous(event); callback(event); } : callback;
};

(async () => {
  for (const path of ["", "contact.html", "forms.html", "location.html", "program_statement.html",
    "programs_fees.html", "grasp_summer_camp.html", "enrollment-form/", "waitlist-form/", "parent-manual-form/"]) {
    const page = "https://example.com/staging/" + path;
    const test = setup(page);
    check(test.requests.length === 0, "no CAPTCHA request before modal opens: " + path);
    const trigger = walk(test.footer).find(node => node.className === "contact-admin-trigger");
    trigger.events.click(); await settle();
    const nodes = walk(test.document.body), form = nodes.find(n => n.tagName === "form");
    const submit = nodes.find(n => n.type === "submit");
    check(test.requests[0].url === "https://example.com/staging/api/contact_admin_config.php", "subdirectory config URL: " + path);
    check(submit.disabled && test.renders() === 1, "submit disabled before checkbox: " + path);
    await form.events.submit({ preventDefault() {} }); await settle();
    check(test.requests.length === 1, "empty form does not send: " + path);
    for (const [name, value] of Object.entries({ fullName: "Security Test", email: "test@example.com", message: "Test only" })) {
      nodes.find(n => n.name === name).value = value;
    }
    test.setToken();
    check(!submit.disabled, "checkbox enables submit: " + path);
    test.expire(); check(submit.disabled, "expiration disables submit: " + path);
    test.setToken(); test.hold();
    form.events.submit({ preventDefault() {} }); await settle();
    form.events.submit({ preventDefault() {} }); await settle();
    check(test.requests.length === 2, "double submit makes one POST: " + path);
    check(test.requests[1].url === "https://example.com/staging/js/public_html/cgi-bin/submit.php", "subdirectory POST URL: " + path);
    check(test.requests[1].options.body.get("g-recaptcha-response") === "google-token", "POST contains token: " + path);
    test.release(); await settle();
    check(submit.disabled && test.resets() > 0, "used token reset: " + path);
    [...test.timers.values()].forEach(fn => fn());
    trigger.events.click(); await settle();
    check(test.renders() === 1, "reopening reuses widget: " + path);
  }
  const failed = setup("https://example.com/staging/", true);
  walk(failed.footer).find(n => n.className === "contact-admin-trigger").events.click(); await settle();
  check(walk(failed.document.body).find(n => n.type === "submit").disabled, "configuration failure disables submission");
  check(walk(failed.document.body).find(n => n.className === "contact-admin-status").textContent.includes("unavailable"), "configuration failure explained to visitor");
  console.log(`${checks} modal DOM checks passed. Real Google/browser testing is separate.`);
})().catch(error => { console.error(error); process.exitCode = 1; });

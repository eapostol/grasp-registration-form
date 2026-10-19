# Contact administrator reCAPTCHA and maintenance

Status: local implementation on `feat/contact-admin-recaptcha`. No staging or
production deployment, and no change to the responsive prototype. Local Google
verification and Mailpit delivery were confirmed on October 10, 2026 (see below).

## What is protected

All ten public pages load `js/main.js`. Their footer administrator button opens
one shared modal. `js/public_html/cgi-bin/submit.php` remains the email endpoint;
every valid submission must now pass server-side reCAPTCHA Enterprise verification
before `mail()` is called. The other registration submission APIs are unchanged.

The modal loads Google only when opened and explicitly renders a checkbox. Google
does not support actions for explicitly rendered checkbox keys, so no LOGIN or
CONTACT_ADMIN action is sent. PHP checks token validity and an exact hostname
allowlist; Google ties the assessment to the configured site key and rejects
expired/reused tokens. Checkbox verification does not establish message truth or
email ownership. This change does not add rate limiting.

## Local setup (Ubuntu-D / DDEV)

Run from this repository inside WSL:

```bash
bash scripts/setup_contact_admin_local.sh
```

This creates two files only if absent; it never overwrites existing settings:

- `$HOME/.config/grasp/reg-form-project/contact-admin.json` (mode 600), outside
  the website and Git checkout. If `XDG_CONFIG_HOME` is set, that directory is
  used in place of `$HOME/.config`.
- `.ddev/docker-compose.contact-admin.local.yaml` (Git-ignored): mounts that
  private directory read-only at `/etc/grasp` in the web container.

Open the private JSON directly in your editor, not through a website URL. Enter
the copied public `site_key` and private server `api_key`. The script prepopulates
project `grasp-511222`, hostname `reg-form-project.ddev.site`, and the current
administrator recipient. Do not paste the private API key into chat, terminal
commands, screenshots, commits, HTML, JavaScript, or tracked YAML files.

Then run `ddev restart` to activate the new mount. Once mounted, changing the JSON
does not require a restart. Keep JSON syntax valid (quoted strings, no comments
or trailing commas). Missing/invalid configuration disables submissions; there
is no automatic CAPTCHA bypass.

## Configuration contract

`config/contact-admin.example.json` is the tracked placeholder template.

| JSON field | Meaning |
| --- | --- |
| `project_id` | Google project ID that owns the API key and site key |
| `site_key` | Public checkbox key ID, restricted to this environment in Google |
| `api_key` | Private standard API key, restricted to reCAPTCHA Enterprise API |
| `allowed_hostnames` | Array of exact hostnames, without protocol, port, or path |
| `recipient` | Email address that receives administrator messages |

PHP defaults to `/etc/grasp/contact-admin.json`. Set `GRASP_CONTACT_CONFIG` to a
different absolute path outside the document root on another host. A symlink
resolving into the document root is also rejected. Never store a real secret in
the public `config/` folder. Protect the private file using the hosting account's
permissions, ensuring the PHP worker can read it.

Individual environment variables override the file when present:
`GRASP_CONTACT_PROJECT_ID`, `GRASP_CONTACT_SITE_KEY`, `GRASP_CONTACT_API_KEY`,
`GRASP_CONTACT_ALLOWED_HOSTNAMES` (comma-separated), and `GRASP_CONTACT_RECIPIENT`.
All fields may be provided through environment variables instead of a file.
The browser endpoint `api/contact_admin_config.php` exposes only the public site
key, uses `Cache-Control: no-store`, and returns a generic 503 if not ready.

For DDEV, prefer the protected mounted JSON for the private key. Do not put it
in DDEV `web_environment`, which can surface in diagnostic/compose output.

## Test before rollout

1. Run `ddev exec php scripts/test_contact_admin_security.php` and
   `ddev exec node --check js/main.js`. Run DOM checks with
   `ddev exec node scripts/test_contact_admin_modal.cjs`, and local negative HTTP
   checks with `ddev exec python3 scripts/test_contact_admin_http.py` (requires
   Python 3 in the container). Avoid sending other messages during the HTTP
   checks, which compare Mailpit message counts before and after.
2. Verify the config endpoint returns only `siteKey`, never credentials.
3. Open `https://reg-form-project.ddev.site/` and the footer modal. Complete the
   real checkbox, enter a clearly labeled test message, and submit.
4. Confirm the complete message, recipient, and Reply-To in
   `https://reg-form-project.ddev.site:8026`. Local PHP must use Mailpit's sendmail
   path; local capture is not evidence of production deliverability.
5. Check nested Enrollment, Waitlist, and Parent Manual pages use the same modal;
   also check the other public pages, narrow screens, cancellation/reopening,
   required fields, expired challenges, and retries after errors.
6. Direct POSTs without a token must return 422 and create no email. Invalid,
   expired, reused, or wrong-host tokens must not send mail. Provider/auth/quota/
   transport failures return 503; verify there is no mail on those failures.

The CLI tests use synthetic Google responses; they cannot prove a real Google
challenge succeeds. Never introduce a request parameter or runtime setting that
substitutes synthetic verification in the deployed handler.

### Validation recorded October 10, 2026

- 30 PHP security checks passed using synthetic assessments; 112 DOM checks
  passed across the ten public page paths under a simulated `/staging/` prefix.
  These are unit tests, not visual browser validation or a deployed staging test.
- Eight negative/config HTTP checks passed against DDEV and left Mailpit's
  message count unchanged. Public config returned only the copied public site key.
- A live request with a deliberately invalid token returned HTTP 422, showing
  Google could be reached with the configured API key and rejected the token.
- A user-submitted message with body `test` arrived in Mailpit at 22:50:31 EDT,
  addressed to the configured administrator with the submitter's Reply-To.
  Mailpit message ID: `6dGm4Rs3XJoZY9byVcqlif`. This confirms live verification
  and local mail capture, not external inbox delivery.
- Browser automation was blocked by a local permission error. Visual checks of
  all pages, real mobile layout, expiration/retry interaction, and a real
  subdirectory deployment still need manual validation before rollout.
- The site maintainer subsequently reported that a manual visual check worked.
  Hosted staging validation remains pending; this confirmation does not establish
  coverage of every page, device, or expiration/retry case.

## Staging, production, and handover

Create separate Google checkbox and private API keys for each environment. Use
the actual hostname in Google and in `allowed_hostnames`. If staging is served
under `/staging` on the production hostname, it needs its own keys/config path;
the hostname setting alone does not distinguish those environments. Script and
endpoint URLs resolve relative to `js/main.js`, preserving subdirectory hosting.

The maintainer must provision private configuration before deploying this change
to a server, or the administrator form will be unavailable. PHP 8.3, cURL with
working CA certificates, outbound HTTPS to Google, and PHP mail delivery are
required. No Google PHP SDK is needed.

Record project ownership/access, permitted domains, billing/quota contacts, key
names, private-file locations and backups in the organization's password manager
or restricted operations record. Transfer Google project access to the incoming
maintainer; replacing config values alone does not transfer project ownership.
Confirm the incoming maintainer can rotate credentials and repeat the delivery
test before removing outgoing access. Restrict server API keys by stable egress
IP where practical; website/referrer restrictions are not for PHP requests.

Monitor Google usage. Free assessments are shared across the organization; the
CreateAssessment API rejects over-quota requests without billing. This handler
fails closed on such errors. Keep a separate contact route available during an
outage. Review the site's privacy information for the use of Google's widget.

Publish through a reviewed feature-branch PR into `develop`, then the normal
production release. Bring approved security changes into the prototype by
merging updated `develop` later, rather than implementing them twice. The three
existing local testing/LAN/internal-doc commits stay on the original Parent
Manual branch and are not prerequisites for this feature.

References:
- https://docs.cloud.google.com/recaptcha/docs/create-assessment-website
- https://docs.cloud.google.com/recaptcha/docs/instrument-web-pages-with-checkbox
- https://docs.cloud.google.com/recaptcha/docs/authentication

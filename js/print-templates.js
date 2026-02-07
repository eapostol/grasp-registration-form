/*
  GRASP print templates

  Goal:
  - Produce a clean, PDF-like printable layout for Enrollment + Waitlist.
  - Keep email preview HTML separate from the print HTML.

  Usage:
    const html = window.GRASP_PRINT_TEMPLATES.buildEnrollmentPrintHtml(formState, window.config);
*/

(function () {
  "use strict";

  function escapeHtml(input) {
    if (input === null || typeof input === "undefined") return "";
    const s = String(input);
    return s
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function isEmpty(v) {
    return v === null || typeof v === "undefined" || String(v).trim() === "";
  }

  function formatMaybeIsoDate(v) {
    if (isEmpty(v)) return "";
    const s = String(v).trim();
    // ISO: YYYY-MM-DD
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return s;
    const yyyy = m[1],
      mm = m[2],
      dd = m[3];
    return `${dd}/${mm}/${yyyy}`;
  }

  // Backward/alias support (older key names used in earlier drafts).
  const ALIASES = {
    parent1_home_phone: "parent1_phones",
    parent1_cell_phone: "parent1_phones",
    parent1_work_school_hours: null,
    disposition: "child_disposition",
    home_language: "languages_spoken",
    fears: "child_fears",
    emergency_contact_phone: "emergency_contact_day_phone",
    allergies_special_needs: "child_allergies",
  };

  function getValue(state, key) {
    if (!state) return "";
    if (Object.prototype.hasOwnProperty.call(state, key)) return state[key];
    const alias = ALIASES[key];
    if (alias && Object.prototype.hasOwnProperty.call(state, alias))
      return state[alias];
    return "";
  }

  function normalizeValueForPrint(v, { type = null } = {}) {
    if (v === null || typeof v === "undefined") return "";

    if (type === "date") return formatMaybeIsoDate(v);

    if (Array.isArray(v)) {
      return v
        .map((x) => String(x || "").trim())
        .filter(Boolean)
        .join("\n");
    }

    if (typeof v === "object") {
      // Common case: {label, value}
      if (Object.prototype.hasOwnProperty.call(v, "label"))
        return String(v.label);
      if (Object.prototype.hasOwnProperty.call(v, "value"))
        return String(v.value);
      return JSON.stringify(v);
    }

    return String(v);
  }

  function renderLineField(
    label,
    value,
    { wide = false, multiline = false } = {},
  ) {
    const safeLabel = escapeHtml(label || "");
    const safeValue = escapeHtml(value || "");

    const valueHtml = multiline
      ? `<div class="grasp-line-value grasp-multiline">${safeValue.replace(/\n/g, "<br/>") || "&nbsp;"}</div>`
      : `<div class="grasp-line-value">${safeValue || "&nbsp;"}</div>`;

    return `
      <div class="grasp-line-field${wide ? " grasp-wide" : ""}">
        <div class="grasp-line-label">${safeLabel}</div>
        ${valueHtml}
      </div>
    `;
  }

  function renderTwoCol(leftHtml, rightHtml) {
    return `
      <div class="grasp-two-col">
        <div class="grasp-col">${leftHtml}</div>
        <div class="grasp-col">${rightHtml}</div>
      </div>
    `;
  }

  function renderCheckbox(checked, label) {
    const isChecked = !!checked;
    return `
      <div class="grasp-choice">
        <span class="grasp-checkbox${isChecked ? " checked" : ""}" aria-hidden="true"></span>
        <span class="grasp-choice-label">${escapeHtml(label || "")}</span>
      </div>
    `;
  }

  function renderRadioChoices(value, options) {
    const v = isEmpty(value) ? "" : String(value);
    const safeOptions = Array.isArray(options) ? options : [];
    return safeOptions
      .map((opt) =>
        renderCheckbox(v === String(opt.value), opt.label || opt.value),
      )
      .join("");
  }

  function renderHeader({ formTitle, includeFax = true } = {}) {
    const contact = includeFax
      ? "15 Greenland Rd, Toronto ON, M3C 1N1 · Phone: 416 444 7427 · Fax: 416 444 8019 · Email: info@greenlandrecreational.com"
      : "15 Greenland Rd, Toronto ON, M3C 1N1 · Phone: 416 444 7427 · Email: info@greenlandrecreational.com";

    return `
      <div class="grasp-header">
        <div class="grasp-header-org">Greenland Recreational After School Program</div>
        <div class="grasp-header-contact">${escapeHtml(contact)}</div>
        <div class="grasp-brand-bar" aria-hidden="true"></div>
        <div class="grasp-form-title">${escapeHtml(formTitle || "")}</div>
      </div>
    `;
  }

  function renderPage({ headerHtml, bodyHtml, pageBreakAfter = true } = {}) {
    return `
      <section class="grasp-print-page">
        ${headerHtml || ""}
        <div class="grasp-page-body">
          ${bodyHtml || ""}
        </div>
      </section>
      ${pageBreakAfter ? '<div class="grasp-page-break"></div>' : ""}
    `;
  }

  function enrollmentPage1(state) {
    const childName = normalizeValueForPrint(getValue(state, "child_name"));
    const birthDate = normalizeValueForPrint(
      getValue(state, "child_birth_date"),
      { type: "date" },
    );
    const subsidy = normalizeValueForPrint(
      getValue(state, "subsidy_file_number"),
    );

    const parent1Block = [
      renderLineField(
        "Parent/Guardian (1) name",
        normalizeValueForPrint(getValue(state, "parent1_name")),
      ),
      renderLineField(
        "Email",
        normalizeValueForPrint(getValue(state, "parent1_email")),
      ),
      renderLineField(
        "Home address",
        normalizeValueForPrint(getValue(state, "parent1_home_address")) ||
          normalizeValueForPrint(getValue(state, "parent1_home_street")),
        { multiline: true },
      ),
      renderLineField(
        "Postal code",
        normalizeValueForPrint(getValue(state, "parent1_postal_code")),
      ),
      renderLineField(
        "Cell and home #",
        normalizeValueForPrint(getValue(state, "parent1_phones")),
      ),
      renderLineField(
        "Work/School address",
        normalizeValueForPrint(getValue(state, "parent1_work_address")) ||
          normalizeValueForPrint(getValue(state, "parent1_work_street")),
        { multiline: true },
      ),
      renderLineField(
        "Work/School phone #",
        normalizeValueForPrint(getValue(state, "parent1_work_phone")),
      ),
    ].join("");

    const parent2Block = [
      renderLineField(
        "Parent/Guardian (2) name",
        normalizeValueForPrint(getValue(state, "parent2_name")),
      ),
      renderLineField(
        "Email",
        normalizeValueForPrint(getValue(state, "parent2_email")),
      ),
      renderLineField(
        "Home address",
        normalizeValueForPrint(getValue(state, "parent2_home_address")) ||
          normalizeValueForPrint(getValue(state, "parent2_home_street")),
        { multiline: true },
      ),
      renderLineField(
        "Postal code",
        normalizeValueForPrint(getValue(state, "parent2_postal_code")),
      ),
      renderLineField(
        "Cell and home #",
        normalizeValueForPrint(getValue(state, "parent2_phones")),
      ),
      renderLineField(
        "Work/School address",
        normalizeValueForPrint(getValue(state, "parent2_work_address")) ||
          normalizeValueForPrint(getValue(state, "parent2_work_street")),
        { multiline: true },
      ),
      renderLineField(
        "Work/School phone #",
        normalizeValueForPrint(getValue(state, "parent2_work_phone")),
      ),
    ].join("");

    const doctorBlock = [
      renderLineField(
        "Doctor’s name",
        normalizeValueForPrint(getValue(state, "doctor_name")),
      ),
      renderLineField(
        "Phone #",
        normalizeValueForPrint(getValue(state, "doctor_phone")),
      ),
      renderLineField(
        "Doctor’s address",
        normalizeValueForPrint(getValue(state, "doctor_address")) ||
          normalizeValueForPrint(getValue(state, "doctor_street")),
        { multiline: true, wide: true },
      ),
      renderLineField(
        "Postal code",
        normalizeValueForPrint(getValue(state, "doctor_postal_code")),
      ),
    ].join("");

    const allergiesBlock = [
      renderLineField(
        "Does your child have any allergies?",
        normalizeValueForPrint(getValue(state, "child_allergies")),
        {
          multiline: true,
          wide: true,
        },
      ),
      renderLineField(
        "Symptoms to look for with allergy",
        normalizeValueForPrint(getValue(state, "allergy_symptoms")),
        { multiline: true, wide: true },
      ),
      renderLineField(
        "Treatment for allergy",
        normalizeValueForPrint(getValue(state, "allergy_treatment")),
        { multiline: true, wide: true },
      ),
      renderLineField(
        "Epipen required?",
        normalizeValueForPrint(getValue(state, "epipen_required")),
      ),
    ].join("");

    const emergencyBlock = [
      renderLineField(
        "First person to call in case of emergency (other than parent/guardians) – Name",
        normalizeValueForPrint(getValue(state, "emergency_contact_name")),
        { wide: true },
      ),
      renderLineField(
        "Relationship to child",
        normalizeValueForPrint(
          getValue(state, "emergency_contact_relationship"),
        ),
      ),
      renderLineField(
        "Day time phone #",
        normalizeValueForPrint(getValue(state, "emergency_contact_day_phone")),
      ),
      renderLineField(
        "Address",
        normalizeValueForPrint(getValue(state, "emergency_contact_address")),
        { multiline: true, wide: true },
      ),
    ].join("");

    const pickups = normalizeValueForPrint(
      getValue(state, "authorized_pickups"),
    );

    const body = `
      <div class="grasp-section">
        <div class="grasp-section-title">Child & Parent/Guardian Information</div>
        <div class="grasp-field-grid grasp-3col">
          ${renderLineField("Child’s name", childName, { wide: true })}
          ${renderLineField("Birth date (D/M/Y)", birthDate)}
          ${renderLineField("Subsidy file #", subsidy)}
        </div>

        ${renderTwoCol(parent1Block, parent2Block)}

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Doctor Information</div>
          <div class="grasp-field-grid grasp-2col">${doctorBlock}</div>
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Allergies</div>
          <div class="grasp-field-grid">${allergiesBlock}</div>
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Emergency Contact</div>
          <div class="grasp-field-grid grasp-2col">${emergencyBlock}</div>
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">People authorized to pick up (other than parents)</div>
          ${renderLineField("Names", pickups, { multiline: true, wide: true })}
        </div>

        <div class="grasp-signatures">
          ${renderLineField(
            "Parent/Guardian signature (typed)",
            normalizeValueForPrint(
              getValue(state, "parent_full_name_signature"),
            ),
            { wide: true },
          )}
          ${renderLineField(
            "Date",
            normalizeValueForPrint(getValue(state, "signature_date"), {
              type: "date",
            }),
          )}
          ${renderLineField("Witness", "")}
        </div>
      </div>
    `;

    return body;
  }

  function enrollmentPage2(state) {
    const childName = normalizeValueForPrint(getValue(state, "child_name"));
    const parentSig = normalizeValueForPrint(
      getValue(state, "parent_full_name_signature"),
    );
    const sigDate = normalizeValueForPrint(getValue(state, "signature_date"), {
      type: "date",
    });

    const body = `
      <div class="grasp-section">
        <div class="grasp-section-title">Medical & Health Information</div>
        <p class="grasp-section-intro">Medical information and consents for emergency treatment.</p>

        <div class="grasp-policy">
          <div class="grasp-policy-title">MEDICATION</div>
          <div class="grasp-policy-text">
            The Centre will administer only prescription medication as required. All medication must come in the original container
            with the prescription label. The Centre will document all medication on the appropriate consent form and
            parents/guardians must sign this medication form before the medication is administered to their child.
          </div>
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">(MEDICAL RELEASE) PARENTS CONSENT FOR MEDICAL TREATMENT</div>
          <div class="grasp-policy-text">
            In the event that a parent/guardian cannot be reached, I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>
            give permission for a Greenland Recreational After School Program qualified staff member to secure any emergency medical
            treatment deemed necessary for my child, <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, by the attending physician.
            Treatment may include anesthetic and/or blood transfusion. I also consent to emergency transportation of whatever type seen fit
            by the staff of the child care centre at the time of the incident. Transportation will be by ambulance, taxi or on rare occasion private vehicle driven by a licensed driver. 
Parents will be notified that their child has been taken to the hospital and updated as often as possible thereafter. (In 
cases where child abuse is suspected parents will be contacted as advised by a Children Aid Society worker.)<em>A copy of 
an updated immunization record must be attached to thisform. </em>
          </div>

          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent/Guardian signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Date", sigDate)}
            ${renderLineField("Witness", "")}
          </div>
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">General Health</div>
          <div class="grasp-field-grid">
            ${renderLineField(
              "General health about your child / things to be aware of",
              normalizeValueForPrint(getValue(state, "general_health_notes")),
              { multiline: true, wide: true },
            )}
            <div class="grasp-field-grid grasp-2col">
              ${renderLineField("Is your child asthmatic?", normalizeValueForPrint(getValue(state, "child_asthmatic")))}
              ${renderLineField("Is your child using a puffer?", normalizeValueForPrint(getValue(state, "child_uses_puffer")))}
              ${renderLineField(
                "Date of last medical examination (y/m/d)",
                normalizeValueForPrint(
                  getValue(state, "last_medical_exam_date"),
                  { type: "date" },
                ),
              )}
              ${renderLineField("Current weight", normalizeValueForPrint(getValue(state, "current_weight")))}
              ${renderLineField(
                "At present time is the child free of communicable diseases?",
                normalizeValueForPrint(getValue(state, "free_of_disease")),
                { wide: true },
              )}
            </div>
            ${renderLineField(
              "Previous history of communicable diseases",
              normalizeValueForPrint(getValue(state, "disease_history")),
              { multiline: true, wide: true },
            )}
            ${renderLineField(
              "Special requirements for diet, rest or exercise",
              normalizeValueForPrint(getValue(state, "special_requirements")),
              { multiline: true, wide: true },
            )}
          </div>
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">AUTHORIZATION FOR RECREATIONAL WATER PLAY</div>
          <div class="grasp-policy-text">
            I, the parent/guardian of <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, hereby give my consent for my child
            to participate in water play such as splash pads and kids town and swimming pools under the supervision and guidance of
            the Centre staff.
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(
              normalizeValueForPrint(getValue(state, "water_play_consent")),
              [
                { value: "yes", label: "I consent" },
                { value: "no", label: "I do not consent" },
              ],
            )}
          </div>
          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent/Guardian signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Witness", "")}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">AUTHORIZATION FOR THE USE OF HAND SANITIZER</div>
          <div class="grasp-policy-text">
            I, the parent/guardian of <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, hereby give my consent for my child to use
            hand sanitizer with 70% to 90% alcohol content under the supervision and guidance of the Centre staff.
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(
              normalizeValueForPrint(getValue(state, "hand_sanitizer_consent")),
              [
                { value: "yes", label: "I consent" },
                { value: "no", label: "I do not consent" },
              ],
            )}
          </div>
          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent/Guardian signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Witness", "")}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>
      </div>
    `;

    return body;
  }

  function enrollmentPage3(state) {
    const childName = normalizeValueForPrint(getValue(state, "child_name"));
    const birthDate = normalizeValueForPrint(
      getValue(state, "child_birth_date"),
      { type: "date" },
    );
    const parentSig = normalizeValueForPrint(
      getValue(state, "parent_full_name_signature"),
    );
    const sigDate = normalizeValueForPrint(getValue(state, "signature_date"), {
      type: "date",
    });

    const body = `
      <div class="grasp-section">
        <div class="grasp-section-title">Initial Parent/Guardian Interview</div>

        <div class="grasp-field-grid grasp-2col">
          ${renderLineField("Child name", childName, { wide: true })}
          ${renderLineField("Date of birth", birthDate)}
          ${renderLineField("Birthmarks", "")}
          ${renderLineField("Child’s disposition", normalizeValueForPrint(getValue(state, "child_disposition")))}
        </div>

        ${renderLineField(
          "General information about eating habits or food restrictions",
          normalizeValueForPrint(getValue(state, "eating_habits")),
          { multiline: true, wide: true },
        )}

        <div class="grasp-field-grid grasp-2col">
          ${renderLineField("Language(s) spoken at home", normalizeValueForPrint(getValue(state, "languages_spoken")))}
          ${renderLineField(
            "Is your child talking, comprehending?",
            normalizeValueForPrint(
              getValue(state, "child_talking_comprehending"),
            ),
          )}
        </div>

        ${renderLineField(
          "What method of discipline do you use in your home?",
          normalizeValueForPrint(getValue(state, "discipline_method")),
          { multiline: true, wide: true },
        )}

        <div class="grasp-field-grid grasp-2col">
          ${renderLineField("Does your child have any specific fears?", normalizeValueForPrint(getValue(state, "child_fears")))}
          ${renderLineField("Reaction to fear", normalizeValueForPrint(getValue(state, "fear_reaction")))}
        </div>

        <div class="grasp-field-grid grasp-2col">
          ${renderLineField("What frustrates your child?", normalizeValueForPrint(getValue(state, "child_frustrations")))}
          ${renderLineField(
            "How do you handle frustrations?",
            normalizeValueForPrint(getValue(state, "child_frustrations")),
          )}
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Child’s special needs or cultural interests</div>
          ${renderLineField(
            "Notes",
            normalizeValueForPrint(getValue(state, "child_special_needs")),
            { multiline: true, wide: true },
          )}
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Child’s interests</div>
          ${renderLineField(
            "Activities, sports, hobbies, etc.",
            normalizeValueForPrint(getValue(state, "child_interests")),
            { multiline: true, wide: true },
          )}
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">Arrival & Departure Procedure</div>
          <div class="grasp-policy-text">
            I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, agree to accompany my child to and from the GRASP classroom and
            notify staff verbally upon arrival and departure. I understand that it is my responsibility to inform all pick up and drop off
            persons of this policy and ensure they make verbal contact with the staff.
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(
              normalizeValueForPrint(getValue(state, "arrival_departure_ack")),
              [
                { value: "yes", label: "I acknowledge and agree" },
                { value: "no", label: "I do not agree" },
              ],
            )}
          </div>
          ${renderLineField(
            "Notes",
            normalizeValueForPrint(getValue(state, "arrival_departure_notes")),
            { multiline: true, wide: true },
          )}
          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent/Guardian signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Witness", "")}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>
      </div>
    `;

    return body;
  }

  function enrollmentPage4(state) {
    const childName = normalizeValueForPrint(getValue(state, "child_name"));
    const parentSig = normalizeValueForPrint(
      getValue(state, "parent_full_name_signature"),
    );
    const sigDate = normalizeValueForPrint(getValue(state, "signature_date"), {
      type: "date",
    });

    const infoSharing = normalizeValueForPrint(
      getValue(state, "info_sharing_consent"),
    );
    const travelConsent = normalizeValueForPrint(
      getValue(state, "travel_consent"),
    );
    const photoConsent = normalizeValueForPrint(
      getValue(state, "photo_media_consent"),
    );

    const body = `
      <div class="grasp-section">
        <div class="grasp-section-title">Policies & Consents</div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">DISCLOSURE OF INFORMATION POLICY</div>
          <div class="grasp-policy-text">
            Consent for sharing information among professionals involved in a child’s day enhances educational and family support.
            I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, consent to reciprocal exchange of information about my child,
            <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, between GRASP, the school and Toronto Children’s Services.
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(infoSharing, [
              { value: "yes", label: "I consent" },
              { value: "no", label: "I do not consent" },
            ])}
          </div>
          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent/Guardian signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Witness", "")}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">TRAVEL CONSENT</div>
          <div class="grasp-policy-text">
            I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, the parent/guardian of
            <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, give consent for my child to leave GRASP premises under staff
            supervision to participate in local outings that can be reached without motorized transportation.
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(travelConsent, [
              { value: "yes", label: "I consent" },
              { value: "no", label: "I do not consent" },
            ])}
          </div>
          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent/Guardian signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Witness", "")}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">PHOTOGRAPH / MEDIA RELEASE</div>
          <div class="grasp-policy-text">
            I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, grant GRASP the right to use photographed or electronic images
            and/or audio-video recordings of my child for GRASP activities and/or promoting GRASP.
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(photoConsent, [
              { value: "full", label: "I agree to full use as described" },
              {
                value: "limited",
                label: "Limited use (centre only, internal projects)",
              },
              { value: "none", label: "I do not agree" },
            ])}
          </div>
          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent/Guardian signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Witness", "")}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>
      </div>
    `;

    return body;
  }

  function enrollmentPage5(state) {
    const parentSig = normalizeValueForPrint(
      getValue(state, "parent_full_name_signature"),
    );
    const sigDate = normalizeValueForPrint(getValue(state, "signature_date"), {
      type: "date",
    });

    const safeArrival = normalizeValueForPrint(
      getValue(state, "safe_arrival_ack"),
    );
    const beforeSchool = normalizeValueForPrint(
      getValue(state, "before_school_program_ack"),
    );
    const sunscreenProvidedBy = normalizeValueForPrint(
      getValue(state, "sunscreen_provided_by"),
    );
    const sunscreenAssist = normalizeValueForPrint(
      getValue(state, "sunscreen_assistance_consent"),
    );
    const sunSafety = normalizeValueForPrint(getValue(state, "sun_safety_ack"));

    const body = `
      <div class="grasp-section">
        <div class="grasp-section-title">Safe Arrival & Sun Safety</div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">SAFE ARRIVAL AND DISMISSAL Acknowledgement</div>
          <div class="grasp-policy-text">
            This policy helps support the safe arrival and dismissal of children receiving care. Parents must call and inform the childcare
            by 10am if their child will be absent from childcare and/or school.
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(safeArrival, [
              { value: "yes", label: "I acknowledge and agree" },
              { value: "no", label: "I do not agree" },
            ])}
          </div>

          <div class="grasp-policy-text grasp-tight">
            Acknowledgement for children who attend school:
          </div>
          <div class="grasp-choices">
            ${renderRadioChoices(beforeSchool, [
              {
                value: "yes",
                label:
                  "My child may NOT attend before school program daily and may be dropped off directly at school.",
              },
              { value: "no", label: "Not applicable / I do not agree" },
            ])}
          </div>

          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-title">Sun & Safety Policy – Sunscreen</div>
          <div class="grasp-policy-text">
            GRASP will provide sunscreen for the summer months (NO-AD SPF 30–45). Parents who wish to provide their own sunscreen must
            supply a labelled cream-only bottle with their child’s name.
          </div>

          <div class="grasp-subsection">
            <div class="grasp-subtitle">Sunscreen arrangement</div>
            <div class="grasp-choices">
              ${renderRadioChoices(sunscreenProvidedBy, [
                { value: "centre", label: "GRASP will provide sunscreen" },
                {
                  value: "parent",
                  label: "I will provide my child’s sunscreen (cream only)",
                },
              ])}
            </div>
          </div>

          <div class="grasp-subsection">
            <div class="grasp-subtitle">Assistance consent</div>
            <div class="grasp-choices">
              ${renderRadioChoices(sunscreenAssist, [
                {
                  value: "yes",
                  label:
                    "GRASP may assist my child in the application of sunscreen if necessary",
                },
                { value: "no", label: "GRASP may NOT assist" },
              ])}
            </div>
          </div>

          <div class="grasp-subsection">
            <div class="grasp-subtitle">Acknowledgement</div>
            <div class="grasp-choices">
              ${renderRadioChoices(sunSafety, [
                {
                  value: "yes",
                  label:
                    "I understand I must send my child with a water bottle and hat each day during July and August",
                },
                { value: "no", label: "I do not agree" },
              ])}
            </div>
          </div>

          <div class="grasp-field-grid grasp-2col">
            ${renderLineField("Parent signature (typed)", parentSig, { wide: true })}
            ${renderLineField("Date", sigDate)}
          </div>
        </div>

        <div class="grasp-section">
          <div class="grasp-section-title">Additional Comments</div>
          ${renderLineField(
            "Comments",
            normalizeValueForPrint(getValue(state, "additional_comments")),
            { multiline: true, wide: true },
          )}
        </div>
      </div>
    `;

    return body;
  }

  function buildEnrollmentPrintHtml(state, config) {
    const cfg = config || {};
    const childName = normalizeValueForPrint(getValue(state, "child_name"));
    const birthDate = normalizeValueForPrint(
      getValue(state, "child_birth_date"),
      { type: "date" },
    );
    const subsidy = normalizeValueForPrint(
      getValue(state, "subsidy_file_number"),
    );
    const parentSig = normalizeValueForPrint(
      getValue(state, "parent_full_name_signature"),
    );
    const sigDate = normalizeValueForPrint(getValue(state, "signature_date"), {
      type: "date",
    });

    // Convert a stored value into a printable display value using config options where possible.
    function displayFieldValue(fieldName, opts = {}) {
      const raw = getValue(state, fieldName);
      const field = cfg ? findField(cfg, fieldName) : null;

      // Respect explicit print formatting hints first
      if (opts.type === "date") {
        return normalizeValueForPrint(raw, { type: "date" });
      }

      if (field?.type === "date") {
        return normalizeValueForPrint(raw, { type: "date" });
      }

      if (field?.type === "checkbox") {
        return raw ? "YES" : "NO";
      }

      if (field?.type === "radio") {
        const val = raw ?? "";
        const opt = (field.options || []).find(
          (o) => String(o.value) === String(val),
        );
        return opt ? opt.label : normalizeValueForPrint(val);
      }

      return normalizeValueForPrint(raw);
    }

    function htmlValue(value, { multiline = false } = {}) {
      const v = value ?? "";
      if (!multiline) return escapeHtml(String(v));
      // Preserve user-entered line breaks (addresses, notes) without forcing hard wraps.
      return escapeHtml(String(v)).replace(/\n/g, "<br />");
    }

    function kvRow(label, value, { multiline = false } = {}) {
      const valueHtml = multiline
        ? `<div class="grasp-kv-multiline">${htmlValue(value, { multiline: true })}</div>`
        : htmlValue(value);
      return `<tr><td class="grasp-kv-label">${escapeHtml(label)}</td><td class="grasp-kv-value">${valueHtml}</td></tr>`;
    }

    function kvTable(rowsHtml) {
      return `<table class="grasp-kv-table"><tbody>${rowsHtml}</tbody></table>`;
    }

    function section(title, innerHtml) {
      return `<div class="grasp-section"><div class="grasp-section-title">${escapeHtml(title)}</div>${innerHtml}</div>`;
    }

    function fieldBlock(label, value, { multiline = false } = {}) {
      const valueHtml = multiline
        ? `<div class="grasp-field-value grasp-multiline">${htmlValue(value, { multiline: true })}</div>`
        : `<div class="grasp-field-value">${htmlValue(value)}</div>`;
      return `<div class="grasp-field"><div class="grasp-field-label">${escapeHtml(label)}</div>${valueHtml}</div>`;
    }

    function signatureRow({
      includeWitness = true,
      includeDate = true,
      label = "Parent/Guardian signature (typed)",
    } = {}) {
      const blocks = [];
      blocks.push(fieldBlock(label, parentSig));
      if (includeDate) blocks.push(fieldBlock("Date", sigDate));
      if (includeWitness) blocks.push(fieldBlock("Witness", ""));
      return `<div class="grasp-sign-row">${blocks.join("")}</div>`;
    }

    function headerHtml() {
      return `
        <div class="grasp-header">
          <h1 class="grasp-header-title">Greenland Recreational After School Program</h1>
          <div class="grasp-header-contact">15 Greenland Rd, Toronto ON M3C 1N1 · Phone: 416-444-7290 · Fax: 416-444-4381</div>
          <div class="grasp-form-title">GRASP Enrollment Form</div>
        </div>
        <div class="grasp-brand-bar"></div>
      `;
    }

    function page(bodyHtml) {
      return `<div class="grasp-page">${headerHtml()}${bodyHtml}</div>`;
    }

    // -----------------
    // Page 1 (Info)
    // -----------------
    const page1ChildRows = [
      kvRow("Child’s name", childName),
      kvRow("Birth date (D/M/Y)", birthDate),
      kvRow("Subsidy file #", subsidy),
    ].join("");

    const parent1Rows = [
      kvRow("Parent / Guardian 1 name", displayFieldValue("parent1_name")),
      kvRow("Email address", displayFieldValue("parent1_email")),
      kvRow("Home address", displayFieldValue("parent1_home_address"), {
        multiline: true,
      }),
      kvRow("Postal code", displayFieldValue("parent1_postal_code")),
      kvRow("Phone # (home/cell)", displayFieldValue("parent1_phones")),
      kvRow("Work/School address", displayFieldValue("parent1_work_address"), {
        multiline: true,
      }),
      kvRow("Work/School phone #", displayFieldValue("parent1_work_phone")),
    ].join("");

    const parent2Rows = [
      kvRow("Parent / Guardian 2 name", displayFieldValue("parent2_name")),
      kvRow("Email address", displayFieldValue("parent2_email")),
      kvRow("Home address", displayFieldValue("parent2_home_address"), {
        multiline: true,
      }),
      kvRow("Postal code", displayFieldValue("parent2_postal_code")),
      kvRow("Phone # (home/cell)", displayFieldValue("parent2_phones")),
      kvRow("Work/School address", displayFieldValue("parent2_work_address"), {
        multiline: true,
      }),
      kvRow("Work/School phone #", displayFieldValue("parent2_work_phone")),
    ].join("");

    const doctorRows = [
      kvRow("Doctor’s name", displayFieldValue("doctor_name")),
      kvRow("Phone #", displayFieldValue("doctor_phone")),
      kvRow("Address", displayFieldValue("doctor_address"), {
        multiline: true,
      }),
      kvRow("Postal code", displayFieldValue("doctor_postal_code")),
    ].join("");

    const allergyRows = [
      kvRow("Allergies / conditions", displayFieldValue("child_allergies"), {
        multiline: true,
      }),
      kvRow("Symptoms", displayFieldValue("allergy_symptoms"), {
        multiline: true,
      }),
      kvRow("Treatment", displayFieldValue("allergy_treatment"), {
        multiline: true,
      }),
      kvRow("Epipen required?", displayFieldValue("epipen_required")),
    ].join("");

    const emergencyRows = [
      kvRow("Name", displayFieldValue("emergency_contact_name")),
      kvRow(
        "Relationship",
        displayFieldValue("emergency_contact_relationship"),
      ),
      kvRow(
        "Day time phone #",
        displayFieldValue("emergency_contact_day_phone"),
      ),
      kvRow("Address", displayFieldValue("emergency_contact_address"), {
        multiline: true,
      }),
    ].join("");

    const pickupsRows = [
      kvRow("Names", displayFieldValue("authorized_pickups"), {
        multiline: true,
      }),
    ].join("");

    const page1Body = [
      section("Child & Parent/Guardian Information", kvTable(page1ChildRows)),
      `<div class="grasp-grid-2">
        <div class="grasp-section">
          <div class="grasp-section-title">Parent / Guardian 1</div>
          ${kvTable(parent1Rows)}
        </div>
        <div class="grasp-section">
          <div class="grasp-section-title">Parent / Guardian 2 (optional)</div>
          ${kvTable(parent2Rows)}
        </div>
      </div>`,
      `<div class="grasp-grid-2">
        <div class="grasp-section">
          <div class="grasp-section-title">Doctor Information</div>
          ${kvTable(doctorRows)}
        </div>
        <div class="grasp-section">
          <div class="grasp-section-title">Allergies</div>
          ${kvTable(allergyRows)}
        </div>
      </div>`,
      section("Emergency Contact", kvTable(emergencyRows)),
      section(
        "People authorized to pick up (other than parents)",
        kvTable(pickupsRows),
      ),
      `<div class="grasp-section">
        <div class="grasp-section-title">Signature</div>
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
    ].join("");

    // -----------------
    // Page 2 (Health)
    // -----------------
    const medicationText =
      "The Centre will administer only prescription medication as required. All medication must come in the original container with the prescription label. The Centre will document all medication on the appropriate consent form and parents/guardians must sign this medication form before the medication is administered to their child.";

    const medicalConsent = displayFieldValue("medical_release_consent");
    const waterConsent = displayFieldValue("water_play_consent");
    const sanitizerConsent = displayFieldValue("hand_sanitizer_consent");

    const page2Body = [
      `<div class="grasp-section">
        <div class="grasp-section-title">Medical & Health Information</div>
        <div class="grasp-subtitle">MEDICATION</div>
        <p class="grasp-paragraph">${escapeHtml(medicationText)}</p>
        ${kvTable(kvRow("Medication (prescription only) – details", displayFieldValue("medication_notes"), { multiline: true }))}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Medical Release – Parents Consent for Medical Treatment</div>
        <p class="grasp-paragraph">
          In the event that a parent/guardian cannot be reached, I,
          <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>
          give permission for a Greenland Recreational After School Program qualified staff member to secure any emergency medical treatment deemed necessary for my child,
          <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>,
          by the attending physician. Treatment may include anesthetic and/or blood transfusion. I also consent to emergency transportation of whatever type seen fit by the staff of the child care centre at the time of the incident.
        </p>
        ${kvTable(kvRow("Consent", medicalConsent))}
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">General Health</div>
        ${kvTable(
          [
            kvRow(
              "General health notes / things to be aware of",
              displayFieldValue("general_health_notes"),
              { multiline: true },
            ),
            kvRow(
              "Is your child asthmatic?",
              displayFieldValue("child_asthmatic"),
            ),
            kvRow(
              "Is your child using a puffer?",
              displayFieldValue("child_uses_puffer"),
            ),
            kvRow(
              "Date of last medical examination (y/m/d)",
              displayFieldValue("last_medical_exam_date", { type: "date" }),
            ),
            kvRow("Current weight", displayFieldValue("current_weight")),
            kvRow(
              "At present time is the child free of communicable diseases?",
              displayFieldValue("free_of_disease"),
            ),
            kvRow(
              "Previous history of communicable diseases",
              displayFieldValue("disease_history"),
              { multiline: true },
            ),
            kvRow(
              "Special requirements for diet, rest or exercise",
              displayFieldValue("special_requirements"),
              { multiline: true },
            ),
          ].join(""),
        )}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Authorization for Recreational Water Play</div>
        <p class="grasp-paragraph">
          I, the parent/guardian of <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, hereby give my consent for my child to participate in water play such as splash pads and kids town and swimming pools under the supervision and guidance of the Centre staff.
        </p>
        ${kvTable(kvRow("Selection", waterConsent))}
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Authorization for the Use of Hand Sanitizer</div>
        <p class="grasp-paragraph">
          I, the parent/guardian of <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, hereby give my consent for my child to use hand sanitizer with 70% to 90% alcohol content under the supervision and guidance of the Centre staff.
        </p>
        ${kvTable(kvRow("Selection", sanitizerConsent))}
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
    ].join("");

    // -----------------
    // Page 3 (Interview)
    // -----------------
    const arrivalAck = displayFieldValue("arrival_departure_ack");
    const arrivalNotes = displayFieldValue("arrival_departure_notes");

    const page3Body = [
      `<div class="grasp-section">
        <div class="grasp-section-title">Initial Parent/Guardian Interview</div>
        ${kvTable(
          [
            kvRow("Child name", childName),
            kvRow("Date of birth", birthDate),
            kvRow("Birthmarks", ""),
            kvRow(
              "Child’s disposition",
              displayFieldValue("child_disposition"),
            ),
            kvRow(
              "General information about eating habits or food restrictions",
              displayFieldValue("eating_habits"),
              { multiline: true },
            ),
            kvRow(
              "Language(s) spoken at home",
              displayFieldValue("languages_spoken"),
            ),
            kvRow(
              "Is your child talking, comprehending?",
              displayFieldValue("child_talking_comprehending"),
            ),
            kvRow(
              "What method of discipline do you use in your home?",
              displayFieldValue("discipline_method"),
              { multiline: true },
            ),
            kvRow(
              "Does your child have any specific fears?",
              displayFieldValue("child_fears"),
            ),
            kvRow("Reaction to fear", displayFieldValue("fear_reaction")),
            kvRow(
              "What frustrates your child?",
              displayFieldValue("child_frustrations"),
            ),
            kvRow(
              "How do you handle frustrations?",
              displayFieldValue("frustrations_handling"),
            ),
            kvRow(
              "Child’s special needs or cultural interests",
              displayFieldValue("child_special_needs"),
              { multiline: true },
            ),
            kvRow(
              "Child’s interests (activities, sports, hobbies, etc.)",
              displayFieldValue("child_interests"),
              { multiline: true },
            ),
          ].join(""),
        )}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Arrival & Departure Procedure</div>
        <p class="grasp-paragraph">
          I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, agree to accompany my child to and from the GRASP classroom and notify staff verbally upon arrival and departure. I understand that it is my responsibility to inform all pick up and drop off persons of this policy and ensure they make verbal contact with the staff.
        </p>
        ${kvTable([kvRow("Acknowledgement", arrivalAck), kvRow("Notes", arrivalNotes, { multiline: true })].join(""))}
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
    ].join("");

    // -----------------
    // Page 4 (Consents)
    // -----------------
    const infoSharing = displayFieldValue("info_sharing_consent");
    const travelConsent2 = displayFieldValue("travel_consent");
    const photoConsent = displayFieldValue("photo_media_consent");

    const page4Body = [
      `<div class="grasp-section">
        <div class="grasp-section-title">Policies & Consents</div>
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Disclosure of Information Policy</div>
        <p class="grasp-paragraph">
          Consent for sharing information among professionals involved in a child’s day enhances educational and family support. I,
          <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, consent to reciprocal exchange of information about my child,
          <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, between GRASP, the school and Toronto Children’s Services.
        </p>
        ${kvTable(kvRow("Selection", infoSharing))}
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Travel Consent</div>
        <p class="grasp-paragraph">
          I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, the parent/guardian of
          <span class="grasp-inline-fill">${escapeHtml(childName || "")}</span>, give consent for my child to leave GRASP premises under staff supervision to participate in local outings that can be reached without motorized transportation.
        </p>
        ${kvTable(kvRow("Selection", travelConsent2))}
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Photograph / Media Release</div>
        <p class="grasp-paragraph">
          I, <span class="grasp-inline-fill">${escapeHtml(parentSig || "")}</span>, grant GRASP the right to use photographed or electronic images and/or audio-video recordings of my child for GRASP activities and/or promoting GRASP.
        </p>
        ${kvTable(kvRow("Selection", photoConsent))}
        ${signatureRow({ includeWitness: true, includeDate: true })}
      </div>`,
    ].join("");

    // -----------------
    // Page 5 (Safe arrival + sun safety + final signature)
    // -----------------
    const safeArrival = displayFieldValue("safe_arrival_ack");
    const beforeSchool = displayFieldValue("before_school_program_ack");
    const sunscreenProvidedBy = displayFieldValue("sunscreen_provided_by");
    const sunscreenAssist = displayFieldValue("sunscreen_assistance_consent");
    const sunSafety = displayFieldValue("sun_safety_ack");
    const finalSig = displayFieldValue("parent_full_name_signature");
    const finalDate = displayFieldValue("signature_date", { type: "date" });
    const additionalComments = displayFieldValue("additional_comments");

    const page5Body = [
      `<div class="grasp-section">
        <div class="grasp-section-title">Safe Arrival & Sun Safety</div>
        <div class="grasp-subtitle">SAFE ARRIVAL AND DISMISSAL Acknowledgement</div>
        <p class="grasp-paragraph">
          This policy helps support the safe arrival and dismissal of children receiving care. Parents must call and inform the childcare by 10am if their child will be absent from childcare and/or school.
        </p>
        ${kvTable(
          [
            kvRow("Acknowledgement", safeArrival),
            kvRow(
              "Acknowledgement for children who attend school",
              beforeSchool,
              { multiline: true },
            ),
          ].join(""),
        )}
        ${signatureRow({ includeWitness: true, includeDate: true, label: "Parent signature (typed)" })}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Sun & Safety Policy – Sunscreen</div>
        <p class="grasp-paragraph">
          GRASP will provide sunscreen for the summer months (NO-AD SPF 30–45). Parents who wish to provide their own sunscreen must supply a labelled cream-only bottle with their child’s name.
        </p>
        ${kvTable(
          [
            kvRow("Sunscreen arrangement", sunscreenProvidedBy),
            kvRow("Assistance consent", sunscreenAssist),
            kvRow("Acknowledgement", sunSafety, { multiline: true }),
          ].join(""),
        )}
        ${signatureRow({ includeWitness: true, includeDate: true, label: "Parent signature (typed)" })}
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Final Acknowledgement & Signature</div>
        <div class="grasp-signature-center">
          <div class="grasp-signature-line">
            <div class="grasp-signature-value">${escapeHtml(finalSig || "")}</div>
            <div class="grasp-signature-caption">Parent/Guardian full name (serves as digital signature)</div>
          </div>
          <div class="grasp-signature-line">
            <div class="grasp-signature-value">${escapeHtml(finalDate || "")}</div>
            <div class="grasp-signature-caption">Date Signed</div>
          </div>
        </div>
      </div>`,
      `<div class="grasp-section">
        <div class="grasp-section-title">Additional Comments</div>
        ${kvTable(kvRow("Comments", additionalComments, { multiline: true }))}
      </div>`,
    ].join("");

    return `
      <div class="grasp-print-root">
        ${page(page1Body)}
        ${page(page2Body)}
        ${page(page3Body)}
        ${page(page4Body)}
        ${page(page5Body)}
      </div>
    `;
  }

  function buildWaitlistPrintHtml(state, config) {
    const header = renderHeader({
      formTitle: "Wait List Application Form",
      includeFax: false,
    });

    const childName = normalizeValueForPrint(getValue(state, "child_name"));
    const birthDate = normalizeValueForPrint(
      getValue(state, "child_birth_date"),
      { type: "date" },
    );
    const dateCareNeeded = normalizeValueForPrint(
      getValue(state, "date_care_needed"),
      { type: "date" },
    );
    const dateApplied = normalizeValueForPrint(
      getValue(state, "date_applied"),
      { type: "date" },
    );

    const address = normalizeValueForPrint(
      getValue(state, "parent1_home_street"),
    );
    const unit = normalizeValueForPrint(getValue(state, "parent1_home_unit"));
    const city = normalizeValueForPrint(getValue(state, "parent1_home_city"));
    const postal = normalizeValueForPrint(
      getValue(state, "parent1_postal_code"),
    );
    const homePhone = normalizeValueForPrint(getValue(state, "parent1_phones"));

    const gender = normalizeValueForPrint(getValue(state, "child_gender"));

    const p1 = [
      renderLineField(
        "Parent 1 name (Guardian 1)",
        normalizeValueForPrint(getValue(state, "parent1_name")),
      ),
      renderLineField(
        "Email address",
        normalizeValueForPrint(getValue(state, "parent1_email")),
      ),
      renderLineField(
        "Work phone #",
        normalizeValueForPrint(getValue(state, "parent1_work_phone")),
      ),
      renderLineField(
        "Cell phone #",
        normalizeValueForPrint(getValue(state, "parent1_cell_phone")),
      ),
    ].join("");

    const p2 = [
      renderLineField(
        "Parent 2 name (Guardian 2)",
        normalizeValueForPrint(getValue(state, "parent2_name")),
      ),
      renderLineField(
        "Email address",
        normalizeValueForPrint(getValue(state, "parent2_email")),
      ),
      renderLineField(
        "Work phone #",
        normalizeValueForPrint(getValue(state, "parent2_work_phone")),
      ),
      renderLineField(
        "Cell phone #",
        normalizeValueForPrint(getValue(state, "parent2_cell_phone")),
      ),
    ].join("");

    // Use config options when present for consistent labels.
    const subsidyField = config && findField(config, "child_subsidy_status");
    const siblingField = config && findField(config, "has_sibling_at_grasp");
    const summerField =
      config && findField(config, "interested_summer_camp_only");
    const schoolYearField =
      config && findField(config, "interested_school_year_only");

    const subsidyChoices = subsidyField
      ? renderRadioChoices(
          normalizeValueForPrint(getValue(state, "child_subsidy_status")),
          subsidyField.options,
        )
      : renderRadioChoices(
          normalizeValueForPrint(getValue(state, "child_subsidy_status")),
          [
            { value: "has_subsidy", label: "Has subsidy in place" },
            {
              value: "on_subsidy_wait_list",
              label: "Is on the Subsidy wait list",
            },
            { value: "paying_full_fee", label: "Will be paying full fee" },
          ],
        );

    const siblingChoices = siblingField
      ? renderRadioChoices(
          normalizeValueForPrint(getValue(state, "has_sibling_at_grasp")),
          siblingField.options,
        )
      : renderRadioChoices(
          normalizeValueForPrint(getValue(state, "has_sibling_at_grasp")),
          [
            { value: "YES", label: "Yes" },
            { value: "NO", label: "No" },
          ],
        );

    const summerChoices = summerField
      ? renderRadioChoices(
          normalizeValueForPrint(
            getValue(state, "interested_summer_camp_only"),
          ),
          summerField.options,
        )
      : renderRadioChoices(
          normalizeValueForPrint(
            getValue(state, "interested_summer_camp_only"),
          ),
          [
            { value: "YES", label: "Yes" },
            { value: "NO", label: "No" },
          ],
        );

    const schoolYearChoices = schoolYearField
      ? renderRadioChoices(
          normalizeValueForPrint(
            getValue(state, "interested_school_year_only"),
          ),
          schoolYearField.options,
        )
      : renderRadioChoices(
          normalizeValueForPrint(
            getValue(state, "interested_school_year_only"),
          ),
          [
            { value: "YES", label: "Yes" },
            { value: "NO", label: "No" },
          ],
        );

    const both = !!getValue(state, "interested_both_summer_and_school_year");

    const currentlyDaycare = normalizeValueForPrint(
      getValue(state, "currently_attends_daycare"),
    );
    const currentlySchool = normalizeValueForPrint(
      getValue(state, "currently_attending_school"),
    );
    const willAttendWhen = normalizeValueForPrint(
      getValue(state, "will_attend_when_require_care"),
    );

    const allergies = normalizeValueForPrint(
      getValue(state, "allergies_special_needs"),
    );

    const body = `
      <div class="grasp-section">
        <div class="grasp-field-grid grasp-4col">
          ${renderLineField("Child’s name", childName, { wide: true })}
          ${renderLineField("Date of birth", birthDate)}
          ${renderLineField("Date care needed", dateCareNeeded)}
          ${renderLineField("Date applied", dateApplied)}
        </div>

        <div class="grasp-field-grid grasp-2col">
          ${renderLineField("Gender (circle)", gender)}
          ${renderLineField("Subsidy file #", normalizeValueForPrint(getValue(state, "subsidy_file_number")))}
        </div>

        <div class="grasp-field-grid grasp-2col">
          ${renderLineField("Address", address, { multiline: true, wide: true })}
          ${renderLineField("Apt / unit #", unit)}
          ${renderLineField("City", city)}
          ${renderLineField("Postal code", postal)}
          ${renderLineField("Home phone #", homePhone)}
        </div>

        ${renderTwoCol(p1, p2)}

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Current care / school</div>
          ${renderLineField("My child attends day care and is attending", currentlyDaycare, { wide: true })}
          ${renderLineField("My child is attending school at", currentlySchool, { wide: true })}
          ${renderLineField("My child will attend", willAttendWhen, { wide: true })}
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Subsidy status</div>
          <div class="grasp-choices">${subsidyChoices}</div>
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Sibling at GRASP</div>
          <div class="grasp-choices">${siblingChoices}</div>
          ${renderLineField("Sibling name", normalizeValueForPrint(getValue(state, "sibling_name")), { wide: true })}
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Allergies / special needs</div>
          ${renderLineField("Details", allergies, { multiline: true, wide: true })}
        </div>

        <div class="grasp-subsection">
          <div class="grasp-subtitle">Program interest</div>
          <div class="grasp-choices">
            <div class="grasp-choice-group">
              <div class="grasp-choice-group-title">I am only interested in summer camp</div>
              ${summerChoices}
            </div>
            <div class="grasp-choice-group">
              <div class="grasp-choice-group-title">I am only interested in school year care only</div>
              ${schoolYearChoices}
            </div>
            ${renderCheckbox(both, "I am interested in both summer camp and school year care")}
          </div>
        </div>

        <div class="grasp-policy">
          <div class="grasp-policy-text">
            GRASP maintains an ongoing waiting list for families that have children that attend Greenland Public School, as well as other schools
            within the Don Mills Community. Once a registration form has been filled out, your child(ren)’s names will be added to the waiting list
            in sequence according to the date of application and using the published criteria.
          </div>
        </div>

        <div class="grasp-signatures">
          ${renderLineField("Parent signature", "", { wide: true })}
          ${renderLineField("Date", "")}
        </div>
      </div>
    `;

    return `
      <div class="grasp-print-root">
        ${renderPage({ headerHtml: header, bodyHtml: body, pageBreakAfter: false })}
      </div>
    `;
  }

  function findField(config, name) {
    try {
      const steps = (config && config.steps) || [];
      for (const step of steps) {
        const groups = step.groups || [];
        for (const group of groups) {
          const fields = group.fields || [];
          for (const f of fields) {
            if (f && f.name === name) return f;
          }
        }
      }
    } catch (_) {}
    return null;
  }

  window.GRASP_PRINT_TEMPLATES = {
    buildEnrollmentPrintHtml,
    buildWaitlistPrintHtml,
  };
})();

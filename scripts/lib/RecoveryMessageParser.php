<?php

declare(strict_types=1);

final class RecoveryMessageParser
{
    private array $parentManualConfig;
    /** @var array<string,string> */
    private array $parentManualSectionToField = [];

    public function __construct(string $projectRoot)
    {
        $cfgPath = $projectRoot . '/config/parent-manual-fields.json';
        $cfg = [];
        if (is_file($cfgPath)) {
            $decoded = json_decode((string)file_get_contents($cfgPath), true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
        $this->parentManualConfig = $cfg;
        foreach ($this->extractParentManualFields($cfg) as $field) {
            $name = (string)($field['name'] ?? '');
            $title = trim((string)($field['sectionTitle'] ?? ''));
            if ($name !== '' && $title !== '') {
                $this->parentManualSectionToField[$this->normalizeKey($title)] = $name;
            }
        }
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    public function parse(array $message): array
    {
        $subject = trim((string)($message['subject'] ?? ''));
        $body = $this->normalizeBody((string)($message['body'] ?? ''));
        $messageId = trim((string)($message['id'] ?? ''));
        $emailTs = trim((string)($message['email_ts'] ?? ''));
        $hasAttachment = !empty($message['has_attachment']);

        $formType = $this->detectFormType($subject, $body);
        $sessionId = $this->extractSessionId($body);
        $submittedAt = $this->extractSubmittedAt($body, $emailTs);

        $result = [
            'ok' => false,
            'messageId' => $messageId,
            'subject' => $subject,
            'emailTs' => $emailTs,
            'hasAttachment' => $hasAttachment,
            'formType' => $formType,
            'sessionId' => $sessionId,
            'submittedAt' => $submittedAt,
            'fields' => [],
            'variant' => 'unknown',
            'confidence' => 0.0,
            'notes' => [],
            'body' => $body,
        ];

        if ($formType === 'enrollment') {
            if (preg_match('/^child_[a-z0-9_]+$/mi', $body) === 1) {
                $fields = $this->parseLegacyRawKeyTriples($body);
                $result['fields'] = $fields;
                $result['variant'] = 'enrollment_legacy_raw';
                $result['confidence'] = !empty($fields) ? 0.97 : 0.0;
                $result['ok'] = !empty($fields);
                return $result;
            }

            $fields = $this->parseEnrollmentStructured($body);
            $result['fields'] = $fields;
            $result['variant'] = 'enrollment_structured';
            $result['confidence'] = $this->estimateEnrollmentConfidence($fields);
            $result['ok'] = $result['confidence'] >= 0.55;
            if (!$result['ok']) {
                $result['notes'][] = 'Structured enrollment parsing produced low confidence output.';
            }
            return $result;
        }

        if ($formType === 'waitlist') {
            $current = $this->parseWaitlistCurrent($body);
            if (!empty($current)) {
                $result['fields'] = $current;
                $result['variant'] = 'waitlist_structured';
                $result['confidence'] = $this->estimateWaitlistConfidence($current);
                $result['ok'] = $result['confidence'] >= 0.70;
                return $result;
            }

            $legacy = $this->parseWaitlistLegacy($body);
            $result['fields'] = $legacy;
            $result['variant'] = 'waitlist_legacy';
            $result['confidence'] = $this->estimateWaitlistConfidence($legacy);
            $result['ok'] = $result['confidence'] >= 0.60;
            return $result;
        }

        if ($formType === 'parent_manual') {
            $fields = $this->parseParentManual($body);
            $result['fields'] = $fields;
            $result['variant'] = 'parent_manual_structured';
            $result['confidence'] = $this->estimateParentManualConfidence($fields);
            $result['ok'] = $result['confidence'] >= 0.80;
            return $result;
        }

        $result['notes'][] = 'Unable to detect form type from subject/body.';
        return $result;
    }

    private function detectFormType(string $subject, string $body): string
    {
        $haystack = strtolower($subject . "\n" . $body);
        if (strpos($haystack, 'parent manual') !== false || strpos($haystack, 'handbook agreement') !== false) {
            return 'parent_manual';
        }
        if (strpos($haystack, 'wait list') !== false || strpos($haystack, 'waitlist') !== false) {
            return 'waitlist';
        }
        if (strpos($haystack, 'enrollment') !== false) {
            return 'enrollment';
        }
        return 'unknown';
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return trim($body);
    }

    private function extractSessionId(string $body): string
    {
        if (preg_match('/session\s*ID:\s*([A-Za-z0-9_.:-]+)/i', $body, $m) === 1) {
            return trim($m[1], ". \t\n\r\0\x0B");
        }
        if (preg_match('/Session\s*ID:\s*([A-Za-z0-9_.:-]+)/', $body, $m) === 1) {
            return trim($m[1], ". \t\n\r\0\x0B");
        }
        return '';
    }

    private function extractSubmittedAt(string $body, string $fallback): string
    {
        foreach ([
            '/^Submitted:\s*(.+?)(?:\s*\*\s*session\s*ID:.*)?$/mi',
            '/^Submitted at:\s*(.+)$/mi',
        ] as $pattern) {
            if (preg_match($pattern, $body, $m) === 1) {
                return trim($m[1]);
            }
        }
        return $fallback;
    }

    /**
     * @return array<string,string>
     */
    private function parseLegacyRawKeyTriples(string $body): array
    {
        $blocks = preg_split('/\n\s*\n+/', $body) ?: [];
        $fields = [];
        foreach ($blocks as $block) {
            $lines = preg_split('/\n+/', trim($block)) ?: [];
            if (count($lines) < 3) {
                continue;
            }
            $key = trim((string)$lines[0]);
            if (!preg_match('/^[a-z0-9_]+$/', $key)) {
                continue;
            }
            $label = trim((string)$lines[1]);
            $value = trim(implode("\n", array_slice($lines, 2)));
            $fields[$key] = $this->cleanValue($value);
            if ($label !== '') {
                $fields['_label_' . $key] = $this->cleanValue($label);
            }
        }

        unset($fields['child_name'], $fields['parent1_home_address'], $fields['parent1_work_address'], $fields['parent2_home_address'], $fields['parent2_work_address'], $fields['doctor_address']);

        if (!empty($fields['child_first_name']) || !empty($fields['child_last_name'])) {
            $fields['child_name'] = trim(implode(' ', array_filter([
                $fields['child_first_name'] ?? '',
                $fields['child_middle_name_or_initial'] ?? '',
                $fields['child_last_name'] ?? '',
            ], static fn(string $v): bool => $v !== '')));
        }

        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function parseWaitlistCurrent(string $body): array
    {
        if (strpos($body, 'GRASP Wait List Application') === false && strpos($body, 'Child Information Address') === false) {
            return [];
        }

        $fields = [];
        $sectionA = $this->sectionBetween($body, 'Child Information Address', 'Parents / Guardians');
        if ($sectionA === '') {
            $sectionA = $this->sectionBetween($body, 'Child Information', 'Parents / Guardians');
        }
        $sectionB = $this->sectionBetween($body, 'Parents / Guardians', 'Current Attendance');
        $sectionC = $this->sectionBetween($body, 'Current Attendance', 'Program Interest');
        $sectionD = $this->sectionBetween($body, 'Program Interest', 'Signature');
        $sectionE = $this->sectionBetween($body, 'Signature', 'This email was generated automatically');
        if ($sectionE === '') {
            $sectionE = $this->sectionBetween($body, 'Signature', '');
        }

        $pairsA = $this->extractOrderedValues($sectionA, [
            'Child\'s Name' => 'child_name',
            'Date of Birth' => 'child_birth_date',
            'Date care needed' => 'date_care_needed',
            'Date Applied' => 'date_applied',
            'Gender' => 'gender',
            'Subsidy File #' => 'subsidy_file_number',
            'Address' => 'address',
            'Apt / unit #' => 'address_unit',
            'City' => 'city',
            'Postal Code \(Parent / Guardian 1\)' => 'postal_code',
            'Home Phone #' => 'home_phone',
        ]);
        $fields = array_merge($fields, $pairsA);

        $parent1 = $this->sectionBetween($sectionB, 'Parent / Guardian 1', 'Parent / Guardian 2');
        $parent2 = $this->sectionBetween($sectionB, 'Parent / Guardian 2', '');
        $fields = array_merge($fields, $this->extractOrderedValues($parent1, [
            'Name' => 'parent1_name',
            'Email Address' => 'parent1_email',
            'Work Phone #' => 'parent1_work_phone',
            'Cell Phone #' => 'parent1_cell_phone',
        ]));
        $fields = array_merge($fields, $this->extractOrderedValues($parent2, [
            'Name' => 'parent2_name',
            'Email Address' => 'parent2_email',
            'Work Phone #' => 'parent2_work_phone',
            'Cell Phone #' => 'parent2_cell_phone',
        ]));

        $fields = array_merge($fields, $this->extractOrderedValues($sectionC, [
            'My child attends .*? day care at the current time\.' => 'currently_attends_daycare',
            'My child is attending .*? at the current time\.' => 'currently_attending_school',
            'My child will be attending .*? when we require care at GRASP\.' => 'will_attend_when_require_care',
            'My child:' => 'subsidy_status',
            'My child has a sibling at GRASP' => 'sibling_at_grasp',
            'Sibling name \(if yes\)' => 'sibling_name',
            'Please list any allergies and/or\s*special needs your child may have or need assistance with' => 'allergies_special_needs',
        ]));

        $fields = array_merge($fields, $this->extractOrderedValues($sectionD, [
            'I am only interested in summer camp' => 'interest_summer_only',
            'I am only interested in school year care only' => 'interest_school_year_only',
            'I am interested in both summer camp and school year care' => 'interest_both',
        ]));

        $fields = array_merge($fields, $this->extractOrderedValues($sectionE, [
            'Parent signature \(type in your full name\)' => 'parent_signature',
            'Date' => 'signature_date',
        ]));

        return $this->normalizeWaitlistFields($fields);
    }

    /**
     * @return array<string,string>
     */
    private function parseWaitlistLegacy(string $body): array
    {
        if (strpos($body, 'Child & Family Information') === false) {
            return [];
        }

        $fields = [];
        $fields = array_merge($fields, $this->extractOrderedValues($body, [
            'Child\'s Name \*' => 'child_name',
            'Date of Birth \*' => 'child_birth_date',
            'Date care needed' => 'date_care_needed',
            'Date Applied' => 'date_applied',
            'Circle: Male or Female' => 'gender',
            'Subsidy File #' => 'subsidy_file_number',
            'Address\s*\*' => 'address',
            'Apt / unit #' => 'address_unit',
            'City \*' => 'city',
            'Postal Code \*' => 'postal_code',
            'Home Phone #' => 'home_phone',
            'Parent 1 Name \(Guardian 1\) \*' => 'parent1_name',
            'Parent 2 Name \(Guardian 2\)' => 'parent2_name',
            'Email address \(Parent 1\) \*' => 'parent1_email',
            'Email address\s*\(Parent 2\)' => 'parent2_email',
            'Work Phone # \(Parent 1\)' => 'parent1_work_phone',
            'Work Phone # \(Parent 2\)' => 'parent2_work_phone',
            'Cell phone # \(Parent 1\)' => 'parent1_cell_phone',
            'Cell phone # \(Parent 2\)' => 'parent2_cell_phone',
            'My child attends Day care at the current time' => 'currently_attends_daycare',
            'My child is attending this school at the current time' => 'currently_attending_school',
            'My child will attend when we require care at GRASP' => 'will_attend_when_require_care',
            'My child:' => 'subsidy_status',
            'My child has a sibling at GRASP' => 'sibling_at_grasp',
            'Sibling name \(if yes\)' => 'sibling_name',
            'Please list any allergies and/or special needs your child may have or need assistance with' => 'allergies_special_needs',
            'I am only interested in summer camp' => 'interest_summer_only',
            'I am only interested in school year care only' => 'interest_school_year_only',
            'I am interested in both summer camp and school year care' => 'interest_both',
            'Parent signature \(type in your full name\) \*\s*Type in your full name' => 'parent_signature',
            'Date \*' => 'signature_date',
        ]));

        return $this->normalizeWaitlistFields($fields);
    }

    /**
     * @return array<string,string>
     */
    private function normalizeWaitlistFields(array $fields): array
    {
        $mapped = $fields;
        if (!empty($mapped['date_care_needed'])) {
            $mapped['care_start_date'] = $mapped['date_care_needed'];
        }
        if (!empty($mapped['parent1_cell_phone']) || !empty($mapped['home_phone'])) {
            $mapped['parent1_phones'] = $mapped['parent1_cell_phone'] ?? $mapped['home_phone'];
        }
        if (!empty($mapped['parent2_cell_phone'])) {
            $mapped['parent2_phones'] = $mapped['parent2_cell_phone'];
        }
        if (!empty($mapped['address'])) {
            $mapped['home_street'] = $mapped['address'];
        }
        if (!empty($mapped['address_unit'])) {
            $mapped['home_unit'] = $mapped['address_unit'];
        }
        if (!empty($mapped['city'])) {
            $mapped['home_city'] = $mapped['city'];
        }
        if (!empty($mapped['postal_code'])) {
            $mapped['parent1_postal_code'] = strtoupper($mapped['postal_code']);
        }
        if (!empty($mapped['allergies_special_needs'])) {
            $mapped['special_needs'] = $mapped['allergies_special_needs'];
        }
        if (!empty($mapped['parent_signature'])) {
            $mapped['parent_full_name_signature'] = $mapped['parent_signature'];
        }
        return $mapped;
    }

    /**
     * @return array<string,string>
     */
    private function parseEnrollmentStructured(string $body): array
    {
        $fields = [];

        $childSection = $this->sectionBetween($body, 'Child\'s Primary Information', 'Parent / Guardian Information');
        $flattenedParentSection = '';
        if ($childSection === '') {
            $flattenedParentSection = $this->sectionBetween($body, 'Child\'s Primary Information', 'Doctor and Allergy Information');
            $childSection = $flattenedParentSection;
        }
        $fields = array_merge($fields, $this->extractOrderedValues($childSection, [
            'First\s+Name' => 'child_first_name',
            'Middle Name / Initial' => 'child_middle_name_or_initial',
            'Last Name' => 'child_last_name',
            'Birth Date' => 'child_birth_date',
            'Subsidy File #' => 'subsidy_file_number',
        ]));
        $fields['child_name'] = trim(implode(' ', array_filter([
            $fields['child_first_name'] ?? '',
            $fields['child_middle_name_or_initial'] ?? '',
            $fields['child_last_name'] ?? '',
        ], static fn(string $v): bool => $v !== '')));

        $parentHomeSection = $this->sectionBetween($body, 'Parent / Guardian Information', 'Parent / Guardian Work / School Information');
        if ($parentHomeSection === '' && $flattenedParentSection !== '') {
            $homeMap = $this->parseEnrollmentFlatParentSection($flattenedParentSection);
        } else {
            $homeMap = $this->parseEnrollmentParentHomeSection($parentHomeSection);
        }
        $fields = array_merge($fields, $homeMap);

        $parentWorkSection = $this->sectionBetween($body, 'Parent / Guardian Work / School Information', 'Doctor and Allergy Information');
        if ($parentWorkSection === '' && $flattenedParentSection !== '') {
            $workMap = [];
        } else {
            $workMap = $this->parseEnrollmentParentWorkSection($parentWorkSection, $fields['parent1_email'] ?? '', $fields['parent2_email'] ?? '');
        }
        $fields = array_merge($fields, $workMap);

        $doctorSection = $this->sectionBetween($body, 'Doctor and Allergy Information', 'Emergency & Authorized Pickups');
        $fields = array_merge($fields, $this->extractOrderedValues($doctorSection, [
            'Doctor\'s Name' => 'doctor_name',
            'Doctor\'s Phone #' => 'doctor_phone',
            'Doctor\'s Address' => 'doctor_address',
            'Does your child have any known allergies\?' => 'child_allergies',
            'Symptoms to look for with allergy' => 'allergy_symptoms',
            'Treatment for allergy' => 'allergy_treatment',
            'Epipen Required\?' => 'epipen_required',
        ]));

        $emergencySection = $this->sectionBetween($body, 'Emergency & Authorized Pickups', 'Medical Release & Medication');
        $fields = array_merge($fields, $this->parseEnrollmentEmergencySection($emergencySection));

        $medicalSection = $this->sectionBetween($body, 'Medical Release & Medication', 'General Health');
        $fields = array_merge($fields, $this->extractOrderedValues($medicalSection, [
            'I acknowledge and agree for GRASP staff to secure emergency medical treatment for my child\.' => 'medical_release_consent',
        ]));

        $healthSection = $this->sectionBetween($body, 'General Health', 'Water Play & Hand Sanitizer');
        $fields = array_merge($fields, $this->extractOrderedValues($healthSection, [
            'General health / things to be aware of' => 'general_health_notes',
            'Is your child asthmatic\?' => 'child_asthmatic',
            'Is your child using a puffer\?' => 'child_uses_puffer',
            'Date of last medical examination' => 'last_medical_exam_date',
            'Current weight \(kg\)' => 'current_weight',
            'At the present time is the child free of communicable diseases\?' => 'free_of_disease',
            'Previous history of any communicable diseases' => 'disease_history',
            'Special requirements for diet, rest or exercise' => 'special_requirements',
        ]));

        $waterSection = $this->sectionBetween($body, 'Water Play & Hand Sanitizer', 'Initial Parent/Guardian Interview');
        $fields = array_merge($fields, $this->extractOrderedValues($waterSection, [
            'Authorization for recreational water play \(splash pads, supervised pools, etc\.\)' => 'water_play_consent',
            'Authorization for the use of hand sanitizer \(70.?90% alcohol, as per Toronto Public Health\)' => 'hand_sanitizer_consent',
        ]));

        $interviewSection = $this->sectionBetween($body, 'Initial Parent/Guardian Interview', 'Arrival & Departure Procedure');
        $fields = array_merge($fields, $this->extractOrderedValues($interviewSection, [
            'Birthmarks' => 'birthmarks',
            'Child.?s disposition / temperament' => 'child_disposition',
            'General information about eating habits or food restrictions' => 'eating_habits',
            'Language\(s\) spoken at home' => 'languages_spoken',
            'Is your child talking and comprehending\?' => 'child_talking_comprehending',
            'What method of discipline do you use in your home\?' => 'discipline_method',
            'Does your child have any specific fears\?' => 'child_fears',
            'Reaction to fear & how you handle it' => 'fear_reaction',
            'What frustrates your child & how do you deal with it\?' => 'child_frustrations',
            'Child.?s special needs or cultural interests' => 'child_special_needs',
            'Child.?s interests \(activities, sports, hobbies, etc\.\)' => 'child_interests',
        ]));

        $arrivalSection = $this->sectionBetween($body, 'Arrival & Departure Procedure', 'Information Sharing, Travel & Photo / Media');
        $fields = array_merge($fields, $this->extractOrderedValues($arrivalSection, [
            'I agree to accompany my child to and from GRASP and notify staff verbally upon arrival and departure\.' => 'arrival_departure_ack',
            'Additional notes regarding arrival & departure \(optional\)' => 'arrival_departure_notes',
        ]));
        $fields = array_merge($fields, $this->extractSignatureTriplet($arrivalSection));

        $sharingSection = $this->sectionBetween($body, 'Information Sharing, Travel & Photo / Media', 'Safe Arrival, Dismissal & Sun Safety');
        $fields = array_merge($fields, $this->extractOrderedValues($sharingSection, [
            'I consent to reciprocal exchange of information about my child between GRASP, the school and Toronto Children.?s Services\.' => 'info_sharing_consent',
            'I give consent for my child to leave GRASP premises for local outings with qualified staff\.' => 'travel_consent',
            'I have read, understood, and agree to the above Release\.' => 'photo_media_consent',
        ]));
        $fields = array_merge($fields, $this->extractSignatureTriplet($sharingSection));

        $safeArrivalSection = $this->sectionBetween($body, 'Safe Arrival, Dismissal & Sun Safety', 'Final Acknowledgement & Signature');
        $fields = array_merge($fields, $this->extractOrderedValues($safeArrivalSection, [
            'I acknowledge that my child may not attend childcare for the before-school program on a daily basis and may be dropped off directly at school\.' => 'before_school_program_ack',
            'I acknowledge the Safe Arrival and Dismissal policy and agree to call the childcare by 10am if my child will be absent\.' => 'safe_arrival_ack',
            'Sunscreen Arrangement' => 'sunscreen_provided_by',
            'GRASP may assist my child in the application of sunscreen if necessary\.' => 'sunscreen_assistance_consent',
            'I understand I must send my child with a water bottle and hat each day during July and August\.' => 'sun_safety_ack',
        ]));

        $finalSection = $this->sectionBetween($body, 'Final Acknowledgement & Signature', 'This email was generated automatically');
        $fields = array_merge($fields, $this->extractOrderedValues($finalSection, [
            'Parent/Guardian full name \(serves as digital signature\)' => 'parent_full_name_signature',
            'Witness' => 'witness',
            'Date Signed' => 'signature_date',
            'Additional comments for the Centre \(optional\)' => 'additional_comments',
        ]));

        if (empty($fields['parent1_name']) && !empty($fields['parent_full_name_signature']) && $this->isBlankLike($fields['parent2_email'] ?? '')) {
            $fields['parent1_name'] = $fields['parent_full_name_signature'];
        }

        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function parseEnrollmentFlatParentSection(string $section): array
    {
        $flat = $this->extractOrderedValues($section, [
            'E-mail Address' => 'parent1_email',
            'Home Street Address' => 'parent1_home_street',
            'Apartment / Suite / Unit \(optional\)' => 'parent1_home_unit',
            'Home City' => 'parent1_home_city',
            'Home Province / Territory' => 'parent1_home_province',
            'Cell and home #' => 'parent1_phones',
            'Parent Work/School street address' => 'parent1_work_street',
            'Parent Work/School unit / suite / extra \(optional\)' => 'parent1_work_unit',
            'Parent Work/School city' => 'parent1_work_city',
            'Parent Work/School province / territory' => 'parent1_work_province',
            'Parent Work/School phone #' => 'parent1_work_phone',
            'Parent/Guardian 2 E-mail Address' => 'parent2_email',
            'Parent 2\'s home address same as Parent/Guardian 1' => 'parent2_same_home',
            'Parent/Guardian 2 Home Street Address' => 'parent2_home_street',
            'Parent/Guardian 2 Apartment / Suite / Unit \(optional\)' => 'parent2_home_unit',
            'Parent/Guardian 2 Home City' => 'parent2_home_city',
            'Parent/Guardian 2 Home Province / Territory' => 'parent2_home_province',
            'Parent / Guardian 2 Cell and home #' => 'parent2_phones',
            'Parent / Guardian 2 Work / School Street Address' => 'parent2_work_street',
            'Parent / Guardian 2 Work / School unit / suite / extra \(optional\)' => 'parent2_work_unit',
            'Parent / Guardian 2 Work / School city' => 'parent2_work_city',
            'Parent / Guardian 2 Work / School province / territory' => 'parent2_work_province',
            'Parent / Guardian 2 Work / School phone #' => 'parent2_work_phone',
        ]);

        $fields = [];
        foreach (['parent1_email', 'parent2_email', 'parent1_phones', 'parent2_phones', 'parent1_work_phone', 'parent2_work_phone'] as $key) {
            if (!empty($flat[$key]) && !$this->isBlankLike($flat[$key])) {
                $fields[$key] = $flat[$key];
            }
        }

        $parent1Home = $this->joinAddressParts([
            $flat['parent1_home_street'] ?? '',
            $flat['parent1_home_unit'] ?? '',
            $flat['parent1_home_city'] ?? '',
            $flat['parent1_home_province'] ?? '',
        ]);
        if ($parent1Home !== '') {
            $fields['parent1_home_address'] = $parent1Home;
        }

        $parent1Work = $this->joinAddressParts([
            $flat['parent1_work_street'] ?? '',
            $flat['parent1_work_unit'] ?? '',
            $flat['parent1_work_city'] ?? '',
            $flat['parent1_work_province'] ?? '',
        ]);
        if ($parent1Work !== '') {
            $fields['parent1_work_address'] = $parent1Work;
        }

        $parent2SameHome = strtolower($this->cleanValue($flat['parent2_same_home'] ?? '')) === 'yes';
        $parent2Home = $parent2SameHome
            ? $parent1Home
            : $this->joinAddressParts([
                $flat['parent2_home_street'] ?? '',
                $flat['parent2_home_unit'] ?? '',
                $flat['parent2_home_city'] ?? '',
                $flat['parent2_home_province'] ?? '',
            ]);
        if ($parent2Home !== '' && !$this->isBlankLike($parent2Home)) {
            $fields['parent2_home_address'] = $parent2Home;
        }

        $parent2Work = $this->joinAddressParts([
            $flat['parent2_work_street'] ?? '',
            $flat['parent2_work_unit'] ?? '',
            $flat['parent2_work_city'] ?? '',
            $flat['parent2_work_province'] ?? '',
        ]);
        if ($parent2Work !== '' && !$this->isBlankLike($parent2Work)) {
            $fields['parent2_work_address'] = $parent2Work;
        }

        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function parseEnrollmentParentHomeSection(string $section): array
    {
        $fields = [];
        $text = $this->collapseWhitespace($section);
        $email1 = $this->capture('/E-mail\s+Address\s+([^\s]+@[^\s]+)\s+([^\s]+@[^\s]+)/i', $text, 1);
        $email2 = $this->capture('/E-mail\s+Address\s+([^\s]+@[^\s]+)\s+([^\s]+@[^\s]+)/i', $text, 2);
        if ($email1 !== '') {
            $fields['parent1_email'] = $email1;
        }
        if ($email2 !== '') {
            $fields['parent2_email'] = $email2;
        }

        $nameBlock = $this->capture('/Name\s+(.*?)\s+E-mail\s+Address/s', $text, 1);
        if ($nameBlock !== '') {
            [$name1, $name2] = $this->splitCombinedNamesByEmails($nameBlock, $email1, $email2);
            if ($name1 !== '') {
                $fields['parent1_name'] = $name1;
            }
            if ($name2 !== '') {
                $fields['parent2_name'] = $name2;
            }
        }

        $addressBlock = $this->capture('/Address\s+(.*?)\s+Cell and Home #/s', $text, 1);
        if ($addressBlock !== '') {
            [$addr1, $addr2] = $this->splitAddresses($addressBlock);
            if ($addr1 !== '') {
                $fields['parent1_home_address'] = $addr1;
            }
            if ($addr2 !== '') {
                $fields['parent2_home_address'] = $addr2;
            }
        }

        if (preg_match('/Cell and Home #\s+(.+?)\s+(.+)$/s', $text, $m) === 1) {
            $fields['parent1_phones'] = $this->cleanValue($m[1]);
            $fields['parent2_phones'] = $this->cleanValue($m[2]);
        }

        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function parseEnrollmentParentWorkSection(string $section, string $email1, string $email2): array
    {
        $fields = [];
        $text = $this->collapseWhitespace($section);
        $addressBlock = $this->capture('/Street Address\s+(.*?)\s+Parent Work\/School phone #/s', $text, 1);
        if ($addressBlock !== '') {
            [$addr1, $addr2] = $this->splitAddresses($addressBlock);
            if ($addr1 !== '') {
                $fields['parent1_work_address'] = $addr1;
            }
            if ($addr2 !== '') {
                $fields['parent2_work_address'] = $addr2;
            }
        }
        if (preg_match('/Parent Work\/School phone #\s+(.+?)\s+(.+)$/s', $text, $m) === 1) {
            $fields['parent1_work_phone'] = $this->cleanValue($m[1]);
            $fields['parent2_work_phone'] = $this->cleanValue($m[2]);
        }
        if (!empty($fields['parent1_name']) || !empty($fields['parent2_name'])) {
            return $fields;
        }
        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function parseEnrollmentEmergencySection(string $section): array
    {
        $fields = [];
        $text = $this->collapseWhitespace($section);
        if (preg_match('/Centre will first attempt to call parents\/guardians and then emergency contact only if we can not reach parents\/guardians\.\s+(.*?)\s+Contact Name Relationship To Child Day Time Phone #/s', $text, $m) === 1) {
            $joined = trim($m[1]);
            if (preg_match('/^(.*?)\s+([^\s]+)\s+(\([^)]*\)|[0-9\-\s]+)$/', $joined, $cm) === 1) {
                $fields['emergency_contact_name'] = $this->cleanValue($cm[1]);
                $fields['emergency_contact_relationship'] = $this->cleanValue($cm[2]);
                $fields['emergency_contact_day_phone'] = $this->cleanValue($cm[3]);
                if (str_contains(strtolower($fields['emergency_contact_name']), ' grandmother') || str_contains(strtolower($fields['emergency_contact_name']), ' grandfather')) {
                    $parts = preg_split('/\s+/', $fields['emergency_contact_name']) ?: [];
                    $fields['emergency_contact_relationship'] = (string)array_pop($parts);
                    $fields['emergency_contact_name'] = trim(implode(' ', $parts));
                }
            }
        }
        $fields = array_merge($fields, $this->extractOrderedValues($section, [
            'Day time Address \(incl\. postal code\)' => 'emergency_contact_address',
            'Other Authorized Pickups' => 'authorized_pickups',
        ]));
        $fields = array_merge($fields, $this->extractSignatureTriplet($section));
        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function extractSignatureTriplet(string $section): array
    {
        $text = $this->collapseWhitespace($section);
        $fields = [];
        if (preg_match('/([A-Za-z][A-Za-z\-\' ]+?)\s+([A-Za-z]+\s+\d{1,2},\s+\d{4}|\d{4}-\d{2}-\d{2})\s+([A-Za-z][A-Za-z\-\' ]+?)\s+Parent \/ Guardian Signature\s+(?:Date Signed|Date)\s+Witness/i', $text, $m) === 1) {
            $fields['parent_full_name_signature'] = $this->cleanValue($m[1]);
            $fields['signature_date'] = $this->cleanValue($m[2]);
            $fields['witness'] = $this->cleanValue($m[3]);
            return $fields;
        }
        if (preg_match('/([A-Za-z][A-Za-z\-\' ]+?)\s+([A-Za-z][A-Za-z\-\' ]+?)\s+([A-Za-z]+\s+\d{1,2},\s+\d{4}|\d{4}-\d{2}-\d{2})\s+Parent \/ Guardian Signature\s+Witness\s+Date Signed/i', $text, $m) === 1) {
            $fields['parent_full_name_signature'] = $this->cleanValue($m[1]);
            $fields['witness'] = $this->cleanValue($m[2]);
            $fields['signature_date'] = $this->cleanValue($m[3]);
        }
        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function parseParentManual(string $body): array
    {
        $fields = [];
        $blocks = preg_split('/\n\s*\n+/', $body) ?: [];
        foreach ($blocks as $block) {
            $lines = preg_split('/\n+/', trim($block)) ?: [];
            if (count($lines) < 2) {
                continue;
            }
            $current = trim((string)$lines[0]);
            $next = trim(implode("\n", array_slice($lines, 1)));
            $normalized = $this->normalizeKey($current);
            if (isset($this->parentManualSectionToField[$normalized])) {
                $fields[$this->parentManualSectionToField[$normalized]] = $this->cleanValue($next);
            }
        }

        $fields = array_merge($fields, $this->extractOrderedValues($body, [
            'Printed Name \(Acknowledgement line\)' => 'pm_ack_printed_name',
            'Signature of Parent/Legal Guardian' => 'pm_parent_signature',
            'Printed Name \(Signature block\)' => 'pm_parent_printed_name',
            'Date' => 'pm_parent_date',
            'Signature of Executive Director \(office use only\)' => 'pm_exec_signature',
            'Date signed by Executive Director \(office use only\)' => 'pm_exec_date',
        ]));

        return $fields;
    }

    /**
     * @param array<string,string> $labelMap
     * @return array<string,string>
     */
    private function extractOrderedValues(string $text, array $labelMap): array
    {
        $normalized = $this->collapseWhitespace($text);
        if ($normalized === '') {
            return [];
        }

        $patterns = array_keys($labelMap);
        $result = [];
        $count = count($patterns);
        for ($i = 0; $i < $count; $i++) {
            $pattern = $patterns[$i];
            $fieldName = $labelMap[$pattern];
            $nextAlternatives = [];
            for ($j = $i + 1; $j < $count; $j++) {
                $nextAlternatives[] = $patterns[$j];
            }
            $lookahead = $nextAlternatives === []
                ? '$'
                : '(?=' . implode('|', array_map(static fn(string $item): string => '(?:' . $item . ')', $nextAlternatives)) . '|$)';
            $regex = '~(?:' . $pattern . ')\s*(.*?)\s*' . $lookahead . '~si';
            if (preg_match($regex, $normalized, $m) === 1) {
                $result[$fieldName] = $this->cleanValue($m[1]);
            }
        }
        return $result;
    }

    private function sectionBetween(string $text, string $start, string $end): string
    {
        $quotedStart = preg_quote($start, '/');
        if ($end === '') {
            if (preg_match('/' . $quotedStart . '\s*(.*)$/si', $text, $m) === 1) {
                return trim($m[1]);
            }
            return '';
        }

        $quotedEnd = preg_quote($end, '/');
        if (preg_match('/' . $quotedStart . '\s*(.*?)\s*' . $quotedEnd . '/si', $text, $m) === 1) {
            return trim($m[1]);
        }
        return '';
    }

    private function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string)$text);
    }

    private function cleanValue(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        $value = preg_replace('/\n{3,}/', "\n\n", $value);
        $value = preg_replace('/\s+/', ' ', (string)$value);
        $value = trim((string)$value);
        if ($value === '—' || strcasecmp($value, 'n/a') === 0 || strcasecmp($value, 'none') === 0) {
            return $value;
        }
        return $value;
    }

    /**
     * @param array<int,string> $parts
     */
    private function joinAddressParts(array $parts): string
    {
        $filtered = [];
        foreach ($parts as $part) {
            $clean = $this->cleanValue($part);
            if ($clean !== '' && !$this->isBlankLike($clean)) {
                $filtered[] = $clean;
            }
        }

        return implode(', ', $filtered);
    }

    private function isBlankLike(string $value): bool
    {
        $normalized = strtolower($this->cleanValue($value));
        return $normalized === ''
            || $normalized === 'n/a'
            || $normalized === 'n/a (not available)'
            || $normalized === 'not available'
            || $normalized === 'no information entered';
    }

    private function capture(string $pattern, string $text, int $group): string
    {
        if (preg_match($pattern, $text, $m) === 1 && isset($m[$group])) {
            return $this->cleanValue($m[$group]);
        }
        return '';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitAddresses(string $combined): array
    {
        $pattern = '/^(.+?[A-Z]\d[A-Z]\s?\d[A-Z]\d)\s+(.+)$/';
        $collapsed = $this->collapseWhitespace($combined);
        if (preg_match($pattern, $collapsed, $m) === 1) {
            return [$this->cleanValue($m[1]), $this->cleanValue($m[2])];
        }
        return [$this->cleanValue($collapsed), ''];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitCombinedNamesByEmails(string $combinedNames, string $email1, string $email2): array
    {
        $tokens = preg_split('/\s+/', trim($combinedNames)) ?: [];
        $tokenCount = count($tokens);
        if ($tokenCount < 2) {
            return [$this->cleanValue($combinedNames), ''];
        }

        $email1Norm = $this->normalizeKey(strtok($email1, '@') ?: '');
        $email2Norm = $this->normalizeKey(strtok($email2, '@') ?: '');
        $bestScore = -INF;
        $bestSplit = (int)ceil($tokenCount / 2);

        for ($i = 1; $i < $tokenCount; $i++) {
            $left = implode(' ', array_slice($tokens, 0, $i));
            $right = implode(' ', array_slice($tokens, $i));
            $score = 0.0;

            foreach (preg_split('/\s+/', strtolower($left)) ?: [] as $token) {
                if ($token !== '' && strpos($email1Norm, $this->normalizeKey($token)) !== false) {
                    $score += 3.0;
                }
            }
            foreach (preg_split('/\s+/', strtolower($right)) ?: [] as $token) {
                if ($token !== '' && strpos($email2Norm, $this->normalizeKey($token)) !== false) {
                    $score += 3.0;
                }
            }

            $balancePenalty = abs(($tokenCount / 2) - $i) * 0.25;
            $score -= $balancePenalty;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSplit = $i;
            }
        }

        return [
            $this->cleanValue(implode(' ', array_slice($tokens, 0, $bestSplit))),
            $this->cleanValue(implode(' ', array_slice($tokens, $bestSplit))),
        ];
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value);
        return (string)$value;
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array<int,array<string,mixed>>
     */
    private function extractParentManualFields(array $cfg): array
    {
        $out = [];
        foreach ((array)($cfg['steps'] ?? []) as $step) {
            foreach ((array)($step['groups'] ?? []) as $group) {
                foreach ((array)($group['fields'] ?? []) as $field) {
                    if (is_array($field)) {
                        $out[] = $field;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,string> $fields
     */
    private function estimateEnrollmentConfidence(array $fields): float
    {
        $score = 0.0;
        foreach (['child_first_name', 'child_last_name', 'child_birth_date', 'doctor_name', 'parent_full_name_signature'] as $key) {
            if (!empty($fields[$key])) {
                $score += 0.12;
            }
        }
        foreach (['parent1_email', 'parent2_email', 'emergency_contact_name', 'medical_release_consent', 'water_play_consent'] as $key) {
            if (!empty($fields[$key])) {
                $score += 0.08;
            }
        }
        return min(1.0, $score);
    }

    /**
     * @param array<string,string> $fields
     */
    private function estimateWaitlistConfidence(array $fields): float
    {
        $score = 0.0;
        foreach (['child_name', 'child_birth_date', 'parent1_name', 'parent1_email', 'parent_signature'] as $key) {
            if (!empty($fields[$key])) {
                $score += 0.18;
            }
        }
        return min(1.0, $score);
    }

    /**
     * @param array<string,string> $fields
     */
    private function estimateParentManualConfidence(array $fields): float
    {
        $required = count($this->parentManualSectionToField);
        $present = 0;
        foreach ($this->parentManualSectionToField as $fieldName) {
            if (!empty($fields[$fieldName])) {
                $present++;
            }
        }
        $score = $required > 0 ? ($present / $required) * 0.8 : 0.0;
        foreach (['pm_ack_printed_name', 'pm_parent_printed_name', 'pm_parent_date'] as $key) {
            if (!empty($fields[$key])) {
                $score += 0.066;
            }
        }
        return min(1.0, $score);
    }
}

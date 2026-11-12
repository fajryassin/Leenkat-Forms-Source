<?php
declare(strict_types=1);
namespace LeenkatForms\Submissions;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Security\SubmissionPolicy;
use LeenkatForms\Security\RateLimiter;
use LeenkatForms\Security\MathCaptcha;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/** Public write-only transport. All values are checked against the published snapshot. */
final class PublicEndpoint {
    public static function hooks(): void {
        NativeSubmission::hooks();
        add_action('rest_api_init', static function (): void {
            register_rest_route('leenkat-forms/v1', '/forms/(?P<id>\d+)/submit', [
                'methods'=>'POST', 'callback'=>[self::class,'submit'],
                'permission_callback'=>'__return_true',
                'args'=>['id'=>['required'=>true,'validate_callback'=>static fn($value): bool => absint($value) > 0]],
            ]);
        });
    }

    public static function submit(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $contentType = (string)$request->get_header('content-type');
        $multipart = (bool)preg_match('/^multipart\/form-data(?:\s*;|\s*$)/i', $contentType);
        if ($multipart) {
            $payload = $request->get_param('payload');
            if (!is_string($payload) || strlen($payload) > SubmissionPolicy::MAX_REQUEST_BYTES || (int)$request->get_header('content-length') > min(wp_max_upload_size(), 50 * MB_IN_BYTES) + SubmissionPolicy::MAX_REQUEST_BYTES) { return self::invalid(); }
            $data = json_decode($payload, true);
        } else {
            if (!preg_match('/^\s*application\/json(?:\s*;|\s*$)/i', $contentType)) { return new WP_Error('lkf_content_type', __('JSON or multipart form data is required.', 'leenkat-forms'), ['status'=>415]); }
            if ((int)$request->get_header('content-length') > SubmissionPolicy::MAX_REQUEST_BYTES || strlen($request->get_body()) > SubmissionPolicy::MAX_REQUEST_BYTES) { return new WP_Error('lkf_too_large', __('Request is too large.', 'leenkat-forms'), ['status'=>413]); }
            $data = $request->get_json_params();
        }
        if (!is_array($data)) { return self::invalid(); }
        $formId = absint($request['id']);
        $published = Publisher::published($formId);
        if (!$published) { return new WP_Error('lkf_unavailable', __('Form is unavailable.', 'leenkat-forms'), ['status'=>404]); }
        if (!RateLimiter::allow($formId, \LeenkatForms\Security\CountryLookup::clientIp($_SERVER))) {
            return new WP_Error('lkf_rate_limit', __('Please wait before submitting again.', 'leenkat-forms'), ['status'=>429]);
        }
        $security=\LeenkatForms\Forms\SecuritySettings::normalize($published['definition']);
        $legacyTrap=!empty($data['website_confirmation']);
        if($legacyTrap&&!isset($published['definition']['settings']['security']))return self::success();
        if(isset($data['website_confirmation'])&&(!is_string($data['website_confirmation'])||strlen($data['website_confirmation'])>1000))return self::invalid();
        $proof=\LeenkatForms\Security\SecurityChallenge::check($security,$data,$formId,$published['version_id']);if(is_wp_error($proof))return $proof;
        $country=\LeenkatForms\Security\SpamProtection::countryCheck($security,$_SERVER);if(is_wp_error($country))return $country;
        $submissionUuid = $data['submission_uuid'] ?? null;
        if (!is_string($submissionUuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $submissionUuid)) { return self::invalid(); }
        $raw = $data['fields'] ?? null;
        if (!is_array($raw) || array_is_list($raw) && $raw !== [] || count($raw) > SubmissionPolicy::MAX_FIELDS) { return self::invalid(); }
        $captchaTokens = $data['captcha_tokens'] ?? [];
        $confirmations = $data['email_confirmations'] ?? [];
        if (!is_array($confirmations) || count($confirmations) > SubmissionPolicy::MAX_FIELDS) { return self::invalid(); }
        if (!is_array($captchaTokens) || array_is_list($captchaTokens) && $captchaTokens !== []) { return self::invalid(); }
        $incomingFiles = $request->get_file_params();
        if (!is_array($incomingFiles) || count($incomingFiles) > 10) { return self::invalid(); }
        $uploadKeys = []; $pendingUploads = []; $uploadBytes = 0; $uploadCount = 0;
        $known = [];
        $values = [];
        $comparisonValues = [];
        $fieldParts = [];
        $fieldChoices = [];
        $fieldErrors=[];$fieldErrorCode='lkf_invalid';$fieldErrorMessage=__('Please check the form values.','leenkat-forms');
        $rules = $published['definition']['rules'] ?? [];
        foreach ($published['definition']['fields'] as $field) {
            $id = $field['id'];
            $known[$id] = true;
            if ($field['type'] === 'file_upload') { $uploadKeys[] = 'upload_' . $id; }
            if (!\LeenkatForms\Forms\Rules::visible($id, $rules, $comparisonValues,$published['definition']['fields'])) { $values[$id] = null; continue; }
            try {
            $messages=\LeenkatForms\Frontend\FieldErrors::messages();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
            $reject=static function(?string $message=null,string $part='')use($messages):never{throw new FieldValidationException([$part=>$message??$messages['invalid']]);};
            $value = $raw[$id] ?? null;
            $required = !empty($field['required']);
            if (in_array($field['type'],['section_divider','page_break'],true)) { if ($value !== null) { $reject(); } continue; }
            if (in_array($field['type'], ['datetime','address'], true)) {
                if ($value !== null && (!is_array($value) || array_is_list($value))) { $reject(); }
                $settings = $field['settings'] ?? [];
                $parts = $field['type'] === 'datetime' ? \LeenkatForms\Forms\DateTimeField::parts($settings) : \LeenkatForms\Forms\AddressField::parts($settings);
                if (array_diff(array_keys($value ?: []), $parts)) { $reject(); }
                $normalized = [];$partErrors=[];
                foreach ($parts as $part) {
                    $partValue = $value[$part] ?? '';
                    if (!is_string($partValue) || strlen($partValue) > 250) { $partErrors[$part]=$messages['invalid'];continue; }
                    $partValue = sanitize_text_field(trim($partValue));
                    if ($required && $part !== 'address2' && $partValue === '') { $partErrors[$part]=$messages['required'];continue; }
                    if ($field['type'] === 'datetime') { if (!\LeenkatForms\Forms\DateTimeField::valid($part, $partValue, $settings)) { $partErrors[$part]=$messages[$part==='date'?'date':'invalid'];continue; } }
                    else { $options = \LeenkatForms\Forms\AddressField::options($part, $settings); if ($partValue !== '' && $options && !isset($options[$partValue])) { $partErrors[$part]=$messages['invalid'];continue; } }
                    if ($partValue !== '') { $normalized[] = $partValue; }
                }
                if($partErrors)throw new FieldValidationException($partErrors);
                $value = implode($field['type'] === 'datetime' ? ' ' : ', ', $normalized);
            } elseif ($field['type'] === 'file_upload') {
                if ($value !== null) { $reject(); }
                $files = FileUploads::prepare($incomingFiles['upload_' . $id] ?? null, $field);
                if (is_wp_error($files)) { throw new FieldValidationException([''=>$files->get_error_message()],$files); }
                $pendingUploads[$id] = ['files'=>$files,'media_library'=>!empty($field['settings']['media_library'])];
                $uploadBytes += array_sum(array_column($files, 'size')); $uploadCount += count($files);
                if ($uploadCount > 10 || $uploadBytes > min(wp_max_upload_size(), 50 * MB_IN_BYTES)) { $reject(); }
                $value = implode(' ', array_map(static fn(array $file): string => $file['name'] . ':' . $file['size'] . ':' . $file['sha256'], $files));
            } elseif ($field['type'] === 'captcha') {
                if (!MathCaptcha::verify($formId, $id, $value, $captchaTokens[$id] ?? null)) { $reject(); }
                $value = __('Passed', 'leenkat-forms');
            } elseif ($field['type'] === 'name') {
                if ($value !== null && (!is_array($value) || array_is_list($value))) { $reject(); }
                $parts = \LeenkatForms\Forms\NameField::parts($field['settings'] ?? []);
                if (array_diff(array_keys($value ?: []), $parts)) { $reject(); }
                $names = [];$partErrors=[];
                foreach ($parts as $part) {
                    $partValue = $value[$part] ?? '';
                    if (!is_string($partValue) || strlen($partValue) > 250) { $partErrors[$part]=$messages['invalid'];continue; }
                    $partValue = sanitize_text_field(trim($partValue));
                    if ($required && $part !== 'middle' && $partValue === '') { $partErrors[$part]=$messages['required'];continue; }
                    $fieldParts[$id][$part] = $partValue;
                    if ($partValue !== '') { $names[] = $partValue; }
                }
                if($partErrors)throw new FieldValidationException($partErrors);
                $value = implode(' ', $names);
            } elseif ($field['type'] === 'select' && !empty($field['settings']['multiple'])) {
                $allowed = array_column(\LeenkatForms\Forms\ChoiceField::items($field['settings']), 'value');
                if ($value === null) { $value = []; }
                if (!is_array($value) || !array_is_list($value) || count($value) > 100 || ($required && !$value)) { $reject(); }
                foreach ($value as $choice) { if (!is_string($choice) || !in_array($choice, $allowed, true)) { $reject(); } }
                if (count(array_unique($value)) !== count($value)) { $reject(); }
            } elseif ($field['type'] === 'checkboxes') {
                $allowed = array_column(\LeenkatForms\Forms\ChoiceField::items($field['settings']), 'value');
                if ($value !== null && (!is_array($value) || !array_is_list($value) || count($value) > count($allowed))) { $reject(); }
                $choices = $value ?? [];
                if ($required && !$choices) { $reject($messages['choices']); }
                if (!empty($field['settings']['choice_limit']) && count($choices) > $field['settings']['choice_limit']) { $reject(); }
                foreach ($choices as $choice) { if (!is_string($choice) || !in_array($choice, $allowed, true)) { $reject(); } }
                if (count(array_unique($choices)) !== count($choices)) { $reject(); }
                $fieldChoices[$id] = $choices;
                $value = implode(', ', $choices);
                if (strlen($value) > 5000) { $reject(); }
            } elseif (in_array($field['type'], ['checkbox','consent'], true)) {
                if ($value !== null && !in_array($value, ['1', 1, true], true)) { $reject(); }
                $value = $value === null ? false : true;
                if ($required && !$value) { $reject($messages['checkbox']); }
            } else {
                if ($value !== null && !is_string($value)) { $reject(); }
                $value = in_array($field['type'],['select','radio'],true) ? (string)$value : \LeenkatForms\Forms\TextLength::normalize((string)$value);
                if ($required && $value === '') {
                    if($field['type']==='email'&&!empty($field['settings']['email_confirmation']))throw new FieldValidationException(['email'=>$messages['required'],'confirm'=>$messages[is_string($confirmations[$id]??null)&&trim($confirmations[$id])!==''?'email_match':'required']]);
                    $reject($messages[in_array($field['type'],['select','radio','rating'],true)?'choice':'required']);
                }
                $limit = match ($field['type']) {
                    'email' => 254, 'phone' => 40, 'number', 'number_slider' => 100,
                    'url' => 2048, 'select', 'radio', 'rating', 'date', 'time' => 200,
                    'textarea' => 5000, default => 1000,
                };
                if (in_array($field['type'],['text','textarea'],true) ? !\LeenkatForms\Forms\TextLength::valid($value,$field['settings'] ?? [],$limit) : strlen($value) > $limit) { $reject(in_array($field['type'],['text','textarea'],true)?\LeenkatForms\Frontend\FieldErrors::lengthMessage($value,$field['settings']??[],$limit):$messages['invalid']); }
                if (in_array($field['type'], ['text','textarea','phone'], true)) { $value = sanitize_textarea_field($value); }
                $settings = $field['settings'] ?? [];
                if (in_array($field['type'], ['text','textarea'], true) && !empty($settings['limit_length_enabled']) && \LeenkatForms\Forms\TextLength::count($value, (string)($settings['length_unit'] ?? 'characters')) > (int)($settings['max_length'] ?? 100)) { $reject(); }
                if ($field['type'] === 'text' && $value !== '' && !empty($settings['input_mask']) && !\LeenkatForms\Forms\InputMask::matches((string)$settings['input_mask'], $value)) { $reject($messages['pattern']); }
                if (in_array($field['type'], ['text','textarea','email','phone','url','number'], true) && !empty($settings['read_only']) && $value !== trim((string)($settings['default_value'] ?? ''))) { $reject($messages['read_only']); }
                if ($field['type'] === 'email' && $value !== '' && !is_email($value)) {
                    if(!empty($settings['email_confirmation'])){
                        $emailErrors=['email'=>$messages['email']];$confirmed=$confirmations[$id]??'';
                        if(!is_string($confirmed)||trim($confirmed)!==$value)$emailErrors['confirm']=$messages[is_string($confirmed)&&trim($confirmed)===''?'required':'email_match'];
                        throw new FieldValidationException($emailErrors);
                    }
                    $reject($messages['email']);
                }
                if ($field['type'] === 'email') {
                    if (!\LeenkatForms\Forms\EmailField::allowed($value, $settings)) { $reject($messages['email_filter']); }
                    if (!empty($settings['email_confirmation']) && (!is_string($confirmations[$id] ?? '') || trim($confirmations[$id] ?? '') !== $value)) { $reject($messages['email_match'],'confirm'); }
                }
                if (in_array($field['type'], ['number', 'number_slider'], true) && $value !== '' && (!is_numeric($value) || !is_finite((float)$value))) { $reject(); }
                if ($field['type'] === 'number_slider' && $value !== '') {
                    if (!\LeenkatForms\Forms\NumberSlider::validValue($value, $field['settings'])) { $reject(); }
                }
                if ($field['type'] === 'phone' && !\LeenkatForms\Forms\PhoneField::valid($value, $settings)) { $reject($messages['phone']); }
                if ($field['type'] === 'url' && $value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower((string)wp_parse_url($value, PHP_URL_SCHEME)), ['http','https'], true))) { $reject(); }
                if ($field['type'] === 'select' && $value !== '' && !in_array($value, array_column(\LeenkatForms\Forms\ChoiceField::items($settings), 'value'), true)) { $reject(); }
                if ($field['type'] === 'radio' && $value !== '' && !in_array($value, array_column(\LeenkatForms\Forms\ChoiceField::items($settings), 'value'), true)) { $reject(); }
                if ($field['type'] === 'date' && $value !== '') {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) { $reject(); }
                }
                if ($field['type'] === 'time' && $value !== '' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) { $reject(); }
                if ($field['type'] === 'rating' && $value !== '' && (!ctype_digit($value) || (int)$value < 1 || (int)$value > (int)($field['settings']['max'] ?? 5))) { $reject(); }
            }
            $values[$id] = $value;
            if(\LeenkatForms\Forms\Rules::sourceAllowed($field))$comparisonValues[$id]=\LeenkatForms\Forms\Rules::normalizeSource($field,$raw[$id] ?? null);
            }catch(FieldValidationException $failure){
                if(!$fieldErrors&&$failure->original){$fieldErrorCode=$failure->original->get_error_code();$fieldErrorMessage=$failure->original->get_error_message();}
                $fieldErrors[$id]=$failure->errors;
            }
        }
        if (array_diff(array_keys($incomingFiles), $uploadKeys) || array_diff(array_keys($raw), array_keys($known))) { return self::invalid(); }
        if($fieldErrors)return new WP_Error($fieldErrorCode,$fieldErrorMessage,['status'=>400,'field_errors'=>$fieldErrors]);
        $matchedKeywords=[];
        $spamReason=$legacyTrap?'Honeypot':($proof['trap']?'Modern anti-spam':($country===false?'Country Filter':\LeenkatForms\Security\SpamProtection::reason($published['definition'],$values,$matchedKeywords)));
        if($spamReason&&(!$security['store_spam']||!empty($published['definition']['settings']['general']['disable_entries']))){
            return ($legacyTrap||$proof['trap'])?self::success():\LeenkatForms\Security\SpamProtection::error($security,$spamReason,$matchedKeywords);
        }
        // Retain exact selections in the retry digest; display text can contain commas.
        $requestValues=$fieldChoices?['values'=>$values,'field_choices'=>$fieldChoices]:$values;
        $captchaCheck=\LeenkatForms\Security\CaptchaService::verifyForm($published['definition'],$formId,$published['version_id'],$submissionUuid,hash('sha256',wp_json_encode($requestValues)),$data['service_captcha_token'] ?? null);
        if(is_wp_error($captchaCheck))return $captchaCheck;
        if(!$spamReason&&!empty($security['akismet_enabled'])){
            $verdict=\LeenkatForms\Security\AkismetService::classify($published['definition'],$values,$formId,$published['version_id'],$submissionUuid,hash('sha256',json_encode($requestValues,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),$_SERVER);
            if(is_wp_error($verdict))return $verdict;
            if($verdict){$spamReason='Akismet';if(!$security['store_spam']||!empty($published['definition']['settings']['general']['disable_entries']))return \LeenkatForms\Security\SpamProtection::error($security,$spamReason);}
        }
        $entries = $wpdb->prefix . 'lkf_entries';
        if (!empty($published['definition']['settings']['general']['disable_entries'])) {
            try { $hash=hash_hmac('sha256',json_encode($requestValues,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),wp_salt('auth')); }
            catch (\JsonException $error) { return self::invalid(); }
            $displayValues=$values;foreach($pendingUploads as $fid=>$upload)$displayValues[$fid]=implode(', ',array_column($upload['files'],'name'));
            return UnstoredSubmission::process($formId,$submissionUuid,$hash,$published,$values,$pendingUploads,$fieldParts,$fieldChoices)?self::success($published['definition'],$displayValues,['form_id'=>$formId,'field_parts'=>$fieldParts,'field_choices'=>$fieldChoices]):self::failure();
        }
        $projections = $wpdb->prefix . 'lkf_entry_values';
        if (!\LeenkatForms\Infrastructure\Database\Transactions::supported([$wpdb->prefix . 'lkf_forms',$entries,$projections,$wpdb->prefix . 'lkf_deliveries',$wpdb->prefix . 'lkf_entry_files'])) { return self::failure(); }
        try { $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException $error) { return self::invalid(); }
        $hash = hash('sha256', json_encode($requestValues,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id,form_id,form_version_id,payload,meta,submitted_at_gmt FROM `{$entries}` WHERE form_id = %d AND submission_uuid = %s", $formId, $submissionUuid));
        if ($existing) {
            $meta = json_decode((string)$existing->meta, true);
            return is_array($meta) && hash_equals((string)($meta['request_hash'] ?? ''), $hash) ? self::storedSuccess($existing,$published['definition']) : self::invalid();
        }
        $createdFiles = []; $committed = false;
        try {
            foreach ($pendingUploads as $fieldId=>$upload) {
                if($spamReason){$values[$fieldId]=implode(', ',array_column($upload['files'],'name'));continue;}
                $before = count($createdFiles);
                $stored = FileUploads::store($upload['files'], $upload['media_library'], $createdFiles);
                if (is_wp_error($stored)) {
                    // Storage failures retain their safe message/status and field identity.
                    $errorData=$stored->get_error_data();
                    $stored->add_data((is_array($errorData)?$errorData:[])+['field_errors'=>[$fieldId=>[''=>$stored->get_error_message()]]]);
                    return $stored;
                }
                for ($i=$before; $i<count($createdFiles); $i++) { $createdFiles[$i]['field_id'] = $fieldId; }
                $values[$fieldId] = $stored;
            }
            $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if ($wpdb->query('START TRANSACTION') === false) { return self::failure(); }
            $forms = $wpdb->prefix . 'lkf_forms';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $lockedForm = $wpdb->get_row($wpdb->prepare("SELECT status,published_version_id FROM `{$forms}` WHERE id=%d FOR UPDATE", $formId));
            if (!$lockedForm || $lockedForm->status !== 'published' || (int)$lockedForm->published_version_id !== $published['version_id']) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                $wpdb->query('ROLLBACK');
                return new WP_Error('lkf_changed', __('Form changed. Please reload and try again.', 'leenkat-forms'), ['status'=>409]);
            }
            $now = current_time('mysql', true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
            $ok = $wpdb->insert($entries, [
                'uuid'=>wp_generate_uuid4(), 'form_id'=>$formId, 'form_version_id'=>$published['version_id'],
                'status'=>$spamReason?'spam':'complete', 'submission_uuid'=>$submissionUuid, 'user_id'=>get_current_user_id() ?: null,
                'payload'=>$json, 'meta'=>wp_json_encode(['request_hash'=>$hash,'uploads'=>$createdFiles,'field_parts'=>$fieldParts,'field_choices'=>$fieldChoices]+($spamReason?['spam_reason'=>$spamReason]:[])+\LeenkatForms\Forms\GeneralSettings::details($published['definition']['settings']['general'] ?? [],$_SERVER)),
                'submitted_at_gmt'=>$now, 'updated_at_gmt'=>$now,
            ], ['%s','%d','%d','%s','%s','%s','%s','%s','%s','%s']);
            if (!$ok) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                $wpdb->query('ROLLBACK');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                $duplicate = $wpdb->get_row($wpdb->prepare("SELECT id,form_id,form_version_id,payload,meta,submitted_at_gmt FROM `{$entries}` WHERE form_id = %d AND submission_uuid = %s", $formId, $submissionUuid));
                $meta = $duplicate ? json_decode((string)$duplicate->meta, true) : null;
                return is_array($meta) && hash_equals((string)($meta['request_hash'] ?? ''), $hash) ? self::storedSuccess($duplicate,$published['definition']) : self::failure();
            }
            $entryId = (int)$wpdb->insert_id;
            if(!\LeenkatForms\Files\FileRecords::register($entryId,$createdFiles))return self::failure();
            $registeredMeta=['request_hash'=>$hash,'uploads'=>$createdFiles,'field_parts'=>$fieldParts,'field_choices'=>$fieldChoices]+($spamReason?['spam_reason'=>$spamReason]:[])+\LeenkatForms\Forms\GeneralSettings::details($published['definition']['settings']['general'] ?? [],$_SERVER);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if($wpdb->update($entries,['meta'=>wp_json_encode($registeredMeta)],['id'=>$entryId])===false)return self::failure();
            foreach ($published['definition']['fields'] as $field) {
                if($spamReason)continue;
                if (empty($field['searchable']) || $values[$field['id']] === '' || $values[$field['id']] === false || $values[$field['id']] === null) { continue; }
                $value = is_array($values[$field['id']]) ? implode(', ', $values[$field['id']]) : (string)$values[$field['id']];
                $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
                $row = ['entry_id'=>$entryId,'form_id'=>$formId,'field_id'=>$field['id'],'row_index'=>0,'value_text'=>implode('', array_slice($characters ?: [],0,191))];
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
                if (!$wpdb->insert($projections, $row, ['%d','%d','%s','%d','%s'])) { $wpdb->query('ROLLBACK'); return self::failure(); }
            }
            $deliveryIds = $spamReason?[]:\LeenkatForms\Workflow\Notifications::createOutbox($entryId, $published['definition'], array_replace($values,$fieldChoices), $now);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if ($deliveryIds === false) { $wpdb->query('ROLLBACK'); return self::failure(); }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return self::failure(); }
            $committed = true;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        } finally { if (!$committed) { $wpdb->query('ROLLBACK'); FileUploads::cleanup($createdFiles); } }
        \LeenkatForms\Files\FileRecords::committed($createdFiles);
        foreach ($deliveryIds as $deliveryId) {
            if(\LeenkatForms\Settings\SiteSettings::email()['optimize_sending'])\LeenkatForms\Workflow\DeliveryWorker::schedule($deliveryId);
            else \LeenkatForms\Workflow\DeliveryWorker::process($deliveryId);
        }
        // Retention is an administrative choice, not acceptance of blocked content.
        if(in_array($spamReason,['Keyword Filter','Country Filter'],true))return \LeenkatForms\Security\SpamProtection::error($security,$spamReason,$matchedKeywords);
        return self::success($published['definition'],$values,['form_id'=>$formId,'entry_id'=>$entryId,'field_parts'=>$fieldParts,'field_choices'=>$fieldChoices]);
    }
    private static function invalid(): WP_Error { return new WP_Error('lkf_invalid', __('Please check the form values.', 'leenkat-forms'), ['status'=>400]); }
    private static function failure(): WP_Error { return new WP_Error('lkf_storage', __('Submission could not be saved. Please try again.', 'leenkat-forms'), ['status'=>503]); }
    private static function storedSuccess(object $entry,array $fallback): WP_REST_Response|WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $snapshot=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT definition FROM {$wpdb->prefix}lkf_form_versions WHERE id=%d",$entry->form_version_id)),true);
        $meta=json_decode($entry->meta,true) ?: [];
        if(($meta['spam_reason']??null)==='Country Filter')return \LeenkatForms\Security\SpamProtection::error(\LeenkatForms\Forms\SecuritySettings::normalize(is_array($snapshot)?$snapshot:$fallback),'Country Filter');
        // UUID retries must not turn an already blocked submission into success.
        if(($meta['spam_reason']??null)==='Keyword Filter')return \LeenkatForms\Security\SpamProtection::error(\LeenkatForms\Forms\SecuritySettings::normalize(is_array($snapshot)?$snapshot:$fallback),'Keyword Filter',\LeenkatForms\Security\SpamKeywords::matches(is_array($snapshot)?$snapshot:$fallback,json_decode($entry->payload??'{}',true) ?: []));
        return self::success(is_array($snapshot)?$snapshot:$fallback,\LeenkatForms\Files\FileRecords::safeValues(is_array($snapshot)?$snapshot:$fallback,json_decode($entry->payload,true) ?: [],$meta['uploads'] ?? []),['form_id'=>(int)$entry->form_id,'entry_id'=>(int)$entry->id,'entry_date'=>get_date_from_gmt($entry->submitted_at_gmt),'field_parts'=>$meta['field_parts'] ?? [],'field_choices'=>$meta['field_choices'] ?? []]);
    }
    private static function success(array $definition = [],array $values=[],array $context=[]): WP_REST_Response {
        $context+=['entry_date'=>current_time('mysql'),'user_email'=>wp_get_current_user()->user_email ?? ''];
        $values=\LeenkatForms\Files\FileRecords::safeValues($definition,$values);
        return new WP_REST_Response(ConfirmationReceipt::create(\LeenkatForms\Forms\ConfirmationSettings::response($definition,$values,$context),(int)($context['form_id'] ?? 0)),200);
    }
}


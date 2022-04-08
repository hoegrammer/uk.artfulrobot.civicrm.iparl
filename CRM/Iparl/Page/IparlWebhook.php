<?php
/**
 *
 * @file
 * Webhook endpoint for iParl.
 *
 * Finds/creates contact, creates action. Success is indicated by simply responding "OK".
 *
 * @author Rich Lott / Artful Robot
 * @copyright Rich Lott 2019
 * see licence.
 *
 * At time of writing, the iParl API provides:
 *
 * from: https://iparlsetup.com/help/output-api.php
 *
 * - actionid     The ID number of the action. This displays in the URL of each action and can also be accessed as an XML file using the 'List actions API' referred to here.
 * - secret       Secret string set when you set up this function. Testing for this in your script will allow you to filter out other, potentially hostile, attempts to feed information into your system. Not used in the redirect data string.
 * - name         if the action gathered two name fields, this will be the first name, otherwise it will be the complete first/surname combination
 * - surname      Surname, if gathered (update Aug 2019, was lastname)
 * - address1     Address line 1
 * - address2     Address line 2
 * - town         Town
 * - postcode     Postcode
 * - country      Country
 * - email        Email address
 * - phone        Phone number
 * - childid      The Child ID number of the sub-action if set. Some actions allow a supporter to select a pathway which will present them with one or another model letter.
 * - target       The email address used in actions which email a single target
 * - personid     The TheyWorkForYou.com personid value for the supporter's MP, if identified in the action. This can be used as the id value in the TheyWorkForYou getMP API method.
 * - mpname
 * - const        ??
 * - council
 * - region
 * - optin1
 * - optin2
 *
 * For petitions we *also* get:
 *
 * - actiontype: 'petition'
 * - actionid   Refers to the petition's ID.
 * - comment
 *
 */
class CRM_Iparl_Page_IparlWebhook extends CRM_Core_Page {

  /** @var array */
  public $test_log = [];

  /** @var mixed FALSE or (for test purposes) a callback to use in place of simplexml_load_file */
  public static $simplexml_load_file = 'simplexml_load_file';
  public $iparl_logging;

  /** @var string parsed first name */
  public $first_name;
  /** @var string parsed last name */
  public $last_name;
  /**
   * Log, if logging is enabled.
   */
  public function iparlLog($message, $priority=PEAR_LOG_INFO) {

    if (!isset($this->iparl_logging)) {
      // Look up logging setting and cache it.
      $this->iparl_logging = (int) Civi::settings()->get('iparl_logging');
    }
    if (!$this->iparl_logging) {
      // Logging disabled.
      return;
    }

    if ($this->iparl_logging === 'phpunit') {
      // For test purposes, just append to an array.
      $this->test_log[] = $message;
      return;
    }

    $message = "From $_SERVER[REMOTE_ADDR]: $message";
    CRM_Core_Error::debug_log_message($message, $out=FALSE, $component='iparl', $priority);
  }
  public function run() {
    try {
      // We do very minimal checks here before queuing it for async processing.
      $data = $_POST;

      // Check minimal required fields
      $errors = [];
      foreach (array('secret', 'email') as $_) {
        if (empty($data[$_])) {
          $errors[] = $_;
        }
      }
      if ($errors) {
        throw new Exception("POST data is invalid or incomplete. Missing: " . implode(', ', $errors));
      }

      // Check secret.
      $key = Civi::settings()->get("iparl_webhook_key");
      if (empty($key)) {
        throw new Exception("iParl secret not configured.");
      }
      if ($data['secret'] !== $key) {
        throw new Exception("iParl key mismatch.");
      }

      // Data looks OK. Remove the secret as we won't check it again.
      unset($data['secret']);
      $this->queueWebhook($data);
      echo "OK";
    }
    catch (Exception $e) {
      $this->iparlLog("EXCEPTION: ". $e->getMessage() .  "\nWhile processing: " . json_encode($_POST));
      header("$_SERVER[SERVER_PROTOCOL] 400 Bad request");
    }
    exit;
  }

  /**
   */
  public function queueWebhook($data) {
    $queue = CRM_Queue_Service::singleton()->create([
      'type'  => 'Sql',
      'name'  => 'iparl-webhooks',
      'reset' => FALSE, // We do NOT want to delete an existing queue!
    ]);
    $queue->createItem(new CRM_Queue_Task(
      ['CRM_Iparl_Page_IparlWebhook', 'processQueueItem'], // callback
      [$data], // arguments
      "" // title
    ));
  }
  /**
   * Provided for Queue Task
   */
  public static function processQueueItem($queueTaskContext, $data) {
    $obj = new static();
    $result = $obj->processWebhook($data);
    if (!$result) {
      // Processing this one failed.
      // We'll add it to another queue called 'iparl-webhook-failed' so the
      // data is not lost completely, but note that this queue has no runner(!)
      $queue = CRM_Queue_Service::singleton()->create([
        'type'  => 'Sql',
        'name'  => 'iparl-webhooks-failed',
        'reset' => FALSE, // We do NOT want to delete an existing queue!
      ]);
      $queue->createItem(new CRM_Queue_Task(
        ['CRM_Iparl_Page_IparlWebhook', 'processQueueItem'], // callback
        [$data], // arguments
        "" // title
      ));
    }
    return $result;
  }

  /**
   * The main procesing method.
   *
   * It is separate for testing purposes.
   *
   * @param array ($_POST data)
   * @return bool TRUE on success
   */
  public function processWebhook($data) {

    try {
      $this->iparlLog("Processing queued webhook: " . json_encode($data));
      $start = microtime(TRUE);

      // Provide a hook for acting on the raw data
      $unused = CRM_Utils_Hook::$_nullObject;
      $status = ['do_not_import' => FALSE, 'reason_not_to_import' => ''];
      $subject = $this->getActivitySubject($data);
      CRM_Utils_Hook::singleton()->invoke(
        ['data', 'subject', 'status'], // Named useful arguments.
        $data, $subject, $status, $unused, $unused, $unused,
        'civicrm_iparl_webhook_process_custom');
      // if the hook implementation has already done the importing, or does not want it to be imported for some reason, it will set this to true
      if ($status['do_not_import']) {
        $this->iparlLog("Not importing data because custom hook says: " . $status['reason_not_to_import']);
      } else {
        $this->parseNames($data);
        $contactID = $this->findOrCreate($data);
        $this->mergePhone($data, $contactID);
        $this->mergeAddress($data, $contactID);
        $activity = $this->recordActivity($data, $contactID, $subject);
        $took = round(microtime(TRUE) - $start, 3);
        $this->iparlLog("Successfully created/updated contact $contactID in {$took}s");

        // Issue #2
        // Provide a hook for custom action on the incoming data after creating the contact and activity.
        $start = microtime(TRUE);
        $unused = CRM_Utils_Hook::$_nullObject;
        $contact = ['id' => $contactID];
        CRM_Utils_Hook::singleton()->invoke(
          ['contact', 'activity', 'data'], // Named useful arguments.
          $contact, $activity, $data, $unused, $unused, $unused,
          'civicrm_iparl_webhook_post_process');
        $took = round(microtime(TRUE) - $start, 3);
        $this->iparlLog("Processed hook_civicrm_iparl_webhook_post_process in {$took}s");
      }
    }
    catch (\Exception $e) {
      $this->iparlLog("Failed processing: " . $e->getMessage());
      return FALSE;
    }

    return TRUE;
  }

  private function getActivitySubject($input) {
    // 'actiontype' key is not present for Lobby Actions, but is present and set to petition for petitions.
    $is_petition = (!empty($input['actiontype']) && $input['actiontype'] === 'petition');

    $subject = ($is_petition ? 'Petition' : 'Action') . " $input[actionid]";
    if (!empty($input['actionid'])) {
      $lookup = $this->getIparlObject($is_petition ? 'petition' : 'action');
      if (isset($lookup[$input['actionid']])) {
        $subject .= ": " . $lookup[$input['actionid']];
      }
      else {
        throw new \Exception("Failed to lookup data for actionid " . $input['actionid']);
      }
    }
    return $subject;
  }


  /**
   * Ensure we have name data in incoming data.
   *
   * If "Separate fields for first & last names" is not checked in the config
   *
   * iParl docs say of the 'name' data key:
   *
   * > name    - if the action gathered two name fields, this will be the first name,
   * >           otherwise it will be the complete first/surname combination
   * >
   * > surname - surname if gathered for this action
   *
   * (29 Aug 2019) https://iparlsetup.com/setup/help#supporterwebhook
   *
   * This function looks for 'surname' - if it's set it uses 'name' as first
   * name and surname as last name. Otherwise it tries to separate 'name' into
   * first and last - the first name is the first word before a space, the rest
   * is considered the surname. (Because this is not always right it's better
   * to collect separate first, last names yourself.)
   */
  public function parseNames($data) {
    $this->first_name = '';
    $this->last_name = '';

    $input_surname = trim($data['surname'] ?? '');
    $input_name = trim($data['name'] ?? '');

    if (!empty($input_surname)) {
      $this->last_name = $data['surname'];
      $this->first_name = $data['name'];
    }
    elseif (!empty($input_name)) {
      $parts = preg_split('/\s+/', $input_name);
      if (count($parts) === 1) {
        // User only supplied one name.
        $this->first_name = $parts[0];
        $this->last_name = '';
      }
      else {
        $this->first_name = array_shift($parts);
        $this->last_name = implode(' ', $parts);
      }
    }
    else {
      throw new Exception("iParl webhook requires data in the 'name' field.");
    }
  }
  /**
   * Returns a contactID
   */
  public function findOrCreate($input) :int {
    $result = \Civi\Api4\Email::get(FALSE)
      ->addSelect('contact_id', 'contact.first_name', 'contact.last_name')
      ->setJoin([['Contact AS contact', TRUE, NULL, ['contact.is_deleted', '=', 0], ['contact.is_deceased', '=', 0]]])
      ->addWhere('email', '=', $input['email'])
      ->execute();

    if (!$result->count()) {
      $contactID = (int) $this->createContact($input)['id'];
      $this->iparlLog("Created contact $contactID because email was not found.");
      return $contactID;
    }
    elseif ($result->count() === 1) {
      // Single email found.
      $contactID = (int) ($result->first()['contact_id']);
      $this->iparlLog("Found contact $contactID by email match.");
      return $contactID;
    }
    // Left with the case that the email is in there multiple times.
    // name matches.
    $unique_contacts = array();
    foreach ($result as $row) {
      $unique_contacts[(int) $row['contact_id']] = $row;
    }
    // Could be the same contact each time.
    if (count($unique_contacts) === 1) {
      return array_keys($unique_contacts)[0];
    }

    // We'll go for the first contact whose
    foreach ($unique_contacts as $contactID => $row) {
      if ($this->first_name == $row['contact.first_name']
        && (!empty($this->last_name) && $this->last_name == $row['contact.last_name'])) {
        // Found a match on name and email, return that.
        $this->iparlLog("Found contact $contactID by email and name match.");
        return $contactID;
      }
    }

    // If we were unable to match on first and last name, try last name only.
    if ($this->last_name) {
      foreach ($unique_contacts as $contactID => $row) {
        if ($this->last_name == $row['contact.last_name']) {
          // Found a match on last name and email, return that.
          $this->iparlLog("Found contact $contactID by email and last name match.");
          return $contactID;
        }
      }
    }

    // If we were unable to match on first and last name, try first name only.
    foreach ($unique_contacts as $contactID => $row) {
      if ($this->first_name == $row['contact.first_name']) {
        // Found a match on last name and email, return that.
        $this->iparlLog("Found contact $contactID by email and first name match.");
        return $contactID;
      }
    }

    // We know the email, but we think it belongs to someone else.
    // Create new contact.
    $contactID = (int) ($this->createContact($input)['id']);
    $this->iparlLog("Created contact $contactID because could not match on email and name");
    return $contactID;
  }
  /**
   * Create a contact.
   */
  public function createContact($input) {
    $params = array(
      'first_name'   => $this->first_name,
      'last_name'    => $this->last_name,
      'contact_type' => 'Individual',
      'email'        => $input['email'],
    );
    $result = civicrm_api3('Contact', 'create', $params);
    return $result;
  }
  /**
   * Ensure we have their phone number.
   */
  public function mergePhone($input, int $contactID) {
    if (empty($input['phone'])) {
      return;
    }
    $phone_numeric = preg_replace('/[^0-9]+/', '', $input['phone']);
    if (!$phone_numeric) {
      return;
    }
    // Does this phone exist already?
    $result = civicrm_api3('Phone', 'get', array(
      'contact_id' => $contactID,
      'phone_numeric' => $phone_numeric,
    ));
    if ($result['count'] == 0) {
      // Create the phone.
      $this->iparlLog("Created phone");
      $result = civicrm_api3('Phone', 'create', array(
        'contact_id' => $contactID,
        'phone' => $input['phone'],
      ));
    }
    else {
      $this->iparlLog("Phone already present");
    }
  }
  /**
   * Ensure we have their address.
   */
  public function mergeAddress($input, int $contactID) {
    if (empty($input['address1']) || empty($input['town']) || empty($input['postcode'])) {
      // Not enough input.
      return;
    }
    // Mangle address1 and address2 into one field since we don't know how
    // supplimental addresses are configured; they're not always the 2nd line.
    $street_address = trim($input['address1']);
    if (!empty($input['address2'])) {
      $street_address .= ", " . trim($input['address2']);
    }
    // Does this address exist already?
    $result = civicrm_api3('Address', 'get', array(
      'contact_id' => $contactID,
      'street_address' => $input['address1'],
      'city' => $input['town'],
      'postal_code' => $input['postcode'],
    ));
    if ($result['count'] == 0) {
      // Create the address.
      $result = civicrm_api3('Address', 'create', array(
        'location_type_id' => "Home",
        'contact_id' => $contactID,
        'street_address' => $input['address1'],
        'city' => $input['town'],
        'postal_code' => $input['postcode'],
      ));
      $this->iparlLog("Created address");
    }
    else {
      $this->iparlLog("Address already existed.");
    }
  }
  /**
   * Record the activity.
   */
  public function recordActivity($input, int $contactID, $subject) {

    $activity_target_type = (int) civicrm_api3('OptionValue', 'getvalue',
      array( 'return' => "value", 'option_group_id' => "activity_contacts", 'name' => "Activity Targets" ));

    $activity_type_declaration= (int) civicrm_api3('OptionValue', 'getvalue',
      array( 'return' => "value", 'option_group_id' => "activity_type", 'name' => "iparl" ));

    $params = [
      'activity_type_id'  => $activity_type_declaration,
      'target_id'         => $contactID,
      'source_contact_id' => $contactID,
      'subject'           => $subject,
      'details'           => '',
    ];
    if (preg_match('/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $input['date'] ?? '')) {
      // Date looks valid enough
      $params['activity_date_time'] = $input['date'];
    }
    $result = civicrm_api3('Activity', 'create', $params);
    return $result;
  }
  /**
   * Obtain a cached array lookup keyed by action/petition id with title as value.
   *
   * @param string $type petition|action
   * @return null|array NULL means unsuccessful at downloading otherwise return
   * array (which may be empty)
   */
  public function getIparlObject($type, $bypass_cache=FALSE) {
    if ($type !== 'action' && $type !== 'petition') {
      throw new Exception("getIparlObject \$type must be action or petition. Received " . json_encode($type));
    }

    // @todo use static cache too.

    // do we have it in cache?
    // Note that this: $cache = Civi::cache(); defaults to a normal array, lost at the end of each request.
    $cache = CRM_Utils_Cache::create([
      'type' => ['SqlGroup', 'ArrayCache'],
      'name' => 'iparl',
    ]);

    $cache_key = "iparl_titles_$type";
    $data = $bypass_cache ? NULL : $cache->get($cache_key, NULL);
    if ($data === NULL) {
      $this->iparlLog("Cache " . ($bypass_cache ? 'bypass' : 'miss') . " on looking up $cache_key");
      // Fetch from iparl api.
      $iparl_username = Civi::settings()->get("iparl_user_name");
      if ($iparl_username) {
        $url = $this->getLookupUrl($type);
        $function = static::$simplexml_load_file;
        $xml = $function($url , null , LIBXML_NOCDATA);
        $file = json_decode(json_encode($xml), TRUE);
        if (is_array($file)) {
          // Successfully downloaded data.
          $data = [];
          if (isset($file[$type])) {
            // This will either contain: {item} or [{item}, {item}]
            if (isset($file[$type]['title'])) {
              // We have the first form. Wrap it in an array.
              $list = [$file[$type]];
            }
            else {
              $list = $file[$type];
            }
            foreach ($list as $item) {
              if (!is_array($item)) {
                Civi::log()->error("Expected to get an array item inside $type but didn't. Got this:\n" . json_encode($file, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
              }
              $data[$item['id']] = $item['title'];
            }
          }
          // Cache it (even an empty dataset) for 1 hour. Note that saving the
          // iParl Settings form will force a refresh of this cache.
          $cache->set($cache_key, $data, 60*60);
          $this->iparlLog("Caching " . count($data) . " results from $url for 1 hour.");
        }
        else {
          $this->iparlLog("Failed to load resource at: $url");
        }
      }
      else {
        $this->iparlLog("Missing iparl_user_name, cannot access iParl API");
      }
    }
    else {
      $this->iparlLog("Cache hit on looking up $cache_key");
    }
    return $data;
  }
  /**
   * Return the iParl API URL
   *
   * @param string $type petition|action
   * @return string URL
   */
  public function getLookupUrl($type) {
    $iparl_username = Civi::settings()->get("iparl_user_name");
    $url = "https://iparlsetup.com/api/$iparl_username/";
    if ($type === 'action') {
      $url .= "actions.xml"; // new .xml extension required ~Autumn 2019
    }
    elseif ($type === 'petition') {
      $url .= "petitions"; // old style, without .xml
    }
    return $url;
  }

}

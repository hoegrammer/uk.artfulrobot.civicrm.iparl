<?php
/**
 *
 * @file
 * Webhook endpoint for iParl.
 *
 * Finds/creates contact, creates action. Success is indicated by simply responding "OK".
 *
 * @author Rich Lott / Artful Robot
 * @copyright Rich Lott 2016
 * see licence.
 *
 * At time of writing, the iParl API provides:
 *
 * from: https://iparlsetup.com/help/output-api.php
 *
 * - actionid     The ID number of the action. This displays in the URL of each action and can also be accessed as an XML file using the 'List actions API' referred to here.
 * - secret       Secret string set when you set up this function. Testing for this in your script will allow you to filter out other, potentially hostile, attempts to feed information into your system. Not used in the redirect data string.
 * - name         Where only one name field is gathered it will display here. If a last name is also gathered, this will be the first name.
 * - lastname     Last name, if gathered
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
  /**
   * Log, if logging is enabled.
   */
  public function iparlLog($message, $priority=PEAR_LOG_INFO) {

    if (!isset($this->iparl_logging)) {
      // Look up logging setting and cache it.
      $this->iparl_logging = (int) civicrm_api3('Setting', 'getvalue', array( 'name' => "iparl_logging", 'group' => 'iParl Integration Settings' ));
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
    $this->iparlLog("POSTed data: " . serialize($_POST));
    try {
      $this->processWebhook($_POST);
      echo "OK";
      exit;
    }
    catch (Exception $e) {
      error_log($e->getMessage());
      header("$_SERVER[SERVER_PROTOCOL] 400 Bad request");
      exit;
    }
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
    // Check secret.
    foreach (array('secret', 'name', 'lastname', 'email') as $_) {
      if (empty($data[$_])) {
        $this->iparlLog("POSTed data missing (at least) $_");
        throw new Exception("POST data is invalid or incomplete.");
      }
    }
    $key = civicrm_api3('Setting', 'getvalue', array( 'name' => "iparl_webhook_key" ));
    if (empty($key)) {
      $this->iparlLog("no iparl_webhook_key set. Will not process.");
      throw new Exception("iParl secret not configured.");
    }
    if ($data['secret'] !== $key) {
      $this->iparlLog("iparl_webhook_key mismatch. Will not process.");
      throw new Exception("iParl invalid auth.");
    }

    $contact = $this->findOrCreate($data);
    $this->mergePhone($data, $contact);
    $this->mergeAddress($data, $contact);
    $this->recordActivity($data, $contact);
    $this->iparlLog("Successfully created/updated contact $contact[id]");
    return TRUE;
  }

  public function findOrCreate($input) {
    // Look up the email first.
    $result = civicrm_api3('Email', 'get', array(
      'sequential' => 1,
      'email' => $input['email'],
      'api.Contact.get' => array(),
    ));

    if ($result['count'] == 0) {
      $result =  $this->createContact($input);
      $this->iparlLog("Created contact $result[id]");
      return $result;
    }
    elseif ($result['count'] == 1) {
      // Single email found.
      $result =  $result['values'][0]['api.Contact.get']['values'][0];
      $this->iparlLog("Found contact $result[id] by email match.");
      return $result;
    }
    // Left with the case that the email is in there multiple times.
    // Could be the same contact each time. We'll go for the first contact whose
    // name matches.
    $unique_contacts = array();
    foreach ($result['values'] as $email_record) {
      foreach ($email_record['api.Contact.get']['values'] as $c) {
        $unique_contacts[$c['contact_id']] = $c;
      }
    }
    foreach ($unique_contacts as $contact_id => $contact) {
      if ($input['name'] == $contact['first_name']
        && $input['lastname'] == $contact['last_name']) {
        // Found a match on name and email, return that.
        $this->iparlLog("Found contact $contact[id] by email and name match.");
        return $contact;
      }
    }

    // If we were unable to match on first and last name, try last name only.
    foreach ($unique_contacts as $contact_id => $contact) {
      if ($input['lastname'] == $contact['last_name']) {
        // Found a match on last name and email, return that.
        $this->iparlLog("Found contact $contact[id] by email and last name match.");
        return $contact;
      }
    }

    // If we were unable to match on first and last name, try first name only.
    foreach ($unique_contacts as $contact_id => $contact) {
      if ($input['firstname'] == $contact['first_name']) {
        // Found a match on last name and email, return that.
        $this->iparlLog("Found contact $contact[id] by email and first name match.");
        return $contact;
      }
    }

    // We know the email, but we think it belongs to someone else.
    // Create new contact.
    $result = $this->createContact($input);
    $this->iparlLog("Created contact $result[id]");
    return $result;
  }
  /**
   * Create a contact.
   */
  public function createContact($input) {
    $params = array(
      'first_name' => $input['name'],
      'last_name' => $input['lastname'],
      'contact_type' => 'Individual',
      'email' => $input['email'],
    );
    $result = civicrm_api3('Contact', 'create', $params);
    return $result;
  }
  /**
   * Ensure we have their phone number.
   */
  public function mergePhone($input, $contact) {
    if (empty($input['phone'])) {
      return;
    }
    $phone_numeric = preg_replace('/[^0-9]+/', '', $input['phone']);
    if (!$phone_numeric) {
      return;
    }
    // Does this phone exist already?
    $result = civicrm_api3('Phone', 'get', array(
      'contact_id' => $contact['id'],
      'phone_numeric' => $phone_numeric,
    ));
    if ($result['count'] == 0) {
      // Create the phone.
      $this->iparlLog("Created phone");
      $result = civicrm_api3('Phone', 'create', array(
        'contact_id' => $contact['id'],
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
  public function mergeAddress($input, $contact) {
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
      'contact_id' => $contact['id'],
      'street_address' => $input['address1'],
      'city' => $input['town'],
      'postal_code' => $input['postcode'],
    ));
    if ($result['count'] == 0) {
      // Create the address.
      $result = civicrm_api3('Address', 'create', array(
        'location_type_id' => "Home",
        'contact_id' => $contact['id'],
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
  public function recordActivity($input, $contact) {

    // 'actiontype' key is not present for Lobby Actions, but is present and set to petition for petitions.
    $is_petition = (!empty($input['actiontype']) && $input['actiontype'] === 'petition');

    $subject = ($is_petition ? 'Petition' : 'Action') . " $input[actionid]";
    if (!empty($input['actionid'])) {
      $lookup = $this->getIparlObject($is_petition ? 'petition' : 'action');
      if (isset($lookup[$input['actionid']])) {
        $subject .= ": " . $lookup[$input['actionid']];
      }
    }

    $activity_target_type = (int) civicrm_api3('OptionValue', 'getvalue',
      array( 'return' => "value", 'option_group_id' => "activity_contacts", 'name' => "Activity Targets" ));

    $activity_type_declaration= (int) civicrm_api3('OptionValue', 'getvalue',
      array( 'return' => "value", 'option_group_id' => "activity_type", 'name' => "iparl" ));

    $params = array(
      'activity_type_id'  => $activity_type_declaration,
      'target_id'         => $contact['id'],
      'source_contact_id' => $contact['id'],
      'subject'           => $subject,
      'details'           => '',
    );
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

    // do we have it in cache?
    $cache = Civi::cache();
    $cache_key = "iparl_titles_$type";
    $data = $bypass_cache ? NULL : $cache->get($cache_key, NULL);
    if ($data === NULL) {
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
            foreach (is_array($file[$type]) ? $file[$type] : [$file[$type]] as $item) {
              $data[$item['id']] = $item['title'];
            }
          }
          // Cache it (even an empty dataset) for 10 minutes.
          $cache->set($cache_key, $data, 10*60);
        }
      }
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
    $url = "https://iparlsetup.com/api/$iparl_username/{$type}s";
    return $url;
  }

}

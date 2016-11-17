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
 * - actionid	The ID number of the action. This displays in the URL of each action and can also be accessed as an XML file using the 'List actions API' referred to here.
 * - secret	Secret string set when you set up this function. Testing for this in your script will allow you to filter out other, potentially hostile, attempts to feed information into your system. Not used in the redirect data string.
 * - name	Where only one name field is gathered it will display here. If a last name is also gathered, this will be the first name.
 * - lastname	Last name, if gathered
 * - adderss1	Address line 1
 * - address2	Address line 2
 * - town	Town
 * - postcode	Postcode
 * - country	Country
 * - email	Email address
 * - phone	Phone number
 * - childid	The Child ID number of the sub-action if set. Some actions allow a supporter to select a pathway which will present them with one or another model letter.
 * - target	The email address used in actions which email a single target
 * - personid	The TheyWorkForYou.com personid value for the supporter's MP, if identified in the action. This can be used as the id value in the TheyWorkForYou getMP API method.
 * - mpname	Name of supporter's MP
 * - const	Westminster constituency
 * - council	Borough Council
 * - region	European Parliamentary region
 * - contactme	Set as 1 if the first optional tick box has been checked
 * - optin2	Set as 1 if the second optional tick box has been checked
 *
 * And there's also https://iparlsetup.com/help/api.php
 * http://www.iparl.com/api/equalitytrust/petitions
 * http://www.iparl.com/api/equalitytrust/actions
 */

class CRM_Iparl_Page_IparlWebhook extends CRM_Core_Page {

  public $iparl_logging;
  /**
   * Log, if logging is enabled.
   */
  public function iparlLog($message, $priority=PEAR_LOG_INFO) {

    if (!isset($this->iparl_logging)) {
      $this->iparl_logging = (int) civicrm_api3('Setting', 'getvalue', array( 'name' => "iparl_logging", 'group' => 'iParl Integration Settings' ));
    }
    if (!$this->iparl_logging) {
      // Logging disabled.
      return;
    }

    $message = "From $_SERVER[REMOTE_ADDR]: $message";
    CRM_Core_Error::debug_log_message($message, $out=FALSE, $component='iparl', $priority);
  }
  public function run() {

    $this->iparlLog("POSTed data: " . serialize($_POST));
    try {

      // Check secret.
      foreach (['secret', 'name', 'lastname', 'email'] as $_) {
        if (empty($_POST[$_])) {
          $this->iparlLog("POSTed data missing (at least) $_");
          throw new Exception("POST data is invalid or incomplete.");
        }
      }
      $key = civicrm_api3('Setting', 'getvalue', array( 'name' => "iparl_webhook_key" ));
      if (empty($key)) {
        $this->iparlLog("no iparl_webhook_key set. Will not process.");
        throw new Exception("iParl secret not configured.");
      }
      if ($_POST['secret'] !== $key) {
        $this->iparlLog("iparl_webhook_key mismatch. Will not process.");
        throw new Exception("iParl invalid auth.");
      }

      $contact = $this->findOrCreate($_POST);
      $this->mergePhone($_POST, $contact);
      $this->mergeAddress($_POST, $contact);
      $this->recordActivity($_POST, $contact);
      $this->iparlLog("Successfuly created/updated contact $contact[id]");
      echo "OK";
      exit;
    }
    catch (Exception $e) {
      error_log($e->getMessage());
      header("$_SERVER[SERVER_PROTOCOL] 400 Bad request");
      exit;
    }

    //parent::run();
  }

  public function findOrCreate($input) {
    // Look up the email first.
    $result = civicrm_api3('Email', 'get', [
      'sequential' => 1,
      'email' => $input['email'],
      'api.Contact.get' => [],
      ]);

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
    $unique_contacts = [];
    foreach ($result['values'] as $email_record) {
      foreach ($email_record['api.Contact.get'] as $c) {
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
    $params = [
      'first_name' => $input['name'],
      'last_name' => $input['lastname'],
      'contact_type' => 'Individual',
      'email' => $input['email'],
    ];
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
    $result = civicrm_api3('Phone', 'get', [
      'contact_id' => $contact['id'],
      'phone_numeric' => $phone_numeric,
    ]);
    if ($result['count'] == 0) {
      // Create the phone.
      $result = civicrm_api3('Phone', 'create', [
        'contact_id' => $contact['id'],
        'phone' => $input['phone'],
      ]);
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
    $result = civicrm_api3('Address', 'get', [
      'contact_id' => $contact['id'],
      'street_address' => $input['address1'],
      'city' => $input['town'],
      'postal_code' => $input['postcode'],
    ]);
    if ($result['count'] == 0) {
      // Create the address.
      $result = civicrm_api3('Address', 'create', [
        'location_type_id' => "Home",
        'contact_id' => $contact['id'],
        'street_address' => $input['address1'],
        'city' => $input['town'],
        'postal_code' => $input['postcode'],
      ]);
    }
  }
  /**
   * Record the activity.
   */
  public function recordActivity($input, $contact) {

    $iparl_username = civicrm_api3('Setting', 'getvalue', array( 'name' => "iparl_user_name", 'group' => 'iParl Integration Settings' ));

    $subject = 'Unknown action or petition';

    if (!empty($input['actionid']) && $iparl_username) {
      // We have an 'action'.
      $url = "http://www.iparl.com/api/$iparl_username/actions";
      $xml = simplexml_load_file($url , null , LIBXML_NOCDATA);
      $file = json_decode(json_encode($xml));
      $subject = "Action $input[actionid]";
      if ($file && !empty($file->action)) {
        foreach ($file->action as $action) {
          if ($action->id == $input['actionid']) {
            $subject = "Action $action->id: $action->title";
            break;
          }
        }
      }
    }
    if (FALSE) {
      // @todo future feature: it is unclear how to look up the petition.
      if (!empty($input['petitionid']) && $iparl_username) {
        // We have an 'action'.
        $url = "http://www.iparl.com/api/$iparl_username/petitions";
        $xml = simplexml_load_file($url , null , LIBXML_NOCDATA);
        $file = json_decode(json_encode($xml));
        $subject = "Petition $input[petitionid]";
        if ($file && !empty($file['petition'])) {
          foreach ($file['petition'] as $petition) {
            if ($petition->id == $input['petitionid']) {
              $subject = "Petition $petition->id: $petition->title";
              break;
            }
          }
        }
      }
    }

    $activity_target_type = (int) civicrm_api3('OptionValue', 'getvalue',
      ['return' => "value", 'option_group_id' => "activity_contacts", 'name' => "Activity Targets"]);

    $activity_type_declaration= (int) civicrm_api3('OptionValue', 'getvalue',
      ['return' => "value", 'option_group_id' => "activity_type", 'name' => "iparl"]);

    $result = civicrm_api3('Activity', 'create', [
      'activity_type_id'  => $activity_type_declaration,
      'target_id'         => $contact['id'],
      'source_contact_id' => $contact['id'],
      'subject'           => $subject,
      'details'           => '',
    ]);
    return $result;
  }
}

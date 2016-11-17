<?php

/**
 *
 * Array
 * (
 *  [secret] => 2jh340f9
 *  [actiontype] => petition
 *  [name] => Wilma
 *  [lastname] => Flintstone
 *  [address1] =>
 *  [address2] =>
 *  [town] => Oxford
 *  [postcode] =>
 *  [country] =>
 *  [email] => forums@artfulrobot.uk
 *  [phone] =>
 *  [comment] =>
 *  [actionid] => 1
 *  [childid] =>
 *  [target] =>
 *  [personid] =>
 *  [mpname] =>
 *  [const] =>
 *  [council] =>
 *  [region] =>
 *  [contactme] => 0
 *  [optin2] => 0
 */

class CRM_Iparl_Page_IparlWebhook extends CRM_Core_Page {
  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    //CRM_Utils_System::setTitle(ts('IparlWebhook'));

    // Example: Assign a variable for use in a template
    //$this->assign('currentTime', date('Y-m-d H:i:s'));
    //
    // Check secret.
    foreach (['secret', 'name', 'lastname', 'email'] as $_) {
      if (empty($_POST[$_])) {
        throw new Exception("POST data is invalid or incomplete.");
      }
    }
    $result = civicrm_api3('Setting', 'get', ['sequential' => 1, 'return' => "iparl_webhook_key"]);
    if (empty($result['values'][0]['iparl_webhook_key'])) {
      throw new Exception("iParl secret not configured.");
    }
    if ($_POST['secret'] !== $result['values'][0]['iparl_webhook_key']) {
      throw new Exception("iParl invalid auth.");
    }

    // @todo check we have name, email, lookup or find.
    $contact = $this->findOrCreate($_POST);

    // @todo merge in phone

    // @todo merge in address

    // @todo Look up action using iParl API

    // @todo Create activity.
    $this->recordActivity($input, $contact);

    echo "OK";
    exit;
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
      return $this->createContact($input);
    }
    elseif ($result['count'] == 1) {
      // Single email found.
      return $result['values'][0]['api.Contact.get']['values'][0];
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
        return $contact;
      }
    }

    // If we were unable to match on first and last name, try last name only.
    foreach ($unique_contacts as $contact_id => $contact) {
      if ($input['lastname'] == $contact['last_name']) {
        // Found a match on last name and email, return that.
        return $contact;
      }
    }

    // If we were unable to match on first and last name, try first name only.
    foreach ($unique_contacts as $contact_id => $contact) {
      if ($input['firstname'] == $contact['first_name']) {
        // Found a match on last name and email, return that.
        return $contact;
      }
    }

    // We know the email, but we think it belongs to someone else.
    // Create new contact.
    return $this->createContact($input);

  }
  /**
   * Create a contact.
   */
  public function createContact($input) {
    // @todo
  }
  /**
   * Record the activity.
   */
  public function recordActivity($input, $contact) {

    $activity_target_type = (int) civicrm_api3('OptionValue', 'getvalue',
      ['return' => "value", 'option_group_id' => "activity_contacts", 'name' => "Activity Targets"]);

    $activity_type_declaration= (int) civicrm_api3('OptionValue', 'getvalue',
      ['return' => "value", 'option_group_id' => "activity_type", 'name' => "iparl"]);

    $result = civicrm_api3('Activity', 'create', [
      'activity_type_id' => $activity_type_declaration,
      'target_id' => $contact['id'],
      'source_contact_id' => $contact['id'],
      'subject' => 'Took action',
      'details' => '',
    ]);
    return $result;
  }
}

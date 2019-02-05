<?php

use CRM_Iparl_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test basic webhooks
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class iparlTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * This is a rather long test.
   *
   * - Submit a webhook
   * - Check the contact was created
   * - check the phone was created
   * - check the address was added
   * - check the activity was added with subject 'Action 123'
   * - configure iparl username (enabling lookup of action titles)
   * - submit another webhook
   * - check that the contact created earlier was found
   * - check that the phone was identified as already there.
   * - check that the address was identified as already there.
   * - check that a new activity was added with subject including action title
   * - check that the lookup of action title data was only called once.
   * - check that accessing lookup for actions again returns cached value
   * - check that lookup fires for petitions data.
   *
   */
  public function testAction() {

    $webhook = new CRM_Iparl_Page_IparlWebhook();
    $webhook->iparl_logging = 'phpunit';

    // Mock the iParl XML API.
    $calls = 0;
    $this->mockIparlTitleLookup($calls);

    // Set the key
    Civi::settings()->set('iparl_webhook_key', 'helloHorseHeadLikeYourJumper');

    $result = $webhook->processWebhook([
      'actionid' => 123,
      'secret'   => 'helloHorseHeadLikeYourJumper',
      'name'     => 'Wilma',
      'lastname' => 'Flintstone',
      'address1' => 'Cave 123',
      'address2' => 'Cave Street',
      'town'     => 'Rocksville',
      'postcode' => 'SW1A 0AA',
      'country'  => 'UK',
      'email'    => 'wilma@example.com',
      'phone'    => '01234 567890',
      'optin1'   => 1,
      'optin2'   => 1,
    ]);
    $this->assertTrue($result);

    // There should now be a contact for Wilma
    $result = civicrm_api3('Contact', 'get', ['email' => 'wilma@example.com', 'first_name' => 'Wilma', 'last_name' => 'Flintstone']);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $contact_id = current($result['values'])['id'];

    $this->assertEquals([
      "Created contact $contact_id",
      "Created phone",
      "Created address",
      "Successfully created/updated contact $contact_id",
    ], $webhook->test_log);

    // There should be a phone.
    $result = civicrm_api3('Phone', 'get', ['sequential' => 1, 'contact_id' => $contact_id]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals('01234567890', $result['values'][0]['phone_numeric']);

    // There should be one address.
    $result = civicrm_api3('Address', 'get', [
      'contact_id' => $contact_id,
      'sequential' => 1,
    ]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals('Cave 123', $result['values'][0]['street_address']);

    // There should be one activity.
    $result = civicrm_api3('Activity', 'get',
      [
     'target_contact_id' => $contact_id,
     'return'               => ["activity_type_id.name", 'subject'],
     'sequential'           => 1
      ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    // As we did not supply username, the subject should be...
    $this->assertEquals('Action 123', $result['values'][0]['subject']);

    // Repeat. There should now be two activities but only one contact
    // We set the username though to obtain more info for the activity.
    Civi::settings()->set('iparl_user_name', 'superfoo');
    $webhook->test_log = [];
    $result = $webhook->processWebhook([
      'actionid' => 123,
      'secret'   => 'helloHorseHeadLikeYourJumper',
      'name'     => 'Wilma',
      'lastname' => 'Flintstone',
      'address1' => 'Cave 123',
      'address2' => 'Cave Street',
      'town'     => 'Rocksville',
      'postcode' => 'SW1A 0AA',
      'country'  => 'UK',
      'email'    => 'wilma@example.com',
      'phone'    => '01234 567890',
      'optin1'   => 1,
      'optin2'   => 1,
    ]);
    $this->assertTrue($result);

    // There should now be a contact for Wilma
    $result = civicrm_api3('Contact', 'get', ['email' => 'wilma@example.com', 'first_name' => 'Wilma', 'last_name' => 'Flintstone']);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals($contact_id, current($result['values'])['id']);

    $this->assertEquals([
      "Found contact $contact_id by email match.",
      "Phone already present",
      "Address already existed.",
      "Successfully created/updated contact $contact_id",
    ], $webhook->test_log);

    // There should be one phone.
    $result = civicrm_api3('Phone', 'get', ['sequential' => 1, 'contact_id' => $contact_id]);
    $this->assertEquals(1, $result['count']);
    $this->assertEquals('01234567890', $result['values'][0]['phone_numeric']);

    $result = civicrm_api3('Activity', 'get',
      [
     'target_contact_id' => $contact_id,
     'return'            => ["activity_type_id.name", 'subject'],
     'sequential'        => 1,
     'options'           => ['sort' => 'id'],
      ]);
    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals(2, $result['count']);
    // The second activity should have a fancier subject
    $this->assertEquals('Action 123: Some demo action', $result['values'][1]['subject']);

    $this->assertEquals(1, $calls);
    $lookup = $webhook->getIparlObject('action');
    $this->assertInternalType('array', $lookup);
    $this->assertEquals(1, $calls, 'Multiple calls to fetch iParl api resource suggests caching fail.');

    $lookup = $webhook->getIparlObject('petition');
    $this->assertInternalType('array', $lookup);
    $this->assertEquals(2, $calls);
  }

  /**
   * Check system status warnings/errors.
   */
  public function testChecksWork() {

    $webhook = new CRM_Iparl_Page_IparlWebhook();
    $calls = 0;
    $this->mockIparlTitleLookup($calls, TRUE);
    $webhook->iparl_logging = 'phpunit';

    $result = civicrm_api3('System', 'check');
    $this->assertEquals(0, $result['is_error'] ?? 1);
    $found_missing_user = FALSE;
    $found_missing_key = FALSE;
    $found_failed_lookup = FALSE;
    foreach ($result['values'] ?? [] as $message) {
      if ($message['name'] === 'iparl_missing_user') {
        $found_missing_user = TRUE;
      }
      elseif ($message['name'] === 'iparl_missing_webhook_key') {
        $found_missing_key = TRUE;
      }
      elseif ($message['name'] === 'iparl_api_fail') {
        $found_failed_lookup = TRUE;
      }
    }
    $this->assertTrue($found_missing_key, 'Expected to find missing webhook key message in system checks');
    $this->assertTrue($found_missing_user, 'Expected to find missing username message in system checks');
    $this->assertFalse($found_failed_lookup, 'Expected to not find failed API message in system checks');

  }
  /**
   * Check the API works.
   */
  public function testChecksApiWorks() {

    $calls = 0;

    // Set user, since the check is not done unless we have a username.
    Civi::settings()->set('iparl_user_name', 'superfoo');

    // Check a failed API call is detected.
    // Mock title lookup to return NULL, i.e. api unavailable.
    $this->mockIparlTitleLookup($calls, NULL);
    $result = civicrm_api3('System', 'check');
    $this->assertEquals(0, $result['is_error'] ?? 1);
    $found_missing_user = FALSE;
    $found_failed_lookup = FALSE;
    foreach ($result['values'] ?? [] as $message) {
      if ($message['name'] === 'iparl_missing_user' && $message['severity'] === 'error') {
        $found_missing_user = TRUE;
      }
      elseif ($message['name'] === 'iparl_api_fail' && $message['severity'] === 'error') {
        $found_failed_lookup = TRUE;
      }
    }
    $this->assertFalse($found_missing_user, 'Did not expect missing user message but got one.');
    $this->assertTrue($found_failed_lookup, 'Expected to find failed API message in system checks');

    // Check an empty API call is detected.
    // Mock title lookup to return NULL, i.e. api unavailable.
    $this->mockIparlTitleLookup($calls, []);
    $result = civicrm_api3('System', 'check');
    $this->assertEquals(0, $result['is_error'] ?? 1);
    $found_failed_lookup = FALSE;
    foreach ($result['values'] ?? [] as $message) {
      if ($message['name'] === 'iparl_api_fail' && $message['severity'] === 'notice') {
        $found_failed_lookup = TRUE;
      }
    }
    $this->assertTrue($found_failed_lookup, 'Expected to find failed API message in system checks for empty result');

  }

  /**
   * For testing purposes.
   *
   * @param int &$calls Counts times it was called.
   * @param mixed $fail. If given and not FALSE, this is the value that any
   * simplexml_load_file call will return. Useful for returning null or []
   */
  public function mockIparlTitleLookup(&$calls, $fail=FALSE) {
    // Mock the iParl XML API.
    if ($fail !== FALSE) {
      CRM_Iparl_Page_IparlWebhook::$simplexml_load_file = function($url, $a, $b) use (&$calls, $fail) {
        $calls++;
        return $fail;
      };
    }
    else {
      CRM_Iparl_Page_IparlWebhook::$simplexml_load_file = function($url, $a, $b) use(&$calls) {
        $calls++;
        switch ($url) {
        case "https://iparlsetup.com/api/superfoo/petitions":
          return simplexml_load_string('<?xml version="1.0"?><xml>
  <petition><title>Some demo petition</title><id>456</id></petition>
  <petition><title>Another demo petition</title><id>678</id></petition>
  </xml>');
          break;

        case "https://iparlsetup.com/api/superfoo/actions":
          return simplexml_load_string('<?xml version="1.0"?><xml>
  <action><title>Some demo action</title><id>123</id></action>
  <action><title>Another demo action</title><id>234</id></action>
  </xml>');
          break;

        default:
          throw new \Exception("unexpected URL: $url");
        }
      };
    }
  }
}

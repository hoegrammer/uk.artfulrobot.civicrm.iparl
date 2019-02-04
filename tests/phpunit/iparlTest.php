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
    Civi::settings()->set('iparl_webhook_key', 'helloHorseHeadLikeYourJumper');
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testAction() {
    $webhook = new CRM_Iparl_Page_IparlWebhook();
    $webhook->iparl_logging = 'phpunit';

    // Mock the iParl XML API.
    $calls = 0;
    $webhook->simplexml_load_file = function($url, $a, $b) use(&$calls) {
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

}

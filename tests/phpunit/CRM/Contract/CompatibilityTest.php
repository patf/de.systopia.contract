<?php

use CRM_Contract_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

include_once 'ContractTestBase.php';

/**
 * Compatibility Tests: Make sure the the engine refactoring
 *  is still compatible with the old behaviour where wanted
 *
 * @group headless
 */
class CRM_Contract_CompatibilityTest extends CRM_Contract_ContractTestBase {

  public function setUp() {
    parent::setUp();
    $this->setActivityFlavour('GP');
  }

  /**
   * Check if the subject of the change activity is as requested
   *
   * @see https://redmine.greenpeace.at/issues/1276#note-74
   */
  public function testUpdateActivitySubject() {
    // create a new contract
    $contract = $this->createNewContract([
        'is_sepa'            => 1,
        'amount'             => '12.00',
        'frequency_unit'     => 'month',
        'frequency_interval' => '1',
        'cycle_day'          => 25,
        'iban'               => 'DE89370400440532013000',
        'bic'                => 'GENODEM1GLS',
    ]);

    // modify contract
    $this->modifyContract($contract['id'], 'update', 'tomorrow', [
        'membership_payment.membership_annual' => '168.00',
        'membership_payment.cycle_day'         => '3',
    ]);

    // get the resulting change activity
    $this->runContractEngine($contract['id'], '+2 days');
    $change_activity = $this->getLastChangeActivity($contract['id']);
    $this->assertNotEmpty($change_activity, "There should be a change activity after the upgrade");
    $this->assertContains("cycle day 25 to 3", $change_activity['subject'], "Activity subject should contain the changed cycle day");
    $this->assertContains("amt. 144.00 to 168.00", $change_activity['subject'], "Activity subject should contain the changed amount");
    $this->assertNotContains("DE89370400440532013000", $change_activity['subject'], "Activity subject should NOT contain the unchanged IBAN");
    $this->assertNotContains("freq. 12 to 12", $change_activity['subject'], "Activity subject should NOT contain the unchanged frequency");
  }

  /**
   * Check if the system created activities are suppressed (if enabled)
   *
   * @see https://redmine.greenpeace.at/issues/1276#note-74
   */
  public function testSuppressSystemActivities() {
    $suppressed_types = CRM_Contract_Configuration::suppressSystemActivityTypes();
    if (!empty($suppressed_types)) {
      $contact_id = $this->createContactWithRandomEmail()['id'];

      // get the last activity (we don't care about the previous ones)
      $last_activity_id = $this->getLastActivityID([
          'activity_type_id'  => ['IN' => $suppressed_types],
          'target_contact_id' => $contact_id
      ]);

      // create a new contract
      $contract = $this->createNewContract([
          'contact_id'         => $contact_id,
          'is_sepa'            => 1,
          'amount'             => '12.00',
          'frequency_unit'     => 'month',
          'frequency_interval' => '1',
          'cycle_day'          => 25,
          'iban'               => 'DE89370400440532013000',
          'bic'                => 'GENODEM1GLS',
      ]);

      // see if there has been an activity created AFTER the last one (if there was one)
      $next_activity_id = $this->getLastActivityID([
          'activity_type_id'  => ['IN' => $suppressed_types],
          'target_contact_id' => $contact_id
      ], $last_activity_id);
      $this->assertEmpty($next_activity_id, "A system activity was generated after contract creation event though it's supposed to be suppressed");

      // modify contract
      $this->modifyContract($contract['id'], 'update', 'tomorrow', [
          'membership_payment.membership_annual' => '168.00',
          'membership_payment.cycle_day'         => '3',
      ]);
      $this->runContractEngine($contract['id'], '+2 days');

      // see if there has been an activity created AFTER the last one (if there was one)
      $next_activity_id = $this->getLastActivityID([
          'activity_type_id'  => ['IN' => $suppressed_types],
          'target_contact_id' => $contact_id
      ], $last_activity_id);
      $this->assertEmpty($next_activity_id, "A system activity was generated after contract update event though it's supposed to be suppressed");

      // pause contract
      $this->modifyContract($contract['id'], 'pause', '+2 days');
      $this->runContractEngine($contract['id'], '+4 days');
      $next_activity_id = $this->getLastActivityID([
          'activity_type_id'  => ['IN' => $suppressed_types],
          'target_contact_id' => $contact_id
      ], $last_activity_id);
      $this->assertEmpty($next_activity_id, "A system activity was generated after contract pause event though it's supposed to be suppressed");
    }
  }
}

<?php
/*-------------------------------------------------------------+
| SYSTOPIA Contract Extension                                  |
| Copyright (C) 2019 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Base class for contract changes. These are tracked changes to
 *  a contract, represented by an activity
 *
 * This new 'Change' concept is the replacement for the CRM_Contract_ModificationActivity
 *  and the CRM_Contract_Handlers
 */
abstract class CRM_Contract_Change {

  /**
   * Data representing the data. Will mostly be the activity data
   */
  protected $data = NULL;

  /**
   * Contract data (cached)
   */
  protected $contract = NULL;

  /**
   * List of known changes,
   *  activity_type_name => change class
   */
  protected static $type2class = [
    'Contract_Cancelled' => 'CRM_Contract_Change_Cancel',
  ];

  /**
   * List of known actions,
   *  activity_type_name => change class
   */
  protected static $action2class = [
      'cancel' => 'CRM_Contract_Change_Cancel',
  ];

  /**
   * List of activity_type_id => change class
   * Will be be populated on demand
   */
  protected static $_type_id2class = NULL;


  /**
   * CRM_Contract_Change constructor.
   * @param $data
   */
  protected function __construct($data) {
    $this->data = $data;
  }

  ################################################################################
  ##                          ABSTRACT FUNCTIONS                                ##
  ################################################################################

  /**
   * Apply the given change to the contract
   *
   * @throws Exception should anything go wrong in the execution
   */
  abstract public function execute();

  /**
   * Get a list of required fields for this type
   *
   * @return array list of required fields
   */
  abstract public function getRequiredFields();


  ################################################################################
  ##                           COMMON FUNCTIONS                                 ##
  ################################################################################

  /**
   * Derive/populate additional data
   */
  public function populateData() {}

  /**
   * Make sure that the data for this change is valid
   *
   * @throws Exception if the data is not valid
   */
  public function verifyData() {
    // simply check if all required fields are there
    // ...anything else needs to be checked in the specific class...
    $required_fields = $this->getRequiredFields();
    foreach ($required_fields as $required_field) {
      if (!isset($this->data[$required_field])) {
        throw new Exception("Parameter '{$required_field}' missing.");
      }
    }
  }

  /**
   * Get the contract ID
   */
  public function getContractID() {
    return $this->data['source_record_id'];
  }

  /**
   * Get the contract data
   */
  public function getContract() {
    $contract_id = $this->getContractID();
    if ($this->contract === NULL || $this->contract['id'] != $contract_id) {
      // (re)load contract
      try {
        $this->contract = civicrm_api3('Membership', 'getsingle', ['id' => $contract_id]);
      } catch (Exception $ex) {
        throw new Exception("Contract [{$contract_id}] not found!");
      }
      CRM_Contract_CustomData::labelCustomFields($contract);
    }
    return $this->contract;
  }

  /**
   * Update the contract with the given data
   *
   * @param $updates array changes: attribute->value
   * @throws CiviCRM_API3_Exception
   */
  public function updateContract($updates) {
    // make sure the ID is there
    $updates['id'] = $this->getContractID();

    // TODO: derive fields

    // make sure all fields are resolved
    CRM_Contract_CustomData::resolveCustomFields($updates);

    // finally: write through
    civicrm_api3('Membership', 'create', $updates);

    // and delete the cached contract data (if any)
    $this->contract = NULL;
  }

  /**
   * Save data to the DB (activity)
   */
  public function save() {
    // make sure all custom fields are transformed into the 'custom_[id]' notation
    CRM_Contract_CustomData::resolveCustomFields($this->data);

    // store via API
    $result = civicrm_api3('Activity', 'create', $this->data);

    // make sure we store the activity ID (if this is the first time)
    if (empty($this->data['id'])) {
      $this->data['id'] = $result['id'];
    }
  }

  /**
   * Check if this change is new, i.e. has not yet been saved to the DB
   */
  public function isNew() {
    return empty($this->data['id']);
  }

  /**
   * Set change status
   *
   * @param $status string valid activity status
   */
  public function setStatus($status) {
    $this->data['status_id'] = $status;
  }




  ################################################################################
  ##                           STATIC FUNCTIONS                                 ##
  ################################################################################

  /**
   * Get the class for the given activity type
   *
   * @param $activity_type int|string acitivity type ID or name
   * @return string class name
   */
  public static function getClassByActivityType($activity_type_id) {
    // check name -> class mapping first
    if (isset(self::$type2class[$activity_type_id])) {
      return self::$type2class[$activity_type_id];
    }

    // check action -> class mapping second
    if (isset(self::$action2class[$activity_type_id])) {
      return self::$action2class[$activity_type_id];
    }

    // then try ID -> class
    $type_id2class = self::getActivityTypeId2Class();
    if (isset($type_id2class[$activity_type_id])) {
      return $type_id2class[$activity_type_id];
    }

    // not found? not one of ours!
    return NULL;
  }

  /**
   * Get the list of activity type ID to class
   *
   * @return array activity_type_id => class name
   */
  public static function getActivityTypeId2Class() {
    if (self::$_type_id2class === NULL) {
      // populate on demand:
      self::$_type_id2class = [];
      $query = civicrm_api3('OptionValue', 'get', [
          'option_group_id' => 'activity_type',
          'name'            => ['IN' => array_keys(self::$type2class)],
          'return'          => 'value,name',
          'option.limit'    => 0,
          'sequential'      => 1]);
      foreach ($query['values'] as $entry) {
        if (isset(self::$type2class[$entry['name']])) {
          self::$_type_id2class[$entry['value']] = self::$type2class[$entry['name']];
        }
      }
    }
    return self::$_type_id2class;
  }

  /**
   * Get a change with data
   *
   * @param $data array data
   * @return CRM_Contract_Change change entity
   * @throws Exception if the change type couldn't be detected from the activity_type_id
   */
  public static function getChangeForData($data) {
    if (empty($data['activity_type_id'])) {
      throw new Exception("No activity_type_id given.");
    }

    $change_class = self::getClassByActivityType($data['activity_type_id']);
    if (empty($change_class)) {
      throw new Exception("Activity type ID '{$data['activity_type_id']}' is not a valid contract change type.");
    }

    // make sure we're using the descriptive indices, not the custom_[id] ones
    CRM_Contract_CustomData::labelCustomFields($data);

    // finally: create a change object on the data
    return new $change_class($data);
  }

}

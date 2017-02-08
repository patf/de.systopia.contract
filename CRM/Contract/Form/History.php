<?php
abstract class CRM_Contract_Form_History extends CRM_Core_Form{

  function preProcess(){
    $this->getParams();
    $this->validateStartStatus();
    parent::preProcess();
  }

  function getParams(){
    $this->id = CRM_Utils_Request::retrieve('id', 'Integer');
    if($this->id){
      $this->set('id', $this->id);
    }
    if(!$this->get('id')){
      CRM_Core_Error::fatal('Missing a membership ID');
    }
    try{
      $this->membership = civicrm_api3('Membership', 'getsingle', array('id' => $this->get('id')));
    }catch(Exception $e){
      CRM_Core_Error::fatal('Not a valid membership ID');
    }

    try{
      $CustomField = civicrm_api3('CustomField', 'getsingle', array('custom_group_id' => 'membership_payment', 'name' => 'membership_recurring_contribution'));
      $this->contributionRecurCustomField = 'custom_'.$CustomField['id'];
      if($this->membership[$this->contributionRecurCustomField]){
        $this->contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', array('id' => $this->membership[$this->contributionRecurCustomField]));
      }
    }catch(Exception $e){
      CRM_Core_Error::fatal('Could not find recurring contribution for this membership');
    }
  }

  function validateStartStatus(){
    $this->membershipStatus = civicrm_api3('MembershipStatus', 'getsingle', array('id' => $this->membership['status_id']));
    if(!in_array($this->membershipStatus['name'], $this->validStartStatuses)){
      CRM_Core_Error::fatal("You cannot run '{$this->action}' when the membership status is '{$this->membershipStatus['name']}'.");
    }
  }

  function buildQuickForm(){

    CRM_Utils_System::setTitle(ucfirst($this->action).' contract');

    if(in_array($this->action, array('resume', 'update', 'revive'))){

      // add fields for update (and similar) actions
      $alter = new CRM_Contract_AlterForm($this, $this->get('id'));
      $alter->addPaymentContractSelect2('contract_history_recurring_contribution');
    }
    elseif($this->action == 'cancel'){

      $this->add('text', 'contract_history_cancel_reason', ts('Cancellation reason'));

    }

    $this->addButtons(array(
        array('type' => 'cancel', 'name' => 'Cancel'),
        array('type' => 'submit', 'name' => ucfirst($this->action), 'isDefault' => true)
    ));

    $defaults['contract_history_recurring_contribution'] = $this->membership[$this->contributionRecurCustomField];
    $this->setDefaults($defaults);

    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  function postProcess(){


    $this->submitted = $this->exportValues();

    $this->updatedMembership = $this->membership;

    $this->updatedMembership['status_id'] = $this->endStatus;
    $this->updatedMembership['is_override'] = $this->membership['status_id'] == 'Current' ? 0 : 1;
    if(isset($this->submitted['contract_history_recurring_contribution'])){
      $this->updatedMembership[$this->contributionRecurCustomField] = $this->submitted['contract_history_recurring_contribution'];
    }

    civicrm_api3('Membership', 'create', $this->updatedMembership);

    $session = CRM_Core_Session::singleton();
    $this->activityParams = array(
      'source_record_id' => $this->id,
      'activity_type_id' => CRM_Contract_Utils_ActionProperties::getByClass($this)['activityType'],
      // 'activity_date_time' => // currently allowing this to be assigned automatically
      //TODO 'subject' =>
      //TODO 'details' =>
      'status_id' => 'Completed',
      //TODO (see issue 410 for details) 'medium_id' => //
      'source_contact_id'=> $session->getLoggedInContactID(),
      'target_id'=> $this->updatedMembership['contact_id'],
    );

    // optional activity params

    // add update activity info
    if(in_array($this->action, array('resume', 'update', 'revive'))){
      $this->getUpdateParams();
    }elseif($this->action == 'cancel'){
      $this->getCancelParams();
    }

    // add campaign params
    if(0){
      // $activityParams['campaign_id'] => // membership_campaign_id
    }
    $this->activityParams['options']['reload'] = 1;
    $activity = civicrm_api3('Activity', 'create', $this->activityParams);

  }

  function calcAnnualAmount($contributionRecur){
    $frequencyUnitTranslate = array(
      'day' => 365,
      'week' => 52,
      'month' => 12,
      'year' => 1
    );
    return $contributionRecur['amount'] * $frequencyUnitTranslate[$contributionRecur['frequency_unit']] / $contributionRecur['frequency_interval'];
  }

  function getUpdateParams(){
    $oldAnnualMembershipAmount = $this->calcAnnualAmount($this->contributionRecur);
    $newContributionRecur = civicrm_api3('ContributionRecur', 'getsingle', array('id' => $this->updatedMembership[$this->contributionRecurCustomField]));
    $newAnnualMembershipAmount = $this->calcAnnualAmount($newContributionRecur);
    $amountDelta = $newAnnualMembershipAmount - $oldAnnualMembershipAmount;

    $this->loadActivityFieldTranslations('contract_updates');

    $this->activityParams['custom_'.$this->translateActivityField['ch_annual']] = $newAnnualMembershipAmount;
    $this->activityParams['custom_'.$this->translateActivityField['ch_annual_diff']] = $amountDelta;
    $this->activityParams['custom_'.$this->translateActivityField['ch_frequency']] = 1;//TODO $this->contributionRecur['frequency_unit'];
    $this->activityParams['custom_'.$this->translateActivityField['ch_recurring_contribution']] = $this->updatedMembership[$this->contributionRecurCustomField];
    $this->activityParams['custom_'.$this->translateActivityField['ch_from_ba']] = 1;
    $this->activityParams['custom_'.$this->translateActivityField['ch_to_ba']] = 1;
  }

  function getCancelParams(){
    $this->loadActivityFieldTranslations('contract_cancellation');
    $this->activityParams['custom_'.$this->translateActivityField['contact_history_cancel_reason']] = $this->submitted['contract_history_cancel_reason']; //TODO make select
  }

  function loadActivityFieldTranslations($customGroup){
    $result = civicrm_api3('CustomField', 'get', array( 'sequential' => 1, 'custom_group_id' => $customGroup ));
    foreach($result['values'] as $v){
      $this->translateActivityField[$v['name']] = $v['id'];
    }
  }

  function getTemplateFileName(){
    return 'CRM/Contract/Form/History.tpl';
  }


}

<?php

require_once 'CRM/Core/Payment/BaseIPN.php';
require_once 'OgoneUtils.php';

class CRM_Core_Payment_OgoneIPN extends CRM_Core_Payment_BaseIPN {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  static protected $_mode = null;

  static function retrieve($name, $type, $object, $abort = true) {
    $value = CRM_Utils_Array::value($name, $object);
    if ($abort && $value === null) {
      CRM_Core_Error::debug_log_message("Could not find an entry for $name");
      echo "Failure: Missing Parameter {$name}<p>";
      exit();
    }
      
    if ($value) {
      if (!CRM_Utils_Type::validate($value, $type)) {
        CRM_Core_Error::debug_log_message("Could not find a valid entry for $name");
        echo "Failure: Invalid Parameter<p>";
        exit();
      }
    }
    return $value;
  }


  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    parent::__construct();
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * The function gets called when a new order takes place.
   * 
   *  @param  string  $status
   *  @param  array $privateData
   *  @param  string  $component
   *  @param  string  $amount
   *  @param  string  $transactionReference
   *
   *  @return void
   *
   */
  function newOrderNotify($status, $privateData, $component, $amount, $transactionReference) {

    $ids = $input = $params = array();

    $input['component'] = strtolower($component);

    $ids['contact'] = self::retrieve('contactID', 'Integer', $privateData, true);
    $ids['contribution'] = self::retrieve('contributionID', 'Integer', $privateData, true);

    if ( $input['component'] == "event" ) {
      $ids['event'] = self::retrieve('eventID', 'Integer', $privateData, true);
      $ids['participant'] = self::retrieve('participantID', 'Integer', $privateData, true);
      $ids['membership']  = null;
    } else {
      $ids['membership'] = self::retrieve('membershipID', 'Integer', $privateData, false);
      $ids['related_contact'] = self::retrieve('relatedContactID', 'Integer', $privateData, false);
      $ids['onbehalf_dupe_alert'] = self::retrieve('onBehalfDupeAlert', 'Integer', $privateData, false);
    }
    $ids['contributionRecur'] = $ids['contributionPage'] = null;

    // unset ids with value null in order to let validateData succeed
    foreach($ids as $key => $value) {
      if ($value == null) {
        unset($ids[$key]);
      }
    }
    
    if (!$this->validateData($input, $ids, $objects)) {
      CRM_Core_Error::debug_log_message("New order data not valid");
      echo "Failure: new order data not valid<p>";
      return false;
    }

    // make sure the invoice is valid and matches what we have in the contribution record
    // NOTE: took this out since invoiceID is not passed in privateData.
    // $input['invoice'] = $privateData['invoiceID'];
    $input['newInvoice'] = $transactionReference;
    $contribution =& $objects['contribution'];
    $input['trxn_id'] =	$transactionReference;

    // NOTE: took this out since invoiceID is not passed in privateData.
    /*
    if ($contribution->invoice_id != $input['invoice']) {
      CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
      echo "Failure: Invoice values dont match between database and IPN request<p>";
      return;
    }
    */

    $input['amount'] = $amount;

    if ( $contribution->total_amount != $input['amount'] ) {
      CRM_Core_Error::debug_log_message( "Amount values dont match between database and IPN request" );
      echo "Failure: Amount values dont match between database and IPN request. ".$contribution->total_amount."/".$input['amount']."<p>";
      return;
    }

    require_once 'CRM/Core/Transaction.php';
    $transaction = new CRM_Core_Transaction( );

    // check if contribution is already completed, if so we ignore this ipn
    if ( $contribution->contribution_status_id == 1 ) {
      CRM_Core_Error::debug_log_message( "Returning since contribution has already been handled" );
      echo "Success: Contribution has already been handled<p>";
      return true;
    } 

//CRM_Core_Error::debug_var('c', $contribution);
    $contribution->save();

    if ($status == '0' || $status == '2') {
      // Order is incomplete or invalid (status=0) or authorization refused (status=2)
      return $this->failed($objects, $transaction);
    } elseif ($status == '1') {
      // Order is cancelled (status=1)
      return $this->cancelled($objects, $transaction);
    } elseif ($status == '52' || $status == '92') {
        // Order authorization not known (status=52) or payment uncertain (status=92)
        return $this->pending($objects, $transaction);
    } else {
      $this->completeTransaction ($input, $ids, $objects, $transaction);
      return true;
    }
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   */
  static function &singleton($mode, $component, &$paymentProcessor) {
    if (self::$_singleton === null) {
      self::$_singleton = new CRM_Core_Payment_OgoneIPN($mode, $paymentProcessor);
    }
    return self::$_singleton;
  }

  /**
   * The function returns the component (Event/Contribute..) and whether it is Test or not
   *
   * @param array   $privateData    contains the name-value pairs of transaction related data
   *
   * @return array context of this call (test, component, payment processor id)
   * @static
   */
  static function getContext($privateData)	{
    require_once 'CRM/Contribute/DAO/Contribution.php';

    $component = null;
    $isTest = null;

    $contributionID = $privateData['contributionID'];
    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionID;

    if (!$contribution->find(true)) {
      CRM_Core_Error::debug_log_message("Could not find contribution record: $contributionID");
      echo "Failure: Could not find contribution record for $contributionID<p>";
      exit();
    }
    if (stristr($contribution->source, ts('Online Event Registration'))) {
      $component = 'event';
    }
    $isTest = $contribution->is_test;

    $duplicateTransaction = 0;
    if ($contribution->contribution_status_id == 1) {
      //contribution already handled. (some processors do two notifications so this could be valid)
      $duplicateTransaction = 1;
    }

    if ($contribution->contribution_page_id) {
      $component = 'contribute';
//        CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
//        echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
//        exit();

      // get the payment processor id from contribution page
      //$paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', $contribution->contribution_page_id, 'payment_processor_id');
      $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', $contribution->contribution_page_id, 'payment_processor');
    }
    else {
      $eventID = $privateData['eventID'];

      if (!$eventID) {
        CRM_Core_Error::debug_log_message("Could not find event ID");
        echo "Failure: Could not find eventID<p>";
        exit();
      }

      // we are in 
      // make sure event exists and is valid
      require_once 'CRM/Event/DAO/Event.php';
      $event = new CRM_Event_DAO_Event();
      $event->id = $eventID;
      if (!$event->find(true)) {
        CRM_Core_Error::debug_log_message("Could not find event: $eventID");
        echo "Failure: Could not find event: $eventID<p>";
        exit();
      }

      // get the payment processor id from contribution page
      //$paymentProcessorID = $event->payment_processor_id;
      $paymentProcessorID = $event->payment_processor;
    }

    if (!$paymentProcessorID) {
      CRM_Core_Error::debug_log_message("Could not find payment processor for contribution record: $contributionID");
      echo "Failure: Could not find payment processor for contribution record: $contributionID<p>";
      exit();
    }

    return array($isTest, $component, $paymentProcessorID, $duplicateTransaction);
  }


  /**
   * This method handles the response that will be invoked (from OgoneNotify.php) every time
   * a notification or request is sent by the Ogone Server.
   *
   */
  static function main($qfKey) {

    require_once 'CRM/Utils/Request.php';
    $config = CRM_Core_Config::singleton();

    //unset($ogoneParams['qfKey']);
    $ogoneParams = array();
    foreach($_GET as $param => $val) {
      $ogoneParams[$param] = $val;
    }
    $shaSign = $ogoneParams['SHASIGN'];
    unset($ogoneParams['SHASIGN']); 

    // remove qfKey from list of parameters created by Ogone
    unset($ogoneParams['qfKey']);

    // decode orderID
    $order_array = explode('-', $ogoneParams['orderID']);
    //$privateData['invoiceID'] = (isset($order_array[0])) ? $order_array[0] : '';
    $privateData['contactID'] = (isset($order_array[0])) ? $order_array[0] : '';
    $privateData['contributionID'] = (isset($order_array[1])) ? $order_array[1] : '';
    $privateData['contributionTypeID'] = (isset($order_array[2])) ? $order_array[2] : '';
    $privateData['eventID'] = (isset($order_array[3])) ? $order_array[3] : '';
    $privateData['participantID'] = (isset($order_array[4])) ? $order_array[4] : '';
    $privateData['membershipID'] = (isset($order_array[5])) ? $order_array[5] : '';

//CRM_Core_Error::debug_var('privateData', $privateData);

    list($mode, $component, $paymentProcessorID, $duplicateTransaction) = self::getContext($privateData);
    $mode = $mode ? 'test' : 'live';
    $paymentProcessorID = intval($paymentProcessorID);

//CRM_Core_Error::debug_var('mode', $mode);
//CRM_Core_Error::debug_var('component', $component);
//CRM_Core_Error::debug_var('paymentProcessorID', $paymentProcessorID);
//CRM_Core_Error::debug_var('duplicateTransaction', $duplicateTransaction);

    //require_once 'CRM/Core/BAO/PaymentProcessor.php';
    require_once 'CRM/Financial/BAO/PaymentProcessor.php';
    //$paymentProcessor = CRM_Core_BAO_PaymentProcessor::getPayment($paymentProcessorID, $mode);
    $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($paymentProcessorID, $mode);

//CRM_Core_Error::debug_var('paymentProcessor', $paymentProcessor);

    $shaCalc = calculateSHA1($ogoneParams, $paymentProcessor['signature']); 
    if (strcmp($shaSign, $shaCalc)) {
      CRM_Core_Error::debug_log_message("Failure: SHA1-out signature does not match calculated value. Request parameters might be forged.");
      exit();
    }

    CRM_Core_Error::debug_log_message("SHA1-out signature matches.");

    // Process the transaction.
    if ($duplicateTransaction == 0) {
      // Process the transaction.
      $ipn=& self::singleton($mode, $component, $paymentProcessor);
      $ipn->newOrderNotify($ogoneParams['STATUS'], $privateData, $component, $ogoneParams['amount'], $ogoneParams['PAYID']);
    }
 
    // Redirect our users to the correct url.
    if ($ogoneParams['STATUS'] == '2' || $ogoneParams['STATUS'] == '1' || $ogoneParams['STATUS'] == '0') {
      // Order is declined (status = 2), cancelled (status = 1) or invalid (status = 0)
      CRM_Core_Error::debug_log_message("Ogone payment is declined, cancelled or invalid.");

      if ($component == "event") {
        $finalURL = CRM_Utils_System::url('civicrm/event/confirm', "reset=1&cc=fail&participantId={$privateData['participantID']}", false, null, false);
      } elseif ($component == "contribute") {
        $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_Main_display=1&cancel=1&qfKey={$qfKey}", false, null, false);
      }
    } else {      
      if ($component == "event") {
        $finalURL = CRM_Utils_System::url('civicrm/event/register', "_qf_ThankYou_display=1&qfKey={$qfKey}", false, null, false);
      }
      elseif ($component == "contribute") {
        $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', "_qf_ThankYou_display=1&qfKey={$qfKey}", false, null, false);
      }
    }
    CRM_Utils_System::redirect( $finalURL );
  }
}

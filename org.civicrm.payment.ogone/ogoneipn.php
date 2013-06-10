<?php
require_once 'OgoneUtils.php';
class ogoneipn extends CRM_Core_Payment_BaseIPN {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  private static $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  protected static $_mode = null;

  static function retrieve($name, $type, $object, $abort = true) {
    $value = CRM_Utils_Array::value($name, $object);
    if ($abort && $value === null) {
      CRM_Core_Error::debug_log_message("Could not find an entry for $name");
      echo "Failure: Missing Parameter - " . $name . "<p>";
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
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   */
  static function &singleton($mode, $component, &$paymentProcessor) {
    if (self::$_singleton === null) {
      self::$_singleton = new ogoneipn($mode, $paymentProcessor);
    }
    return self::$_singleton;
  }

  /**
   * This method is handles the response that will be invoked by the
   * notification or request sent by the payment processor.
   * hex string from paymentexpress is passed to this function as hex string.
   */
  static function main($qfKey){
    $config = CRM_Core_Config::singleton();

    unset($ogoneParams['qfKey']);
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
    $privateData['relatedContactID'] = (isset($order_array[6])) ? $order_array[6] : '';
    $privateData['onBehalfDupeAlert'] = (isset($order_array[7])) ? $order_array[7] : '';

    list($mode, $component, $paymentProcessorID, $duplicateTransaction) = self::getContext($privateData);
    $mode = $mode ? 'test' : 'live';

    $paymentProcessor = CRM_core_BAO_PaymentProcessor::getPayment($paymentProcessorID, $mode);

    $shaCalc = calculateSHA1($ogoneParams, $paymentProcessor['signature']);
    if (strcmp($shaSign, $shaCalc)) {
      CRM_Core_Error::debug_log_message("Failure: SHA1-out signature does not match calculated value. Request parameters might be forged.");
      exit();
    }

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


  function &error($errorCode = null, $errorMessage = null) {
    $e = & CRM_Core_Error::singleton();
    if ($errorCode) {
      $e->push($errorCode, 0, null, $errorMessage);
    }
    else {
      $e->push(9001, 0, null, 'Unknowns System Error.');
    }
    return $e;
  }

  /**
   * The function gets called when a new order takes place.
   *
   * @param array  $privateData  contains the CiviCRM related data
   * @param string $component    the CiviCRM component
   * @param array  $borikaData contains the Merchant related data
   *
   * @return void
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

    // lets replace invoice_id with Ogone PAYID (transaction reference).
    $contribution->invoice_id = $input['newInvoice'];

    $input['amount'] = $amount;

    if ( $contribution->total_amount != $input['amount'] ) {
      CRM_Core_Error::debug_log_message( "Amount values dont match between database and IPN request" );
      echo "Failure: Amount values dont match between database and IPN request. ".$contribution->total_amount."/".$input['amount']."<p>";
      return;
    }

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
   * The function returns the component(Event/Contribute..)and whether it is Test or not
   *
   * @param array   $privateData    contains the name-value pairs of transaction related data
   *
   * @return array context of this call (test, component, payment processor id)
   * @static
   */
  static function getContext($privateData){

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

    if (stristr($contribution->source, 'Online Contribution')) {
      $component = 'contribute';
    }
    elseif (stristr($contribution->source, 'Online Event Registration')) {
      $component = 'event';
    }
    $isTest = $contribution->is_test;

    $duplicateTransaction = 0;
    if ($contribution->contribution_status_id == 1) {
      //contribution already handled. (some processors do two notifications so this could be valid)
      $duplicateTransaction = 1;
    }

    if ($component == 'contribute') {
      if (!$contribution->contribution_page_id) {
        CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
        echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
        exit();
      }

      // get the payment processor id from contribution page
      $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', $contribution->contribution_page_id, 'payment_processor_id');
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
      $event = new CRM_Event_DAO_Event();
      $event->id = $eventID;
      if (!$event->find(true)) {
        CRM_Core_Error::debug_log_message("Could not find event: $eventID");
        echo "Failure: Could not find event: $eventID<p>";
        exit();
      }

      // get the payment processor id from contribution page
      $paymentProcessorID = $event->payment_processor_id;
    }

    if (!$paymentProcessorID) {
      CRM_Core_Error::debug_log_message("Could not find payment processor for contribution record: $contributionID");
      echo "Failure: Could not find payment processor for contribution record: $contributionID<p>";
      exit();
    }

    return array($isTest, $component, $paymentProcessorID, $duplicateTransaction);
  }
}

?>

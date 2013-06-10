<?php
require_once 'OgoneUtils.php';
class com_webaccessglobal_ogone extends CRM_Core_Payment {

  static protected $_mode = null;
  static protected $_params = array();

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {

    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Ogone');
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error = array();

   if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "PSPID" is not set in the Administer CiviCRM Payment Processor.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return null;
    }
  }

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Submit an Automated Recurring Billing subscription
   *
   * @param  array $params assoc array of input parameters for this transaction
   * @return array the result in a nice formatted array (or an error object)
   * @public
   */
  function doTransferCheckout(&$params, $component){
    $config = CRM_Core_Config::singleton();

    if ($component != 'contribute' && $component != 'event') {
      CRM_Core_Error::fatal(ts('Component is invalid'));
    }

    // Start building our parameters
    // - Algemene parameters
    //    PSPID
    //    orderID
    //    amount
    //    currency
    //    language
    //    CN
    //    EMAIL
    //    ownerZIP
    //    owneraddress
    //    ownercty
    //    ownertown
    //    ownertelno
    // - Controle voor betaling
    //    SHASign
    // - Feedback na betaling
    //    accepturl
    //    declineurl
    //    exceptionurl
    //    cancelurl
    //
    // In order to calculate SHA1 hash:
    //  * parameters sorted in alphabetical order,
    //  * parameter names in uppercase
    //  * name=value pairs separated with SHA passphrase (defined in Ogone > Technical info settings)
    //

    $OgoneParams['PSPID'] = $this->_paymentProcessor['user_name'];
    //TODO: from Ogone tech spec
    //  Although our system can accept up to 30 characters, the norm for most acquirers is 10 or 12.
    //  The exact accepted length and data validation format depend on the acquirer/bank.
    //  If the orderID does not comply to the ref2 rules set by the acquirer, we’ll send our PAYID as ref2 to the acquirer instead.
    //  Avoid using spaces or special characters in the orderID.
    //
    //  We need to encode following values in orderID to allow further processing in OgoneIPN.php
    //    getContext() in OgoneIPN.php
    //      contributionId
    //      eventID
    //    newOrderNotify() in OgoneIPN.php
    //      contactID
    //      contributionId
    //      eventID
    //      participantID
    //      membershipID
    //      invoiceID - SKIP THIS AND MODIFY newOrderNotify() to ignore this.
    //        invoiceID is too long and causes Ogone orderid to exceed its maximum value of 30 chars.
    //
    $orderID = array(
      //      $params['invoiceID'],
      $params['contactID'],
      $params['contributionID'],
      $params['contributionTypeID'],
      $params['eventID'],
      $params['participantID'],
      //      $params['membershipId'],
    );
    $membershipID = CRM_Utils_Array::value('membershipID', $params);
    if ($membershipID) {
      $orderID[] = $membershipID;
    }
    $relatedContactID = CRM_Utils_Array::value('related_contact', $params);
    if ($relatedContactID) {
      $orderID[] = $relatedContactID;

      $onBehalfDupeAlert = CRM_Utils_Array::value('on_behalf_dupe_alert', $params);
      if ($onBehalfDupeAlert) {
        $orderID[] = $onBehalfDupeAlert;
      }
    }

    $OgoneParams['orderID'] = implode('-', $orderID);
    $OgoneParams['amount'] = sprintf("%d", (float)$params['amount'] * 100);
    $OgoneParams['currency'] = 'EUR';
    if (isset($params['preferred_language'])) {
      $OgoneParams['language'] = $params['preferred_language'];
    } else {
      $OgoneParams['language'] = 'nl_NL';
    }
    if (isset($params['first_name']) || isset($params['last_name'])) {
      $OgoneParams['CN'] = $params['first_name'] . ' ' . $params['last_name'];
    }
    if (isset($params['email'])) {
      $OgoneParams['EMAIL'] = $params['email'];
    }
    if (isset($params['postal_code-1'])) {
      $OgoneParams['ownerZIP'] = $params['postal_code-1'];
    }
    if (isset($params['street_address-1'])) {
      $OgoneParams['owneraddress'] = $params['street_address-1'];
    }
    if (isset($params['country-1'])) {
      $OgoneParams['ownercty'] = $params['country-1'];
    }
    if (isset($params['city-1'])) {
      $OgoneParams['ownertown'] = $params['city-1'];
    }
    if (isset($params['phone-1'])) {
      $OgoneParams['ownertelno'] = $params['phone'];
    }

    $notifyURL = CRM_Utils_System::url('civicrm/payment/ipn', "processor_name=Ogone", false, null, false);
    $notifyURL .= "?qfKey=" . $params['qfKey'];
    $OgoneParams['accepturl'] = $notifyURL;
    $OgoneParams['declineurl'] = $notifyURL;
    $OgoneParams['exceptionurl'] = $notifyURL;
    $OgoneParams['cancelurl'] = $notifyURL;

    $shaSign = calculateSHA1($OgoneParams, $this->_paymentProcessor['password']);
    $OgoneParams['SHASign'] = $shaSign;

    //CRM_Core_Error::debug_var('OgoneParams', $OgoneParams);

    // Allow further manipulation of the arguments via custom hooks ..
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $OgoneParams);

    // Build our query string;
    $query_string = '';
    foreach ($OgoneParams as $name => $value) {
      $query_string .= $name . '=' . $value . '&';
    }

    // Remove extra &
    $query_string = rtrim($query_string, '&');

    // Redirect the user to the payment url.
    CRM_Utils_System::redirect($this->_paymentProcessor['url_site'] . '?' . $query_string);

    exit();

  }

  /**
   * Get the value of a field if set
   *
   * @param string $field the field
   * @return mixed value of the field, or empty string if the field is
   * not set
   */
  function _getParam($field) {
    return CRM_Utils_Array::value($field, $this->_params, '');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null) {
      self::$_singleton[$processorName] = new com_webaccessglobal_ogone($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
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
   * Set a field to the specified value.  Value must be a scalar (int,
   * float, string, or boolean)
   *
   * @param string $field
   * @param mixed $value
   * @return bool false if value is not a scalar, true if successful
   */
  function _setParam($field, $value) {
    if (!is_scalar($value)) {
      return false;
    }
    else {
      $this->_params[$field] = $value;
    }
  }

  /**
   * Handle return response from payment processor
   */
  function handlePaymentNotification() {
    require_once 'ogoneipn.php';
    $ogoneIPN = new ogoneipn($this->_mode, $this->_paymentProcessor);
    $ogoneIPN->main($_GET);
  }

}

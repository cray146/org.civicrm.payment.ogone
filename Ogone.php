<?php

require_once 'CRM/Core/Payment.php';
require_once 'OgoneUtils.php';

class org_civicrm_payment_ogone extends CRM_Core_Payment {
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
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

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct( $mode, &$paymentProcessor ) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Ogone');
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
  static function &singleton( $mode, &$paymentProcessor ) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null ) {
      self::$_singleton[$processorName] = new org_civicrm_payment_ogone($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig( ) {
    $config = CRM_Core_Config::singleton();
    $error = array();
    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "PSPID" is not set in the Administer CiviCRM Payment Processor.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    } else {
      return NULL;
    }
  }

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Sets appropriate parameters for checking out to Ogone
   *
   * @param array $params  name value pair of contribution datat
   *
   * @return void
   * @access public
   *
   */
  function doTransferCheckout($params, $component) {
    $config = CRM_Core_Config::singleton();

CRM_Core_Error::debug_var('doTransferCheckOut - params', $params);
CRM_Core_Error::debug_var('doTransferCheckOut - component', $component);

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
      CRM_Utils_Array::value('contactID', $params),
      CRM_Utils_Array::value('contributionID', $params),
      CRM_Utils_Array::value('contributionTypeID', $params),
      CRM_Utils_Array::value('eventID', $params),
      CRM_Utils_Array::value('participantID', $params),
      CRM_Utils_Array::value('membershipID', $params)
    );
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

    $notifyURL = $config->userFrameworkResourceURL . "extern/OgoneNotify.php";
    $notifyURL .= "?qfKey=" . $params['qfKey'];
    $OgoneParams['accepturl'] = $notifyURL;
    $OgoneParams['declineurl'] = $notifyURL;
    $OgoneParams['exceptionurl'] = $notifyURL;
    $OgoneParams['cancelurl'] = $notifyURL;

    // ogone was failing with "unknown order/1/s/" due to non ascii7 char. This is an ugly workaround
    foreach ($OgoneParams as &$str) {
      $from = 'àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ';
      $to   = 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY';     
      $keys = array();
      $values = array();
      preg_match_all('/./u', $from, $keys);
      preg_match_all('/./u', $to, $values);
      $mapping = array_combine($keys[0], $values[0]);
      $str= strtr($str, $mapping);
    }
    $shaSign = calculateSHA1($OgoneParams, $this->_paymentProcessor['password']);
    $OgoneParams['SHASign'] = $shaSign;

//CRM_Core_Error::debug_var('doTransferCheckout - OgoneParams', $OgoneParams);
    
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
}



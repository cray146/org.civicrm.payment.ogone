<?php

session_start( );

require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';
require_once 'CRM/Utils/Request.php';

$config = CRM_Core_Config::singleton();

// Change this to fit your processor name.
require_once 'OgoneIPN.php';

static $store = null;
$qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $store, false, null, 'GET');

// Change this to match your payment processor class.
CRM_Core_Payment_OgoneIPN::main($qfKey);

<?php

session_start( );

require_once '../civicrm.config.php';
require_once 'CRM/Core/Config.php';
require_once 'CRM/Utils/Request.php';

$config = CRM_Core_Config::singleton();

require_once 'CRM/Core/Extensions/Extension.php';
$ext = new CRM_Core_Extensions_Extension( 'org.civicrm.payment.ogone' );
if ( !empty( $ext->path ) ) {
    require_once $ext->path . '/OgoneIPN.php';
}

static $store = null;
$qfKey = CRM_Utils_Request::retrieve('qfKey', 'String', $store, false, null, 'GET');

// Change this to match your payment processor class.
CRM_Core_Payment_OgoneIPN::main($qfKey);

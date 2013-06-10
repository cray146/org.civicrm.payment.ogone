<?php

require_once 'ogone.civix.php';
require_once 'ogonepayment.php';

/**
 * Implementation of hook_civicrm_config
 */
function ogone_civicrm_config(&$config) {
  _ogone_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function ogone_civicrm_xmlMenu(&$files) {
  _ogone_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function ogone_civicrm_install() {
  return _ogone_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function ogone_civicrm_uninstall() {

$ogonID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessorType', 'name', 'id', 'Ogone');
if($ogonID){
  CRM_Core_DAO::executeQuery("DELETE  FROM civicrm_payment_processor where payment_processor_type_id =". $ogonID);
  $affectedRows = mysql_affected_rows();

  if($affectedRows)
    CRM_Core_Session::setStatus("Ogone Payment Processor Message:
    <br />Entries for Ogone Payment Processor are now Deleted!
    <br />");
}
  return _ogone_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function ogone_civicrm_enable() {

$ogonID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessorType', 'name', 'id', 'Ogone');
if($ogonID){
 CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor SET is_active = 1 where payment_processor_type_id =".$ogonID);
  $affectedRows = mysql_affected_rows();

  if($affectedRows)
    CRM_Core_Session::setStatus("Ogone Payment Processor Message:
    <br />Entries for Ogone Payment Processor are now Enabled!
    <br />");
}
  return _ogone_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function ogone_civicrm_disable() {
$ogonID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessorType', 'name', 'id', 'Ogone');
CRM_Core_Error::debug('$ogonID' , $ogonID);
if($ogonID){
 CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor SET is_active = 0 where payment_processor_type_id =".$ogonID);
  $affectedRows = mysql_affected_rows();

  if($affectedRows)
    CRM_Core_Session::setStatus("Ogone Payment Processor Message:
    <br />Entries for Ogone Payment Processor are now Disabled!
    <br />");
}
  return _ogone_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function ogone_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _ogone_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function ogone_civicrm_managed(&$entities) {
$entities[] = array(
    'module' => 'org.civicrm.payment.ogone',
    'name' => 'Ogone',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'Ogone',
      'title' => 'Ogone',
      'description' => 'Ogone Payment Processor',
      'class_name' => 'org.civicrm.payment.ogone',
      'billing_mode' => 'notify',
      'user_name_label' => 'PSPID',
      'password_label' => 'SHA1-IN Passphrase',
      'signature_label' => 'SHA1-OUT Passphrase',
      'subject_label' => 'Merchant ID',
      'url_site_default' => 'https://secure.ogone.com/ncol/prod/orderstandard.asp',
      'url_site_test_default' => 'https://secure.ogone.com/ncol/test/orderstandard.asp',
      'payment_type' => 1,
    ),
  );
  return _ogone_civix_civicrm_managed($entities);
}

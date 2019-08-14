<?php

$installer = $this;
/* @var $installer Mage_Core_Model_Resource_Setup */
$installer->startSetup();

Mage::log('PayVector installer script started');

try
{
	// try to run the installation script
	$installer->run("
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_failed_hosted_payment');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_failed_threed_secure');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_paid');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_pending');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_pending_hosted_payment');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_pending_threed_secure');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_refunded');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_voided');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_preauth');
	DELETE FROM `{$installer->getTable('sales_order_status')}` WHERE (`status`='irc_collected');
	
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_failed_hosted_payment', 'PayVector - Failed Payment');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_failed_threed_secure', 'PayVector - Failed 3D Secure');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_paid', 'PayVector - Successful Payment');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_pending', 'PayVector - Pending Hosted Payment');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_pending_hosted_payment', 'PayVector - Pending Hosted Payment');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_pending_threed_secure', 'PayVector - Pending 3D Secure');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_refunded', 'PayVector - Payment Refunded');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_voided', 'PayVector - Payment Voided');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_preauth', 'PayVector - PreAuthorized');
	INSERT INTO `{$installer->getTable('sales_order_status')}` (`status`, `label`) VALUES ('irc_collected', 'PayVector - Payment Collected');
	");

	$installer->run("
		DROP TABLE IF EXISTS {$installer->getTable('tpg/gatewayentrypoints')};
		CREATE TABLE {$installer->getTable('tpg/gatewayentrypoints')} (
			`gateway_entry_point_object` LONGTEXT NOT NULL DEFAULT '',
			`date_time_processed` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
		)
	");

	$installer->run("
		INSERT INTO {$installer->getTable('tpg/gatewayentrypoints')} VALUES(
			'PlaceHolder',
			0
		);
	");
}
catch(Exception $exc)
{
	Mage::log("Error during script installation: ". $exc->__toString());
}

Mage::log('PayVector installer script ended');

$installer->endSetup();
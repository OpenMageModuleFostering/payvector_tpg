<?php

$installer = $this;
$installer->startSetup();

$table = $installer->getConnection()->newTable($installer->getTable('tpg/gatewayentrypoints'))
	->addColumn('gateway_entry_point_object', Varien_Db_Ddl_Table::TYPE_TEXT, null, array(
			'nullable' => false,
			'primary' => true
		))
	->addColumn('date_time_processed', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
		'nullable' => false
		));

$installer->getConnection()->createTable($table);
$installer->endSetup();
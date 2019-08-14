<?php

class PayVector_Tpg_Model_Resource_Gatewayentrypoints extends Mage_Core_Model_Resource_Db_Abstract
{
	protected function _construct()
	{
		$this->_init('tpg/gatewayentrypoints', 'gateway_entry_point_object');
	}
}
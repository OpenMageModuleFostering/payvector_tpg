<?php

class PayVector_Tpg_Model_Gatewayentrypoints extends Mage_Core_Model_Mysql4_Abstract
{
	protected function _construct()
	{
		$this->_init('tpg/gatewayentrypoints', 'gateway_entry_point_object');
	}

	public function saveEntryPoints($gatewayEntryPointListXML, $dateTimeProcessed)
	{
		$newData = array(
			'gateway_entry_point_object' => $gatewayEntryPointListXML,
			'date_time_processed'  => $dateTimeProcessed
		);

		$this->_getWriteAdapter()->update($this->getMainTable(), $newData);
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getEntryPoints()
	{
		$offsetTimestamp = Mage::getSingleton('core/date')->gmtTimestamp() - 600;
		$offsetDateTime = date('Y-m-d H:i:s', $offsetTimestamp);
		$select = (string) $this->_getReadAdapter()->select()->from($this->getMainTable(), 'gateway_entry_point_object')->where('date_time_processed >= ?', $offsetDateTime);

		$query = $this->_getReadAdapter()->query($select);
		$query->execute();
		return $query->fetchColumn();
	}
}
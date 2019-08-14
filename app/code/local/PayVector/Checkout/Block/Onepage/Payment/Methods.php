<?php

/**
 * One page checkout status
 *
 * @category Mage
 * @package  Mage_Checkout
 * @author   Magento Core Team <core@magentocommerce.com>
 */
class PayVector_Checkout_Block_Onepage_Payment_Methods extends Mage_Checkout_Block_Onepage_Payment_Methods
{
	/**
	 * Override the base function - by default the PayVector payment option will be selected
	 *
	 * @return mixed
	 */
	public function getSelectedMethodCode()
	{
		$method = false;
		$model = Mage::getModel('tpg/direct');

		if($this->getQuote()->getPayment()->getMethod())
		{
			$method = $this->getQuote()->getPayment()->getMethod();
		}

		/*else
		{
			// force the current payment to be selected
			if($model)
			{
				$method = 'tpg';
			}
		}*/

		return $method;
	}
}

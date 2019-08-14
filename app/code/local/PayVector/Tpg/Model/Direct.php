<?php

include("Common/ThePaymentGateway/PaymentSystem.php");
include_once("Common/PaymentFormHelper.php");
include("Common/ISOCurrencies.php");
include("Common/ISOCountries.php");
include("Source/HashMethod.php");

class PayVector_Tpg_Model_Direct extends Mage_Payment_Model_Method_Abstract
{
	/**
	 * unique internal payment method identifier
	 *
	 * @var string [a-z0-9_]
	 */
	protected $_code = 'tpg';
	protected $_formBlockType = 'tpg/form';
	protected $_infoBlockType = 'tpg/info';

	protected $_isGateway = true;
	protected $_canAuthorize = true;
	protected $_canCapture = true;
	protected $_canCapturePartial = false;
	protected $_canRefund = true;
	protected $_canVoid = true;
	protected $_canUseInternal = true;
	protected $_canUseCheckout = true;
	protected $_canUseForMultishipping = true;
	protected $_canSaveCc = false;

	/**
	 * Assign data to info model instance
	 *
	 * @param   mixed $data
	 * @return  Mage_Payment_Model_Info
	 */
	public function assignData($data)
	{
		if(!($data instanceof Varien_Object))
		{
			$data = new Varien_Object($data);
		}
		$info = $this->getInfoInstance();
		$info->setCcOwner($data->getCcOwner())
			->setCcLast4(substr($data->getCcNumber(), -4))
			->setCcNumber($data->getCcNumber())
			->setCcCid($data->getCcCid())
			->setCcExpMonth($data->getCcExpMonth())
			->setCcExpYear($data->getCcExpYear())
			->setCcSsStartMonth($data->getCcSsStartMonth())
			->setCcSsStartYear($data->getCcSsStartYear())
			->setCcSsIssue($data->getCcSsIssue());

		return $this;
	}

	/**
	 * Validate payment method information object
	 *
	 * @param   Mage_Payment_Model_Info $info
	 * @return  Mage_Payment_Model_Method_Abstract
	 */
	public function validate()
	{
		// NOTE : cancel out the core Magento validator functionality, the payment gateway will overtake this task
		return $this;
	}

	/**
	 * Authorize - core Mage pre-authorization functionality
	 *
	 * @param   Varien_Object $orderPayment
	 * @return  Mage_Payment_Model_Method_Abstract
	 */
	public function authorize(Varien_Object $payment, $amount)
	{
		$error = false;
		//if this is a cross reference transaction then skip mode checking as it must be Direct/API
		if(isset($_POST['payment']['payment_type']) && $_POST['payment']['payment_type'] === "stored_card")
		{
			$paymentAction = $this->getConfigData('payment_action');
			if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE_CAPTURE)
			{
				$szTransactionType = "SALE";
			}
			else if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE)
			{
				$szTransactionType = "PREAUTH";
			}
			else
			{
				Mage::throwException('Unknown payment action: ' . $paymentAction);
			}
			$error = $this->_runCrossReferenceTransaction($payment, $szTransactionType, $amount, true);
		}
		else
		{
			$mode = $this->getConfigData('mode');
			// TODO : need to finish for non Direct API methods
			switch($mode)
			{
				case PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_DIRECT_API:
					$error = $this->_runTransaction($payment, $amount);
					break;
				case PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_HOSTED_PAYMENT_FORM:
					$error = $this->_runHostedPaymentTransaction($payment, $amount);
					break;
				case PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_TRANSPARENT_REDIRECT:
					$error = $this->_runTransparentRedirectTransaction($payment, $amount);
					//Mage::throwException('TR not supported');
					break;
				default:
					Mage::throwException('Invalid payment type: ' . $this->getConfigData('mode'));
					break;
			}
		}
		if($error)
		{
			Mage::throwException($error);
		}

		return $this;
	}

	/**
	 * Capture payment - immediate settlement payments
	 *
	 * @param   Varien_Object $payment
	 * @return  Mage_Payment_Model_Method_Abstract
	 */
	public function capture(Varien_Object $payment, $amount)
	{
		$error = false;
		$session = Mage::getSingleton('checkout/session');
		$mode = $this->getConfigData('mode');
		$nVersion = $this->getVersion();
		if($amount <= 0)
		{
			Mage::throwException(Mage::helper('paygate')->__('Invalid amount for authorization.'));
		}
		else
		{
			if($session->getThreedsecurerequired())
			{
				$md = $session->getMd();
				$pares = $session->getPares();
				$session->setThreedsecurerequired(null);
				$this->_run3DSecureTransaction($payment, $pares, $md);

				return $this;
			}
			if($session->getRedirectedPayment())
			{
				$szStatusCode = $session->getStatuscode();
				$szMessage = $session->getMessage();
				$szPreviousStatusCode = $session->getPreviousstatuscode();
				$szPreviousMessage = $session->getPreviousmessage();
				$szOrderID = $session->getOrderid();
				$szCrossReference = $session->getCrossReference();
				// check whether it is a hosted payment or a transparent redirect action
				$boIsHostedPaymentAction = $session->getIshostedpayment();
				$session->setRedirectedPayment(null);
				$session->setIshostedpayment(null);
				$this->_runRedirectedPaymentComplete($payment, $boIsHostedPaymentAction, $szStatusCode, $szMessage, $szPreviousStatusCode, $szPreviousMessage, $szOrderID, $szCrossReference);

				return $this;
			}
			if($session->getIsCollectionCrossReferenceTransaction())
			{
				// do a CrossReference transaction
				$error = $this->_runCrossReferenceTransaction($payment, "COLLECTION", $amount);
			}
			else
			{
				// fresh payment request
				$session->setThreedsecurerequired(null)
					->setRedirectedPayment(null)
					->setIshostedpayment(null)
					->setHostedPayment(null)
					->setMd(null)
					->setPareq(null)
					->setAcsurl(null)
					->setPaymentprocessorresponse(null);
				$payment->setAmount($amount);
				switch($mode)
				{
					case PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_DIRECT_API:
						$error = $this->_runTransaction($payment, $amount);
						break;
					case PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_HOSTED_PAYMENT_FORM:
						$error = $this->_runHostedPaymentTransaction($payment, $amount);
						break;
					case PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_TRANSPARENT_REDIRECT:
						$error = $this->_runTransparentRedirectTransaction($payment, $amount);
						break;
					default:
						Mage::throwException('Invalid payment type: ' . $this->getConfigData('mode'));
						break;
				}
			}
		}
		if($error)
		{
			Mage::throwException($error);
		}
		else
		{
			if($nVersion == 1324 || $nVersion == 1330)
			{
				$payment->setIsInvoicePaid(true);
			}
		}

		return $this;
	}

	/**
	 * Processing the transaction using the direct integration
	 *
	 * @param  Varien_Object $orderPayment
	 * @param  float         $amount
	 * @return bool
	 */
	public function _runTransaction(Varien_Object $payment, $amount)
	{
		$MerchantID = $this->getConfigData('merchantid');
		$Password = $this->getConfigData('password');
		$SecretKey = $this->getConfigData('secretkey');
		// assign payment form field values to variables
		$order = $payment->getOrder();
		$szOrderID = $payment->getOrder()->increment_id;
		$szOrderDescription = '';
		$szCardName = $payment->getCcOwner();
		$szCardNumber = $payment->getCcNumber();
		//save card last four to the session so that it persists through a redirect on 3DS
		Mage::getModel('customer/session')->setData('payvector_card_last_four', substr($payment->getCcNumber(), -4));
		$szIssueNumber = $payment->getCcSsIssue();
		$szCV2 = $payment->getCcCid();
		$szCurrencyShort = $order->getOrderCurrency()->getCurrencyCode();
		// address details
		$billingAddress = $order->getBillingAddress();
		$szAddress1 = $billingAddress->getStreet1();
		$szAddress2 = $billingAddress->getStreet2();
		$szAddress3 = $billingAddress->getStreet3();
		$szAddress4 = $billingAddress->getStreet4();
		$szCity = $billingAddress->getCity();
		$szState = $billingAddress->getRegion();
		$szPostCode = $billingAddress->getPostcode();
		$szISO2CountryCode = $billingAddress->getCountry();
		$szEmailAddress = $billingAddress->getCustomerEmail();
		$szPhoneNumber = $billingAddress->getTelephone();
		$iclISOCurrencyList = ISOCurrencies::getISOCurrencyList();
		$iclISOCountryList = ISOCountries::getISOCountryList();

		$rgeplRequestGatewayEntryPointList = $this->_getGatewayEntryPointList();

		$paymentAction = $this->getConfigData('payment_action');
		if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE_CAPTURE)
		{
			$szTransactionType = "SALE";
		}
		else if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE)
		{
			$szTransactionType = "PREAUTH";
		}
		else
		{
			Mage::throwException('Unknown payment action: ' . $paymentAction);
		}
		$cdtCardDetailsTransaction = new CardDetailsTransaction($rgeplRequestGatewayEntryPointList);
		$cdtCardDetailsTransaction->getMerchantAuthentication()->setMerchantID($MerchantID);
		$cdtCardDetailsTransaction->getMerchantAuthentication()->setPassword($Password);
		$cdtCardDetailsTransaction->getTransactionDetails()->getMessageDetails()->setTransactionType($szTransactionType);
		if($szCurrencyShort != '' &&
			$iclISOCurrencyList->getISOCurrency($szCurrencyShort, $icISOCurrency)
		)
		{
			$cdtCardDetailsTransaction->getTransactionDetails()->getCurrencyCode()->setValue($icISOCurrency->getISOCode());
		}
		$nDecimalAmount = $this->_getRoundedAmount($amount, $icISOCurrency->getExponent());
		$cdtCardDetailsTransaction->getTransactionDetails()->getAmount()->setValue($nDecimalAmount);
		$cdtCardDetailsTransaction->getTransactionDetails()->setOrderID($szOrderID);
		$cdtCardDetailsTransaction->getTransactionDetails()->setOrderDescription($szOrderDescription);
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoCardType()->setValue(true);
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoAmountReceived()->setValue(true);
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoAVSCheckResult()->setValue(true);
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoCV2CheckResult()->setValue(true);
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getThreeDSecureOverridePolicy()->setValue(true);
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getDuplicateDelay()->setValue(60);
		$cdtCardDetailsTransaction->getTransactionDetails()->getThreeDSecureBrowserDetails()->getDeviceCategory()->setValue(0);
		$cdtCardDetailsTransaction->getTransactionDetails()->getThreeDSecureBrowserDetails()->setAcceptHeaders("*/*");
		$cdtCardDetailsTransaction->getTransactionDetails()->getThreeDSecureBrowserDetails()->setUserAgent($_SERVER["HTTP_USER_AGENT"]);
		$cdtCardDetailsTransaction->getCardDetails()->setCardName($szCardName);
		$cdtCardDetailsTransaction->getCardDetails()->setCardNumber($szCardNumber);
		if($payment->getCcExpMonth() != "")
		{
			$cdtCardDetailsTransaction->getCardDetails()->getExpiryDate()->getMonth()->setValue($payment->getCcExpMonth());
		}
		if($payment->getCcExpYear() != "")
		{
			$cdtCardDetailsTransaction->getCardDetails()->getExpiryDate()->getYear()->setValue($payment->getCcExpYear());
		}
		if($payment->getCcSsStartMonth() != "")
		{
			$cdtCardDetailsTransaction->getCardDetails()->getStartDate()->getMonth()->setValue($payment->getCcSsStartMonth());
		}
		if($payment->getCcSsStartYear() != "")
		{
			$cdtCardDetailsTransaction->getCardDetails()->getStartDate()->getYear()->setValue($payment->getCcSsStartYear());
		}
		$cdtCardDetailsTransaction->getCardDetails()->setIssueNumber($szIssueNumber);
		$cdtCardDetailsTransaction->getCardDetails()->setCV2($szCV2);
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setAddress1($szAddress1);
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setAddress2($szAddress2);
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setAddress3($szAddress3);
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setAddress4($szAddress4);
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setCity($szCity);
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setState($szState);
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setPostCode($szPostCode);
		$szCountryShort = $this->_getISO3Code($szISO2CountryCode);
		if($iclISOCountryList->getISOCountry($szCountryShort, $icISOCountry))
		{
			$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->getCountryCode()->setValue($icISOCountry->getISOCode());
		}
		$cdtCardDetailsTransaction->getCustomerDetails()->setEmailAddress($szEmailAddress);
		$cdtCardDetailsTransaction->getCustomerDetails()->setPhoneNumber($szPhoneNumber);
		$boTransactionProcessed = $cdtCardDetailsTransaction->processTransaction($cdtrCardDetailsTransactionResult, $todTransactionOutputData);
		$error = $this->_handleTransactionResults($payment, $boTransactionProcessed, $cdtCardDetailsTransaction, $cdtrCardDetailsTransactionResult, $todTransactionOutputData);

		return $error;
	}

	/**
	 * Processing the transaction using the hosted payment form integration
	 *
	 * @param Varien_Object $payment
	 * @param float         $amount
	 */
	public function _runHostedPaymentTransaction(Varien_Object $payment, $amount)
	{
		$session = Mage::getSingleton('checkout/session');
		$nVersion = $this->getVersion();
		$szMerchantID = $this->getConfigData('merchantid');
		$szPassword = $this->getConfigData('password');
		$szPreSharedKey = $this->getConfigData('presharedkey');
		$hmHashMethod = $this->getConfigData('hashmethod');
		$boCV2Mandatory = 'false';
		$boAddress1Mandatory = 'false';
		$boCityMandatory = 'false';
		$boPostCodeMandatory = 'false';
		$boStateMandatory = 'false';
		$boCountryMandatory = 'false';
		$szEchoCardType = 'true';
		$rdmResultDeliveryMethod = $this->getConfigData('resultdeliverymethod');
		$szServerResultURL = '';
		// set to always true to display the result on the Hosted Payment Form
		$boPaymentFormDisplaysResult = '';

		//Set callback URL
		if($rdmResultDeliveryMethod === PayVector_Tpg_Model_Source_ResultDeliveryMethod::RESULT_DELIVERY_METHOD_SERVER_PULL)
		{
			$szCallbackURL = Mage::getUrl('tpg/payment/serverpullresult', array('_secure' => true));
		}
		else
		{
			$szCallbackURL = Mage::getUrl('tpg/payment/callbackhostedpayment', array('_secure' => true));
		}

		//For SERVER method then set ServerResultURL
		if($rdmResultDeliveryMethod === PayVector_Tpg_Model_Source_ResultDeliveryMethod::RESULT_DELIVERY_METHOD_SERVER)
		{
			$szServerResultURL = Mage::getUrl('tpg/payment/serverresult', array('_secure' => true));
			$boPaymentFormDisplaysResult = 'true';
		}

//		switch($rdmResultDeliveryMethod)
//		{
//			case PayVector_Tpg_Model_Source_ResultDeliveryMethod::RESULT_DELIVERY_METHOD_POST:
//				$szCallbackURL = Mage::getUrl('tpg/payment/callbackhostedpayment', array('_secure' => true));
//				break;
//			case PayVector_Tpg_Model_Source_ResultDeliveryMethod::RESULT_DELIVERY_METHOD_SERVER:
//				$szCallbackURL = Mage::getUrl('tpg/payment/callbackhostedpayment', array('_secure' => true));
//				$szServerResultURL = Mage::getUrl('tpg/payment/serverresult', array('_secure' => true));
//				$boPaymentFormDisplaysResult = 'true';
//				break;
//			case PayVector_Tpg_Model_Source_ResultDeliveryMethod::RESULT_DELIVERY_METHOD_SERVER_PULL:
//				$szCallbackURL = Mage::getUrl('tpg/payment/serverpullresult', array('_secure' => true));
//				break;
//		}

		$order = $payment->getOrder();
		$billingAddress = $order->getBillingAddress();
		$iclISOCurrencyList = ISOCurrencies::getISOCurrencyList();
		$iclISOCountryList = ISOCountries::getISOCountryList();
		$cookie = Mage::getSingleton('core/cookie');
		$arCookieArray = $cookie->get();
		$arCookieKeysArray = array_keys($arCookieArray);
		$nKeysArrayLength = count($arCookieKeysArray);
		$szCookiePath = $cookie->getPath();
		$szCookieDomain = $cookie->getDomain();
		$szServerResultURLCookieVariables = '';
		$szServerResultURLFormVariables = '';
		$szServerResultURLQueryStringVariables = '';
		//ServerResutlURLCookieVariables string format: cookie1=123&path=/&domain=www.domain.com@@cookie2=456&path=/&domain=www.domain.com 
		for($nCount = 0; $nCount < $nKeysArrayLength; $nCount++)
		{
			$szEncodedCookieValue = urlencode($arCookieArray[$arCookieKeysArray[$nCount]]);
			$szServerResultURLCookieVariables .= $arCookieKeysArray[$nCount] . "=" . $szEncodedCookieValue . "&path=" . $szCookiePath . "&domain=" . $szCookieDomain;
			if($nCount < $nKeysArrayLength - 1)
			{
				$szServerResultURLCookieVariables .= "@@";
			}
		}
		$szCurrencyShort = $order->getOrderCurrency()->getCurrencyCode();
		if($szCurrencyShort != '' &&
			$iclISOCurrencyList->getISOCurrency($szCurrencyShort, $icISOCurrency)
		)
		{
			$nCurrencyCode = $icISOCurrency->getISOCode();
		}
		$nAmount = $this->_getRoundedAmount($amount, $icISOCurrency->getExponent());
		$szISO2CountryCode = $billingAddress->getCountry();
		$szCountryShort = $this->_getISO3Code($szISO2CountryCode);
		if($iclISOCountryList->getISOCountry($szCountryShort, $icISOCountry))
		{
			$nCountryCode = $icISOCountry->getISOCode();
		}
		$szOrderID = $payment->getOrder()->increment_id;
		//date time with 2008-12-01 14:12:00 +01:00 format
		$szTransactionDateTime = date('Y-m-d H:i:s P');
		$szOrderDescription = '';
		//$szTransactionType = "SALE";
		$paymentAction = $this->getConfigData('payment_action');
		if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE_CAPTURE)
		{
			$szTransactionType = "SALE";
		}
		else if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE)
		{
			$szTransactionType = "PREAUTH";
		}
		else
		{
			Mage::throwException('Unknown payment action: ' . $paymentAction);
		}
		$szCustomerName = $billingAddress->getfirstname();
		if($billingAddress->getfirstname())
		{
			$szCustomerName = $szCustomerName . ' ' . $billingAddress->getlastname();
		}
		$szAddress1 = $billingAddress->getStreet1();
		$szAddress2 = $billingAddress->getStreet2();
		$szAddress3 = $billingAddress->getStreet3();
		$szAddress4 = $billingAddress->getStreet4();
		$szCity = $billingAddress->getCity();
		$szState = $billingAddress->getRegion();
		$szPostCode = $billingAddress->getPostcode();
		if($this->getConfigData('cv2mandatory'))
		{
			$boCV2Mandatory = 'true';
		}
		if($this->getConfigData('address1mandatory'))
		{
			$boAddress1Mandatory = 'true';
		}
		if($this->getConfigData('citymandatory'))
		{
			$boCityMandatory = 'true';
		}
		if($this->getConfigData('postcodemandatory'))
		{
			$boPostCodeMandatory = 'true';
		}
		if($this->getConfigData('statemandatory'))
		{
			$boStateMandatory = 'true';
		}
		if($this->getConfigData('countrymandatory'))
		{
			$boCountryMandatory = 'true';
		}
		if($this->getConfigData('paymentformdisplaysresult'))
		{
			$boPaymentFormDisplaysResult = 'true';
		}
		$szHashDigest =
			TPG_PaymentFormHelper::calculateHashDigest(
				$szMerchantID,
				$szPassword,
				$hmHashMethod,
				$szPreSharedKey,
				$nAmount,
				$nCurrencyCode,
				$szEchoCardType,
				$szOrderID,
				$szTransactionType,
				$szTransactionDateTime,
				$szCallbackURL,
				$szOrderDescription,
				$szCustomerName,
				$szAddress1,
				$szAddress2,
				$szAddress3,
				$szAddress4,
				$szCity,
				$szState,
				$szPostCode,
				$nCountryCode,
				$boCV2Mandatory,
				$boAddress1Mandatory,
				$boCityMandatory,
				$boPostCodeMandatory,
				$boStateMandatory,
				$boCountryMandatory,
				$rdmResultDeliveryMethod,
				$szServerResultURL,
				$boPaymentFormDisplaysResult,
				$szServerResultURLCookieVariables,
				$szServerResultURLFormVariables,
				$szServerResultURLQueryStringVariables
			);
		$session->setHashdigest($szHashDigest)
			->setMerchantid($szMerchantID)
			->setAmount($nAmount)
			->setCurrencycode($nCurrencyCode)
			->setEchoCardType($szEchoCardType)
			->setOrderid($szOrderID)
			->setTransactiontype($szTransactionType)
			->setTransactiondatetime($szTransactionDateTime)
			->setCallbackurl($szCallbackURL)
			->setOrderdescription($szOrderDescription)
			->setCustomername($szCustomerName)
			->setAddress1($szAddress1)
			->setAddress2($szAddress2)
			->setAddress3($szAddress3)
			->setAddress4($szAddress4)
			->setCity($szCity)
			->setState($szState)
			->setPostcode($szPostCode)
			->setCountrycode($nCountryCode)
			->setCv2mandatory($boCV2Mandatory)
			->setAddress1mandatory($boAddress1Mandatory)
			->setCitymandatory($boCityMandatory)
			->setPostcodemandatory($boPostCodeMandatory)
			->setStatemandatory($boStateMandatory)
			->setCountrymandatory($boCountryMandatory)
			->setResultdeliverymethod($rdmResultDeliveryMethod)
			->setServerresulturl($szServerResultURL)
			->setPaymentformdisplaysresult($boPaymentFormDisplaysResult)
			->setServerresulturlcookievariables($szServerResultURLCookieVariables)
			->setServerresulturlformvariables($szServerResultURLFormVariables)
			->setServerresulturlquerystringvariables($szServerResultURLQueryStringVariables);
		if($nVersion >= 1410)
		{
			$session->setRedirectionMethod('_runRedirectedPaymentComplete');
			$payment->getOrder()->setIsHostedPaymentPending(true);
		}
		/* serve out a dummy CrossReference as the TransactionId - this need to be done to enable the "Refund" button
		   in the Magento CreditMemo internal refund mechanism */
		$payment->setTransactionId($szOrderID . "_" . date('YmdHis'));
	}

	/**
	 * Processing the transaction using the transparent redirect integration
	 *
	 * @param Varien_Object $payment
	 * @param float         $amount
	 */
	public function _runTransparentRedirectTransaction(Varien_Object $payment, $amount)
	{
		$GLOBALS['m_boPayInvoice'] = false;
		$payment->setIsTransactionPending(true);
		$nVersion = $this->getVersion();
		$szMerchantID = $this->getConfigData('merchantid');
		$szPassword = $this->getConfigData('password');
		$szPreSharedKey = $this->getConfigData('presharedkey');
		$hmHashMethod = $this->getConfigData('hashmethod');
		$szCallbackURL = Mage::getUrl('tpg/payment/callbacktransparentredirect', array('_secure' => true));
		$order = $payment->getOrder();
		$billingAddress = $order->getBillingAddress();
		$iclISOCurrencyList = ISOCurrencies::getISOCurrencyList();
		$iclISOCountryList = ISOCountries::getISOCountryList();
		$szStartDateMonth = '';
		$szStartDateYear = '';
		$szCurrencyShort = $order->getOrderCurrency()->getCurrencyCode();
		if($szCurrencyShort != '' &&
			$iclISOCurrencyList->getISOCurrency($szCurrencyShort, $icISOCurrency)
		)
		{
			$nCurrencyCode = $icISOCurrency->getISOCode();
		}
		$nAmount = $this->_getRoundedAmount($amount, $icISOCurrency->getExponent());
		$szOrderID = $payment->getOrder()->increment_id;
		//date time with 2008-12-01 14:12:00 +01:00 format
		$szTransactionDateTime = date('Y-m-d H:i:s P');
		$szOrderDescription = '';
		//$szTransactionType = 'SALE';
		$paymentAction = $this->getConfigData('payment_action');
		if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE_CAPTURE)
		{
			$szTransactionType = "SALE";
		}
		else if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE)
		{
			$szTransactionType = "PREAUTH";
		}
		else
		{
			Mage::throwException('Unknown payment action: ' . $paymentAction);
		}
		$szAddress1 = $billingAddress->getStreet1();
		$szAddress2 = $billingAddress->getStreet2();
		$szAddress3 = $billingAddress->getStreet3();
		$szAddress4 = $billingAddress->getStreet4();
		$szCity = $billingAddress->getCity();
		$szState = $billingAddress->getRegion();
		$szPostCode = $billingAddress->getPostcode();
		$szISO2CountryCode = $billingAddress->getCountry();
		$szCountryShort = $this->_getISO3Code($szISO2CountryCode);
		if($iclISOCountryList->getISOCountry($szCountryShort, $icISOCountry))
		{
			$nCountryCode = $icISOCountry->getISOCode();
		}
		$szCardName = $payment->getCcOwner();
		$szCardNumber = $payment->getCcNumber();
		$szExpiryDateMonth = $payment->getCcExpMonth();
		$szExpiryDateYear = $payment->getCcExpYear();
		if($payment->getCcSsStartMonth() != '')
		{
			$szStartDateMonth = $payment->getCcSsStartMonth();
		}
		if($payment->getCcSsStartYear() != '')
		{
			$szStartDateYear = $payment->getCcSsStartYear();
		}
		$szIssueNumber = $payment->getCcSsIssue();
		$szCV2 = $payment->getCcCid();
		$szHashDigest =
			TPG_PaymentFormHelper::calculateTransparentRedirectHashDigest(
				$szMerchantID,
				$szPassword,
				$hmHashMethod,
				$szPreSharedKey,
				$nAmount,
				$nCurrencyCode,
				$szOrderID,
				$szTransactionType,
				$szTransactionDateTime,
				$szCallbackURL,
				$szOrderDescription
			);
		Mage::getSingleton('checkout/session')->setHashdigest($szHashDigest)
			->setMerchantid($szMerchantID)
			->setAmount($nAmount)
			->setCurrencycode($nCurrencyCode)
			->setOrderid($szOrderID)
			->setTransactiontype($szTransactionType)
			->setTransactiondatetime($szTransactionDateTime)
			->setCallbackurl($szCallbackURL)
			->setOrderdescription($szOrderDescription)
			->setAddress1($szAddress1)
			->setAddress2($szAddress2)
			->setAddress3($szAddress3)
			->setAddress4($szAddress4)
			->setCity($szCity)
			->setState($szState)
			->setPostcode($szPostCode)
			->setCountrycode($nCountryCode)
			->setCardname($szCardName)
			->setCardnumber($szCardNumber)
			->setExpirydatemonth($szExpiryDateMonth)
			->setExpirydateyear($szExpiryDateYear)
			->setStartdatemonth($szStartDateMonth)
			->setStartdateyear($szStartDateYear)
			->setIssuenumber($szIssueNumber)
			->setCv2($szCV2);
		if($nVersion >= 1410)
		{
			Mage::getSingleton('checkout/session')->setRedirectionMethod('_runRedirectedPaymentComplete');
			$payment->getOrder()->setIsHostedPaymentPending(true);
		}
		/* serve out a dummy CrossReference as the TransactionId - this need to be done to enable the "Refund" button
		   in the Magento CreditMemo internal refund mechanism */
		$payment->setTransactionId($szOrderID . "_" . date('YmdHis'));
	}

	/**
	 * Processing the 3D Secure transaction
	 *
	 * @param Varien_Object $payment
	 * @param int           $amount
	 * @param string        $szPaRes
	 * @param string        $szMD
	 */
	public function _run3DSecureTransaction(Varien_Object $payment, $szPaRes, $szMD)
	{
		$MerchantID = $this->getConfigData('merchantid');
		$Password = $this->getConfigData('password');
		$SecretKey = $this->getConfigData('secretkey');
		$rgeplRequestGatewayEntryPointList = $this->_getGatewayEntryPointList();

		$tdsaThreeDSecureAuthentication = new ThreeDSecureAuthentication($rgeplRequestGatewayEntryPointList);
		$tdsaThreeDSecureAuthentication->getMerchantAuthentication()->setMerchantID($MerchantID);
		$tdsaThreeDSecureAuthentication->getMerchantAuthentication()->setPassword($Password);
		$tdsaThreeDSecureAuthentication->getThreeDSecureInputData()->setCrossReference($szMD);
		$tdsaThreeDSecureAuthentication->getThreeDSecureInputData()->setPaRES($szPaRes);
		$boTransactionProcessed = $tdsaThreeDSecureAuthentication->processTransaction($tdsarThreeDSecureAuthenticationResult, $todTransactionOutputData);
		$error = $this->_handleTransactionResults($payment, $boTransactionProcessed, $tdsaThreeDSecureAuthentication, $tdsarThreeDSecureAuthenticationResult, $todTransactionOutputData);

		if($error)
		{
			Mage::throwException($error);
		}

		return $this;
	}

	/**
	 * @param  Varien_Object $payment
	 * @param  bool          $boIsHostedPaymentAction
	 * @param  string        $szStatusCode
	 * @param  string        $szMessage
	 * @param  string        $szPreviousStatusCode
	 * @param  string        $szPreviousMessage
	 * @param  string        $szOrderID
	 * @param  string        $szCrossReference
	 * @param  string        $szCardType
	 * @return PayVector_Tpg_Model_Direct
	 * @throws Mage_Core_Exception
	 */
	public function _runRedirectedPaymentComplete(
		Varien_Object $payment,
		$boIsHostedPaymentAction,
		$szStatusCode, $szMessage,
		$szPreviousStatusCode,
		$szPreviousMessage,
		$szOrderID,
		$szCrossReference,
		$szCardType
	)
	{
		$error = false;
		$message = null;
		$session = Mage::getSingleton('checkout/session');
		$nVersion = $this->getVersion();
		if($boIsHostedPaymentAction == true)
		{
			$szWording = "Hosted Payment Form ";
		}
		else
		{
			$szWording = "Transparent Redirect ";
		}
		$message = "Payment Processor Response: " . $szMessage;

		//save cross reference so the user can use saved card functionality for future transactions
		$payment->setTransactionId($szCrossReference);
		$payment->setCcType($szCardType);

		switch($szStatusCode)
		{
			case "0":
				Mage::log($szWording . "transaction successfully completed. " . $message);
				// need to store the new CrossReference and only store it against the payment object in the payment controller class
				$session->setNewCrossReference($szCrossReference);
				break;
			case "20":
				Mage::log("Duplicate " . $szWording . "transaction. " . $message);
				$message =
					$message .
					". A duplicate transaction means that a transaction with these details has already been processed by the payment provider. The details of the original transaction - Previous Transaction Response: " .
					$szPreviousMessage;
				if($szPreviousStatusCode != "0")
				{
					$error = true;
				}
				break;
			case "5":
				Mage::log($szWording . "transaction couldn't be completed. " . $message);
				$error = true;
				//$message = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_183 . "<br />" . $message;
				break;
			case "30":
			default:
				Mage::log($szWording . "transaction couldn't be completed. " . $message);
				$error = true;
				break;
		}
		$session->setPaymentprocessorresponse($message);
		// store the CrossReference and other data
		$this->setPaymentAdditionalInformation($payment, $szCrossReference);
		if($error == true)
		{
			$message = Mage::helper('tpg')->__($message);
			Mage::throwException($message);
		}
		else
		{
			$payment->setStatus(self::STATUS_APPROVED);
			if($nVersion == 1324 || $nVersion == 1330)
			{
				$payment->setIsInvoicePaid(true);
				Mage::getSingleton('core/session')->addSuccess($message);
			}
		}

		return $this;
	}

	/**
	 * Override the core Mage function to get the URL to be redirected from the Onepage
	 *
	 * @return string
	 */
	public function getOrderPlaceRedirectUrl()
	{
		$result = false;
		$session = Mage::getSingleton('checkout/session');
		$nVersion = $this->getVersion();
		$mode = $this->getConfigData('mode');
		if($session->getMd() &&
			$session->getAcsurl() &&
			$session->getPareq()
		)
		{
			// Direct (API) for 3D Secure payments
			if($nVersion >= 1410)
			{
				// need to re-add the ordered item quantity to stock as per not completed 3DS transaction
				if($mode != PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_TRANSPARENT_REDIRECT)
				{
					$order = Mage::getModel('sales/order')->load(Mage::getSingleton('checkout/session')->getLastOrderId());
					$this->addOrderedItemsToStock($order);
				}
			}
			$result = Mage::getUrl('tpg/payment/threedsecure', array('_secure' => true));
		}
		if($session->getHashdigest())
		{
			// Hosted Payment Form and Transparent Redirect payments
			if($nVersion >= 1410)
			{
				// need to re-add the ordered item quantity to stock as per not completed 3DS transaction
				if(!Mage::getSingleton('checkout/session')->getPares())
				{
					$order = Mage::getModel('sales/order')->load(Mage::getSingleton('checkout/session')->getLastOrderId());
					$this->addOrderedItemsToStock($order);
				}
			}
			$result = Mage::getUrl('tpg/payment/redirect', array('_secure' => true));
		}

		return $result;
	}

	/**
	 * Get the correct payment processor domain
	 *
	 * @return string
	 */
	private function _getPaymentProcessorFullDomain()
	{
		$szPaymentProcessorFullDomain = null;
		// get the stored config setting
		$szPaymentProcessorDomain = $this->getConfigData('paymentprocessordomain');
		$szPaymentProcessorPort = $this->getConfigData('paymentprocessorport');
		if($szPaymentProcessorPort == '443')
		{
			$szPaymentProcessorFullDomain = $szPaymentProcessorDomain . "/";
		}
		else
		{
			$szPaymentProcessorFullDomain = $szPaymentProcessorDomain . ":" . $szPaymentProcessorPort . "/";
		}

		return $szPaymentProcessorFullDomain;
	}

	/**
	 * Get the country ISO3 code from the ISO2 code
	 *
	 * @param  ISO2Code
	 * @return string|null
	 */
	private function _getISO3Code($szISO2Code)
	{
		$szISO3Code = null;
		$collection = null;
		$boFound = false;
		$nCount = 1;
		$collection = Mage::getModel('directory/country_api')->items();
		while($boFound == false &&
			$nCount < count($collection))
		{
			$item = $collection[$nCount];
			if($item['iso2_code'] == $szISO2Code)
			{
				$boFound = true;
				$szISO3Code = $item['iso3_code'];
			}
			$nCount++;
		}

		return $szISO3Code;
	}

	/**
	 * Transform the string Magento version number into an integer ready for comparison
	 *
	 * @return int
	 */
	public function getVersion()
	{
		$magentoVersion = Mage::getVersion();
		$pattern = '/[^\d]/';
		$magentoVersion = preg_replace($pattern, '', $magentoVersion);
		while(strlen($magentoVersion) < 4)
		{
			$magentoVersion .= '0';
		}
		$magentoVersion = (int) $magentoVersion;

		return $magentoVersion;
	}

	/**
	 * @param  float $amount
	 * @param  int   $nExponent
	 * @return float
	 */
	private function _getRoundedAmount($amount, $nExponent)
	{
		// round the amount before use
		$amount = round($amount, $nExponent);
		$power = pow(10, $nExponent);
		$nDecimalAmount = $amount * $power;

		return $nDecimalAmount;
	}

	/**
	 * Depreciated function - sets the additional_information column data in the sales_flat_order_payment table
	 *
	 * @param Varien_Object $payment
	 * @param string        $szCrossReference
	 * @param string        $szTransactionType
	 * @param string        $szTransactionDate
	 */
	public function setPaymentAdditionalInformation($payment, $szCrossReference)
	{
		$arAdditionalInformationArray = array();
		$paymentAction = $this->getConfigData('payment_action');
		if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE_CAPTURE)
		{
			$szTransactionType = "SALE";
		}
		else if($paymentAction == Mage_Paygate_Model_Authorizenet::ACTION_AUTHORIZE)
		{
			$szTransactionType = "PREAUTH";
		}
		else
		{
			Mage::throwException('Unknown payment action: ' . $paymentAction);
		}
		$szTransactionDate = date("Ymd");
		$arAdditionalInformationArray["CrossReference"] = $szCrossReference;
		$arAdditionalInformationArray["TransactionType"] = $szTransactionType;
		$arAdditionalInformationArray["TransactionDateTime"] = $szTransactionDate;
		$payment->setAdditionalInformation($arAdditionalInformationArray);
	}

	/**
	 * Deduct the order items from the stock
	 *
	 * @param unknown_type $order
	 */
	public function subtractOrderedItemsFromStock($order)
	{
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		$isCustomStockManagementEnabled = Mage::getModel('tpg/direct')->getConfigData('customstockmanagementenabled');
		if($nVersion >= 1410 &&
			$isCustomStockManagementEnabled
		)
		{
			$items = $order->getAllItems();
			foreach($items as $itemId => $item)
			{
				// ordered quantity of the item from stock
				$quantity = $item->getQtyOrdered();
				$productId = $item->getProductId();
				$stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
				$stockManagement = $stock->getManageStock();
				if($stockManagement)
				{
					$stock->setQty($stock->getQty() - $quantity);
					$stock->save();
				}
			}
		}
	}

	/**
	 * Re-add the order items to the stock to balance the incorrect stock management before a payment is completed
	 *
	 * @param unknown_type $order
	 */
	public function addOrderedItemsToStock($order)
	{
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		$isCustomStockManagementEnabled = Mage::getModel('tpg/direct')->getConfigData('customstockmanagementenabled');
		if($nVersion >= 1410 &&
			$isCustomStockManagementEnabled
		)
		{
			$items = $order->getAllItems();
			foreach($items as $itemId => $item)
			{
				// ordered quantity of the item from stock
				$quantity = $item->getQtyOrdered();
				$productId = $item->getProductId();
				$stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
				$stockManagement = $stock->getManageStock();
				if($stockManagement)
				{
					$stock->setQty($stock->getQty() + $quantity);
					$stock->save();
				}
			}
		}
	}

	/**
	 * Override the refund function to run a CrossReference transaction
	 *
	 * @param  Varien_Object $payment
	 * @param  int           $amount
	 * @return PayVector_Tpg_Model_Direct
	 */
	public function refund(Varien_Object $payment, $amount)
	{
		$error = false;
		$szTransactionType = "REFUND";
		$orderStatus = 'TPG_refunded';
		$szMessage = 'Payment refunded';
		if($amount > 0)
		{
			$error = $this->_runCrossReferenceTransaction($payment, $szTransactionType, $amount);
		}
		else
		{
			$error = 'Error in refunding the payment';
		}
		if($error === false)
		{
			$order = $payment->getOrder();
			$payment = $order->getPayment();
			$arAdditionalInformationArray = $payment->getAdditionalInformation();
			$arAdditionalInformationArray["Refunded"] = 1;
			$payment->setAdditionalInformation($arAdditionalInformationArray);
			$payment->save();
			$order->setState('canceled', $orderStatus, $szMessage, false);
			$order->save();
		}
		else
		{
			Mage::throwException($error);
		}

		return $this;
	}

	/**
	 * PayVector VOID functionality
	 * Note: if a transaction (payment) is once voided (canceled) it isn't possible to redo this action
	 *
	 * @param  Varien_Object $payment
	 * @return bool
	 */
	public function ircVoid(Varien_Object $payment)
	{
		$error = false;
		$szTransactionType = "VOID";
		$orderStatus = "TPG_voided";
		// attempt a VOID and accordingly to the last saved transaction id (CrossReference) set the new message
		$szLastTransId = $payment->getLastTransId();
		$transaction = $payment->getTransaction($szLastTransId);
		$szMagentoTxnType = $transaction->getTxnType();
		$szMessage = "Payment voided";
		if($szMagentoTxnType == "capture")
		{
			$szMessage = "Payment voided";
		}
		else if($szMagentoTxnType == "authorization")
		{
			$szMessage = "PreAuthorization voided";
		}
		else if($szMagentoTxnType == "refund")
		{
			$szMessage = "Refund voided";
		}
		else
		{
			// general message
			$szMessage = "Payment voided";
		}
		$error = $this->_runCrossReferenceTransaction($payment, $szTransactionType);
		if($error === false)
		{
			$order = $payment->getOrder();
			$invoices = $order->getInvoiceCollection();
			$payment = $order->getPayment();
			$arAdditionalInformationArray = $payment->getAdditionalInformation();
			$arAdditionalInformationArray["Voided"] = 1;
			$payment->setAdditionalInformation($arAdditionalInformationArray);
			$payment->save();
			// cancel the invoices
			foreach($invoices as $invoice)
			{
				$invoice->cancel();
				$invoice->save();
			}
			// update the order
			$order->setActionBy($payment->getLggdInAdminUname())
				->setActionDate(date('Y-m-d H:i:s'))
				->setVoided(1)
				->setState('canceled', $orderStatus, $szMessage, false);
			$order->save();
			$result = "0";
		}
		else
		{
			$result = $error;
		}

		return $result;
	}

	/**
	 * PayVector COLLECTION functionality (capture called in Magento)
	 *
	 * @param  Varien_Object $payment
	 * @param  string        $szOrderID
	 * @param  string        $szCrossReference
	 * @return bool
	 */
	public function ircCollection(Varien_Object $payment, $szOrderID, $szCrossReference)
	{
		$szTransactionType = "COLLECTION";
		$orderStatus = 'TPG_collected';
		$szMessage = 'Preauthorization successfully collected';
		$state = Mage_Sales_Model_Order::STATE_PROCESSING;
		$error = $this->_captureAuthorizedPayment($payment);
		if($error === false)
		{
			$order = $payment->getOrder();
			$invoices = $order->getInvoiceCollection();
			$payment = $order->getPayment();
			$arAdditionalInformationArray = $payment->getAdditionalInformation();
			$arAdditionalInformationArray["Collected"] = 1;
			$payment->setAdditionalInformation($arAdditionalInformationArray);
			$payment->save();
			// update the invoices to paid status
			foreach($invoices as $invoice)
			{
				$invoice->pay()->save();
			}
			$order->setActionBy($payment->getLggdInAdminUname())
				->setActionDate(date('Y-m-d H:i:s'))
				->setState($state, $orderStatus, $szMessage, false);
			$order->save();
			$result = "0";
		}
		else
		{
			$result = $error;
		}

		return $result;
	}

	/**
	 * Private capture function for an authorized payment
	 *
	 * @param Varien_Object $payment
	 * @return unknown
	 */
	private function _captureAuthorizedPayment(Varien_Object $payment)
	{
		$error = false;
		$session = Mage::getSingleton('checkout/session');
		try
		{
			// set the COLLECTION variable to true
			$session->setIsCollectionCrossReferenceTransaction(true);
			$invoice = $payment->getOrder()->prepareInvoice();
			$invoice->register();
			if($this->_canCapture)
			{
				$invoice->capture();
			}
			$payment->getOrder()->addRelatedObject($invoice);
			$payment->setCreatedInvoice($invoice);
		}
		catch(Exception $exc)
		{
			$error = "Couldn't capture pre-authorized payment. Message: " . $exc->getMessage();
			Mage::log($exc->getMessage());
		}
		// remove the COLLECTION session variable once finished the COLLECTION attempt
		$session->setIsCollectionCrossReferenceTransaction(null);

		return $error;
	}

	/**
	 * Internal CrossReference function for all VOID, REFUND, COLLECTION transaction types
	 *
	 * @param  Varien_Object $payment
	 * @param  string        $szTransactionType
	 * @param  float         $amount
	 * @param  bool          $threeDSecureOverridePolicy Whether 3DSecure should be run on this transaction
	 * @return string|bool
	 */
	private function _runCrossReferenceTransaction(Varien_Object $payment, $szTransactionType, $amount = false, $threeDSecureOverridePolicy = null)
	{
		$error = false;
		$boTransactionProcessed = false;
		$szMerchantID = $this->getConfigData('merchantid');
		$szPassword = $this->getConfigData('password');
		$iclISOCurrencyList = ISOCurrencies::getISOCurrencyList();
		$iclISOCurrencyList;
		$order = $payment->getOrder();
		$szOrderID = $order->getRealOrderId();;
		//$szCrossReference = $payment->getLastTransId();
		$additionalInformation = $payment->getAdditionalInformation();
		$szCrossReference = $additionalInformation["CrossReference"];
		$szCrossReference = $payment->getLastTransId();
		//if cross reference isn't found then look for the customers last transaction
		if(!isset($szCrossReference))
		{
			if(Mage::getSingleton('customer/session')->isLoggedIn())
			{
				$customer = Mage::getSingleton('customer/session')->getCustomer();
				$customerID = $customer->getId();
				$orderPaymentTableName = Mage::getSingleton('core/resource')->getTableName('sales/order_payment');
				$orderCollection = Mage::getModel('sales/order')->getCollection()
					->addFilter('customer_id', $customerID);
				$orderCollection->getSelect()
					->join(
						array("order_payment" => $orderPaymentTableName),
						"main_table.entity_id=order_payment.entity_id",
						"order_payment.last_trans_id"
					);
				//check if a cross reference was set in any of the previous transactions by this customer
				foreach($orderCollection as $order)
				{
					$szCrossReference = $order->getLastTransId();
					if(isset($szCrossReference))
					{
						break;
					}
				}
			}
		}
		// check the CrossReference and TransactionType parameters
		if(!$szCrossReference)
		{
			$error = 'Error occurred for ' . $szTransactionType . ': Missing Cross Reference';
		}
		if(!$szTransactionType)
		{
			$error = 'Error occurred for ' . $szTransactionType . ': Missing Transaction Type';
		}
		if($error === false)
		{
			$PaymentProcessorFullDomain = $this->_getPaymentProcessorFullDomain();
			$rgeplRequestGatewayEntryPointList = new RequestGatewayEntryPointList();
			$rgeplRequestGatewayEntryPointList->add("https://gw1." . $PaymentProcessorFullDomain, 100, 2);
			$rgeplRequestGatewayEntryPointList->add("https://gw2." . $PaymentProcessorFullDomain, 200, 2);
			$rgeplRequestGatewayEntryPointList->add("https://gw3." . $PaymentProcessorFullDomain, 300, 2);

			$rgeplRequestGatewayEntryPointList = $this->_getGatewayEntryPointList();

			$crtCrossReferenceTransaction = new CrossReferenceTransaction($rgeplRequestGatewayEntryPointList);
			$crtCrossReferenceTransaction->getMerchantAuthentication()->setMerchantID($szMerchantID);
			$crtCrossReferenceTransaction->getMerchantAuthentication()->setPassword($szPassword);
			// if no amount is specified get the grand total amount
			if($amount === false)
			{
				$nAmount = $order->getBaseGrandTotal();
			}
			else
			{
				$nAmount = $amount;
			}
			$szCurrencyShort = $order->getOrderCurrency()->getCurrencyCode();
			if($szCurrencyShort != '' && $iclISOCurrencyList->getISOCurrency($szCurrencyShort, $icISOCurrency))
			{
				$nCurrencyCode = new NullableInt($icISOCurrency->getISOCode());
				$crtCrossReferenceTransaction->getTransactionDetails()->getCurrencyCode()->setValue($icISOCurrency->getISOCode());
			}
			// round the amount before use
			$nAmount = round($nAmount, $icISOCurrency->getExponent());
			$power = pow(10, $icISOCurrency->getExponent());
			$nDecimalAmount = $nAmount * $power;
			$crtCrossReferenceTransaction->getTransactionDetails()->setOrderID($szOrderID);
			$crtCrossReferenceTransaction->getTransactionDetails()->getAmount()->setValue($nDecimalAmount);
			$crtCrossReferenceTransaction->getTransactionDetails()->getMessageDetails()->setCrossReference($szCrossReference);
			$crtCrossReferenceTransaction->getTransactionDetails()->getMessageDetails()->setTransactionType($szTransactionType);
			if(isset($threeDSecureOverridePolicy))
			{
				$crtCrossReferenceTransaction->getTransactionDetails()->getTransactionControl()->getThreeDSecureOverridePolicy()->setValue($threeDSecureOverridePolicy);
			}
			$crtCrossReferenceTransaction->getTransactionDetails()->getTransactionControl()->getEchoCardType()->setValue(true);
			try
			{
				$boTransactionProcessed = $crtCrossReferenceTransaction->processTransaction($crtrCrossReferenceTransactionResult, $todTransactionOutputData);
			}
			catch(Exception $exc)
			{
				Mage::log("exception: " . $exc->getMessage());
			}
			$error = $this->_handleTransactionResults($payment, $boTransactionProcessed, $crtCrossReferenceTransaction, $crtrCrossReferenceTransactionResult, $todTransactionOutputData);
//			if ($boTransactionProcessed == false)
//			{
//				// could not communicate with the payment gateway
//				$error = "Couldn't complete ".$szTransactionType." transaction. Details: ".$crtCrossReferenceTransaction->getLastException();
//				$szLogMessage = $error;
//			}
//			else
//			{
//				switch($crtrCrossReferenceTransactionResult->getStatusCode())
//				{
//					case 0:
//						$error = false;
//						$szNewCrossReference = $todTransactionOutputData->getCrossReference();
//						$szLogMessage = $szTransactionType . " CrossReference transaction successfully completed. Response object: ";
//
//						$payment->setTransactionId($szNewCrossReference)
//								->setParentTransactionId($szCrossReference)
//								->setIsTransactionClosed(1);
//						$payment->save();
//						break;
//					default:
//						$szLogMessage = $crtrCrossReferenceTransactionResult->getMessage();
//						if ($crtrCrossReferenceTransactionResult->getErrorMessages()->getCount() > 0)
//						{
//							$szLogMessage = $szLogMessage.".";
//
//							for ($LoopIndex = 0; $LoopIndex < $crtrCrossReferenceTransactionResult->getErrorMessages()->getCount(); $LoopIndex++)
//							{
//								$szLogMessage = $szLogMessage.$crtrCrossReferenceTransactionResult->getErrorMessages()->getAt($LoopIndex).";";
//							}
//							$szLogMessage = $szLogMessage." ";
//						}
//
//						$error = "Couldn't complete ".$szTransactionType." transaction for CrossReference: " . $szCrossReference . ". Payment Response: ".$szLogMessage;
//						$szLogMessage = $szTransactionType . " CrossReference transaction failed. Response object: ";
//						break;
//				}
//
//				$szLogMessage = $szLogMessage.print_r($crtrCrossReferenceTransactionResult, 1);
//			}
//
//			Mage::log($szLogMessage);
		}

		return $error;
	}

	private function _getGatewayEntryPointList()
	{
		$rgeplRequestGatewayEntryPointList = new RequestGatewayEntryPointList();

		/* @var PayVector_Tpg_Model_Gatewayentrypoints $gatewayEntryPointsTable */
		$gatewayEntryPointsTable = Mage::getModel('tpg/gatewayentrypoints');
		$geplGatewayEntryPointListXML = $gatewayEntryPointsTable->getEntryPoints();

		if($geplGatewayEntryPointListXML !== null && $geplGatewayEntryPointListXML !== false)
		{
			$geplGatewayEntryPointList = GatewayEntryPointList::fromXmlString($geplGatewayEntryPointListXML);
			for($nCount = 0; $nCount < $geplGatewayEntryPointList->getCount(); $nCount++)
			{
				$geplGatewayEntryPoint = $geplGatewayEntryPointList->getAt($nCount);
				$rgeplRequestGatewayEntryPointList->add($geplGatewayEntryPoint->getEntryPointURL(), $geplGatewayEntryPoint->getMetric(), 1);
			}
		}
		else
		{
			// if we don't have a recent list in the database then just use blind processing
			$paymentProcessorFullDomain = $this->_getPaymentProcessorFullDomain();
			$rgeplRequestGatewayEntryPointList->add("https://gw1." . $paymentProcessorFullDomain, 100, 2);
			$rgeplRequestGatewayEntryPointList->add("https://gw2." . $paymentProcessorFullDomain, 200, 2);
			$rgeplRequestGatewayEntryPointList->add("https://gw3." . $paymentProcessorFullDomain, 300, 2);
		}
		return $rgeplRequestGatewayEntryPointList;
	}

	/**
	 * @param  PayVector_Sales_Model_Order_Payment $payment
	 * @param  bool                                $boTransactionProcessed
	 * @param  GatewayTransaction                  $toTransactionObject
	 * @param  GatewayOutput                       $troTransactionResultObject
	 * @param  TransactionOutputData               $todTransactionOutputData
	 * @return string|bool
	 */
	private function _handleTransactionResults($payment, $boTransactionProcessed, $toTransactionObject, $troTransactionResultObject, $todTransactionOutputData)
	{
		$order = $payment->getOrder();
		$szOrderID = $payment->increment_id;
		$nVersion = $this->getVersion();
		if($boTransactionProcessed === false)
		{
			// could not communicate with the payment gateway
			$error = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_261;
			if($toTransactionObject->getLastException())
			{
				$error .= " [ " . $toTransactionObject->getLastException() . " ]";
			}
			$szLogMessage =
				"Couldn't complete transaction. Details: " . print_r($troTransactionResultObject, 1) . " " . print_r($todTransactionOutputData, 1); //Couldn't communicate with payment gateway.
			Mage::log($szLogMessage);
			Mage::log("Last exception: " . print_r($toTransactionObject->getLastException(), 1));
		}
		else
		{
			$currentTimestamp = Mage::getSingleton('core/date')->gmtDate();
			$gatewayEntryPointsListXML = $todTransactionOutputData->getGatewayEntryPoints()->toXmlString();

			$gatewayEntryPointsTable = Mage::getResourceModel('tpg/gatewayentrypoints');
			$gatewayEntryPointsTable->saveEntryPoints($gatewayEntryPointsListXML, $currentTimestamp);

			$szLogMessage = "Transaction could not be completed for OrderID: " . $szOrderID . ". Result details: ";
			$szNotificationMessage = 'Payment Processor Response: ' . $troTransactionResultObject->getMessage();
			$szCrossReference = $todTransactionOutputData->getCrossReference();
			/* serve out the CrossReference as the TransactionId - this need to be done to enable the "Refund" button
			   in the Magento CreditMemo internal refund mechanism */
			$payment->setTransactionId($szCrossReference);
			switch($troTransactionResultObject->getStatusCode())
			{
				case 0:
					// status code of 0 - means transaction successful
					$szLogMessage = "Transaction successfully completed for OrderID: " . $szOrderID . ". Response object: ";
					// serve out the CrossReference as a TransactionId in the Magento system
					$order->setCustomerNote($szNotificationMessage);
					$this->setPaymentAdditionalInformation($payment, $szCrossReference);
					$payment->setCcType($todTransactionOutputData->getCardTypeData()->getCardType());
					$payment->setCcLast4(Mage::getModel('customer/session')->getData('payvector_card_last_four'));
					// deactivate the current quote - fixing the cart not emptied bug
					Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
					// add the success message
					Mage::getSingleton('core/session')->addSuccess($szNotificationMessage);
					break;
				case 3:
					// status code of 3 - means 3D Secure authentication required
					$szLogMessage = "3D Secure Authentication required for OrderID: " . $szOrderID . ". Response object: ";
					$szNotificationMessage = '';
					$szPaReq = $todTransactionOutputData->getThreeDSecureOutputData()->getPaREQ();
					$szACSURL = $todTransactionOutputData->getThreeDSecureOutputData()->getACSURL();
					Mage::getSingleton('checkout/session')->setMd($szCrossReference)
						->setAcsurl($szACSURL)
						->setPareq($szPaReq);
					if($nVersion >= 1410)
					{
						Mage::getSingleton('checkout/session')->setRedirectionMethod('_run3DSecureTransaction');
						$order->setIsThreeDSecurePending(true);
					}
					break;
				case 5:
					// status code of 5 - means transaction declined
					$error = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_182;
					$error .= $szNotificationMessage;
					break;
				case 20:
					// status code of 20 - means duplicate transaction
					$szPreviousTransactionMessage = $troTransactionResultObject->getPreviousTransactionResult()->getMessage();
					$szLogMessage =
						"Duplicate transaction for OrderID: " .
						$szOrderID .
						". A duplicate transaction means that a transaction with these details has already been processed by the payment provider. The details of the original transaction: " .
						$szPreviousTransactionMessage .
						". Response object: ";
					$szNotificationMessage =
						$szNotificationMessage .
						". A duplicate transaction means that a transaction with these details has already been processed by the payment provider. The details of the original transaction - Previous Transaction Response: " .
						$szPreviousTransactionMessage;
					if($troTransactionResultObject->getPreviousTransactionResult()->getStatusCode()->getValue() != 0)
					{
						$error = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_182;
						$error .= $szNotificationMessage;
					}
					else
					{
						Mage::getSingleton('core/session')->addSuccess($szNotificationMessage);
					}
					break;
				case 30:
					// status code of 30 - means an error occurred
					$error = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_183;
					$error .= $szNotificationMessage;
					$szLogMessage = "Transaction could not be completed for OrderID: " . $szOrderID . ". Error message: " . $troTransactionResultObject->getMessage();
					if($troTransactionResultObject->getErrorMessages()->getCount() > 0)
					{
						$szLogMessage = $szLogMessage . ".";
						for($LoopIndex = 0; $LoopIndex < $troTransactionResultObject->getErrorMessages()->getCount(); $LoopIndex++)
						{
							$szLogMessage = $szLogMessage . $troTransactionResultObject->getErrorMessages()->getAt($LoopIndex) . ";";
						}
						$szLogMessage = $szLogMessage . " ";
					}
					$szLogMessage = $szLogMessage . ' Response object: ';
					break;
				default:
					// unhandled status code
					$error = $szNotificationMessage;
					break;
			}
			$szLogMessage = $szLogMessage . print_r($troTransactionResultObject, 1);
			Mage::log($szLogMessage);
		}
		if($error)
		{
			$payment->setStatus('FAIL')
				->setCcApproval('FAIL');
		}
		else
		{
			if($nVersion == 1324 || $nVersion == 1330)
			{
				$payment->setIsInvoicePaid(true);
			}
		}

		return $error;
	}
}
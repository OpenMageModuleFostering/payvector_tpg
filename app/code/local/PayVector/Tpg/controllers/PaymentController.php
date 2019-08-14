<?php

/**
 * Standard Checkout Controller
 *
 */
class PayVector_Tpg_PaymentController extends Mage_Core_Controller_Front_Action
{
	protected function _expireAjax()
	{
		if(!Mage::getSingleton('checkout/session')->getQuote()->hasItems())
		{
			$this->getResponse()->setHeader('HTTP/1.1', '403 Session Expired');
			exit;
		}
	}

	public function errorAction()
	{
		//$this->_redirect('checkout/cart');
		$this->_redirect('checkout/onepage/failure');
		#$this->loadLayout();
		#$this->renderLayout();
	}

	/**
	 * When a customer cancel payment.
	 */
	public function cancelAction()
	{
		$session = Mage::getSingleton('checkout/session');
		$session->setQuoteId($session->getPaypalStandardQuoteId(true));
		$this->_redirect('checkout/cart');
	}

	/**
	 * Action logic for Hosted Payment mode
	 *
	 */
	public function redirectAction()
	{
		$this->getResponse()->setBody($this->getLayout()->createBlock('tpg/redirect')->toHtml());
	}

	/**
	 * Action logic for 3D Secure redirection
	 *
	 */
	public function threedsecureAction()
	{
		$this->getResponse()->setBody($this->getLayout()->createBlock('tpg/threedsecure')->toHtml());
	}

	/**
	 * Action logic for handling the reception of the 3D Secure authentication result (PaRes)
	 *
	 * @return unknown
	 */
	public function callback3dAction()
	{   
		$boError = false;
		$szMessage = '';
		$checkout = new PayVector_Checkout_Model_Type_Onepage();//Mage::getSingleton('checkout/type_onepage');
		$session = Mage::getSingleton('checkout/session');
		$szPaymentProcessorResponse = '';
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		$order = Mage::getModel('sales/order');
		$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
		$boCartIsEmpty = false;
		try
		{
			$szPaRes = $this->getRequest()->getPost('PaRes');
			$szMD = $this->getRequest()->getPost('MD');
			// check if the cart is not empty, ie: after successful completion back button clicked in the browser
			$payVectorOrderId = Mage::getSingleton('checkout/session')->getTpgOrderId();
			$szOrderStatus = $order->getStatus();
			if($szOrderStatus != 'irc_paid' &&
				$szOrderStatus != 'irc_preauth'
			)
			{
				// cart is not empty
				// complete the 3D Secure transaction with the 3D Authorization result
				$checkout->saveOrderAfter3dSecure($szPaRes, $szMD);
				$szPaymentProcessorResponse = $session->getPaymentprocessorresponse();
			}
			else
			{
				// cart is empty
				$boCartIsEmpty = true;
				$szPaymentProcessorResponse = null;
			}
		}
		catch(Exception $exc)
		{
			$boError = true;
			Mage::logException($exc);
			if(isset($_SESSION['tpg_message']))
			{
				$szMessage = $_SESSION['tpg_message'];
				unset($_SESSION['tpg_message']);
			}
			else
			{
				$szMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_7655;
			}
		}
		if($boError)
		{
			if($szPaymentProcessorResponse != null &&
				$szPaymentProcessorResponse != ''
			)
			{
				$szMessage .= '<br/>' . $szPaymentProcessorResponse;
			}
			if($nVersion >= 1410)
			{
				if($order)
				{
					$orderState = 'pending_payment';
					$orderStatus = 'irc_failed_threed_secure';
					$order->setCustomerNote(Mage::helper('tpg')->__('3D Secure Authentication Failed'));
					$order->setState($orderState, $orderStatus, $szPaymentProcessorResponse, false);
					$order->save();
				}
			}
			if($nVersion == 1324 || $nVersion == 1330)
			{
				Mage::getSingleton('checkout/session')->addError($szMessage);
			}
			else
			{
				Mage::getSingleton('core/session')->addError($szMessage);
			}
			$this->_clearSessionVariables();
			// report out an fatal error
			$this->_redirect('checkout/onepage/failure');
		}
		else
		{
			// set the quote as inactive after back from paypal
			Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
			// if the cart is empty do not attempt to update the invoices
			if($boCartIsEmpty == false)
			{
				// send confirmation email to customer
				if($order->getId())
				{
					$order->sendNewOrderEmail();
				}
				if($nVersion >= 1410)
				{
					// TODO : no need to remove stock item as the system will do it in 1.6 version
					if($nVersion < 1600)
					{
						Mage::getModel('tpg/direct')->subtractOrderedItemsFromStock($order);
					}
					$this->_updateInvoices($order, $szPaymentProcessorResponse);
				}
				if($nVersion != 1324 && $nVersion != 1330)
				{
					if($szPaymentProcessorResponse != '')
					{
						Mage::getSingleton('core/session')->addSuccess($szPaymentProcessorResponse);
					}
				}
			}
			$this->_redirect('checkout/onepage/success', array('_secure' => true));
		}
	}

	/**
	 * Action logic for handling the result from the Hosted Payment page
	 *
	 */
	public function callbackhostedpaymentAction()
	{
		$boError = false;
		$formVariables = array();
		$model = Mage::getModel('tpg/direct');
		$szOrderID = $this->getRequest()->getPost('OrderID');
		/* @var PayVector_Checkout_Model_Type_Onepage $checkout */
		$checkout = Mage::getSingleton('checkout/type_onepage');
		$session = Mage::getSingleton('checkout/session');
		$szPaymentProcessorResponse = '';
		$order = Mage::getModel('sales/order');
		$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		$boCartIsEmpty = false;
		try
		{
			$hmHashMethod = $model->getConfigData('hashmethod');
			$szPassword = $model->getConfigData('password');
			$szPreSharedKey = $model->getConfigData('presharedkey');
			$formVariables['HashDigest'] = $this->getRequest()->getPost('HashDigest');
			$formVariables['MerchantID'] = $this->getRequest()->getPost('MerchantID');
			$formVariables['StatusCode'] = $this->getRequest()->getPost('StatusCode');
			$formVariables['Message'] = $this->getRequest()->getPost('Message');
			$formVariables['PreviousStatusCode'] = $this->getRequest()->getPost('PreviousStatusCode');
			$formVariables['PreviousMessage'] = $this->getRequest()->getPost('PreviousMessage');
			$formVariables['CrossReference'] = $this->getRequest()->getPost('CrossReference');
			$formVariables['Amount'] = $this->getRequest()->getPost('Amount');
			$formVariables['CurrencyCode'] = $this->getRequest()->getPost('CurrencyCode');
			$formVariables['CardType'] = $this->getRequest()->getPost('CardType');
			$formVariables['CardClass'] = $this->getRequest()->getPost('CardClass');
			$formVariables['CardIssuer'] = $this->getRequest()->getPost('CardIssuer');
			$formVariables['CardIssuerCountryCode'] = $this->getRequest()->getPost('CardIssuerCountryCode');
			$formVariables['OrderID'] = $this->getRequest()->getPost('OrderID');
			$formVariables['TransactionType'] = $this->getRequest()->getPost('TransactionType');
			$formVariables['TransactionDateTime'] = $this->getRequest()->getPost('TransactionDateTime');
			$formVariables['OrderDescription'] = $this->getRequest()->getPost('OrderDescription');
			$formVariables['CustomerName'] = $this->getRequest()->getPost('CustomerName');
			$formVariables['Address1'] = $this->getRequest()->getPost('Address1');
			$formVariables['Address2'] = $this->getRequest()->getPost('Address2');
			$formVariables['Address3'] = $this->getRequest()->getPost('Address3');
			$formVariables['Address4'] = $this->getRequest()->getPost('Address4');
			$formVariables['City'] = $this->getRequest()->getPost('City');
			$formVariables['State'] = $this->getRequest()->getPost('State');
			$formVariables['PostCode'] = $this->getRequest()->getPost('PostCode');
			$formVariables['CountryCode'] = $this->getRequest()->getPost('CountryCode');
			if(!TPG_PaymentFormHelper::compareHostedPaymentFormHashDigest($formVariables, $szPassword, $hmHashMethod, $szPreSharedKey))
			{
				$boError = true;
				$szNotificationMessage = "The payment was rejected for a SECURITY reason: the incoming payment data was tampered with.";
				Mage::log("The Hosted Payment Form transaction couldn't be completed for the following reason: [" . $szNotificationMessage . "]. Form variables: " . print_r($formVariables, 1));
			}
			else
			{
				$payVectorOrderId = Mage::getSingleton('checkout/session')->getTpgOrderId();
				$szOrderStatus = $order->getStatus();
				$szStatusCode = $formVariables['StatusCode'];
				$szMessage = $formVariables['Message'];
				$szPreviousStatusCode = $formVariables['PreviousStatusCode'];
				$szPreviousMessage = $formVariables['PreviousMessage'];
				$szOrderID = $formVariables['OrderID'];
				$szCrossReference = $formVariables['CrossReference'];
				$szCardType = $formVariables['CardType'];

				if($szOrderStatus != 'irc_paid' && $szOrderStatus != 'irc_preauth')
				{
					$checkout->saveOrderAfterRedirectedPaymentAction(
						true,
						$szStatusCode,
						$szMessage,
						$szPreviousStatusCode,
						$szPreviousMessage,
						$szOrderID,
						$szCrossReference,
						$szCardType
					);
				}
				else
				{
					// cart is empty
					$boCartIsEmpty = true;
					$szPaymentProcessorResponse = null;
					// check the StatusCode as the customer might have just clicked the BACK button and re-submitted the card details
					// which can cause a charge back to the merchant
					$this->_fixBackButtonBug($szOrderID, $szStatusCode, $szMessage, $szPreviousStatusCode, $szPreviousMessage);
				}
			}
		}
		catch(Exception $exc)
		{
			$boError = true;
			$szPaymentProcessorResponse = $exc->getMessage();
			$szNotificationMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_183;
			Mage::logException($exc);
		}
		$szPaymentProcessorResponse = $session->getPaymentprocessorresponse();
		if($boError)
		{
			if($szPaymentProcessorResponse != null &&
				$szPaymentProcessorResponse != ''
			)
			{
				$szNotificationMessage .= '<br/>' . $szPaymentProcessorResponse;
			}
			$model->setPaymentAdditionalInformation($order->getPayment(), $this->getRequest()->getPost('CrossReference'));
			//$order->getPayment()->setTransactionId($this->getRequest()->getPost('CrossReference'));
			if($nVersion >= 1410)
			{
				if($order)
				{
					$orderState = 'pending_payment';
					$orderStatus = 'irc_failed_hosted_payment';
					$order->setCustomerNote(Mage::helper('tpg')->__('Hosted Payment Failed'));
					$order->setState($orderState, $orderStatus, $szPaymentProcessorResponse, false);
					$order->save();
				}
			}
			if($nVersion == 1324 || $nVersion == 1330)
			{
				Mage::getSingleton('checkout/session')->addError($szNotificationMessage);
			}
			else
			{
				Mage::getSingleton('core/session')->addError($szNotificationMessage);
			}
			$order->save();
			$this->_clearSessionVariables();
			$this->_redirect('checkout/onepage/failure');
		}
		else
		{
			// set the quote as inactive after back from paypal
			Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
			if($boCartIsEmpty == false)
			{
				// send confirmation email to customer
				if($order->getId())
				{
					$order->sendNewOrderEmail();
				}
				if($nVersion >= 1410)
				{
					// TODO : no need to remove stock item as the system will do it in 1.6 version
					if($nVersion < 1600)
					{
						$model->subtractOrderedItemsFromStock($order);
					}
					$this->_updateInvoices($order, $szPaymentProcessorResponse);
				}
				if($nVersion != 1324 && $nVersion != 1330)
				{
					if($szPaymentProcessorResponse != '')
					{
						Mage::getSingleton('core/session')->addSuccess($szPaymentProcessorResponse);
					}
				}
			}
			$this->_redirect('checkout/onepage/success', array('_secure' => true));
		}
	}

	/**
	 * Action logic for handling the server to server communication in case of Result Delivery Method = SERVER
	 *
	 */
	public function serverresultAction()
	{
		$boError = false;
		$model = Mage::getModel('tpg/direct');
		$checkout = Mage::getSingleton('checkout/type_onepage');
		$szOrderID = $this->getRequest()->getPost('OrderID');
		$szMessage = $this->getRequest()->getPost('Message');
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		try
		{
			// finish off the transaction: if StatusCode = 0 create an order otherwise do nothing
			$checkout->saveOrderAfterRedirectedPaymentAction(
				true,
				$this->getRequest()->getPost('StatusCode'),
				$szMessage,
				$this->getRequest()->getPost('PreviousStatusCode'),
				$this->getRequest()->getPost('PreviousMessage'),
				$this->getRequest()->getPost('OrderID'),
				$this->getRequest()->getPost('CrossReference')
			);
		}
		catch(Exception $exc)
		{
			$boError = true;
			$szNotificationMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_183;
			$szErrorMessage = $exc->getMessage();
			Mage::logException($exc);
		}
		if($boError == true)
		{
			$this->getResponse()->setBody('StatusCode=30&Message=' . $szErrorMessage);
		}
		else
		{
			$order = Mage::getModel('sales/order');
			$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
			// set the quote as inactive after back from paypal
			Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
			// send confirmation email to customer
			if($order->getId())
			{
				$order->sendNewOrderEmail();
			}
			// if the payment was successful clear the session so that if the customer navigates back to the Magento store
			// the shopping cart will be emptied rather than 'uncomplete'
			if($this->getRequest()->getPost('StatusCode') == '0')
			{
				Mage::getSingleton('checkout/session')->clear();
				if($nVersion >= 1410)
				{
					if($nVersion < 1600)
					{
						$model->subtractOrderedItemsFromStock($order);
					}
					$this->_updateInvoices($order, $szMessage);
				}
			}
			$this->getResponse()->setBody('StatusCode=0');
		}
	}

	/*
	 * Action logic to handle the SERVER_PUSH web request to the PaymentFormResultHandler.ashx to get the transaction result details
	 */
	public function serverpullresultAction()
	{
		$boError = false;
		$nStartIndex = false;
		//
		$szHashDigest = false;
		$szMerchantID = false;
		$szCrossReference = false;
		$szOrderID = false;
		//
		$nErrorNumber = false;
		$szErrorMessage = false;
		$model = Mage::getModel('tpg/direct');
		$checkout = Mage::getSingleton('checkout/type_onepage');
		$szServerPullURL = $model->getConfigData('serverpullresultactionurl');
		$szMerchantID = $model->getConfigData('merchantid');
		$szPassword = $model->getConfigData('password');
		$hmHashMethod = $model->getConfigData('hashmethod');
		$szPreSharedKey = $model->getConfigData('presharedkey');
		$szURLVariableString = $this->getRequest()->getRequestUri();
		$nStartIndex = strpos($szURLVariableString, "?");
		$order = Mage::getModel('sales/order');
		$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		if(!is_int($nStartIndex))
		{
			$szErrorMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_309;
			Mage::log(PayVector_Tpg_Model_Common_GlobalErrors::ERROR_309 . " Request URI: " . $szURLVariableString);
		}
		else
		{
			$szURLVariableString = substr($szURLVariableString, $nStartIndex + 1);
			$arFormVariables = TPG_PaymentFormHelper::getVariableCollectionFromString($szURLVariableString);
			if(!TPG_PaymentFormHelper::compareServerHashDigest($arFormVariables, $szPassword, $hmHashMethod, $szPreSharedKey))
			{
				// report an error message
				$szErrorMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_304;
			}
			else
			{
				$szOrderID = $arFormVariables["OrderID"];
				$szCrossReference = $arFormVariables["CrossReference"];
				$szPostFields = "MerchantID=" . urlencode($szMerchantID) . "&Password=" . urlencode($szPassword) . "&CrossReference=" . urlencode($szCrossReference);
				//$szPostFields = "MerchantID=".$szMerchantID."&Password=".$szPassword."&CrossReference=".$szCrossReference;
				$cCurl = curl_init();
				curl_setopt($cCurl, CURLOPT_URL, $szServerPullURL);
				curl_setopt($cCurl, CURLOPT_POST, true);
				curl_setopt($cCurl, CURLOPT_POSTFIELDS, $szPostFields);
				curl_setopt($cCurl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($cCurl, CURLOPT_ENCODING, "UTF-8");
				curl_setopt($cCurl, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($cCurl, CURLOPT_SSL_VERIFYHOST, false);
				$response = curl_exec($cCurl);
				$nErrorNumber = curl_errno($cCurl);
				$szErrorMessage = curl_error($cCurl);
				curl_close($cCurl);
				if(is_int($nErrorNumber) &&
					$nErrorNumber > 0
				)
				{
					Mage::log(
						"Error happened while trying to retrieve the transaction result details for a SERVER_PULL method for CrossReference: " .
						$szCrossReference .
						". Error code: " .
						$nErrorNumber .
						", message: " .
						$szErrorMessage
					);
					// suppress the message and use customer friendly instead
					$szErrorMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_329 . " Message: " . $szErrorMessage;
				}
				else
				{
					// synchronize of the Magento backend with the transcation result
					try
					{
						// get the response items
						$responseItems = TPG_PaymentFormHelper::getVariableCollectionFromString($response);
						$szStatusCode = $responseItems["StatusCode"];
						$szMessage = $responseItems["Message"];
						$transactionResult = $responseItems["TransactionResult"];
						if($szStatusCode !== '0')
						{
							$szErrorMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_381;
							$szErrorMessage .= " Message: " . $szMessage;
						}
						else
						{
							// URL decode the transaction result variable and get the transaction result sub variables
							$transactionResult = urldecode($transactionResult);
							$transactionResult = TPG_PaymentFormHelper::getVariableCollectionFromString($transactionResult);
							// create the order item in the Magento backend
							$szStatusCode = isset($transactionResult["StatusCode"]) ? $transactionResult["StatusCode"] : false;
							$szMessage = isset($transactionResult["Message"]) ? $transactionResult["Message"] : false;
							$szPreviousStatusCode = $szStatusCode;
							$szPreviousMessage = $szMessage;
							$checkout->saveOrderAfterRedirectedPaymentAction(
								true,
								$szStatusCode,
								$szMessage,
								$szPreviousStatusCode,
								$szPreviousMessage,
								$szOrderID,
								$szCrossReference
							);
						}
					}
					catch(Exception $exc)
					{
						$boError = true;
						$szPaymentProcessorResponse = $exc->getMessage();
						$szErrorMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_183;
						Mage::logException($exc);
					}
				}
			}
		}
		if($boError)
		{
			if($szPaymentProcessorResponse != null &&
				$szPaymentProcessorResponse != ''
			)
			{
				$szErrorMessage .= '<br/>' . $szPaymentProcessorResponse;
			}
			$model->setPaymentAdditionalInformation($order->getPayment(), $szCrossReference);
			//$order->getPayment()->setTransactionId($szCrossReference);
			if($nVersion >= 1410)
			{
				if($order)
				{
					$orderState = 'pending_payment';
					$orderStatus = 'irc_failed_hosted_payment';
					$order->setCustomerNote(Mage::helper('tpg')->__('Hosted Payment Failed'));
					$order->setState($orderState, $orderStatus, $szErrorMessage, false);
					$order->save();
				}
			}
			if($nVersion == 1324 || $nVersion == 1330)
			{
				Mage::getSingleton('checkout/session')->addError($szErrorMessage);
			}
			else
			{
				Mage::getSingleton('core/session')->addError($szErrorMessage);
			}
			$order->save();
			$this->_clearSessionVariables();
			$this->_redirect('checkout/onepage/failure');
		}
		else
		{
			// set the quote as inactive after back from paypal
			Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
			// send confirmation email to customer
			if($order->getId())
			{
				$order->sendNewOrderEmail();
			}
			if($nVersion >= 1410)
			{
				if($nVersion < 1600)
				{
					$model->subtractOrderedItemsFromStock($order);
				}
				$this->_updateInvoices($order, $szMessage);
			}
			if($nVersion != 1324 && $nVersion != 1330)
			{
				Mage::getSingleton('core/session')->addSuccess('Payment Processor Response: ' . $szMessage);
			}
			$this->_redirect('checkout/onepage/success', array('_secure' => true));
		}
	}

	/**
	 * Action logic for handling the result set from the Transparent Redirect page
	 *
	 */
	public function callbacktransparentredirectAction()
	{
		$model = Mage::getModel('tpg/direct');
		$order = Mage::getModel('sales/order');
		$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		try
		{
			$hmHashMethod = $model->getConfigData('hashmethod');
			$szPassword = $model->getConfigData('password');
			$szPreSharedKey = $model->getConfigData('presharedkey');
			$szPaREQ = $this->getRequest()->getPost('PaREQ');
			$szPaRES = $this->getRequest()->getPost('PaRes');
			$nStatusCode = $this->getRequest()->getPost('StatusCode');
			if(isset($szPaREQ))
			{
				// 3D Secure authentication required
				self::_threeDSecureAuthenticationRequired($szPassword, $hmHashMethod, $szPreSharedKey);
			}
			else if(isset($szPaRES))
			{
				// 3D Secure post authentication
				self::_postThreeDSecureAuthentication($szPassword, $hmHashMethod, $szPreSharedKey);
			}
			else
			{
				// payment complete
				self::_paymentComplete($szPassword, $hmHashMethod, $szPreSharedKey);
			}
		}
		catch(Exception $exc)
		{
			$error = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_260;
			Mage::logException($exc);
			if($nVersion >= 1410)
			{
				if($order)
				{
					$orderState = 'pending_payment';
					$orderStatus = 'irc_failed_hosted_payment';
					$order->setCustomerNote(Mage::helper('tpg')->__('Transparent Redirect Payment Failed'));
					$order->setState($orderState, $orderStatus, $exc->getMessage(), false);
					$order->save();
				}
			}
			if($nVersion == 1324 || $nVersion == 1330)
			{
				Mage::getSingleton('checkout/session')->addError($error);
			}
			else
			{
				Mage::getSingleton('core/session')->addError($error);
			}
			$this->_clearSessionVariables();
			$this->_redirect('checkout/onepage/failure');
		}
	}

	private function _threeDSecureAuthenticationRequired($szPassword, $hmHashMethod, $szPreSharedKey)
	{
		$error = false;
		$formVariables = array();
		$formVariables['HashDigest'] = $this->getRequest()->getPost('HashDigest');
		$formVariables['MerchantID'] = $this->getRequest()->getPost('MerchantID');
		$formVariables['StatusCode'] = $this->getRequest()->getPost('StatusCode');
		$formVariables['Message'] = $this->getRequest()->getPost('Message');
		$formVariables['CrossReference'] = $this->getRequest()->getPost('CrossReference');
		$formVariables['OrderID'] = $this->getRequest()->getPost('OrderID');
		$formVariables['TransactionDateTime'] = $this->getRequest()->getPost('TransactionDateTime');
		$formVariables['ACSURL'] = $this->getRequest()->getPost('ACSURL');
		$formVariables['PaREQ'] = $this->getRequest()->getPost('PaREQ');
		if(!TPG_PaymentFormHelper::compareThreeDSecureAuthenticationRequiredHashDigest($formVariables, $szPassword, $hmHashMethod, $szPreSharedKey))
		{
			$error = "The payment was rejected for a SECURITY reason: the incoming payment data was tampered with.";
			Mage::log("The Transparent Redirect transaction couldn't be completed for the following reason: " . $error . " Form variables: " . print_r($formVariables, 1));
		}
		if($error)
		{
			$this->_clearSessionVariables();
			//Mage::getSingleton('core/session')->addError($error);
			//$this->_redirect('checkout/onepage/failure');
			Mage::throwException($error);
		}
		else
		{
			// redirect to a secure 3DS authentication page
			Mage::getSingleton('checkout/session')->setMd($formVariables['CrossReference'])
				->setAcsurl($formVariables['ACSURL'])
				->setPareq($formVariables['PaREQ'])
				->setTermurl('tpg/payment/callbacktransparentredirect');
			// redirect to a 3D Secure page
			$this->_redirect('tpg/payment/threedsecure');
		}
	}

	private function _postThreeDSecureAuthentication($szPassword, $hmHashMethod, $szPreSharedKey)
	{
		$error = false;
		$formVariables = array();
		$model = Mage::getModel('tpg/direct');
		$szPaRES = $this->getRequest()->getPost('PaRes');
		$szCrossReference = $this->getRequest()->getPost('MD');
		$szMerchantID = $model->getConfigData('merchantid');
		$szTransactionDateTime = date('Y-m-d H:i:s P');
		$szCallbackURL = Mage::getUrl('tpg/payment/callbacktransparentredirect', array('_secure' => true));
		$szHashDigest =
			TPG_PaymentFormHelper::calculatePostThreeDSecureAuthenticationHashDigest(
				$szMerchantID,
				$szPassword,
				$hmHashMethod,
				$szPreSharedKey,
				$szPaRES,
				$szCrossReference,
				$szTransactionDateTime,
				$szCallbackURL
			);
		Mage::getSingleton('checkout/session')->setHashdigest($szHashDigest)
			->setMerchantid($szMerchantID)
			->setCrossreference($szCrossReference)
			->setTransactiondatetime($szTransactionDateTime)
			->setCallbackurl($szCallbackURL)
			->setPares($szPaRES);
		// redirect to the redirection bridge page
		$this->_redirect('tpg/payment/redirect');
	}

	private function _paymentComplete($szPassword, $hmHashMethod, $szPreSharedKey)
	{
		$boError = false;
		$formVariables = array();
		$model = Mage::getModel('tpg/direct');
		$szOrderID = $this->getRequest()->getPost('OrderID');
		$checkout = Mage::getSingleton('checkout/type_onepage');
		$session = Mage::getSingleton('checkout/session');
		$szPaymentProcessorResponse = '';
		$order = Mage::getModel('sales/order');
		$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
		$nVersion = Mage::getModel('tpg/direct')->getVersion();
		$boCartIsEmpty = false;
		try
		{
			$formVariables['HashDigest'] = $this->getRequest()->getPost('HashDigest');
			$formVariables['MerchantID'] = $this->getRequest()->getPost('MerchantID');
			$formVariables['StatusCode'] = $this->getRequest()->getPost('StatusCode');
			$formVariables['Message'] = $this->getRequest()->getPost('Message');
			$formVariables['PreviousStatusCode'] = $this->getRequest()->getPost('PreviousStatusCode');
			$formVariables['PreviousMessage'] = $this->getRequest()->getPost('PreviousMessage');
			$formVariables['CrossReference'] = $this->getRequest()->getPost('CrossReference');
			$formVariables['AddressNumericCheckResult'] = $this->getRequest()->getPost('AddressNumericCheckResult');
			$formVariables['PostCodeCheckResult'] = $this->getRequest()->getPost('PostCodeCheckResult');
			$formVariables['CV2CheckResult'] = $this->getRequest()->getPost('CV2CheckResult');
			$formVariables['ThreeDSecureAuthenticationCheckResult'] = $this->getRequest()->getPost('ThreeDSecureAuthenticationCheckResult');
			$formVariables['CardType'] = $this->getRequest()->getPost('CardType');
			$formVariables['CardClass'] = $this->getRequest()->getPost('CardClass');
			$formVariables['CardIssuer'] = $this->getRequest()->getPost('CardIssuer');
			$formVariables['CardIssuerCountryCode'] = $this->getRequest()->getPost('CardIssuerCountryCode');
			$formVariables['Amount'] = $this->getRequest()->getPost('Amount');
			$formVariables['CurrencyCode'] = $this->getRequest()->getPost('CurrencyCode');
			$formVariables['OrderID'] = $this->getRequest()->getPost('OrderID');
			$formVariables['TransactionType'] = $this->getRequest()->getPost('TransactionType');
			$formVariables['TransactionDateTime'] = $this->getRequest()->getPost('TransactionDateTime');
			$formVariables['OrderDescription'] = $this->getRequest()->getPost('OrderDescription');
			$formVariables['Address1'] = $this->getRequest()->getPost('Address1');
			$formVariables['Address2'] = $this->getRequest()->getPost('Address2');
			$formVariables['Address3'] = $this->getRequest()->getPost('Address3');
			$formVariables['Address4'] = $this->getRequest()->getPost('Address4');
			$formVariables['City'] = $this->getRequest()->getPost('City');
			$formVariables['State'] = $this->getRequest()->getPost('State');
			$formVariables['PostCode'] = $this->getRequest()->getPost('PostCode');
			$formVariables['CountryCode'] = $this->getRequest()->getPost('CountryCode');
			$formVariables['EmailAddress'] = $this->getRequest()->getPost('EmailAddress');
			$formVariables['PhoneNumber'] = $this->getRequest()->getPost('PhoneNumber');
			if(!TPG_PaymentFormHelper::comparePaymentCompleteHashDigest($formVariables, $szPassword, $hmHashMethod, $szPreSharedKey))
			{
				$boError = true;
				$szNotificationMessage = "The payment was rejected for a SECURITY reason: the incoming payment data was tampered with.";
				Mage::log("The Transparent Redirect transaction couldn't be completed for the following reason: [" . $szNotificationMessage . "] Form variables: " . print_r($formVariables, 1));
			}
			else
			{
				$payVectorOrderId = Mage::getSingleton('checkout/session')->getTpgOrderId();
				$szOrderStatus = $order->getStatus();
				if($szOrderStatus != 'irc_paid' &&
					$szOrderStatus != 'irc_preauth'
				)
				{
					$checkout->saveOrderAfterRedirectedPaymentAction(
						false,
						$this->getRequest()->getPost('StatusCode'),
						$this->getRequest()->getPost('Message'),
						$this->getRequest()->getPost('PreviousStatusCode'),
						$this->getRequest()->getPost('PreviousMessage'),
						$this->getRequest()->getPost('OrderID'),
						$this->getRequest()->getPost('CrossReference')
					);
				}
				else
				{
					$boCartIsEmpty = true;
					$szPaymentProcessorResponse = null;
					// chek the StatusCode as the customer might have just clicked the BACK button and re-submitted the card details
					// which can cause a charge back to the merchant
					$szStatusCode = $this->getRequest()->getPost('StatusCode');
					$szMessage = $this->getRequest()->getPost('Message');
					$szPreviousStatusCode = $this->getRequest()->getPost('PreviousStatusCode');
					$szPreviousMessage = $this->getRequest()->getPost('PreviousMessage');
					$szOrderID = $this->getRequest()->getPost('OrderID');
					$this->_fixBackButtonBug($szOrderID, $szStatusCode, $szMessage, $szPreviousStatusCode, $szPreviousMessage);
				}
			}
		}
		catch(Exception $exc)
		{
			$boError = true;
			$szNotificationMessage = PayVector_Tpg_Model_Common_GlobalErrors::ERROR_183;
			Mage::logException($exc);
		}
		$szPaymentProcessorResponse = $session->getPaymentprocessorresponse();
		if($boError == true)
		{
			if($szPaymentProcessorResponse != null &&
				$szPaymentProcessorResponse != ''
			)
			{
				$szNotificationMessage = $szNotificationMessage . '<br/>' . $szPaymentProcessorResponse;
			}
			$model->setPaymentAdditionalInformation($order->getPayment(), $this->getRequest()->getPost('CrossReference'));
			//$order->getPayment()->setTransactionId($this->getRequest()->getPost('CrossReference'));
			if($nVersion >= 1410)
			{
				if($order)
				{
					$orderState = 'pending_payment';
					$orderStatus = 'irc_failed_hosted_payment';
					$order->setCustomerNote(Mage::helper('tpg')->__('Transparent Redirect Payment Failed'));
					$order->setState($orderState, $orderStatus, $szPaymentProcessorResponse, false);
				}
			}
			$order->save();
			if($nVersion == 1324 || $nVersion == 1330)
			{
				Mage::getSingleton('checkout/session')->addError($szNotificationMessage);
			}
			else
			{
				Mage::getSingleton('core/session')->addError($szNotificationMessage);
			}
			$this->_clearSessionVariables();
			$this->_redirect('checkout/onepage/failure');
		}
		else
		{
			// set the quote as inactive after back from paypal
			Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
			if($boCartIsEmpty == false)
			{
				// send confirmation email to customer
				if($order->getId())
				{
					$order->sendNewOrderEmail();
				}
				if($nVersion >= 1410)
				{
					// TODO : no need to remove stock item as the system will do it in 1.6 version
					if($nVersion < 1600)
					{
						$model->subtractOrderedItemsFromStock($order);
					}
					$this->_updateInvoices($order, $szPaymentProcessorResponse);
				}
				if($nVersion != 1324 && $nVersion != 1330)
				{
					if($szPaymentProcessorResponse != '')
					{
						Mage::getSingleton('core/session')->addSuccess($szPaymentProcessorResponse);
					}
				}
			}
			$this->_redirect('checkout/onepage/success', array('_secure' => true));
		}
	}

	private function _clearSessionVariables()
	{
		// clear all the custom session variables used in the payment module in case of a failed payment
		Mage::getSingleton('checkout/session')->setHashdigest(null)
			->setMerchantid(null)
			->setCrossreference(null)
			->setTransactiondatetime(null)
			->setCallbackurl(null)
			->setPareq(null)
			->setPares(null)
			->setMd(null)
			->setAcsurl(null)
			->setTermurl(null)
			->setThreedsecurerequired(null)
			->setIshostedpayment(null)
			->setStatuscode(null)
			->setMessage(null)
			->setPreviousstatuscode(null)
			->setPreviousmessage(null)
			->setOrderid(null)
			->setRedirectedPayment(null);
		// do not clear the order id as after the a failed payment the customer still might want to repeat the payment attempt
		//->setTpgOrderId(null);
	}

	/**
	 * Set the invoice status to "Paid" after a successful payment
	 *
	 * @param Mage_Core_Model_Abstract $order
	 * @param string       $message
	 */
	private function _updateInvoices($order, $message)
	{
		$invoices = $order->getInvoiceCollection();
		$state = Mage_Sales_Model_Order::STATE_PROCESSING;
		$payment = $order->getPayment();
		$session = Mage::getSingleton('checkout/session');
		$transactionId = $payment->getLastTransId();
		$transaction = $payment->getTransaction($transactionId);
		$transactionType = $transaction->getTxnType();

		if($session->getNewCrossReference())
		{
			$szNewCrossReference = $session->getNewCrossReference();
			$value = $transaction->setTxnId($szNewCrossReference);
			$transaction->save();
			$payment->setLastTransId($szNewCrossReference);
			$session->setNewCrossReference(null);
		}
		foreach($invoices as $invoice)
		{
			// set the invoice state to be "Paid"
			$invoice->pay()->save();
		}
		// add a comment to the order comments
		if($transactionType == 'authorization')
		{
			$order->setState($state, 'irc_preauth', $message, true);
		}
		else if($transactionType == 'capture')
		{
			$order->setState($state, 'irc_paid', $message, true);
		}
		else
		{
			Mage::throwException('invalid transaction type [' . $transactionType . '] for invoice updating');
		}
		$order->save();
	}

	private function _fixBackButtonBug($szOrderID, $szStatusCode, $szMessage, $szPreviousStatusCode, $szPreviousMessage)
	{
		// check the payment type as hitting the BACK button in the browser for Transparent Redirect payment method only redirects back the client side result and
		// not letting the customer to change the card details or re-submitting the payment
		$mode = Mage::getModel('tpg/direct')->getConfigData('mode');
		$boIgnoreDuplicateMessage = false;
		if($mode == PayVector_Tpg_Model_Source_PaymentMode::PAYMENT_MODE_TRANSPARENT_REDIRECT)
		{
			$boIgnoreDuplicateMessage = true;
		}
		if($boIgnoreDuplicateMessage)
		{
			Mage::getSingleton('core/session')->addError(
				'ERROR - Order ID: ' .
				$szOrderID .
				' has already been successfully paid and processed. Payment Processor Response: ' .
				$szMessage .
				'. <br/> IMPORTANT: please do not attempt to click the back button in your browser as it could cause duplicate charges to your bank account.'
			);
		}
		else
		{
			if($szStatusCode == '0')
			{
				Mage::getSingleton('core/session')->addError(
					'ERROR - Duplicate payment for Order ID: ' .
					$szOrderID .
					' with Payment Processor Response: ' .
					$szMessage .
					'. This order has already been successfully paid and processed. Please contact us immediately to avoid duplicate charges to your bank account.'
				);
			}
			else if($szStatusCode == '20')
			{
				Mage::getSingleton('core/session')->addError(
					'Duplicate payment attempted for Order ID: ' .
					$szOrderID .
					'. Previous Payment Processor Response: ' .
					$szPreviousMessage .
					'. This order has already been successfully paid and processed. </br/>IMPORTANT: please do not attempt to click the back button in your browser and re-submit the payment for this order as it could cause duplicate charges to your bank account.'
				);
			}
			else
			{
				Mage::getSingleton('core/session')->addError(
					'ERROR: Order ID: ' .
					$szOrderID .
					' has already been successfully paid and processed. Payment Processor Response: ' .
					$szMessage .
					'. Please contact us immediately to avoid duplicate charges to your bank account.'
				);
			}
		}
	}

	/**
	 * Refund actioned when the user clicks the VOID button in the admin backend
	 *
	 * @return Zend_Controller_Response_Abstract
	 */
	public function voidAction()
	{
		$model = Mage::getSingleton('tpg/direct');
		$parameters = $this->getRequest()->getParams();
		$szOrderID = $parameters['OrderID'];
		$szCrossReference = $parameters['CrossReference'];
		$order = Mage::getModel('sales/order')->loadByIncrementId((int) $szOrderID);
		$payment = $order->getPayment();
		$result = Mage::getModel('tpg/direct')->ircVoid($payment);
		if($result == "0")
		{
			$model->addOrderedItemsToStock($order);
		}

		return $this->getResponse()->setBody($result);
	}

	/**
	 * Refund actioned when the user clicks the COLLECT button in the admin backend
	 *
	 * @return Zend_Controller_Response_Abstract
	 */
	public function collectionAction()
	{
		$parameters = $this->getRequest()->getParams();
		$szOrderID = $parameters['OrderID'];
		$szCrossReference = $parameters['CrossReference'];
		$order = Mage::getModel('sales/order')->loadByIncrementId((int) $szOrderID);
		$payment = $order->getPayment();
		$result = Mage::getModel('tpg/direct')->ircCollection($payment, $szOrderID, $szCrossReference);

		return $this->getResponse()->setBody($result);
	}
}
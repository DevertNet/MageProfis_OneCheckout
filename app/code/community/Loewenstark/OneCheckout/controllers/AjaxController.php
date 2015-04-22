<?php 

/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Loewenstark Magento License (LML 1.0).
 * It is  available through the world-wide-web at this URL:
 * http://www.loewenstark.de/licenses/lml-1.0.html
 * If you are unable to obtain it through the world-wide-web, please send an 
 * email to license@loewenstark.de so we can send you a copy immediately.
 *
 * @category   Loewenstark
 * @package    Loewenstark_OneCheckout
 * @copyright  Copyright (c) 2012 Ulrich Abelmann
 * @copyright  Copyright (c) 2012 wwg.l�wenstark im Internet GmbH
 * @license    http://www.loewenstark.de/licenses/lml-1.0.html  Loewenstark Magento License (LML 1.0)
 */

class Loewenstark_OneCheckout_AjaxController extends Mage_Core_Controller_Front_Action {
	
    protected function getOnepage() {
        return Mage::getSingleton('checkout/type_onepage');
    }

    protected function getCheckout() {
        return Mage::getSingleton('checkout/type_onepage');
    }
	
    protected function getQuote() {
        return $this->getOnepage()->getQuote();
    }

    protected function _ajaxRedirectResponse() {
        $this->getResponse()
            ->setHeader('HTTP/1.1', '403 Session Expired')
            ->setHeader('Login-Required', 'true')
            ->sendResponse();
        return $this;
    }

    protected function _expireAjax() {
		$quote = $this->getQuote();
        if (!$quote->hasItems() || $quote->getHasError() || $quote->getIsMultiShipping()) {
            $this->_ajaxRedirectResponse();
            return true;
        }
        $action = $this->getRequest()->getActionName();
        if (Mage::getSingleton('checkout/session')->getCartWasUpdated(true)) {
            $this->_ajaxRedirectResponse();
            return true;
        }

        return false;
    }

    protected function _getShippingMethodsHtml() {
        $layout = $this->getLayout();
        $update = $layout->getUpdate();
        $update->load('checkout_onepage_shippingmethod');
        $layout->generateXml();
        $layout->generateBlocks();
        $output = $layout->getOutput();
        return $output;
    }

    protected function _getPaymentMethodsHtml() {
        $layout = $this->getLayout();
        $update = $layout->getUpdate();
        $update->load('checkout_onepage_paymentmethod');
        $layout->generateXml();
        $layout->generateBlocks();
        $output = $layout->getOutput();
        return $output;
    }
	
	protected function _getReviewHtml() {
        $layout = $this->getLayout();
        $update = $layout->getUpdate();
        $update->load('onecheckout_ajax_review');
        $layout->generateXml();
        $layout->generateBlocks();
        $output = $layout->getOutput();
        return $output;
    }

	private function _merge($result, $tmpResult) {
		if (is_array($tmpResult)) {
			$result = array_merge_recursive($result, $tmpResult);
		}
		return $result;
	}
	
	public function preLoginAction() {
		Mage::getSingleton('customer/session')->setBeforeAuthUrl(Mage::getUrl("onecheckout"));
		die(1);
	}

   	public function updateAction() {
        if ($this->_expireAjax()) {
            return;
        }

		$this->importDataToAddresses();
		$result = array();
		$result = $this->_merge($result, Mage::helper("onecheckout")->setAddresses());
		$result = $this->_merge($result, $this->saveShippingMethod());
		$result = $this->_merge($result, $this->savePayment());
   
        $this->getQuote()->setTotalsCollectedFlag(false)->collectTotals()->save();
       
		$result['updates']['checkout-review-load'] = $this->_getReviewHtml();
		$result['updates']['checkout-shipping-method-load'] = $this->_getShippingMethodsHtml();
		$result['updates']['checkout-payment-method-load'] = $this->_getPaymentMethodsHtml();
		
	   	$this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }	

	private function importDataToAddresses() {
		$billingAddressId = $this->getRequest()->getPost('billing_address_id', false);
		$billingData = $this->getRequest()->getPost('billing', array());

		if (!$billingAddressId && !empty($billingData)) {
			$address = $this->getQuote()->getBillingAddress();
			$addressForm = Mage::getModel('customer/form');
			$addressForm->setFormCode('customer_address_edit')
				->setEntityType('customer_address')
				->setIsAjaxRequest(true);
 			$addressForm->setEntity($address);
            $addressData = $addressForm->extractData($addressForm->prepareRequest($billingData));
            $addressForm->compactData($addressData);
		}
	
		$usingCase = isset($billingData['use_for_shipping']) ? (int)$billingData['use_for_shipping'] : 0;
		if ($usingCase) {
			$shippingData = $billingData;
		} else {
			$shippingData = $this->getRequest()->getPost('shipping', array());
		}
		$shippingAddressId = $this->getRequest()->getPost('shipping_address_id', false);
		if (!$shippingAddressId && !empty($shippingData)) {
			$address = $this->getQuote()->getShippingAddress();
			$addressForm = Mage::getModel('customer/form');
			$addressForm->setFormCode('customer_address_edit')
				->setEntityType('customer_address')
				->setIsAjaxRequest(true);
 			$addressForm->setEntity($address);
            $addressData = $addressForm->extractData($addressForm->prepareRequest($shippingData));
            $addressForm->compactData($addressData);
		}
	}

    protected function saveShippingMethod() {
        if ($this->_expireAjax()) {
            return;
        }
        if ($this->getRequest()->isPost()) {
            $data = $this->getRequest()->getPost('shipping_method', '');
            $result = $this->getCheckout()->saveShippingMethod($data);
            /*
            $result will have erro data if shipping method is empty
            */
            if(!$result) {
                Mage::dispatchEvent('checkout_controller_onepage_save_shipping_method',
                        array('request'=>$this->getRequest(),
                            'quote'=>$this->getQuote()));
                $this->getQuote()->collectTotals();
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
            }
            return $result;
        }
    }

    protected function savePayment() {
        if ($this->_expireAjax()) {
            return;
        }
        try {
            if (!$this->getRequest()->isPost()) {
                $this->_ajaxRedirectResponse();
                return;
            }

            // set payment to quote
            $result = array();
            $data = $this->getRequest()->getPost('payment', array());
            $result = $this->getCheckout()->savePayment($data);

            // get section and redirect data
            $redirectUrl = $this->getQuote()->getPayment()->getCheckoutRedirectUrl();
            if (empty($result['error']) && !$redirectUrl) {
                $this->loadLayout('checkout_onepage_review');
                $result['goto_section'] = 'review';
            }
            if ($redirectUrl) {
                $result['redirect'] = $redirectUrl;
            }
        } catch (Mage_Payment_Exception $e) {
            if ($e->getFields()) {
                $result['fields'] = $e->getFields();
            }
            $result['error'] = $e->getMessage();
        } catch (Mage_Core_Exception $e) {
            $result['error'] = $e->getMessage();
        } catch (Exception $e) {
            Mage::logException($e);
            $result['error'] = $this->__('Unable to set Payment Method.');
        }
        return $result;
    }
	
}

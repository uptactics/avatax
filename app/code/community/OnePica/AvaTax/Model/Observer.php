<?php
/**
 * OnePica_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0), a
 * copy of which is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 * @copyright  Copyright (c) 2009 One Pica, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

/**
 * Avatax Observer
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 */
class OnePica_AvaTax_Model_Observer extends Mage_Core_Model_Abstract
{
    /**
     * Avalara lib classes
     *
     * @var array
     */
    protected static $_classes = array(
        'TaxRequest',
        'PostTaxRequest',
        'PostTaxResult',
        'CommitTaxRequest',
        'CommitTaxResult',
        'CancelTaxRequest',
        'CancelTaxResult',
        'Enum',
        'CancelCode',
        'ATConfig',
        'ATObject',
        'DynamicSoapClient',
        'AvalaraSoapClient',
        'AddressServiceSoap',
        'Address',
        'Enum',
        'TextCase',
        'Message',
        'SeverityLevel',
        'ValidateRequest',
        'ValidateResult',
        'ValidAddress',
        'TaxServiceSoap',
        'GetTaxRequest',
        'DocumentType',
        'DetailLevel',
        'Line',
        'ServiceMode',
        'GetTaxResult',
        'TaxLine',
        'TaxDetail',
        'PingResult',
        'TaxOverride',
        'TaxOverrideType'
    );

    /**
     * Sets the collectTotals tax node based on the extensions enabled/disabled status
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesQuoteCollectTotalsBefore(Varien_Event_Observer $observer)
    {
        $storeId = $observer->getEvent()->getQuote()->getStoreId();
        if (Mage::getStoreConfig('tax/avatax/action', $storeId) != OnePica_AvaTax_Model_Config::ACTION_DISABLE) {
            Mage::getConfig()->setNode('global/sales/quote/totals/tax/class', 'avatax/sales_quote_address_total_tax');
        }
        return $this;
    }

    /**
     * Create a sales invoice record in Avalara
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesOrderInvoiceSaveAfter(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Order_Invoice $invoice */
        $invoice = $observer->getEvent()->getInvoice();

        $existingInvoiceInQueue = Mage::getModel('avatax_records/queue')
            ->loadInvoiceByIncrementId($invoice->getIncrementId());
        if ($existingInvoiceInQueue->getId()) {
            return $this;
        }

        /* do not post pending or cancelled invoices */
        if ($invoice->getState() != Mage_Sales_Model_Order_Invoice::STATE_PAID) {
            return $this;
        }
        /* if the previous state was unpaid, process now */
        if (!$invoice->getOrigData($invoice->getIdFieldName()
                && $invoice->getOrigData('state') != Mage_Sales_Model_Order_Invoice::STATE_OPEN)
            && $this->_getDataHelper()->isObjectActionable($invoice)
        ) {
            Mage::getModel('avatax_records/queue')
                ->setEntity($invoice)
                ->setType(OnePica_AvaTax_Model_Records_Queue::QUEUE_TYPE_INVOICE)
                ->setStatus(OnePica_AvaTax_Model_Records_Queue::QUEUE_STATUS_PENDING)
                ->save();
        }
        return $this;
    }

    /**
     * Create a return invoice record in Avalara
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesOrderCreditmemoSaveAfter(Varien_Event_Observer $observer)
    {
        /* @var $creditmemo Mage_Sales_Model_Order_Creditmemo */
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo->getOrigData($creditmemo->getIdFieldName())
            && $this->_getDataHelper()->isObjectActionable($creditmemo)
        ) {
            Mage::getModel('avatax_records/queue')
                ->setEntity($creditmemo)
                ->setType(OnePica_AvaTax_Model_Records_Queue::QUEUE_TYPE_CREDITMEMEO)
                ->setStatus(OnePica_AvaTax_Model_Records_Queue::QUEUE_STATUS_PENDING)
                ->save();
        }
        return $this;
    }

    /**
     * Validate addresses when multishipping checkout on set shipping items
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function multishippingSetShippingItems(Varien_Event_Observer $observer)
    {
        // skip validation if customer wants to add new address
        if (Mage::app()->getRequest()->getParam('new_address')) {
            return $this;
        }

        /* @var $quote Mage_Sales_Model_Quote */
        $quote = $observer->getEvent()->getQuote();

        $errors = array();
        $normalized = false;
        $store = Mage::getModel('core/store')->load($quote->getStoreId());
        $addresses = $quote->getAllShippingAddresses();
        $message = Mage::getStoreConfig('tax/avatax/validate_address_message', $store);
        foreach ($addresses as $address) {
            /* @var $address OnePica_AvaTax_Model_Sales_Quote_Address */
            if ($address->validate() !== true) {
                $errors[] = sprintf($message, $address->format('oneline'));
            }
            if ($address->getAddressNormalized()) {
                $normalized = true;
            }
        }

        $session = Mage::getSingleton('checkout/session');
        if ($normalized) {
            $session->addNotice(Mage::getStoreConfig('tax/avatax/multiaddress_normalize_message', $store));
        }

        if (!empty($errors)) {
            throw new Mage_Core_Exception(implode('<br />', $errors));
        }
        return $this;
    }

    /**
     * Observer push data to Avalara
     *
     * @param Mage_Cron_Model_Schedule $schedule
     * @return $this;
     */
    public function processQueue($schedule)
    {
        Mage::getModel('avatax_records/queue_process')->run();
        return $this;
    }

    /**
     * Test for required values when admin config setting related to the this extension are changed
     *
     * @param Varien_Event_Observer $observer
     * @return bool
     */
    public function adminSystemConfigChangedSectionTax(Varien_Event_Observer $observer)
    {
        Mage::app()->cleanCache('block_html');

        $session = Mage::getSingleton('adminhtml/session');
        $storeId = Mage::getModel('core/store')->load($observer->getEvent()->getStore())->getStoreId();
        $warnings = array();
        $errors = array();

        if (strpos(Mage::getStoreConfig('tax/avatax/url', $storeId), 'development.avalara.net') !== false) {
            $warnings[] = $this->_getDataHelper()->__('You are using the AvaTax development connection URL. If you are receiving errors about authentication, please ensure that you have a development account.');
        }
        if (Mage::getStoreConfig('tax/avatax/action', $storeId) == OnePica_AvaTax_Model_Config::ACTION_DISABLE) {
            $warnings[] = $this->_getDataHelper()->__('All AvaTax services are disabled');
        }
        if (Mage::getStoreConfig('tax/avatax/action', $storeId) == OnePica_AvaTax_Model_Config::ACTION_CALC) {
            $warnings[] = $this->_getDataHelper()->__('Orders will not be sent to the AvaTax system');
        }
        if (Mage::getStoreConfig('tax/avatax/action', $storeId) == OnePica_AvaTax_Model_Config::ACTION_CALC_SUBMIT) {
            $warnings[] = $this->_getDataHelper()->__('Orders will be sent but never committed to the AvaTax system');
        }

        $ping = Mage::getSingleton('avatax/avatax_ping')->ping($storeId);
        if ($ping !== true) {
            $errors[] = $ping;
        }
        if (!Mage::getStoreConfig('tax/avatax/url', $storeId)) {
            $errors[] = $this->_getDataHelper()->__('You must enter a connection URL');
        }
        if (!Mage::getStoreConfig('tax/avatax/account', $storeId)) {
            $errors[] = $this->_getDataHelper()->__('You must enter an account number');
        }
        if (!Mage::getStoreConfig('tax/avatax/license', $storeId)) {
            $errors[] = $this->_getDataHelper()->__('You must enter a license key');
        }
        if (!is_numeric(Mage::getStoreConfig('tax/avatax/log_lifetime'))) {
            $errors[] = $this->_getDataHelper()->__('You must enter the number of days to keep log entries');
        }
        if (!Mage::getStoreConfig('tax/avatax/company_code', $storeId)) {
            $errors[] = $this->_getDataHelper()->__('You must enter a company code');
        }
        if (!Mage::getStoreConfig('tax/avatax/shipping_sku', $storeId)) {
            $errors[] = $this->_getDataHelper()->__('You must enter a shipping sku');
        }
        if (!Mage::getStoreConfig('tax/avatax/adjustment_positive_sku', $storeId)) {
            $errors[] = $this->_getDataHelper()->__('You must enter an adjustment refund sku');
        }
        if (!Mage::getStoreConfig('tax/avatax/adjustment_negative_sku', $storeId)) {
            $errors[] = $this->_getDataHelper()->__('You must enter an adjustment fee sku');
        }

        if (!class_exists('SoapClient')) {
            $errors[] = $this->_getDataHelper()->__('The PHP class SoapClient is missing. It must be enabled to use this extension. See %s for details.', '<a href="http://www.php.net/manual/en/book.soap.php" target="_blank">http://www.php.net/manual/en/book.soap.php</a>');
        }

        if (!function_exists('openssl_sign') && count($errors)) {
            $key = array_search($this->_getDataHelper()->__('SSL support is not available in this build'), $errors);
            if (isset($errors[$key])) {
                unset($errors[$key]);
            }
            $errors[] = $this->_getDataHelper()->__('SSL must be enabled in PHP to use this extension. Typically, OpenSSL is used but it is not enabled on your server. This may not be a problem if you have some other form of SSL in place. For more information about OpenSSL, see %s.', '<a href="http://www.php.net/manual/en/book.openssl.php" target="_blank">http://www.php.net/manual/en/book.openssl.php</a>');
        }

        if (!Mage::getResourceModel('cron/schedule_collection')->count()) {
            $warnings[] = $this->_getDataHelper()->__('It appears that Magento\'s cron scheduler is not running. For more information, see %s.', '<a href="http://www.magentocommerce.com/wiki/how_to_setup_a_cron_job" target="_black">How to Set Up a Cron Job</a>');
        }

        if (count($errors) == 1) {
            $session->addError(implode('', $errors));
        } elseif (count($errors)) {
            $session->addError($this->_getDataHelper()->__('Please fix the following issues:') . '<br /> - '
                . implode('<br /> - ', $errors));
        }

        if (count($warnings) == 1) {
            $session->addWarning(implode('', $warnings));
        } elseif (count($warnings)) {
            $session->addWarning($this->_getDataHelper()->__('Please be aware of the following warnings:')
                . '<br /> - '
                . implode('<br /> - ', $warnings));
        }
    }

    /**
     * Observer to clean the log every so often so it does not get too big.
     *
     * @param Mage_Cron_Model_Schedule $schedule
     * @return $this
     */
    public function cleanLog($schedule)
    {
        $days = floatval(Mage::getStoreConfig('tax/avatax/log_lifetime'));
        Mage::getModel('avatax_records/log')->deleteLogsByInterval($days);
        return $this;
    }

    /**
     * This an observer function for the event 'controller_front_init_before'.
     * It prepends our autoloader, so we can load the extra libraries.
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function controllerFrontInitBefore(Varien_Event_Observer $observer)
    {
        spl_autoload_register(array($this, 'loadLib'), true, true);
        return $this;
    }

    /**
     * This function can autoloads classes to work with Avalara API
     *
     * @param string $class
     */
    public static function loadLib($class)
    {
        if (in_array($class, self::$_classes)) {
            /** @var OnePica_AvaTax_Helper_Data $helper */
            $helper = Mage::helper('avatax');
            $helper->loadFunctions();
            $helper->loadClass($class);
        }
        return;
    }

    /**
     * Set post type for checkout session when 'controller_action_predispatch_checkout_cart_estimatePost' event
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function controllerActionPredispatchCheckoutCartEstimatePost(Varien_Event_Observer $observer)
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setPostType('estimate');
        return $this;
    }

    /**
     * Set post type for checkout session when 'controller_action_predispatch_checkout_onepage_index' event
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function controllerActionPredispatchCheckoutOnepageIndex(Varien_Event_Observer $observer)
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setPostType('onepage');
        return $this;
    }

    /**
     * Add error message if tax estimation has problems when user estimates post
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function controllerActionPostdispatchCheckoutCartEstimatePost(Varien_Event_Observer $observer)
    {
        $this->_handleTaxEstimation();
        return $this;
    }

    /**
     * Add error message if tax estimation has problems when user updates estimate post
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function controllerActionPostdispatchCheckoutCartEstimateUpdatePost(Varien_Event_Observer $observer)
    {
        $this->_handleTaxEstimation();
        return $this;
    }

    /**
     * Add error message if tax estimation has problems when user located at checkout/cart/index
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function controllerActionPredispatchCheckoutCartIndex(Varien_Event_Observer $observer)
    {
        $this->_handleTaxEstimation();
        return $this;
    }

    /**
     * Add error message if tax estimation has problems
     *
     * @return $this
     */
    protected function _handleTaxEstimation()
    {
        $quote = $this->_getQuote();
        $quote->collectTotals();
        if ($quote->getData('estimate_tax_error')) {
            $this->_getDataHelper()->addErrorMessage($quote->getStoreId());
        }
        return $this;
    }

    /**
     * Stop order creation if tax estimation has problems
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     * @throws OnePica_AvaTax_Model_Exception
     */
    public function salesModelServiceQuoteSubmitBefore(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = $observer->getEvent()->getQuote();
        $this->_handleTaxEstimationOnOrderPlace($quote);
        return $this;
    }

    /**
     * Stop order creation if tax estimation has problems
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     * @throws OnePica_AvaTax_Model_Exception
     */
    public function controllerActionPostdispatchCheckoutOnepageSaveShippingMethod(Varien_Event_Observer $observer) {
        /** @var Mage_Sales_Model_Quote $quote */
        $quote = Mage::getSingleton('checkout/cart')->getQuote();
        /** @var OnePica_AvaTax_Helper_Data $helper */
        if ($this->_getDataHelper()->fullStopOnError($quote)) {
            Mage::app()
                ->getResponse()
                ->setBody($this->_getResponseErrorMessage());
        }
        return $this;
    }

    /**
     * Stop order creation if tax estimation has problems when multishipping
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     * @throws OnePica_AvaTax_Model_Exception
     */
    public function checkoutTypeMultishippingCreateOrdersSingle(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Quote_Address $address */
        $address = $observer->getEvent()->getAddress();
        $quote = $address->getQuote();
        $this->_handleTaxEstimationOnOrderPlace($quote);
        return $this;
    }

    /**
     * Stop order creation if tax estimation has problems
     *
     * @param Mage_Sales_Model_Quote $quote
     * @return $this
     * @throws OnePica_AvaTax_Model_Exception
     */
    protected function _handleTaxEstimationOnOrderPlace($quote)
    {
        /** @var OnePica_AvaTax_Helper_Data $helper */
        $helper = $this->_getDataHelper();
        if ($helper->fullStopOnError($quote)) {
            throw new OnePica_AvaTax_Model_Exception($helper->getErrorMessage());
        }
        return $this;
    }

    /**
     * Delete validation notices on successful order place on multiple checkout
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function checkoutSubmitAllAfter(Varien_Event_Observer $observer)
    {
        $this->_deleteValidateNotices();
        return $this;
    }

    /**
     * Delete validation notices on successful order place
     *
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function salesModelServiceQuoteSubmitAfter(Varien_Event_Observer $observer)
    {
        $this->_deleteValidateNotices();
        return $this;
    }

    /**
     * Delete validation notices
     *
     * @return $this
     */
    protected function _deleteValidateNotices()
    {
        /** @var Mage_Checkout_Model_Session $session */
        $session = Mage::getSingleton('core/session');
        $messages = $session->getMessages();
        $messages->deleteMessageByIdentifier(OnePica_AvaTax_Helper_Data::VALIDATION_NOTICE_IDENTIFIER);
        return $this;
    }

    /**
     * Get quote
     *
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote()
    {
        return Mage::getModel('checkout/cart')->getQuote();
    }

    /**
     * Get data helper
     *
     * @return OnePica_AvaTax_Helper_Data
     */
    protected function _getDataHelper()
    {
        return Mage::helper('avatax');
    }

    /**
     * Get response error message
     *
     * @return string
     */
    protected function _getResponseErrorMessage()
    {
        return Mage::helper('core')->jsonEncode(
            array(
                'error'   => -1,
                'message' => $this->_getDataHelper()->getErrorMessage()
            )
        );
    }
}

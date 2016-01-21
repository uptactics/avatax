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
 * @copyright  Copyright (c) 2015 One Pica, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

/**
 * @class OnePica_AvaTax_Model_Service_Avatax16
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 */
class OnePica_AvaTax_Model_Service_Avatax16
    extends OnePica_AvaTax_Model_Service_Abstract
{
    /**
     * Estimate Resource
     *
     * @var mixed
     */
    protected $_estimateResource;

    /**
     * Invoice Resource
     *
     * @var mixed
     */
    protected $_invoiceResource;

    /**
     * Ping Resource
     *
     * @var mixed
     */
    protected $_pingResource;

    /**
     * OnePica_AvaTax_Model_Service_Avatax16 constructor.
     *
     * @param mixed
     */
    public function __construct()
    {
        $storeId = Mage::app()->getStore()->getStoreId();
        $this->setStoreId($storeId);
    }

    /**
     * Set Store Id
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId($storeId)
    {
        $this->setCurrentStoreId($storeId);
        if (!$this->getServiceConfig()) {
            $this->setServiceConfig(Mage::getModel('avatax/service_avatax16_config')->init($this->getCurrentStoreId()));
        }

        // update service config for each resource
        if (null !== $this->_estimateResource) {
            $this->_estimateResource->setServiceConfig($this->getServiceConfig());
        }

        if (null !== $this->_invoiceResource) {
            $this->_invoiceResource->setServiceConfig($this->getServiceConfig());
        }

        if (null !== $this->_pingResource) {
            $this->_pingResource->setServiceConfig($this->getServiceConfig());
        }

        return $this;
    }

    /**
     * Get estimate resource
     *
     * return OnePica_AvaTax_Model_Service_Avatax16_Estimate
     */
    protected function _getEstimateResource()
    {
        if (null === $this->_estimateResource) {
            $this->_estimateResource = Mage::getModel('avatax/service_avatax16_estimate',
                array('service_config' => $this->getServiceConfig()));
        }

        return $this->_estimateResource;
    }

    /**
     * Get invoice resource
     *
     * return OnePica_AvaTax_Model_Service_Avatax16_Invoice
     */
    protected function _getInvoiceResource()
    {
        if (null === $this->_invoiceResource) {
            $this->_invoiceResource = Mage::getModel('avatax/service_avatax16_invoice',
                array('service_config' => $this->getServiceConfig()));
        }

        return $this->_invoiceResource;
    }

    /**
     * Get ping resource
     *
     * return OnePica_AvaTax_Model_Service_Avatax16_Ping
     */
    protected function _getPingResource()
    {
        if (null === $this->_pingResource) {
            $this->_pingResource = Mage::getModel('avatax/service_avatax16_ping',
                array('service_config' => $this->getServiceConfig()));
        }

        return $this->_pingResource;
    }

    /**
     * Get Address Validator resource
     *
     * @param OnePica_AvaTax_Model_Sales_Quote_Address $address
     * @return OnePica_AvaTax_Model_Service_Avatax16_Address
     */
    protected function _getAddressValidatorResource($address)
    {
        return Mage::getModel('avatax/service_avatax16_address',
            array('service_config' => $this->getServiceConfig(), 'address' => $address)
        );
    }

    /**
     * Get rates from Service
     *
     * @param Mage_Sales_Model_Quote_Address $address
     * @return array
     */
    public function getRates($address)
    {
        return $this->_getEstimateResource()->getRates($address);
    }

    /**
     * Get tax detail summary
     *
     * @param Mage_Sales_Model_Quote_Address|null $address
     *
     * @return array
     */
    public function getSummary($address = null)
    {
        return $this->_getEstimateResource()->getSummary($address);
    }

    /**
     * Test to see if the product carries its own numbers or is calculated based on parent or children
     *
     * @param Mage_Sales_Model_Quote_Item|Mage_Sales_Model_Order_Item|mixed $item
     * @return bool
     */
    public function isProductCalculated($item)
    {
        return $this->_getEstimateResource()->isProductCalculated($item);
    }

    /**
     * Save order in AvaTax system
     *
     * @see OnePica_AvaTax_Model_Observer::salesOrderPlaceAfter()
     * @param Mage_Sales_Model_Order_Invoice     $invoice
     * @param OnePica_AvaTax_Model_Records_Queue $queue
     * @return bool
     */
    public function invoice($invoice, $queue)
    {
        return $this->_getInvoiceResource()->invoice($invoice, $queue);
    }

    /**
     * Save order in AvaTax system
     *
     * @see OnePica_AvaTax_Model_Observer::salesOrderPlaceAfter()
     * @param Mage_Sales_Model_Order_Creditmemo  $creditmemo
     * @param OnePica_AvaTax_Model_Records_Queue $queue
     * @return bool
     */
    public function creditmemo($creditmemo, $queue)
    {
        return $this->_getInvoiceResource()->creditmemo($creditmemo, $queue);
    }

    /**
     * Tries to ping AvaTax service with provided credentials
     *
     * @param int $storeId
     * @return bool|array
     */
    public function ping($storeId)
    {
        return $this->_getPingResource()->ping($storeId);
    }

    /**
     * Get service address validator
     *
     * @param OnePica_AvaTax_Model_Sales_Quote_Address $address
     * @return \OnePica_AvaTax_Model_Service_Avatax16_Address
     */
    public function getAddressValidator($address)
    {
        return $this->_getAddressValidatorResource($address);
    }
}

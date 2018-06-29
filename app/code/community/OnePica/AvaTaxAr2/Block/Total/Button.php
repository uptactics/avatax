<?php
/**
 * OnePica_AvaTax
 * NOTICE OF LICENSE
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
 * Class OnePica_AvaTaxAr2_Block_ActionButton
 */
class OnePica_AvaTaxAr2_Block_Total_Button extends Mage_Checkout_Block_Total_Default
{
    protected $_template = 'onepica/avataxar2/total/button.phtml';

    protected function _construct()
    {
        parent::_construct();

        $totals = $this->getTotals();

        if (!isset($totals['tax'])) {
            $this->setTemplate('');
        }

        if (isset($totals['tax']) && $totals['tax']->getValue() == 0) {
            $this->setTemplate('');
        }
    }

    /**
     * @return string
     */
    public function getPopupUrl()
    {
        return $this->getUrl('avataxcert/popup/genCert');
    }
}

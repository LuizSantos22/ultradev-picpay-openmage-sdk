<?php
class Ultradev_Picpay_Block_Form extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/picpay/form.phtml');
    }

    /** @return Ultradev_Picpay_Helper_Data */
    public function getPicpayHelper()
    {
        return Mage::helper('ultradev_picpay');
    }

    public function getTypes()
    {
        return $this->getPicpayHelper()->getEnabledTypes();
    }

    /** @return Mage_Sales_Model_Quote */
    public function getQuote()
    {
        return $this->getMethod()->getInfoInstance()->getQuote();
    }

    public function getDefaultTaxvat()
    {
        $quote = $this->getQuote();
        $billing = $quote->getBillingAddress();
        $doc = $billing && $billing->getVatId() ? $billing->getVatId() : $quote->getCustomerTaxvat();
        return $this->getPicpayHelper()->digits($doc);
    }

    /**
     * Parcelas sem juros respeitando máximo e valor mínimo da parcela.
     * @return array [n => 'Nx de R$ x,xx sem juros']
     */
    public function getInstallments()
    {
        $total = (float) $this->getQuote()->getGrandTotal();
        $max = max(1, (int) $this->getPicpayHelper()->getConfig('max_installments'));
        $min = (float) $this->getPicpayHelper()->getConfig('minimum_installment_amount');
        $list = [];
        for ($n = 1; $n <= $max; $n++) {
            $value = round($total / $n, 2);
            if ($n > 1 && $value < $min) {
                break;
            }
            $list[$n] = sprintf(
                '%dx de R$ %s %s',
                $n,
                number_format($value, 2, ',', '.'),
                'sem juros'
            );
        }
        return $list;
    }
}

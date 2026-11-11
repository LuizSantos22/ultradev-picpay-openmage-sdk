<?php
class Ultradev_Picpay_Block_Info extends Mage_Payment_Block_Info
{
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info = $this->getInfo();
        $helper = Mage::helper('ultradev_picpay');

        $type = $info->getAdditionalInformation('picpay_type');
        $data = [];
        if ($type) {
            $data['Forma de pagamento'] = 'PicPay - ' . $helper->getTypeLabel($type);
        }
        if ($info->getAdditionalInformation('status')) {
            $data['Status PicPay'] = $info->getAdditionalInformation('status');
        }
        if ($type === Ultradev_Picpay_Helper_Data::TYPE_CARD) {
            if ($info->getCcLast4()) {
                $data['Cartão'] = '**** ' . $info->getCcLast4();
            }
            if ($info->getAdditionalInformation('cc_installments')) {
                $data['Parcelas'] = (int) $info->getAdditionalInformation('cc_installments') . 'x';
            }
        }
        if (Mage::app()->getStore()->isAdmin()) {
            if ($info->getAdditionalInformation('merchantChargeId')) {
                $data['ID da cobrança'] = $info->getAdditionalInformation('merchantChargeId');
            }
        }
        return $transport->addData($data);
    }
}

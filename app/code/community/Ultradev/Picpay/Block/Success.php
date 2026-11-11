<?php
class Ultradev_Picpay_Block_Success extends Mage_Core_Block_Template
{
    /** @var Mage_Sales_Model_Order|null|false */
    protected $_order = false;

    /** @return Mage_Sales_Model_Order|null pedido recém-criado, somente se for PicPay */
    public function getPicpayOrder()
    {
        if ($this->_order === false) {
            $this->_order = null;
            $id = Mage::getSingleton('checkout/session')->getLastRealOrderId();
            if ($id) {
                $order = Mage::getModel('sales/order')->loadByIncrementId($id);
                if ($order->getId() && $order->getPayment()
                    && $order->getPayment()->getMethod() === Ultradev_Picpay_Helper_Data::CODE) {
                    $this->_order = $order;
                }
            }
        }
        return $this->_order;
    }

    public function getPicpayInfo($key)
    {
        $order = $this->getPicpayOrder();
        return $order ? $order->getPayment()->getAdditionalInformation($key) : null;
    }

    public function isPaid()
    {
        return $this->getPicpayInfo('status') === Ultradev_Picpay_Model_Processor::PAID;
    }

    public function getQrImageSrc()
    {
        $img = (string) $this->getPicpayInfo('qr_code_base64');
        if ($img === '' || $img === 'NA') {
            return '';
        }
        return strpos($img, 'data:') === 0 ? $img : 'data:image/png;base64,' . $img;
    }

    public function getSecondsLeft()
    {
        $expires = (int) $this->getPicpayInfo('pix_expires_at');
        return $expires ? max(0, $expires - time()) : 0;
    }

    public function getStatusUrl()
    {
        return $this->getUrl('picpaycheckout/status/check', ['_secure' => Mage::app()->getStore()->isCurrentlySecure()]);
    }
}

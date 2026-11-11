<?php
/**
 * Monta os payloads enviados à API (mesmo formato do módulo oficial Magento 2).
 */
class Ultradev_Picpay_Model_Builder
{
    /** @return Ultradev_Picpay_Helper_Data */
    protected function _helper()
    {
        return Mage::helper('ultradev_picpay');
    }

    public function getDocument(Mage_Sales_Model_Order $order, Varien_Object $payment)
    {
        $doc = $payment->getAdditionalInformation('picpay_customer_taxvat');
        if (!$doc) {
            $billing = $order->getBillingAddress();
            $doc = $billing ? $billing->getVatId() : '';
        }
        if (!$doc) {
            $doc = $order->getCustomerTaxvat();
        }
        return $this->_helper()->digits($doc);
    }

    public function getCustomerName(Mage_Sales_Model_Order $order)
    {
        $name = trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());
        if ($name === '' && $order->getBillingAddress()) {
            $name = trim($order->getBillingAddress()->getFirstname() . ' ' . $order->getBillingAddress()->getLastname());
        }
        return $name;
    }

    public function card(Mage_Sales_Model_Order $order, Varien_Object $payment)
    {
        return [
            'cardNumber'         => (string) $payment->getCcNumber(),
            'cvv'                => (string) $payment->getCcCid(),
            'cardholderDocument' => $this->getDocument($order, $payment),
            'expirationMonth'    => (int) $payment->getCcExpMonth(),
            'expirationYear'     => (int) $payment->getCcExpYear(),
        ];
    }

    public function cardVerification(Mage_Sales_Model_Order $order, Varien_Object $payment)
    {
        $data = $this->card($order, $payment);
        $data['cardType'] = 'CREDIT';
        return $data;
    }

    protected function _address(Mage_Sales_Model_Order $order)
    {
        $helper = $this->_helper();
        $billing = $order->getBillingAddress();
        $line = function ($key) use ($helper, $billing) {
            $n = (int) $helper->getConfig('street_line_' . $key);
            return $n > 0 ? trim((string) $billing->getStreet($n)) : '';
        };
        $address = [
            'street'       => $line('street'),
            'number'       => $line('number') !== '' ? $line('number') : '0',
            'neighborhood' => $line('district'),
            'city'         => (string) $billing->getCity(),
            'state'        => (string) $billing->getRegionCode(),
            'country'      => (string) $billing->getCountryId(),
            'zipCode'      => $helper->digits($billing->getPostcode()),
        ];
        if ($line('complement') !== '') {
            $address['complement'] = $line('complement');
        }
        return $address;
    }

    /**
     * @param string $type card|pix|wallet
     */
    public function charge($type, Mage_Sales_Model_Order $order, Varien_Object $payment)
    {
        $helper = $this->_helper();
        $document = $this->getDocument($order, $payment);

        $tx = [
            'amount'         => $helper->toCents($order->getGrandTotal()),
            'softDescriptor' => $helper->getSoftDescriptor(),
            'transactionId'  => (string) $order->getIncrementId(),
        ];

        switch ($type) {
            case Ultradev_Picpay_Helper_Data::TYPE_PIX:
                $tx['paymentType'] = 'PIX';
                $minutes = (int) $helper->getConfig('pix_expiration');
                $tx['pix'] = ['expiration' => ($minutes > 0 ? $minutes : 30) * 60];
                break;
            case Ultradev_Picpay_Helper_Data::TYPE_WALLET:
                $tx['paymentType'] = 'WALLET';
                break;
            default:
                $installments = max(1, (int) $payment->getAdditionalInformation('cc_installments'));
                $tx['paymentType'] = 'CREDIT';
                $tx['credit'] = $this->card($order, $payment) + [
                    'cardholderName'    => $payment->getCcOwner() ?: $this->getCustomerName($order),
                    'installmentNumber' => $installments,
                    'installmentType'   => $installments > 1 ? 'MERCHANT' : 'NONE',
                    'billingAddress'    => $this->_address($order),
                ];
        }

        $request = [
            'paymentSource'     => 'GATEWAY',
            'customer'          => [
                'name'         => $this->getCustomerName($order),
                'email'        => (string) $order->getCustomerEmail(),
                'documentType' => $helper->getDocumentType($document),
                'document'     => $document,
                'phone'        => $helper->formatPhone($order->getBillingAddress()->getTelephone()),
            ],
            'transactions'      => [$tx],
            'deviceInformation' => [
                'ip'        => (string) $order->getRemoteIp(),
                'id'        => (string) Mage::helper('core/http')->getHttpUserAgent(),
                'sessionId' => (string) Mage::getSingleton('core/session')->getSessionId(),
            ],
            'sourceId'          => (string) Mage::getStoreConfig('general/store_information/name'),
        ];

        // A Carteira não envia lateCapture (igual ao módulo M2)
        if ($type !== Ultradev_Picpay_Helper_Data::TYPE_WALLET) {
            $request['lateCapture'] = $helper->isLateCapture();
        }

        return $request;
    }
}

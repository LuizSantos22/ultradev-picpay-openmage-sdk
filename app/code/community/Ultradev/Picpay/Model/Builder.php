<?php
/**
 * Monta os payloads enviados à API.
 * Cartão: somente o temporaryCardToken gerado pelo SDK no navegador (nenhum dado do cartão passa pelo servidor).
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
        // a API só aceita letras, números, espaço e &
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\d &]/u', '', $name)));
    }

    /**
     * Id único por tentativa (a API falha se um merchantChargeId for reutilizado).
     * Regex da API: ^[a-zA-Z0-9-]+$ , 6 a 36 caracteres.
     */
    public function newChargeId(Mage_Sales_Model_Order $order)
    {
        $base = substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string) $order->getIncrementId()), 0, 24);
        return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    protected function _clean($value, $pattern)
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace($pattern, '', (string) $value)));
    }

    protected function _address(Mage_Sales_Model_Order $order)
    {
        $helper = $this->_helper();
        $billing = $order->getBillingAddress();
        $line = function ($key) use ($helper, $billing) {
            $n = (int) $helper->getConfig('street_line_' . $key);
            return $n > 0 ? trim((string) $billing->getStreet($n)) : '';
        };
        $text = '/[^\p{L}\d .\'-]/u';
        $number = $this->_clean($line('number'), '/[^\p{L}\d .-]/u');

        $address = [
            'street'       => $this->_clean($line('street'), $text),
            'number'       => $number !== '' ? $number : 's/n',
            'neighborhood' => $this->_clean($line('district'), $text),
            'city'         => $this->_clean($billing->getCity(), $text),
            'state'        => strtoupper(substr($this->_clean($billing->getRegionCode(), '/[^\p{L}]/u'), 0, 2)),
            'country'      => $this->_clean($billing->getCountryId(), '/[^\p{L} ]/u'),
            'zipCode'      => $helper->digits($billing->getPostcode()),
        ];
        $complement = $this->_clean($line('complement'), $text);
        if ($complement !== '') {
            $address['complement'] = $complement;
        }
        return $address;
    }

    /** Somente esquema + host (+ porta não padrão), como exige a API para cartão */
    protected function _billingUrl()
    {
        $parts = parse_url(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB));
        $url = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $url .= ':' . $parts['port'];
        }
        return $url;
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
                $tx['credit'] = [
                    'temporaryCardToken' => (string) $payment->getPicpayCardToken(),
                    'installmentNumber'  => $installments,
                    'installmentType'    => $installments > 1 ? 'MERCHANT' : 'NONE',
                    'billingAddress'     => $this->_address($order),
                ];
        }

        $request = [
            'paymentSource'     => 'GATEWAY',
            'merchantChargeId'  => $this->newChargeId($order),
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

        if ($type === Ultradev_Picpay_Helper_Data::TYPE_CARD) {
            $request['billingUrlAddress'] = $this->_billingUrl();
        }
        // A Carteira não envia lateCapture (igual ao módulo M2)
        if ($type !== Ultradev_Picpay_Helper_Data::TYPE_WALLET) {
            $request['lateCapture'] = $helper->isLateCapture();
        }

        return $request;
    }
}

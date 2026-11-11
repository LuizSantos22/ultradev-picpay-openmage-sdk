<?php
class Ultradev_Picpay_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CODE = 'ultradev_picpay';
    const LOG_FILE = 'ultradev_picpay.log';

    const TYPE_CARD = 'card';
    const TYPE_PIX = 'pix';
    const TYPE_WALLET = 'wallet';

    public function getConfig($field, $storeId = null)
    {
        return Mage::getStoreConfig('payment/' . self::CODE . '/' . $field, $storeId);
    }

    public function getConfigFlag($field, $storeId = null)
    {
        return (bool) (int) $this->getConfig($field, $storeId);
    }

    /**
     * @return array type => label (somente as formas habilitadas)
     */
    public function getEnabledTypes($storeId = null)
    {
        $types = [];
        foreach ([self::TYPE_CARD, self::TYPE_PIX, self::TYPE_WALLET] as $type) {
            if ($type === self::TYPE_CARD && !$this->isTokenizationConfigured($storeId)) {
                continue; // cartão só com SDK de tokenização configurado
            }
            if ($this->getConfigFlag('enable_' . $type, $storeId)) {
                $types[$type] = (string) $this->getConfig('label_' . $type, $storeId);
            }
        }
        return $types;
    }

    public function getTypeLabel($type)
    {
        $labels = [
            self::TYPE_CARD   => 'Cartão de crédito',
            self::TYPE_PIX    => 'Pix',
            self::TYPE_WALLET => 'Carteira PicPay (QR Code)',
        ];
        return isset($labels[$type]) ? $labels[$type] : (string) $type;
    }

    public function getApiKey($storeId = null)
    {
        return trim((string) $this->getConfig('api_key', $storeId));
    }

    public function getApiSecret($storeId = null)
    {
        return trim((string) Mage::helper('core')->decrypt($this->getConfig('api_secret', $storeId)));
    }

    public function getWebhookToken($storeId = null)
    {
        return trim((string) Mage::helper('core')->decrypt($this->getConfig('webhook_token', $storeId)));
    }

    public function getMerchantCredential($storeId = null)
    {
        return $this->digits($this->getConfig('merchant_credential', $storeId));
    }

    public function getTransparentToken($storeId = null)
    {
        return trim((string) $this->getConfig('transparent_token', $storeId));
    }

    public function getSdkUrl($storeId = null)
    {
        $url = trim((string) $this->getConfig('sdk_url', $storeId));
        return $url !== '' ? $url : 'https://checkout.picpay.com/cdn/pp-transparent-v1.0.0.js';
    }

    public function isTokenizationConfigured($storeId = null)
    {
        return $this->getMerchantCredential($storeId) !== '' && $this->getTransparentToken($storeId) !== '';
    }

    public function isSandbox($storeId = null)
    {
        return $this->getConfigFlag('use_sandbox', $storeId);
    }

    public function isLateCapture($storeId = null)
    {
        return !$this->getConfigFlag('cc_auto_capture', $storeId);
    }

    public function getWebhookUrl($storeId = null)
    {
        return Mage::app()->getStore($storeId)->getUrl('picpaycheckout/callback/payments', ['_secure' => true]);
    }

    public function digits($string)
    {
        return preg_replace('/\D/', '', (string) $string);
    }

    public function toCents($amount)
    {
        return (int) round(((float) $amount) * 100);
    }

    public function getSoftDescriptor()
    {
        $text = trim((string) $this->getConfig('soft_descriptor'));
        if ($text === '') {
            $text = (string) Mage::getStoreConfig('general/store_information/name');
        }
        return substr($text, 0, 13);
    }

    /**
     * Divide o telefone no formato exigido pela API
     */
    public function formatPhone($phone)
    {
        $phone = $this->digits($phone);
        if (strlen($phone) > 11 && substr($phone, 0, 2) === '55') {
            $phone = substr($phone, 2);
        }
        $phone = ltrim($phone, '0');
        return [
            'type'        => 'MOBILE',
            'countryCode' => '55',
            'areaCode'    => substr($phone, 0, 2),
            'number'      => substr($phone, 2),
        ];
    }

    public function getDocumentType($document)
    {
        $len = strlen($this->digits($document));
        if ($len === 14) {
            return 'CNPJ';
        }
        return $len === 11 ? 'CPF' : 'PASSPORT';
    }

    /**
     * Estado do pedido associado a um status (ou o padrão informado)
     */
    public function getStateForStatus(Mage_Sales_Model_Order $order, $status, $default)
    {
        if ($status) {
            $states = $order->getConfig()->getStatusStates($status);
            if (count($states)) {
                return $states[0]->getState();
            }
        }
        return $default;
    }

    public function findOrderByChargeId($chargeId)
    {
        $order = Mage::getModel('sales/order');
        if ($chargeId) {
            $collection = $order->getCollection()
                ->addFieldToFilter('picpay_charge_id', (string) $chargeId)
                ->setPageSize(1);
            $order = $collection->getFirstItem();
        }
        return $order;
    }

    public function mask($message)
    {
        $message = preg_replace('/"cardNumber"\s*:\s*"[^"]*"/', '"cardNumber":"*********"', $message);
        $message = preg_replace('/"cvv"\s*:\s*"?[^",}]*"?/', '"cvv":"***"', $message);
        $message = preg_replace('/"client_secret"\s*:\s*"[^"]*"/', '"client_secret":"*********"', $message);
        $message = preg_replace('/"access_token"\s*:\s*"[^"]*"/', '"access_token":"*********"', $message);
        $message = preg_replace('/"qrCodeBase64"\s*:\s*"[^"]{40,}"/', '"qrCodeBase64":"(omitido)"', $message);
        return $message;
    }

    public function log($message, $force = false)
    {
        if (!$force && !$this->getConfigFlag('debug')) {
            return;
        }
        if (!is_string($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        Mage::log($this->mask($message), Zend_Log::DEBUG, self::LOG_FILE, true);
    }
}

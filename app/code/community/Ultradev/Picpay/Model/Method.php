<?php
/**
 * Método de pagamento único "PicPay" com três ramificações:
 * card (cartão de crédito), pix e wallet (Carteira / QR Code).
 */
class Ultradev_Picpay_Model_Method extends Mage_Payment_Model_Method_Abstract
{
    const CODE = 'ultradev_picpay';

    const STATUS_PAID           = 'PAID';
    const STATUS_PRE_AUTHORIZED = 'PRE_AUTHORIZED';
    const STATUS_DENIED         = 'DENIED';
    const STATUS_ERROR          = 'ERROR';
    const STATUS_CANCELED       = 'CANCELED';

    protected $_code = self::CODE;
    protected $_formBlockType = 'ultradev_picpay/form';
    protected $_infoBlockType = 'ultradev_picpay/info';

    protected $_isGateway = true;
    protected $_isInitializeNeeded = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;

    /** @return Ultradev_Picpay_Helper_Data */
    protected function _helper()
    {
        return Mage::helper('ultradev_picpay');
    }

    /** @return Ultradev_Picpay_Model_Api */
    protected function _api()
    {
        return Mage::getModel('ultradev_picpay/api');
    }

    /**
     * Precisa ser "truthy" para o core chamar initialize() em Order_Payment::place().
     */
    public function getConfigPaymentAction()
    {
        return self::ACTION_AUTHORIZE;
    }

    public function getTitle()
    {
        return $this->getConfigData('title') ?: 'PicPay';
    }

    public function canUseForCurrency($currencyCode)
    {
        return $currencyCode === 'BRL';
    }

    public function canRefundPartialPerInvoice()
    {
        $type = $this->getInfoInstance() ? $this->getInfoInstance()->getAdditionalInformation('picpay_type') : null;
        return $type !== Ultradev_Picpay_Helper_Data::TYPE_CARD;
    }

    public function isAvailable($quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }
        $helper = $this->_helper();
        $storeId = $quote ? $quote->getStoreId() : null;
        if (!$helper->getEnabledTypes($storeId) || !$helper->getApiKey($storeId) || !$helper->getApiSecret($storeId)) {
            return false;
        }
        return !$quote || $quote->getGrandTotal() > 0;
    }

    public function assignData($data)
    {
        if (is_array($data)) {
            $data = new Varien_Object($data);
        }
        $helper = $this->_helper();
        $info = $this->getInfoInstance();

        $type = strtolower(trim((string) $data->getData('picpay_type')));
        $info->setAdditionalInformation('picpay_type', $type);
        $info->setAdditionalInformation('picpay_customer_taxvat', $helper->digits($data->getData('picpay_taxvat')));

        if ($type === Ultradev_Picpay_Helper_Data::TYPE_CARD) {
            $number = $helper->digits($data->getData('cc_number'));
            $exp = $helper->digits($data->getData('cc_exp'));
            $month = (int) substr($exp, 0, 2);
            $year = (int) substr($exp, 2);
            if ($year > 0 && $year < 100) {
                $year += 2000;
            }
            // cc_number e cc_cid NÃO são colunas: ficam só em memória (nunca gravados)
            $info->setCcNumber($number)
                ->setCcCid($helper->digits($data->getData('cc_cid')))
                ->setCcOwner(trim((string) $data->getData('cc_owner')))
                ->setCcExpMonth($month)
                ->setCcExpYear($year)
                ->setCcLast4(substr($number, -4));
            $info->setAdditionalInformation('cc_installments', max(1, (int) $data->getData('cc_installments')));
        }
        return $this;
    }

    public function validate()
    {
        parent::validate();
        $helper = $this->_helper();
        $info = $this->getInfoInstance();
        $order = $info instanceof Mage_Sales_Model_Order_Payment ? $info->getOrder() : null;
        $type = (string) $info->getAdditionalInformation('picpay_type');

        if (!array_key_exists($type, $helper->getEnabledTypes())) {
            Mage::throwException('Escolha uma forma de pagamento PicPay.');
        }

        $doc = $helper->digits($info->getAdditionalInformation('picpay_customer_taxvat'));
        if (!$doc) {
            $address = $order ? $order->getBillingAddress() : $info->getQuote()->getBillingAddress();
            $doc = $helper->digits($address->getVatId() ?: ($order ? $order->getCustomerTaxvat() : $info->getQuote()->getCustomerTaxvat()));
        }
        if (!in_array(strlen($doc), [11, 14], true)) {
            Mage::throwException('Informe um CPF ou CNPJ válido.');
        }

        if ($type === Ultradev_Picpay_Helper_Data::TYPE_CARD) {
            $this->_validateCard($info);
        }
        return $this;
    }

    protected function _validateCard($info)
    {
        $number = (string) $info->getCcNumber();
        if (!$this->_luhn($number) || strlen($number) < 13) {
            Mage::throwException('Número do cartão inválido.');
        }
        $cvv = (string) $info->getCcCid();
        if (!preg_match('/^\d{3,4}$/', $cvv)) {
            Mage::throwException('Código de segurança (CVV) inválido.');
        }
        $month = (int) $info->getCcExpMonth();
        $year = (int) $info->getCcExpYear();
        if ($month < 1 || $month > 12 || $year < (int) date('Y') || ($year === (int) date('Y') && $month < (int) date('n'))) {
            Mage::throwException('Validade do cartão inválida.');
        }
        if (trim((string) $info->getCcOwner()) === '') {
            Mage::throwException('Informe o nome impresso no cartão.');
        }
        $max = max(1, (int) $this->_helper()->getConfig('max_installments'));
        $installments = (int) $info->getAdditionalInformation('cc_installments');
        if ($installments < 1 || $installments > $max) {
            Mage::throwException('Número de parcelas inválido.');
        }
    }

    protected function _luhn($number)
    {
        if ($number === '' || !ctype_digit($number)) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $n = (int) $number[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    /**
     * Chamado pelo core em Order_Payment::place() (isInitializeNeeded = true).
     * Cria a cobrança na PicPay e define o estado inicial do pedido.
     */
    public function initialize($paymentAction, $stateObject)
    {
        $helper = $this->_helper();
        $api = $this->_api();
        $builder = Mage::getModel('ultradev_picpay/builder');
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $type = (string) $payment->getAdditionalInformation('picpay_type');

        if ($type === Ultradev_Picpay_Helper_Data::TYPE_CARD) {
            $check = $api->verifyCard($builder->cardVerification($order, $payment));
            if ($check['status'] >= 300) {
                Mage::throwException('Não foi possível validar os dados do cartão. Confira e tente novamente.');
            }
        }

        $result = $api->createCharge($type, $builder->charge($type, $order, $payment));
        $body = $result['body'];
        $chargeId = isset($body['merchantChargeId']) ? (string) $body['merchantChargeId'] : '';
        $status = isset($body['chargeStatus']) ? strtoupper((string) $body['chargeStatus']) : '';

        if ($result['status'] >= 300 || $chargeId === '' || $status === '') {
            $helper->log('Falha ao criar cobrança: HTTP ' . $result['status'], true);
            Mage::throwException('Não foi possível processar o pagamento no PicPay. Tente novamente ou escolha outra forma de pagamento.');
        }
        if (in_array($status, [self::STATUS_DENIED, self::STATUS_ERROR, self::STATUS_CANCELED], true)) {
            Mage::throwException('Pagamento não autorizado. Verifique os dados ou escolha outra forma de pagamento.');
        }

        $payment->setAdditionalInformation('merchantChargeId', $chargeId);
        $payment->setAdditionalInformation('status', $status);
        $payment->setAdditionalInformation('tid', isset($body['id']) ? (string) $body['id'] : $chargeId);

        $tx = isset($body['transactions'][0]) && is_array($body['transactions'][0]) ? $body['transactions'][0] : [];
        if (isset($tx['transactionStatus'])) {
            $payment->setAdditionalInformation('transaction_status', (string) $tx['transactionStatus']);
        }
        $section = $type === Ultradev_Picpay_Helper_Data::TYPE_PIX ? 'pix' : ($type === Ultradev_Picpay_Helper_Data::TYPE_WALLET ? 'wallet' : null);
        if ($section && isset($tx[$section]) && is_array($tx[$section])) {
            $payment->setAdditionalInformation('qr_code', isset($tx[$section]['qrCode']) ? (string) $tx[$section]['qrCode'] : '');
            $payment->setAdditionalInformation('qr_code_base64', isset($tx[$section]['qrCodeBase64']) ? (string) $tx[$section]['qrCodeBase64'] : '');
        }
        if ($type === Ultradev_Picpay_Helper_Data::TYPE_PIX) {
            $minutes = (int) $helper->getConfig('pix_expiration');
            $payment->setAdditionalInformation('pix_expires_at', time() + ($minutes > 0 ? $minutes : 30) * 60);
        }
        if ($type === Ultradev_Picpay_Helper_Data::TYPE_CARD) {
            // nunca persistir PAN/CVV
            $payment->setCcNumber(null)->setCcCid(null);
        }

        $order->setData('picpay_charge_id', $chargeId);

        $newStatus = $this->getConfigData('order_status');
        $stateObject->setState(Mage_Sales_Model_Order::STATE_NEW);
        $stateObject->setStatus($newStatus ?: $order->getConfig()->getStateDefaultStatus(Mage_Sales_Model_Order::STATE_NEW));
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * Captura online (fatura criada no admin) de cobrança só autorizada.
     */
    public function capture(Varien_Object $payment, $amount)
    {
        parent::capture($payment, $amount);

        $status = (string) $payment->getAdditionalInformation('status');
        $chargeId = (string) $payment->getAdditionalInformation('merchantChargeId');
        if ($status === self::STATUS_PAID) {
            return $this;
        }
        if ($chargeId === '') {
            Mage::throwException('Cobrança PicPay não encontrada para este pedido.');
        }

        $result = $this->_api()->capture($chargeId, $this->_helper()->toCents($amount));
        if ($result['status'] >= 300) {
            Mage::throwException('O PicPay recusou a captura do pagamento.');
        }
        $payment->setAdditionalInformation('status', self::STATUS_PAID);
        return $this;
    }

    public function refund(Varien_Object $payment, $amount)
    {
        parent::refund($payment, $amount);

        if ($payment->getData('ultradev_picpay_skip_api')) {
            return $this;
        }
        $chargeId = (string) $payment->getAdditionalInformation('merchantChargeId');
        if ($chargeId === '') {
            Mage::throwException('Cobrança PicPay não encontrada para este pedido.');
        }
        $result = $this->_api()->refund($chargeId, $this->_helper()->toCents($amount));
        if ($result['status'] >= 300) {
            Mage::throwException('O PicPay recusou o estorno. Verifique o painel PicPay.');
        }
        return $this;
    }
}

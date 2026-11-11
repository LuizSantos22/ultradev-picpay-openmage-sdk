<?php
class Ultradev_Picpay_Model_Observer
{
    /**
     * sales_convert_quote_payment_to_order_payment
     * O core só copia campos conhecidos; o token temporário (em memória) precisa ser repassado.
     */
    public function copyCardToken(Varien_Event_Observer $observer)
    {
        $quotePayment = $observer->getEvent()->getQuotePayment();
        $orderPayment = $observer->getEvent()->getOrderPayment();
        if ($quotePayment && $orderPayment
            && $quotePayment->getMethod() === Ultradev_Picpay_Helper_Data::CODE
            && $quotePayment->getPicpayCardToken()) {
            $orderPayment->setPicpayCardToken($quotePayment->getPicpayCardToken());
        }
        return $this;
    }

    /**
     * sales_model_service_quote_submit_success
     * Cartão aprovado na hora (PAID): gera a fatura logo após o pedido.
     */
    public function invoiceIfPaid(Varien_Event_Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getId()) {
            return $this;
        }
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== Ultradev_Picpay_Helper_Data::CODE) {
            return $this;
        }
        if ($payment->getAdditionalInformation('status') === Ultradev_Picpay_Model_Processor::PAID) {
            try {
                $processor = Mage::getModel('ultradev_picpay/processor');
                if ($processor->invoicePaid($order)) {
                    $order->save();
                }
            } catch (Exception $e) {
                // o pedido já existe; a fatura pode ser criada depois via webhook
                Mage::logException($e);
            }
        }
        return $this;
    }

    /**
     * sales_order_payment_cancel
     * Pedido cancelado no Magento: libera/estorna a cobrança de cartão no PicPay.
     * Pix/Carteira não pagos expiram sozinhos.
     */
    public function cancelCharge(Varien_Event_Observer $observer)
    {
        $payment = $observer->getEvent()->getPayment();
        if (!$payment || $payment->getMethod() !== Ultradev_Picpay_Helper_Data::CODE) {
            return $this;
        }
        if ($payment->getData('ultradev_picpay_skip_api')) {
            return $this;
        }
        $type = $payment->getAdditionalInformation('picpay_type');
        $status = $payment->getAdditionalInformation('status');
        $chargeId = $payment->getAdditionalInformation('merchantChargeId');
        $isCard = $type === Ultradev_Picpay_Helper_Data::TYPE_CARD;
        $isActive = in_array($status, [Ultradev_Picpay_Model_Method::STATUS_PRE_AUTHORIZED, Ultradev_Picpay_Model_Method::STATUS_PAID], true);

        if ($isCard && $isActive && $chargeId) {
            $helper = Mage::helper('ultradev_picpay');
            $order = $payment->getOrder();
            $result = Mage::getModel('ultradev_picpay/api')->refund($chargeId, $helper->toCents($order->getGrandTotal()));
            if ($result['status'] >= 300) {
                Mage::throwException('O PicPay recusou o cancelamento da cobrança. Verifique o painel PicPay antes de cancelar o pedido.');
            }
        }
        return $this;
    }
}

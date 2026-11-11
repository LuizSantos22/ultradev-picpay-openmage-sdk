<?php
/**
 * Aplica no pedido o status de uma cobrança PicPay (webhook, polling ou retorno síncrono).
 */
class Ultradev_Picpay_Model_Processor
{
    const PAID           = 'PAID';
    const CANCELED       = 'CANCELED';
    const DENIED         = 'DENIED';
    const EXPIRED        = 'EXPIRED';
    const REFUNDED       = 'REFUNDED';
    const CHARGEBACK     = 'CHARGEBACK';

    /** @return Ultradev_Picpay_Helper_Data */
    protected function _helper()
    {
        return Mage::helper('ultradev_picpay');
    }

    /**
     * @param array $data payload (amount e refundedAmount em centavos, opcionais)
     * @return bool true se algo foi alterado
     */
    public function apply(Mage_Sales_Model_Order $order, $status, array $data = [])
    {
        $status = strtoupper(trim((string) $status));
        $payment = $order->getPayment();
        if ($status === '' || !$payment || $payment->getMethod() !== Ultradev_Picpay_Helper_Data::CODE) {
            return false;
        }

        $old = (string) $payment->getAdditionalInformation('status');
        if ($status === $old && $status !== self::PAID) {
            return false;
        }

        switch ($status) {
            case self::PAID:
                $changed = $this->invoicePaid($order);
                break;
            case self::CANCELED:
            case self::DENIED:
            case self::EXPIRED:
                $changed = $this->cancel($order, $status);
                break;
            case self::REFUNDED:
            case self::CHARGEBACK:
                $changed = $this->refund($order, $status, $data);
                break;
            default:
                $order->addStatusHistoryComment('PicPay: status da cobrança alterado para ' . $status . '.');
                $changed = true;
        }

        if ($status !== $old) {
            $payment->setAdditionalInformation('status', $status);
            $changed = true;
        }
        if ($changed) {
            $order->save();
            $payment->save();
        }
        return $changed;
    }

    /**
     * Gera a fatura offline (pagamento já confirmado pelo PicPay).
     */
    public function invoicePaid(Mage_Sales_Model_Order $order)
    {
        $helper = $this->_helper();
        if (!$order->canInvoice()) {
            if ($order->isCanceled()) {
                $order->addStatusHistoryComment('PicPay: pagamento recebido, mas o pedido já estava cancelado. Verifique manualmente.');
                return true;
            }
            return false;
        }

        $payment = $order->getPayment();
        $invoice = $order->prepareInvoice();
        if (!$invoice->getTotalQty()) {
            return false;
        }
        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
        $invoice->setTransactionId((string) $payment->getAdditionalInformation('tid'));
        $invoice->register();
        $order->setIsInProcess(true);

        $field = $order->getIsVirtual() ? 'paid_virtual_order_status' : 'paid_order_status';
        $status = $helper->getConfig($field, $order->getStoreId());
        $state = $helper->getStateForStatus(
            $order,
            $status,
            $order->getIsVirtual() ? Mage_Sales_Model_Order::STATE_COMPLETE : Mage_Sales_Model_Order::STATE_PROCESSING
        );
        $order->setState(
            $state,
            $status ?: true,
            'PicPay: pagamento confirmado (cobrança ' . $payment->getAdditionalInformation('merchantChargeId') . ').',
            true
        );

        Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($order)
            ->save();

        try {
            $invoice->sendEmail(true);
        } catch (Exception $e) {
            Mage::logException($e);
        }
        return true;
    }

    public function cancel(Mage_Sales_Model_Order $order, $status)
    {
        if ($order->canCancel()) {
            // já cancelado no PicPay: evita nova chamada à API no observer
            $order->getPayment()->setData('ultradev_picpay_skip_api', true);
            $order->cancel();
            $order->addStatusHistoryComment('PicPay: cobrança ' . $status . '. Pedido cancelado.');
            return true;
        }
        $order->addStatusHistoryComment('PicPay: cobrança ' . $status . ', mas o pedido não pôde ser cancelado automaticamente. Verifique manualmente.');
        return true;
    }

    public function refund(Mage_Sales_Model_Order $order, $status, array $data)
    {
        if (!$order->canCreditmemo()) {
            return false; // normalmente: estorno originado no próprio Magento
        }
        $refunded = !empty($data['refundedAmount']) ? ((float) $data['refundedAmount']) / 100 : 0;
        if ($refunded > 0 && $refunded < (float) $order->getGrandTotal() - 0.01) {
            $order->addStatusHistoryComment(sprintf(
                'PicPay: estorno parcial de R$ %s recebido. Gere o credit memo manualmente.',
                number_format($refunded, 2, ',', '.')
            ));
            return true;
        }

        $order->getPayment()->setData('ultradev_picpay_skip_api', true);
        $creditmemo = Mage::getModel('sales/service_order', $order)->prepareCreditmemo();
        $creditmemo->setOfflineRequested(true);
        $creditmemo->register();
        Mage::getModel('core/resource_transaction')
            ->addObject($creditmemo)
            ->addObject($creditmemo->getOrder())
            ->save();
        $order->addStatusHistoryComment('PicPay: cobrança ' . $status . '. Credit memo gerado offline.');
        return true;
    }
}

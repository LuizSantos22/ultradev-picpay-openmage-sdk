<?php
/**
 * Polling da página de sucesso (Pix / Carteira).
 */
class Ultradev_Picpay_StatusController extends Mage_Core_Controller_Front_Action
{
    protected function _json(array $data, $code = 200)
    {
        $this->getResponse()
            ->setHttpResponseCode($code)
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setBody(json_encode($data));
        return $this;
    }

    public function checkAction()
    {
        $helper = Mage::helper('ultradev_picpay');
        $incrementId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        if (!$incrementId) {
            return $this->_json(['message' => 'Sem pedido na sessão'], 404);
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        if (!$order->getId() || $order->getPayment()->getMethod() !== Ultradev_Picpay_Helper_Data::CODE) {
            return $this->_json(['message' => 'Pedido inválido'], 404);
        }

        try {
            $payment = $order->getPayment();
            $status = (string) $payment->getAdditionalInformation('status');

            // Plano B: consulta a API no máx. a cada 15s caso o webhook não tenha chegado
            if ($status !== Ultradev_Picpay_Model_Processor::PAID && $helper->getConfigFlag('poll_sync')) {
                $session = Mage::getSingleton('core/session');
                if (time() - (int) $session->getUltradevPicpayLastSync() >= 15) {
                    $session->setUltradevPicpayLastSync(time());
                    $result = Mage::getModel('ultradev_picpay/api')->getCharge($payment->getAdditionalInformation('merchantChargeId'));
                    if ($result['status'] === 200) {
                        $real = isset($result['body']['chargeStatus']) ? $result['body']['chargeStatus'] : '';
                        if ($real !== '') {
                            Mage::getModel('ultradev_picpay/processor')->apply($order, $real, $result['body']);
                            $order = Mage::getModel('sales/order')->load($order->getId());
                            $status = (string) $order->getPayment()->getAdditionalInformation('status');
                        }
                    }
                }
            }

            $paid = $status === Ultradev_Picpay_Model_Processor::PAID;
            $redirect = '';
            if ($paid && Mage::getSingleton('customer/session')->isLoggedIn()) {
                $redirect = Mage::getUrl('sales/order/view', ['order_id' => $order->getId()]);
            }
            return $this->_json([
                'paid'     => $paid,
                'status'   => $status,
                'canceled' => $order->isCanceled(),
                'redirect' => $redirect,
            ]);
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_json(['message' => 'Erro'], 500);
        }
    }
}

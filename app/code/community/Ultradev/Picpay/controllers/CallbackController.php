<?php
class Ultradev_Picpay_CallbackController extends Mage_Core_Controller_Front_Action
{
    protected function _json(array $data, $code = 200)
    {
        $this->getResponse()
            ->setHttpResponseCode($code)
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(json_encode($data));
        return $this;
    }

    /**
     * POST /picpaycheckout/callback/payments
     * Authorization: Bearer <token do webhook>
     */
    public function paymentsAction()
    {
        /** @var Ultradev_Picpay_Helper_Data $helper */
        $helper = Mage::helper('ultradev_picpay');

        if (!$this->getRequest()->isPost()) {
            return $this->_json(['message' => 'Método inválido'], 405);
        }
        if (!$helper->getConfigFlag('active')) {
            return $this->_json(['message' => 'Módulo desativado'], 400);
        }

        $expected = $helper->getWebhookToken();
        $given = trim(preg_replace('/^Bearer\s+/i', '', (string) $this->getRequest()->getHeader('Authorization')));
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            $helper->log('Webhook recusado: token inválido', true);
            return $this->_json(['message' => 'Não autorizado'], 401);
        }

        try {
            $content = json_decode((string) $this->getRequest()->getRawBody(), true);
            if (!is_array($content)) {
                return $this->_json(['message' => 'JSON inválido'], 400);
            }
            $helper->log('WEBHOOK');
            $helper->log($content);
            if (isset($content['type']) && isset($content['data']) && is_array($content['data'])) {
                $content = $content['data'];
            }

            $chargeId = isset($content['merchantChargeId']) ? (string) $content['merchantChargeId'] : '';
            $status = isset($content['status']) ? (string) $content['status'] : '';
            if ($chargeId === '' || $status === '') {
                return $this->_json(['message' => 'Payload incompleto'], 422);
            }

            $order = $helper->findOrderByChargeId($chargeId);
            if (!$order->getId()) {
                return $this->_json(['message' => 'Pedido não encontrado'], 404);
            }

            // Confirma o status direto na API (não confia só no payload)
            if ($helper->getConfigFlag('verify_webhook')) {
                $result = Mage::getModel('ultradev_picpay/api')->getCharge($chargeId);
                $real = '';
                if ($result['status'] === 200) {
                    $real = isset($result['body']['chargeStatus'])
                        ? $result['body']['chargeStatus']
                        : (isset($result['body']['status']) ? $result['body']['status'] : '');
                }
                if ($real === '') {
                    $helper->log('Não foi possível confirmar o status na API (HTTP ' . $result['status'] . ')', true);
                    return $this->_json(['message' => 'Falha ao confirmar status'], 502);
                }
                $status = (string) $real;
            }

            Mage::getModel('ultradev_picpay/processor')->apply($order, $status, $content);
            return $this->_json(['message' => 'OK']);
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_json(['message' => 'Erro interno'], 500);
        }
    }
}

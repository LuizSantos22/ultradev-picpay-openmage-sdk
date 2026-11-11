<?php
/**
 * Cliente da API PicPay Checkout (OAuth2 client_credentials + JSON).
 * Endpoints espelham o módulo oficial Magento 2 (PicPay_Checkout).
 */
class Ultradev_Picpay_Model_Api
{
    const URL_PRODUCTION = 'https://checkout-api.picpay.com/';
    const URL_SANDBOX    = 'https://checkout-api-sandbox.picpay.com/';

    const PATH_AUTH           = 'oauth2/token';
    const PATH_CREATE_CARD    = 'api/v1/charge/authorization';
    const PATH_CREATE_PIX     = 'api/v1/charge/pix';
    const PATH_CREATE_WALLET  = 'api/v1/charge/wallet';
    const PATH_CARD_VERIFY    = 'api/v1/cards/verification';
    const PATH_CHARGE         = 'api/v1/charge/%s';
    const PATH_CAPTURE        = 'api/v1/charge/%s/capture';
    const PATH_REFUND         = 'api/v1/charge/%s/refund';

    /** @var string|null token em memória durante a requisição */
    protected static $_token = null;

    /** @return Ultradev_Picpay_Helper_Data */
    protected function _helper()
    {
        return Mage::helper('ultradev_picpay');
    }

    public function getBaseUrl()
    {
        return $this->_helper()->isSandbox() ? self::URL_SANDBOX : self::URL_PRODUCTION;
    }

    /**
     * @return array ['status' => int, 'body' => array|string]
     */
    public function request($method, $path, array $data = [], $useAuth = true)
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'caller-origin: OpenMage-v1.0.0',
        ];
        if ($useAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->getToken();
        }

        $url = $this->getBaseUrl() . $path;
        $payload = $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $this->_helper()->log("REQUEST {$method} {$url}");
        if ($payload !== null) {
            $this->_helper()->log($payload);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->_helper()->log('CURL ERROR: ' . $error, true);
            Mage::throwException('Não foi possível comunicar com o PicPay. Tente novamente em instantes.');
        }

        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            $body = ['raw' => $raw];
        }

        $this->_helper()->log("RESPONSE {$status}");
        $this->_helper()->log($raw);

        return ['status' => $status, 'body' => $body];
    }

    public function getToken()
    {
        if (self::$_token !== null) {
            return self::$_token;
        }
        $helper = $this->_helper();
        $result = $this->request('POST', self::PATH_AUTH, [
            'grant_type'    => 'client_credentials',
            'client_id'     => $helper->getApiKey(),
            'client_secret' => $helper->getApiSecret(),
        ], false);

        if ($result['status'] !== 200 || empty($result['body']['access_token'])) {
            $helper->log('Falha ao obter token OAuth (HTTP ' . $result['status'] . ')', true);
            Mage::throwException('Não foi possível autenticar no PicPay. Verifique as credenciais.');
        }
        self::$_token = (string) $result['body']['access_token'];
        return self::$_token;
    }

    public function verifyCard(array $card)
    {
        return $this->request('POST', self::PATH_CARD_VERIFY, $card);
    }

    public function createCharge($type, array $payload)
    {
        switch ($type) {
            case Ultradev_Picpay_Helper_Data::TYPE_PIX:
                $path = self::PATH_CREATE_PIX;
                break;
            case Ultradev_Picpay_Helper_Data::TYPE_WALLET:
                $path = self::PATH_CREATE_WALLET;
                break;
            default:
                $path = self::PATH_CREATE_CARD;
        }
        return $this->request('POST', $path, $payload);
    }

    public function getCharge($chargeId)
    {
        return $this->request('GET', sprintf(self::PATH_CHARGE, rawurlencode($chargeId)));
    }

    public function capture($chargeId, $cents)
    {
        return $this->request('POST', sprintf(self::PATH_CAPTURE, rawurlencode($chargeId)), ['amount' => (int) $cents]);
    }

    public function refund($chargeId, $cents)
    {
        return $this->request('POST', sprintf(self::PATH_REFUND, rawurlencode($chargeId)), ['amount' => (int) $cents]);
    }
}

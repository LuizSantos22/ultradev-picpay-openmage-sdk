<?php
/**
 * Cliente da API PicPay Checkout (OAuth2 client_credentials + JSON).
 * Os hosts e o prefixo de path são configuráveis no admin.
 */
class Ultradev_Picpay_Model_Api
{
    const DEFAULT_URL_PRODUCTION = 'https://checkout-api.picpay.com/';
    const DEFAULT_URL_SANDBOX    = 'https://checkout-api-sandbox.picpay.com/';
    const DEFAULT_PATH_PREFIX    = 'api/v1/';

    // OAuth fica na raiz; os demais paths recebem o prefixo configurado
    const PATH_AUTH          = 'oauth2/token';
    const PATH_CREATE_CARD   = 'charge/authorization';
    const PATH_CREATE_PIX    = 'charge/pix';
    const PATH_CREATE_WALLET = 'charge/wallet';
    const PATH_CHARGE        = 'charge/%s';
    const PATH_CAPTURE       = 'charge/%s/capture';
    const PATH_REFUND        = 'charge/%s/refund';

    /** @var string|null token em memória durante a requisição (válido 5 min na PicPay) */
    protected static $_token = null;

    /** @return Ultradev_Picpay_Helper_Data */
    protected function _helper()
    {
        return Mage::helper('ultradev_picpay');
    }

    public function getBaseUrl()
    {
        $helper = $this->_helper();
        $url = trim((string) $helper->getConfig($helper->isSandbox() ? 'api_url_sandbox' : 'api_url_production'));
        if ($url === '') {
            $url = $helper->isSandbox() ? self::DEFAULT_URL_SANDBOX : self::DEFAULT_URL_PRODUCTION;
        }
        return rtrim($url, '/') . '/';
    }

    public function getPathPrefix()
    {
        $prefix = $this->_helper()->getConfig('api_path_prefix');
        if ($prefix === null) {
            $prefix = self::DEFAULT_PATH_PREFIX;
        }
        $prefix = trim((string) $prefix, '/');
        return $prefix === '' ? '' : $prefix . '/';
    }

    /**
     * @return array ['status' => int, 'body' => array]
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
            $path = $this->getPathPrefix() . $path;
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

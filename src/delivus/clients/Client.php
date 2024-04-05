<?php namespace delivus\clients;

use Amp\ByteStream\BufferException;
use Amp\ByteStream\StreamException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Sync\LocalSemaphore;
use Composer\InstalledVersions;
use delivus\clients\exceptions\LoginFailedError;
use delivus\clients\exceptions\RequestFailed;
use delivus\clients\logging\Logging;
use delivus\clients\utils\Singleton;
use Monolog\Logger;
use function Amp\delay;


class Client extends Singleton
{
    private const array BASE_URLS = array(
        "beta" => "https://od9dawmxfa.execute-api.ap-northeast-2.amazonaws.com/beta",
        "prod" => "https://3rl3zuk7zi.execute-api.ap-northeast-2.amazonaws.com/prod"
    );
    private const array CLIENT_ID = array(
        "beta" => "4rb4qh272oapuha0ac2antp4nv",
        "prod" => "6qrfvs16lh3p1lg2mrkcgfbqll"
    );
    private ?string $_env = NULL;
    private ?string $_idToken = NULL;
    private ?string $_refreshToken = NULL;
    private ?int $_idTokenExpiry = NULL;
    private HttpClient $_client;
    private Logger $_logger;
    private bool $_initialized = FALSE;
    private LocalSemaphore $sem;

    /**
     * @return Client
     * @throws LoginFailedError
     */
    public static function getInstance(): Client
    {
        $instance = parent::getInstance();
        $instance->init(getenv("DELIRABBIT_ENV"));
        if (is_null($instance->getIdToken())) {
            $instance->login(
                getenv("DELIRABBIT_USERNAME"),
                getenv("DELIRABBIT_PASSWORD")
            );
        }
        return $instance;
    }

    public function init(string $env): void {
        if (!$this->isInitialized()) {
            if (!array_key_exists($env, self::CLIENT_ID)) {
                throw new \RuntimeException("Invalid env: $env");
            }
            $this->_env = $env;
            $this->_client = HttpClientBuilder::buildDefault();
            $this->_logger = Logging::getLogger('delirabbit');
            $con = (int) getenv("DELIRABBIT_MAX_CONCURRENCY");
            $this->sem = new LocalSemaphore(($con >= 1) ? $con : 10);
            $this->_initialized = TRUE;
        }
    }

    function isInitialized(): bool {
        return $this->_initialized;
    }

    function getClientId(): ?string
    {
        return self::CLIENT_ID[$this->_env];
    }

    function getIdToken(): ?string
    {
        return $this->_idToken;
    }

    function getRefreshToken(): ?string
    {
        return $this->_refreshToken;
    }

    function getIdTokenExpiry(): ?int
    {
        return $this->_idTokenExpiry;
    }

    function getBaseURL(): ?string
    {
        return self::BASE_URLS[$this->_env];
    }

    /**
     * @param string $username 사용자이름 - E.164 포멧의 전화번호
     * @param string $password 비밀번호
     * @param bool $force
     * @return void
     * @throws LoginFailedError
     */
    function login(string $username, #[\SensitiveParameter] string $password, bool $force = FALSE): void
    {
        if ($this->getIdToken() != NULL && !$force && time() < $this->_idTokenExpiry) {
            $this->_logger->debug('ID Token not yet expired.');
            return;
        }
        $payload = array(
            'AuthParameters' => array(
                'USERNAME' => $username,
                'PASSWORD' => $password
            ),
            'AuthFlow' => 'USER_PASSWORD_AUTH',
            'ClientId' => $this->getClientId()
        );
        $request = new Request(
            'https://cognito-idp.ap-northeast-2.amazonaws.com/',
            'POST',
            json_encode($payload)
        );
        $request->setHeaders(
            array(
                'X-Amz-Target' => 'AWSCognitoIdentityProviderService.InitiateAuth',
                'Content-Type' => 'application/x-amz-json-1.1'
            )
        );
        try {
            $response = $this->_client->request($request);
        } catch (HttpException $exception) {
            throw new LoginFailedError($exception->getMessage(), $exception->getCode(), $exception);
        }
        try {
            $responseBody = $response->getBody()->buffer();
        } catch (StreamException|BufferException $exception) {
            throw new LoginFailedError($exception->getMessage(), $exception->getCode(), $exception);
        }
        $this->_logger->debug("Response body: $responseBody");
        $json = json_decode($responseBody, true);
        $this->_idToken = $json['AuthenticationResult']['IdToken'];
        $this->_refreshToken = $json['AuthenticationResult']['RefreshToken'];
        $this->_idTokenExpiry = time() + $json['AuthenticationResult']['ExpiresIn'];
        $this->_logger->debug("ID Token issued: $this->_idToken");
    }

    /**
     * @param string $endpoint Endpoint path
     * @param string $method HTTP method
     * @param ?array $params Query parameters
     * @param array|string|NULL $body Body object
     * @param ?array $headers Headers
     * @param int|float $transferTimeout Transfer timeout
     * @param int|float $inactivityTimeout Inactivity timeout
     * @param int|float $tcpConnectTimeout TCP connect timeout
     * @param int $retries Number of retries
     * @param array $retryOn Array of HTTP status codes to retry on
     * @return ?array
     * @throws HttpException
     */
    public function request(
        string            $endpoint,
        string            $method,
        ?array            $params = NULL,
        array|string|null $body = NULL,
        ?array            $headers = NULL,
        int|float         $transferTimeout = 180,
        int|float         $inactivityTimeout = 180,
        int|float         $tcpConnectTimeout = 3,
        int               $retries = 3,
        array             $retryOn = [408, 500, 502, 503, 504, 522, 524],
    ): ?array
    {
        $lock = $this->sem->acquire();
        $uri = $this->getBaseURL() . $endpoint;
        $this->_logger->debug("Requesting HTTP $method $uri");
        $request = new Request($uri, $method);
        # Set headers
        $idToken = $this->getIdToken();
        $h = array(
            "Content-Type" => 'application/json',
            "Accept" => "application/json",
            "Authorization" => "Bearer $idToken",
            "X-Client-Version" => InstalledVersions::getRootPackage()['version']
        );
        $request->setHeaders((!is_null($headers)) ? array_merge($h, $headers) : $h);
        # Set query parameters
        if ($params != NULL) {
            $request->setQueryParameters($params);
        }
        # Set body
        if ($body != NULL) {
            $request->setBody((is_array($body) ? json_encode($body) : $body));
        }
        $request->setTransferTimeout($transferTimeout);
        $request->setInactivityTimeout($inactivityTimeout);
        $request->setTcpConnectTimeout($tcpConnectTimeout);
        # Make request
        $response = NULL;
        $retried = 0;
        while ($retried <= $retries && is_null($response)) {
            $retried = $retries + 1;
            $future = \Amp\async(fn() => $this->_client->request($request));
            try {
                $response = $future->await();
            } catch (HttpException $exception) {
                if ($retried >= $retries) {
                    $lock->release();
                    throw $exception;
                }
            } catch (\Exception $exception) {
                $lock->release();
                throw $exception;
            }
            $status = $response->getStatus();
            if (!in_array($status, $retryOn)) {
                break;
            }
            \Amp\async(function () {
                delay(1.0);
            });
        }
        try {
            $responseBody = $response->getBody()->read();
        } catch (StreamException|BufferException $exception) {
            $s = serialize($response->getBody());
            $lock->release();
            throw new \UnexpectedValueException("Unexpected response: $s", 0, $exception);
        }
        $json = json_decode($responseBody, true);
        $this->_logger->debug('Response received: ' . json_encode($json));
        $lock->release();
        return $json;
    }

    /**
     * @param string $job_group_uuid
     * @param string $job_uuid
     * @return array
     * @throws RequestFailed
     */
    public function getJobResponse(string $job_group_uuid, string $job_uuid): array {
        $uri = "/api/v1/jobs/groups/$job_group_uuid/jobs/$job_uuid/response/";
        try {
            return $this->request($uri, 'GET');
        } catch (HttpException $exception) {
            $code = $exception->getCode();
            $message = $exception->getMessage();
            $this->_logger->error(
                "작업($job_uuid) 응답을 불러올 수 없습니다: [{$code}] {$message}",
            );
            throw new RequestFailed("작업($job_uuid) 응답을 불러올 수 없습니다: [{$code}] {$message}");
        }
    }
}
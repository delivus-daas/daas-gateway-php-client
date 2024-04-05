<?php namespace delivus\clients\services;

use Amp\Http\Client\HttpException;
use delivus\clients\Client;
use delivus\clients\exceptions\LoginFailedError;
use delivus\clients\exceptions\RequestFailed;
use delivus\clients\models\JobGroup;
use delivus\clients\requests\DelirabbitRequest;
use Exception;

abstract class DelirabbitService {
    protected static string $path;
    protected static array $pathOverrides = [];
    protected static string $method = 'GET';

    /**
     * @param DelirabbitRequest $request
     * @return JobGroup
     * @throws LoginFailedError
     * @throws RequestFailed
     * @throws Exception
     */
    public function exec(DelirabbitRequest $request): JobGroup {
        if (!isset(static::$path)) {
            throw new Exception('path is not set.');
        }
        $client = Client::getInstance();
        try {
            $response = $client->request(
                $this->parsePath($request),
                static::$method,
                NULL,
                $request->getBody()
            );
        } catch (HttpException $err) {
            throw new RequestFailed('요청 전송에 실패했습니다.', $err->getCode(), $err);
        }
        return new JobGroup($response['job_group_uuid']);
    }

    protected function parsePath(DelirabbitRequest $request): string {
        $path = static::$path;
        foreach (static::$pathOverrides as $key) {
            $getter = 'get' . ucfirst($key);
            $val = $request->$getter();
            $path = sprintf($path, $val);
        }
        return $path;
    }
}


class CreateOrderService extends DelirabbitService {
    protected static string $path = '/api/v2/order/orders/';
    protected static string $method = 'POST';
}
<?php

use Amp\Future;
use delivus\clients\models\Job;
use delivus\clients\requests\DelirabbitRequest;
use PHPUnit\Framework\TestCase;
use delivus\clients\services\CreateOrderService;
use Ramsey\Uuid\Uuid;
use function Amp\async;
use function Amp\Future\awaitAll;


final class ServicesTest extends TestCase {
    public function testExec() {
        $request = new DelirabbitRequest([
            [
                'order_number' => Uuid::uuid4()->getHex()->toString(),
                'receiver_name' => '김현일',
                'receiver_mobile_tel' => '010-1234-5678',
                'receiver_postcode' => '16852',
                'receiver_address1' => '경기도 용인시 수지구 성복1로 80 (성복역 서희스타힐스)',
                'receiver_address2' => '101동 1101호',
                'order_items' => [
                    [
                        'product' => [
                            'name' => '크리스마스 양말 [WHT]'
                        ]
                    ]
                ]
            ],
        ]);
        $service = new CreateOrderService();
        $jobGroup = $service->exec($request);
        $this->assertNotNull($jobGroup->getUuid());
        $jobGroup->waitUntilCompleted();
        $this->assertNotEmpty($jobGroup->getJobs());
        [$errors, $responses] = awaitAll(
            array_map(function (Job $job): Future {
                return async(fn () => $job->getResponse());
            }, $jobGroup->getJobs())
        );
        $this->assertEmpty($errors);
        $this->assertNotEmpty($responses);
    }

    public function testExecMany() {
        $requests = array_map(
            function () {
                return new DelirabbitRequest([
                    [
                        'order_number' => Uuid::uuid4()->getHex()->toString(),
                        'receiver_name' => '김현일',
                        'receiver_mobile_tel' => '010-1234-5678',
                        'receiver_postcode' => '16852',
                        'receiver_address1' => '경기도 용인시 수지구 성복1로 80 (성복역 서희스타힐스)',
                        'receiver_address2' => '101동 1101호',
                        'order_items' => [
                            [
                                'product' => [
                                    'name' => '크리스마스 양말 [WHT]'
                                ]
                            ]
                        ]
                    ]
                ]);
            },
            range(1, 20)
        );
        $run = function (DelirabbitRequest $request) {
            $service = new CreateOrderService();
            $jobGroup = $service->exec($request);
            $this->assertNotNull($jobGroup->getUuid());
            $jobGroup->waitUntilCompleted();
            $this->assertNotEmpty($jobGroup->getJobs());
            [$errors, $responses] = awaitAll(
                array_map(function (Job $job): Future {
                    return async(fn () => $job->getResponse());
                }, $jobGroup->getJobs())
            );
            $this->assertEmpty($errors);
            $this->assertNotEmpty($responses);
        };
        [$errors, $responses] = awaitAll(array_map(
            function(DelirabbitRequest $request) use ($run): Future {
                return async(fn () => $run($request));
            },
            $requests
        ));
        $this->assertEmpty($errors);
        $this->assertNotEmpty($responses);
    }
}

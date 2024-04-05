<?php namespace delivus\clients\models;

use Amp\Http\Client\HttpException;
use delivus\clients\Client;
use function Amp\delay;

final class Job {
    private ?array $response = NULL;
    private ?array $request = NULL;

    public function __construct(
        private readonly string $jobGroupUuid,
        private readonly string $uuid,
        private string $eventType,
        private string $status,
        private ?int $statusCode = NULL
    ) {}

    public function getJobGroupUuid(): string {
        return $this->jobGroupUuid;
    }

    public function getUuid(): string {
        return $this->uuid;
    }

    public function getResponse(): ?array {
        if (is_null($this->response)) {
            $client = Client::getInstance();
            $this->response = $client->getJobResponse($this->getJobGroupUuid(), $this->getUuid());
        }
        return $this->response;
    }

    public function getRequest(): ?array {

    }
}

final class JobGroup {
    private string $status = "PENDING";
    private array $jobs = [];
    private int $numFailed = 0;

    public function __construct(private readonly string $uuid) {}

    public function getUuid(): string {
        return $this->uuid;
    }

    public function getStatus(): string {
        return $this->status;
    }

    public function getJobs(): array {
        return $this->jobs;
    }

    public function getNumFailed(): int {
        return $this->numFailed;
    }

    public function waitUntilCompleted(): void {
        do {
            $response = NULL;
            try {
                $response = Client::getInstance()->request(
                    '/api/v1/jobs/groups/' . $this->getUuid() . '/',
                    'GET'
                );
            } catch (HttpException $exception) {
                continue;
            }
            $this->status = $response['job_group_status'];
            $this->numFailed = $response['num_failed'];
            delay(1.0);
        } while ($this->getStatus() != 'COMPLETED');
        $this->jobs = array_map(function ($job): Job {
            return new Job(
                $this->getUuid(),
                $job['uuid'],
                $job['event_type'],
                $job['job_status'],
                $job['job_status_code']
            );
        }, $response['jobs']);
    }
}
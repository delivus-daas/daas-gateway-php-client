<?php namespace delivus\clients\exceptions;

use Throwable;

class DelirabbitError extends \Exception {}

class LoginFailedError extends DelirabbitError {}

class RequestFailed extends DelirabbitError {}

class CannotCreateOrder extends RequestFailed {}

class CannotGetJobResponse extends RequestFailed {}

class BatchJobFailed extends DelirabbitError {
    protected string $job_group_uuid;
    protected string $job_uuid;
    protected int $status;
    protected string $detail;

    public function __construct(string $message = "", string $job_group_uuid = "", string $job_uuid = "", int $status = 0, string $detail = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getJobGroupUUID(): string {
        return $this->job_group_uuid;
    }

    public function getJobUUID(): string {
        return $this->job_uuid;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function getDetail(): string {
        return $this->detail;
    }
}